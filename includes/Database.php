<?php

/**
 * Handles all database operations for AB test variant storage
 */
class Database {

    private const TABLE_VERSION = '1.0';

    /**
     * Creates the assignments table only if it hasn't been created yet
     */
    public function maybeCreateTable(): void {
        if (get_option('ab_test_table_version') === self::TABLE_VERSION) {
            return;
        }

        $this->createTable();
        update_option('ab_test_table_version', self::TABLE_VERSION);
    }

    /**
     * Retrieves the assigned variant for a visitor and experiment
     * Returns null if no assignment is found
     *
     * @param string $experimentId
     * @param string $visitorId
     * @return string|null
     */
    public function getVariant(string $experimentId, string $visitorId): ?string {
        global $wpdb;

        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT variant FROM {$this->getTableName()} WHERE experiment_id = %s AND visitor_id = %s",
                $experimentId,
                $visitorId
            )
        );

        return $result ?? null;
    }

    /**
     * Saves the assigned variant for a visitor and experiment
     *
     * @param string $experimentId
     * @param string $visitorId
     * @param string $variant
     * @return bool
     */
    public function saveVariant(string $experimentId, string $visitorId, string $variant): bool {
        global $wpdb;

        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$this->getTableName()} (experiment_id, visitor_id, variant) VALUES (%s, %s, %s)",
                $experimentId,
                $visitorId,
                $variant
            )
        );

        return $result !== false;
    }

    /**
     * Returns the full table name including WordPress prefix
     *
     * @return string
     */
    private function getTableName(): string {
        global $wpdb;
        return $wpdb->prefix . 'ab_test_assignments';
    }

    /**
     * Creates the assignments table using WordPress dbDelta()
     */
    private function createTable(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE {$this->getTableName()} (
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

        error_log('[AB Test] Table ' . $this->getTableName() . ' created or already exists.');
    }
}