<?php

declare(strict_types=1);

namespace CodeOn\ShippingRules\Rules;

use InvalidArgumentException;
use OverflowException;

/** Shared quote evaluator for checkout, rate adjustments, and simulation. */
final class Evaluator
{
    public static function quote(array $config, array $context, bool $trace = false): array
    {
        $baseRate = Contents::number($context['base_rate'] ?? null);
        $decimals = max(0, min(Decimal::PRECISION, (int) ($context['decimals'] ?? 2)));
        try {
            return self::calculate($config, $context, $baseRate, $decimals, $trace);
        } catch (InvalidArgumentException | OverflowException $error) {
            // An invalid or overflowing calculation cannot silently replace a provider's quote.
            $details = $trace ? [['reason' => 'calculation_error', 'message' => $error->getMessage()]] : [];
            return self::result($baseRate !== null, $baseRate ?? 0, $decimals, 'calculation_error', $details);
        }
    }

    private static function calculate(
        array $config,
        array $context,
        ?int $baseRate,
        int $decimals,
        bool $traceEnabled
    ): array {
        if ($config['logged_in_only'] && empty($context['logged_in'])) {
            return self::result(false, 0, $decimals, 'login_required');
        }

        $divisor = Decimal::parse($config['dim_divisor']);
        $missingAsZero = ($config['missing_measurements'] ?? 'unavailable') === 'zero';
        $package = Contents::from(self::lines($context['package'] ?? $context['cart'] ?? [], $missingAsZero), $divisor);
        if ($package->lines === [] && $baseRate === null) {
            return self::result(false, 0, $decimals, 'empty_package');
        }
        $cart = isset($context['cart']) ? Contents::from(self::lines($context['cart'], $missingAsZero), $divisor) : $package;
        $scopes = ['package' => $package, 'cart' => $cart];
        $basis = $config['value_basis'];
        $seed = $baseRate ?? 0;
        $runningCost = $seed;
        $subtotal = null;
        $trace = [];

        foreach ($config['rules'] as $rule) {
            $scope = $rule['scope'] === 'method' ? $config['scope'] : $rule['scope'];
            $decision = Conditions::evaluate($rule, $scopes[$scope], $context, $basis, $runningCost, $traceEnabled);
            $contribution = null;
            $reason = 'condition_failed';
            $hidden = $decision['matched'] && $rule['action'] === 'hide';
            if ($hidden) {
                $reason = 'hidden';
            } elseif ($decision['matched']) {
                $contribution = self::calculateRuleCost($rule, $decision['contents'], $basis, $runningCost);
                if ($contribution === null) {
                    $reason = 'missing_measurement';
                } else {
                    $reason = 'matched';
                    $subtotal = self::aggregate($subtotal, $contribution, $config['aggregation']);
                    $runningCost = Decimal::add($seed, $subtotal);
                }
            }

            if ($traceEnabled) {
                $trace[] = [
                    'id' => $rule['id'],
                    'label' => $rule['label'],
                    'matched' => $decision['matched'],
                    'action' => $rule['action'],
                    'scope' => $scope,
                    'line_count' => count($decision['contents']->lines),
                    'contribution' => $contribution === null ? null : Decimal::format($contribution),
                    'running_cost' => Decimal::format($runningCost),
                    'reason' => $reason,
                    'conditions' => $decision['checks'],
                ];
            }
            if ($hidden) {
                return self::result(false, 0, $decimals, 'hidden', $trace);
            }
            if ($contribution !== null && ($rule['action'] === 'stop' || $config['aggregation'] === 'first')) {
                break;
            }
        }

        $reason = 'matched';
        if ($subtotal === null) {
            if ($baseRate !== null) {
                return self::result(true, $baseRate, $decimals, 'passthrough', $trace);
            }
            if ($config['fallback'] === '') {
                return self::result(false, 0, $decimals, 'no_matching_rule', $trace);
            }
            $runningCost = Decimal::parse($config['fallback']);
            $reason = 'fallback';
        }

        $boundedCost = max($runningCost, Contents::number($config['min_cost']) ?? 0, 0);
        $maximum = Contents::number($config['max_cost']);
        if ($maximum !== null) {
            $boundedCost = min($boundedCost, $maximum);
        }

        $free = $config['free'];
        $entitlement = self::freeShipping($free, $scopes[$free['scope']], $context, $basis, $decimals);
        $result = self::result(
            true,
            $entitlement['eligible'] ? 0 : $boundedCost,
            $decimals,
            $entitlement['eligible'] ? 'free_shipping' : $reason,
            $trace,
            $entitlement['eligible'],
            $boundedCost > 0 ? $entitlement['progress'] : null
        );
        if ($traceEnabled) {
            $result['totals'] = [
                'seed' => Decimal::format($seed),
                'calculated' => Decimal::format($runningCost),
                'bounded' => Decimal::format($boundedCost),
                'final' => $result['cost'],
            ];
        }
        return $result;
    }

