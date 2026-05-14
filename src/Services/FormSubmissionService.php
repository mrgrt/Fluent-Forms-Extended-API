<?php

declare(strict_types=1);

namespace FluentFormsExtendedApi\Services;

use FluentFormsExtendedApi\Support\FluentFormsDependency;
use FluentFormsExtendedApi\Support\FluentFormsGateway;
use FluentFormsExtendedApi\Support\FluentSubmissionClient;
use FluentFormsExtendedApi\Support\SubmissionResponseNormalizer;
use FluentFormsExtendedApi\Support\ValidationErrorNormalizer;

/**
 * REST-facing submission orchestration.
 *
 * The actual submission is performed via {@see FluentSubmissionClient}, which talks to
 * Fluent Forms' publicly-exposed AJAX action `fluentform_submit` over loopback HTTP.
 * This service is responsible for: dependency checks, payload preparation, mapping
 * HTTP responses from Fluent's endpoint to our stable REST contract — without ever
 * referencing any Fluent Forms class, model, service, or framework component.
 */
final class FormSubmissionService
{
    /**
     * HTTP status codes Fluent's submit endpoint emits for validation failures.
     * Anything outside this set is treated as a transport / server error.
     *
     * @var array<int, int>
     */
    private const VALIDATION_STATUS_CODES = [400, 401, 403, 404, 422, 423, 429];

    private FluentFormsDependency $dependency;

    private FluentFormsGateway $gateway;

    private FormService $formService;

    private FluentSubmissionClient $client;

    private SubmissionResponseNormalizer $normalizer;

    private ValidationErrorNormalizer $validationErrorNormalizer;

    public function __construct(
        FluentFormsDependency $dependency,
        FluentFormsGateway $gateway,
        FormService $formService,
        FluentSubmissionClient $client,
        SubmissionResponseNormalizer $normalizer,
        ValidationErrorNormalizer $validationErrorNormalizer
    ) {
        $this->dependency                = $dependency;
        $this->gateway                   = $gateway;
        $this->formService               = $formService;
        $this->client                    = $client;
        $this->normalizer                = $normalizer;
        $this->validationErrorNormalizer = $validationErrorNormalizer;
    }

