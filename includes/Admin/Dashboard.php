<?php

declare(strict_types=1);

namespace CodeOn\ShippingRules\Admin;

use CodeOn\Framework\Admin\Tab;
use CodeOn\Framework\Plugin\Manifest;

final class Dashboard
{
    /** @return Tab[] */
    public static function tabs(): array
    {
        return [
            new WorkspaceTab('methods', __('Methods', 'codeon-shipping-rules')),
            new WorkspaceTab('simulator', __('Simulator', 'codeon-shipping-rules')),
            new WorkspaceTab('tools', __('Tools', 'codeon-shipping-rules')),
        ];
    }

    public static function enqueue(string $hook, Manifest $manifest): void
    {
        if ('codeon-shipping-rules' !== $manifest->slug || !current_user_can('manage_woocommerce')) {
            return;
        }

        $handle = 'codeon-shipping-rules-admin';
        $base = plugin_dir_url(CODEON_SHIPPING_RULES_FILE) . 'assets/admin/';
        $version = CODEON_SHIPPING_RULES_VERSION;

        wp_enqueue_style($handle, $base . 'workspace.css', ['codeon-framework-admin'], $version);
        wp_enqueue_script($handle, $base . 'workspace.js', ['wp-i18n'], $version, true);
        wp_set_script_translations(
            $handle,
            'codeon-shipping-rules',
            plugin_dir_path(CODEON_SHIPPING_RULES_FILE) . 'languages'
        );

        $store = wc_get_base_location();
        $config = [
            'apiUrl' => rest_url('codeon-shipping-rules/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'pageUrl' => add_query_arg('page', 'codeon-shipping-rules', admin_url('admin.php')),
            'zonesUrl' => add_query_arg(['page' => 'wc-settings', 'tab' => 'shipping'], admin_url('admin.php')),
            'currency' => get_woocommerce_currency(),
            'weightUnit' => (string) get_option('woocommerce_weight_unit', 'kg'),
            'dimensionUnit' => (string) get_option('woocommerce_dimension_unit', 'cm'),
            'digits' => wc_get_price_decimals(),
            'locale' => str_replace('_', '-', determine_locale()),
            'country' => $store['country'],
            'state' => $store['state'],
        ];

        wp_add_inline_script(
            $handle,
            'window.codeonShippingRules = ' . wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';',
            'before'
        );
    }
}
