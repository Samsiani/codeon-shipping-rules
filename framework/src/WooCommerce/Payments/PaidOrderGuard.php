<?php

declare(strict_types=1);

namespace CodeOn\Framework\WooCommerce\Payments;

use CodeOn\Framework\Logging\Logger;

/**
 * Stops an order that was PAID through one of our gateways from being
 * switched to Failed or Cancelled by an automated process — whichever
 * plugin that process belongs to.
 *
 * Real case (intelekti.ge #22366, 2026-09-25): the customer abandoned a
 * Flitt attempt and paid with BOG card. Ten hours later Flitt closed the
 * abandoned attempt as "declined" and a third-party Flitt plugin set the
 * paid order to Failed — restocking it and emailing the customer "order
 * failed". Nothing in our BOG plugin ran; this guard is what can stop it.
 *
 * The change is refused only when ALL of these hold:
 *   - the order's saved payment method is a protected gateway;
 *   - its saved status (read from the DB) is a paid status;
 *   - the pending change moves it to failed or cancelled;
 *   - it is not a person's change: WooCommerce marks admin edits, bulk
 *     edits and the order-list quick actions as "manual"; REST and
 *     WP-CLI requests made by a user who can edit orders also pass.
 * Refunds move orders to "refunded", never failed/cancelled, so they are
 * untouched.
 *
 * The veto runs in `woocommerce_before_order_object_save` — before the
 * row is written and before WooCommerce fires the status hooks. It takes
 * the status change (and any payment-identity change bundled with it)
 * out of the pending save and cancels the pending status transition, so
 * no email, restock or downstream sync happens; the rest of that save
 * goes through and the calling plugin carries on normally. One order
 * note (at most hourly per target status) says who tried.
 *
 * Once a person with order-editing rights changes a status manually in
 * a request, the rest of that request counts as manual too — e.g. a
 * marketplace plugin cascading an admin's cancel to sub-orders.
 *
 * Disable per site with:
 *   add_filter('codeon_payments_paid_order_guard', '__return_false');
 */
final class PaidOrderGuard
{
    /** @var array<string,string> gateway id => log source (plugin slug) */
    private static array $gateways = [];

    private static bool $hooked = false;

    /** A person made a manual status change earlier in this request. */
    private static bool $humanRequest = false;

    /** Changes that travel with a downgrade and must not land on a paid order. */
    private const PAYMENT_IDENTITY = ['status', 'payment_method', 'payment_method_title', 'transaction_id'];

    /**
     * @param list<string> $gatewayIds WC gateway ids this plugin owns.
     * @param string       $logSource  Plugin slug used as the WC log source.
     */
    public static function protect(array $gatewayIds, string $logSource): void
    {
        foreach ($gatewayIds as $id) {
            self::$gateways[$id] = $logSource;
        }
        if (!self::$hooked) {
            self::$hooked = true;
            add_action('woocommerce_before_order_object_save', [self::class, 'beforeSave'], 1, 1);
            add_action('woocommerce_order_edit_status', static function (): void {
                if (function_exists('current_user_can') && current_user_can('edit_shop_orders')) {
                    self::$humanRequest = true;
                }
            });
        }
    }

