<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers and renders the Conversions Reporting page.
 *
 * Shows a Flagship-style table per experiment: unique visitors, unique
 * conversions, total conversions, conversion rate, and growth vs. the
 * 'control' baseline.
 *
 * Data sources, in priority order:
 *   1. Redis (DB 3) via ConversionTracker — live, real-time, the default.
 *   2. wp_ab_test_conversions_stats — the periodic SQL snapshot, used as a
 *      fallback when Redis is unavailable. A notice tells the admin the data
 *      is a snapshot and shows when it was last rebuilt.
 *
 * Unique visitor counts (the conversion-rate denominator) always come from
 * wp_ab_test_stats, the pre-aggregated assignment totals.
 */
class ReportingPage
{
    private const MENU_SLUG = 'abtf-reporting';
    private const BASELINE  = 'control';

    public function __construct()
    {
        add_action('admin_menu',    [$this, 'addMenuPage']);
        add_action('admin_init',    [$this, 'handleWrites']);
        add_action('admin_notices', [$this, 'renderNotice']);
    }

    public function addMenuPage(): void
    {
        add_submenu_page(
            'abtf-experiments',
            'AB Test — Reporting',
            'Reporting',
            'manage_options',
            self::MENU_SLUG,
            [$this, 'renderPage']
        );
    }

    // -------------------------------------------------------------------------
    // Write handler — hooked to admin_init (before HTML output, so redirects work)
    //
    // The Rebuild Stats button posts here. Rebuilding refreshes BOTH the
    // assignment stats (visitor counts) and the conversion snapshot, so the
    // button belongs with the data consumers (this page and the dashboard
    // widget), not on the experiments-config page.
    // -------------------------------------------------------------------------

    public function handleWrites(): void
    {
        if (($_GET['page'] ?? '') !== self::MENU_SLUG) {
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $action = sanitize_key($_POST['abtf_action'] ?? '');

        if ($action === 'rebuild_stats') {
            $this->handleRebuildStats();
        }
    }

    private function handleRebuildStats(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.'), 403);
        }

        check_admin_referer('abtf_rebuild_stats');

        $result = StatsRebuildJob::run();

        $noticeKey  = $result['error'] === null ? 'stats_rebuilt' : 'stats_error';
        $noticeType = $result['error'] === null ? 'success' : 'error';

        wp_safe_redirect(add_query_arg([
            'page'        => self::MENU_SLUG,
            'abtf_notice' => $noticeKey,
            'abtf_type'   => $noticeType,
        ], admin_url('admin.php')));
        exit;
    }

    // -------------------------------------------------------------------------
    // Notices
    // -------------------------------------------------------------------------

    private const NOTICE_MESSAGES = [
        'stats_rebuilt' => 'Stats rebuilt successfully.',
        'stats_error'   => 'Stats rebuild failed. Check error logs.',
    ];

