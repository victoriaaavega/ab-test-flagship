<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers and renders the Flagship credentials settings page.
 *
 * Credentials are encrypted before being stored in wp_options.
 * This is the only supported method for configuring Flagship credentials.
 *
 * Also manages the Visitor ID Provider configuration, which determines
 * how the plugin resolves a persistent visitor ID across page loads.
 */
class Settings
{

    private const MENU_SLUG = 'abtf-settings';

    public function __construct()
    {
        add_action('admin_menu',    [$this, 'addMenuPage']);
        add_action('admin_init',    [$this, 'handleWrites']);
        add_action('admin_notices', [$this, 'renderNotice']);
        add_action('admin_notices', [$this, 'renderLocalModeBanner']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenuPage(): void
    {
        add_submenu_page(
            'abtf-experiments',
            'AB Tests — Settings',
            'Settings',
            'manage_options',
            self::MENU_SLUG,
            [$this, 'renderPage']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if (!str_contains($hook, self::MENU_SLUG)) {
            return;
        }

        wp_enqueue_script(
            'abtf-settings-admin',
            NOFLIQ_PLUGIN_URL . 'assets/js/settings-admin.js',
            [],
            NOFLIQ_VERSION,
            true
        );
    }

    // -------------------------------------------------------------------------
    // Write handler
    // -------------------------------------------------------------------------

    public function handleWrites(): void
    {
        if ((isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '') !== self::MENU_SLUG) {
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'nofliq-server-side-ab-testing'), 403);
        }

        $action = sanitize_key($_POST['abtf_settings_action'] ?? '');

        match ($action) {
            'save'             => $this->handleSave(),
            'delete'           => $this->handleDelete(),
            'save_provider'    => $this->handleSaveProvider(),
            'save_mode'        => $this->handleSaveMode(),
            default            => null,
        };
    }

    private function handleSave(): void
    {
        check_admin_referer('abtf_save_settings');

        $envId  = sanitize_text_field(wp_unslash($_POST['flagship_env_id'] ?? ''));
        $apiKey = sanitize_text_field(wp_unslash($_POST['flagship_api_key'] ?? ''));

        if ($envId === '' || $apiKey === '') {
            $this->redirect('invalid_fields', 'error');
        }

        $ok = CredentialsManager::save($envId, $apiKey);

        $this->redirect($ok ? 'saved' : 'encrypt_error', $ok ? 'success' : 'error');
    }

    private function handleDelete(): void
    {
        check_admin_referer('abtf_delete_settings');

        CredentialsManager::delete();

        $this->redirect('deleted', 'success');
    }

    private function handleSaveProvider(): void
    {
        check_admin_referer('abtf_save_provider');

        $provider = sanitize_key($_POST['visitor_id_provider'] ?? '');
        $jsPath   = sanitize_text_field(wp_unslash($_POST['visitor_id_js_path'] ?? ''));

        if (!in_array($provider, VisitorIdProvider::VALID_PROVIDERS, true)) {
            $this->redirect('provider_invalid', 'error');
        }

        if ($provider === VisitorIdProvider::PROVIDER_CUSTOM && $jsPath === '') {
            $this->redirect('provider_js_path_required', 'error');
        }

        $ok = VisitorIdProvider::save($provider, $jsPath ?: null);

        $this->redirect($ok ? 'provider_saved' : 'provider_invalid', $ok ? 'success' : 'error');
    }

    private function handleSaveMode(): void
    {
        check_admin_referer('abtf_save_mode');

        $mode = sanitize_key($_POST['decision_engine'] ?? '');

        $ok = DecisionMode::save($mode);

        $this->redirect($ok ? 'mode_saved' : 'mode_invalid', $ok ? 'success' : 'error');
    }

    private function redirect(string $notice, string $type): void
    {
        wp_safe_redirect(add_query_arg([
            'page'        => self::MENU_SLUG,
            'abtf_notice' => $notice,
            'abtf_type'   => $type,
        ], admin_url('admin.php')));
        exit;
    }

    // -------------------------------------------------------------------------
    // Notices
    // -------------------------------------------------------------------------

    private const NOTICE_MESSAGES = [
        'saved'                    => 'Credentials saved and encrypted successfully.',
        'deleted'                  => 'Credentials removed from database.',
        'invalid_fields'           => 'Both fields are required.',
        'encrypt_error'            => 'Encryption failed. Check that AUTH_KEY and AUTH_SALT are defined in wp-config.php.',
        'provider_saved'           => 'Visitor ID provider saved successfully.',
        'provider_invalid'         => 'Invalid provider selected.',
        'provider_js_path_required'=> 'A JavaScript path is required for the Custom provider.',
        'mode_saved'               => 'Decision engine updated.',
        'mode_invalid'             => 'Invalid decision engine selected.',
    ];

    public function renderNotice(): void
    {
        $screen = get_current_screen();
        if (!$screen || !str_contains($screen->id, self::MENU_SLUG)) {
            return;
        }

        $key  = sanitize_key($_GET['abtf_notice'] ?? '');
        $type = sanitize_key($_GET['abtf_type']   ?? 'success');

        if ($key === '' || !isset(self::NOTICE_MESSAGES[$key])) {
            return;
        }

        $type = in_array($type, ['success', 'error', 'warning'], true) ? $type : 'success';

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($type),
            esc_html(self::NOTICE_MESSAGES[$key])
        );
    }

