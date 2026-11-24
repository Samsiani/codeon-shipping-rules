<?php

declare(strict_types=1);

namespace CodeOn\ShippingRules;

use CodeOn\Framework\Plugin\Bootstrap;
use CodeOn\Framework\Plugin\Manifest;
use CodeOn\ShippingRules\Admin\Api;
use CodeOn\ShippingRules\Admin\Dashboard;
use CodeOn\ShippingRules\Storage\Revisions;
use CodeOn\ShippingRules\WooCommerce\Checkout;
use CodeOn\ShippingRules\WooCommerce\Rates;
use CodeOn\ShippingRules\WooCommerce\ShippingMethod;

final class Plugin
{
    public static function register(): void
    {
        add_action('before_woocommerce_init', static function (): void {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', CODEON_SHIPPING_RULES_FILE, true);
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', CODEON_SHIPPING_RULES_FILE, true);
            }
        });

        add_action('plugins_loaded', static function (): void {
            if (!class_exists('WooCommerce')) {
                return;
            }

            $revisions = new Revisions();
            add_action('codeon_sr_prune_revisions', [$revisions, 'prune'], 10, 2);
            add_filter('woocommerce_shipping_methods', [ShippingMethod::class, 'register']);
            add_action('woocommerce_shipping_zone_method_deleted', [$revisions, 'delete']);
            (new Rates($revisions))->register();
            (new Checkout())->register();
            add_action('rest_api_init', [new Api($revisions), 'register']);

            add_action('init', static function (): void {
                load_plugin_textdomain('codeon-shipping-rules', false, dirname(plugin_basename(CODEON_SHIPPING_RULES_FILE)) . '/languages');
                if (is_admin()) {
                    self::registerDashboard();
                }
            });
        }, 20);
    }

    private static function registerDashboard(): void
    {
        $manifest = (new Manifest('codeon-shipping-rules', __('Shipping Rules', 'codeon-shipping-rules')))
            ->hub(true)
            ->hubLabel(__('Shipping Rules', 'codeon-shipping-rules'))
            ->capability('manage_woocommerce')
            ->version(CODEON_SHIPPING_RULES_VERSION)
            ->icon(plugins_url('assets/icon/icon-128x128.png', CODEON_SHIPPING_RULES_FILE))
            ->support('https://codeon.ge/');

        if (method_exists($manifest, 'hubCatalog')) {
            $manifest->hubCatalog(false);
        }

        // Allow a Shop Manager to reach our submenu on a standalone install.
        add_filter('codeon/hub/toplevel_config', static function (array $defaults, string $group): array {
            if ($group === 'codeon' && !is_callable($defaults['dashboardCallback'] ?? null)) {
                $defaults['capability'] = 'manage_woocommerce';
            }

            return $defaults;
        }, 20, 2);

        Bootstrap::register($manifest, Dashboard::tabs());
        add_action('codeon/admin/enqueue', [Dashboard::class, 'enqueue'], 10, 2);
    }
}
