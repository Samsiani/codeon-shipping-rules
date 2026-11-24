<?php

declare(strict_types=1);

namespace CodeOn\ShippingRules\Rules;

final class Conditions
{
    private const LINE_RANGES = [
        'length', 'width', 'height', 'max_dimension', 'total_dimensions', 'stock_quantity', 'product_field_range',
    ];

    private const LINE_SELECTIONS = [
        'product', 'variation', 'sku', 'category', 'tag', 'shipping_class', 'stock_status', 'product_field',
    ];

    public static function evaluate(
        array $rule,
        Contents $contents,
        array $context,
        string $valueBasis,
        int $runningCost,
        bool $trace
    ): array {
        $conditions = $rule['conditions'];
        if ($conditions === []) {
            return ['matched' => true, 'contents' => $contents, 'checks' => []];
        }

        $selected = null;
        $matched = $rule['logic'] === 'all';
        $checks = [];
        if ($rule['logic'] === 'all') {
            // Select goods first so compound weight and value rules measure the same subset
            // regardless of the rows' visual order in the editor.
            foreach ($conditions as $index => $condition) {
                if (!self::lineType($condition['type'])) {
                    continue;
                }
                [$passes, $indexes] = self::lineCondition($condition, $contents->lines);
                if ($trace) {
                    $checks[$index] = ['type' => $condition['type'], 'matched' => $passes];
                }
                $matched = $matched && $passes;
                if ($passes && $indexes !== null) {
                    $selected = $selected === null ? $indexes : array_intersect_key($selected, $indexes);
                    $matched = $matched && $selected !== [];
                }
                if (!$matched && !$trace) {
                    return ['matched' => false, 'contents' => $contents, 'checks' => []];
                }
            }

            $scope = $selected === null ? $contents : $contents->subset($selected);
            foreach ($conditions as $index => $condition) {
                if (self::lineType($condition['type'])) {
                    continue;
                }
                $passes = self::contextCondition($condition, $scope, $context, $valueBasis, $runningCost);
                if ($trace) {
                    $checks[$index] = ['type' => $condition['type'], 'matched' => $passes];
                }
                $matched = $matched && $passes;
                if (!$matched && !$trace) {
                    break;
                }
            }
        } else {
            $selected = [];
            foreach ($conditions as $index => $condition) {
                if (self::lineType($condition['type'])) {
                    [$passes, $indexes] = self::lineCondition($condition, $contents->lines);
                } else {
                    $passes = self::contextCondition($condition, $contents, $context, $valueBasis, $runningCost);
                    $indexes = null;
                }
                if ($trace) {
                    $checks[$index] = ['type' => $condition['type'], 'matched' => $passes];
                }
                if ($passes) {
                    $matched = true;
                    $selected = $indexes === null || $selected === null ? null : $selected + $indexes;
                }
                if ($matched && $selected === null && !$trace) {
                    break;
                }
            }
            $scope = $selected === null ? $contents : $contents->subset($selected);
        }

        ksort($checks);
        return ['matched' => $matched, 'contents' => $scope, 'checks' => array_values($checks)];
    }

    private static function lineType(string $type): bool
    {
        return in_array($type, self::LINE_RANGES, true) || in_array($type, self::LINE_SELECTIONS, true);
    }

    private static function lineCondition(array $condition, array $lines): array
    {
        $type = $condition['type'];
        $matchedLines = [];
        if (in_array($type, self::LINE_RANGES, true)) {
            $min = Contents::number($condition['min']);
            $max = Contents::number($condition['max']);
            foreach ($lines as $index => $line) {
                $value = match ($type) {
                    'stock_quantity' => Contents::number($line['stock_quantity'] ?? null),
                    'product_field_range' => Contents::number($line['fields'][$condition['field']] ?? null),
                    default => Contents::physical($line, $type),
                };
                if ($value !== null && self::range($value, $min, $max, $condition)) {
                    $matchedLines[$index] = true;
                }
            }
            return [$matchedLines !== [], $matchedLines];
        }

        $wanted = self::strings($condition['values']);
        $found = [];
        $hasUnknown = false;
        foreach ($lines as $index => $line) {
            $actual = self::lineValues($line, $type, $condition['field']);
            if ($actual === [] && in_array($type, ['stock_status', 'product_field'], true)) {
                $hasUnknown = true;
            }
            foreach ($wanted as $value) {
                if (in_array($value, $actual, true)) {
                    $found[$value] = true;
                    $matchedLines[$index] = true;
                }
            }
        }
        $passes = match ($condition['operator']) {
            'all' => count($found) === count($wanted),
            'none' => $matchedLines === [] && !$hasUnknown,
            'only' => $matchedLines !== [] && count($matchedLines) === count($lines),
            default => $matchedLines !== [],
        };
        return [$passes, $condition['operator'] === 'none' ? null : $matchedLines];
    }

