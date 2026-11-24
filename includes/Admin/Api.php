<?php

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are returned as JSON data or rendered with textContent.

namespace CodeOn\ShippingRules\Admin;

use CodeOn\ShippingRules\Import\Importer;
use CodeOn\ShippingRules\Import\TierCsv;
use CodeOn\ShippingRules\Rules\Diagnostics;
use CodeOn\ShippingRules\Rules\Evaluator;
use CodeOn\ShippingRules\Rules\Schema;
use CodeOn\ShippingRules\Rules\ValidationMessages;
use CodeOn\ShippingRules\Storage\Conflict;
use CodeOn\ShippingRules\Storage\Revisions;
use CodeOn\ShippingRules\WooCommerce\Context;
use CodeOn\ShippingRules\WooCommerce\Rates;
use CodeOn\ShippingRules\WooCommerce\ShippingMethod;
use WP_Error;
use WP_REST_Request;

final class Api
{
    public const REST_NAMESPACE = 'codeon-shipping-rules/v1';

    public function __construct(private readonly Revisions $revisions)
    {
    }

    public function register(): void
    {
        $routes = [
            ['/methods', 'GET', 'listMethods'],
            ['/methods', 'POST', 'createMethod'],
            ['/methods/(?P<id>[1-9][0-9]*)', 'GET', 'detail'],
            ['/methods/(?P<id>[1-9][0-9]*)/draft', 'PUT', 'draft'],
            ['/methods/(?P<id>[1-9][0-9]*)/publish', 'POST', 'publish'],
            ['/methods/(?P<id>[1-9][0-9]*)/rollback', 'POST', 'rollback'],
            ['/methods/(?P<id>[1-9][0-9]*)/state', 'PUT', 'state'],
            ['/methods/(?P<id>[1-9][0-9]*)/duplicate', 'POST', 'duplicate'],
            ['/export/(?P<id>[1-9][0-9]*)', 'GET', 'export'],
            ['/simulate', 'POST', 'simulate'],
            ['/import', 'POST', 'import'],
            ['/products', 'GET', 'products'],
        ];
        foreach ($routes as [$route, $method, $handler]) {
            register_rest_route(self::REST_NAMESPACE, $route, [
                'methods' => $method,
                'args' => $this->routeArgs($handler),
                'permission_callback' => static fn (): bool => current_user_can('manage_woocommerce'),
                'callback' => function (WP_REST_Request $request) use ($handler): array|WP_Error {
                    if (strlen($request->get_body() ?? '') > 1_100_000) {
                        return new WP_Error('codeon_sr_size', __('The request is too large.', 'codeon-shipping-rules'), ['status' => 413]);
                    }
                    try {
                        return $this->$handler($request);
                    } catch (Conflict $error) {
                        return new WP_Error('codeon_sr_conflict', $error->getMessage(), ['status' => 409]);
                    } catch (\InvalidArgumentException $error) {
                        return new WP_Error('codeon_sr_invalid', ValidationMessages::localize($error->getMessage()), ['status' => 400]);
                    } catch (\OutOfBoundsException $error) {
                        return new WP_Error('codeon_sr_missing', $error->getMessage(), ['status' => 404]);
                    } catch (\Throwable $error) {
                        wc_get_logger()->error('Admin request failed: ' . get_class($error), ['source' => 'codeon-shipping-rules']);
                        return new WP_Error('codeon_sr_failed', __('The request could not be completed. Try again.', 'codeon-shipping-rules'), ['status' => 500]);
                    }
                },
            ]);
        }
    }

