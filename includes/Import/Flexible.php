<?php

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are returned as JSON data or rendered with textContent.

namespace CodeOn\ShippingRules\Import;

use CodeOn\ShippingRules\Rules\Schema;
use InvalidArgumentException;

final class Flexible
{
    private const TYPES = [
        'none' => 'always',
        'value' => 'price',
        'item' => 'quantity',
        'cart_line_item' => 'line_count',
        'product_category' => 'category',
        'product_tag' => 'tag',
        'product_field_value' => 'product_field',
        'product_stock_quantity' => 'stock_quantity',
        'product_stock_status' => 'stock_status',
        'product_length' => 'length',
        'product_width' => 'width',
        'product_height' => 'height',
        'total_overall_dimensions' => 'total_dimensions',
        'shipping_cost' => 'running_cost',
        'user_role' => 'role',
        'day_of_the_week' => 'weekday',
        'time_of_the_day' => 'time',
    ];

    public function convert(array $method): array
    {
        if (!array_key_exists('method_rules', $method) && !array_key_exists('rules', $method)) {
            throw new InvalidArgumentException(__('The method does not contain Flexible Shipping rules.', 'codeon-shipping-rules'));
        }
        if (!empty($method['method_integration']) && $method['method_integration'] !== 'none') {
            throw new InvalidArgumentException(__('This method uses a carrier integration. Import an export of its pricing rules and attach them to a supported service.', 'codeon-shipping-rules'));
        }

        $rules = $method['method_rules'] ?? $method['rules'] ?? [];
        if (is_string($rules)) {
            $rules = json_decode($rules, true, 30);
        }
        if (!is_array($rules) || array_filter($rules, static fn ($rule): bool => !is_array($rule))) {
            throw new InvalidArgumentException(__('The source method rules are unreadable.', 'codeon-shipping-rules'));
        }

        $freeMode = match ($method['method_free_shipping_requires'] ?? '') {
            'item_quantity' => 'quantity',
            'coupon' => 'coupon',
            'order_amount_or_coupon' => 'amount_or_coupon',
            'order_amount_and_coupon' => 'amount_and_coupon',
            default => ($method['method_free_shipping'] ?? '') !== '' ? 'amount' : 'none',
        };
        $scope = ($method['cart_calculation'] ?? '') === 'package' ? 'package' : 'cart';

        return [
            'title' => $method['method_title'] ?? $method['title'] ?? __('Imported method', 'codeon-shipping-rules'),
            'description' => $method['method_description'] ?? '',
            'aggregation' => ($method['method_calculation_method'] ?? '') ?: 'sum',
            'scope' => $scope,
            'tax_status' => ($method['tax_status'] ?? '') === 'none' ? 'none' : 'taxable',
            'value_basis' => get_option('woocommerce_tax_display_cart') === 'incl' ? 'gross' : 'net',
            'max_cost' => self::decimal($method['method_max_cost'] ?? ''),
            'dim_divisor' => self::decimal(($method['method_weight_ratio'] ?? $method['dimensional_weight_ratio'] ?? '') ?: '5000'),
            'logged_in_only' => self::yes($method['method_visibility'] ?? ''),
            'default' => self::yes($method['method_default'] ?? ''),
            'free' => [
                'mode' => $freeMode,
                'scope' => 'cart',
                'threshold' => self::decimal($method['method_free_shipping'] ?? ''),
                'before_discount' => self::yes($method['method_free_shipping_ignore_discounts'] ?? ''),
                'notice' => self::yes($method['method_free_shipping_cart_notice'] ?? ''),
                'label' => $method['method_free_shipping_label'] ?? '',
            ],
            'rules' => array_map(fn (array $rule): array => $this->rule($rule), array_values($rules)),
        ];
    }

    private function rule(array $rule): array
    {
        $conditions = $rule['conditions'] ?? [];
        $basis = (string) ($rule['based_on'] ?? 'none');

        if (!$conditions) {
            $conditions = [[
                'condition_id' => $basis ?: 'none',
                'min' => $rule['min'] ?? '',
                'max' => $rule['max'] ?? '',
            ]];
            if (!empty($rule['shipping_class'])) {
                $conditions[] = ['condition_id' => 'shipping_class', 'shipping_class' => $rule['shipping_class']];
            }
        }

        $charges = $rule['additional_costs'] ?? [];
        if (!is_array($conditions) || !is_array($charges)
            || array_filter($conditions, static fn ($row): bool => !is_array($row))
            || array_filter($charges, static fn ($row): bool => !is_array($row))) {
            throw new InvalidArgumentException(__('The source method rules are unreadable.', 'codeon-shipping-rules'));
        }
        if (!$charges && ($rule['cost_additional'] ?? '') !== '' && (float) $rule['cost_additional'] !== 0.0) {
            $charges = [[
                'additional_cost' => $rule['cost_additional'],
                'per_value' => $rule['per_value'] ?? '',
                'based_on' => $basis,
            ]];
        }
        $action = $rule['special_action'] ?? '';
        if ($action === 'cancel' || $action === 'hide' || self::yes($rule['cancel'] ?? '')) {
            $action = 'hide';
        } elseif ($action === 'stop' || self::yes($rule['stop'] ?? '')) {
            $action = 'stop';
        } else {
            $action = 'continue';
        }

        return [
            'label' => $rule['name'] ?? '',
            'base' => self::decimal($rule['cost_per_order'] ?? '') ?: '0',
            'conditions' => array_map(fn (array $condition): array => $this->condition($condition), array_values($conditions)),
            'charges' => array_map(fn (array $charge): array => $this->charge($charge), array_values($charges)),
            'action' => $action,
        ];
    }

