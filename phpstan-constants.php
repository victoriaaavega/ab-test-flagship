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

// Time constant (wp-includes/default-constants.php, since WP 3.5).
define('HOUR_IN_SECONDS', 3600);

// wpdb::get_results() output type constants (wp-includes/wp-db.php).
define('ARRAY_A', 'ARRAY_A');
