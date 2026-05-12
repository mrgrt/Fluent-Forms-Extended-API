<?php

declare(strict_types=1);

namespace FluentFormsExtendedApi\Services;

use FluentFormsExtendedApi\Support\FluentFormsDependency;
use FluentFormsExtendedApi\Support\FluentFormsGateway;
use FluentFormsExtendedApi\Support\FluentSubmissionPipeline;
use FluentFormsExtendedApi\Support\SubmissionResponseNormalizer;
use FluentFormsExtendedApi\Support\ValidationErrorNormalizer;

/**
 * REST-facing submission orchestration: validates prerequisites, augments payload, delegates to Fluent Forms.
 */
final class FormSubmissionService
{
    private FluentFormsDependency $dependency;

    private FluentFormsGateway $gateway;

    private FormService $formService;

    private FluentSubmissionPipeline $pipeline;

    private SubmissionResponseNormalizer $normalizer;

    private ValidationErrorNormalizer $validationErrorNormalizer;

    public function __construct(
        FluentFormsDependency $dependency,
        FluentFormsGateway $gateway,
        FormService $formService,
        FluentSubmissionPipeline $pipeline,
        SubmissionResponseNormalizer $normalizer,
        ValidationErrorNormalizer $validationErrorNormalizer
    ) {
        $this->dependency                 = $dependency;
        $this->gateway                    = $gateway;
        $this->formService                = $formService;
        $this->pipeline                   = $pipeline;
        $this->normalizer                 = $normalizer;
        $this->validationErrorNormalizer = $validationErrorNormalizer;
    }

    /**
     * Submit a form using Fluent Forms' native pipeline.
     *
     * @param array<string, mixed> $jsonPayload Decoded JSON body; keys should match Fluent input names, or dotted logical `id` from GET /forms/{id} (mapped to `submit_key` when present).
     *
     * @return array{http_status: int, body: array<string, mixed>}
     */
    public function submit(int $formId, array $jsonPayload): array
    {
        if (! $this->dependency->isAvailable() || ! FluentSubmissionPipeline::isSupported()) {
            return [
                'http_status' => 503,
                'body'        => array_merge(
                    $this->normalizer->serverErrorBody(),
                    ['message' => $this->dependency->getUnavailableMessage()]
                ),
            ];
        }

        /**
         * Extension point for authentication, rate limits, or capability checks later.
         *
         * @param bool                 $allow
         * @param int                  $formId
         * @param array<string, mixed> $jsonPayload
         */
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

        if ($formId < 1) {
            return [
                'http_status' => 400,
                'body'        => [
                    'success' => false,
                    'message' => __('Invalid form id.', 'fluent-forms-extended-api'),
                ],
            ];
        }

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

        // Build the same associative shape Fluent's AJAX handler expects (field names => values).
        $payload = $this->preparePayloadForFluent($formId, $jsonPayload);

        try {
            $handlerResponse = $this->pipeline->dispatch($formId, $payload);

            return [
                'http_status' => 200,
                'body'        => $this->normalizer->successBody($handlerResponse),
            ];
        } catch (\Throwable $exception) {
            // Fluent Forms validation failures — avoid hard type references so this file loads when Fluent Forms is inactive.
            if (is_a($exception, 'FluentForm\Framework\Validator\ValidationException', true) && method_exists($exception, 'errors')) {
                /** @var mixed $rawErrors */
                $rawErrors = $exception->errors();
                $rawArray  = is_array($rawErrors) ? $rawErrors : [];

                // Reuse the same normalised field list as GET /forms/{id} for label/type enrichment.
                $normalizedFields = $this->formService->getNormalizedFields($formId);

                return [
                    'http_status' => $this->normalizer->validationHttpStatusFromCode((int) $exception->getCode()),
                    'body'        => $this->validationErrorNormalizer->normalizeValidationBody($rawArray, $normalizedFields),
                ];
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[fluent-forms-extended-api] Submission failed: ' . $exception->getMessage());
            }

            return [
                'http_status' => 500,
                'body'        => $this->normalizer->serverErrorBody(),
            ];
        }
    }

    /**
     * Merge REST JSON with values Fluent's insert pipeline expects (referrer, nonce when verification is on).
     *
     * @param array<string, mixed> $jsonPayload
     *
     * @return array<string, mixed>
     */
    private function preparePayloadForFluent(int $formId, array $jsonPayload): array
    {
        $payload = $jsonPayload;

        // Used for `source_url` in Fluent's insert row; harmless when absent.
        if (empty($payload['_wp_http_referer']) || ! is_string($payload['_wp_http_referer'])) {
            $payload['_wp_http_referer'] = esc_url(home_url('/'));
        } else {
            $payload['_wp_http_referer'] = esc_url_raw($payload['_wp_http_referer']);
        }

        // When `fluentform/nonce_verify` is enabled, Fluent expects this key — issuing server-side avoids stale browser nonces for headless clients.
        $nonceKey            = '_fluentform_' . $formId . '_fluentformnonce';
        $payload[ $nonceKey ] = wp_create_nonce('fluentform-submit-form');

        $this->remapLogicalFieldKeysToSubmitKeys($formId, $payload);

        /**
         * Allow integrators to append trusted hidden fields (e.g. payment or captcha tokens) before the handler runs.
         *
         * @param array<string, mixed> $payload
         */
        return apply_filters('fluent_forms_extended_api_submission_payload', $payload, $formId);
    }

    /**
     * Map dotted logical field ids from GET /forms/{id} to Fluent `submit_key` names when clients post using `id`.
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
