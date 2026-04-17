<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers and renders the Experiments CRUD page in the admin dashboard.
 */
class ExperimentsPage {

    public function __construct() {
        add_action('admin_menu', [$this, 'addMenuPage']);
    }

    public function addMenuPage(): void {
        add_menu_page(
            'AB Test Experiments',
            'AB Tests',
            'manage_options',
            'abtf-experiments',
            [$this, 'renderPage'],
            'dashicons-chart-line', // Chart icon
            30
        );
    }

    public function renderPage(): void {
        global $wpdb;
        $tableName = $wpdb->prefix . 'ab_test_experiments';
        
        // 1. Logic to save a new experiment (CREATE)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['abtf_action']) && $_POST['abtf_action'] === 'create') {
            check_admin_referer('abtf_create_experiment');
            
            $wpdb->insert($tableName, [
                'flag_key'   => sanitize_text_field($_POST['flag_key']),
                'name'       => sanitize_text_field($_POST['name']),
                'selector'   => sanitize_text_field($_POST['selector']),
                'event_name' => sanitize_text_field($_POST['event_name']),
                'urls'       => sanitize_textarea_field($_POST['urls']),
                'status'     => 'active'
            ]);
            echo '<div class="notice notice-success is-dismissible"><p>Experiment saved successfully.</p></div>';
        }

        // 2. Logic to delete an experiment (DELETE)
        if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete') {
            check_admin_referer('abtf_delete_' . $_GET['id']);
            $wpdb->delete($tableName, ['id' => intval($_GET['id'])]);
            echo '<div class="notice notice-success is-dismissible"><p>Experiment deleted.</p></div>';
        }

        // 3. Get all experiments (READ)
        $experiments = $wpdb->get_results("SELECT * FROM {$tableName} ORDER BY created_at DESC");

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Active Experiments</h1>
            <hr class="wp-header-end">
            
            <table class="wp-list-table widefat fixed striped table-view-list">
                <thead>
                    <tr>
                        <th>Internal Name</th>
                        <th>Flag Key (Flagship)</th>
                        <th>Selector / Event</th>
                        <th>Paths (URLs)</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($experiments)): ?>
                        <tr><td colspan="6">No experiments configured yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($experiments as $exp): ?>
                            <tr>
                                <td><strong><?php echo esc_html($exp->name); ?></strong></td>
                                <td><code><?php echo esc_html($exp->flag_key); ?></code></td>
                                <td>
                                    <?php echo esc_html($exp->selector); ?><br>
                                    <small style="color:#666;"><?php echo esc_html($exp->event_name); ?></small>
                                </td>
                                <td><?php echo esc_html($exp->urls); ?></td>
                                <td>
                                    <span style="background:#b8e6bf; color:#125222; padding:3px 8px; border-radius:10px; font-size:12px;">
                                        <?php echo esc_html($exp->status); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="?page=abtf-experiments&action=delete&id=<?php echo $exp->id; ?>&_wpnonce=<?php echo wp_create_nonce('abtf_delete_' . $exp->id); ?>" 
                                       class="button button-link-delete" 
                                       style="color: #a00;" 
                                       onclick="return confirm('Are you sure you want to delete this experiment?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <br><br>

            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 800px;">
                <h2>Add New Experiment</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('abtf_create_experiment'); ?>
                    <input type="hidden" name="abtf_action" value="create">
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="name">Name (Internal use)</label></th>
                            <td><input name="name" type="text" id="name" class="regular-text" required placeholder="E.g.: CTA Redesign"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="flag_key">Flag Key</label></th>
                            <td>
                                <input name="flag_key" type="text" id="flag_key" class="regular-text" required placeholder="E.g.: cta_version_test">
                                <p class="description">Must exactly match the key in AB Tasty Flagship.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="selector">CSS Selector</label></th>
                            <td>
                                <input name="selector" type="text" id="selector" class="regular-text" required placeholder="E.g.: .main-btn">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="event_name">Event Name (Analytics)</label></th>
                            <td><input name="event_name" type="text" id="event_name" class="regular-text" required placeholder="E.g.: main_btn_click"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="urls">Paths where it runs</label></th>
                            <td>
                                <textarea name="urls" id="urls" class="large-text" rows="3" required placeholder="/, /talent/*"></textarea>
                                <p class="description">Separate by commas. Use <code>*</code> as a wildcard (e.g., <code>/blog/*</code>).</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Save Experiment', 'primary'); ?>
                </form>
            </div>
        </div>
        <?php
    }
}