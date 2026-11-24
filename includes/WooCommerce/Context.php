<?php

declare(strict_types=1);

namespace CodeOn\ShippingRules\WooCommerce;

use CodeOn\ShippingRules\Integrations\CoreLocations;

final class Context
{
    public function fromPackage(array $package, array $config, array $requirements = [], bool $forCache = false): array
    {
        $requirements = $requirements ?: self::requirements($config);
        $now = current_datetime();
        $user = wp_get_current_user();
        $cart = WC()->cart;
        $cartItems = $requirements['cart'] && $cart instanceof \WC_Cart ? $cart->get_cart() : ($package['contents'] ?? []);
        $destination = $package['destination'] ?? [];
        if (!empty($requirements['geo'])) {
            $destination = CoreLocations::enrich($destination);
        }
        $destination = apply_filters('codeon_shipping_rules_destination', $destination, $package);

        $packageLines = !$forCache || $requirements['catalog'] || $requirements['fields']
            ? $this->lines($package['contents'] ?? [], $requirements) : [];
        return [
            'package' => $packageLines,
            'cart' => $requirements['cart'] ? $this->lines($cartItems, $requirements) : $packageLines,
            'logged_in' => $user->exists(),
            'roles' => $user->exists() ? $user->roles : ['guest'],
            'day' => (int) $now->format('N'),
            'time' => $now->format('H:i'),
            'destination' => is_array($destination) ? $destination : [],
            'coupon' => $requirements['coupon'] && $cart instanceof \WC_Cart && $this->hasFreeCoupon($cart),
            'currency' => get_woocommerce_currency(),
            'decimals' => wc_get_price_decimals(),
        ];
    }

    public static function requirements(array $config): array
    {
        $scope = $config['scope'] ?? 'package';
        $mode = $config['free']['mode'] ?? 'none';
        $freeScope = $config['free']['scope'] ?? $scope;
        $needs = [
            'cart' => $scope === 'cart' || ($mode !== 'none' && $freeScope === 'cart'),
            'coupon' => in_array($mode, ['coupon', 'amount_or_coupon', 'amount_and_coupon'], true),
            'role' => !empty($config['logged_in_only']),
            'time' => false,
            'day' => false,
            'geo' => false,
            'catalog' => [],
            'fields' => [],
        ];

        foreach ($config['rules'] ?? [] as $rule) {
            $needs['cart'] = $needs['cart'] || ($rule['scope'] ?? '') === 'cart';
            foreach ($rule['conditions'] ?? [] as $condition) {
                $type = $condition['type'] ?? '';
                if ($type === 'role') {
                    $needs['role'] = true;
                }
                if ($type === 'weekday') {
                    $needs['day'] = true;
                }
                if (in_array($type, ['municipality', 'settlement'], true)) {
                    $needs['geo'] = true;
                }
                if ($type === 'time') {
                    $needs['time'] = true;
                }
                if (in_array($type, ['product_field', 'product_field_range'], true) && !empty($condition['field'])) {
                    $needs['fields'][] = $condition['field'];
                }
                if (in_array($type, ['sku', 'category', 'tag', 'shipping_class', 'stock_quantity', 'stock_status', 'weight', 'dimensional_weight', 'volume', 'max_dimension', 'total_dimensions', 'length', 'width', 'height'], true)) {
                    $needs['catalog'][] = $type;
                }
            }
            foreach ($rule['charges'] ?? [] as $charge) {
                if (in_array($charge['basis'] ?? '', ['weight', 'dimensional_weight', 'volume'], true)) {
                    $needs['catalog'][] = $charge['basis'];
                }
            }
        }

        $needs['catalog'] = array_values(array_unique($needs['catalog']));
        $needs['fields'] = array_values(array_unique($needs['fields']));
        return $needs;
    }

    public function lines(array $items, array $requirements): array
    {
        $lines = [];
        foreach ($items as $item) {
            $product = $item['data'] ?? null;
            if (!$product instanceof \WC_Product) {
                continue;
            }

            $quantity = $item['quantity'] ?? 1;
            $net = (float) ($item['line_total'] ?? $product->get_price() * (float) $quantity);
            $netBefore = (float) ($item['line_subtotal'] ?? $net);
            $lines[] = $this->productFacts($product, $requirements) + [
                'quantity' => $quantity,
                'net' => $net,
                'gross' => $net + (float) ($item['line_tax'] ?? 0),
                'net_before_discount' => $netBefore,
                'gross_before_discount' => $netBefore + (float) ($item['line_subtotal_tax'] ?? 0),
            ];
        }
        return $lines;
    }

    public function productFacts(\WC_Product $product, array $requirements): array
    {
        $parent = $product->get_parent_id();
        $productId = $parent ?: $product->get_id();
        $needed = array_fill_keys($requirements['catalog'], true);
        $facts = [
            'product_id' => $productId,
            'variation_id' => $parent ? $product->get_id() : 0,
            'virtual' => !$product->needs_shipping(),
        ];
        if (isset($needed['sku'])) {
            $facts['sku'] = $product->get_sku();
        }
        if (isset($needed['stock_quantity'])) {
            $facts['stock_quantity'] = $product->get_stock_quantity();
        }
        if (isset($needed['stock_status'])) {
            $facts['stock_status'] = $product->get_stock_status();
        }
        if (isset($needed['shipping_class'])) {
            $facts['shipping_class'] = $product->get_shipping_class_id();
            $facts['shipping_class_slug'] = $product->get_shipping_class();
        }
        if (isset($needed['category'])) {
            $facts['categories'] = $this->terms($productId, 'product_cat');
        }
        if (isset($needed['tag'])) {
            $facts['tags'] = $this->terms($productId, 'product_tag');
        }
        if (isset($needed['weight']) || isset($needed['dimensional_weight'])) {
            $facts['weight'] = $this->measure($product->get_weight());
        }
        if (array_intersect(array_keys($needed), ['length', 'width', 'height', 'volume', 'dimensional_weight', 'max_dimension', 'total_dimensions'])) {
            $facts['length'] = $this->measure($product->get_length());
            $facts['width'] = $this->measure($product->get_width());
            $facts['height'] = $this->measure($product->get_height());
        }
        foreach ($requirements['fields'] as $field) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_:.\-]{0,127}$/D', $field)) {
                continue;
            }
            $value = $product->get_meta($field, true, 'edit');
            if (is_scalar($value) && $value !== '') {
                $facts['fields'][$field] = $value;
            }
        }

        return apply_filters('codeon_shipping_rules_product_facts', $facts, $product, $requirements);
    }

    private function measure(mixed $value): ?string
    {
        return is_numeric($value) ? (string) $value : null;
    }

    private function terms(int $id, string $taxonomy): array
    {
        $terms = get_the_terms($id, $taxonomy);
        return is_array($terms)
            ? [...wp_list_pluck($terms, 'term_id'), ...wp_list_pluck($terms, 'slug')]
            : [];
    }

    private function hasFreeCoupon(\WC_Cart $cart): bool
    {
        if (!$cart->get_applied_coupons()) {
            return false;
        }
        $discounts = new \WC_Discounts($cart);
        foreach ($cart->get_coupons() as $coupon) {
            if ($coupon->get_free_shipping() && $discounts->is_coupon_valid($coupon) === true) {
                return true;
            }
        }
        return false;
    }
}
