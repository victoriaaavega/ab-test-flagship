<?php
/**
 * Plugin Name: AB Test Flagship
 * Description: Server-side A/B testing using AB Tasty Flagship SDK
 * Version: 1.3.0
 * Author: Victoria Vega
 * Requires PHP: 8.1
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ABTF_VERSION', '1.3.0');
define('ABTF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ABTF_PLUGIN_URL', plugin_dir_url(__FILE__));

// Dependencias y utilidades
require_once ABTF_PLUGIN_DIR . 'vendor/autoload.php';
require_once ABTF_PLUGIN_DIR . 'includes/Fingerprint.php';
require_once ABTF_PLUGIN_DIR . 'includes/RedisClient.php';
require_once ABTF_PLUGIN_DIR . 'includes/HitCacheRedis.php';
require_once ABTF_PLUGIN_DIR . 'includes/Database.php';

// Adaptadores y lógica de decisión
require_once ABTF_PLUGIN_DIR . 'includes/adapters/DecisionAdapterInterface.php';
require_once ABTF_PLUGIN_DIR . 'includes/adapters/SimulatorAdapter.php';
require_once ABTF_PLUGIN_DIR . 'includes/adapters/FlagshipAdapter.php';
require_once ABTF_PLUGIN_DIR . 'includes/ExperimentRunner.php';

// APIs, Controladores y UI
require_once ABTF_PLUGIN_DIR . 'includes/RateLimiter.php';
require_once ABTF_PLUGIN_DIR . 'includes/EventEndpoint.php';
require_once ABTF_PLUGIN_DIR . 'includes/Dashboard/MetaBox.php';

// Nuevos componentes (Fase 2 y 3)
require_once ABTF_PLUGIN_DIR . 'includes/Dashboard/ExperimentsPage.php';
require_once ABTF_PLUGIN_DIR . 'includes/AutoInjector.php';

/**
 * Creates a nonce in a user-0 context
 */
function abtf_create_public_nonce(string $action): string {
    $saved_user_id = get_current_user_id();
    wp_set_current_user(0);
    $nonce = wp_create_nonce($action);
    wp_set_current_user($saved_user_id);
    return $nonce;
}

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

    wp_localize_script('abtf-event-tracker', 'abtfConfig', [
        'apiUrl' => rest_url('abtest/v1/event'),
        'nonce'  => abtf_create_public_nonce('abtf_track_event'),
    ]);
}
add_action('wp_enqueue_scripts', 'abtf_enqueue_scripts');

function abtf_check_credentials(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!defined('FLAGSHIP_ENV_ID') || !defined('FLAGSHIP_API_KEY')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>AB Test Flagship:</strong> ';
            echo esc_html__('Flagship credentials are not configured. Please define FLAGSHIP_ENV_ID and FLAGSHIP_API_KEY in wp-config.php.');
            echo '</p></div>';
        });
    }
}
add_action('admin_init', 'abtf_check_credentials');

function abtf_runner(): ExperimentRunner {
    static $runner = null;

    if ($runner === null) {
        if (defined('FLAGSHIP_ENV_ID') && defined('FLAGSHIP_API_KEY')) {
            $runner = new ExperimentRunner(new FlagshipAdapter());
        } else {
            $runner = new ExperimentRunner(new SimulatorAdapter());
        }
    }

    return $runner;
}

// -----------------------------------------------------------------------------
// Inicialización principal
// -----------------------------------------------------------------------------

function abtf_init(): void {
    $database = new Database();
    $database->maybeCreateTable();

    // Arrancamos el inyector automático SÓLO en el frontend
    if (!is_admin()) {
        new AutoInjector();
    }
}
add_action('init', 'abtf_init');

// Arrancamos la interfaz del administrador SÓLO en el backend
if (is_admin()) {
    new ExperimentsPage();
}

// -----------------------------------------------------------------------------

function abtf_shutdown(): void {
    if (!defined('FLAGSHIP_ENV_ID') || !defined('FLAGSHIP_API_KEY')) {
        return;
    }

    try {
        Flagship\Flagship::close();
    } catch (\Exception $e) {
        error_log('[AB Test] Flagship close error: ' . $e->getMessage());
    }
}
register_shutdown_function('abtf_shutdown');