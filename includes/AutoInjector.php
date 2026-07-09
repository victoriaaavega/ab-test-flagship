<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Automatically injects AB test configuration into the site footer
 * based on the current URL and active experiments in the database.
 */
class AutoInjector {

    public function __construct() {
        // Hook into wp_footer with a high priority (99) to ensure it runs late
        add_action('wp_footer', [$this, 'injectScripts'], 99);
    }

    public function injectScripts(): void {
        // Safety guard: with no Flagship credentials configured, the plugin does
        // NOT touch the visitor-facing page at all — no experiment script is
        // injected, no decision is made, nothing is rendered. The site looks
        // exactly as if the plugin were inactive. The admin still shows the
        // "credentials not configured" notice so the cause is visible.
        //
        // This makes it impossible for the plugin to alter a real page before
        // it has been deliberately configured. It also means the local
        // SimulatorAdapter is never reached through auto-injection without
        // credentials (intended: experiments only run against real Flagship).
        if (!CredentialsManager::hasCredentials()) {
            return;
        }

        global $wpdb;
        $tableName = $wpdb->prefix . 'ab_test_experiments';

        // 1. Fetch only active experiments
        // Table name comes from $wpdb->prefix (not user input) and cannot be
        // passed through prepare(). 'active' is a hardcoded literal, no user data.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $experiments = $wpdb->get_results("SELECT * FROM {$tableName} WHERE status = 'active'");

        if (empty($experiments)) {
            return;
        }

        $currentUri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        // parse_url() returns string on success, null when there is no path,
        // or false on a malformed URL. matchUrl() requires a string, so fall
        // back to '/' to avoid a TypeError on unusual request URIs (bots,
        // fuzzing, rewriting proxies).
        $parsedUri  = wp_parse_url($currentUri, PHP_URL_PATH) ?: '/';

        $matchedExperiments = [];

        // 2. Match current URL against experiment rules
        foreach ($experiments as $exp) {
            if ($this->matchUrl($parsedUri, $exp->urls)) {
                $matchedExperiments[] = $exp;
            }
        }

        // If no experiments match this page, do nothing
        if (empty($matchedExperiments)) {
            return;
        }

        // 3. Run matched experiments and build the JS config
        $runner       = abtf_runner();
        $visitorId    = '';
        $abTestData   = ['experiments' => []];
        $abTestConfig = [];

        foreach ($matchedExperiments as $exp) {
            $result = $runner->run($exp->flag_key);

            // The visitor ID will be the same for all experiments in the same session
            if (empty($visitorId)) {
                $visitorId = $result['visitorId'];
            }

            $abTestData['experiments'][$exp->flag_key] = $result['variant'];

            $abTestConfig[] = [
                'experimentId' => $exp->flag_key,
                'selector'     => $exp->selector,
                'eventName'    => $exp->event_name,
                'type'         => $exp->event_type
            ];
        }

        $abTestData['visitorId'] = $visitorId;

        // 4. Output the configuration safely as JSON
        ?>
        <script>
            window.abTestData = <?php echo wp_json_encode($abTestData); ?>;
            window.abTestConfig = <?php echo wp_json_encode($abTestConfig); ?>;
        </script>
        <?php
    }

    /**
     * Checks if the current path matches any of the comma-separated rules.
     * Supports exact matches and wildcards (e.g., /talent/*)
     */
    private function matchUrl(string $currentPath, string $rules): bool {
        $rulesArray = array_map('trim', explode(',', $rules));

        foreach ($rulesArray as $rule) {
            // Exact match or universal wildcard
            if ($rule === '*' || $rule === $currentPath) {
                return true;
            }

            // Handle wildcard at the end (e.g., /talent/*)
            if (str_ends_with($rule, '/*')) {
                $basePath = substr($rule, 0, -2); // Remove the /*

                // If base path is empty (rule was /*), it matches everything
                // Otherwise, check if current path starts with base path
                if ($basePath === '' || str_starts_with($currentPath, $basePath . '/') || $currentPath === $basePath) {
                    return true;
                }
            }
        }

        return false;
    }
}