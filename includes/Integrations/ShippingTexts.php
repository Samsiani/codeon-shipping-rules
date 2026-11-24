<?php

declare(strict_types=1);

namespace CodeOn\ShippingRules\Integrations;

final class ShippingTexts
{
    private const GROUP = 'CodeOn Shipping Rules';

    public static function register(int $id, array $config): void
    {
        $texts = [
            'title' => $config['title'],
            'description' => $config['description'],
            'delivery_time' => $config['delivery_time'],
            'free_label' => $config['free']['label'],
        ];
        foreach ($texts as $field => $text) {
            if ($text === '') {
                continue;
            }
            $key = self::key($id, $field);
            do_action('wpml_register_single_string', self::GROUP, $key, $text);
            if (function_exists('pll_register_string')) {
                pll_register_string($key, $text, self::GROUP);
            }
        }
    }

    public static function get(int $id, string $field, string $text): string
    {
        if ($text === '') {
            return '';
        }
        $translated = apply_filters('wpml_translate_single_string', $text, self::GROUP, self::key($id, $field));
        if (function_exists('pll__')) {
            $translated = pll__((string) $translated);
        }

        return wp_strip_all_tags((string) apply_filters('codeon_shipping_rules_text', $translated, $field, $id, $text));
    }

    private static function key(int $id, string $field): string
    {
        return 'method-' . $id . '-' . $field;
    }
}
