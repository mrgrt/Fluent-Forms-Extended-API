<?php

declare(strict_types=1);

namespace FluentFormsExtendedApi\Support;

/**
 * Centralises checks for Fluent Forms availability (public API bootstrap).
 */
final class FluentFormsDependency
{
    /**
     * Fluent Forms documents {@see fluentFormApi()} as the supported programmatic entry point.
     *
     * @see https://developers.fluentforms.com/global-functions/
     */
    public function isAvailable(): bool
    {
        return function_exists('fluentFormApi');
    }

    /**
     * Human-readable reason for diagnostics (translatable for admin contexts).
     */
    public function getUnavailableMessage(): string
    {
        return __(
            'Fluent Forms is not installed, not active, or has not finished loading. Install and activate Fluent Forms to use this API.',
            'fluent-forms-extended-api'
        );
    }
}
