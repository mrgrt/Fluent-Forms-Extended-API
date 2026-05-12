<?php

declare(strict_types=1);

namespace FluentFormsExtendedApi\Support;

/**
 * Delegates programmatic submissions to Fluent Forms' native handler.
 *
 * Fluent Forms processes validation, persistence, notifications, and feeds inside
 * {@see \FluentForm\App\Services\Form\SubmissionHandlerService::handleSubmission()}.
 * This class exists so the rest of our plugin never references that type directly.
 *
 * @see https://fluentforms.com/docs/fluent-form-php-api/ (submission behaviour is shared with the AJAX pipeline)
 */
final class FluentSubmissionPipeline
{
    /**
     * Run Fluent Forms' full submission pipeline for the given form and raw field map.
     *
     * Keys in {@see $formDataRaw} must match Fluent Forms input names (including nested keys where applicable).
     *
     * @param array<string, mixed> $formDataRaw Associative payload (same shape as a decoded form POST body).
     *
     * @return array<string, mixed> Internal handler return (not our public REST shape).
     *
     * @throws \FluentForm\Framework\Validator\ValidationException When Fluent validation fails.
     */
    public function dispatch(int $formId, array $formDataRaw): array
    {
        $handler = new \FluentForm\App\Services\Form\SubmissionHandlerService();

        return $handler->handleSubmission($formDataRaw, $formId);
    }

    /**
     * Whether the submission service class is present (Fluent Forms loaded).
     */
    public static function isSupported(): bool
    {
        return class_exists(\FluentForm\App\Services\Form\SubmissionHandlerService::class);
    }
}
