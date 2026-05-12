<?php
/**
 * Plugin Name:       Fluent Forms Extended API
 * Plugin URI:        https://github.com/mrgrt/Fluent-Forms-Extended-API
 * Description:       Exposes a REST API for Fluent Forms definitions and native-pipeline form submissions for headless and external integrations.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Grahame Thomson
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       fluent-forms-extended-api
 *
 * @package FluentFormsExtendedApi
 */

declare(strict_types=1);

// Abort if WordPress has not bootstrapped (direct file access).
if (! defined('ABSPATH')) {
    exit;
}

// Plugin metadata constants consumed by future upgrades or companion code.
define('FLUENT_FORMS_EXTENDED_API_VERSION', '1.0.0');
define('FLUENT_FORMS_EXTENDED_API_FILE', __FILE__);
define('FLUENT_FORMS_EXTENDED_API_DIR', plugin_dir_path(__FILE__));

/**
 * PSR-4–style autoloader for the `FluentFormsExtendedApi\` namespace (no Composer required at runtime).
 */
spl_autoload_register(
    static function (string $class): void {
        $prefix  = 'FluentFormsExtendedApi\\';
        $baseDir = FLUENT_FORMS_EXTENDED_API_DIR . 'src/';

        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_readable($file)) {
            require_once $file;
        }
    }
);

/**
 * Bootstrap the plugin once WordPress and other plugins are loaded.
 */
add_action(
    'plugins_loaded',
    static function (): void {
        if (! class_exists(\FluentFormsExtendedApi\Plugin::class)) {
            return;
        }

        (new \FluentFormsExtendedApi\Plugin())->register();
    }
);
