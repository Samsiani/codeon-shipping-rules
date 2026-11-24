<?php

declare(strict_types=1);

namespace CodeOn\ShippingRules\Rules;

use InvalidArgumentException;
use OverflowException;

/** Fixed-point arithmetic for currency and measurements without a PHP extension. */
final class Decimal
{
    public const SCALE = 100_000_000;
    public const PRECISION = 8;

    public static function parse(mixed $value): int
    {
        if (is_float($value) && is_finite($value)) {
            $value = sprintf('%.8F', $value);
        }
        if (!is_int($value) && !is_string($value)) {
            throw new InvalidArgumentException('Expected a decimal number.');
        }
        $value = trim((string) $value);
        if (preg_match('/\A[+-]?\.[0-9]/', $value) === 1) {
            $offset = $value[0] === '.' ? 0 : 1;
            $value = substr_replace($value, '0', $offset, 0);
        }
        if (preg_match('/\A([+-]?)([0-9]{1,9})(?:\.([0-9]{0,32}))?\z/D', $value, $parts) !== 1) {
            throw new InvalidArgumentException('Expected a decimal number with up to nine whole digits.');
        }
        $fraction = str_pad($parts[3] ?? '', self::PRECISION + 1, '0');
        $scaled = (int) $parts[2] * self::SCALE + (int) substr($fraction, 0, self::PRECISION);
        if ((int) $fraction[self::PRECISION] >= 5) {
            ++$scaled;
        }
        if ($scaled >= 1_000_000_000 * self::SCALE) {
            throw new InvalidArgumentException('Expected a decimal number with up to nine whole digits.');
        }
        return $parts[1] === '-' ? -$scaled : $scaled;
    }

    public static function add(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < -PHP_INT_MAX - $right)) {
            throw new OverflowException('Shipping calculation exceeds the supported numeric range.');
        }
        return $left + $right;
    }

    public static function multiply(int $left, int $right): int
    {
        $negative = ($left < 0) !== ($right < 0);
        $left = abs($left);
        $right = abs($right);
        $fraction = $left % self::SCALE;

        // Split operands at the decimal point so fractional products fit in an integer.
        $whole = self::multiplyWhole(intdiv($left, self::SCALE), $right);
        $scaledFraction = self::multiplyWhole($fraction, intdiv($right, self::SCALE));
        $fractionProduct = $fraction * ($right % self::SCALE);
        $scaledFraction = self::add($scaledFraction, self::divideRounded($fractionProduct, self::SCALE));
        $result = self::add($whole, $scaledFraction);

        return $negative ? -$result : $result;
    }

    public static function multiplyWhole(int $value, int $whole): int
    {
        if ($whole !== 0 && abs($value) > intdiv(PHP_INT_MAX, abs($whole))) {
            throw new OverflowException('Shipping calculation exceeds the supported numeric range.');
        }
        return $value * $whole;
    }

    public static function divide(int $value, int $divisor): int
    {
        if ($divisor <= 0 || $divisor > intdiv(PHP_INT_MAX, 10)) {
            throw new InvalidArgumentException('Expected a positive divisor within the supported numeric range.');
        }
        $absolute = abs($value);
        $scaled = intdiv($absolute, $divisor);
        $remainder = $absolute % $divisor;
        for ($place = 0; $place < self::PRECISION; ++$place) {
            $remainder *= 10;
            $scaled = self::add(self::multiplyWhole($scaled, 10), intdiv($remainder, $divisor));
            $remainder %= $divisor;
        }
        if ($remainder >= $divisor - $remainder) {
            $scaled = self::add($scaled, 1);
        }
        return $value < 0 ? -$scaled : $scaled;
    }

    public static function divideRounded(int $value, int $divisor): int
    {
        $absolute = abs($value);
        $remainder = $absolute % $divisor;
        $quotient = intdiv($absolute, $divisor) + (int) ($remainder >= $divisor - $remainder);
        return $value < 0 ? -$quotient : $quotient;
    }

    public static function steps(int $value, int $step, string $rounding): int
    {
        $value = max(0, $value);
        $whole = intdiv($value, $step);
        $remainder = $value % $step;
        return match ($rounding) {
            'down' => $whole,
            'nearest' => $whole + (int) ($remainder >= $step - $remainder),
            default => $whole + (int) ($remainder > 0),
        };
    }

    public static function format(int $value, ?int $decimals = null): string
    {
        $places = $decimals === null ? self::PRECISION : max(0, min(self::PRECISION, $decimals));
        $factor = 10 ** (self::PRECISION - $places);
        $rounded = self::divideRounded($value, $factor);
        $digits = (string) abs($rounded);
        if ($places > 0) {
            $digits = str_pad($digits, $places + 1, '0', STR_PAD_LEFT);
            $digits = substr($digits, 0, -$places) . '.' . substr($digits, -$places);
            if ($decimals === null) {
                $digits = rtrim(rtrim($digits, '0'), '.');
            }
        }
        return ($rounded < 0 ? '-' : '') . $digits;
    }
}
