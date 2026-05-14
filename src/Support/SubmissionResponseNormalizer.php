<?php

declare(strict_types=1);

namespace FluentFormsExtendedApi\Support;

/**
 * Maps Fluent Forms handler / exception payloads to our stable REST contract.
 */
final class SubmissionResponseNormalizer
{
    /**
     * Normalise Fluent Forms' successful handler array to our public JSON shape.
     *
     * Consumes the array that Fluent Forms' submit endpoint emits inside the
     * `data` key of its `wp_send_json_success()` envelope (no internal class
     * types are referenced).
     *
     * @param array<string, mixed> $handlerResponse Decoded `data` payload from the public AJAX submit response.
     *
     * @return array{success: true, message: string}
     */
    public function successBody(array $handlerResponse): array
    {
        $message = __('Form submitted successfully.', 'fluent-forms-extended-api');

        $result = $handlerResponse['result'] ?? null;
        if (is_array($result) && isset($result['message']) && is_string($result['message']) && $result['message'] !== '') {
            // Confirmation HTML is already sanitised inside Fluent Forms; strip tags for a plain JSON message.
            $message = wp_strip_all_tags($result['message']);
        }

        return [
            'success' => true,
            'message' => $message,
        ];
    }

    /**
     * @return array{success: false, message: string}
     */
    public function serverErrorBody(): array
    {
        return [
            'success' => false,
            'message' => __('An unexpected error occurred', 'fluent-forms-extended-api'),
        ];
    }

    /**
     * Pick an HTTP status for Fluent validation-style failures (defaults to 422).
     */
    public function validationHttpStatusFromCode(int $code): int
    {
        if (in_array($code, [400, 401, 403, 404, 422, 423, 429], true)) {
            return $code;
        }

        return 422;
    }
}
