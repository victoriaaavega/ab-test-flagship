<?php

if (!defined('ABSPATH')) {
    exit;
}

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

        $table = $wpdb->prefix . 'ab_test_assignments';
        $results = $wpdb->get_results(
            "SELECT experiment_id, variant, COUNT(*) as total
             FROM {$table}
             GROUP BY experiment_id, variant
             ORDER BY experiment_id, variant"
        );

        if (empty($results)) {
            echo '<p>No experiment data yet.</p>';
            return;
        }

        $experiments = [];
        foreach ($results as $row) {
            $experiments[$row->experiment_id][$row->variant] = (int) $row->total;
        }

        echo '<table style="width:100%; border-collapse: collapse;">';
        echo '<thead><tr>
                <th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">Experiment</th>
                <th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">Variant</th>
                <th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">Visitors</th>
                <th style="text-align:left; padding:6px; border-bottom:1px solid #ddd;">Percentage</th>
              </tr></thead>';
        echo '<tbody>';

        foreach ($experiments as $experimentId => $variants) {
            $total = array_sum($variants);
            foreach ($variants as $variant => $count) {
                $percentage = $total > 0 ? round(($count / $total) * 100, 1) : 0;
                echo "<tr>
                        <td style='padding:6px; border-bottom:1px solid #eee;'>{$experimentId}</td>
                        <td style='padding:6px; border-bottom:1px solid #eee;'>{$variant}</td>
                        <td style='padding:6px; border-bottom:1px solid #eee;'>{$count}</td>
                        <td style='padding:6px; border-bottom:1px solid #eee;'>{$percentage}%</td>
                      </tr>";
            }
        }

        echo '</tbody></table>';
    }
}

new MetaBox();