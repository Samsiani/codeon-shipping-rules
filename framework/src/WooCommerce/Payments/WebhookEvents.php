<?php

declare(strict_types=1);

namespace CodeOn\Framework\WooCommerce\Payments;

/**
 * Cross-plugin webhook idempotency table.
 *
 * Single source of truth for "have we already processed this bank
 * webhook?" across every CodeOn payment micro-plugin. Each plugin's
 * Activator calls {@see installSchema()} on activation; the first
 * call creates `{$wpdb->prefix}codeon_payment_events`, every
 * subsequent call no-ops via dbDelta. The table survives any single
 * plugin's uninstall — we never auto-drop it because another active
 * plugin may still be relying on the in-flight rows.
 *
 * Handlers use `record()` which atomically `INSERT IGNORE`s the row
 * — the database itself decides who wins the race. Application-level
 * locking is unnecessary.
 *
 * Each row is keyed by `(gateway, event_id)` UNIQUE. `gateway` is the
 * concrete WC gateway slug (`codeon_tbc_card`, `codeon_bog_card`,
 * `codeon_flitt_card`, …) — the slug stays plugin-scoped so two
 * plugins can't accidentally share an event_id space.
 */
final class WebhookEvents
{
    public const TABLE = 'codeon_payment_events';
    public const DB_VERSION_OPTION = 'codeon_payment_events_db_version';
    public const DB_VERSION = '1';

    /**
     * Idempotently install the cross-plugin events table.
     * Safe to call from any plugin's activation hook.
     */
    public static function installSchema(): void
    {
        global $wpdb;

        $table           = self::tableName();
        $charsetCollate  = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            gateway       VARCHAR(40)  NOT NULL,
            event_id      VARCHAR(191) NOT NULL,
            order_id      BIGINT UNSIGNED NULL,
            status        VARCHAR(40)  NOT NULL,
            payload_hash  CHAR(64)     NOT NULL,
            received_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at  DATETIME     NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_gateway_event (gateway, event_id),
            KEY idx_order (order_id),
            KEY idx_received (received_at)
        ) {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    /**
     * A row recorded but never marked processed for this long is treated
     * as abandoned (lock miss, PHP fatal, exception in a WC hook) and may
     * be claimed again by the next webhook / reconcile pass.
     */
    public const RECLAIM_AFTER_SECONDS = 300;

    /**
     * Try to record a webhook event. Returns true when the caller should
     * process side effects: the row was freshly inserted, or an earlier
     * attempt recorded it but never finished (see RECLAIM_AFTER_SECONDS).
     * Returns false for a genuine duplicate (drop the request).
     *
     * Before 0.3.20 an unfinished row blocked the event forever — every
     * later callback and reconcile saw "duplicate" — so a payment whose
     * first apply lost the per-order lock could leave the order unpaid.
     * A missing table (plugin updated, activation hook never re-ran) also
     * made every event look like a duplicate; the schema is now created
     * on demand.
     */
    public static function record(
        string $gateway,
        string $eventId,
        ?int $orderId,
        string $status,
        string $payloadHash
    ): bool {
        global $wpdb;

        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            self::installSchema();
        }

        $table = self::tableName();
        $now = current_time('mysql', true);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only
        $insert = $wpdb->prepare(
            "INSERT IGNORE INTO {$table} (gateway, event_id, order_id, status, payload_hash, received_at) VALUES (%s, %s, %d, %s, %s, %s)",
            $gateway,
            $eventId,
            $orderId ?? 0,
            $status,
            $payloadHash,
            $now
        );

        $result = $wpdb->query($insert);
        if ($result === false && stripos((string) $wpdb->last_error, "doesn't exist") !== false) {
            // Table dropped or never created despite the version flag.
            self::installSchema();
            $result = $wpdb->query($insert);
        }
        if ($result === false && function_exists('wc_get_logger')) {
            // Deadlock / lost connection: the caller treats this as "not
            // new" and the next webhook or reconcile pass tries again.
            wc_get_logger()->error('codeon_payment_events insert failed', [
                'source'   => 'codeon-payments',
                'gateway'  => $gateway,
                'event_id' => $eventId,
                'error'    => (string) $wpdb->last_error,
            ]);
        }
        if (1 === (int) $result) {
            return true;
        }

        // Claim an abandoned row. The UPDATE is atomic, so only one
        // concurrent caller gets affected-rows = 1.
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::RECLAIM_AFTER_SECONDS);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only
        $claimed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET received_at = %s, payload_hash = %s WHERE gateway = %s AND event_id = %s AND processed_at IS NULL AND received_at < %s",
                $now,
                $payloadHash,
                $gateway,
                $eventId,
                $cutoff
            )
        );
        return 1 === (int) $claimed;
    }

    /**
     * Mark an event as fully processed. Useful for observability
     * (rows where `processed_at IS NULL` represent crashes between
     * record() and side-effect application; record() lets the next
     * webhook / reconcile pass claim them after RECLAIM_AFTER_SECONDS).
     * Call it for every event you deliberately consume, including ones
     * you decide to ignore, or they will be offered again.
     */
    public static function markProcessed(string $gateway, string $eventId): void
    {
        global $wpdb;
        $wpdb->update(
            self::tableName(),
            ['processed_at' => current_time('mysql', true)],
            [
                'gateway'  => $gateway,
                'event_id' => $eventId,
            ],
            ['%s'],
            ['%s', '%s']
        );
    }

    public static function exists(string $gateway, string $eventId): bool
    {
        global $wpdb;
        $table = self::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE gateway = %s AND event_id = %s LIMIT 1",
                $gateway,
                $eventId
            )
        );
        return $value !== null;
    }

    /** Fully-qualified table name — `{prefix}codeon_payment_events`. */
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }
}
