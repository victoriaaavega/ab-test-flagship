<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers and renders the AB Test experiments widget
 * in the WordPress admin dashboard.
 */
class MetaBox
{

    public function __construct()
    {
        add_action('wp_dashboard_setup', [$this, 'register']);
    }

    public function register(): void
    {
        wp_add_dashboard_widget(
            'abtf_experiments_widget',
            'AB Test — Experiments',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        global $wpdb;

        $table   = $wpdb->prefix . 'ab_test_assignments';
        $results = $wpdb->get_results(
            "SELECT experiment_id, variant, COUNT(*) as total
         FROM {$table}
         GROUP BY experiment_id, variant
         ORDER BY experiment_id, variant"
        );

        if (empty($results)) {
            echo '<p>' . esc_html__('No experiment data yet.') . '</p>';
            return;
        }

        $experiments = [];
        foreach ($results as $row) {
            $experiments[$row->experiment_id][$row->variant] = (int) $row->total;
        }

        echo '<table style="width:100%; border-collapse:collapse;">';
        echo '<thead><tr>
            <th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">' . esc_html__('Experiment') . '</th>
            <th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">' . esc_html__('Variant') . '</th>
            <th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">' . esc_html__('Visitors') . '</th>
            <th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">' . esc_html__('Percentage') . '</th>
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
    }
}