    private function routeArgs(string $handler): array
    {
        $identity = ['type' => 'integer', 'minimum' => 1];
        $zone = ['type' => 'integer', 'minimum' => 0];
        $generation = ['type' => 'integer', 'minimum' => 0, 'required' => true];
        $config = ['type' => 'object'];
        $revisionArgs = ['revision' => $generation, 'draft_version' => $generation];

        return match ($handler) {
            'createMethod' => [
                'zone_id' => $zone + ['required' => true],
                'title' => ['type' => 'string', 'maxLength' => 255, 'required' => true],
            ],
            'draft' => $revisionArgs + ['config' => $config + ['required' => true]],
            'publish' => $revisionArgs,
            'rollback' => [
                'revision' => $generation,
                'target' => $identity + ['required' => true],
            ],
            'state' => ['enabled' => ['type' => 'boolean', 'required' => true]],
            'duplicate' => ['zone_id' => $zone],
            'simulate' => [
                'method_id' => $identity + ['required' => true],
                'config' => $config,
                'scenario' => ['type' => 'object'],
            ],
            'import' => [
                'format' => ['type' => 'string', 'enum' => ['json', 'csv', 'flexible'], 'required' => true],
                'content' => ['type' => 'string', 'required' => true],
                'apply' => ['type' => 'boolean'],
                'zone_id' => $zone,
                'method_id' => $identity,
                'revision' => $zone,
                'draft_version' => $zone,
            ],
            'export' => ['format' => ['type' => 'string', 'enum' => ['json', 'csv']]],
            'products' => ['search' => ['type' => 'string']],
            default => [],
        };
    }

    private function listMethods(): array
    {
        $zoneIds = array_values(array_map('intval', array_column(\WC_Shipping_Zones::get_zones(), 'zone_id')));
        $zones = [];
        $methods = [];
        foreach ([...$zoneIds, 0] as $zoneId) {
            $zone = \WC_Shipping_Zones::get_zone($zoneId);
            if (!$zone) {
                continue;
            }
            $zones[] = ['id' => $zoneId, 'name' => $zone->get_zone_name()];
            foreach ($zone->get_shipping_methods(false) as $method) {
                if (!$this->canEdit($method)) {
                    continue;
                }
                $methods[] = $this->methodSummary($method, $zone);
            }
        }

        return ['methods' => $methods, 'zones' => $zones, 'catalog' => Schema::catalog(), 'defaults' => Schema::defaults()];
    }

    private function detail(WP_REST_Request $request): array
    {
        [$method, $zone] = $this->findMethod((int) $request['id']);
        $summary = $this->methodSummary($method, $zone);
        $detail = $this->revisions->details($summary['id']);
        if (!$detail['revision']) {
            $detail['config']['title'] = $summary['title'];
        }
        if (!$detail['draft_version']) {
            $detail['draft']['title'] = $summary['title'];
        }
        return $summary + $detail;
    }

    private function createMethod(WP_REST_Request $request): array
    {
        $zone = $this->zone((int) $request['zone_id']);
        $value = $request->get_param('title');
        $title = is_string($value) ? sanitize_text_field($value) : '';
        if ($title === '') {
            throw new \InvalidArgumentException(__('Enter a delivery method name.', 'codeon-shipping-rules'));
        }

        $id = $this->insertMethod($zone, ['title' => $title] + Schema::defaults());
        return ['id' => $id, 'created' => true];
    }

    private function draft(WP_REST_Request $request): array
    {
        [$method] = $this->findMethod((int) $request['id']);
        $config = $this->config($request);
        return $this->revisions->saveDraft((int) $method->instance_id, $config, (int) $request['revision'], (int) $request['draft_version']);
    }

    private function publish(WP_REST_Request $request): array
    {
        [$method] = $this->findMethod((int) $request['id']);
        return $this->revisions->publish((int) $method->instance_id, (int) $request['revision'], (int) $request['draft_version']);
    }

    private function rollback(WP_REST_Request $request): array
    {
        [$method] = $this->findMethod((int) $request['id']);
        return $this->revisions->rollback((int) $method->instance_id, (int) $request['revision'], (int) $request['target']);
    }

