<?php

declare(strict_types=1);

namespace FluentFormsExtendedApi\Rest;

use FluentFormsExtendedApi\Services\FormService;
use FluentFormsExtendedApi\Services\FormSubmissionService;
use FluentFormsExtendedApi\Support\FluentFormsDependency;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Registers and handles REST routes under `/wp-json/fluent-forms-extended/v1/`.
 *
 * Example successful list response (abbreviated):
 * <code>
 * [
 *   { "id": 12, "title": "Contact", "status": "published" }
 * ]
 * </code>
 *
 * Example successful detail response (abbreviated):
 * <code>
 * {
 *   "id": 12,
 *   "title": "Contact",
 *   "status": "published",
 *   "fields": [
 *     {
 *       "id": "names.first_name",
 *       "submit_key": "names_first_name_1",
 *       "type": "text",
 *       "group": "names",
 *       "component": "name.first",
 *       "label": "First Name",
 *       "required": true,
 *       "placeholder": ""
 *     },
 *     {
 *       "id": "email",
 *       "type": "email",
 *       "label": "Email Address",
 *       "required": true,
 *       "placeholder": "you@example.com"
 *     }
 *   ],
 *   "submit_button": { "text": "Submit" }
 * }
 * </code>
 */
final class FormsController
{
    private FormService $formService;

    private FormSubmissionService $submissionService;

    private FluentFormsDependency $dependency;

    public function __construct(
        FormService $formService,
        FormSubmissionService $submissionService,
        FluentFormsDependency $dependency
    ) {
        $this->formService        = $formService;
        $this->submissionService = $submissionService;
        $this->dependency        = $dependency;
    }

    public function registerRoutes(): void
    {
        $public = static function (): bool {
            // Intentionally public for now; combine with `fluent_forms_extended_api_can_submit` for auth/rate limits.
            return true;
        };

        register_rest_route(
            'fluent-forms-extended/v1',
            '/forms',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getForms'],
                'permission_callback' => $public,
            ]
        );

        // Register the more specific `/submit` route before the generic `/{id}` route (defensive ordering).
        register_rest_route(
            'fluent-forms-extended/v1',
            '/forms/(?P<id>\\d+)/submit',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'postFormSubmit'],
                'permission_callback' => $public,
                'args'                => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        register_rest_route(
            'fluent-forms-extended/v1',
            '/forms/(?P<id>\\d+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getForm'],
                'permission_callback' => $public,
                'args'                => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    /**
     * GET /forms — list Fluent Forms with id, title, and status.
     */
    public function getForms(WP_REST_Request $_request)
    {
        unset($_request);

        if (! $this->dependency->isAvailable()) {
            return $this->dependencyErrorResponse();
        }

        $forms = $this->formService->listForms();

        // Top-level JSON array: [ { "id": 1, "title": "...", "status": "..." }, ... ]
        return new WP_REST_Response($forms, 200);
    }

    /**
     * GET /forms/{id} — full schema with normalised `fields` and optional `submit_button`.
     */
    public function getForm(WP_REST_Request $request)
    {
        if (! $this->dependency->isAvailable()) {
            return $this->dependencyErrorResponse();
        }

        $formId = (int) $request->get_param('id');
        $form   = $this->formService->getFormStructure($formId);

        if ($form === null) {
            return new WP_Error(
                'fluent_forms_extended_api_form_not_found',
                __('No form exists for the requested id.', 'fluent-forms-extended-api'),
                ['status' => 404]
            );
        }

        return new WP_REST_Response($form, 200);
    }

    /**
     * POST /forms/{id}/submit — JSON body keyed by Fluent Forms input names; uses Fluent's native submission handler.
     */
    public function postFormSubmit(WP_REST_Request $request): WP_REST_Response
    {
        $formId = (int) $request->get_param('id');

        $params = $this->parseJsonParams($request);
        if ($params === null) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => __('Request body must be valid JSON.', 'fluent-forms-extended-api'),
                ],
                400
            );
        }

        $result = $this->submissionService->submit($formId, $params);

        return new WP_REST_Response($result['body'], $result['http_status']);
    }

    /**
     * Decode JSON body into an associative array (empty object becomes []).
     *
     * @return array<string, mixed>|null Null when JSON is invalid.
     */
    private function parseJsonParams(WP_REST_Request $request): ?array
    {
        $params = $request->get_json_params();
        if (is_array($params)) {
            return $params;
        }

        $body = $request->get_body();
        if ($body === null || $body === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * Shared JSON error payload when Fluent Forms is unavailable.
     */
    private function dependencyErrorResponse(): WP_Error
    {
        return new WP_Error(
            'fluent_forms_extended_api_missing_fluent_forms',
            $this->dependency->getUnavailableMessage(),
            ['status' => 503]
        );
    }
}
