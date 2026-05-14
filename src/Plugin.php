<?php

declare(strict_types=1);

namespace FluentFormsExtendedApi;

use FluentFormsExtendedApi\Rest\EntriesController;
use FluentFormsExtendedApi\Rest\FormsController;
use FluentFormsExtendedApi\Services\EntryService;
use FluentFormsExtendedApi\Services\FormService;
use FluentFormsExtendedApi\Services\FormSubmissionService;
use FluentFormsExtendedApi\Support\EntryResponseNormalizer;
use FluentFormsExtendedApi\Support\FieldSchemaTransformer;
use FluentFormsExtendedApi\Support\FluentEntriesGateway;
use FluentFormsExtendedApi\Support\FluentFormsDependency;
use FluentFormsExtendedApi\Support\FluentFormsGateway;
use FluentFormsExtendedApi\Support\FluentSubmissionClient;
use FluentFormsExtendedApi\Support\SubmissionResponseNormalizer;
use FluentFormsExtendedApi\Support\ValidationErrorNormalizer;

/**
 * Root plugin object: wires services and REST routes.
 */
final class Plugin
{
    /**
     * Register hooks (REST API routes).
     */
    public function register(): void
    {
        // Allow packaged translations under /languages (optional for this plugin).
        load_plugin_textdomain(
            'fluent-forms-extended-api',
            false,
            dirname(plugin_basename(FLUENT_FORMS_EXTENDED_API_FILE)) . '/languages'
        );

        // REST routes are registered on rest_api_init so the REST server is fully available.
        add_action(
            'rest_api_init',
            function (): void {
                $dependency  = new FluentFormsDependency();
                $gateway     = new FluentFormsGateway();
                $transformer = new FieldSchemaTransformer();

                $formService = new FormService($dependency, $gateway, $transformer);

                // Submission goes through Fluent Forms' public AJAX action via loopback HTTP —
                // no Fluent Forms PHP classes are referenced.
                $submissionClient        = new FluentSubmissionClient();
                $normalizer              = new SubmissionResponseNormalizer();
                $validationErrorNormalizer = new ValidationErrorNormalizer();
                $submission              = new FormSubmissionService(
                    $dependency,
                    $gateway,
                    $formService,
                    $submissionClient,
                    $normalizer,
                    $validationErrorNormalizer
                );

                $controller = new FormsController($formService, $submission, $dependency);
                $controller->registerRoutes();

                // Entry endpoints — purely additive; share the same Fluent Forms dependency check.
                $entriesGateway   = new FluentEntriesGateway();
                $entryNormalizer  = new EntryResponseNormalizer();
                $entryService     = new EntryService($dependency, $gateway, $entriesGateway, $entryNormalizer);
                $entriesController = new EntriesController($entryService, $dependency);
                $entriesController->registerRoutes();
            }
        );
    }
}
