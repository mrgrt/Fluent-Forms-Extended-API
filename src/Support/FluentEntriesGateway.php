<?php

declare(strict_types=1);

namespace FluentFormsExtendedApi\Support;

/**
 * Thin abstraction over Fluent Forms' documented entry/submission public APIs.
 *
 * Uses only:
 *   - {@see fluentFormApi('submissions')->get()}   — cross-form submissions list
 *   - {@see fluentFormApi('submissions')->find()}  — single submission by id
 *   - {@see fluentFormApi('forms')->entryInstance($form)->entries()} — per-form entries
 *
 * @see https://fluentforms.com/docs/fluent-form-php-api/
 * @see https://developers.fluentforms.com/global-functions/
 */
final class FluentEntriesGateway
{
    /**
     * Documented public PHP API: Fluent Forms' submissions module.
     *
     * @return mixed Untyped to avoid referencing any internal namespace.
     */
    public function submissionsModule()
    {
        return fluentFormApi('submissions');
    }

    /**
     * Documented public PHP API: Fluent Forms' forms module.
     *
     * @return mixed Untyped to avoid referencing any internal namespace.
     */
    public function formsModule()
    {
        return fluentFormApi('forms');
    }

    /**
     * Fetch a paginated batch of submissions across ALL forms.
     *
     * Mirrors the upstream return contract verbatim (no DB access here).
     *
     * @param int    $page     1-indexed page number.
     * @param int    $perPage  Submissions per page (>=1).
     * @param string $sortType Validated `ASC` or `DESC`.
     *
     * @return array{current_page:int,per_page:int,from:?int,to:?int,last_page:int,total:int,data:array<int, object>}
     */
    public function listAllSubmissions(int $page, int $perPage, string $sortType): array
    {
        $result = $this->submissionsModule()->get(
            [
                'per_page'   => $perPage,
                'page'       => $page,
                'sort_type'  => $sortType,
                'entry_type' => 'all',
            ]
        );

        return is_array($result) ? $result : [];
    }

    /**
     * Look up a single submission by id via the documented public API.
     *
     * `fluentFormApi('submissions')->find()` does not null-guard internally —
     * it dereferences `$submission->response` immediately, so on a missing id
     * PHP 8+ raises an Error. We swallow that and any other throwable so the
     * caller can map a missing/invalid entry cleanly to HTTP 404 without ever
     * touching a Fluent Forms model or repository directly.
     *
     * @return object|null Submission row object (response field decoded), or null when not found.
     */
    public function findSubmission(int $entryId)
    {
        try {
            $row = $this->submissionsModule()->find($entryId);
        } catch (\Throwable $e) {
            return null;
        }

        // Defensive: a valid row must be an object with an `id`. Anything else (null, empty stdClass) => 404.
        if (! is_object($row) || empty($row->id)) {
            return null;
        }

        return $row;
    }

    /**
     * Fetch a paginated batch of entries for a single form.
     *
     * @param object $form     Form row from {@see FluentFormsGateway::findForm()}.
     * @param int    $page     1-indexed page number.
     * @param int    $perPage  Entries per page (>=1).
     * @param string $sortType Validated `ASC` or `DESC`.
     *
     * @return array{current_page:int,per_page:int,from:?int,to:?int,last_page:int,total:int,data:array<int, object>}
     */
    public function listFormEntries(object $form, int $page, int $perPage, string $sortType): array
    {
        // entryInstance() is the documented public factory for the per-form entry API,
        // so we never instantiate any Fluent Forms class ourselves.
        $entryApi = $this->formsModule()->entryInstance($form);

        $result = $entryApi->entries(
            [
                'per_page'   => $perPage,
                'page'       => $page,
                'sort_type'  => $sortType,
                'entry_type' => 'all',
            ],
            false
        );

        return is_array($result) ? $result : [];
    }
}