    /**
     * Submit a form by proxying through Fluent Forms' public AJAX submission action.
     *
     * @param array<string, mixed> $jsonPayload Decoded JSON body; keys should match Fluent input
     *                                          names, or dotted logical `id` values from GET /forms/{id}
     *                                          (mapped to `submit_key` when present).
     *
     * @return array{http_status: int, body: array<string, mixed>}
     */
    public function submit(int $formId, array $jsonPayload): array
    {
        // 1. Dependency: Fluent Forms loaded?
        if (! $this->dependency->isAvailable()) {
            return [
                'http_status' => 503,
                'body'        => array_merge(
                    $this->normalizer->serverErrorBody(),
                    ['message' => $this->dependency->getUnavailableMessage()]
                ),
            ];
        }

        // 2. Authorisation extension point for downstream integrators.
        $allowed = apply_filters('fluent_forms_extended_api_can_submit', true, $formId, $jsonPayload);
        if (! is_bool($allowed) || ! $allowed) {
            return [
                'http_status' => 403,
                'body'        => [
                    'success' => false,
                    'message' => __('Submission is not allowed for this request.', 'fluent-forms-extended-api'),
                ],
            ];
        }

        // 3. Cheap input validation before doing any work.
        if ($formId < 1) {
            return [
                'http_status' => 400,
                'body'        => [
                    'success' => false,
                    'message' => __('Invalid form id.', 'fluent-forms-extended-api'),
                ],
            ];
        }

        // 4. Form-existence check via the public `fluentFormApi('forms')->find()` API.
        $form = $this->gateway->findForm($formId);
        if ($form === null) {
            return [
                'http_status' => 404,
                'body'        => [
                    'success' => false,
                    'message' => __('No form exists for the requested id.', 'fluent-forms-extended-api'),
                ],
            ];
        }

        // 5. Build the wire payload exactly as Fluent's frontend would and send it.
        $payload = $this->preparePayload($formId, $jsonPayload);
        $result  = $this->client->submit($formId, $payload);

        // 6. Transport failure — loopback blocked, network error, etc.
        if (! $result['transport_ok']) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[fluent-forms-extended-api] Submission transport failed: ' . (string) ($result['error'] ?? 'unknown'));
            }
            return [
                'http_status' => 503,
                'body'        => $this->normalizer->serverErrorBody(),
            ];
        }

        // 7. Map Fluent's HTTP response shape onto our stable contract.
        $statusCode = (int) $result['status_code'];
        $decoded    = is_array($result['decoded']) ? $result['decoded'] : [];

        if ($statusCode === 200) {
            return $this->mapSuccessResponse($decoded);
        }

        if (in_array($statusCode, self::VALIDATION_STATUS_CODES, true)) {
            return $this->mapValidationResponse($formId, $statusCode, $decoded);
        }

        // 8. Anything else — treat as a generic server error.
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[fluent-forms-extended-api] Unexpected submission status ' . $statusCode);
        }
        return [
            'http_status' => 500,
            'body'        => $this->normalizer->serverErrorBody(),
        ];
    }

    /**
     * Translate a 200 OK response (`wp_send_json_success` wrapper) into our success body.
     *
     * @param array<string, mixed> $decoded Decoded JSON from the AJAX endpoint.
     *
     * @return array{http_status:int, body:array<string, mixed>}
     */
    private function mapSuccessResponse(array $decoded): array
    {
        // `wp_send_json_success($x)` always emits `{"success": true, "data": $x}`.
        // The original handler return lives inside `data`.
        $success     = (bool) ($decoded['success'] ?? false);
        $handlerData = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        if (! $success) {
            return [
                'http_status' => 500,
                'body'        => $this->normalizer->serverErrorBody(),
            ];
        }

        return [
            'http_status' => 200,
            'body'        => $this->normalizer->successBody($handlerData),
        ];
    }

    /**
     * Translate a non-2xx response (validation failure) into our `errors[]` shape.
     *
     * Fluent's handler emits `wp_send_json($errors, $code)` on validation failure, so
     * the body is the raw error map keyed by field name — no `success/data` wrapper.
     *
     * @param array<string, mixed> $decoded
     *
     * @return array{http_status:int, body:array<string, mixed>}
     */
    private function mapValidationResponse(int $formId, int $statusCode, array $decoded): array
    {
        // Reuse the same normalised field list as GET /forms/{id} for label/type enrichment.
        $normalizedFields = $this->formService->getNormalizedFields($formId);

        return [
            'http_status' => $this->normalizer->validationHttpStatusFromCode($statusCode),
            'body'        => $this->validationErrorNormalizer->normalizeValidationBody($decoded, $normalizedFields),
        ];
    }

    /**
     * Merge REST JSON with values Fluent's submit handler expects (referrer, optional nonce).
     *
     * @param array<string, mixed> $jsonPayload
     *
     * @return array<string, mixed>
     */
    private function preparePayload(int $formId, array $jsonPayload): array
    {
        $payload = $jsonPayload;

        // Used for `source_url` on the submission row; harmless when absent.
        if (empty($payload['_wp_http_referer']) || ! is_string($payload['_wp_http_referer'])) {
            $payload['_wp_http_referer'] = esc_url(home_url('/'));
        } else {
            $payload['_wp_http_referer'] = esc_url_raw($payload['_wp_http_referer']);
        }

        // Send a fresh nonce as a defensive default. Fluent only verifies it when the
        // public `fluentform/nonce_verify` filter returns true — see Fluent's FormHandler.
        // Across the loopback boundary a server-generated nonce can fail to verify in
        // strict-nonce setups; in that case the site owner should disable nonce_verify
        // for trusted internal requests or authenticate at the REST layer.
        $nonceKey            = '_fluentform_' . $formId . '_fluentformnonce';
        $payload[ $nonceKey ] = wp_create_nonce('fluentform-submit-form');

        $this->remapLogicalFieldKeysToSubmitKeys($formId, $payload);

        /**
         * Allow integrators to append trusted hidden fields (payment / captcha tokens, …)
         * before the loopback request is sent.
         *
         * @param array<string, mixed> $payload
         */
        return apply_filters('fluent_forms_extended_api_submission_payload', $payload, $formId);
    }

    /**
     * Map dotted logical field ids from GET /forms/{id} to Fluent `submit_key` names
     * when clients post using `id`.
     *
     * @param array<string, mixed> $payload
     */
    private function remapLogicalFieldKeysToSubmitKeys(int $formId, array &$payload): void
    {
        $fields = $this->formService->getNormalizedFields($formId);
        foreach ($fields as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = isset($row['id']) ? (string) $row['id'] : '';
            if ($id === '' || strpos($id, '.') === false) {
                continue;
            }
            $submitKey = isset($row['submit_key']) ? (string) $row['submit_key'] : '';
            if ($submitKey === '' || $submitKey === $id) {
                continue;
            }
            if (! array_key_exists($id, $payload)) {
                continue;
            }
            if (! array_key_exists($submitKey, $payload)) {
                $payload[ $submitKey ] = $payload[ $id ];
            }
            unset($payload[ $id ]);
        }
    }
}
