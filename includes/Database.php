<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles all database operations for AB test variant storage
 * and experiment configurations.
 */
class Database
{

    // Bump version to trigger dbDelta on schema changes
    private const TABLE_VERSION = '1.5';

    /**
     * Creates the required tables only if they haven't been created yet
     */
    public function maybeCreateTable(): void
    {
        if (get_option('ab_test_table_version') === self::TABLE_VERSION) {
            return;
        }

        $this->createAssignmentsTable();
        $this->createExperimentsTable();
        $this->createStatsTable();
        $this->createConversionsStatsTable();
        $this->createConversionsLocalTable();

        update_option('ab_test_table_version', self::TABLE_VERSION);
    }

    /**
     * Retrieves the assigned variant for a visitor and experiment
     */
    public function getVariant(string $experimentId, string $visitorId): ?string
    {
        global $wpdb;

        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT variant FROM {$this->getAssignmentsTableName()} WHERE experiment_id = %s AND visitor_id = %s LIMIT 1",
                $experimentId,
                $visitorId
            )
        );

        return $result ?? null;
    }

    /**
     * Saves the assigned variant for a visitor and experiment.
     *
     * Uses INSERT IGNORE: the UNIQUE KEY (experiment_id, visitor_id) means a
     * second save for the same visitor + experiment is silently dropped, which
     * is the intended behaviour (the first assignment wins).
     *
     * Returns true ONLY when a new row was actually inserted. A duplicate that
     * INSERT IGNORE discarded returns false — not because anything failed, but
     * because nothing was written. This keeps the boolean honest: true means
     * "a new assignment was stored", not merely "no SQL error".
     *
     * Rationale: $wpdb->query() returns 1 on insert, 0 when INSERT IGNORE drops
     * a duplicate, and false on a real SQL error. The previous `!== false`
     * check collapsed 1 and 0 into "success", so a duplicate reported true —
     * a subtle lie for any caller that reads the result to mean "inserted".
     *
     * @return bool true if a new row was inserted; false if it already existed
     *              or a SQL error occurred. SQL errors are logged.
     */
    public function saveVariant(string $experimentId, string $visitorId, string $variant): bool
    {
        global $wpdb;

        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$this->getAssignmentsTableName()} (experiment_id, visitor_id, variant) VALUES (%s, %s, %s)",
                $experimentId,
                $visitorId,
                $variant
            )
        );

        if ($result === false) {
            error_log('[AB Test] Database saveVariant error: ' . ($wpdb->last_error ?: 'unknown'));
            return false;
        }

        // 1 = inserted, 0 = ignored (duplicate). Honest "was it stored?".
        return $result === 1;
    }

    private function getAssignmentsTableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ab_test_assignments';
    }

    private function getExperimentsTableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ab_test_experiments';
    }

    /**
     * Creates the assignments table
     */
    private function createAssignmentsTable(): void
    {
        global $wpdb;

        $charset   = $wpdb->get_charset_collate();
        $tableName = $this->getAssignmentsTableName();

        $sql = "CREATE TABLE {$tableName} (
            id            BIGINT(20)   NOT NULL AUTO_INCREMENT,
            experiment_id VARCHAR(100) NOT NULL,
            visitor_id    VARCHAR(64)  NOT NULL,
            variant       VARCHAR(100) NOT NULL,
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY experiment_visitor (experiment_id, visitor_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        error_log('[AB Test] Table ' . $tableName . ' checked/created.');
    }

    /**
     * Creates the experiments table for the admin CRUD UI
     * 
     * variant_a / variant_b name the two variants of the experiment. In local
     * mode LocalAdapter buckets visitors between them; in Flagship mode they
     * document what the campaign returns, so the reporting labels line up.
     * variant_a is the baseline the growth column compares against.
     */
    private function createExperimentsTable(): void
    {
        global $wpdb;

        $charset   = $wpdb->get_charset_collate();
        $tableName = $this->getExperimentsTableName();

        $sql = "CREATE TABLE {$tableName} (
            id            BIGINT(20)   NOT NULL AUTO_INCREMENT,
            flag_key      VARCHAR(100) NOT NULL,
            name          VARCHAR(255) NOT NULL,
            selector      VARCHAR(255) NOT NULL,
            event_name    VARCHAR(100) NOT NULL,
            event_type    VARCHAR(50)  DEFAULT 'click' NOT NULL,
            variant_a     VARCHAR(100) DEFAULT 'control' NOT NULL,
            variant_b     VARCHAR(100) DEFAULT 'variation_b' NOT NULL,
            urls          TEXT         NOT NULL,
            status        VARCHAR(20)  DEFAULT 'active' NOT NULL,
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY flag_key (flag_key)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        error_log('[AB Test] Table ' . $tableName . ' checked/created.');
    }

    /**
     * Creates the stats table for pre-calculated experiment totals.
     * The cron job writes here so MetaBox never runs COUNT(*) live.
     */
    private function createStatsTable(): void
    {
        global $wpdb;

        $charset   = $wpdb->get_charset_collate();
        $tableName = $wpdb->prefix . 'ab_test_stats';

        $sql = "CREATE TABLE {$tableName} (
        id                BIGINT(20)   NOT NULL AUTO_INCREMENT,
        experiment_id     VARCHAR(100) NOT NULL,
        variant           VARCHAR(100) NOT NULL,
        total             BIGINT(20)   NOT NULL DEFAULT 0,
        last_rebuilt_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY experiment_variant (experiment_id, variant)
    ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        error_log('[AB Test] Table ' . $tableName . ' checked/created.');
    }

    /**
     * Creates the conversions stats table — a persistent backup of the
     * real-time conversion counters held in Redis (DB 3).
     *
     * Redis is the live source of truth for the reporting dashboard; this
     * table is a periodic snapshot written by StatsRebuildJob so data
     * survives a Redis restart or outage.
     *
     * One row per experiment + variant + event combination.
     * 
     * mode records which engine produced the row. Redis snapshots are written
     * as 'flagship'; rows written directly by ConversionTracker when Redis is
     * down in local mode are 'local'. StatsRebuildJob only sweeps 'flagship'
     * rows, so a Redis recovery never deletes locally recorded conversions.
     */
    private function createConversionsStatsTable(): void
    {
        global $wpdb;

        $charset   = $wpdb->get_charset_collate();
        $tableName = $wpdb->prefix . 'ab_test_conversions_stats';

        $sql = "CREATE TABLE {$tableName} (
            id                  BIGINT(20)   NOT NULL AUTO_INCREMENT,
            experiment_id       VARCHAR(100) NOT NULL,
            variant             VARCHAR(100) NOT NULL,
            event_name          VARCHAR(100) NOT NULL,
            unique_conversions  BIGINT(20)   NOT NULL DEFAULT 0,
            total_conversions   BIGINT(20)   NOT NULL DEFAULT 0,
            mode                VARCHAR(20)  DEFAULT 'flagship' NOT NULL,
            last_rebuilt_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY experiment_variant_event (experiment_id, variant, event_name)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        error_log('[AB Test] Table ' . $tableName . ' checked/created.');
    }

    /**
     * Creates the local conversions table — the persistent store for
     * conversions recorded directly in local mode when Redis is unavailable.
     *
     * One row per unique (experiment + variant + event + visitor). The UNIQUE
     * KEY plus INSERT IGNORE means a visitor is counted once per goal no matter
     * how many times they click, so COUNT(*) over a combo yields the unique
     * conversion count without needing Redis' HyperLogLog.
     *
     * This table is independent of ab_test_conversions_stats: it is never
     * touched by StatsRebuildJob's Redis snapshot or its orphan sweep, so a
     * Redis recovery can never delete locally recorded conversions.
     */
    private function createConversionsLocalTable(): void
    {
        global $wpdb;

        $charset   = $wpdb->get_charset_collate();
        $tableName = $wpdb->prefix . 'ab_test_conversions_local';

        $sql = "CREATE TABLE {$tableName} (
            id            BIGINT(20)   NOT NULL AUTO_INCREMENT,
            experiment_id VARCHAR(100) NOT NULL,
            variant       VARCHAR(100) NOT NULL,
            event_name    VARCHAR(100) NOT NULL,
            visitor_id    VARCHAR(255) NOT NULL,
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY experiment_variant_event_visitor (experiment_id, variant, event_name, visitor_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        error_log('[AB Test] Table ' . $tableName . ' checked/created.');
    }
}