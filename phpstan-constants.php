<?php

/**
 * PHPStan bootstrap: WordPress core constants.
 *
 * The php-stubs/wordpress-stubs package describes WordPress functions and
 * classes, but does NOT define WordPress' global constants. This file
 * declares the specific core constants this plugin uses, with their real
 * WordPress values, so static analysis can resolve them.
 *
 * This file is only loaded by PHPStan (see phpstan.neon → bootstrapFiles).
 * It is never loaded at runtime and is not shipped with the plugin.
 */

// Block direct web access, but allow PHPStan (which loads this file on the
// CLI, where ABSPATH is not defined) to read the constant definitions below.
if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

// Time constant (wp-includes/default-constants.php, since WP 3.5).
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

// wpdb::get_results() output type constants (wp-includes/wp-db.php).
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// Plugin constants (defined in the main plugin file, which is out of scope for
// the classes in includes/). Declared here so static analysis can resolve them.
if (!defined('NOFLIQ_VERSION')) {
    define('NOFLIQ_VERSION', '1.9.0');
}

if (!defined('NOFLIQ_PLUGIN_URL')) {
    define('NOFLIQ_PLUGIN_URL', 'https://example.test/wp-content/plugins/nofliq-server-side-ab-testing/');
}

if (!defined('NOFLIQ_PLUGIN_DIR')) {
    define('NOFLIQ_PLUGIN_DIR', '/var/www/wp-content/plugins/nofliq-server-side-ab-testing/');
}