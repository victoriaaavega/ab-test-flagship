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
 *
 * Orphan cleanup (mark-and-sweep): each rebuild stamps every row it writes
 * with a single timestamp captured at the start of that rebuild. After the
 * upsert, any row whose last_rebuilt_at predates this run is an orphan — a
 * variant/experiment/event combination that no longer exists in the live
 * source — and is deleted. This keeps the SQL fallback faithful to live data
 * without ever leaving the table empty (the upsert runs before the delete).
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

        Nofliq_Logger::info("StatsRebuildJob completed. Assignment rows: {$assignments['rows_written']}, Conversion rows: {$conversions['rows_written']}, Duration: {$durationMs}ms.");

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

        // Single timestamp for the whole batch so mark-and-sweep is exact:
        // every row written by this run shares it, and orphan cleanup can
        // safely delete anything older without risking a partial-batch delete.
        $rebuildAt = current_time('mysql', true); // UTC 'Y-m-d H:i:s'

        // Table name comes from $wpdb->prefix (not user input) and cannot be
        // passed through prepare(). No user data is interpolated.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            "SELECT experiment_id, variant, COUNT(*) as total
             FROM {$assignmentsTable}
             GROUP BY experiment_id, variant",
            ARRAY_A
        );

        if ($rows === null) {
            $error = $wpdb->last_error ?: 'Unknown database error during assignment SELECT.';
            Nofliq_Logger::error('StatsRebuildJob failed on assignment SELECT: ' . $error);
            return ['rows_written' => 0, 'error' => $error];
        }

        if (empty($rows)) {
            Nofliq_Logger::info('StatsRebuildJob: no assignment data found, nothing to write.');
            return ['rows_written' => 0, 'error' => null];
        }

        $placeholders = [];
        $values       = [];

        foreach ($rows as $row) {
            $placeholders[] = '(%s, %s, %d, %s)';
            $values[]       = $row['experiment_id'];
            $values[]       = $row['variant'];
            $values[]       = (int) $row['total'];
            $values[]       = $rebuildAt;
        }

        // The table name is interpolated (safe: from $wpdb->prefix); all values
        // go through prepare() with the placeholders built above. The disable
        // block covers the whole multi-line prepare() call.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare(
            "INSERT INTO {$statsTable} (experiment_id, variant, total, last_rebuilt_at)
             VALUES " . implode(', ', $placeholders) . "
             ON DUPLICATE KEY UPDATE
                 total           = VALUES(total),
                 last_rebuilt_at = VALUES(last_rebuilt_at)",
            $values
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        // $sql was built with $wpdb->prepare() immediately above.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->query($sql);

        if ($result === false) {
            $error = $wpdb->last_error ?: 'Unknown database error during assignment UPSERT.';
            Nofliq_Logger::error('StatsRebuildJob failed on assignment UPSERT: ' . $error);
            return ['rows_written' => 0, 'error' => $error];
        }

        // Sweep orphans: rows not refreshed by this rebuild no longer exist live.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$statsTable} WHERE last_rebuilt_at < %s",
                $rebuildAt
            )
        );

        if ($deleted === false) {
            // Non-fatal: the upsert already succeeded and the data is correct;
            // only stale orphans may remain. Log and continue.
            Nofliq_Logger::error('StatsRebuildJob: assignment orphan sweep failed: ' . ($wpdb->last_error ?: 'unknown'));
        } elseif ($deleted > 0) {
            Nofliq_Logger::info("StatsRebuildJob: swept {$deleted} orphan assignment-stat row(s).");
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
            Nofliq_Logger::info('StatsRebuildJob: Redis unavailable, skipping conversion snapshot.');
            return ['rows_written' => 0, 'error' => null];
        }

        $combos = $tracker->listAll();

        if (empty($combos)) {
            Nofliq_Logger::info('StatsRebuildJob: no conversion data in Redis, nothing to write.');
            return ['rows_written' => 0, 'error' => null];
        }

        $statsTable   = $wpdb->prefix . 'ab_test_conversions_stats';

        // Single timestamp for the whole batch — see rebuildAssignmentStats().
        $rebuildAt = current_time('mysql', true); // UTC 'Y-m-d H:i:s'

        $placeholders = [];
        $values       = [];

        foreach ($combos as $combo) {
            $placeholders[] = '(%s, %s, %s, %d, %d, %s)';
            $values[]       = $combo['experiment_id'];
            $values[]       = $combo['variant'];
            $values[]       = $combo['event_name'];
            $values[]       = (int) $combo['unique'];
            $values[]       = (int) $combo['total'];
            $values[]       = $rebuildAt;
        }

        // The table name is interpolated (safe: from $wpdb->prefix); all values
        // go through prepare() with the placeholders built above. The disable
        // block covers the whole multi-line prepare() call.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
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
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        // $sql was built with $wpdb->prepare() immediately above.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->query($sql);

        if ($result === false) {
            $error = $wpdb->last_error ?: 'Unknown database error during conversion UPSERT.';
            Nofliq_Logger::error('StatsRebuildJob failed on conversion UPSERT: ' . $error);
            return ['rows_written' => 0, 'error' => $error];
        }

        // Sweep orphans: conversion combos no longer present in Redis.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$statsTable} WHERE last_rebuilt_at < %s",
                $rebuildAt
            )
        );

        if ($deleted === false) {
            Nofliq_Logger::error('StatsRebuildJob: conversion orphan sweep failed: ' . ($wpdb->last_error ?: 'unknown'));
        } elseif ($deleted > 0) {
            Nofliq_Logger::info("StatsRebuildJob: swept {$deleted} orphan conversion-stat row(s).");
        }

        return ['rows_written' => (int) $result, 'error' => null];
    }
}