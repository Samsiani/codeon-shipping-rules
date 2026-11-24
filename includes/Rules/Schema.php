<?php

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are returned as JSON data or rendered with textContent.

namespace CodeOn\ShippingRules\Rules;

use InvalidArgumentException;

final class Schema
{
    public const RANGES = [
        'price' => 'Merchandise value',
        'gross_price' => 'Merchandise value including tax',
        'price_before_discount' => 'Merchandise value before discount',
        'gross_before_discount' => 'Merchandise value with tax before discount',
        'weight' => 'Weight',
        'quantity' => 'Item quantity',
        'line_count' => 'Cart line count',
        'volume' => 'Volume',
        'dimensional_weight' => 'Dimensional weight',
        'max_dimension' => 'Longest edge',
        'total_dimensions' => 'Length plus width plus height',
        'length' => 'Length',
        'width' => 'Width',
        'height' => 'Height',
        'stock_quantity' => 'Stock quantity',
        'product_field_range' => 'Numeric product field',
        'running_cost' => 'Running shipping cost',
    ];

    public const SELECTIONS = [
        'product' => 'Product',
        'variation' => 'Variation',
        'sku' => 'Product SKU',
        'category' => 'Product category',
        'tag' => 'Product tag',
        'shipping_class' => 'Shipping class',
        'stock_status' => 'Stock status',
        'product_field' => 'Product field value',
        'role' => 'Customer role',
        'weekday' => 'Day of week',
        'country' => 'Country',
        'state' => 'State or region',
        'city' => 'City',
        'postcode' => 'Postal code',
        'municipality' => 'Municipality',
        'settlement' => 'Settlement',
        'currency' => 'Currency',
    ];

    public const BASES = [
        'price' => 'Merchandise value',
        'gross_price' => 'Merchandise value including tax',
        'price_before_discount' => 'Merchandise value before discount',
        'gross_before_discount' => 'Merchandise value with tax before discount',
        'weight' => 'Weight',
        'quantity' => 'Item quantity',
        'line_count' => 'Cart line count',
        'volume' => 'Volume',
        'dimensional_weight' => 'Dimensional weight',
        'running_cost' => 'Running shipping cost',
    ];

    public static function catalog(): array
    {
        $conditions = ['always' => ['label' => 'Always', 'kind' => 'always', 'field' => false]];
        foreach (self::RANGES as $type => $label) {
            $conditions[$type] = ['label' => $label, 'kind' => 'range', 'field' => $type === 'product_field_range'];
        }
        foreach (self::SELECTIONS as $type => $label) {
            $conditions[$type] = ['label' => $label, 'kind' => 'selection', 'field' => $type === 'product_field'];
        }
        $conditions['time'] = ['label' => 'Time of day', 'kind' => 'time', 'field' => false];
        return ['conditions' => $conditions, 'bases' => self::BASES];
    }

    public static function defaults(): array
    {
        return [
            'title' => '',
            'description' => '',
            'delivery_time' => '',
            'logo_id' => 0,
            'tax_status' => 'taxable',
            'cost_includes_tax' => false,
            'value_basis' => 'net',
            'scope' => 'package',
            'aggregation' => 'sum',
            'dim_divisor' => '5000',
            'min_cost' => '',
            'max_cost' => '',
            'fallback' => '',
            'default' => false,
            'logged_in_only' => false,
            'bridge_rate_ids' => [],
            'free' => [
                'mode' => 'none',
                'scope' => 'cart',
                'threshold' => '',
                'before_discount' => false,
                'label' => '',
                'notice' => false,
            ],
            'rules' => [],
        ];
    }

    public static function normalize(array $input): array
    {
        $config = self::defaults();
        $config['title'] = self::text($input['title'] ?? '', 'Method title', 255);
        $config['description'] = self::text($input['description'] ?? '', 'Method description', 4000);
        $config['delivery_time'] = self::text($input['delivery_time'] ?? '', 'Delivery time', 255);
        $config['logo_id'] = self::identifier($input['logo_id'] ?? 0, 'Logo ID');
        $config['tax_status'] = self::option($input['tax_status'] ?? 'taxable', ['taxable', 'none'], 'Tax status');
        $config['value_basis'] = self::option($input['value_basis'] ?? 'net', ['net', 'gross'], 'Value basis');
        $config['scope'] = self::option($input['scope'] ?? 'package', ['package', 'cart'], 'Method scope');
        $config['aggregation'] = self::option($input['aggregation'] ?? 'sum', ['sum', 'lowest', 'highest', 'first'], 'Aggregation');

        foreach (['cost_includes_tax', 'default', 'logged_in_only'] as $key) {
            $config[$key] = self::boolean($input[$key] ?? false, $key);
        }
        foreach (['min_cost', 'max_cost', 'fallback'] as $key) {
            $config[$key] = self::decimal($input[$key] ?? '', $key, true, true);
        }
        if ($config['min_cost'] !== '' && $config['max_cost'] !== ''
            && Decimal::parse($config['min_cost']) > Decimal::parse($config['max_cost'])) {
            throw new InvalidArgumentException('Minimum cost cannot exceed maximum cost.');
        }
        $config['dim_divisor'] = self::positive($input['dim_divisor'] ?? '5000', 'Dimensional divisor');
        $config['free'] = self::free($input['free'] ?? []);
        foreach (self::sequence($input['bridge_rate_ids'] ?? [], 'Target rate IDs') as $rateId) {
            $rateId = self::text($rateId, 'Target rate ID', 255);
            if ($rateId !== '') {
                $config['bridge_rate_ids'][] = $rateId;
            }
        }
        $config['bridge_rate_ids'] = array_values(array_unique($config['bridge_rate_ids']));

        $seen = [];
        foreach (self::sequence($input['rules'] ?? [], 'Rules') as $index => $rawRule) {
            $path = 'Rule ' . ($index + 1);
            try {
                $rule = self::rule(self::object($rawRule, $path), $index);
                if (isset($seen[$rule['id']])) {
                    throw new InvalidArgumentException('Duplicate rule ID.');
                }
                $seen[$rule['id']] = true;
                $config['rules'][] = $rule;
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException($path . ': ' . $exception->getMessage(), 0, $exception);
            }
        }
        return $config;
    }

