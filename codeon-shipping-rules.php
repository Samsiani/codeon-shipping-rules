<?php
/**
 * Plugin Name: CodeOn Shipping Rules for WooCommerce
 * Description: Configure table-rate shipping, simulate shipping quotes, and safely publish changes.
 * Version: 1.0.1
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 10.0
 * WC tested up to: 11.3
 * Text Domain: codeon-shipping-rules
 * Domain Path: /languages
 * Author: CodeOn
 * Author URI: https://codeon.ge/
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('CODEON_SHIPPING_RULES_FILE', __FILE__);
define('CODEON_SHIPPING_RULES_VERSION', '1.0.1');

if (!is_file(__DIR__ . '/vendor/autoload_packages.php')) {
    add_action('admin_notices', static function (): void {
        if (current_user_can('activate_plugins')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('CodeOn Shipping Rules needs the complete release ZIP.', 'codeon-shipping-rules') . '</p></div>';
        }
    });
    return;
}

require __DIR__ . '/vendor/autoload_packages.php';

CodeOn\ShippingRules\Plugin::register();