    private function state(WP_REST_Request $request): array
    {
        [$method, $zone] = $this->findMethod((int) $request['id']);
        if ($method->id !== ShippingMethod::ID) {
            throw new \InvalidArgumentException(__('Change the live service status in WooCommerce Shipping Zones.', 'codeon-shipping-rules'));
        }
        if (!is_bool($request['enabled'])) {
            throw new \InvalidArgumentException(__('Choose whether to enable this method.', 'codeon-shipping-rules'));
        }
        if ($request['enabled'] && !$this->revisions->state((int) $method->instance_id)['revision']) {
            throw new \InvalidArgumentException(__('Publish rules before enabling this method.', 'codeon-shipping-rules'));
        }

        $this->setEnabled((int) $method->instance_id, (int) $zone->get_id(), $request['enabled']);
        return ['enabled' => $request['enabled']];
    }

    private function duplicate(WP_REST_Request $request): array
    {
        [$method, $zone] = $this->findMethod((int) $request['id']);
        if ($method->id !== ShippingMethod::ID) {
            throw new \InvalidArgumentException(__('Use an export to copy adjustments to another service.', 'codeon-shipping-rules'));
        }
        if ($request->has_param('zone_id')) {
            $zone = $this->zone((int) $request['zone_id']);
        }
        $details = $this->revisions->details((int) $method->instance_id);
        $config = $details['draft'];
        /* translators: %s: name of the method being duplicated. */
        $config['title'] = sprintf(__('%s (copy)', 'codeon-shipping-rules'), $config['title'] ?: $method->get_title());

        return ['id' => $this->insertMethod($zone, $config), 'created' => true];
    }

    private function simulate(WP_REST_Request $request): array
    {
        [$method] = $this->findMethod((int) $request['method_id']);
        $details = $this->revisions->details((int) $method->instance_id);
        $input = $request->get_param('config');
        $draft = is_array($input) ? Schema::normalize($input) : $details['draft'];
        $scenario = $request->get_param('scenario');
        $scenario = is_array($scenario) ? $scenario : [];
        $isBridge = $method->id !== ShippingMethod::ID;
        if ($isBridge && $method->id === 'flat_rate' && !isset($scenario['base_rate'])) {
            $plainBase = $method->get_option('cost', '0');
            $scenario['base_rate'] = is_numeric($plainBase) ? $plainBase : 0;
        }
        $evaluator = new Evaluator();
        $live = $evaluator->quote($details['config'], $this->scenario($scenario, $details['config'], $isBridge), true);
        $preview = $evaluator->quote($draft, $this->scenario($scenario, $draft, $isBridge), true);

        return ['live' => $live, 'draft' => $preview, 'issues' => $this->issues($draft, $preview)];
    }

