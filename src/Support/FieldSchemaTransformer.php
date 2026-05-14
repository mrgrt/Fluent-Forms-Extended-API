<?php

declare(strict_types=1);

namespace FluentFormsExtendedApi\Support;

/**
 * Turns Fluent Forms API shapes into a stable, headless-friendly field schema.
 *
 * Consumes only the documented public PHP API surface exposed by
 * `fluentFormApi('forms')->form($form)`: the `fields()` editor tree for structure
 * (containers, composites) and the `inputs()` parser-backed metadata (rules,
 * options, labels). Composite blocks (name, address, repeaters, etc.) are
 * **flattened** into a single top-level `fields` list with dotted logical `id`,
 * optional `submit_key`, `group`, and `component` hints — raw editor nodes are
 * never returned and no Fluent Forms class names are referenced.
 */
final class FieldSchemaTransformer
{
    /**
     * Fluent composite elements that are flattened into leaf rows (no nested `fields` array).
     *
     * @var array<string, string> element => component hint (prefix for row `component`, e.g. `name`)
     */
    private const GROUP_ELEMENT_COMPONENTS = [
        'input_name'     => 'name',
        'address'        => 'address',
        'input_repeat'   => 'repeat',
        'repeater_field' => 'repeater',
    ];

    /**
     * @var array<string, string>
     */
    private const NAME_PART_COMPONENT_SUFFIX = [
        'first_name'  => 'first',
        'middle_name' => 'middle',
        'last_name'   => 'last',
    ];

    /**
     * @var array<string, string>
     */
    private const ADDRESS_PART_COMPONENT_SUFFIX = [
        'address_line_1' => 'line1',
        'address_line_2' => 'line2',
        'city'           => 'city',
        'state'          => 'state',
        'zip'            => 'zip',
        'country'        => 'country',
    ];

    /**
     * Build the public field list from decoded `fields()` plus flattened `inputs()`.
     *
     * @param array<string, mixed> $decodedRoot Result of `FormProperties::fields()`.
     * @param array<string, mixed> $entryInputs Keyed by input name from `FormProperties::inputs()`.
     *
     * @return list<array<string, mixed>>
     */
    public function buildNormalizedFields(array $decodedRoot, array $entryInputs): array
    {
        $editorFields = isset($decodedRoot['fields']) && is_array($decodedRoot['fields'])
            ? $decodedRoot['fields']
            : [];

        if ($editorFields === []) {
            return $this->fromFluentEntryInputs($entryInputs);
        }

        $out = [];
        $this->walkEditorFields($editorFields, $entryInputs, $out);

        return $out;
    }

    /**
     * Build normalised field rows from `inputs()` only (legacy / empty editor tree).
     *
     * @param array<string, mixed> $entryInputs Keyed by input name (Fluent Forms contract).
     *
     * @return list<array<string, mixed>>
     */
    public function fromFluentEntryInputs(array $entryInputs): array
    {
        $rows = [];

        foreach ($entryInputs as $name => $meta) {
            if (! is_string($name) || $name === '' || ! is_array($meta)) {
                continue;
            }

            $element = isset($meta['element']) ? (string) $meta['element'] : '';

            $label = $this->labelFromInputMeta($meta);
            $attributes = isset($meta['attributes']) && is_array($meta['attributes']) ? $meta['attributes'] : [];
            $placeholder = isset($attributes['placeholder']) ? (string) $attributes['placeholder'] : '';

            $rules = isset($meta['rules']) && is_array($meta['rules']) ? $meta['rules'] : null;

            $canonical = $this->canonicalTypeFromFluentElement($element);

            $row = [
                'id'          => $name,
                'type'        => $canonical,
                'label'       => $label,
                'required'    => $this->inferRequiredFromRules($rules),
                'placeholder' => $placeholder,
            ];

            $component = $this->optionalComponentHint($element, $canonical);
            if ($component !== null) {
                $row['component'] = $component;
            }

            $options = $this->optionsListFromInputMeta($meta);
            if ($options !== null) {
                $row['options'] = $options;
            }

            $rootRequired = (bool) ($row['required'] ?? false);
            $validation   = $this->summariseValidationRules($rules, $rootRequired, $canonical);
            if ($validation !== null) {
                $row['validation'] = $validation;
            }

            $rows[] = $row;
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
            }
        );

