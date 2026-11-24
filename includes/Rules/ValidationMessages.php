<?php

declare(strict_types=1);

namespace CodeOn\ShippingRules\Rules;

/** Translate known domain errors where they cross into the WordPress UI. */
final class ValidationMessages
{
    public static function localize(string $message): string
    {
        if (preg_match('/\ARule ([0-9]+): (.*)\z/Ds', $message, $parts) === 1) {
            /* translators: 1: the rule number, 2: a validation message. */
            return sprintf(__('Rule %1$d — %2$s', 'codeon-shipping-rules'), (int) $parts[1], self::issue($parts[2]));
        }
        return self::issue($message);
    }

    private static function issue(string $message): string
    {
        $known = match ($message) {
            'Minimum cost cannot exceed maximum cost.' => __('Minimum cost cannot exceed maximum cost.', 'codeon-shipping-rules'),
            'Duplicate rule ID.' => __('Duplicate rule ID.', 'codeon-shipping-rules'),
            'Rule ID may contain only letters, numbers, underscores and hyphens.' =>
                __('Rule ID may contain only letters, numbers, underscores and hyphens.', 'codeon-shipping-rules'),
            'Use a public product field key that starts with a letter.' =>
                __('Use a public product field key that starts with a letter.', 'codeon-shipping-rules'),
            'Select at least one condition value.' => __('Select at least one condition value.', 'codeon-shipping-rules'),
            'Days of week must use numbers 1 through 7.' => __('Days of week must use numbers 1 through 7.', 'codeon-shipping-rules'),
            'The condition range is empty.' => __('The condition range is empty.', 'codeon-shipping-rules'),
            'Shipping calculation exceeds the supported numeric range.' =>
                __('Shipping calculation exceeds the supported numeric range.', 'codeon-shipping-rules'),
            default => null,
        };
        if ($known !== null) {
            return $known;
        }
        if (preg_match('/\AUnknown condition type: (.*)\.\z/Ds', $message, $parts) === 1) {
            /* translators: %s: a configured condition type. */
            return sprintf(__('Unknown condition type “%s”.', 'codeon-shipping-rules'), $parts[1]);
        }
        $endings = [
            /* translators: %s: a field name. */
            ' must be a valid decimal number.' => __('Enter a decimal number in “%s”.', 'codeon-shipping-rules'),
            /* translators: %s: a field name. */
            ' cannot be negative.' => __('The value in “%s” cannot be negative.', 'codeon-shipping-rules'),
            /* translators: %s: a field name. */
            ' must be greater than zero.' => __('Enter a value greater than zero in “%s”.', 'codeon-shipping-rules'),
            /* translators: %s: a field name. */
            ' must use the 24-hour HH:MM format.' => __('Use HH:MM in “%s”.', 'codeon-shipping-rules'),
            /* translators: %s: a field name. */
            ' must be text.' => __('Enter text in “%s”.', 'codeon-shipping-rules'),
            /* translators: %s: a field name. */
            ' is too long.' => __('Shorten the value in “%s”.', 'codeon-shipping-rules'),
            /* translators: %s: a field name. */
            ' must be a non-negative integer.' => __('Enter a whole number equal to or greater than zero in “%s”.', 'codeon-shipping-rules'),
            /* translators: %s: a field name. */
            ' must be a list.' => __('Select a list of values in “%s”.', 'codeon-shipping-rules'),
            /* translators: %s: a field name. */
            ' must be an object.' => __('Check the fields in “%s”.', 'codeon-shipping-rules'),
        ];
        foreach ($endings as $ending => $format) {
            if (!str_ends_with($message, $ending)) {
                continue;
            }
            $field = self::field(substr($message, 0, -strlen($ending)));
            if ($field !== null) {
                return sprintf($format, $field);
            }
        }
        if (preg_match('/\AInvalid (.+) setting\.\z/Ds', $message, $parts) === 1) {
            $field = self::field($parts[1]);
            if ($field !== null) {
                /* translators: %s: a field name. */
                return sprintf(__('Choose a valid value for “%s”.', 'codeon-shipping-rules'), $field);
            }
        }
        return $message;
    }