    /**
     * Persistent banner shown across the admin whenever Local mode is active,
     * so the operator is never unaware that variants are being decided locally
     * instead of by Flagship. Deliberately not dismissible: the mode is a
     * standing state, not a one-off event.
     */
    public function renderLocalModeBanner(): void
    {
        if (!DecisionMode::isLocal()) {
            return;
        }

        echo '<div class="notice notice-info"><p>';
        echo '<strong>Nofliq: Local mode active.</strong> ';
        echo 'Variants are decided on this server and no data is sent to Flagship. ';
        echo 'Switch to Flagship in AB Tests → Settings to use AB Tasty.';
        echo '</p></div>';
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function renderPage(): void
    {
        $hasDbCredentials = get_option(CredentialsManager::OPTION_ENV_ID, '') !== '';
        $currentProvider  = VisitorIdProvider::getProvider();
        $currentJsPath    = VisitorIdProvider::getJsPath() ?? '';
?>
        <div class="wrap">
            <h1>AB Tests — Settings</h1>

            <!-- Section 1: Flagship Credentials -->
            <div style="background: #fff; padding: 24px; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 600px; margin-top: 16px;">
                <h2 style="margin-top: 0;">Flagship Credentials</h2>

                <form method="post" action="">
                    <?php wp_nonce_field('abtf_save_settings'); ?>
                    <input type="hidden" name="abtf_settings_action" value="save">

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="flagship_env_id">Environment ID</label></th>
                            <td>
                                <input type="text"
                                    name="flagship_env_id"
                                    id="flagship_env_id"
                                    class="regular-text"
                                    value=""
                                    autocomplete="off"
                                    required
                                    placeholder="e.g. abc123xyz">
                                <?php if ($hasDbCredentials): ?>
                                    <p class="description" style="color: #2e7d32;">
                                        ✓ A value is currently saved. Leave blank to keep it, or enter a new one to replace it.
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="flagship_api_key">API Key</label></th>
                            <td>
                                <input type="password"
                                    name="flagship_api_key"
                                    id="flagship_api_key"
                                    class="regular-text"
                                    value=""
                                    autocomplete="new-password"
                                    required
                                    placeholder="Your Flagship API key">
                                <?php if ($hasDbCredentials): ?>
                                    <p class="description" style="color: #2e7d32;">
                                        ✓ A value is currently saved. Leave blank to keep it, or enter a new one to replace it.
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>

                    <p class="description" style="margin-bottom: 16px;">
                        Credentials are encrypted using AES-256-CBC before being stored.
                        The encryption key is derived from your WordPress <code>AUTH_KEY</code> and <code>AUTH_SALT</code>.
                    </p>

                    <?php submit_button('Save Credentials', 'primary', 'submit', false); ?>
                </form>

                <?php if ($hasDbCredentials): ?>
                    <form method="post" style="margin-top: 12px;">
                        <?php wp_nonce_field('abtf_delete_settings'); ?>
                        <input type="hidden" name="abtf_settings_action" value="delete">
                        <button type="submit"
                            class="button"
                            style="color: #a00; border-color: #a00;"
                            onclick="return confirm('Remove saved credentials? The plugin will fall back to SimulatorAdapter.');">
                            Remove Credentials
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Section 2: Current credentials status -->
            <div style="background: #fff; padding: 24px; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 600px; margin-top: 16px;">
                <h2 style="margin-top: 0;">Current Status</h2>
                <?php
                $status = CredentialsManager::hasCredentials()
                    ? ['label' => 'Configured', 'color' => '#2e7d32', 'bg' => '#e8f5e9']
                    : ['label' => 'Not configured', 'color' => '#a00', 'bg' => '#fce8e8'];
                ?>
                <span style="background: <?php echo esc_attr($status['bg']); ?>; color: <?php echo esc_attr($status['color']); ?>; padding: 4px 12px; border-radius: 10px; font-size: 13px;">
                    <?php echo esc_html($status['label']); ?>
                </span>
            </div>
            <!-- Section: Decision Engine -->
            <div style="background: #fff; padding: 24px; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 600px; margin-top: 16px;">
                <h2 style="margin-top: 0;">Decision Engine</h2>
                <p style="color: #555; margin-bottom: 20px;">
                    Chooses how variants are decided. <strong>Flagship</strong> uses AB Tasty
                    for remote targeting, segmentation and its dashboard (requires credentials).
                    <strong>Local</strong> decides variants on this server with no external
                    service and no data leaving the site — useful for evaluation or for running
                    simple experiments without an AB Tasty account.
                </p>

                <?php $currentMode = DecisionMode::get(); ?>

                <form method="post" action="">
                    <?php wp_nonce_field('abtf_save_mode'); ?>
                    <input type="hidden" name="abtf_settings_action" value="save_mode">

                    <table class="form-table">
                        <tr>
                            <th scope="row">Engine</th>
                            <td>
                                <fieldset>
                                    <label style="display: block; margin-bottom: 10px;">
                                        <input type="radio"
                                            name="decision_engine"
                                            value="flagship"
                                            <?php checked($currentMode, DecisionMode::MODE_FLAGSHIP); ?>>
                                        <strong>Flagship</strong> (recommended)
                                        <span style="color: #666; font-size: 13px; display: block; margin-left: 22px;">
                                            Variants decided by AB Tasty Flagship. Requires credentials above.
                                        </span>
                                    </label>

                                    <label style="display: block; margin-bottom: 10px;">
                                        <input type="radio"
                                            name="decision_engine"
                                            value="local"
                                            <?php checked($currentMode, DecisionMode::MODE_LOCAL); ?>>
                                        <strong>Local</strong>
                                        <span style="color: #666; font-size: 13px; display: block; margin-left: 22px;">
                                            Variants decided locally, no Flagship call. No data leaves your site.
                                        </span>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button('Save Engine', 'primary', 'submit', false); ?>
                </form>
            </div>
            <!-- Section 3: Visitor ID Provider -->
            <div style="background: #fff; padding: 24px; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 600px; margin-top: 16px;">
                <h2 style="margin-top: 0;">Visitor ID Provider</h2>
                <p style="color: #555; margin-bottom: 20px;">
                    Determines how the plugin identifies returning visitors. Choose
                    <strong>Fingerprint</strong> if you have no analytics tool,
                    <strong>Heap</strong> if the site uses Heap Analytics, or
                    <strong>Custom</strong> to read an ID from any JavaScript variable.
                </p>

                <form method="post" action="">
                    <?php wp_nonce_field('abtf_save_provider'); ?>
                    <input type="hidden" name="abtf_settings_action" value="save_provider">

                    <table class="form-table">
                        <tr>
                            <th scope="row">Provider</th>
                            <td>
                                <fieldset>
                                    <label style="display: block; margin-bottom: 10px;">
                                        <input type="radio"
                                            name="visitor_id_provider"
                                            value="fingerprint"
                                            <?php checked($currentProvider, 'fingerprint'); ?>>
                                        <strong>Fingerprint</strong>
                                        <span style="color: #666; font-size: 13px; display: block; margin-left: 22px;">
                                            SHA256 of IP + User-Agent + Accept-Language. No JavaScript dependency.
                                            Works out of the box but does not persist across IP changes or devices.
                                        </span>
                                    </label>

                                    <label style="display: block; margin-bottom: 10px;">
                                        <input type="radio"
                                            name="visitor_id_provider"
                                            value="heap"
                                            <?php checked($currentProvider, 'heap'); ?>>
                                        <strong>Heap</strong>
                                        <span style="color: #666; font-size: 13px; display: block; margin-left: 22px;">
                                            Reads <code>window.heap.userId</code>. Requires the Heap Analytics snippet
                                            to be installed on the site.
                                        </span>
                                    </label>

                                    <label style="display: block; margin-bottom: 10px;">
                                        <input type="radio"
                                            name="visitor_id_provider"
                                            value="custom"
                                            <?php checked($currentProvider, 'custom'); ?>>
                                        <strong>Custom</strong>
                                        <span style="color: #666; font-size: 13px; display: block; margin-left: 22px;">
                                            Read an ID from any JavaScript variable available on the page.
                                        </span>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>

                        <tr id="abtf-js-path-row" style="<?php echo $currentProvider === 'custom' ? '' : 'display:none;'; ?>">
                            <th scope="row"><label for="visitor_id_js_path">JavaScript Path</label></th>
                            <td>
                                <input type="text"
                                    name="visitor_id_js_path"
                                    id="visitor_id_js_path"
                                    class="regular-text"
                                    value="<?php echo esc_attr($currentJsPath); ?>"
                                    placeholder="e.g. window.myApp.user.id">
                                <p class="description">
                                    Dot-notation path to the visitor ID variable. Must be available
                                    synchronously when the page loads (not inside a callback).
                                </p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button('Save Provider', 'primary', 'submit', false); ?>
                </form>
            </div>
        </div>
<?php
    }
}