    private static function rule(array $input, int $index): array
    {
        $id = self::text($input['id'] ?? 'rule-' . ($index + 1), 'Rule ID', 80);
        if ($id === '') {
            $id = 'rule-' . ($index + 1);
        }
        if (preg_match('/\A[a-zA-Z0-9_-]+\z/D', $id) !== 1) {
            throw new InvalidArgumentException('Rule ID may contain only letters, numbers, underscores and hyphens.');
        }
        $conditions = [];
        foreach (self::sequence($input['conditions'] ?? [], 'Conditions') as $condition) {
            $conditions[] = self::condition(self::object($condition, 'Condition'));
        }
        $charges = [];
        foreach (self::sequence($input['charges'] ?? [], 'Charges') as $charge) {
            $charges[] = self::charge(self::object($charge, 'Charge'));
        }
        return [
            'id' => $id,
            'label' => self::text($input['label'] ?? '', 'Rule label', 255),
            'scope' => self::option($input['scope'] ?? 'method', ['method', 'cart', 'package'], 'Rule scope'),
            'logic' => self::option($input['logic'] ?? 'all', ['all', 'any'], 'Condition logic'),
            'conditions' => $conditions,
            'base' => self::decimal(($input['base'] ?? '') === '' ? '0' : $input['base'], 'Base charge'),
            'charges' => $charges,
            'action' => self::option($input['action'] ?? 'continue', ['continue', 'stop', 'hide'], 'Rule action'),
        ];
    }

    private static function condition(array $input): array
    {
        $type = self::text($input['type'] ?? '', 'Condition type', 80);
        if ($type === 'always') {
            return ['type' => 'always', 'operator' => 'any', 'min' => '', 'max' => '', 'values' => [], 'field' => '',
                'min_exclusive' => false, 'max_exclusive' => false];
        }
        $selection = array_key_exists($type, self::SELECTIONS);
        if (!$selection && $type !== 'time' && !array_key_exists($type, self::RANGES)) {
            throw new InvalidArgumentException('Unknown condition type: ' . $type . '.');
        }
        $operator = $input['operator'] ?? ($selection ? 'any' : 'between');
        $operator = match ($operator) {
            'is' => $selection ? 'any' : 'between',
            'is_not' => $selection ? 'none' : 'not_between',
            default => $operator,
        };
        $operator = self::option(
            $operator,
            $selection ? ['any', 'all', 'none', 'only'] : ['between', 'not_between'],
            'Condition operator'
        );
        $field = '';
        if ($type === 'product_field' || $type === 'product_field_range') {
            $field = self::text($input['field'] ?? '', 'Product field', 128);
            if (preg_match('/\A[A-Za-z][A-Za-z0-9_.:-]*\z/D', $field) !== 1) {
                throw new InvalidArgumentException('Use a public product field key that starts with a letter.');
            }
        }

        $values = [];
        $min = $max = '';
        $minExclusive = $maxExclusive = false;
        if ($selection) {
            foreach (self::sequence($input['values'] ?? [], 'Condition values') as $value) {
                $value = self::text($value, 'Condition value', 255);
                if ($value !== '') {
                    $values[] = $value;
                }
            }
            $values = array_values(array_unique($values));
            if ($values === []) {
                throw new InvalidArgumentException('Select at least one condition value.');
            }
            if ($type === 'weekday' && array_diff($values, ['1', '2', '3', '4', '5', '6', '7']) !== []) {
                throw new InvalidArgumentException('Days of week must use numbers 1 through 7.');
            }
        } else {
            $minExclusive = self::boolean($input['min_exclusive'] ?? false, 'Lower bound exclusivity');
            $maxExclusive = self::boolean($input['max_exclusive'] ?? false, 'Upper bound exclusivity');
            if ($type === 'time') {
                $min = self::clock($input['min'] ?? '', 'Start time');
                $max = self::clock($input['max'] ?? '', 'End time');
            } else {
                $min = self::decimal($input['min'] ?? '', 'Lower bound', true);
                $max = self::decimal($input['max'] ?? '', 'Upper bound', true);
            }
            if ($min !== '' && $max !== '') {
                $comparison = $type === 'time' ? strcmp($min, $max) : Decimal::parse($min) <=> Decimal::parse($max);
                if (($type !== 'time' && $comparison > 0) || ($comparison === 0 && ($minExclusive || $maxExclusive))) {
                    throw new InvalidArgumentException('The condition range is empty.');
                }
            }
        }
        return [
            'type' => $type,
            'operator' => $operator,
            'min' => $min,
            'max' => $max,
            'values' => $values,
            'field' => $field,
            'min_exclusive' => $minExclusive,
            'max_exclusive' => $maxExclusive,
        ];
    }

