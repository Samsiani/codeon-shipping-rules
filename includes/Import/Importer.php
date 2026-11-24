<?php

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are returned as JSON data or rendered with textContent.

namespace CodeOn\ShippingRules\Import;

use CodeOn\ShippingRules\Rules\Schema;
use CodeOn\ShippingRules\Rules\ValidationMessages;
use InvalidArgumentException;

final class Importer
{
    public function parse(string $format, string $content): array
    {
        if (strlen($content) > 1_000_000) {
            throw new InvalidArgumentException(__('Import files can be up to 1 MB.', 'codeon-shipping-rules'));
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $first = substr(ltrim($content), 0, 1);
        if ($format === 'csv' || ($format === 'flexible' && !in_array($first, ['[', '{'], true))) {
            [$rows, $isFlexible] = $this->csv($content, $format === 'flexible');
        } elseif (in_array($format, ['json', 'flexible'], true)) {
            [$rows, $isFlexible] = $this->json($content, $format === 'flexible');
        } else {
            throw new InvalidArgumentException(__('Choose a supported import format.', 'codeon-shipping-rules'));
        }

        if (!$rows || count($rows) > 50) {
            throw new InvalidArgumentException(__('Import a file containing between 1 and 50 shipping methods.', 'codeon-shipping-rules'));
        }

        $methods = [];
        $converter = new Flexible();
        foreach (array_values($rows) as $index => $method) {
            $config = null;
            $issues = [];
            $title = __('Imported shipping method', 'codeon-shipping-rules') . ' ' . ($index + 1);
            if (is_array($method)) {
                $candidate = $method[$isFlexible ? 'method_title' : 'title'] ?? '';
                $title = is_string($candidate) && $candidate !== '' ? sanitize_text_field($candidate) : $title;
                try {
                    $config = Schema::normalize($isFlexible ? $converter->convert($method) : $method);
                } catch (InvalidArgumentException $error) {
                    $issues[] = ValidationMessages::localize($error->getMessage());
                }
            } else {
                $issues[] = __('This entry is not a shipping method object.', 'codeon-shipping-rules');
            }

            $methods[] = ['title' => $title, 'config' => $config, 'issues' => $issues];
        }

        return ['methods' => $methods, 'issues' => []];
    }

    private function json(string $content, bool $flexible): array
    {
        try {
            $data = json_decode($content, true, 40, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new InvalidArgumentException(__('The file contains invalid JSON.', 'codeon-shipping-rules'), 0, $error);
        }
        if (!is_array($data)) {
            throw new InvalidArgumentException(__('The JSON file must contain a rules configuration.', 'codeon-shipping-rules'));
        }
        if (($data['format'] ?? '') === 'codeon-shipping-rules') {
            if (($data['version'] ?? 0) !== 1 || !is_array($data['methods'] ?? null)) {
                throw new InvalidArgumentException(__('This CodeOn export version is unsupported.', 'codeon-shipping-rules'));
            }
            return [$data['methods'], false];
        }
        if ($flexible) {
            if (isset($data['shipping_methods']) && is_array($data['shipping_methods'])) {
                return [$data['shipping_methods'], true];
            }
            if (isset($data['rules_table']['rules']) && is_array($data['rules_table']['rules'])) {
                return [[['method_title' => __('Imported rules', 'codeon-shipping-rules'), 'method_rules' => $data['rules_table']['rules']]], true];
            }
            return [array_is_list($data) ? $data : [$data], true];
        }
        throw new InvalidArgumentException(__('Choose a CodeOn export or select the Flexible Shipping import format.', 'codeon-shipping-rules'));
    }

    private function csv(string $content, bool $flexible): array
    {
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations -- CSV is processed through a temporary PHP stream with no caller-controlled path.
        $file = fopen('php://temp', 'w+b');
        fwrite($file, $content);
        rewind($file);
        try {
            $firstLine = (string) fgets($file);
            rewind($file);
            $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
            $columns = fgetcsv($file, 0, $delimiter, '"', '');
            $columns = array_map(static fn ($value): string => strtolower(trim((string) $value)), $columns ?: []);
            $flexible = $flexible || in_array('method title', $columns, true);
            $required = $flexible ? ['method title', 'cost per order'] : ['title', 'base'];
            if (array_diff($required, $columns)) {
                throw new InvalidArgumentException(__('The CSV header is missing required columns.', 'codeon-shipping-rules'));
            }
            $grouped = [];
            $line = 1;
            while (($cells = fgetcsv($file, 0, $delimiter, '"', '')) !== false) {
                $line++;
                if ($line > 1001) {
                    throw new InvalidArgumentException(__('CSV imports can contain up to 1,000 rows.', 'codeon-shipping-rules'));
                }
                if ($cells === [null]) {
                    continue;
                }
                if (count($cells) !== count($columns)) {
                    /* translators: %d: CSV line number. */
                    throw new InvalidArgumentException(sprintf(__('CSV row %d has the wrong number of columns.', 'codeon-shipping-rules'), $line));
                }
                $row = array_combine($columns, $cells);
                if ($flexible) {
                    $this->addFlexibleCsvRow($grouped, $row, $line);
                } else {
                    $this->addCodeOnCsvRow($grouped, $row, $line);
                }
            }
            return [array_values($grouped), $flexible];
        } finally {
            fclose($file);
            // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations
        }
    }

    private function addCodeOnCsvRow(array &$grouped, array $row, int $line): void
    {
        $title = TierCsv::textFromCell(trim($row['title']));
        if ($title === '') {
            throw new InvalidArgumentException(__('Every CSV row needs a method title.', 'codeon-shipping-rules'));
        }
        $grouped[$title] ??= ['title' => $title, 'rules' => []];
        $condition = ($row['condition'] ?? '') ?: 'always';
        $shape = Schema::catalog()['conditions'][$condition]['kind'] ?? '';
        $grouped[$title]['rules'][] = [
            'id' => 'csv-' . $line,
            'label' => TierCsv::textFromCell($row['label'] ?? ''),
            'base' => Flexible::decimal($row['base']),
            'action' => ($row['action'] ?? '') ?: 'continue',
            'scope' => ($row['scope'] ?? '') ?: 'method',
            'conditions' => [[
                'type' => $condition ?: 'always',
                'operator' => ($row['operator'] ?? '') ?: ($shape === 'selection' ? 'any' : 'between'),
                'min' => Flexible::decimal(TierCsv::textFromCell($row['min'] ?? '')),
                'max' => Flexible::decimal(TierCsv::textFromCell($row['max'] ?? '')),
                'min_exclusive' => ($row['min_exclusive'] ?? '') === '1',
                'max_exclusive' => ($row['max_exclusive'] ?? '') === '1',
                'values' => array_values(array_filter(array_map('trim', explode('|', TierCsv::textFromCell($row['values'] ?? ''))), static fn ($value) => $value !== '')),
                'field' => $row['field'] ?? '',
            ]],
        ];
    }

    private function addFlexibleCsvRow(array &$grouped, array $row, int $line): void
    {
        $title = trim($row['method title']);
        if ($title === '') {
            throw new InvalidArgumentException(__('Every Flexible Shipping CSV row needs a method title.', 'codeon-shipping-rules'));
        }
        $key = ($row['shipping method id'] ?? $row['method id'] ?? '') . ':' . $title;
        $grouped[$key] ??= [
            'method_title' => $title,
            'method_description' => $row['method description'] ?? '',
            'method_free_shipping' => self::number($row, 'free shipping'),
            'method_max_cost' => self::number($row, 'maximum cost'),
            'method_calculation_method' => $row['calculation method'] ?? 'sum',
            'cart_calculation' => $row['cart calculation'] ?? 'cart',
            'method_default' => $row['default'] ?? '',
            'method_visibility' => $row['visibility'] ?? '',
            'method_rules' => [],
        ];
        $rule = [
            'id' => 'csv-' . $line,
            'based_on' => $row['based on'] ?? 'none',
            'min' => self::number($row, 'min'),
            'max' => self::number($row, 'max'),
            'cost_per_order' => self::number($row, 'cost per order'),
            'cost_additional' => self::number($row, 'additional cost'),
            'per_value' => self::number($row, 'value'),
            'shipping_class' => isset($row['shipping class']) ? preg_split('/[|,]/', $row['shipping class'], -1, PREG_SPLIT_NO_EMPTY) : [],
            'stop' => $row['stop'] ?? '',
            'cancel' => $row['cancel'] ?? '',
        ];
        $grouped[$key]['method_rules'][] = $rule;
    }

    private static function number(array $row, string $column): string
    {
        return Flexible::decimal($row[$column] ?? '');
    }
}
