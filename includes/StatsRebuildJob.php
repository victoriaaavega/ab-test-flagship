<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rebuilds the pre-calculated stats table from raw assignment data.
 *
 * Called by:
 *   - WP-Cron every 8 hours (via CronManager)
 *   - Admin "Rebuild now" button (via ExperimentsPage)
 *
 * Uses INSERT ... ON DUPLICATE KEY UPDATE instead of TRUNCATE + INSERT
 * to avoid a window where the stats table is empty between operations.
 */
class StatsRebuildJob {

    /**
     * Runs the rebuild.
     * Reads all assignments, groups them by experiment + variant,
     * and upserts the totals into wp_ab_test_stats.
     *
     * @return array{rows_written: int, duration_ms: int, error: string|null}
     */
    public static function run(): array {
        global $wpdb;

        $assignmentsTable = $wpdb->prefix . 'ab_test_assignments';
        $statsTable       = $wpdb->prefix . 'ab_test_stats';
        $startTime        = microtime(true);

        // Aggregate totals from raw assignments
        $rows = $wpdb->get_results(
            "SELECT experiment_id, variant, COUNT(*) as total
             FROM {$assignmentsTable}
             GROUP BY experiment_id, variant",
            ARRAY_A
        );

        if ($rows === null) {
            $error = $wpdb->last_error ?: 'Unknown database error during SELECT.';
            error_log('[AB Test] StatsRebuildJob failed on SELECT: ' . $error);
            return ['rows_written' => 0, 'duration_ms' => 0, 'error' => $error];
        }

        if (empty($rows)) {
            error_log('[AB Test] StatsRebuildJob: no assignment data found, nothing to write.');
            return ['rows_written' => 0, 'duration_ms' => 0, 'error' => null];
        }

        // Build a single atomic upsert for all rows
        // INSERT ... ON DUPLICATE KEY UPDATE ensures the table is never empty
        // between a TRUNCATE and a re-INSERT.
        $placeholders = [];
        $values       = [];

        foreach ($rows as $row) {
            $placeholders[] = '(%s, %s, %d, NOW())';
            $values[]       = $row['experiment_id'];
            $values[]       = $row['variant'];
            $values[]       = (int) $row['total'];
        }

        $sql = $wpdb->prepare(
            "INSERT INTO {$statsTable} (experiment_id, variant, total, last_rebuilt_at)
             VALUES " . implode(', ', $placeholders) . "
             ON DUPLICATE KEY UPDATE
                 total           = VALUES(total),
                 last_rebuilt_at = VALUES(last_rebuilt_at)",
            $values
        );

        $result = $wpdb->query($sql);

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        if ($result === false) {
            $error = $wpdb->last_error ?: 'Unknown database error during UPSERT.';
            error_log('[AB Test] StatsRebuildJob failed on UPSERT: ' . $error);
            return ['rows_written' => 0, 'duration_ms' => $durationMs, 'error' => $error];
        }

        error_log("[AB Test] StatsRebuildJob completed. Rows written: {$result}, Duration: {$durationMs}ms.");

        return ['rows_written' => (int) $result, 'duration_ms' => $durationMs, 'error' => null];
    }
}