    /** @param mixed $order */
    public static function beforeSave($order): void
    {
        if (!$order instanceof \WC_Order || $order->get_id() <= 0) {
            return;
        }
        $changes = $order->get_changes();
        $to = isset($changes['status']) ? (string) $changes['status'] : '';
        if ($to !== 'failed' && $to !== 'cancelled') {
            return;
        }

        $transitionProp = self::property('status_transition', \WC_Order::class);
        $changesProp = self::property('changes', \WC_Data::class);
        if ($transitionProp === null || $changesProp === null) {
            return; // WooCommerce internals changed — fail open.
        }
        $pending = $transitionProp->getValue($order);
        if ((is_array($pending) && !empty($pending['manual'])) || self::$humanRequest || self::isAuthorisedApiOrCli()) {
            return;
        }

        $saved = PaidOrders::persisted((int) $order->get_id());
        if ($saved['status'] === null || $saved['payment_method'] === null) {
            $data = $order->get_data(); // DB read failed — fall back to the loaded values.
            $saved['status'] ??= (string) ($data['status'] ?? '');
            $saved['payment_method'] ??= (string) ($data['payment_method'] ?? '');
        }
        $method = (string) $saved['payment_method'];
        $from = (string) $saved['status'];
        if (!isset(self::$gateways[$method]) || !in_array($from, wc_get_is_paid_statuses(), true)) {
            return;
        }
        if (!apply_filters('codeon_payments_paid_order_guard', true, $order, $from, $to)) {
            return;
        }

        // Take the downgrade out of this save and cancel the transition
        // WooCommerce would fire after it. Everything else still saves.
        $pendingChanges = $changesProp->getValue($order);
        foreach (self::PAYMENT_IDENTITY as $key) {
            unset($pendingChanges[$key]);
        }
        $changesProp->setValue($order, $pendingChanges);
        $transitionProp->setValue($order, false);

        $source = self::callingPlugin();
        $theirNote = is_array($pending) && is_string($pending['note'] ?? null) ? wp_strip_all_tags($pending['note']) : '';

        (new Logger(self::$gateways[$method]))->warning('paid-order guard blocked a status change', [
            'order_id' => $order->get_id(),
            'from'     => $from,
            'to'       => $to,
            'source'   => $source,
            'note'     => mb_substr($theirNote, 0, 300),
        ]);

        // One note per target status per hour — a bank retrying its
        // callback shouldn't bury the order in identical notes.
        $stampKey = '_codeon_paid_guard_noted_' . $to;
        if (time() - (int) $order->get_meta($stampKey) < HOUR_IN_SECONDS) {
            return;
        }
        $order->update_meta_data($stampKey, (string) time());
        $order->add_order_note(sprintf(
            /* translators: 1: target status, 2: payment method title, 3: transaction id, 4: plugin that asked, 5: that plugin's note */
            __('Blocked an automatic change of this paid order to "%1$s": it was paid with %2$s (transaction %3$s). The request came from %4$s%5$s. The order was not changed. If it really should be %1$s, change the status manually.', 'codeon-framework'),
            wc_get_order_status_name($to),
            $order->get_payment_method_title() !== '' ? $order->get_payment_method_title() : $method,
            $order->get_transaction_id() !== '' ? $order->get_transaction_id() : '—',
            $source !== '' ? sprintf('"%s"', $source) : __('an automated process', 'codeon-framework'),
            $theirNote !== '' ? sprintf(' ("%s")', mb_substr($theirNote, 0, 200)) : ''
        ));
    }

    private static function property(string $name, string $class): ?\ReflectionProperty
    {
        static $cache = [];
        if (!array_key_exists($name, $cache)) {
            try {
                $prop = new \ReflectionProperty($class, $name);
                if (PHP_VERSION_ID < 80100) {
                    $prop->setAccessible(true);
                }
                $cache[$name] = $prop;
            } catch (\ReflectionException $e) {
                $cache[$name] = null;
            }
        }
        return $cache[$name];
    }

    /** REST (e.g. the WooCommerce mobile app) or WP-CLI, run by someone allowed to edit orders. */
    private static function isAuthorisedApiOrCli(): bool
    {
        $viaApi = (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI);
        return $viaApi && function_exists('current_user_can') && current_user_can('edit_shop_orders');
    }

    /**
     * Directory name of the first plugin on the call stack that isn't
     * WooCommerce, a bundled library or one of ours — '' if none.
     */
    private static function callingPlugin(): string
    {
        $pluginsDir = wp_normalize_path(WP_PLUGIN_DIR) . '/';
        $own = array_unique(array_values(self::$gateways));
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $file = isset($frame['file']) ? wp_normalize_path((string) $frame['file']) : '';
            if ($file === '' || !str_starts_with($file, $pluginsDir) || str_contains($file, '/vendor/')) {
                continue;
            }
            $slug = strtok(substr($file, strlen($pluginsDir)), '/');
            if ($slug === false || $slug === 'woocommerce' || in_array($slug, $own, true)) {
                continue;
            }
            return $slug;
        }
        return '';
    }
}
