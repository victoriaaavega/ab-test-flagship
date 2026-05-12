<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the WP-Cron schedule for rebuilding experiment stats.
 *
 * Registers a custom 8-hour interval and ensures the recurring event
 * is always scheduled while the plugin is active.
 *
 * Production note: WP-Cron only fires when someone visits the site.
 * On Kinsta, configure a real system cron to hit the site every 8 hours:
 *   curl --silent https://castingnetworks.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1
 */
class CronManager
{

    public const HOOK     = 'abtf_rebuild_stats';
    public const INTERVAL = 'abtf_8hours';

    public function __construct()
    {
        add_action(self::HOOK, [$this, 'runRebuild']);
        $this->maybeSchedule();
    }

    /**
     * Registers the custom 8-hour interval with WP-Cron.
     */
    public function registerInterval(array $schedules): array
    {
        $schedules[self::INTERVAL] = [
            'interval' => 8 * HOUR_IN_SECONDS,
            'display'  => 'Every 8 hours',
        ];
        return $schedules;
    }

    /**
     * Schedules the recurring event if it is not already scheduled.
     * Safe to call on every request — exits immediately if already set.
     */
    public function maybeSchedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), self::INTERVAL, self::HOOK);
            error_log('[AB Test] CronManager: abtf_rebuild_stats scheduled.');
        }
    }

    /**
     * Callback fired by WP-Cron. Delegates entirely to StatsRebuildJob.
     */
    public function runRebuild(): void
    {
        error_log('[AB Test] CronManager: starting scheduled stats rebuild.');
        StatsRebuildJob::run();
    }

    /**
     * Removes the scheduled event. Call this on plugin deactivation.
     */
    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
            error_log('[AB Test] CronManager: abtf_rebuild_stats unscheduled.');
        }
    }
}