    private function condition(array $condition): array
    {
        $source = (string) ($condition['condition_id'] ?? 'none');
        $type = self::TYPES[$source] ?? $source;
        $catalog = Schema::catalog()['conditions'];
        if (!isset($catalog[$type])) {
            /* translators: %s: source condition type. */
            throw new InvalidArgumentException(sprintf(__('The source condition “%s” needs a manual replacement.', 'codeon-shipping-rules'), $source));
        }
        if ($type === 'always') {
            return ['type' => 'always'];
        }

        $operator = (string) ($condition['operator'] ?? '');
        $row = ['type' => $type, 'field' => $condition['product_field_name'] ?? ''];
        if ($catalog[$type]['kind'] === 'selection') {
            $values = match ($type) {
                'weekday' => $condition['days'] ?? [],
                'product_field' => [$condition['value'] ?? ''],
                default => $condition[$source] ?? $condition['values'] ?? [],
            };
            $values = is_array($values) ? array_values($values) : [$values];
            if ($type === 'shipping_class') {
                if (in_array('all', $values, true)) {
                    return ['type' => 'always'];
                }
                $values = array_map(static fn ($value) => match ($value) { 'none' => '0', 'any' => 'assigned', default => $value }, $values);
            }
            return $row + [
                'operator' => match ($operator) {
                    'is_not', 'not_equal', 'none' => 'none',
                    'all' => 'all',
                    'only' => 'only',
                    default => 'any',
                },
                'values' => $this->resolve($type, $values),
            ];
        }
        return $row + [
            'operator' => $operator === 'is_not' ? 'not_between' : 'between',
            'min' => $type === 'time' ? ($condition['from'] ?? '00:00') : self::decimal($condition['min'] ?? ''),
            'max' => $type === 'time' ? ($condition['to'] ?? '23:00') : self::decimal($condition['max'] ?? ''),
        ];
    }

    private function charge(array $charge): array
    {
        $source = (string) ($charge['based_on'] ?? '');
        $basis = self::TYPES[$source] ?? $source;
        if (!isset(Schema::catalog()['bases'][$basis])) {
            /* translators: %s: source charging basis. */
            throw new InvalidArgumentException(sprintf(__('The surcharge basis “%s” needs a manual replacement.', 'codeon-shipping-rules'), $source));
        }

        return [
            'type' => 'step',
            'basis' => $basis,
            'amount' => self::decimal($charge['additional_cost'] ?? '0'),
            'step' => self::decimal($charge['per_value'] ?? ''),
            'rounding' => 'up',
        ];
    }

    private function resolve(string $type, array $values): array
    {
        $taxonomy = ['category' => 'product_cat', 'tag' => 'product_tag', 'shipping_class' => 'product_shipping_class'][$type] ?? '';
        if ($taxonomy) {
            return array_map(static function ($value) use ($taxonomy): string {
                if ($taxonomy === 'product_shipping_class' && in_array((string) $value, ['0', 'assigned'], true)) {
                    return (string) $value;
                }
                $term = is_numeric($value) ? get_term((int) $value, $taxonomy) : get_term_by('slug', (string) $value, $taxonomy);
                if (!$term instanceof \WP_Term && !is_numeric($value)) {
                    $term = get_term_by('name', (string) $value, $taxonomy);
                }
                if (!$term instanceof \WP_Term) {
                    /* translators: %s: imported term. */
                    throw new InvalidArgumentException(sprintf(__('Match the imported product term “%s” to a term on this site.', 'codeon-shipping-rules'), (string) $value));
                }
                return (string) $term->term_id;
            }, $values);
        }
        if ($type === 'product') {
            return array_map(static function ($value): string {
                $id = is_numeric($value) ? (int) $value : (int) wc_get_product_id_by_sku((string) $value);
                if (!$id || !wc_get_product($id)) {
                    /* translators: %s: imported product identifier. */
                    throw new InvalidArgumentException(sprintf(__('Match the imported product “%s” to an item on this site.', 'codeon-shipping-rules'), (string) $value));
                }
                return (string) $id;
            }, $values);
        }
        return $values;
    }

    public static function decimal(mixed $value): string
    {
        return is_scalar($value) ? str_replace(',', '.', trim((string) $value)) : '';
    }

    private static function yes(mixed $value): bool
    {
        return in_array($value, ['yes', '1', 1, true], true);
    }
}
