<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers and renders the Flagship credentials settings page.
 *
 * Credentials are encrypted before being stored in wp_options.
 * This is the only supported method for configuring Flagship credentials.
 */
class Settings
{

    private const MENU_SLUG = 'abtf-settings';

    public function __construct()
    {
        add_action('admin_menu',    [$this, 'addMenuPage']);
        add_action('admin_init',    [$this, 'handleWrites']);
        add_action('admin_notices', [$this, 'renderNotice']);
    }

    public function addMenuPage(): void
    {
        add_submenu_page(
            'abtf-experiments',
            'AB Test — Settings',
            'Settings',
            'manage_options',
            self::MENU_SLUG,
            [$this, 'renderPage']
        );
    }

    // -------------------------------------------------------------------------
    // Write handler
    // -------------------------------------------------------------------------

    public function handleWrites(): void
    {
        if (($_GET['page'] ?? '') !== self::MENU_SLUG) {
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.'), 403);
        }

        $action = sanitize_key($_POST['abtf_settings_action'] ?? '');

        match ($action) {
            'save'   => $this->handleSave(),
            'delete' => $this->handleDelete(),
            default  => null,
        };
    }

    private function handleSave(): void
    {
        check_admin_referer('abtf_save_settings');

        $envId  = sanitize_text_field($_POST['flagship_env_id']  ?? '');
        $apiKey = sanitize_text_field($_POST['flagship_api_key'] ?? '');

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
        'saved'          => 'Credentials saved and encrypted successfully.',
        'deleted'        => 'Credentials removed from database.',
        'invalid_fields' => 'Both fields are required.',
        'encrypt_error'  => 'Encryption failed. Check that AUTH_KEY and AUTH_SALT are defined in wp-config.php.',
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

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function renderPage(): void
    {
        $hasDbCredentials = get_option(CredentialsManager::OPTION_ENV_ID, '') !== '';
?>
        <div class="wrap">
            <h1>AB Test — Settings</h1>

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
        </div>
<?php
    }
}
