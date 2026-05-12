<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers and renders the AB Test experiments widget
 * in the WordPress admin dashboard.
 *
 * Reads from wp_ab_test_stats (pre-calculated) instead of
 * running COUNT(*) live on wp_ab_test_assignments.
 */
class MetaBox {

    public function __construct() {
        add_action('wp_dashboard_setup', [$this, 'register']);
    }

    public function register(): void {
        wp_add_dashboard_widget(
            'abtf_experiments_widget',
            'AB Test — Experiments',
            [$this, 'render']
        );
    }

    public function render(): void {
        global $wpdb;

        $statsTable = $wpdb->prefix . 'ab_test_stats';

        $results = $wpdb->get_results(
            "SELECT experiment_id, variant, total, last_rebuilt_at
             FROM {$statsTable}
             ORDER BY experiment_id, variant"
        );

        if (empty($results)) {
            echo '<p>' . esc_html('No stats yet. The cron job will populate this data, or use the Rebuild button on the Experiments page.') . '</p>';
            return;
        }

        // Group by experiment
        $experiments    = [];
        $lastRebuiltAt  = '';

        foreach ($results as $row) {
            $experiments[$row->experiment_id][$row->variant] = (int) $row->total;
            $lastRebuiltAt = $row->last_rebuilt_at; // same for all rows after a rebuild
        }

        // Render table
        echo '<table style="width:100%; border-collapse:collapse;">';
        echo '<thead><tr>
            <th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">Experiment</th>
            <th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">Variant</th>
            <th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">Visitors</th>
            <th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">%</th>
          </tr></thead>';
        echo '<tbody>';

        foreach ($experiments as $experimentId => $variants) {
            $total = array_sum($variants);

            foreach ($variants as $variant => $count) {
                $percentage = $total > 0 ? round(($count / $total) * 100, 1) : 0;

                echo '<tr>
                    <td style="padding:6px; border-bottom:1px solid #eee;">' . esc_html($experimentId) . '</td>
                    <td style="padding:6px; border-bottom:1px solid #eee;">' . esc_html($variant) . '</td>
                    <td style="padding:6px; border-bottom:1px solid #eee;">' . esc_html((string) $count) . '</td>
                    <td style="padding:6px; border-bottom:1px solid #eee;">' . esc_html((string) $percentage) . '%</td>
                  </tr>';
            }
        }

        echo '</tbody></table>';

        // Show freshness info
        if ($lastRebuiltAt) {
            echo '<p style="margin-top:12px; color:#666; font-size:12px;">';
            echo 'Last rebuilt: ' . esc_html($lastRebuiltAt) . ' (UTC). Updates every 8 hours.';
            echo '</p>';
        }
    }
}