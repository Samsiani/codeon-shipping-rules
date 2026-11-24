<?php

declare(strict_types=1);

namespace CodeOn\ShippingRules\WooCommerce;

/** Builds the storefront label for a quoted rate. */
final class RateLabel
{
    /**
     * Uses the free label when free shipping applies, or for any zero-cost quote when the method asks for it.
     * The `append` format matches Flexible Shipping: "Standard (Free)".
     */
    public static function compose(string $title, string $freeLabel, array $free, array $quote): string
    {
        $zeroCost = !empty($free['label_on_zero']) && !empty($quote['available']) && (float) ($quote['cost'] ?? 0) === 0.0;
        if ($freeLabel === '' || (empty($quote['free']) && !$zeroCost)) {
            return $title;
        }
        if (($free['label_format'] ?? 'replace') === 'append' && $title !== '') {
            return $title . ' (' . $freeLabel . ')';
        }
        return $freeLabel;
    }
}
