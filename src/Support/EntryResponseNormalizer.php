<?php

declare(strict_types=1);

namespace FluentFormsExtendedApi\Support;

/**
 * Maps Fluent Forms' Submission row objects to our stable REST entry contract.
 *
 * The fields we surface are deliberately a subset of the underlying model so
 * the public API doesn't drift if Fluent Forms adds/removes internal columns.
 */
final class EntryResponseNormalizer
{
    /**
     * Wrap a Fluent Forms entries-list result into our paginated envelope.
     *
     * @param array{current_page?:mixed,per_page?:mixed,last_page?:mixed,total?:mixed,data?:mixed} $upstream
     *
     * @return array{
     *     total:int,
     *     current_page:int,
     *     per_page:int,
     *     total_pages:int,
     *     data:list<array<string, mixed>>
     * }
     */
    public function paginatedBody(array $upstream): array
    {
        $rawRows = $upstream['data'] ?? [];
        $rows    = is_array($rawRows) || $rawRows instanceof \Traversable ? $rawRows : [];

        $entries = [];
        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }
            $entries[] = $this->entryBody($row);
        }

        return [
            'total'        => (int) ($upstream['total'] ?? 0),
            'current_page' => (int) ($upstream['current_page'] ?? 1),
            'per_page'     => (int) ($upstream['per_page'] ?? count($entries)),
            'total_pages'  => (int) ($upstream['last_page'] ?? 0),
            'data'         => $entries,
        ];
    }

    /**
     * Map a single Submission row object to the public entry shape used in LIST responses.
     *
     * The upstream list APIs (`Submission::get()`, `Entry::entries()`) decode `response`
     * to an associative array. The single-entry API (`Submission::find()`) decodes it to
     * a `stdClass` instead. {@see self::decodeResponseField()} normalises both back to
     * an associative array so the wire format is stable.
     *
     * @param object $row Fluent Forms `Submission` row object (public API output).
     *
     * @return array<string, mixed>
     */
    public function entryBody(object $row): array
    {
        return [
            'entry_id'   => isset($row->id) ? (int) $row->id : 0,
            'form_id'    => isset($row->form_id) ? (int) $row->form_id : 0,
            'status'     => isset($row->status) ? (string) $row->status : '',
            'created_at' => isset($row->created_at) ? (string) $row->created_at : '',
            // Cast 0/empty to null so JSON shows guests as "user_id": null rather than 0.
            'user_id'    => ! empty($row->user_id) ? (int) $row->user_id : null,
            // The submissions schema stores the IP as `ip`; we expose it as `user_ip` per our contract.
            'user_ip'    => isset($row->ip) ? (string) $row->ip : '',
            'browser'    => isset($row->browser) ? (string) $row->browser : '',
            'device'     => isset($row->device) ? (string) $row->device : '',
            'submission' => $this->decodeResponseField($row->response ?? null),
        ];
    }

    /**
     * Map a single Submission row object to the SINGLE-entry shape.
     *
     * Adds `updated_at` and `payment_status` on top of {@see self::entryBody()}.
     * `payment_status` is intentionally `null` when the form has no payment context
     * (rather than an empty string) so clients can distinguish "not applicable" from
     * a real status like "paid"/"pending".
     *
     * @param object $row Fluent Forms `Submission` row object.
     *
     * @return array<string, mixed>
     */
    public function singleEntryBody(object $row): array
    {
        $base = $this->entryBody($row);

        $paymentStatus = isset($row->payment_status) ? (string) $row->payment_status : '';

        return array_merge(
            $base,
            [
                'updated_at'     => isset($row->updated_at) ? (string) $row->updated_at : '',
                'payment_status' => $paymentStatus !== '' ? $paymentStatus : null,
            ]
        );
    }

    /**
     * Coerce the upstream `response` field into a stable associative array.
     *
     * Handles all three shapes Fluent Forms can hand us across public APIs:
     *   - associative array (from list APIs) — used as-is
     *   - `stdClass` (from {@see fluentFormApi('submissions')->find()}) — round-tripped via JSON
     *   - JSON string (defensive: in case a future upstream change skips decoding)
     *
     * Anything else falls back to an empty array so the response shape stays predictable.
     *
     * @param mixed $value
     *
     * @return array<string, mixed>
     */
    private function decodeResponseField($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (is_object($value)) {
            // `wp_json_encode()` is used as it sanely handles UTF-8 and recursive guards.
            $encoded = wp_json_encode($value);
            if (! is_string($encoded) || $encoded === '') {
                return [];
            }
            $decoded = json_decode($encoded, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