    private static function contextCondition(
        array $condition,
        Contents $contents,
        array $context,
        string $valueBasis,
        int $runningCost
    ): bool {
        $type = $condition['type'];
        if ($type === 'always') {
            return true;
        }
        if ($type === 'time') {
            $time = $context['time'] ?? '';
            return is_string($time) && preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $time) === 1
                && self::range($time, $condition['min'] ?: null, $condition['max'] ?: null, $condition, true);
        }
        if (array_key_exists($type, Schema::RANGES)) {
            $value = $type === 'running_cost' ? $runningCost : $contents->measure($type, $valueBasis);
            return $value !== null
                && self::range($value, Contents::number($condition['min']), Contents::number($condition['max']), $condition);
        }

        $actual = match ($type) {
            'role' => $context['roles'] ?? $context['role'] ?? (empty($context['logged_in']) ? 'guest' : null),
            'weekday' => $context['day'] ?? null,
            'currency' => $context['currency'] ?? null,
            default => $context['destination'][$type] ?? null,
        };
        $actual = self::strings($actual, $type === 'postcode');
        if ($actual === []) {
            return false;
        }
        $wanted = self::strings($condition['values'], $type === 'postcode');
        $found = $matchedActual = [];
        foreach ($actual as $observed) {
            foreach ($wanted as $expected) {
                if ($observed === $expected || ($type === 'postcode' && self::postcode($observed, $expected))) {
                    $found[$expected] = true;
                    $matchedActual[$observed] = true;
                }
            }
        }
        return match ($condition['operator']) {
            'all' => count($found) === count($wanted),
            'none' => $found === [],
            'only' => count($matchedActual) === count($actual),
            default => $found !== [],
        };
    }

    private static function lineValues(array $line, string $type, string $field): array
    {
        $values = match ($type) {
            'product' => array_filter([$line['product_id'] ?? null, $line['variation_id'] ?? null]),
            'variation' => array_filter([$line['variation_id'] ?? null]),
            'sku' => $line['sku'] ?? null,
            'category' => $line['categories'] ?? [],
            'tag' => $line['tags'] ?? [],
            'shipping_class' => empty($line['shipping_class'])
                ? ['0', 'none', 'unclassified']
                : array_merge(['assigned', $line['shipping_class_slug'] ?? null], (array) $line['shipping_class']),
            'stock_status' => $line['stock_status'] ?? null,
            'product_field' => $line['fields'][$field] ?? null,
            default => [],
        };
        return self::strings($values);
    }

    private static function strings(mixed $values, bool $postcode = false): array
    {
        $result = [];
        foreach (is_array($values) ? $values : [$values] as $value) {
            if (is_scalar($value)) {
                $value = trim(is_bool($value) ? (string) (int) $value : (string) $value);
                if ($value !== '') {
                    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
                    $result[] = $postcode ? str_replace(' ', '', $value) : $value;
                }
            }
        }
        return array_values(array_unique($result));
    }

    private static function postcode(string $value, string $pattern): bool
    {
        if (!str_contains($pattern, '*')) {
            return false;
        }
        $pattern = str_replace('\\*', '.*', preg_quote($pattern, '~'));
        return preg_match('~\A' . $pattern . '\z~D', $value) === 1;
    }

    private static function range(
        int|string $value,
        int|string|null $min,
        int|string|null $max,
        array $condition,
        bool $overnight = false
    ): bool {
        $above = $min === null || ($condition['min_exclusive'] ? $value > $min : $value >= $min);
        $below = $max === null || ($condition['max_exclusive'] ? $value < $max : $value <= $max);
        $matches = $overnight && $min !== null && $max !== null && $min > $max
            ? ($above || $below)
            : ($above && $below);
        return $condition['operator'] === 'not_between' ? !$matches : $matches;
    }
}
