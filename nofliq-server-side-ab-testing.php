<?php

/**
 * Plugin Name: Nofliq Server-Side A/B Testing
 * Description: No-flicker server-side A/B testing. Integrates with AB Tasty Flagship.
 * Version: 1.8.0
 * Author: Victoria Vega
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nofliq-server-side-ab-testing
 */

if (!defined('ABSPATH')) {
    exit;
}

define('NOFLIQ_VERSION', '1.8.0');
define('NOFLIQ_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NOFLIQ_PLUGIN_URL', plugin_dir_url(__FILE__));

// Dependencies and utilities
require_once NOFLIQ_PLUGIN_DIR . 'vendor/autoload.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/Logger.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/VisitorIdProvider.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/Fingerprint.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/RedisClient.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/HitCacheRedis.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/Database.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/DecisionMode.php';

// Adapters and decision logic
require_once NOFLIQ_PLUGIN_DIR . 'includes/adapters/DecisionAdapterInterface.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/adapters/LocalAdapter.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/adapters/FlagshipAdapter.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/FlagshipActivator.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/ExperimentRunner.php';

// APIs, controllers, and UI
require_once NOFLIQ_PLUGIN_DIR . 'includes/ConversionTracker.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/RateLimiter.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/EventEndpoint.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/Dashboard/MetaBox.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/Dashboard/ExperimentsPage.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/Dashboard/ReportingPage.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/AutoInjector.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/StatsRebuildJob.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/CronManager.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/Encryption.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/CredentialsManager.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/Settings.php';
require_once NOFLIQ_PLUGIN_DIR . 'includes/IdentifyEndpoint.php';

// -----------------------------------------------------------------------------
// Bootstrap — runs after all plugins are loaded
// -----------------------------------------------------------------------------

add_filter('cron_schedules', function (array $schedules): array {
    $schedules[CronManager::INTERVAL] = [
        'interval' => 8 * HOUR_IN_SECONDS,
        'display'  => 'Every 8 hours',
    ];
    return $schedules;
});

new CronManager();

add_action('plugins_loaded', function (): void {
    new EventEndpoint();
    new IdentifyEndpoint();

    if (is_admin()) {
        new MetaBox();
        // Submenu order follows instantiation order: Experiments (parent),
        // then Reporting, then Settings. Keep Settings last by convention.
        new ExperimentsPage();
        new ReportingPage();
        new Nofliq_Settings();
    }
});

// -----------------------------------------------------------------------------
// Frontend scripts
// -----------------------------------------------------------------------------

/**
 * Derives the shared cookie domain from the site's home URL.
 * Returns '.example.com' for a host like 'www.example.com', etc.
 * Returns an empty string for single-word hosts like 'localhost'.
 */
function abtf_get_cookie_domain(): string
{
    $host  = wp_parse_url(home_url(), PHP_URL_HOST) ?? '';
    $parts = explode('.', $host);

    if (count($parts) >= 2) {
        return '.' . implode('.', array_slice($parts, -2));
    }

    return '';
}

function abtf_enqueue_scripts(): void
{
    if (is_admin()) {
        return;
    }

    wp_enqueue_script(
        'abtf-event-tracker',
        NOFLIQ_PLUGIN_URL . 'assets/js/event-tracker.js',
        [],
        NOFLIQ_VERSION,
        true
    );

    // Only enqueue visitor-sync.js when the provider requires JS-side ID resolution.
    // For fingerprint, PHP handles everything and there is nothing to sync.
    if (VisitorIdProvider::usesExternalId()) {
        wp_enqueue_script(
            'abtf-visitor-sync',
            NOFLIQ_PLUGIN_URL . 'assets/js/visitor-sync.js',
            [],
            NOFLIQ_VERSION,
            true
        );
    }

    wp_localize_script('abtf-event-tracker', 'abtfConfig', [
        'apiUrl'            => rest_url('abtest/v1/event'),
        'identifyUrl'       => rest_url('abtest/v1/identify'),
        'nonce'             => abtf_create_public_nonce('abtf_track_event'),
        'cookieDomain'      => abtf_get_cookie_domain(),
        // Passed to visitor-sync.js — null when provider is fingerprint.
        'visitorIdProvider' => VisitorIdProvider::getProvider(),
        'visitorIdJsPath'   => VisitorIdProvider::getJsPath(),
        // Gates frontend console output behind the same ABTF_LOG_LEVEL switch
        // that controls PHP logging. True only when the level includes debug.
        'debug'             => Nofliq_Logger::isDebug(),
    ]);

    // Expose the AB test decision for this page as inline JS attached to the
    // tracker handle, so it is emitted before event-tracker.js runs. Replaces
    // the former raw <script> printed on wp_footer (WordPress.org: no inline
    // <script> tags; use wp_add_inline_script).
    $injector   = new AutoInjector();
    $inlineData = $injector->buildInlineData();

    if ($inlineData !== '') {
        wp_add_inline_script('abtf-event-tracker', $inlineData, 'before');
    }
}
add_action('wp_enqueue_scripts', 'abtf_enqueue_scripts');

// -----------------------------------------------------------------------------
// Admin notices
// -----------------------------------------------------------------------------

function abtf_check_credentials(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!CredentialsManager::hasCredentials()) {
        add_action('admin_notices', function (): void {
            $settingsUrl = admin_url('admin.php?page=abtf-settings');
            echo '<div class="notice notice-error"><p>';
            echo '<strong>AB Tests:</strong> ';
            echo 'Flagship credentials are not configured. ';
            echo '<a href="' . esc_url($settingsUrl) . '">Configure them in AB Tests → Settings</a>.';
            echo '</p></div>';
        });
    }
}
add_action('admin_init', 'abtf_check_credentials');

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Creates a nonce in a user-0 context so it works regardless of login state.
 */
function abtf_create_public_nonce(string $action): string
{
    $savedUserId = get_current_user_id();
    wp_set_current_user(0);
    $nonce = wp_create_nonce($action);
    wp_set_current_user($savedUserId);
    return $nonce;
}

/**
 * Returns the singleton ExperimentRunner, choosing the correct adapter
 * based on whether Flagship credentials are configured.
 */
function abtf_runner(): ExperimentRunner
{
    static $runner = null;

    if ($runner === null) {
        // The decision engine is chosen by the administrator's explicit mode
        // setting, never inferred from whether credentials happen to exist.
        // Local mode uses the plugin's own engine; Flagship mode uses AB Tasty.
        $adapter = DecisionMode::isLocal()
            ? new LocalAdapter()
            : new FlagshipAdapter();

        $runner = new ExperimentRunner($adapter);
    }

    return $runner;
}

// -----------------------------------------------------------------------------
// Init hook — DB + frontend AutoInjector
// -----------------------------------------------------------------------------

function abtf_init(): void
{
    $database = new Nofliq_Database();
    $database->maybeCreateTable();

}
add_action('init', 'abtf_init');

// -----------------------------------------------------------------------------
// Shutdown — flush Flagship hit queue
// -----------------------------------------------------------------------------

register_deactivation_hook(__FILE__, function (): void {
    CronManager::unschedule();
});

function abtf_shutdown(): void
{
    if (!CredentialsManager::hasCredentials()) {
        return;
    }

    try {
        Flagship\Flagship::close();
    } catch (\Exception $e) {
        error_log('[AB Test] Flagship close error: ' . $e->getMessage());
    }
}
register_shutdown_function('abtf_shutdown');