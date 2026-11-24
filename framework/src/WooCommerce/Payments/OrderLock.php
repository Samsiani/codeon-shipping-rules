<?php

declare(strict_types=1);

namespace CodeOn\Framework\WooCommerce\Payments;

/**
 * Per-order critical section shared by webhook, reconcile and admin
 * re-check paths.
 *
 * Replaces the `add_option()` / `update_option()` fallback the payment
 * plugins copied from docs/PAYMENT_GATEWAY_HARDENING.md §2.2. That was
 * not a mutex: `add_option()` is an upsert once `get_option()` misses,
 * and a failed add fell through to `update_option()` and carried on, so
 * two workers could both "hold" the lock.
 *
 * MySQL `GET_LOCK(name, 0)` first: held by the DB connection, no TTL to
 * run out mid-apply, released by `RELEASE_LOCK` or automatically when
 * the request dies, and the same backend for web and CLI requests. Only
 * when the database can't take named locks (NULL — Galera, SQLite
 * drop-ins) does it fall back to `wp_cache_add()` with a persistent
 * object cache, and failing that runs the callback unguarded — the
 * previous behaviour — rather than stalling payments.
 *
 * Returns the callback's result, or null when another request holds
 * the lock.
 */
final class OrderLock
{
    private const CACHE_GROUP = 'codeon-payments';

    /**
     * @template T
     * @param string        $scope Plugin-specific prefix, e.g. 'bog_card'.
     * @param callable(): T $fn
     * @return T|null
     */
    public static function run(string $scope, int $orderId, callable $fn, int $ttlSeconds = 30): mixed
    {
        global $wpdb;

        $key = 'codeon_' . $scope . '_lock_' . $orderId;
        // Named locks are server-wide: include the database and table
        // prefix so two shops on one MySQL server never share a lock.
        // MySQL caps the name at 64 characters.
        $dbName = defined('DB_NAME') ? (string) DB_NAME : '';
        $name = 'codeon:' . md5($dbName . '|' . $wpdb->prefix . '|' . $key);
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));

        if ($got !== null) {
            if ((int) $got !== 1) {
                return null;
            }
            try {
                return $fn();
            } finally {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            }
        }

        self::warnOnce();

        if (wp_using_ext_object_cache()) {
            if (!wp_cache_add($key, 1, self::CACHE_GROUP, $ttlSeconds)) {
                return null;
            }
            try {
                return $fn();
            } finally {
                wp_cache_delete($key, self::CACHE_GROUP);
            }
        }

        return $fn();
    }

    private static function warnOnce(): void
    {
        static $warned = false;
        if ($warned || !function_exists('wc_get_logger')) {
            return;
        }
        $warned = true;
        wc_get_logger()->warning(
            'GET_LOCK unavailable on this database — per-order payment locking falls back to the object cache or runs unguarded.',
            ['source' => 'codeon-payments']
        );
    }
}
