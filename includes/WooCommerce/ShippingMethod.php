<?php

declare(strict_types=1);

namespace CodeOn\ShippingRules\WooCommerce;

use CodeOn\ShippingRules\Integrations\ShippingTexts;
use CodeOn\ShippingRules\Rules\Evaluator;
use CodeOn\ShippingRules\Storage\Revisions;

final class ShippingMethod extends \WC_Shipping_Method
{
    public const ID = 'codeon_shipping_rules';

    public static function register(array $methods): array
    {
        $methods[self::ID] = self::class;
        return $methods;
    }

    public function __construct($instanceId = 0)
    {
        parent::__construct($instanceId);
        $this->id = self::ID;
        $this->instance_id = absint($instanceId);
        $this->method_title = __('CodeOn Shipping Rules', 'codeon-shipping-rules');
        $this->method_description = __('Create delivery prices and availability rules for this zone.', 'codeon-shipping-rules');
        $this->supports = ['shipping-zones', 'instance-settings'];
        $this->title = $this->method_title;
        $this->tax_status = 'taxable';

        if ($this->instance_id) {
            $revisions = new Revisions();
            if ($revisions->state($this->instance_id)['revision']) {
                $config = $revisions->live($this->instance_id);
                $this->title = $config['title'] ?: $this->method_title;
                $this->tax_status = $config['tax_status'];
            }
        }
    }

    public function admin_options(): void
    {
        $url = add_query_arg(
            ['page' => 'codeon-shipping-rules', 'method' => $this->instance_id],
            admin_url('admin.php')
        );
        printf(
            '<h2>%s</h2><p>%s</p><p><a class="button button-primary" href="%s">%s</a></p>',
            esc_html($this->method_title),
            esc_html__('Edit this method, simulate a quote, and publish your rules in CodeOn Shipping Rules.', 'codeon-shipping-rules'),
            esc_url($url),
            esc_html__('Edit shipping rules', 'codeon-shipping-rules')
        );
    }

    public function calculate_shipping($package = []): void
    {
        if (!$this->instance_id || empty($package['contents'])) {
            return;
        }
        $revisions = new Revisions();
        $state = $revisions->state($this->instance_id);
        if (!$state['revision']) {
            return;
        }

        $config = $revisions->live($this->instance_id);
        try {
            $context = (new Context())->fromPackage($package, $config, $state['requirements'] ?? []);
            $quote = (new Evaluator())->quote($config, $context);
        } catch (\Throwable $error) {
            wc_get_logger()->error('Shipping rules could not be evaluated for method ' . $this->instance_id . '.', ['source' => 'codeon-shipping-rules']);
            return;
        }
        if (!$quote['available']) {
            return;
        }

        $label = $quote['free'] && $config['free']['label'] !== ''
            ? ShippingTexts::get($this->instance_id, 'free_label', $config['free']['label'])
            : ShippingTexts::get($this->instance_id, 'title', $this->title);
        $label = $label ?: $this->method_title;
        $description = ShippingTexts::get($this->instance_id, 'description', $config['description']);
        $deliveryTime = ShippingTexts::get($this->instance_id, 'delivery_time', $config['delivery_time']);
        $cost = $quote['cost'];
        $taxes = false;
        if ((float) $cost > 0 && $this->is_taxable()) {
            $rates = \WC_Tax::get_shipping_tax_rates();
            if ($config['cost_includes_tax']) {
                $taxes = \WC_Tax::calc_tax((float) $cost, $rates, true);
                $cost = wc_format_decimal((float) $cost - array_sum($taxes), wc_get_rounding_precision());
            } else {
                $taxes = \WC_Tax::calc_shipping_tax((float) $cost, $rates);
            }
        }

        $this->add_rate([
            'id' => $this->get_rate_id(),
            'label' => $label,
            'cost' => $cost,
            'taxes' => $taxes,
            'price_decimals' => wc_get_rounding_precision(),
            'meta_data' => [
                '_codeon_sr_revision' => $state['revision'],
                '_codeon_sr_default' => $config['default'] ? 'yes' : '',
                '_codeon_sr_description' => $description,
                '_codeon_sr_logo' => $config['logo_id'],
                '_codeon_sr_progress' => $config['free']['notice'] ? ($quote['progress'] ?? []) : [],
                '_codeon_sr_free' => $quote['free'] ? 'yes' : '',
            ],
        ]);

        $rate = $this->rates[$this->get_rate_id()] ?? null;
        if ($rate instanceof \WC_Shipping_Rate && method_exists($rate, 'set_description')) {
            $rate->set_description($description);
            $rate->set_delivery_time($deliveryTime);
        }
    }
}