    private function scenario(array $scenario, array $config, bool $isBridge): array
    {
        $products = $scenario['products'] ?? [];
        $products = is_array($products) ? array_slice($products, 0, 100) : [];
        $requirements = Context::requirements($config);
        $mapper = new Context();
        $lines = [];
        $packageLines = [];
        foreach ($products as $item) {
            if (!is_array($item)) {
                continue;
            }
            $product = wc_get_product(absint($item['id'] ?? 0));
            if (!$product instanceof \WC_Product || $product->get_status() === 'trash') {
                throw new \InvalidArgumentException(__('One of the selected products is unavailable.', 'codeon-shipping-rules'));
            }

            $quantity = $this->numeric($item['quantity'] ?? 1, 1, 100000);
            $net = wc_get_price_excluding_tax($product, ['qty' => $quantity]);
            $gross = wc_get_price_including_tax($product, ['qty' => $quantity]);
            $line = $mapper->productFacts($product, $requirements) + [
                'quantity' => $quantity,
                'net' => $net,
                'gross' => $gross,
                'net_before_discount' => $net,
                'gross_before_discount' => $gross,
            ];
            $lines[] = $line;
            if ($product->needs_shipping()) {
                $packageLines[] = $line;
            }
        }

        if ($lines && !empty($scenario['discount'])) {
            $discount = $this->numeric($scenario['discount']);
            $total = array_sum(array_column($lines, 'net'));
            foreach ([&$lines, &$packageLines] as &$scope) {
                foreach ($scope as &$line) {
                    $share = $total > 0 ? min($line['net'], $discount * $line['net'] / $total) : 0;
                    $taxRatio = $line['net'] > 0 ? $line['gross'] / $line['net'] : 1;
                    $line['net'] -= $share;
                    $line['gross'] = max(0, $line['gross'] - $share * $taxRatio);
                }
                unset($line);
            }
            unset($scope);
        }

        if (!$products) {
            $quantity = $this->numeric($scenario['quantity'] ?? 1, 1, 100000);
            $net = $this->numeric($scenario['amount'] ?? 0);
            $gross = $this->numeric($scenario['gross'] ?? $net);
            $discount = $this->numeric($scenario['discount'] ?? 0);
            $line = [
                'product_id' => 0,
                'quantity' => $quantity,
                'net' => max(0, $net - $discount),
                'gross' => max(0, $gross - $discount),
                'net_before_discount' => $net,
                'gross_before_discount' => $gross,
                'weight' => $this->numeric($scenario['weight'] ?? 0) / $quantity,
                'length' => $this->numeric($scenario['length'] ?? 0),
                'width' => $this->numeric($scenario['width'] ?? 0),
                'height' => $this->numeric($scenario['height'] ?? 0),
            ];
            $lines[] = $line;
            $packageLines[] = $line;
        }

        $time = sanitize_text_field($scenario['time'] ?? current_datetime()->format('H:i'));
        if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time)) {
            throw new \InvalidArgumentException(__('Use a valid local time in hours and minutes.', 'codeon-shipping-rules'));
        }
        $destination = [];
        foreach (['country', 'state', 'city', 'postcode', 'settlement', 'municipality'] as $field) {
            $destination[$field] = sanitize_text_field($scenario[$field] ?? '');
        }
        $role = sanitize_key($scenario['role'] ?? 'guest');
        $currency = strtoupper(sanitize_key($scenario['currency'] ?? get_woocommerce_currency()));
        if (!isset(get_woocommerce_currencies()[$currency])) {
            throw new \InvalidArgumentException(__('Choose a valid WooCommerce currency.', 'codeon-shipping-rules'));
        }
        return [
            'package' => $packageLines,
            'cart' => $lines,
            'logged_in' => $role !== 'guest',
            'roles' => [$role],
            'day' => (int) $this->numeric($scenario['day'] ?? current_datetime()->format('N'), 1, 7),
            'time' => $time,
            'destination' => $destination,
            'coupon' => ($scenario['coupon'] ?? false) === true || ($scenario['coupon'] ?? '') === 'yes',
            'currency' => $currency,
            'decimals' => wc_get_price_decimals(),
            'base_rate' => $isBridge ? (string) $this->numeric($scenario['base_rate'] ?? 0) : null,
        ];
    }

    private function issues(array $config, array $quote): array
    {
        $issues = [];
        $alwaysStops = false;
        foreach ($config['rules'] as $rule) {
            if ($alwaysStops) {
                $issues[] = __('A rule follows an unconditional stop or hide and cannot be reached.', 'codeon-shipping-rules');
                break;
            }
            $always = $rule['conditions'] === [] || (count($rule['conditions']) === 1 && $rule['conditions'][0]['type'] === 'always');
            $alwaysStops = $always && in_array($rule['action'], ['stop', 'hide'], true);
        }
        foreach (Diagnostics::analyze($config, wc_get_price_decimals()) as $finding) {
            $issues[] = match ($finding['code']) {
                'bands_overlap' => __('Two pricing bands overlap. Review the combined price at their shared boundary.', 'codeon-shipping-rules'),
                'bands_gap' => __('Two pricing bands leave a gap where this method can disappear.', 'codeon-shipping-rules'),
                'empty_band' => __('One pricing band has no matching values at the store’s precision.', 'codeon-shipping-rules'),
                'free_threshold_gap' => __('The free-shipping threshold falls outside the eligible rate bands.', 'codeon-shipping-rules'),
                default => __('Review the rate bands and their boundaries.', 'codeon-shipping-rules'),
            };
        }
        if (!$quote['available']) {
            $issues[] = __('This example has no rate for this method. Check the rules or add an intentional fallback.', 'codeon-shipping-rules');
        }
        return $issues;
    }

    private function import(WP_REST_Request $request): array
    {
        $format = sanitize_key((string) $request['format']);
        $content = $request['content'];
        if (!is_string($content) || $content === '') {
            throw new \InvalidArgumentException(__('Upload or paste a rules file.', 'codeon-shipping-rules'));
        }
        $parsed = (new Importer())->parse($format, $content);
        $created = [];
        if ($request['apply'] === true) {
            if (!$parsed['methods'] || $parsed['issues'] || array_filter(array_column($parsed['methods'], 'issues'))) {
                throw new \InvalidArgumentException(__('Resolve the import issues before creating drafts.', 'codeon-shipping-rules'));
            }
            $id = absint($request['method_id'] ?? 0);
            if ($id) {
                if (count($parsed['methods']) !== 1) {
                    throw new \InvalidArgumentException(__('Choose a single-method file to load into an existing draft.', 'codeon-shipping-rules'));
                }
                $this->findMethod($id);
                $this->revisions->saveDraft($id, $parsed['methods'][0]['config'], (int) $request['revision'], (int) $request['draft_version']);
                $created[] = $id;
            } else {
                $zone = $this->zone((int) $request['zone_id']);
                $created = $this->importIntoZone($zone, array_column($parsed['methods'], 'config'));
            }
        }
        return $parsed + ['created' => $created];
    }

    private function importIntoZone(\WC_Shipping_Zone $zone, array $methods): array
    {
        global $wpdb;
        $zoneId = (int) $zone->get_id();
        $lock = 'codeon-shipping-rules-import-' . md5($wpdb->options . ':' . $zoneId);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
            throw new Conflict(__('Another import is running in this zone. Try again shortly.', 'codeon-shipping-rules'));
        }

        $result = [];
        $inserted = [];
        try {
            $existing = [];
            foreach ($zone->get_shipping_methods(false) as $method) {
                if ($method->id !== ShippingMethod::ID || $method->enabled === 'yes') {
                    continue;
                }
                $id = (int) $method->instance_id;
                $details = $this->revisions->details($id);
                if (!$details['revision'] && $details['draft_version']) {
                    $existing[hash('sha256', serialize($details['draft']))] = $id;
                }
            }
            foreach ($methods as $config) {
                $key = hash('sha256', serialize($config));
                if (!isset($existing[$key])) {
                    $existing[$key] = $this->insertMethod($zone, $config);
                    $inserted[] = $existing[$key];
                }
                $result[] = $existing[$key];
            }
        } catch (\Throwable $error) {
            foreach ($inserted as $methodId) {
                $zone->delete_shipping_method($methodId);
            }
            throw $error;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }

        return array_values(array_unique($result));
    }

    private function export(WP_REST_Request $request): array
    {
        [$method] = $this->findMethod((int) $request['id']);
        $details = $this->revisions->details((int) $method->instance_id);
        $config = $details['revision'] ? $details['config'] : $details['draft'];
        if ($request->get_param('format') === 'csv') {
            return ['data' => TierCsv::export($config), 'revision' => $details['revision']];
        }
        return [
            'data' => [
                'format' => 'codeon-shipping-rules',
                'version' => 1,
                'methods' => [$config],
            ],
            'revision' => $details['revision'],
        ];
    }

    private function products(WP_REST_Request $request): array
    {
        $search = substr(sanitize_text_field((string) $request['search']), 0, 120);
        if (mb_strlen($search) < 2) {
            return ['products' => []];
        }

        $ids = \WC_Data_Store::load('product')->search_products($search, '', true, false, 20);
        $products = [];
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if ($product instanceof \WC_Product && $product->get_status() !== 'trash') {
                $products[] = ['id' => $product->get_id(), 'name' => $product->get_name(), 'sku' => $product->get_sku()];
            }
        }
        return ['products' => $products];
    }

    private function methodSummary(\WC_Shipping_Method $method, \WC_Shipping_Zone $zone): array
    {
        $id = (int) $method->instance_id;
        $native = $method->id === ShippingMethod::ID;
        $state = $this->revisions->state($id);
        $title = $method->get_title();
        if (!$state['revision'] && $state['draft_version']) {
            $title = $this->revisions->details($id)['draft']['title'] ?: $title;
        }
        return [
            'id' => $id,
            'zone_id' => $zone->get_id(),
            'zone' => $zone->get_zone_name(),
            'title' => wp_strip_all_tags($title),
            'enabled' => $method->enabled === 'yes',
            'native' => $native,
            'method_id' => $method->id,
            'revision' => $state['revision'],
        ];
    }

    private function config(WP_REST_Request $request): array
    {
        $config = $request->get_param('config');
        if (!is_array($config)) {
            throw new \InvalidArgumentException(__('A rules configuration is required.', 'codeon-shipping-rules'));
        }
        return $config;
    }

    private function insertMethod(\WC_Shipping_Zone $zone, array $config): int
    {
        $config = Schema::normalize($config);
        $id = $zone->add_shipping_method(ShippingMethod::ID);
        if (!$id) {
            throw new \RuntimeException('WooCommerce did not add the shipping method.');
        }
        try {
            $this->setEnabled($id, (int) $zone->get_id(), false);
            $this->revisions->saveDraft($id, $config, 0, 0);
        } catch (\Throwable $error) {
            $zone->delete_shipping_method($id);
            throw $error;
        }
        return $id;
    }

    private function setEnabled(int $id, int $zoneId, bool $enabled): void
    {
        global $wpdb;
        // WooCommerce's zone methods REST controller uses this table and hook.
        $rows = $wpdb->update(
            $wpdb->prefix . 'woocommerce_shipping_zone_methods',
            ['is_enabled' => (int) $enabled],
            ['instance_id' => $id, 'zone_id' => $zoneId],
            ['%d'],
            ['%d', '%d']
        );
        if ($rows === false) {
            throw new \RuntimeException('WooCommerce could not update the shipping method.');
        }
        if ($rows) {
            do_action('woocommerce_shipping_zone_method_status_toggled', $id, ShippingMethod::ID, $zoneId, $enabled);
            \WC_Cache_Helper::get_transient_version('shipping', true);
            \WC_Cache_Helper::invalidate_cache_group('shipping_zones');
        }
    }

    private function findMethod(int $id): array
    {
        $method = \WC_Shipping_Zones::get_shipping_method($id);
        $zone = \WC_Shipping_Zones::get_zone_by('instance_id', $id);
        if (!$method instanceof \WC_Shipping_Method || !$zone || !$this->canEdit($method)) {
            throw new \OutOfBoundsException(__('That shipping method is unavailable.', 'codeon-shipping-rules'));
        }

        // The direct method getter does not hydrate is_enabled.
        $zoneMethod = $zone->get_shipping_methods(false)[$id] ?? $method;
        return [$zoneMethod, $zone];
    }

    private function zone(int $id): \WC_Shipping_Zone
    {
        $zone = \WC_Shipping_Zones::get_zone($id);
        if (!$zone instanceof \WC_Shipping_Zone || $zone->get_id() !== $id) {
            throw new \OutOfBoundsException(__('That shipping zone is unavailable.', 'codeon-shipping-rules'));
        }
        return $zone;
    }

    private function canEdit(\WC_Shipping_Method $method): bool
    {
        return $method->id === ShippingMethod::ID || Rates::supportsProvider($method->id);
    }

    private function numeric(mixed $value, float $minimum = 0, float $maximum = 100000000): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < $minimum || (float) $value > $maximum) {
            throw new \InvalidArgumentException(__('Enter valid scenario quantities and amounts.', 'codeon-shipping-rules'));
        }
        return (float) $value;
    }
}
