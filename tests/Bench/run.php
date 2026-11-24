<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use CodeOn\ShippingRules\Rules\Evaluator;
use CodeOn\ShippingRules\Rules\Schema;

$lines = [];
for ($i = 1; $i <= 25; $i++) {
    $lines[] = [
        'product_id' => $i,
        'quantity' => 2,
        'weight' => '1.1',
        'net' => '5',
        'gross' => '5',
        'shipping_class' => $i % 3,
    ];
}
$rules = [];
for ($i = 1; $i <= 100; $i++) {
    $rules[] = [
        'id' => 'rule-' . $i,
        'base' => '0.15',
        'conditions' => [
            ['type' => 'shipping_class', 'operator' => 'any', 'values' => [(string) ($i % 3)]],
            ['type' => 'weight', 'operator' => 'between', 'min' => '1', 'max' => '50'],
        ],
        'charges' => [['type' => 'step', 'basis' => 'weight', 'amount' => '0.25', 'step' => '2']],
    ];
}
$config = Schema::normalize(['rules' => $rules]);
$context = ['package' => $lines, 'cart' => $lines, 'currency' => 'USD', 'decimals' => 2];
$samples = [];
for ($i = 0; $i < 300; $i++) {
    $begin = hrtime(true);
    $quote = Evaluator::quote($config, $context);
    if (!$quote['available']) {
        throw new RuntimeException('The benchmark must evaluate an available rate.');
    }
    if ($i >= 20) {
        $samples[] = (hrtime(true) - $begin) / 1_000_000;
    }
}
sort($samples);
$median = $samples[(int) floor(count($samples) * 0.5)];
$p95 = $samples[(int) ceil(count($samples) * 0.95) - 1];
printf("100 rules × 25 lines, trace off, %d samples, PHP %s\n", count($samples), PHP_VERSION);
printf("Median %.2f ms, p95 %.2f ms, peak PHP memory %.2f MiB\n", $median, $p95, memory_get_peak_usage(true) / 1048576);