    private static function field(string $name): ?string
    {
        return match ($name) {
            'Method title' => __('Method name', 'codeon-shipping-rules'),
            'Method description' => __('Checkout description', 'codeon-shipping-rules'),
            'Delivery time' => __('Estimated delivery time', 'codeon-shipping-rules'),
            'Logo ID' => __('Media attachment ID', 'codeon-shipping-rules'),
            'Tax status' => __('Shipping tax', 'codeon-shipping-rules'),
            'Value basis' => __('Order value basis', 'codeon-shipping-rules'),
            'Method scope' => __('Calculate from', 'codeon-shipping-rules'),
            'Aggregation' => __('Combine matching rules', 'codeon-shipping-rules'),
            'cost_includes_tax' => __('Amounts already include shipping tax', 'codeon-shipping-rules'),
            'default' => __('Prefer this method when no option is selected', 'codeon-shipping-rules'),
            'logged_in_only' => __('Require a signed-in customer', 'codeon-shipping-rules'),
            'min_cost' => __('Minimum shipping charge', 'codeon-shipping-rules'),
            'max_cost' => __('Maximum shipping charge', 'codeon-shipping-rules'),
            'fallback' => __('Fallback charge', 'codeon-shipping-rules'),
            'Dimensional divisor' => __('Dimensional weight divisor', 'codeon-shipping-rules'),
            'Target rate IDs', 'Target rate ID' => __('Target rate IDs', 'codeon-shipping-rules'),
            'Rules' => __('Rate rules', 'codeon-shipping-rules'),
            'Rule', 'Rule ID', 'Rule label' => __('Rule name', 'codeon-shipping-rules'),
            'Conditions', 'Condition' => __('Conditions', 'codeon-shipping-rules'),
            'Condition type' => __('Other condition', 'codeon-shipping-rules'),
            'Condition operator' => __('Comparison', 'codeon-shipping-rules'),
            'Condition logic' => __('Match conditions', 'codeon-shipping-rules'),
            'Condition values', 'Condition value' => __('Values', 'codeon-shipping-rules'),
            'Rule scope' => __('Rule scope', 'codeon-shipping-rules'),
            'Rule action' => __('After a match', 'codeon-shipping-rules'),
            'Base charge' => __('Base charge', 'codeon-shipping-rules'),
            'Charge', 'Charges', 'Charge type' => __('Price adjustments', 'codeon-shipping-rules'),
            'Charge basis' => __('Based on', 'codeon-shipping-rules'),
            'Charge amount' => __('Amount', 'codeon-shipping-rules'),
            'Charge step' => __('Increment size', 'codeon-shipping-rules'),
            'Charge rounding' => __('Round increments', 'codeon-shipping-rules'),
            'Product field' => __('Product field key', 'codeon-shipping-rules'),
            'Lower bound exclusivity', 'Lower bound' => __('From', 'codeon-shipping-rules'),
            'Upper bound exclusivity', 'Upper bound' => __('To', 'codeon-shipping-rules'),
            'Start time' => __('From time', 'codeon-shipping-rules'),
            'End time' => __('To time', 'codeon-shipping-rules'),
            'Free shipping' => __('Free shipping', 'codeon-shipping-rules'),
            'Free shipping mode' => __('Offer free shipping when', 'codeon-shipping-rules'),
            'Free shipping scope' => __('Threshold applies to', 'codeon-shipping-rules'),
            'Free shipping threshold' => __('Minimum value', 'codeon-shipping-rules'),
            'Free shipping before discount' => __('Use the value before discounts', 'codeon-shipping-rules'),
            'Free shipping label' => __('Free rate label', 'codeon-shipping-rules'),
            'Free shipping notice' => __('Show progress toward free shipping', 'codeon-shipping-rules'),
            default => null,
        };
    }
}
