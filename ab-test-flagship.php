<?php
/**
 * Plugin Name: AB Test Flagship
 * Description: Server-side A/B testing using AB Tasty Flagship SDK
 * Version: 1.0.0
 * Author: Victoria Vega
 * Requires PHP: 8.1
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ABTF_VERSION', '1.0.0');
define('ABTF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ABTF_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once ABTF_PLUGIN_DIR . 'vendor/autoload.php';
require_once ABTF_PLUGIN_DIR . 'includes/Fingerprint.php';
require_once ABTF_PLUGIN_DIR . 'includes/RedisClient.php';
require_once ABTF_PLUGIN_DIR . 'includes/Database.php';
require_once ABTF_PLUGIN_DIR . 'includes/adapters/DecisionAdapterInterface.php';
require_once ABTF_PLUGIN_DIR . 'includes/adapters/SimulatorAdapter.php';
require_once ABTF_PLUGIN_DIR . 'includes/adapters/FlagshipAdapter.php';
require_once ABTF_PLUGIN_DIR . 'includes/ExperimentRunner.php';
require_once ABTF_PLUGIN_DIR . 'includes/EventEndpoint.php';
require_once ABTF_PLUGIN_DIR . 'includes/Dashboard/MetaBox.php';
require_once ABTF_PLUGIN_DIR . 'includes/HitCacheRedis.php';

function abtf_enqueue_scripts(): void {
    if (is_admin()) {
        return;
    }

    wp_enqueue_script(
        'abtf-event-tracker',
        ABTF_PLUGIN_URL . 'assets/js/event-tracker.js',
        [],
        ABTF_VERSION,
        true
    );

    wp_enqueue_script(
        'abtf-heap-sync',
        ABTF_PLUGIN_URL . 'assets/js/heap-sync.js',
        [],
        ABTF_VERSION,
        true
    );
}
add_action('wp_enqueue_scripts', 'abtf_enqueue_scripts');

function abtf_init(): void {
    $database = new Database();
    $database->maybeCreateTable();
}
add_action('init', 'abtf_init');

function abtf_shutdown(): void {
    try {
        Flagship\Flagship::close();
    } catch (\Exception $e) {
        error_log('[AB Test] Flagship close error: ' . $e->getMessage());
    }
}
register_shutdown_function('abtf_shutdown');