<?php

declare(strict_types=1);

namespace FluentFormsExtendedApi\Rest;

use FluentFormsExtendedApi\Services\EntryService;
use FluentFormsExtendedApi\Support\FluentFormsDependency;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Registers and handles entry routes under `/wp-json/fluent-forms-extended/v1/`.
 *
 * Routes owned by this controller:
 *   GET  /entries                        — paginated submissions across all forms
 *   GET  /entries/{entry_id}             — single submission by id
 *   GET  /forms/{form_id}/entries        — paginated submissions for one form
 *
 * Example successful list response (abbreviated):
 * <code>
 * {
 *   "total": 47,
 *   "current_page": 1,
 *   "per_page": 20,
 *   "total_pages": 3,
 *   "data": [
 *     {
 *       "entry_id": 102,
 *       "form_id": 12,
 *       "status": "read",
 *       "created_at": "2026-05-13 09:14:22",
 *       "user_id": 5,
 *       "user_ip": "203.0.113.42",
 *       "browser": "Chrome",
 *       "device": "Desktop",
 *       "submission": {
 *         "names": { "first_name": "Ada", "last_name": "Lovelace" },
 *         "email": "ada@example.com",
 *         "message": "Hello"
 *       }
 *     }
 *   ]
 * }
 * </code>
 *
 * Example single-entry response (abbreviated):
 * <code>
 * {
 *   "entry_id": 102,
 *   "form_id": 12,
 *   "status": "read",
 *   "created_at": "2026-05-13 09:14:22",
 *   "updated_at": "2026-05-13 10:01:18",
 *   "user_id": 5,
 *   "user_ip": "203.0.113.42",
 *   "browser": "Chrome",
 *   "device": "Desktop",
 *   "payment_status": "paid",
 *   "submission": { "email": "ada@example.com", "message": "Hello" }
 * }
 * </code>
 *
 * Example 404:
 * <code>
 * { "success": false, "message": "No entry exists for the requested id." }
 * </code>
 */
final class EntriesController
{
    private EntryService $entryService;

    private FluentFormsDependency $dependency;

    public function __construct(EntryService $entryService, FluentFormsDependency $dependency)
    {
        $this->entryService = $entryService;
        $this->dependency   = $dependency;
    }

    public function registerRoutes(): void
    {
        // Common pagination/sort args shared by both list routes — keeps behaviour identical.
        $listArgs = [
            'page' => [
                'required'          => false,
                'type'              => 'integer',
                'default'           => 1,
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => [$this, 'validatePositiveInt'],
            ],
            'per_page' => [
                'required'          => false,
                'type'              => 'integer',
                'default'           => 20,
                'minimum'           => 1,
                'maximum'           => 100,
                'sanitize_callback' => 'absint',
                'validate_callback' => [$this, 'validatePositiveInt'],
            ],
            'sort_by' => [
                'required'          => false,
                'type'              => 'string',
                'default'           => 'id',
                'enum'              => ['id', 'created_at'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'sort_order' => [
                'required'          => false,
                'type'              => 'string',
                'default'           => 'DESC',
                'enum'              => ['ASC', 'DESC', 'asc', 'desc'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];

        // Reused for both `entry_id` and `form_id` path params — identical contract.
        $idPathArg = [
            'required'          => true,
            'type'              => 'integer',
            'minimum'           => 1,
            'sanitize_callback' => 'absint',
            'validate_callback' => [$this, 'validatePositiveInt'],
        ];

        // GET /entries — cross-form paginated list.
        register_rest_route(
            'fluent-forms-extended/v1',
            '/entries',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getEntries'],
                'permission_callback' => [$this, 'canViewEntries'],
                'args'                => $listArgs,
            ]
        );

        // GET /entries/{entry_id} — single submission by id (404 when missing).
        register_rest_route(
            'fluent-forms-extended/v1',
            '/entries/(?P<entry_id>\\d+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getEntry'],
                'permission_callback' => [$this, 'canViewEntries'],
                'args'                => [
                    'entry_id' => $idPathArg,
                ],
            ]
        );

        // GET /forms/{form_id}/entries — paginated submissions scoped to a single form.
        // The form_id path is validated against the public `find()` API before listing,
        // so unknown ids get a clean 404 instead of an empty list.
        register_rest_route(
            'fluent-forms-extended/v1',
            '/forms/(?P<form_id>\\d+)/entries',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getFormEntries'],
                'permission_callback' => [$this, 'canViewEntries'],
                'args'                => array_merge(
                    ['form_id' => $idPathArg],
                    $listArgs
                ),
            ]
        );
    }

    /**
     * Permission callback shared by every entry endpoint.
     *
     * Entries hold PII (IP, browser, user submissions). Default: only callers with
     * Fluent Forms' documented `fluentform_entries_viewer` capability — or
     * `manage_options` as a fallback — may read entries. Integrators can override
     * via filter for custom auth (application passwords, JWT, etc.).
     */
    public function canViewEntries(WP_REST_Request $request): bool
    {
        $default = current_user_can('fluentform_entries_viewer') || current_user_can('manage_options');

        /**
         * Override entry access (e.g. plug in an external auth provider).
         *
         * @param bool            $allow   The default decision based on capabilities.
         * @param WP_REST_Request $request Current REST request.
         */
        $allowed = apply_filters('fluent_forms_extended_api_can_view_entries', $default, $request);

        return is_bool($allowed) ? $allowed : (bool) $default;
    }

    /**
     * `validate_callback` for any positive-integer arg (path or query).
     *
     * Returns `true` on success or a `WP_Error` so WordPress emits HTTP 400 with
     * a useful message instead of letting an invalid value reach our service.
     *
     * @param mixed $value
     *
     * @return true|\WP_Error
     */
    public function validatePositiveInt($value)
    {
        if (is_numeric($value) && (int) $value >= 1) {
            return true;
        }

        return new \WP_Error(
            'rest_invalid_param',
            __('Value must be a positive integer.', 'fluent-forms-extended-api'),
            ['status' => 400]
        );
    }

    /**
     * GET /entries — list submissions across all forms (paginated).
     */
    public function getEntries(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->entryService->listAll($this->extractQuery($request));

        return new WP_REST_Response($result['body'], $result['http_status']);
    }

    /**
     * GET /entries/{entry_id} — single entry by id.
     */
    public function getEntry(WP_REST_Request $request): WP_REST_Response
    {
        $entryId = (int) $request->get_param('entry_id');
        $result  = $this->entryService->findOne($entryId);

        return new WP_REST_Response($result['body'], $result['http_status']);
    }

    /**
     * GET /forms/{form_id}/entries — paginated entries for one form.
     */
    public function getFormEntries(WP_REST_Request $request): WP_REST_Response
    {
        $formId = (int) $request->get_param('form_id');
        $result = $this->entryService->listForForm($formId, $this->extractQuery($request));

        return new WP_REST_Response($result['body'], $result['http_status']);
    }

    /**
     * Extract the subset of query params the entry service cares about.
     *
     * @return array<string, mixed>
     */
    private function extractQuery(WP_REST_Request $request): array
    {
        return [
            'page'       => $request->get_param('page'),
            'per_page'   => $request->get_param('per_page'),
            'sort_by'    => $request->get_param('sort_by'),
            'sort_order' => $request->get_param('sort_order'),
        ];
    }
}
