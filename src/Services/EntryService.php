<?php

declare(strict_types=1);

namespace FluentFormsExtendedApi\Services;

use FluentFormsExtendedApi\Support\EntryResponseNormalizer;
use FluentFormsExtendedApi\Support\FluentEntriesGateway;
use FluentFormsExtendedApi\Support\FluentFormsDependency;
use FluentFormsExtendedApi\Support\FluentFormsGateway;

/**
 * Orchestrates entry retrieval for the REST layer.
 *
 * Speaks ONLY to Fluent Forms' documented PHP API via {@see FluentEntriesGateway}
 * and {@see FluentFormsGateway} — no $wpdb, no internal models, no raw SQL.
 */
final class EntryService
{
    /**
     * Sort columns we expose publicly. Both map to the supported upstream sort.
     *
     * Fluent Forms' public `Submission::get()` and `Entry::entries()` both order by
     * `id` internally. Since `submissions.id` is auto-increment, sorting by `id` is
     * monotonic with `created_at`, so we accept either alias without lying about
     * the contract — but we reject anything else with 400 (see {@see normalizeQuery()}).
     *
     * @var array<int, string>
     */
    private const ALLOWED_SORT_BY = ['id', 'created_at'];

    /**
     * @var array<int, string>
     */
    private const ALLOWED_SORT_ORDER = ['ASC', 'DESC'];

    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE     = 100;

    private FluentFormsDependency $dependency;

    private FluentFormsGateway $formsGateway;

    private FluentEntriesGateway $entriesGateway;

    private EntryResponseNormalizer $normalizer;

    public function __construct(
        FluentFormsDependency $dependency,
        FluentFormsGateway $formsGateway,
        FluentEntriesGateway $entriesGateway,
        EntryResponseNormalizer $normalizer
    ) {
        $this->dependency     = $dependency;
        $this->formsGateway   = $formsGateway;
        $this->entriesGateway = $entriesGateway;
        $this->normalizer     = $normalizer;
    }

    /**
     * GET /entries — paginated entries across all forms.
     *
     * @param array<string, mixed> $rawQuery Sanitised query params from the request.
     *
     * @return array{http_status:int, body:array<string, mixed>}
     */
    public function listAll(array $rawQuery): array
    {
        if (! $this->dependency->isAvailable()) {
            return $this->dependencyErrorPayload();
        }

        $query = $this->normalizeQuery($rawQuery);
        if (isset($query['__error'])) {
            return $query['__error'];
        }

        $upstream = $this->entriesGateway->listAllSubmissions(
            $query['page'],
            $query['per_page'],
            $query['sort_type']
        );

        return [
            'http_status' => 200,
            'body'        => $this->normalizer->paginatedBody($upstream),
        ];
    }

