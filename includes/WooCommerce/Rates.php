<?php

declare(strict_types=1);

namespace CodeOn\ShippingRules\WooCommerce;

use CodeOn\ShippingRules\Integrations\ShippingTexts;
use CodeOn\ShippingRules\Rules\Evaluator;
use CodeOn\ShippingRules\Storage\Revisions;

final class Rates
{
    public function __construct(private readonly Revisions $revisions)
    {
    }

    public function register(): void
    {
        add_filter('woocommerce_cart_shipping_packages', [$this, 'fingerprint'], 1200);
        add_filter('woocommerce_package_rates', [$this, 'adjustProviderRates'], 40, 2);
        add_filter('woocommerce_shipping_chosen_method', [$this, 'defaultRate'], 10, 3);
    }

    public static function supportsProvider(string $methodId): bool
    {
        return in_array($methodId, (array) apply_filters('codeon_shipping_rules_supported_rate_methods', ['flat_rate', 'quickshipper_delivery']), true);
    }

    public function fingerprint(array $packages): array
    {
        if (!$this->revisions->hasPublishedMethods()) {
            return $packages;
        }

        foreach ($packages as &$package) {
            if (!is_array($package) || empty($package['contents'])) {
                continue;
            }

            $zone = \WC_Shipping_Zones::get_zone_matching_package($package);
            $inputs = [];
            foreach ($zone->get_shipping_methods(true, 'json') as $method) {
                $id = (int) $method->instance_id;
                if ($method->id !== ShippingMethod::ID && !self::supportsProvider($method->id)) {
                    continue;
                }
                $state = $this->revisions->state($id);
                if (!$state['revision']) {
                    continue;
                }
                $config = $this->revisions->live($id);
                $requirements = ($state['requirements'] ?? []) ?: Context::requirements($config);
                $context = (new Context())->fromPackage($package, $config, $requirements, true);
                $inputs[$id] = [
                    $state['revision'],
                    $requirements['role'] ? $context['roles'] : [],
                    $requirements['coupon'] && $context['coupon'],
                    $requirements['time'] || $requirements['day'] ? $this->scheduleSignature($config, $context) : [],
                    ($requirements['catalog'] || $requirements['fields']) ? $context['package'] : [],
                    $requirements['cart'] ? $context['cart'] : [],
                    array_intersect_key($context['destination'], array_flip(['country', 'state', 'city', 'postcode', 'municipality', 'settlement'])),
                    $context['currency'],
                    determine_locale(),
                ];
            }

            if ($inputs) {
                $package['_codeon_sr_context'] = hash('sha256', (string) wp_json_encode($inputs));
            }
        }
        unset($package);

        return $packages;
    }

    private function scheduleSignature(array $config, array $context): array
    {
        $windows = [];

        foreach ($config['rules'] as $rule) {
            foreach ($rule['conditions'] as $condition) {
                if ($condition['type'] === 'weekday') {
                    $windows[] = $context['day'];
                }
                if ($condition['type'] === 'time') {
                    $min = ($condition['min'] ?? '') ?: '00:00';
                    $max = ($condition['max'] ?? '') ?: '23:59';
                    $windows[] = [$context['time'] <=> $min, $context['time'] <=> $max];
                }
            }
        }

        return $windows;
    }

    public function adjustProviderRates(array $rates, array $package): array
    {
        if (!$this->revisions->hasPublishedMethods()) {
            return $rates;
        }
        foreach ($rates as $key => $rate) {
            if (!$rate instanceof \WC_Shipping_Rate || !self::supportsProvider($rate->get_method_id())) {
                continue;
            }

            $instanceId = $this->instanceId($rate, $package);
            if (!$instanceId) {
                continue;
            }
            $state = $this->revisions->state($instanceId);
            if (!$state['revision']) {
                continue;
            }

            $config = $this->revisions->live($instanceId);
            if (!empty($config['bridge_rate_ids']) && !in_array($rate->get_id(), $config['bridge_rate_ids'], true)) {
                continue;
            }

            $meta = $rate->get_meta_data();
            if (isset($meta['_codeon_sr_revision'])) {
                continue;
            }
            $base = (float) $rate->get_cost();
            $rateCurrency = $meta['quickshipper_currency'] ?? '';
            if ($rateCurrency !== '' && strtoupper((string) $rateCurrency) !== get_woocommerce_currency()) {
                continue;
            }

            try {
                $context = (new Context())->fromPackage($package, $config, $state['requirements'] ?? []);
                $context['base_rate'] = (string) $base;
                $quote = (new Evaluator())->quote($config, $context);
            } catch (\Throwable $error) {
                wc_get_logger()->error('Could not apply shipping rules to method ' . $instanceId . '.', ['source' => 'codeon-shipping-rules']);
                continue;
            }
            if (!$quote['available']) {
                unset($rates[$key]);
                continue;
            }

            $cost = (float) $quote['cost'];
            if ($cost !== $base) {
                $rate->set_cost($cost);
                $rate->set_taxes($this->adjustedTaxes($rate, $base, $cost));
            }

            $rate->add_meta_data('_codeon_sr_revision', $state['revision']);
            $rate->add_meta_data('_codeon_sr_base_rate', wc_format_decimal($base));
            $rate->add_meta_data('_codeon_sr_default', $config['default'] ? 'yes' : '');

            if ($quote['free'] && $config['free']['label'] !== '') {
                $rate->set_label(ShippingTexts::get($instanceId, 'free_label', $config['free']['label']) ?: $rate->get_label());
            }
            if ($config['free']['notice'] && !empty($quote['progress'])) {
                $rate->add_meta_data('_codeon_sr_progress', $quote['progress']);
            }
        }

        return $rates;
    }

    private function instanceId(\WC_Shipping_Rate $rate, array $package): int
    {
        // QuickShipper's live rates can use a provider-specific rate ID.
        $id = $rate->get_instance_id();
        if ($id) {
            return (int) $id;
        }

        $zone = \WC_Shipping_Zones::get_zone_matching_package($package);
        $methods = array_filter(
            $zone->get_shipping_methods(true),
            static fn ($method): bool => $method->id === $rate->get_method_id()
        );

        return count($methods) === 1 ? (int) reset($methods)->instance_id : 0;
    }

    private function adjustedTaxes(\WC_Shipping_Rate $rate, float $base, float $cost): array
    {
        $taxes = $rate->get_taxes();
        if ($cost <= 0) {
            return array_fill_keys(array_keys($taxes), 0);
        }

        if ($base > 0 && $taxes) {
            return array_map(static fn ($tax): float => (float) $tax * $cost / $base, $taxes);
        }

        $customer = WC()->customer;
        $taxable = $rate->get_tax_status() === 'taxable' && wc_tax_enabled()
            && $customer instanceof \WC_Customer && !$customer->get_is_vat_exempt();

        return $taxable ? \WC_Tax::calc_shipping_tax($cost, \WC_Tax::get_shipping_tax_rates()) : [];
    }

    public function defaultRate($default, array $rates, $chosen): string
    {
        $default = (string) $default;
        $chosen = (string) $chosen;
        if (isset($rates[$chosen])) {
            return $chosen;
        }
        if (str_starts_with($default, 'free_shipping:')) {
            return $default;
        }
        foreach ($rates as $id => $rate) {
            if ($rate instanceof \WC_Shipping_Rate && ($rate->get_meta_data()['_codeon_sr_default'] ?? '') === 'yes') {
                return $id;
            }
        }

        return $default;
    }
}
