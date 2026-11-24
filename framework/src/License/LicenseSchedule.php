<?php

declare(strict_types=1);

namespace CodeOn\Framework\License;

/**
 * When CodeOn plugins re-check their licence with codeon.ge.
 *
 * One WP-Cron event per site, every 8 hours, shared by every CodeOn
 * plugin on it: each plugin hangs its check on the event, so all
 * licences are checked in one background run. A plugin checks only
 * when its last check — successful or failed — is old enough, so a
 * site asks codeon.ge at most three times a day per plugin, also
 * while codeon.ge is unreachable.
 *
 * Nothing runs on page loads. The one exception: when a plugin's last
 * check is more than two days old (the site's WP-Cron isn't running),
 * an administrator's visit queues the shared check once.
 *
 * "Check now" and saving a key keep calling the plugin's own check
 * directly — that path doesn't go through here.
 *
 * Every CodeOn plugin loads the framework through the Jetpack
 * autoloader, so the newest copy of this class on a site serves all of
 * them; plugins that don't call it keep their own schedule.
 */
final class LicenseSchedule
{
    public const HOOK = 'codeon/licenses/check';
    public const INTERVAL = 'codeon_every_8_hours';
    public const GROUP = 'codeon-licenses';

    /** The shared event's interval. */
    public const EVERY = 8 * 3600;

    /**
     * A check is due this long after the last one — a little under the
     * interval, so the event that comes 8 hours after a check (which
     * stamped its time a few seconds after the event started) still finds
     * it due.
     */
    public const DUE_AFTER = self::EVERY - 1800;

    /** Last check older than this: WP-Cron isn't running here. */
    public const STALE_AFTER = 2 * 86400;

    /** Option listing the plugins' old per-plugin hooks already removed. */
    public const RETIRED_OPTION = 'codeon_license_retired_hooks';

    private static bool $armed = false;

    /**
     * Hang a plugin's licence check on the shared event.
     *
     * @param callable():void $check         the plugin's check (asks codeon.ge, stores the answer)
     * @param callable():int  $lastCheckedAt unix time of its last check, successful or failed; 0 = never
     * @param string          $legacyHook    the plugin's old per-plugin revalidate hook, removed once
     */
    public static function register(callable $check, callable $lastCheckedAt, string $legacyHook = ''): void
    {
        add_filter('cron_schedules', [self::class, 'addInterval']);
        add_action(self::HOOK, static function () use ($check, $lastCheckedAt): void {
            if (self::isDue((int) $lastCheckedAt(), time())) {
                $check();
            }
        });
        add_action('admin_init', static function () use ($lastCheckedAt): void {
            self::queueIfStale((int) $lastCheckedAt(), time());
        });
        if ($legacyHook !== '') {
            add_action('init', static function () use ($legacyHook): void {
                self::retire($legacyHook);
            });
        }
        add_action('init', [self::class, 'arm']);
    }

    /**
     * @param array<string, array{interval:int, display:string}> $schedules
     * @return array<string, array{interval:int, display:string}>
     */
    public static function addInterval(array $schedules): array
    {
        if (!isset($schedules[self::INTERVAL])) {
            $schedules[self::INTERVAL] = [
                'interval' => self::EVERY,
                'display'  => 'Every 8 hours (CodeOn licences)',
            ];
        }
        return $schedules;
    }

    /** Schedule the shared event if no plugin has yet. Once per request. */
    public static function arm(): void
    {
        if (self::$armed) {
            return;
        }
        self::$armed = true;
        if (!wp_next_scheduled(self::HOOK)) {
            // Spread sites over the interval instead of every site asking
            // codeon.ge at the same minute after a release.
            wp_schedule_event(time() + mt_rand(300, self::EVERY), self::INTERVAL, self::HOOK);
        }
    }

    public static function isDue(int $lastCheckedAt, int $now): bool
    {
        return $lastCheckedAt <= 0 || $now - $lastCheckedAt >= self::DUE_AFTER;
    }

    /**
     * On an administrator's visit: when the last check is more than two
     * days old, queue the shared check once. Action Scheduler runs it
     * even where WP-Cron is off; plain WP-Cron otherwise.
     */
    public static function queueIfStale(int $lastCheckedAt, int $now): void
    {
        if ($lastCheckedAt <= 0 || $now - $lastCheckedAt < self::STALE_AFTER) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        if (function_exists('as_enqueue_async_action') && function_exists('as_has_scheduled_action')) {
            if (!as_has_scheduled_action(self::HOOK, [], self::GROUP)) {
                as_enqueue_async_action(self::HOOK, [], self::GROUP);
            }
            return;
        }
        $next = wp_next_scheduled(self::HOOK);
        if ($next === false || $next > $now) {
            wp_schedule_single_event($now, self::HOOK);
        }
    }

    /**
     * Remove a plugin's old per-plugin check — its daily event and any
     * queued async checks — once per site.
     */
    public static function retire(string $legacyHook): void
    {
        $retired = get_option(self::RETIRED_OPTION, []);
        if (!is_array($retired)) {
            $retired = [];
        }
        if (in_array($legacyHook, $retired, true)) {
            return;
        }
        wp_clear_scheduled_hook($legacyHook);
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions($legacyHook);
        }
        $retired[] = $legacyHook;
        update_option(self::RETIRED_OPTION, $retired, true);
    }
}
