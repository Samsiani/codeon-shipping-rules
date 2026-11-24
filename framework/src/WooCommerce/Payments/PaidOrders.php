<?php

declare(strict_types=1);

namespace CodeOn\Framework\WooCommerce\Payments;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * "Is this order already paid?" — answered from the database, not from
 * the WC_Order object in hand.
 *
 * Webhook, reconcile and polling paths load an order, then spend
 * seconds on bank HTTP calls before acting on it. Meanwhile another
 * request (the bank's callback, a different gateway, an admin) may have
 * paid it. Acting on the stale object is how a late "failed/expired"
 * overwrote paid orders across the suite (arttime #144096 on BOG card
 * v0.3.7, intelekti #22366 via a third-party Flitt plugin). Every
 * negative transition checks here first.
 *
 * Lazy-loaded behind the WC_Order guard like OrderStatusMapper.
 */
if (!class_exists('WC_Order')) {
    return;
}

final class PaidOrders
{
    /**
     * Status (without the `wc-` prefix) and payment method as currently
     * stored, bypassing object caches. Null fields when the row can't be
     * read — callers then fall back to the object.
     *
     * @return array{status: ?string, payment_method: ?string}
     */
    public static function persisted(int $orderId): array
    {
        global $wpdb;

        $row = null;
        if (class_exists(OrderUtil::class) && OrderUtil::custom_orders_table_usage_is_enabled()) {
            $table = OrdersTableDataStore::get_orders_table_name();
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only
            $row = $wpdb->get_row($wpdb->prepare("SELECT status, payment_method FROM {$table} WHERE id = %d", $orderId), ARRAY_A);
        } else {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT p.post_status AS status, pm.meta_value AS payment_method
                     FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_payment_method'
                     WHERE p.ID = %d
                     ORDER BY pm.meta_id ASC
                     LIMIT 1",
                    $orderId
                ),
                ARRAY_A
            );
        }

        if (!is_array($row)) {
            return ['status' => null, 'payment_method' => null];
        }

        $status = (string) ($row['status'] ?? '');
        if (str_starts_with($status, 'wc-')) {
            $status = substr($status, 3);
        }

        return [
            'status'         => $status === '' ? null : $status,
            'payment_method' => isset($row['payment_method']) ? (string) $row['payment_method'] : null,
        ];
    }

    /**
     * Why a failed/cancelled transition must NOT be applied to this
     * order, or null when it may be.
     *
     *  - 'paid'     — paid status in the DB or on the object, or a paid date is set.
     *  - 'switched' — the DB says another payment method owns the order now:
     *                 not $gatewayId when the caller names its gateway, else
     *                 not the method on the (possibly stale) object.
     *
     * @param array{status: ?string, payment_method: ?string}|null $persisted
     *        Result of {@see persisted()} when the caller already has it.
     */
    public static function negativeTransitionBlocker(\WC_Order $order, ?array $persisted = null, ?string $gatewayId = null): ?string
    {
        $db = $persisted ?? self::persisted((int) $order->get_id());
        $status = $db['status'] ?? $order->get_status();

        if (in_array($status, wc_get_is_paid_statuses(), true) || $order->is_paid() || $order->get_date_paid() !== null) {
            return 'paid';
        }
        $owner = $gatewayId ?? $order->get_payment_method();
        if ($db['payment_method'] !== null && $db['payment_method'] !== $owner) {
            return 'switched';
        }
        return null;
    }

    /**
     * Load the order again from the database, dropping WooCommerce's
     * in-request caches first — `wc_get_order()` alone can hand back the
     * object this request loaded before its bank call. Use it inside the
     * per-order lock, right before deciding a transition.
     */
    public static function fresh(int $orderId): ?\WC_Order
    {
        clean_post_cache($orderId);
        try {
            if (class_exists(\Automattic\WooCommerce\Caches\OrderCache::class) && function_exists('wc_get_container')) {
                wc_get_container()->get(\Automattic\WooCommerce\Caches\OrderCache::class)->remove($orderId);
            }
            $store = \WC_Data_Store::load('order');
            if ($store->has_callable('clear_cached_data')) {
                $store->clear_cached_data([$orderId]); // HPOS datastore groups (orders_data / orders_meta)
            }
        } catch (\Throwable $e) {
            // Cache layer unavailable — the DB read below still happens on a miss.
        }
        $order = wc_get_order($orderId);
        return $order instanceof \WC_Order ? $order : null;
    }

    /** True when the DB (not the object) already shows a paid status. */
    public static function isPersistedPaid(\WC_Order $order): bool
    {
        $status = self::persisted((int) $order->get_id())['status'];
        return $status !== null && in_array($status, wc_get_is_paid_statuses(), true);
    }
}
