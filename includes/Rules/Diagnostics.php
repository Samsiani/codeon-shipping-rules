<?php

declare(strict_types=1);

namespace CodeOn\ShippingRules\Rules;

/** Static checks for simple tariff bands. Mixed conditions are demonstrated in the simulator. */
final class Diagnostics
{
    public static function analyze(array $config, int $decimals = 2): array
    {
        $groups = [];
        $currencyUnit = 10 ** (Decimal::PRECISION - max(0, min(Decimal::PRECISION, $decimals)));
        foreach ($config['rules'] as $rule) {
            $condition = count($rule['conditions']) === 1 ? $rule['conditions'][0] : null;
            if ($condition === null || !in_array($condition['type'], ['price', 'weight'], true)
                || $condition['operator'] !== 'between' || $rule['action'] !== 'continue') {
                continue;
            }
            $scope = $rule['scope'] === 'method' ? $config['scope'] : $rule['scope'];
            $type = $condition['type'];
            $unit = $type === 'price' ? $currencyUnit : 1;
            $minimum = Contents::number($condition['min']);
            $maximum = Contents::number($condition['max']);
            $from = intdiv(max(0, $minimum ?? 0), $unit) * $unit;
            if ($minimum !== null && ($from < $minimum || ($from === $minimum && $condition['min_exclusive']))) {
                $from += $unit;
            }
            $to = null;
            if ($maximum !== null) {
                $to = intdiv(max(0, $maximum), $unit) * $unit;
                if ($maximum < 0 || ($to === $maximum && $condition['max_exclusive'])) {
                    $to -= $unit;
                }
            }
            $groups[$type][$scope][] = ['from' => $from, 'to' => $to, 'id' => $rule['id']];
        }

        $issues = [];
        foreach ($groups as $type => $scopes) {
            foreach ($scopes as $scope => $bands) {
                $complete = count($bands) === count($config['rules']) && $config['fallback'] === '';
                usort($bands, static fn (array $left, array $right): int => $left['from'] <=> $right['from']);
                $unit = $type === 'price' ? $currencyUnit : 1;
                $issues = array_merge($issues, self::bandIssues($bands, $type, $scope, $unit, $complete));
                if ($complete && $type === 'price' && self::hasFreeThresholdGap($config['free'], $scope, $bands, $unit)) {
                    $issues[] = ['code' => 'free_threshold_gap', 'type' => 'price', 'scope' => $scope, 'at' => $config['free']['threshold']];
                }
            }
        }
        return $issues;
    }

    private static function bandIssues(array $bands, string $type, string $scope, int $unit, bool $complete): array
    {
        $frontier = null;
        $issues = [];
        foreach ($bands as $band) {
            if ($band['to'] !== null && $band['from'] > $band['to']) {
                $issues[] = ['code' => 'empty_band', 'type' => $type, 'scope' => $scope, 'rules' => [$band['id']]];
                continue;
            }
            if ($frontier !== null) {
                $relation = $frontier['to'] === null || $band['from'] <= $frontier['to']
                    ? 'bands_overlap'
                    : ($complete && $band['from'] - $frontier['to'] > $unit ? 'bands_gap' : '');
                if ($relation !== '') {
                    $issues[] = [
                        'code' => $relation, 'type' => $type, 'scope' => $scope,
                        'rules' => [$frontier['id'], $band['id']],
                    ];
                }
            }
            if ($frontier === null
                || ($frontier['to'] !== null && ($band['to'] === null || $band['to'] > $frontier['to']))) {
                $frontier = $band;
            }
        }
        return $issues;
    }

    private static function hasFreeThresholdGap(array $free, string $scope, array $bands, int $unit): bool
    {
        if ($free['scope'] !== $scope || $free['before_discount']
            || !in_array($free['mode'], ['amount', 'amount_or_coupon', 'amount_and_coupon'], true)) {
            return false;
        }
        $threshold = Decimal::steps(Decimal::parse($free['threshold']), $unit, 'up') * $unit;
        foreach ($bands as $band) {
            if ($threshold >= $band['from'] && ($band['to'] === null || $threshold <= $band['to'])) {
                return false;
            }
        }
        return true;
    }
}