    /**
     * GET /entries/{form_id} — paginated entries for a single form.
     *
     * @param array<string, mixed> $rawQuery Sanitised query params from the request.
     *
     * @return array{http_status:int, body:array<string, mixed>}
     */
    public function listForForm(int $formId, array $rawQuery): array
    {
        if (! $this->dependency->isAvailable()) {
            return $this->dependencyErrorPayload();
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

        // Validate the form exists *before* querying entries (proper 404 per the spec).
        $form = $this->formsGateway->findForm($formId);
        if ($form === null) {
            return [
                'http_status' => 404,
                'body'        => [
                    'success' => false,
                    'message' => __('No form exists for the requested id.', 'fluent-forms-extended-api'),
                ],
            ];
        }

        $query = $this->normalizeQuery($rawQuery);
        if (isset($query['__error'])) {
            return $query['__error'];
        }

        $upstream = $this->entriesGateway->listFormEntries(
            $form,
            $query['page'],
            $query['per_page'],
            $query['sort_type']
        );

        return [
            'http_status' => 200,
            'body'        => $this->normalizer->paginatedBody($upstream),
        ];
    }

    /**
     * GET /entries/{entry_id} — single entry by id.
     *
     * Uses ONLY the public `fluentFormApi('submissions')->find()` path (via the
     * gateway's defensive wrapper); does not touch internal models/repositories.
     *
     * @return array{http_status:int, body:array<string, mixed>}
     */
    public function findOne(int $entryId): array
    {
        if (! $this->dependency->isAvailable()) {
            return $this->dependencyErrorPayload();
        }

        if ($entryId < 1) {
            return [
                'http_status' => 400,
                'body'        => [
                    'success' => false,
                    'message' => __('Invalid entry id.', 'fluent-forms-extended-api'),
                ],
            ];
        }

        $row = $this->entriesGateway->findSubmission($entryId);
        if ($row === null) {
            return [
                'http_status' => 404,
                'body'        => [
                    'success' => false,
                    'message' => __('No entry exists for the requested id.', 'fluent-forms-extended-api'),
                ],
            ];
        }

        return [
            'http_status' => 200,
            'body'        => $this->normalizer->singleEntryBody($row),
        ];
    }

    /**
     * Validate + coerce pagination/sort parameters; return an error payload on bad input.
     *
     * Centralising this keeps both endpoints behaving identically.
     *
     * @param array<string, mixed> $raw
     *
     * @return array{
     *     page:int,
     *     per_page:int,
     *     sort_by:string,
     *     sort_type:string,
     *     __error?:array{http_status:int, body:array<string, mixed>}
     * }
     */
    private function normalizeQuery(array $raw): array
    {
        $page = isset($raw['page']) ? (int) $raw['page'] : 1;
        if ($page < 1) {
            $page = 1;
        }

        $perPage = isset($raw['per_page']) ? (int) $raw['per_page'] : self::DEFAULT_PER_PAGE;
        if ($perPage < 1) {
            $perPage = self::DEFAULT_PER_PAGE;
        }
        if ($perPage > self::MAX_PER_PAGE) {
            $perPage = self::MAX_PER_PAGE;
        }

        $sortBy = isset($raw['sort_by']) && is_string($raw['sort_by']) ? strtolower($raw['sort_by']) : 'id';
        if (! in_array($sortBy, self::ALLOWED_SORT_BY, true)) {
            return [
                '__error' => [
                    'http_status' => 400,
                    'body'        => [
                        'success'        => false,
                        'message'        => __('Invalid sort_by value.', 'fluent-forms-extended-api'),
                        'allowed_sort_by' => self::ALLOWED_SORT_BY,
                    ],
                ],
                'page'      => $page,
                'per_page'  => $perPage,
                'sort_by'   => $sortBy,
                'sort_type' => 'DESC',
            ];
        }

        $sortOrder = isset($raw['sort_order']) && is_string($raw['sort_order']) ? strtoupper($raw['sort_order']) : 'DESC';
        if (! in_array($sortOrder, self::ALLOWED_SORT_ORDER, true)) {
            return [
                '__error' => [
                    'http_status' => 400,
                    'body'        => [
                        'success'           => false,
                        'message'           => __('Invalid sort_order value.', 'fluent-forms-extended-api'),
                        'allowed_sort_order' => self::ALLOWED_SORT_ORDER,
                    ],
                ],
                'page'      => $page,
                'per_page'  => $perPage,
                'sort_by'   => $sortBy,
                'sort_type' => 'DESC',
            ];
        }

        return [
            'page'      => $page,
            'per_page'  => $perPage,
            'sort_by'   => $sortBy,
            // The upstream public APIs use `sort_type` (not `sort_order`).
            'sort_type' => $sortOrder,
        ];
    }

    /**
     * @return array{http_status:int, body:array<string, mixed>}
     */
    private function dependencyErrorPayload(): array
    {
        return [
            'http_status' => 503,
            'body'        => [
                'success' => false,
                'message' => $this->dependency->getUnavailableMessage(),
            ],
        ];
    }
}
