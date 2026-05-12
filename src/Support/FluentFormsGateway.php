<?php

declare(strict_types=1);

namespace FluentFormsExtendedApi\Support;

/**
 * Thin abstraction over Fluent Forms' documented {@see fluentFormApi()} entry point.
 *
 * @see https://developers.fluentforms.com/global-functions/
 * @see https://fluentforms.com/docs/fluent-form-php-api/
 */
final class FluentFormsGateway
{
    /**
     * @return mixed Fluent Forms `\FluentForm\App\Api\Form` instance (intentionally untyped so this file autoloads when Fluent Forms is inactive).
     */
    public function formsModule()
    {
        return fluentFormApi('forms');
    }

    /**
     * All forms as lightweight summaries, using paginated `forms()` until exhausted.
     *
     * @return list<array{id:int,title:string,status:string}>
     */
    public function allFormSummaries(): array
    {
        $api = $this->formsModule();
        $out = [];
        $page     = 1;
        $perPage  = 100;
        $lastPage = 1;

        do {
            /** @var array<string, mixed> $batch */
            $batch = $api->forms(
                [
                    'per_page'    => $perPage,
                    'page'        => $page,
                    'status'      => 'all',
                    'sort_column' => 'id',
                    'sort_by'     => 'ASC',
                ],
                false
            );

            $lastPage = (int) ($batch['last_page'] ?? 1);
            $rows     = $batch['data'] ?? [];

            foreach ($rows as $form) {
                if (! is_object($form) || ! isset($form->id)) {
                    continue;
                }
                $out[] = [
                    'id'     => (int) $form->id,
                    'title'  => (string) $form->title,
                    'status' => (string) $form->status,
                ];
            }

            ++$page;
        } while ($page <= $lastPage && $lastPage > 0);

        return $out;
    }

    /**
     * @return object|null Fluent Forms form model / row object.
     */
    public function findForm(int $formId)
    {
        $form = $this->formsModule()->find($formId);

        return $form ?: null;
    }

    /**
     * FormProperties wrapper for a loaded form model (documented fluent chain).
     *
     * @param object $form Form object from {@see self::findForm()}.
     *
     * @return mixed `\FluentForm\App\Api\FormProperties`
     */
    public function formProperties($form)
    {
        return $this->formsModule()->form($form);
    }
}