        return $rows;
    }

    /**
     * @param array<string, mixed>|null $decodedRoot Result of `FormProperties::fields()`.
     */
    public function submitButtonFromDecodedFormFields(?array $decodedRoot): ?array
    {
        if ($decodedRoot === null || ! isset($decodedRoot['submitButton']) || ! is_array($decodedRoot['submitButton'])) {
            return null;
        }

        return $this->normaliseSubmitButton($decodedRoot['submitButton']);
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed>       $entryInputs
     * @param list<array<string, mixed>> $out
     */
    private function walkEditorFields(array $fields, array $entryInputs, array &$out): void
    {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $element = isset($field['element']) ? (string) $field['element'] : '';

            if ($element === 'container' && ! empty($field['columns']) && is_array($field['columns'])) {
                foreach ($field['columns'] as $column) {
                    if (is_array($column) && ! empty($column['fields']) && is_array($column['fields'])) {
                        $this->walkEditorFields($column['fields'], $entryInputs, $out);
                    }
                }
                continue;
            }

            if ($element === 'step_form' && ! empty($field['fields']) && is_array($field['fields'])) {
                $this->walkEditorFields($field['fields'], $entryInputs, $out);
                continue;
            }

            if ($element === 'tabular_grid') {
                $leaf = $this->normalizeLeafEditorField($field, $entryInputs);
                if ($leaf !== null) {
                    $out[] = $leaf;
                }
                continue;
            }

            if ($this->isGroupElement($element) && ! empty($field['fields']) && is_array($field['fields'])) {
                $this->flattenComposite($field, $element, $entryInputs, $out);
                continue;
            }

            $leaf = $this->normalizeLeafEditorField($field, $entryInputs);
            if ($leaf !== null) {
                $out[] = $leaf;
            }
        }
    }

    private function isGroupElement(string $element): bool
    {
        return isset(self::GROUP_ELEMENT_COMPONENTS[ $element ]);
    }

    /**
     * @param array<string, mixed>       $node
     * @param array<string, mixed>       $entryInputs
     * @param list<array<string, mixed>> $out
     */
    private function flattenComposite(array $node, string $element, array $entryInputs, array &$out): void
    {
        $attributes = isset($node['attributes']) && is_array($node['attributes']) ? $node['attributes'] : [];
        $groupId    = isset($attributes['name']) ? (string) $attributes['name'] : '';
        if ($groupId === '') {
            $groupId = $this->fallbackNodeId($node);
        }

        $groupComponent = self::GROUP_ELEMENT_COMPONENTS[ $element ] ?? $element;

        $childFields = $node['fields'];
        if (! is_array($childFields)) {
            return;
        }

        foreach ($childFields as $childIndex => $child) {
            if (! is_array($child)) {
                continue;
            }

            $childElement = isset($child['element']) ? (string) $child['element'] : '';

            if ($this->isGroupElement($childElement) && ! empty($child['fields']) && is_array($child['fields'])) {
                $this->flattenComposite($child, $childElement, $entryInputs, $out);
                continue;
            }

            if (! $this->isParentChildActiveInEditor($child, $element)) {
                continue;
            }

            $row = $this->buildFlattenedCompositeChildRow(
                $child,
                $element,
                $groupId,
                $groupComponent,
                (string) $childIndex,
                $entryInputs
            );
            if ($row !== null) {
                $out[] = $row;
            }
        }
    }

    /**
     * Mirror Fluent's parser behaviour for name/address children — a child subfield is
     * "active" when its editor settings don't explicitly disable it. The rule is derived
     * from the documented `fields()` editor payload only; no internal parser classes are
     * referenced.
     *
     * @param array<string, mixed> $child
     */
    private function isParentChildActiveInEditor(array $child, string $parentElement): bool
    {
        if (in_array($parentElement, ['input_name', 'address'], true)) {
            return $this->isAddressOrNameSubfieldActive($child);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $child
     */
    private function isAddressOrNameSubfieldActive(array $child): bool
    {
        $attributes = isset($child['attributes']) && is_array($child['attributes']) ? $child['attributes'] : [];
        $subName    = isset($attributes['name']) ? (string) $attributes['name'] : '';
        if ($subName !== '' && in_array($subName, ['latitude', 'longitude'], true)) {
            return true;
        }

        $settings = isset($child['settings']) && is_array($child['settings']) ? $child['settings'] : [];

        return $this->boolish($settings['visible'] ?? false);
    }

    /**
     * @param array<string, mixed> $child
     * @param array<string, mixed> $entryInputs
     *
     * @return array<string, mixed>|null
     */
    private function buildFlattenedCompositeChildRow(
        array $child,
        string $parentElement,
        string $groupId,
        string $groupComponent,
        string $childIndex,
        array $entryInputs
    ): ?array {
        $childElement = isset($child['element']) ? (string) $child['element'] : '';

        if ($childElement === '' || $this->shouldSkipLeafElement($childElement)) {
            return null;
        }

        $attributes = isset($child['attributes']) && is_array($child['attributes']) ? $child['attributes'] : [];
        $settings   = isset($child['settings']) && is_array($child['settings']) ? $child['settings'] : [];

        $partName = isset($attributes['name']) ? (string) $attributes['name'] : '';

        if ($partName === '' && $childElement !== 'custom_html' && $childElement !== 'section_break') {
            return null;
        }

        $submitKey = $this->resolveSubmitKeyForCompositeChild(
            $parentElement,
            $groupId,
            $partName,
            $childIndex,
            $entryInputs
        );

        $meta = isset($entryInputs[ $submitKey ]) && is_array($entryInputs[ $submitKey ])
            ? $entryInputs[ $submitKey ]
            : [];

        $logicalId = $this->buildLogicalCompositeChildId($parentElement, $groupId, $partName, $childIndex);

        $rowComponent = $this->compositeRowComponent($parentElement, $groupComponent, $partName, $childIndex);

        return $this->composeFieldRow(
            $child,
            $entryInputs,
            $meta,
            $submitKey,
            $logicalId,
            $groupId,
            $rowComponent
        );
    }

    private function buildLogicalCompositeChildId(string $parentElement, string $groupId, string $partName, string $childIndex): string
    {
        if (in_array($parentElement, ['input_repeat', 'repeater_field'], true)) {
            $segment = $partName !== '' ? $partName : $childIndex;

            return $groupId . '.' . $segment;
        }

        if ($partName !== '') {
            return $groupId . '.' . $partName;
        }

        return $groupId . '.' . $childIndex;
    }

    private function compositeRowComponent(string $parentElement, string $groupComponent, string $partName, string $childIndex): string
    {
        if ($parentElement === 'input_name' && $partName !== '') {
            $suffix = self::NAME_PART_COMPONENT_SUFFIX[ $partName ] ?? $partName;

            return $groupComponent . '.' . $suffix;
        }

        if ($parentElement === 'address' && $partName !== '') {
            $suffix = self::ADDRESS_PART_COMPONENT_SUFFIX[ $partName ] ?? $partName;

            return $groupComponent . '.' . $suffix;
        }

        if (in_array($parentElement, ['input_repeat', 'repeater_field'], true)) {
            return $groupComponent . '.' . ($partName !== '' ? $partName : $childIndex);
        }

        return $groupComponent . '.' . ($partName !== '' ? $partName : $childIndex);
    }

    /**
     * Resolve the Fluent `inputs()` key for a composite child (matches parser naming where possible).
     *
     * @param array<string, mixed> $entryInputs
     */
    private function resolveSubmitKeyForCompositeChild(
        string $parentElement,
        string $groupId,
        string $partName,
        string $childIndex,
        array $entryInputs
    ): string {
        if (in_array($parentElement, ['input_repeat', 'repeater_field'], true)) {
            return $this->resolveRepeaterChildSubmitKey($groupId, $partName, $childIndex, $entryInputs);
        }

        if ($partName === '') {
            return $groupId;
        }

        if (isset($entryInputs[ $partName ]) && is_array($entryInputs[ $partName ])) {
            return $partName;
        }

        $pattern = '/^' . preg_quote($groupId, '/') . '_' . preg_quote($partName, '/') . '_\d+$/';
        foreach (array_keys($entryInputs) as $key) {
            if (! is_string($key)) {
                continue;
            }
            if (preg_match($pattern, $key) === 1) {
                return $key;
            }
        }

        $bracket = $groupId . '[' . $partName . ']';
        if (isset($entryInputs[ $bracket ]) && is_array($entryInputs[ $bracket ])) {
            return $bracket;
        }

        return $groupId . '_' . $partName . '_1';
    }

    /**
     * @param array<string, mixed> $entryInputs
     */
    private function resolveRepeaterChildSubmitKey(string $groupId, string $partName, string $childIndex, array $entryInputs): string
    {
        $wild = $groupId . '[' . $childIndex . '].*';
        if (isset($entryInputs[ $wild ]) && is_array($entryInputs[ $wild ])) {
            return $wild;
        }

        $candidates = [];
        if ($partName !== '') {
            $candidates[] = $groupId . '[' . $childIndex . '][' . $partName . ']';
        }
        foreach ($candidates as $c) {
            if (isset($entryInputs[ $c ]) && is_array($entryInputs[ $c ])) {
                return $c;
            }
        }

        $prefix = $groupId . '[' . $childIndex . ']';
        foreach (array_keys($entryInputs) as $key) {
            if (! is_string($key)) {
                continue;
            }
            if (strpos($key, $prefix) === 0) {
                return $key;
            }
        }

        return $partName !== '' ? ($groupId . '[' . $childIndex . '][' . $partName . ']') : $wild;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $entryInputs
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>|null
     */
    private function composeFieldRow(
        array $node,
        array $entryInputs,
        array $meta,
        string $submitKey,
        string $logicalId,
        ?string $groupId,
        ?string $rowComponent
    ): ?array {
        $element = isset($node['element']) ? (string) $node['element'] : '';

        if ($element === '' || $this->shouldSkipLeafElement($element)) {
            return null;
        }

        $attributes = isset($node['attributes']) && is_array($node['attributes']) ? $node['attributes'] : [];
        $settings   = isset($node['settings']) && is_array($node['settings']) ? $node['settings'] : [];

        if ($submitKey === '' && $element !== 'custom_html' && $element !== 'section_break') {
            return null;
        }

        $label = $this->labelFromInputMeta($meta);
        if ($label === '' && isset($settings['label'])) {
            $label = is_string($settings['label']) ? wp_strip_all_tags($settings['label']) : '';
        }

        $rules = null;
        if (isset($meta['rules']) && is_array($meta['rules'])) {
            $rules = $meta['rules'];
        } elseif (isset($settings['validation_rules']) && is_array($settings['validation_rules'])) {
            $rules = $settings['validation_rules'];
        }

        $placeholder = '';
        if (isset($attributes['placeholder'])) {
            $placeholder = (string) $attributes['placeholder'];
        } elseif (isset($settings['placeholder'])) {
            $placeholder = (string) $settings['placeholder'];
        }

        $canonical = $this->canonicalTypeFromFluentElement($element);

        $row = [
            'id'          => $logicalId,
            'type'        => $canonical,
            'label'       => $label,
            'required'    => $this->inferRequiredFromRules($rules),
            'placeholder' => $placeholder,
        ];

        if ($groupId !== null && $groupId !== '') {
            $row['group'] = $groupId;
        }

        if ($rowComponent !== null && $rowComponent !== '') {
            $row['component'] = $rowComponent;
        } else {
            $hint = $this->optionalComponentHint($element, $canonical);
            if ($hint !== null) {
                $row['component'] = $hint;
            }
        }

        if (isset($meta['options']) && is_array($meta['options'])) {
            $options = $this->optionsListFromInputMeta($meta);
            if ($options !== null) {
                $row['options'] = $options;
            }
        }

        $rootRequired = (bool) ($row['required'] ?? false);
        $validation   = $this->summariseValidationRules($rules, $rootRequired, $canonical);
        if ($validation !== null) {
            $row['validation'] = $validation;
        }

        if ($logicalId !== $submitKey) {
            $row['submit_key'] = $submitKey;
        }

        $filtered = apply_filters('fluent_forms_extended_api_normalize_leaf_field', $row, $node, $entryInputs);

        return is_array($filtered) ? $filtered : $row;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $entryInputs
     *
     * @return array<string, mixed>|null
     */
    private function normalizeLeafEditorField(array $node, array $entryInputs): ?array
    {
        $element = isset($node['element']) ? (string) $node['element'] : '';

        if ($element === '' || $this->shouldSkipLeafElement($element)) {
            return null;
        }

        $attributes = isset($node['attributes']) && is_array($node['attributes']) ? $node['attributes'] : [];
        $settings   = isset($node['settings']) && is_array($node['settings']) ? $node['settings'] : [];

        $inputName = isset($attributes['name']) ? (string) $attributes['name'] : '';
        if ($inputName === '') {
            if ($element === 'custom_html') {
                return [
                    'id'          => $this->fallbackNodeId($node),
                    'type'        => 'html',
                    'component'   => 'custom_html',
                    'label'       => isset($settings['label']) && is_string($settings['label'])
                        ? wp_strip_all_tags($settings['label'])
                        : '',
                    'required'    => false,
                    'placeholder' => '',
                ];
            }

            if ($element === 'section_break') {
                return [
                    'id'          => $this->fallbackNodeId($node),
                    'type'        => 'separator',
                    'component'   => 'section_break',
                    'label'       => isset($settings['label']) && is_string($settings['label'])
                        ? wp_strip_all_tags($settings['label'])
                        : '',
                    'required'    => false,
                    'placeholder' => '',
                ];
            }

            return null;
        }

        $meta = isset($entryInputs[ $inputName ]) && is_array($entryInputs[ $inputName ])
            ? $entryInputs[ $inputName ]
            : [];

        return $this->composeFieldRow($node, $entryInputs, $meta, $inputName, $inputName, null, null);
    }

    private function shouldSkipLeafElement(string $element): bool
    {
        return in_array(
            $element,
            [
                'container',
                'button',
                'custom_submit_button',
                'save_and_continue',
            ],
            true
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    private function fallbackNodeId(array $node): string
    {
        if (! empty($node['uniqElKey'])) {
            return (string) $node['uniqElKey'];
        }

        return 'field_' . substr(md5((string) wp_json_encode($node)), 0, 12);
    }

    /**
     * Map Fluent `element` string to a small canonical vocabulary for clients.
     */
    private function canonicalTypeFromFluentElement(string $element): string
    {
        static $map = [
            'input_text'          => 'text',
            'input_email'         => 'email',
            'input_url'           => 'url',
            'input_number'        => 'number',
            'input_password'      => 'password',
            'input_hidden'        => 'hidden',
            'textarea'            => 'textarea',
            'select'              => 'select',
            'input_radio'         => 'radio',
            'input_checkbox'      => 'checkbox',
            'input_date'          => 'date',
            'input_file'          => 'file',
            'input_image'         => 'file',
            'select_country'      => 'select',
            'phone'               => 'phone',
            'input_mask'          => 'text',
            'terms_and_condition' => 'checkbox',
            'gdpr_agreement'      => 'checkbox',
            'ratings'             => 'rating',
            'net_promoter'        => 'scale',
        ];

        if (isset($map[ $element ])) {
            return $map[ $element ];
        }

        if (strncmp($element, 'input_', 6) === 0) {
            return substr($element, 6);
        }

        return $element !== '' ? $element : 'unknown';
    }

    /**
     * When the Fluent element carries more nuance than the canonical type alone.
     */
    private function optionalComponentHint(string $element, string $canonical): ?string
    {
        if ($element === '') {
            return null;
        }

        if (strncmp($element, 'input_', 6) === 0) {
            $stripped = substr($element, 6);
            if ($stripped === $canonical) {
                return null;
            }
        }

        if ($element === 'select_country' && $canonical === 'select') {
            return 'select_country';
        }

        if ($canonical === 'select' && $element !== 'select') {
            return $element;
        }

        if ($element !== $canonical) {
            return $element;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function labelFromInputMeta(array $meta): string
    {
        if (isset($meta['label']) && is_string($meta['label'])) {
            return wp_strip_all_tags($meta['label']);
        }
        if (isset($meta['admin_label']) && is_string($meta['admin_label'])) {
            return wp_strip_all_tags($meta['admin_label']);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $submitButton Fluent Forms `submitButton` node.
     *
     * @return array<string, mixed>
     */
    private function normaliseSubmitButton(array $submitButton): array
    {
        $settings = isset($submitButton['settings']) && is_array($submitButton['settings'])
            ? $submitButton['settings']
            : [];

        $buttonUi = isset($settings['button_ui']) && is_array($settings['button_ui'])
            ? $settings['button_ui']
            : [];

        $text   = isset($buttonUi['text']) ? (string) $buttonUi['text'] : '';
        $imgUrl = isset($buttonUi['img_url']) ? (string) $buttonUi['img_url'] : '';

        $out = [
            'text' => $text !== '' ? wp_strip_all_tags($text) : 'Submit',
        ];

        if ($imgUrl !== '') {
            $out['image_url'] = esc_url_raw($imgUrl);
        }

        foreach (['align', 'size', 'type'] as $key) {
            if (isset($buttonUi[ $key ]) && is_scalar($buttonUi[ $key ])) {
                $out[ $key ] = (string) $buttonUi[ $key ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $rules
     */
    private function inferRequiredFromRules(?array $rules): bool
    {
        if ($rules === null) {
            return false;
        }

        if (isset($rules['required'])) {
            $req = $rules['required'];
            if (is_array($req) && array_key_exists('value', $req)) {
                return $this->boolish($req['value']);
            }

            return $this->boolish($req);
        }

        if (! empty($rules['rules']) && is_array($rules['rules'])) {
            foreach ($rules['rules'] as $rule) {
                if (is_array($rule) && ($rule['rule'] ?? '') === 'required') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return list<array{label:string,value:string}>|null
     */
    private function optionsListFromInputMeta(array $meta): ?array
    {
        if (empty($meta['options']) || ! is_array($meta['options'])) {
            return null;
        }

        $list = [];
        foreach ($meta['options'] as $value => $label) {
            $list[] = [
                'label' => is_scalar($label) ? (string) $label : '',
                'value' => is_scalar($value) ? (string) $value : '',
            ];
        }

        return $list === [] ? null : $list;
    }

    /**
     * @param array<string, mixed>|null $rules
     *
     * @return array<string, mixed>|null
     */
    private function summariseValidationRules(?array $rules, bool $rootRequired, string $canonicalType): ?array
    {
        if ($rules === null) {
            return null;
        }

        $summary = [];

        foreach (['max', 'digits', 'max_file_count', 'max_file_size'] as $key) {
            if (! array_key_exists($key, $rules)) {
                continue;
            }
            $coerced = $this->coerceRuleScalar($rules[ $key ]);
            if ($coerced !== null) {
                $summary[ $key ] = $coerced;
            }
        }

        if (array_key_exists('min', $rules)) {
            $minVal = $this->coerceRuleScalar($rules['min']);
            if ($minVal !== null) {
                if (in_array($canonicalType, ['text', 'textarea'], true)) {
                    $summary['minLength'] = $minVal;
                } else {
                    $summary['min'] = $minVal;
                }
            }
        }

        if (! empty($rules['rules']) && is_array($rules['rules'])) {
            $simplified = [];
            foreach ($rules['rules'] as $rule) {
                if (! is_array($rule)) {
                    continue;
                }
                if ($rootRequired && ($rule['rule'] ?? '') === 'required') {
                    continue;
                }
                $entry = array_intersect_key(
                    $rule,
                    array_flip(['rule', 'value', 'message', 'element'])
                );
                if ($entry !== []) {
                    $simplified[] = $entry;
                }
            }
            if ($simplified !== []) {
                $summary['rules'] = $simplified;
            }
        }

        return $summary === [] ? null : $summary;
    }

    /**
     * @param mixed $value
     */
    private function boolish($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalised = strtolower($value);

            return in_array($normalised, ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * @param mixed $rulePart Fluent may store scalars or `['value' => …, 'message' => …]` shapes.
     *
     * @return string|int|float|bool|null
     */
    private function coerceRuleScalar($rulePart)
    {
        if (is_scalar($rulePart)) {
            return $rulePart;
        }

        if (is_array($rulePart) && array_key_exists('value', $rulePart) && is_scalar($rulePart['value'])) {
            return $rulePart['value'];
        }

        return null;
    }
}