    public function renderNotice(): void
    {
        $screen = get_current_screen();
        if (!$screen || !str_contains($screen->id, self::MENU_SLUG)) {
            return;
        }

        $key  = sanitize_key($_GET['abtf_notice'] ?? '');
        $type = sanitize_key($_GET['abtf_type']   ?? 'success');

        if ($key === '' || !isset(self::NOTICE_MESSAGES[$key])) {
            return;
        }

        $type = in_array($type, ['success', 'error', 'warning'], true) ? $type : 'success';

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($type),
            esc_html(self::NOTICE_MESSAGES[$key])
        );
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function renderPage(): void
    {
        [$rows, $source, $lastRebuiltAt] = $this->fetchConversionData();
        $visitorCounts = $this->fetchVisitorCounts();

        // Group conversion rows by experiment, then by event, then by variant.
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['experiment_id']][$row['event_name']][$row['variant']] = $row;
        }

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">AB Test — Reporting</h1>
            <form method="post" style="display:inline;">
                <?php wp_nonce_field('abtf_rebuild_stats'); ?>
                <input type="hidden" name="abtf_action" value="rebuild_stats">
                <button type="submit" class="page-title-action">
                    ↺ Rebuild Stats
                </button>
            </form>
            <hr class="wp-header-end">

            <?php $this->renderSourceNotice($source, $lastRebuiltAt); ?>

            <?php if (empty($grouped)): ?>
                <p style="margin-top: 16px;">No conversion data yet. Conversions appear here in real time as visitors interact with active experiments.</p>
            <?php else: ?>
                <?php foreach ($grouped as $experimentId => $events): ?>
                    <?php $this->renderExperiment($experimentId, $events, $visitorCounts[$experimentId] ?? []); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderSourceNotice(string $source, string $lastRebuiltAt): void
    {
        if ($source === 'redis') {
            echo '<p style="margin-top: 12px; color: #2e7d32;">';
            echo '● Live data from Redis (real time).';
            echo '</p>';
            return;
        }

        echo '<div class="notice notice-warning inline" style="margin-top: 12px;"><p>';
        echo '<strong>Redis unavailable.</strong> Showing the last saved snapshot';
        if ($lastRebuiltAt !== '') {
            echo ' from ' . esc_html($lastRebuiltAt) . ' (UTC)';
        }
        echo '. Live numbers may be higher.';
        echo '</p></div>';
    }

    private function renderExperiment(string $experimentId, array $events, array $variantVisitors): void
    {
        ?>
        <div style="background: #fff; padding: 20px 24px; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 1000px; margin-top: 20px;">
            <h2 style="margin-top: 0;"><?php echo esc_html($experimentId); ?></h2>

            <?php foreach ($events as $eventName => $variants): ?>
                <h3 style="margin-bottom: 8px; color: #1d2327;">
                    <?php echo esc_html($eventName); ?>
                    <span style="font-weight: normal; color: #646970; font-size: 13px;">— Event</span>
                </h3>

                <?php
                // Compute the baseline conversion rate for growth comparison.
                $baselineRate = null;
                if (isset($variants[self::BASELINE])) {
                    $baselineVisitors = (int) ($variantVisitors[self::BASELINE] ?? 0);
                    $baselineRate = $this->conversionRate(
                        (int) $variants[self::BASELINE]['unique'],
                        $baselineVisitors
                    );
                }
                ?>

                <table class="wp-list-table widefat fixed striped" style="margin-bottom: 24px;">
                    <thead>
                        <tr>
                            <th>Variation</th>
                            <th style="text-align: right;">Unique Visitors</th>
                            <th style="text-align: right;">Unique Conversions</th>
                            <th style="text-align: right;">Total Conversions</th>
                            <th style="text-align: right;">Conversion Rate</th>
                            <th style="text-align: right;">Growth</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($variants as $variant => $data): ?>
                            <?php
                            $visitors   = (int) ($variantVisitors[$variant] ?? 0);
                            $unique     = (int) $data['unique'];
                            $total      = (int) $data['total'];
                            $rate       = $this->conversionRate($unique, $visitors);
                            $isBaseline = ($variant === self::BASELINE);
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($variant); ?></strong>
                                    <?php if ($isBaseline): ?>
                                        <span style="background: #e0e0e0; color: #333; padding: 2px 8px; border-radius: 10px; font-size: 11px; margin-left: 6px;">baseline</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;"><?php echo esc_html(number_format($visitors)); ?></td>
                                <td style="text-align: right;"><?php echo esc_html(number_format($unique)); ?></td>
                                <td style="text-align: right;"><?php echo esc_html(number_format($total)); ?></td>
                                <td style="text-align: right;"><?php echo esc_html(number_format($rate, 2)); ?>%</td>
                                <td style="text-align: right;"><?php echo $this->growthCell($rate, $baselineRate, $isBaseline); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Renders the growth cell HTML comparing a variant's rate to the baseline.
     */
    private function growthCell(float $rate, ?float $baselineRate, bool $isBaseline): string
    {
        if ($isBaseline) {
            return '<span style="color: #646970;">0%</span>';
        }

        if ($baselineRate === null) {
            return '<span style="color: #646970;">—</span>';
        }

        if ($baselineRate == 0.0) {
            // Baseline never converted — growth is undefined rather than infinite.
            return '<span style="color: #646970;">—</span>';
        }

        $growth = (($rate - $baselineRate) / $baselineRate) * 100;
        $color  = $growth >= 0 ? '#2e7d32' : '#a00';
        $sign   = $growth >= 0 ? '+' : '';

        return sprintf(
            '<span style="color: %s; font-weight: 600;">%s%s%%</span>',
            esc_attr($color),
            esc_html($sign),
            esc_html(number_format($growth, 1))
        );
    }

    /**
     * Conversion rate = unique conversions / unique visitors * 100.
     */
    private function conversionRate(int $uniqueConversions, int $uniqueVisitors): float
    {
        if ($uniqueVisitors <= 0) {
            return 0.0;
        }
        return ($uniqueConversions / $uniqueVisitors) * 100;
    }

    // -------------------------------------------------------------------------
    // Data fetching
    // -------------------------------------------------------------------------

    /**
     * Returns conversion data with its source.
     *
     * @return array{0: array<int, array{experiment_id: string, variant: string, event_name: string, total: int, unique: int}>, 1: string, 2: string}
     *               [rows, source ('redis'|'sql'), lastRebuiltAt]
     */
    private function fetchConversionData(): array
    {
        $tracker = new ConversionTracker();

        if ($tracker->isAvailable()) {
            return [$tracker->listAll(), 'redis', ''];
        }

        return $this->fetchConversionDataFromSql();
    }

    /**
     * Reads the SQL snapshot fallback.
     *
     * @return array{0: array, 1: string, 2: string}
     */
    private function fetchConversionDataFromSql(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ab_test_conversions_stats';

        $results = $wpdb->get_results(
            "SELECT experiment_id, variant, event_name, unique_conversions, total_conversions, last_rebuilt_at
             FROM {$table}",
            ARRAY_A
        );

        if (empty($results)) {
            return [[], 'sql', ''];
        }

        $rows          = [];
        $lastRebuiltAt = '';

        foreach ($results as $r) {
            $rows[] = [
                'experiment_id' => $r['experiment_id'],
                'variant'       => $r['variant'],
                'event_name'    => $r['event_name'],
                'unique'        => (int) $r['unique_conversions'],
                'total'         => (int) $r['total_conversions'],
            ];
            $lastRebuiltAt = $r['last_rebuilt_at'];
        }

        return [$rows, 'sql', $lastRebuiltAt];
    }

    /**
     * Returns unique visitor counts per experiment + variant from the
     * pre-aggregated assignment stats table.
     *
     * @return array<string, array<string, int>> [experimentId][variant] => count
     */
    private function fetchVisitorCounts(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ab_test_stats';

        $results = $wpdb->get_results(
            "SELECT experiment_id, variant, total FROM {$table}",
            ARRAY_A
        );

        $counts = [];
        foreach ($results as $r) {
            $counts[$r['experiment_id']][$r['variant']] = (int) $r['total'];
        }

        return $counts;
    }
}