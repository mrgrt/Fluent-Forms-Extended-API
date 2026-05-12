<?php

declare(strict_types=1);

namespace FluentFormsExtendedApi\Services;

use FluentFormsExtendedApi\Support\FieldSchemaTransformer;
use FluentFormsExtendedApi\Support\FluentFormsDependency;
use FluentFormsExtendedApi\Support\FluentFormsGateway;

/**
 * Form definitions for the REST layer, sourced only from Fluent Forms' public PHP API.
 *
 * @see fluentFormApi()
 * @see https://fluentforms.com/docs/fluent-form-php-api/
 */
final class FormService
{
    private FluentFormsDependency $dependency;

    private FluentFormsGateway $gateway;

    private FieldSchemaTransformer $transformer;

    public function __construct(
        FluentFormsDependency $dependency,
        FluentFormsGateway $gateway,
        FieldSchemaTransformer $transformer
    ) {
        $this->dependency  = $dependency;
        $this->gateway     = $gateway;
        $this->transformer = $transformer;
    }

    /**
     * @return list<array{id:int,title:string,status:string}>
     */
    public function listForms(): array
    {
        if (! $this->dependency->isAvailable()) {
            return [];
        }

        return $this->gateway->allFormSummaries();
    }

    /**
     * @return array<string, mixed>|null Null when the form does not exist.
     */
    public function getFormStructure(int $formId): ?array
    {
        if (! $this->dependency->isAvailable()) {
            return null;
        }

        $form = $this->gateway->findForm($formId);
        if ($form === null) {
            return null;
        }

        $props = $this->gateway->formProperties($form);

        // Documented API: formatted entry inputs (flattened, parser-backed).
        $inputs = $props->inputs(['element', 'label', 'admin_label', 'attributes', 'options', 'rules']);
        if (! is_array($inputs)) {
            $inputs = [];
        }

        // Documented API: decoded editor payload — we only consume `submitButton`, not returned verbatim.
        $fieldsRoot = $props->fields();
        $fieldsRoot = is_array($fieldsRoot) ? $fieldsRoot : [];

        return [
            'id'            => (int) $form->id,
            'title'         => (string) $form->title,
            'status'        => (string) $form->status,
            'fields'        => $this->transformer->buildNormalizedFields($fieldsRoot, $inputs),
            'submit_button' => $this->transformer->submitButtonFromDecodedFormFields($fieldsRoot),
        ];
    }

    /**
     * Normalised input definitions for the given form (same objects as `fields` on the detail endpoint).
     *
     * Used to enrich validation errors with labels/types without duplicating Fluent parsing logic.
     *
     * @return list<array<string, mixed>>
     */
    public function getNormalizedFields(int $formId): array
    {
        $structure = $this->getFormStructure($formId);
        if ($structure === null) {
            return [];
        }

        $fields = $structure['fields'] ?? [];

        return is_array($fields) ? $fields : [];
    }
}
