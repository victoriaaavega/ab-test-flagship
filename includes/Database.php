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
    private const TABLE_VERSION = '1.2';

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

        return $result !== false;
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
}
