<?php

declare(strict_types=1);

namespace CodeOn\ShippingRules\WooCommerce;

use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;

final class Checkout
{
    public function register(): void
    {
        add_action('woocommerce_after_shipping_rate', [$this, 'renderClassicRate'], 10, 2);
        add_action('woocommerce_checkout_create_order_shipping_item', [$this, 'keepAttribution']);
        add_action('init', [$this, 'registerBlock']);
        add_shortcode('codeon_shipping_progress', [$this, 'progressBlock']);
        if (function_exists('woocommerce_store_api_register_endpoint_data')) {
            $this->registerStoreApi();
        } else {
            add_action('woocommerce_blocks_loaded', [$this, 'registerStoreApi']);
        }
    }

    public function registerStoreApi(): void
    {
        woocommerce_store_api_register_endpoint_data([
            'endpoint' => CartSchema::IDENTIFIER,
            'namespace' => 'codeon-shipping-rules',
            'data_callback' => fn (): array => ['notices' => $this->notices()],
            'schema_callback' => [$this, 'noticeSchema'],
            'schema_type' => ARRAY_A,
        ]);
    }

    public function noticeSchema(): array
    {
        return [
            'notices' => [
                'description' => __('Free shipping progress for eligible rates.', 'codeon-shipping-rules'),
                'type' => 'array',
                'readonly' => true,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'rate' => ['type' => 'string'],
                        'package' => ['type' => 'integer'],
                        'label' => ['type' => 'string'],
                        'message' => ['type' => 'string'],
                        'percent' => ['type' => 'integer'],
                    ],
                ],
            ],
        ];
    }

    public function registerBlock(): void
    {
        $base = plugin_dir_url(CODEON_SHIPPING_RULES_FILE);
        $version = CODEON_SHIPPING_RULES_VERSION;
        wp_register_script('codeon-shipping-progress-editor', $base . 'assets/blocks/editor.js', ['wp-blocks', 'wp-block-editor', 'wp-element', 'wp-i18n'], $version, true);
        wp_register_script('codeon-shipping-progress', $base . 'assets/blocks/view.js', ['wp-data', 'wc-blocks-data-store'], $version, true);
        wp_register_style('codeon-shipping-progress', $base . 'assets/blocks/progress.css', [], $version);
        wp_set_script_translations('codeon-shipping-progress-editor', 'codeon-shipping-rules', dirname(CODEON_SHIPPING_RULES_FILE) . '/languages');
        register_block_type(dirname(CODEON_SHIPPING_RULES_FILE) . '/assets/blocks/block.json', [
            'render_callback' => [$this, 'progressBlock'],
        ]);
    }

    public function progressBlock(): string
    {
        wp_enqueue_style('codeon-shipping-progress');
        wp_enqueue_script('codeon-shipping-progress');

        $html = '<div class="codeon-shipping-progress" data-codeon-shipping-progress aria-live="polite">';
        foreach ($this->notices() as $notice) {
            $html .= $this->progressMarkup($notice, true);
        }
        return $html . '</div>';
    }

    public function renderClassicRate(\WC_Shipping_Rate $rate, $index): void
    {
        $meta = $rate->get_meta_data();
        if (!isset($meta['_codeon_sr_revision'])) {
            return;
        }
        $description = (string) ($meta['_codeon_sr_description'] ?? '');
        $logo = (int) ($meta['_codeon_sr_logo'] ?? 0);
        $notice = $this->noticeForRate($rate, (int) $index);

        if ($description !== '' || $logo || $notice) {
            wp_enqueue_style('codeon-shipping-progress');
        }
        if ($description !== '') {
            echo '<small class="codeon-shipping-description">' . esc_html($description) . '</small>';
        }
        if ($logo) {
            echo wp_get_attachment_image($logo, 'thumbnail', false, ['class' => 'codeon-shipping-logo', 'alt' => '']);
        }
        if ($notice) {
            echo $this->progressMarkup($notice, false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is escaped at construction.
        }
    }

    public function keepAttribution(\WC_Order_Item_Shipping $item): void
    {
        foreach (['_codeon_sr_default', '_codeon_sr_description', '_codeon_sr_logo', '_codeon_sr_progress', '_codeon_sr_free'] as $key) {
            $item->delete_meta_data($key);
        }
    }

    private function notices(): array
    {
        if (!WC()->cart || !WC()->cart->needs_shipping()) {
            return [];
        }

        $notices = [];
        foreach (WC()->shipping()->get_packages() as $index => $package) {
            foreach ($package['rates'] ?? [] as $rate) {
                if ($rate instanceof \WC_Shipping_Rate && ($notice = $this->noticeForRate($rate, (int) $index))) {
                    $notices[] = $notice;
                }
            }
        }
        return $notices;
    }

    private function noticeForRate(\WC_Shipping_Rate $rate, int $index): ?array
    {
        $progress = $rate->get_meta_data()['_codeon_sr_progress'] ?? [];
        if (empty($progress['metric']) || !isset($progress['remaining'], $progress['percent'])) {
            return null;
        }

        $remaining = max(0, (float) $progress['remaining']);
        if ($remaining === 0.0 && (float) $rate->get_cost() !== 0.0) {
            return null;
        }
        if ($remaining === 0.0) {
            $message = __('Free shipping is available for this service.', 'codeon-shipping-rules');
        } elseif ($progress['metric'] === 'quantity') {
            $count = (int) ceil($remaining);
            /* translators: %s: remaining item quantity. */
            $message = sprintf(_n('Add %s more item for free shipping.', 'Add %s more items for free shipping.', $count, 'codeon-shipping-rules'), number_format_i18n($count));
        } else {
            /* translators: %s: remaining merchandise amount. */
            $message = sprintf(__('Add %s more for free shipping.', 'codeon-shipping-rules'), html_entity_decode(wp_strip_all_tags(wc_price($remaining)), ENT_QUOTES, 'UTF-8'));
        }

        return [
            'rate' => $rate->get_id(),
            'label' => wp_strip_all_tags($rate->get_label()),
            'package' => $index,
            'message' => $message,
            'percent' => max(0, min(100, (int) $progress['percent'])),
        ];
    }

    private function progressMarkup(array $notice, bool $showLabel): string
    {
        return sprintf(
            '<div class="codeon-shipping-progress-item">%s<p>%s</p><progress max="100" value="%d" aria-label="%s"></progress></div>',
            $showLabel ? '<strong>' . esc_html($notice['label']) . '</strong>' : '',
            esc_html($notice['message']),
            $notice['percent'],
            esc_attr($notice['message'])
        );
    }
}
