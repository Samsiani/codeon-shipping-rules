<?php

declare(strict_types=1);

namespace CodeOn\ShippingRules\Rules;

use InvalidArgumentException;

/** A set of cart lines and its lazily computed measurements. */
final class Contents
{
    /** @var array<string, int|null> */
    private array $measurements = [];

    public function __construct(public readonly array $lines, private readonly int $divisor)
    {
    }

    public static function from(mixed $lines, int $divisor): self
    {
        $contents = [];
        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (is_array($line) && (self::number($line['quantity'] ?? '1') ?? 0) > 0) {
                    $contents[] = $line;
                }
            }
        }
        return new self($contents, $divisor);
    }

    public function subset(array $indexes): self
    {
        if (count($indexes) === count($this->lines)) {
            return $this;
        }
        return new self(array_intersect_key($this->lines, $indexes), $this->divisor);
    }

    public function measure(string $type, string $valueBasis): ?int
    {
        $key = $type . ':' . $valueBasis;
        if (!array_key_exists($key, $this->measurements)) {
            $this->measurements[$key] = $this->calculate($type, $valueBasis);
        }
        return $this->measurements[$key];
    }

    private function calculate(string $type, string $valueBasis): ?int
    {
        if ($type === 'line_count') {
            return Decimal::multiplyWhole(Decimal::SCALE, count($this->lines));
        }
        if ($type === 'dimensional_weight') {
            $weight = $this->measure('weight', $valueBasis);
            $volume = $this->measure('volume', $valueBasis);
            return $weight === null || $volume === null ? null : max($weight, Decimal::divide($volume, $this->divisor));
        }
        $sum = 0;
        foreach ($this->lines as $line) {
            $quantity = self::number($line['quantity'] ?? '1');
            $value = match ($type) {
                'quantity' => $quantity,
                'price' => self::number($valueBasis === 'gross' ? ($line['gross'] ?? $line['net'] ?? null) : ($line['net'] ?? null)),
                'gross_price' => self::number($line['gross'] ?? $line['net'] ?? null),
                'price_before_discount' => self::number(
                    $valueBasis === 'gross'
                        ? ($line['gross_before_discount'] ?? $line['gross'] ?? $line['net_before_discount'] ?? $line['net'] ?? null)
                        : ($line['net_before_discount'] ?? $line['net'] ?? null)
                ),
                'gross_before_discount' => self::number($line['gross_before_discount'] ?? $line['gross'] ?? $line['net_before_discount'] ?? $line['net'] ?? null),
                'volume', 'weight' => self::physical($line, $type),
                default => null,
            };
            if ($value === null) {
                return null;
            }
            if ($type === 'volume' || $type === 'weight') {
                $value = Decimal::multiply($value, $quantity ?? 0);
            }
            $sum = Decimal::add($sum, $value);
        }
        return $sum;
    }

    public static function number(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Decimal::parse($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function physical(array $line, string $type): ?int
    {
        if (!empty($line['virtual'])) {
            return in_array($type, ['weight', 'volume'], true) ? 0 : null;
        }
        if (in_array($type, ['weight', 'length', 'width', 'height'], true)) {
            $value = self::number($line[$type] ?? null);
            return $value !== null && $value >= 0 ? $value : null;
        }
        $length = self::physical($line, 'length');
        $width = self::physical($line, 'width');
        $height = self::physical($line, 'height');
        if ($length === null || $width === null || $height === null) {
            return null;
        }
        return match ($type) {
            'volume' => Decimal::multiply(Decimal::multiply($length, $width), $height),
            'max_dimension' => max($length, $width, $height),
            'total_dimensions' => Decimal::add(Decimal::add($length, $width), $height),
            default => null,
        };
    }
}
