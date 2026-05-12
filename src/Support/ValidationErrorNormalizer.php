<?php

declare(strict_types=1);

namespace FluentFormsExtendedApi\Support;

/**
 * Turns Fluent Forms validation payloads into a stable, UI-friendly REST contract.
 *
 * Enriches raw field keys using the same normalised schema as {@see FieldSchemaTransformer::buildNormalizedFields()}
 * (via {@see FormService::getNormalizedFields()}), indexing both logical `id` and `submit_key` where present,
 * without exposing Fluent's internal error arrays.
 */
final class ValidationErrorNormalizer
{
    /**
     * @param array<string, mixed>   $rawErrors         Value from {@see \FluentForm\Framework\Validator\ValidationException::errors()} (or equivalent shape).
     * @param list<array<string, mixed>> $normalizedFields Output of the shared field schema transformer.
     *
     * @return array{success: false, errors: list<array{field: string, label: string, message: string, type: string}>}
     */
    public function normalizeValidationBody(array $rawErrors, array $normalizedFields): array
    {
        $flatMessages = $this->extractFlatFieldMessages($rawErrors);
        $lookup       = $this->buildFieldLookup($normalizedFields);

        $rows = [];
        foreach ($flatMessages as $fieldKey => $message) {
            $meta = $this->resolveFieldMeta((string) $fieldKey, $lookup);
            $rows[] = [
                'field'   => (string) $fieldKey,
                'label'   => $meta['label'],
                'message' => $message,
                'type'    => $meta['type'],
            ];
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                return strcmp((string) ($a['field'] ?? ''), (string) ($b['field'] ?? ''));
            }
        );

        return [
            'success' => false,
            'errors'  => $rows,
        ];
    }

    /**
     * Flatten Fluent's exception payload to field key => single human-readable string.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, string>
     */
    private function extractFlatFieldMessages(array $raw): array
    {
        $errors = $raw['errors'] ?? $raw;

        if (is_string($errors)) {
            return ['_form' => $errors];
        }

        if (! is_array($errors)) {
            return ['_form' => __('The given data was invalid.', 'fluent-forms-extended-api')];
        }

        $flat = [];
        foreach ($errors as $field => $messages) {
            if (! is_string($field) || $field === '') {
                continue;
            }
            $flat[ $field ] = $this->firstHumanMessage($messages);
        }

        return $flat !== [] ? $flat : ['_form' => __('The given data was invalid.', 'fluent-forms-extended-api')];
    }

    /**
     * Map normalised field `id` values (and common alias shapes) to label + type.
     *
     * @param list<array<string, mixed>> $normalizedFields
     *
     * @return array<string, array{label: string, type: string}>
     */
    private function buildFieldLookup(array $normalizedFields): array
    {
        $lookup = [];

        foreach ($normalizedFields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $this->registerFieldAndDescendants($field, $lookup);
        }

        return $lookup;
    }

    /**
     * Index a normalised field row by logical `id`, `submit_key`, and common alias spellings.
     *
     * @param array<string, mixed>                           $field
     * @param array<string, array{label: string, type: string}> $lookup
     */
    private function registerFieldAndDescendants(array $field, array &$lookup): void
    {
        $id = isset($field['id']) ? (string) $field['id'] : '';
        if ($id === '') {
            return;
        }

        $submitKey = isset($field['submit_key']) ? (string) $field['submit_key'] : '';

        $entry = [
            'label' => isset($field['label']) && is_string($field['label']) ? $field['label'] : '',
            'type'  => isset($field['type']) && is_string($field['type']) ? $field['type'] : 'unknown',
        ];

        foreach ($this->expandFieldKeyAliases($id) as $alias) {
            if ($alias !== '') {
                $lookup[ $alias ] = $entry;
            }
        }

        if ($submitKey !== '' && $submitKey !== $id) {
            foreach ($this->expandFieldKeyAliases($submitKey) as $alias) {
                if ($alias !== '') {
                    $lookup[ $alias ] = $entry;
                }
            }
        }

        if (! empty($field['fields']) && is_array($field['fields'])) {
            foreach ($field['fields'] as $child) {
                if (is_array($child)) {
                    $this->registerFieldAndDescendants($child, $lookup);
                }
            }
        }
    }

    /**
     * Register alternate key spellings Fluent may use in validation (dot vs bracket segments).
     *
     * @return list<string>
     */
    private function expandFieldKeyAliases(string $id): array
    {
        $aliases = [$id];

        // `names.first_name` <-> `names[first_name]` (and deeper paths).
        if (strpos($id, '.') !== false && strpos($id, '[') === false) {
            $parts = explode('.', $id);
            $root  = array_shift($parts);
            if ($root !== null && $root !== '' && $parts !== []) {
                $aliases[] = $root . '[' . implode('][', $parts) . ']';
            }
        }

        if (strpos($id, '[') !== false) {
            $dot = preg_replace('/\[([^\]]+)\]/', '.$1', $id);
            if (is_string($dot) && $dot !== '' && $dot !== $id) {
                $aliases[] = $dot;
            }
        }

        /**
         * Allow custom mappings when Fluent uses non-standard keys (e.g. legacy add-ons).
         *
         * @param list<string> $aliases
         */
        $aliases = apply_filters('fluent_forms_extended_api_validation_field_aliases', array_values(array_unique(array_filter($aliases))), $id);

        return is_array($aliases) ? $aliases : [$id];
    }

    /**
     * @param array<string, array{label: string, type: string}> $lookup
     *
     * @return array{label: string, type: string}
     */
    private function resolveFieldMeta(string $fieldKey, array $lookup): array
    {
        $default = [
            'label' => '',
            'type'  => 'unknown',
        ];

        if (isset($lookup[ $fieldKey ])) {
            return $lookup[ $fieldKey ];
        }

        foreach ($this->expandFieldKeyAliases($fieldKey) as $alias) {
            if (isset($lookup[ $alias ])) {
                return $lookup[ $alias ];
            }
        }

        /**
         * Last-resort enrichment for keys not present in the normalised schema (e.g. captcha, honeypot).
         *
         * @param array{label: string, type: string} $default
         *
         * @return array{label: string, type: string}
         */
        $enriched = apply_filters('fluent_forms_extended_api_validation_field_meta', $default, $fieldKey, $lookup);

        if (! is_array($enriched)) {
            return $default;
        }

        return [
            'label' => isset($enriched['label']) && is_string($enriched['label']) ? $enriched['label'] : '',
            'type'  => isset($enriched['type']) && is_string($enriched['type']) ? $enriched['type'] : 'unknown',
        ];
    }

    /**
     * @param mixed $messages
     */
    private function firstHumanMessage($messages): string
    {
        if (is_string($messages)) {
            return wp_strip_all_tags($messages);
        }

        if (is_array($messages)) {
            foreach ($messages as $item) {
                if (is_string($item) && $item !== '') {
                    return wp_strip_all_tags($item);
                }
                if (is_array($item)) {
                    $nested = $this->firstHumanMessage($item);
                    if ($nested !== '') {
                        return $nested;
                    }
                }
            }
        }

        return __('This field is invalid.', 'fluent-forms-extended-api');
    }
}