    /**
     * Counts a missing weight or dimension as zero, as WooCommerce and Flexible Shipping do, when the method asks for it.
     */
    private static function lines(array $lines, bool $missingAsZero): array
    {
        if (!$missingAsZero) {
            return $lines;
        }
        foreach ($lines as $index => $line) {
            if (!is_array($line)) {
                continue;
            }
            foreach (['weight', 'length', 'width', 'height'] as $measurement) {
                if (!is_numeric($line[$measurement] ?? null)) {
                    $lines[$index][$measurement] = '0';
                }
            }
        }
        return $lines;
    }

    private static function calculateRuleCost(array $rule, Contents $contents, string $valueBasis, int $runningCost): ?int
    {
        $amount = Decimal::parse($rule['base']);
        foreach ($rule['charges'] as $charge) {
            $unitAmount = Decimal::parse($charge['amount']);
            if ($charge['type'] === 'fixed' || $unitAmount === 0) {
                $amount = Decimal::add($amount, $unitAmount);
                continue;
            }
            $basis = $charge['basis'] === 'running_cost' ? $runningCost : $contents->measure($charge['basis'], $valueBasis);
            if ($basis === null) {
                return null;
            }
            $increment = $charge['type'] === 'percent'
                ? Decimal::divideRounded(Decimal::multiply($basis, $unitAmount), 100)
                : Decimal::multiplyWhole($unitAmount, Decimal::steps($basis, Decimal::parse($charge['step']), $charge['rounding']));
            $amount = Decimal::add($amount, $increment);
        }
        return $amount;
    }

    private static function aggregate(?int $subtotal, int $contribution, string $aggregation): int
    {
        if ($subtotal === null) {
            return $contribution;
        }
        return match ($aggregation) {
            'lowest' => min($subtotal, $contribution),
            'highest' => max($subtotal, $contribution),
            default => Decimal::add($subtotal, $contribution),
        };
    }

    private static function freeShipping(array $policy, Contents $contents, array $context, string $basis, int $decimals): array
    {
        $mode = $policy['mode'];
        if ($mode === 'none') {
            return ['eligible' => false, 'progress' => null];
        }
        $coupon = !empty($context['coupon']);
        if ($mode === 'coupon') {
            return ['eligible' => $coupon, 'progress' => null];
        }
        $threshold = Decimal::parse($policy['threshold']);
        $metric = $mode === 'quantity' ? 'quantity' : 'amount';
        $measure = $metric === 'quantity' ? 'quantity' : ($policy['before_discount'] ? 'price_before_discount' : 'price');
        $used = $contents->measure($measure, $basis);
        $reached = $used !== null && $used >= $threshold;
        $eligible = match ($mode) {
            'amount_or_coupon' => $reached || $coupon,
            'amount_and_coupon' => $reached && $coupon,
            default => $reached,
        };

        $progress = null;
        if (!$eligible && $policy['notice'] && $used !== null && $used < $threshold
            && ($mode !== 'amount_and_coupon' || $coupon)) {
            $used = max(0, $used);
            $remaining = $threshold - $used;
            if ($metric === 'amount') {
                $factor = 10 ** (Decimal::PRECISION - $decimals);
                $remaining = Decimal::multiplyWhole($factor, Decimal::steps($remaining, $factor, 'up'));
            }
            $progress = [
                'remaining' => Decimal::format($remaining, $metric === 'amount' ? $decimals : null),
                'percent' => min(99, intdiv(Decimal::divide($used, $threshold), intdiv(Decimal::SCALE, 100))),
                'metric' => $metric,
            ];
        }
        return ['eligible' => $eligible, 'progress' => $progress];
    }

    private static function result(
        bool $available,
        int $cost,
        int $decimals,
        string $reason,
        array $trace = [],
        bool $free = false,
        ?array $progress = null
    ): array {
        return [
            'available' => $available,
            'cost' => $available ? Decimal::format($cost, $decimals) : '0',
            'reason' => $reason,
            'trace' => $trace,
            'progress' => $progress,
            'free' => $free,
        ];
    }
}
