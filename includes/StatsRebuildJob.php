<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rebuilds the pre-calculated stats tables from their live sources.
 *
 * Called by:
 *   - WP-Cron every 8 hours (via CronManager)
 *   - Admin "Rebuild now" button (via ExperimentsPage)
 *
 * Two independent rebuilds run in sequence:
 *   1. Assignment stats — aggregates wp_ab_test_assignments into
 *      wp_ab_test_stats (visitor counts per variant).
 *   2. Conversion stats — snapshots the live Redis counters (DB 3) into
 *      wp_ab_test_conversions_stats so conversion data survives a Redis
 *      restart. Redis remains the live source of truth for the dashboard;
 *      this table is the persistent fallback.
 *
 * Both use INSERT ... ON DUPLICATE KEY UPDATE instead of TRUNCATE + INSERT
 * to avoid a window where a stats table is empty between operations.
 */
class StatsRebuildJob {

    /**
     * Runs both rebuilds and returns a combined result.
     *
     * @return array{rows_written: int, duration_ms: int, error: string|null}
     */
    public static function run(): array {
        $startTime = microtime(true);

        $assignments = self::rebuildAssignmentStats();
        $conversions = self::rebuildConversionStats();

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        // Surface the first error encountered, if any.
        $error = $assignments['error'] ?? $conversions['error'] ?? null;

        $rowsWritten = $assignments['rows_written'] + $conversions['rows_written'];

        error_log("[AB Test] StatsRebuildJob completed. Assignment rows: {$assignments['rows_written']}, Conversion rows: {$conversions['rows_written']}, Duration: {$durationMs}ms.");

        return [
            'rows_written' => $rowsWritten,
            'duration_ms'  => $durationMs,
            'error'        => $error,
        ];
    }

    /**
     * Aggregates raw assignments into wp_ab_test_stats (visitor counts).
     *
     * @return array{rows_written: int, error: string|null}
     */
    private static function rebuildAssignmentStats(): array {
        global $wpdb;

        $assignmentsTable = $wpdb->prefix . 'ab_test_assignments';
        $statsTable       = $wpdb->prefix . 'ab_test_stats';

        $rows = $wpdb->get_results(
            "SELECT experiment_id, variant, COUNT(*) as total
             FROM {$assignmentsTable}
             GROUP BY experiment_id, variant",
            ARRAY_A
        );

        if ($rows === null) {
            $error = $wpdb->last_error ?: 'Unknown database error during assignment SELECT.';
            error_log('[AB Test] StatsRebuildJob failed on assignment SELECT: ' . $error);
            return ['rows_written' => 0, 'error' => $error];
        }

        if (empty($rows)) {
            error_log('[AB Test] StatsRebuildJob: no assignment data found, nothing to write.');
            return ['rows_written' => 0, 'error' => null];
        }

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

        if ($result === false) {
            $error = $wpdb->last_error ?: 'Unknown database error during assignment UPSERT.';
            error_log('[AB Test] StatsRebuildJob failed on assignment UPSERT: ' . $error);
            return ['rows_written' => 0, 'error' => $error];
        }

        return ['rows_written' => (int) $result, 'error' => null];
    }

    /**
     * Snapshots live conversion counters from Redis into
     * wp_ab_test_conversions_stats.
     *
     * @return array{rows_written: int, error: string|null}
     */
    private static function rebuildConversionStats(): array {
        global $wpdb;

        $tracker = new ConversionTracker();

        if (!$tracker->isAvailable()) {
            error_log('[AB Test] StatsRebuildJob: Redis unavailable, skipping conversion snapshot.');
            return ['rows_written' => 0, 'error' => null];
        }

        $combos = $tracker->listAll();

        if (empty($combos)) {
            error_log('[AB Test] StatsRebuildJob: no conversion data in Redis, nothing to write.');
            return ['rows_written' => 0, 'error' => null];
        }

        $statsTable   = $wpdb->prefix . 'ab_test_conversions_stats';
        $placeholders = [];
        $values       = [];

        foreach ($combos as $combo) {
            $placeholders[] = '(%s, %s, %s, %d, %d, NOW())';
            $values[]       = $combo['experiment_id'];
            $values[]       = $combo['variant'];
            $values[]       = $combo['event_name'];
            $values[]       = (int) $combo['unique'];
            $values[]       = (int) $combo['total'];
        }

        $sql = $wpdb->prepare(
            "INSERT INTO {$statsTable}
                (experiment_id, variant, event_name, unique_conversions, total_conversions, last_rebuilt_at)
             VALUES " . implode(', ', $placeholders) . "
             ON DUPLICATE KEY UPDATE
                 unique_conversions = VALUES(unique_conversions),
                 total_conversions  = VALUES(total_conversions),
                 last_rebuilt_at    = VALUES(last_rebuilt_at)",
            $values
        );

        $result = $wpdb->query($sql);

        if ($result === false) {
            $error = $wpdb->last_error ?: 'Unknown database error during conversion UPSERT.';
            error_log('[AB Test] StatsRebuildJob failed on conversion UPSERT: ' . $error);
            return ['rows_written' => 0, 'error' => $error];
        }

        return ['rows_written' => (int) $result, 'error' => null];
    }
}