    private static function charge(array $input): array
    {
        $type = self::option($input['type'] ?? '', ['step', 'percent', 'fixed'], 'Charge type');
        return [
            'type' => $type,
            'basis' => $type === 'fixed' ? 'price' : self::option($input['basis'] ?? 'price', array_keys(self::BASES), 'Charge basis'),
            'amount' => self::decimal($input['amount'] ?? '', 'Charge amount'),
            'step' => $type === 'step' ? self::positive($input['step'] ?? '', 'Charge step') : '',
            'rounding' => $type === 'step'
                ? self::option($input['rounding'] ?? 'up', ['up', 'down', 'nearest'], 'Charge rounding')
                : 'up',
        ];
    }

    private static function free(mixed $input): array
    {
        $input = self::object($input, 'Free shipping');
        $mode = self::option(
            $input['mode'] ?? 'none',
            ['none', 'amount', 'quantity', 'coupon', 'amount_or_coupon', 'amount_and_coupon'],
            'Free shipping mode'
        );
        return [
            'mode' => $mode,
            'scope' => self::option($input['scope'] ?? 'cart', ['cart', 'package'], 'Free shipping scope'),
            'threshold' => in_array($mode, ['none', 'coupon'], true)
                ? self::decimal($input['threshold'] ?? '', 'Free shipping threshold', true, true)
                : self::positive($input['threshold'] ?? '', 'Free shipping threshold'),
            'before_discount' => self::boolean($input['before_discount'] ?? false, 'Free shipping before discount'),
            'label' => self::text($input['label'] ?? '', 'Free shipping label', 255),
            'notice' => self::boolean($input['notice'] ?? false, 'Free shipping notice'),
        ];
    }

    private static function decimal(mixed $value, string $name, bool $blank = false, bool $nonNegative = false): string
    {
        if (is_string($value)) {
            // The editor can receive a locale-specific decimal separator, while stored rules use a dot.
            $value = str_replace(',', '.', trim($value));
        }
        if ($blank && ($value === '' || $value === null)) {
            return '';
        }
        try {
            $number = Decimal::parse($value);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException($name . ' must be a valid decimal number.', 0, $exception);
        }
        if ($nonNegative && $number < 0) {
            throw new InvalidArgumentException($name . ' cannot be negative.');
        }
        return Decimal::format($number);
    }

    private static function positive(mixed $value, string $name): string
    {
        $number = self::decimal($value, $name);
        if (Decimal::parse($number) <= 0) {
            throw new InvalidArgumentException($name . ' must be greater than zero.');
        }
        return $number;
    }

    private static function clock(mixed $value, string $name): string
    {
        $clock = self::text($value, $name, 5);
        if ($clock !== '' && preg_match('/\A(?:(?:[01][0-9]|2[0-3]):[0-5][0-9]|24:00)\z/D', $clock) !== 1) {
            throw new InvalidArgumentException($name . ' must use the 24-hour HH:MM format.');
        }
        return $clock;
    }

    private static function text(mixed $input, string $name, int $limit): string
    {
        if (!is_string($input) && !is_int($input)) {
            throw new InvalidArgumentException($name . ' must be text.');
        }
        $value = trim((string) $input);
        if (strlen($value) > $limit) {
            throw new InvalidArgumentException($name . ' is too long.');
        }
        return $value;
    }

    private static function identifier(mixed $input, string $name): int
    {
        $value = filter_var($input, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($value === false) {
            throw new InvalidArgumentException($name . ' must be a non-negative integer.');
        }
        return $value;
    }

    private static function boolean(mixed $input, string $name): bool
    {
        if (!is_scalar($input)) {
            throw new InvalidArgumentException('Invalid ' . $name . ' setting.');
        }
        $value = filter_var($input, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new InvalidArgumentException('Invalid ' . $name . ' setting.');
        }
        return $value;
    }

    private static function option(mixed $input, array $choices, string $name): string
    {
        if (!is_string($input) || !in_array($input, $choices, true)) {
            throw new InvalidArgumentException('Invalid ' . $name . ' setting.');
        }
        return $input;
    }

    private static function sequence(mixed $input, string $name): array
    {
        if (!is_array($input) || !array_is_list($input)) {
            throw new InvalidArgumentException($name . ' must be a list.');
        }
        return $input;
    }

    private static function object(mixed $input, string $name): array
    {
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            throw new InvalidArgumentException($name . ' must be an object.');
        }
        return $input;
    }
}
