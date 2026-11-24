<?php

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are returned as JSON data or rendered with textContent.

namespace CodeOn\ShippingRules\Import;

final class TierCsv
{
    public static function export(array $config): string
    {
        if (!$config['rules']) {
            throw new \InvalidArgumentException(__('Add at least one pricing tier before exporting to CSV.', 'codeon-shipping-rules'));
        }

        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations -- CSV is processed through a temporary PHP stream with no caller-controlled path.
        $file = fopen('php://temp', 'w+b');
        try {
            fputcsv($file, ['title', 'label', 'condition', 'operator', 'min', 'max', 'min_exclusive', 'max_exclusive', 'values', 'field', 'base', 'action', 'scope'], ',', '"', '');
            foreach ($config['rules'] as $rule) {
                if (count($rule['conditions']) > 1 || $rule['charges']) {
                    throw new \InvalidArgumentException(__('This method uses complex rules. Export JSON to keep every condition and charge.', 'codeon-shipping-rules'));
                }
                $condition = $rule['conditions'][0] ?? ['type' => 'always'];
                $values = $condition['values'] ?? [];
                if (array_filter($values, static fn ($value): bool => str_contains((string) $value, '|'))) {
                    throw new \InvalidArgumentException(__('Export JSON to preserve condition values that contain a vertical bar.', 'codeon-shipping-rules'));
                }

                fputcsv($file, [
                    self::textForCell($config['title']),
                    self::textForCell($rule['label']),
                    $condition['type'],
                    $condition['operator'] ?? '',
                    self::textForCell($condition['min'] ?? ''),
                    self::textForCell($condition['max'] ?? ''),
                    empty($condition['min_exclusive']) ? '0' : '1',
                    empty($condition['max_exclusive']) ? '0' : '1',
                    self::textForCell(implode('|', $values)),
                    $condition['field'] ?? '',
                    $rule['base'],
                    $rule['action'],
                    $rule['scope'],
                ], ',', '"', '');
            }

            rewind($file);
            return (string) stream_get_contents($file);
        } finally {
            fclose($file);
            // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations
        }
    }

    public static function textFromCell(string $value): string
    {
        return preg_match("/^'[=+@\\-\\t\\r]/", $value) ? substr($value, 1) : $value;
    }

    private static function textForCell(string $value): string
    {
        // Prevent a label or selection value from becoming a spreadsheet formula.
        return preg_match('/^[=+@\-\t\r]/', $value) ? "'" . $value : $value;
    }
}
