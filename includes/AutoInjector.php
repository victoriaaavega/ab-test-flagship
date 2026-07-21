<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides active experiments for the current URL and exposes the result to the
 * frontend as window.abTestData / window.abTestConfig.
 *
 * The data is attached to the event-tracker script via wp_add_inline_script()
 * (see abtf_enqueue_scripts) rather than printed as a raw <script> tag, so it
 * follows WordPress' script pipeline and always lands before event-tracker.js.
 */
class AutoInjector {

    /**
     * Builds the inline JS that exposes the AB test data for the current page,
     * or an empty string when there is nothing to inject (wrong page, no active
     * experiments, or Flagship mode without credentials).
     *
     * Returns a string instead of echoing so the caller can hand it to
     * wp_add_inline_script() on the 'abtf-event-tracker' handle.
     */
    public function buildInlineData(): string {
        // In Flagship mode, credentials are required to decide and activate
        // against AB Tasty. Without them there is nothing to inject and we bail
        // (the admin sees the credentials notice). In Local mode no credentials
        // are needed: the plugin decides variants itself, so injection proceeds.
        if (DecisionMode::isFlagship() && !CredentialsManager::hasCredentials()) {
            return '';
        }

        global $wpdb;
        $tableName = $wpdb->prefix . 'ab_test_experiments';

        // Table name comes from $wpdb->prefix (not user input) and cannot be
        // passed through prepare(). 'active' is a hardcoded literal, no user data.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $experiments = $wpdb->get_results("SELECT * FROM {$tableName} WHERE status = 'active'");

        if (empty($experiments)) {
            return '';
        }

        $currentUri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        // parse_url() returns string on success, null when there is no path,
        // or false on a malformed URL. matchUrl() requires a string, so fall
        // back to '/' to avoid a TypeError on unusual request URIs (bots,
        // fuzzing, rewriting proxies).
        $parsedUri  = wp_parse_url($currentUri, PHP_URL_PATH) ?: '/';

        $matchedExperiments = [];

        foreach ($experiments as $exp) {
            if ($this->matchUrl($parsedUri, $exp->urls)) {
                $matchedExperiments[] = $exp;
            }
        }

        if (empty($matchedExperiments)) {
            return '';
        }

        $runner       = abtf_runner();
        $visitorId    = '';
        $abTestData   = ['experiments' => []];
        $abTestConfig = [];

        foreach ($matchedExperiments as $exp) {
            $result = $runner->run($exp->flag_key);

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

        $dataJson   = wp_json_encode($abTestData);
        $configJson = wp_json_encode($abTestConfig);

        // wp_json_encode returns false on failure; guard so we never emit
        // 'window.abTestData = false;'.
        if ($dataJson === false || $configJson === false) {
            Nofliq_Logger::error('AutoInjector: failed to JSON-encode AB test data. Nothing injected.');
            return '';
        }

        return 'window.abTestData = ' . $dataJson . ';'
             . 'window.abTestConfig = ' . $configJson . ';';
    }

    /**
     * Checks if the current path matches any of the comma-separated rules.
     * Supports exact matches and wildcards (e.g., /talent/*)
     */
    private function matchUrl(string $currentPath, string $rules): bool {
        $rulesArray = array_map('trim', explode(',', $rules));

        foreach ($rulesArray as $rule) {
            if ($rule === '*' || $rule === $currentPath) {
                return true;
            }

            if (str_ends_with($rule, '/*')) {
                $basePath = substr($rule, 0, -2);

                if ($basePath === '' || str_starts_with($currentPath, $basePath . '/') || $currentPath === $basePath) {
                    return true;
                }
            }
        }

        return false;
    }
}