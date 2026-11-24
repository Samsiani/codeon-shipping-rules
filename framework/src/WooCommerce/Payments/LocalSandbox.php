<?php

declare(strict_types=1);

namespace CodeOn\Framework\WooCommerce\Payments;

/**
 * "Local sandbox": codeon.ge stands in for the bank's payment API and
 * pages, so a shop can run a plugin's whole checkout with no merchant
 * account, no money and no licence (pre-sale demos). Real environments
 * (the bank's own sandbox, production) stay licence-gated.
 *
 * Server side: codeon.ge docs/LOCAL_SANDBOX.md. One section per bank
 * there; BoG Card is the first (base path `/api/sandbox/bog`).
 *
 * Shared by every gateway that returns true from
 * {@see \CodeOn\Framework\WooCommerce\AbstractGateway::supportsLocalSandbox()}.
 */
final class LocalSandbox
{
    /** codeon.ge root of every bank's sandbox API. */
    public const BASE_URL = 'https://codeon.ge/api/sandbox';

    /** OAuth client secret: a fixed public value (no licence, no merchant account). */
    public const CLIENT_SECRET = 'local-sandbox';

    /** Order meta that marks an order paid in Local sandbox (no money received). */
    public const ORDER_META = '_codeon_sandbox';

    /**
     * Public half of the key codeon.ge signs sandbox callbacks with
     * (SHA256withRSA, `Callback-Signature` header). Hard-coded like the
     * banks' keys — never configurable. It is not any bank's key, so a
     * sandbox callback never passes a live check and vice versa.
     */
    public const CALLBACK_PUBLIC_KEY_PEM = <<<PEM
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtnQjp6WDOt7jb/xaCfeG
imbth6W5U6UrtKu5OB9P/BlPrmdbLTw3385rwnU26g9F6f9S2abiaYdyy7tRJAXq
/DcxWvGkOUPdywDevqdqEBoiuCnLpxO+cl2iiaTzvuxDMjZuCbY1q43ZpCK7JpLh
R0bTzwcAaX3KRy1NkqeQYSxxi3lJfsJwhC3/dHPP/Z7P6NqgHUxKfcpdVbJF3YhU
/atPBK2JObGISrevIISKbPVukmBZANAX0luylumGKl4O5/6ijRypuYGQYCyH5IEs
mTFvnDoK+7gQxOv6l/ouY1X/vSrx9rPZefWF9r84e1a/PkdVJ5gYLj/Pr+i2dZBk
UQIDAQAB
-----END PUBLIC KEY-----
PEM;

    /**
     * Base URL for one bank's sandbox (`bog` → https://codeon.ge/api/sandbox/bog).
     * A developer may point it at a codeon.ge running on their own machine
     * with the wp-config constant CODEON_LOCAL_SANDBOX_BASE. Only local
     * addresses are accepted (localhost, 127.0.0.1, *.localhost, *.test,
     * *.local), so the constant can't route payments to a real bank behind
     * a proxy. A constant, not a filter: no other plugin can redirect it.
     */
    public static function baseUrl(string $bank): string
    {
        $root = self::BASE_URL;
        if (defined('CODEON_LOCAL_SANDBOX_BASE') && is_string(CODEON_LOCAL_SANDBOX_BASE)
            && self::isLocalDevUrl(CODEON_LOCAL_SANDBOX_BASE)
        ) {
            $root = CODEON_LOCAL_SANDBOX_BASE;
        }
        return rtrim($root, '/') . '/' . rawurlencode($bank);
    }

    private static function isLocalDevUrl(string $url): bool
    {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        return $host === 'localhost' || $host === '127.0.0.1'
            || str_ends_with($host, '.localhost') || str_ends_with($host, '.test') || str_ends_with($host, '.local');
    }

    /**
     * The shop's identity in the sandbox: home_url() host[:port], lower
     * case (`shop.ge`, `localhost:8888`). codeon.ge only sends callbacks
     * and customers back to this host.
     */
    public static function shopHost(): string
    {
        $parts = wp_parse_url(home_url('/'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        return isset($parts['port']) ? $host . ':' . (int) $parts['port'] : $host;
    }

    /**
     * Mark an order as a Local sandbox order: meta, payment title and a
     * note, so nobody mistakes it for money received.
     */
    public static function markOrder(\WC_Order $order, string $paymentTitle, string $note): void
    {
        $order->update_meta_data(self::ORDER_META, '1');
        $order->set_payment_method_title($paymentTitle);
        $order->save();
        $order->add_order_note($note);
    }

    public static function isSandboxOrder(\WC_Order $order): bool
    {
        return (string) $order->get_meta(self::ORDER_META) === '1';
    }

    /**
     * Admin notice on every admin screen while a gateway runs in Local
     * sandbox. Call once at boot; $isOn (and callable $message) are
     * evaluated lazily on `admin_notices` so callers don't trigger __()
     * before `init` (WordPress 6.7+ `_load_textdomain_just_in_time`).
     *
     * @param callable():bool        $isOn
     * @param string|callable():string $message
     */
    public static function adminNotice(callable $isOn, string|callable $message): void
    {
        add_action('admin_notices', static function () use ($isOn, $message): void {
            if (!current_user_can('manage_woocommerce') || !$isOn()) {
                return;
            }
            $text = is_callable($message) ? (string) $message() : $message;
            echo '<div class="notice notice-warning"><p>' . esc_html($text) . '</p></div>';
        });
    }
}
