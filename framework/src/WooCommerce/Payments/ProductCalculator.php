<?php

declare(strict_types=1);

namespace CodeOn\Framework\WooCommerce\Payments;

/**
 * Monthly-payment widget for the single product page.
 *
 * One bank per widget — each installment plugin registers its own, with
 * its own logo, rate, terms and colour (the "no cross-bank aggregation"
 * rule: two banks on one shop show two widgets). Two kinds:
 *
 *  - `loan`:  amortised monthly payment ({@see InstallmentEstimator::monthlyPayment()}),
 *             layouts `line` | `card` | `calculator`;
 *  - `split`: interest-free pay-in-N, layouts `line` | `card` | `schedule`.
 *
 * Settings are fields of the calling gateway ({@see formFields()}), saved
 * in its `woocommerce_<id>_settings` option. The widget is off until the
 * merchant ticks it. The plugin says whether its gateway can be offered
 * and with which rate/terms ({@see register()}'s callback); this class
 * reads the product price, renders, and the script keeps the amounts in
 * step with the variation the customer picks.
 *
 * Figures for a loan are an estimate; the bank's own page carries the
 * binding numbers, and the widget says so.
 */
final class ProductCalculator
{
    public const KIND_LOAN  = 'loan';
    public const KIND_SPLIT = 'split';

    public const POSITION_AUTO      = 'auto';
    public const POSITION_SHORTCODE = 'shortcode';

    /** Option keys inside the gateway's settings. */
    public const OPT_ENABLED  = 'product_widget';
    public const OPT_LAYOUT   = 'product_widget_layout';
    public const OPT_POSITION = 'product_widget_position';
    public const OPT_COLOR    = 'product_widget_color';
    public const OPT_PARTS    = 'product_widget_parts';
    public const OPT_MIN      = 'product_widget_min';
    public const OPT_MAX      = 'product_widget_max';

    public const DEFAULT_LAYOUT = 'card';

    /** Term buttons on the card layout, when the bank allows them. */
    private const CARD_TERMS = [6, 12, 24, 36, 48];
    private const CARD_MAX_BUTTONS = 6;

    private const HANDLE = 'codeon-product-calculator';

    /**
     * Cache-bust version for assets/css|js/codeon-product-calculator.*.
     * Bump when either file changes.
     */
    private const ASSET_VERSION = '0.3.24';

    /** @return list<string> */
    public static function layouts(string $kind): array
    {
        return $kind === self::KIND_SPLIT
            ? ['line', 'card', 'schedule']
            : ['line', 'card', 'calculator'];
    }

    /**
     * Settings fields for the gateway's form, appended after its own.
     *
     * @param string $kind       KIND_LOAN or KIND_SPLIT.
     * @param string $bankColour The bank's colour, shown as the default.
     * @param string $shortcode  This gateway's shortcode tag.
     * @param string $textdomain The calling plugin's textdomain.
     * @param bool   $ownLimits  Add "number of payments" + price range fields
     *                           (for a gateway that has no min/max of its own).
     * @return array<string,array<string,mixed>>
     */
    public static function formFields(string $kind, string $bankColour, string $shortcode, string $textdomain, bool $ownLimits = false): array
    {
        $layouts = $kind === self::KIND_SPLIT
            ? [
                'line'     => __('Line — one line under the price', $textdomain),
                'card'     => __('Card — the payments side by side', $textdomain),
                'schedule' => __('Schedule — the payment dates', $textdomain),
            ]
            : [
                'line'       => __('Line — one line under the price', $textdomain),
                'card'       => __('Card — monthly amount with term buttons', $textdomain),
                'calculator' => __('Calculator — monthly amount with a term slider', $textdomain),
            ];

        $fields = [
            'product_widget_section' => [
                'title'       => __('Monthly payment on the product page', $textdomain),
                'type'        => 'title',
                'description' => __('Shows the payment per month under the product price, with the bank\'s logo. While it is on, it replaces the product-page badge.', $textdomain),
            ],
            self::OPT_ENABLED => [
                'title'   => __('Product-page widget', $textdomain),
                'type'    => 'checkbox',
                'label'   => __('Show the monthly payment on product pages', $textdomain),
                'default' => 'no',
            ],
            self::OPT_LAYOUT => [
                'title'   => __('Layout', $textdomain),
                'type'    => 'select',
                'default' => self::DEFAULT_LAYOUT,
                'options' => $layouts,
            ],
            self::OPT_POSITION => [
                'title'       => __('Position', $textdomain),
                'type'        => 'select',
                'default'     => self::POSITION_AUTO,
                'options'     => [
                    self::POSITION_AUTO      => __('Under the product price', $textdomain),
                    self::POSITION_SHORTCODE => __('Only where I add the shortcode', $textdomain),
                ],
                'description' => sprintf(
                    /* translators: %s: shortcode, e.g. [codeon_bog_installment_calculator] */
                    __('Shortcode: %s — for product pages built with a page builder (Elementor, WoodMart layouts) that leave out the standard product summary.', $textdomain),
                    '<code>[' . esc_html($shortcode) . ']</code>'
                ),
            ],
            self::OPT_COLOR => [
                'title'       => __('Colour', $textdomain),
                'type'        => 'color',
                'default'     => '',
                'placeholder' => $bankColour,
                'css'         => 'width:7em;',
                'description' => sprintf(
                    /* translators: %s: hex colour, e.g. #ff671d */
                    __('Colour of the selected term and the slider. Leave empty for the bank\'s colour (%s), or enter your shop\'s colour to match your theme.', $textdomain),
                    $bankColour
                ),
            ],
        ];

        if ($ownLimits) {
            $fields[self::OPT_PARTS] = [
                'title'             => __('Number of payments', $textdomain),
                'type'              => 'number',
                'default'           => '4',
                'description'       => __('How many equal parts the price is split into.', $textdomain),
                'desc_tip'          => true,
                'custom_attributes' => ['step' => '1', 'min' => '2', 'max' => '24'],
            ];
            $fields[self::OPT_MIN] = [
                'title'             => __('Show from (GEL)', $textdomain),
                'type'              => 'number',
                'default'           => '0',
                'description'       => __('Lowest product price the widget shows for. 0 = no limit.', $textdomain),
                'desc_tip'          => true,
                'custom_attributes' => ['step' => '0.01', 'min' => '0'],
            ];
            $fields[self::OPT_MAX] = [
                'title'             => __('Show up to (GEL)', $textdomain),
                'type'              => 'number',
                'default'           => '0',
                'description'       => __('Highest product price the widget shows for. 0 = no limit.', $textdomain),
                'desc_tip'          => true,
                'custom_attributes' => ['step' => '0.01', 'min' => '0'],
            ];
        }

        return $fields;
    }

    /**
     * The saved settings of a gateway, read straight from its option so a
     * page with the widget off never builds the gateway objects.
     *
     * @return array{enabled:bool, layout:string, position:string, color:string, parts:int, min:float, max:float}
     */
    public static function settings(string $gatewayId, string $kind): array
    {
        $saved = function_exists('get_option') ? get_option('woocommerce_' . $gatewayId . '_settings', []) : [];
        $saved = is_array($saved) ? $saved : [];

        $layout = (string) ($saved[self::OPT_LAYOUT] ?? self::DEFAULT_LAYOUT);
        if (!in_array($layout, self::layouts($kind), true)) {
            $layout = self::DEFAULT_LAYOUT;
        }

        return [
            'enabled'  => ($saved[self::OPT_ENABLED] ?? 'no') === 'yes',
            'layout'   => $layout,
            'position' => ($saved[self::OPT_POSITION] ?? '') === self::POSITION_SHORTCODE ? self::POSITION_SHORTCODE : self::POSITION_AUTO,
            'color'    => self::hexColour((string) ($saved[self::OPT_COLOR] ?? '')) ?? '',
            'parts'    => max(2, min(24, (int) ($saved[self::OPT_PARTS] ?? 4))),
            'min'      => max(0.0, (float) ($saved[self::OPT_MIN] ?? 0)),
            'max'      => max(0.0, (float) ($saved[self::OPT_MAX] ?? 0)),
        ];
    }

    /** Whether the merchant turned the widget on for this gateway. */
    public static function isEnabled(string $gatewayId, string $kind = self::KIND_LOAN): bool
    {
        return self::settings($gatewayId, $kind)['enabled'];
    }

    /**
     * Wire one gateway's widget: under the product price (when its
     * position is automatic), as a shortcode, and its assets on product
     * pages.
     *
     * `$offer` is called only when the widget is on and about to render.
     * It returns null when the gateway can't be offered (disabled, no
     * licence, not configured…), else the bank's side of the render
     * arguments: `bank`, `label`, `logo`, `logo_width`, `logo_height`,
     * `bank_colour`, `textdomain`, `range_min`, `range_max` (else the
     * widget's own "show from / up to" settings), and for a loan `apr`,
     * `terms`, `default_term`, optional `multiplier`; for a split
     * optional `parts` (else the saved number of payments).
     *
     * @param callable():?array<string,mixed> $offer
     */
    public static function register(string $gatewayId, string $kind, string $shortcode, int $priority, callable $offer): void
    {
        add_action('woocommerce_single_product_summary', static function () use ($gatewayId, $kind, $offer): void {
            // Quick-view popups load the summary over AJAX, where the
            // stylesheet can't follow: no widget there.
            if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
                return;
            }
            $settings = self::settings($gatewayId, $kind);
            if (!$settings['enabled'] || $settings['position'] !== self::POSITION_AUTO) {
                return;
            }
            echo self::renderFor(self::currentProduct(), $gatewayId, $kind, $offer); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_*
        }, $priority);

        add_shortcode($shortcode, static function ($atts) use ($gatewayId, $kind, $offer): string {
            if (!self::settings($gatewayId, $kind)['enabled']) {
                return '';
            }
            $atts = is_array($atts) ? $atts : [];
            $id = absint($atts['product_id'] ?? ($atts['id'] ?? 0));
            $product = $id > 0 && function_exists('wc_get_product') ? wc_get_product($id) : self::currentProduct();
            // A product named by id must be public: no prices of drafts or private products.
            if ($id > 0 && $product instanceof \WC_Product && $product->get_status() !== 'publish') {
                return '';
            }
            return self::renderFor($product instanceof \WC_Product ? $product : null, $gatewayId, $kind, $offer);
        });

        add_action('wp_enqueue_scripts', static function () use ($gatewayId, $kind): void {
            if (function_exists('is_product') && is_product() && self::settings($gatewayId, $kind)['enabled']) {
                self::enqueueAssets();
            }
        });
    }

    /**
     * @param callable():?array<string,mixed> $offer
     */
    private static function renderFor(?\WC_Product $product, string $gatewayId, string $kind, callable $offer): string
    {
        if (!$product instanceof \WC_Product) {
            return '';
        }
        if (!function_exists('get_woocommerce_currency') || !Money::isGel((string) get_woocommerce_currency())) {
            return '';
        }
        $prices = self::productPrices($product);
        if ($prices === null) {
            return '';
        }
        $bank = $offer();
        if (!is_array($bank)) {
            return '';
        }
        $settings = self::settings($gatewayId, $kind);
        $accent = $settings['color'] !== ''
            ? $settings['color']
            : (self::hexColour((string) ($bank['bank_colour'] ?? '')) ?? '#111827');

        return self::render(array_merge($bank, [
            'kind'       => $kind,
            'layout'     => $settings['layout'],
            'product_id' => $product->get_id(),
            'price'      => $prices['price'],
            'min_price'  => $prices['min'],
            'max_price'  => $prices['max'],
            'accent'     => $accent,
            // A gateway without its own price limits uses the widget's.
            'range_min'  => (float) ($bank['range_min'] ?? $settings['min']),
            'range_max'  => (float) ($bank['range_max'] ?? $settings['max']),
            'parts'      => (int) ($bank['parts'] ?? $settings['parts']),
        ]));
    }

    private static function currentProduct(): ?\WC_Product
    {
        global $product;
        if ($product instanceof \WC_Product) {
            return $product;
        }
        $id = function_exists('get_the_ID') ? (int) get_the_ID() : 0;
        $found = $id > 0 && function_exists('wc_get_product') ? wc_get_product($id) : null;
        return $found instanceof \WC_Product ? $found : null;
    }

    /**
     * Display prices of a product (tax shown as the shop shows it): the one
     * the widget starts from, and the lowest and highest a variation has.
     * Null for products that can't be bought here (grouped, external, no price).
     *
     * @return array{price:float, min:float, max:float}|null
     */
    public static function productPrices(\WC_Product $product): ?array
    {
        if (in_array($product->get_type(), ['grouped', 'external'], true) || !$product->is_purchasable()) {
            return null;
        }
        if ($product instanceof \WC_Product_Variable) {
            $min = (float) $product->get_variation_price('min', true);
            $max = (float) $product->get_variation_price('max', true);
            return $max > 0 ? ['price' => $min, 'min' => $min, 'max' => $max] : null;
        }
        $price = (float) wc_get_price_to_display($product);
        return $price > 0 ? ['price' => $price, 'min' => $price, 'max' => $price] : null;
    }

    /**
     * Render the widget.
     *
     * @param array<string,mixed> $args kind, layout, product_id, price, min_price, max_price,
     *                                  range_min, range_max, accent, bank, label, logo,
     *                                  logo_width, logo_height, textdomain; loan: apr, terms,
     *                                  default_term, multiplier; split: parts.
     */
    public static function render(array $args): string
    {
        $kind = ($args['kind'] ?? self::KIND_LOAN) === self::KIND_SPLIT ? self::KIND_SPLIT : self::KIND_LOAN;
        $layout = (string) ($args['layout'] ?? self::DEFAULT_LAYOUT);
        if (!in_array($layout, self::layouts($kind), true)) {
            $layout = self::DEFAULT_LAYOUT;
        }
        $td = (string) ($args['textdomain'] ?? 'codeon-framework');
        $price = (float) ($args['price'] ?? 0);
        $minPrice = (float) ($args['min_price'] ?? $price);
        $maxPrice = (float) ($args['max_price'] ?? $price);
        $rangeMin = max(0.0, (float) ($args['range_min'] ?? 0));
        $rangeMax = max(0.0, (float) ($args['range_max'] ?? 0));
        // A loan's fee (Credo's handling fee) is part of the amount the
        // bank's limits apply to, as at checkout.
        $multiplier = $kind === self::KIND_LOAN ? max(1.0, (float) ($args['multiplier'] ?? 1.0)) : 1.0;

        if ($maxPrice <= 0 || !self::overlapsRange($minPrice * $multiplier, $maxPrice * $multiplier, $rangeMin, $rangeMax)) {
            return '';
        }

        self::enqueueAssets();

        $accent = self::hexColour((string) ($args['accent'] ?? '')) ?? '#111827';
        $hidden = !self::inRange($price * $multiplier, $rangeMin, $rangeMax);
        $dec = function_exists('wc_get_price_decimal_separator') ? (string) wc_get_price_decimal_separator() : '.';
        $thou = function_exists('wc_get_price_thousand_separator') ? (string) wc_get_price_thousand_separator() : ',';
        $money = static fn (float $v): string => number_format($v, 2, $dec, $thou);

        $config = [
            'kind'    => $kind,
            'product' => (int) ($args['product_id'] ?? 0),
            'price'   => round($price, 4),
            'rmin'    => $rangeMin,
            'rmax'    => $rangeMax,
            'dec'     => $dec,
            'thou'    => $thou,
        ];

        $bank = (string) ($args['bank'] ?? '');

        if ($kind === self::KIND_SPLIT) {
            $parts = max(2, min(24, (int) ($args['parts'] ?? 4)));
            $config['parts'] = $parts;
            $config['today'] = self::now()->format('Y-m-d');
            $body = self::renderSplit($layout, $args, $parts, $price, $money, $td);
        } else {
            $apr = max(0.0, (float) ($args['apr'] ?? InstallmentEstimator::DEFAULT_APR));
            $terms = self::cleanTerms((array) ($args['terms'] ?? InstallmentEstimator::DEFAULT_MONTHS));
            $default = (int) ($args['default_term'] ?? InstallmentEstimator::DEFAULT_PICK);
            if (!in_array($default, $terms, true)) {
                $default = $terms[intdiv(count($terms), 2)];
            }
            $shown = $layout === 'card' ? self::cardTerms($terms, $default) : $terms;
            $config['apr'] = $apr;
            $config['terms'] = $shown;
            $config['term'] = $default;
            $config['mult'] = $multiplier;
            /* translators: %d: number of months */
            $config['monthsText'] = __('%d months', $td);
            $monthly = InstallmentEstimator::monthlyPayment($price * $multiplier, $default, $apr);
            $body = self::renderLoan($layout, $shown, $default, $monthly, $money, $td);
        }

        $label = trim($bank . ' — ' . (string) ($args['label'] ?? ''), ' —');
        $style = '--codeon-pc-accent:' . $accent . ';--codeon-pc-tint:' . self::tint($accent, 0.1) . ';';

        $html = '<div class="codeon-pc codeon-pc--' . esc_attr($layout) . ' codeon-pc--' . esc_attr($kind) . '"'
            . ' role="group" aria-label="' . esc_attr($label) . '"'
            . ' style="' . esc_attr($style) . '"'
            . ' data-codeon-pc="' . esc_attr((string) wp_json_encode($config)) . '"'
            . ($hidden ? ' hidden' : '')
            . '>';

        $html .= $layout === 'line'
            ? self::logo($args, $bank) . $body
            : self::head($args, $bank) . $body;

        return $html . '</div>';
    }

    /**
     * @param list<int> $shown
     * @param callable(float):string $money
     */
    private static function renderLoan(string $layout, array $shown, int $default, float $monthly, callable $money, string $td): string
    {
        $amount = '<span data-pc-amount>' . esc_html($money($monthly)) . '</span> ₾';
        $note = '<p class="codeon-pc__note">' . esc_html(__('Approximate amount. The bank will confirm the exact terms.', $td)) . '</p>';
        /* translators: %d: number of months */
        $termLine = sprintf(__('Installment for %d months', $td), $default);

        if ($layout === 'line') {
            return '<span class="codeon-pc__text"><strong class="codeon-pc__strong">≈ ' . $amount . '</strong> '
                . '<span class="codeon-pc__rest">' . esc_html(__('per month', $td)) . ' · ' . esc_html($termLine) . '</span></span>';
        }

        if ($layout === 'card') {
            $captionId = self::uniqueId();
            $chips = '';
            foreach ($shown as $m) {
                $on = $m === $default;
                $chips .= '<button type="button" class="codeon-pc__chip' . ($on ? ' is-active' : '') . '"'
                    . ' data-pc-term="' . esc_attr((string) $m) . '"'
                    . ' aria-pressed="' . ($on ? 'true' : 'false') . '">' . esc_html((string) $m) . '</button>';
            }
            return '<div class="codeon-pc__amount" aria-live="polite"><span class="codeon-pc__big">' . $amount . '</span>'
                . '<span class="codeon-pc__unit">' . esc_html(__('per month', $td)) . '</span></div>'
                . '<div class="codeon-pc__terms">'
                . '<span class="codeon-pc__caption" id="' . esc_attr($captionId) . '">' . esc_html(__('Term, months', $td)) . '</span>'
                . '<div class="codeon-pc__chips" role="group" aria-labelledby="' . esc_attr($captionId) . '" style="--codeon-pc-cols:' . count($shown) . '">' . $chips . '</div>'
                . '</div>'
                . $note;
        }

        // Calculator: a slider over the allowed terms, evenly spaced.
        $count = count($shown);
        $index = (int) array_search($default, $shown, true);
        $fill = $count > 1 ? round($index / ($count - 1) * 100, 4) : 50;
        $marks = '';
        foreach ($shown as $m) {
            $marks .= '<span class="codeon-pc__mark' . ($m === $default ? ' is-active' : '') . '" data-pc-mark="' . esc_attr((string) $m) . '">' . esc_html((string) $m) . '</span>';
        }
        /* translators: %d: number of months */
        $valueText = sprintf(__('%d months', $td), $default);

        return '<div class="codeon-pc__stats">'
            . '<div class="codeon-pc__stat"><span class="codeon-pc__caption">' . esc_html(__('Term', $td)) . '</span>'
            . '<span class="codeon-pc__value"><span data-pc-term-value>' . esc_html((string) $default) . '</span> <span class="codeon-pc__unit">' . esc_html(__('months', $td)) . '</span></span></div>'
            . '<div class="codeon-pc__stat"><span class="codeon-pc__caption">' . esc_html(__('per month', $td)) . '</span>'
            . '<span class="codeon-pc__value" aria-live="polite">' . $amount . '</span></div>'
            . '</div>'
            . '<div class="codeon-pc__slider">'
            . '<input type="range" class="codeon-pc__range" data-pc-range min="0" max="' . esc_attr((string) max(0, $count - 1)) . '" step="1"'
            . ' value="' . esc_attr((string) $index) . '"'
            . ' aria-label="' . esc_attr(__('Term, months', $td)) . '"'
            . ' aria-valuetext="' . esc_attr($valueText) . '"'
            . ' style="--codeon-pc-fill:' . esc_attr((string) $fill) . '%" />'
            . '<div class="codeon-pc__marks" aria-hidden="true">' . $marks . '</div>'
            . '</div>'
            . $note;
    }

    /**
     * @param array<string,mixed> $args
     * @param callable(float):string $money
     */
    private static function renderSplit(string $layout, array $args, int $parts, float $price, callable $money, string $td): string
    {
        // Payments add up to the price: the first takes the leftover tetri.
        $split = self::splitAmounts($price, $parts);
        $part = '<span data-pc-part>' . esc_html($money($split['rest'])) . '</span> ₾';
        $first = '<span data-pc-first>' . esc_html($money($split['first'])) . '</span> ₾';
        $label = (string) ($args['label'] ?? '');

        if ($layout === 'line') {
            return '<span class="codeon-pc__text"><strong class="codeon-pc__strong">' . esc_html((string) $parts) . ' × ' . $part . '</strong> '
                . '<span class="codeon-pc__rest">· ' . esc_html($label) . ', 0%</span></span>';
        }

        if ($layout === 'card') {
            $items = '';
            for ($i = 0; $i < $parts; $i++) {
                $bars = '';
                for ($j = 0; $j < $parts; $j++) {
                    $bars .= '<span' . ($j <= $i ? ' class="is-on"' : '') . '></span>';
                }
                $items .= '<li class="codeon-pc__part">'
                    . '<span class="codeon-pc__bars" aria-hidden="true" style="--codeon-pc-bars:' . $parts . '">' . $bars . '</span>'
                    . '<span class="codeon-pc__part-amount">' . ($i === 0 ? $first : $part) . '</span>'
                    . '<span class="codeon-pc__caption">' . esc_html(self::whenLabel($i, $td)) . '</span>'
                    . '</li>';
            }
            /* translators: %d: number of payments */
            $title = sprintf(__('%d equal payments, 0%%', $td), $parts);
            return '<p class="codeon-pc__title">' . esc_html($title) . '</p>'
                . '<ol class="codeon-pc__parts" style="--codeon-pc-cols:' . min($parts, 4) . '">' . $items . '</ol>';
        }

        // Schedule: one row per payment date.
        $rows = '';
        for ($i = 0; $i < $parts; $i++) {
            $when = $i === 0 ? __('Today', $td) : self::dateLabel($i);
            $rows .= '<li class="codeon-pc__row' . ($i === 0 ? ' is-first' : '') . '">'
                . '<span class="codeon-pc__dot" aria-hidden="true"></span>'
                . '<span class="codeon-pc__date"' . ($i > 0 ? ' data-pc-date="' . $i . '"' : '') . '>' . esc_html($when) . '</span>'
                . '<span class="codeon-pc__row-amount">' . ($i === 0 ? $first : $part) . '</span>'
                . '</li>';
        }
        return '<ol class="codeon-pc__timeline">' . $rows . '</ol>'
            . '<div class="codeon-pc__total"><span>' . esc_html(__('Total, interest-free', $td)) . '</span>'
            . '<span class="codeon-pc__total-amount"><span data-pc-total>' . esc_html($money($price)) . '</span> ₾</span></div>';
    }

    /** @param array<string,mixed> $args */
    private static function head(array $args, string $bank): string
    {
        return '<div class="codeon-pc__head">' . self::logo($args, '')
            . '<span class="codeon-pc__bank">' . esc_html($bank) . '</span>'
            . '<span class="codeon-pc__label">' . esc_html((string) ($args['label'] ?? '')) . '</span></div>';
    }

    /** @param array<string,mixed> $args */
    private static function logo(array $args, string $alt): string
    {
        $src = (string) ($args['logo'] ?? '');
        if ($src === '') {
            return '';
        }
        $w = max(1, (int) ($args['logo_width'] ?? 24));
        $h = max(1, (int) ($args['logo_height'] ?? 24));
        return '<img class="codeon-pc__logo" src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '"'
            . ' width="' . $w . '" height="' . $h . '" decoding="async" />';
    }

    private static function whenLabel(int $index, string $td): string
    {
        if ($index === 0) {
            return __('Today', $td);
        }
        /* translators: %d: number of months from today */
        return sprintf(_n('In %d month', 'In %d months', $index, $td), $index);
    }

    /** Payment date `$months` from today, in the site's language. */
    private static function dateLabel(int $months): string
    {
        $timestamp = self::addMonths(self::now(), $months)->getTimestamp();
        return function_exists('wp_date') ? (string) wp_date('j F', $timestamp) : gmdate('j F', $timestamp);
    }

    private static function now(): \DateTimeImmutable
    {
        return function_exists('current_datetime') ? current_datetime() : new \DateTimeImmutable('now');
    }

    /**
     * Same day `$months` later; the month's last day when it is shorter
     * (31 January + 1 month = 28/29 February, not 3 March).
     */
    public static function addMonths(\DateTimeImmutable $from, int $months): \DateTimeImmutable
    {
        $first = $from->modify('first day of this month')->modify('+' . $months . ' months');
        $day = min((int) $from->format('j'), (int) $first->format('t'));
        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day);
    }

    /**
     * Terms as sorted, unique positive integers; the estimator's
     * standard list when none are given.
     *
     * @param array<int|string,mixed> $terms
     * @return list<int>
     */
    public static function cleanTerms(array $terms): array
    {
        $clean = [];
        foreach ($terms as $term) {
            $m = (int) $term;
            if ($m > 0 && $m <= 120) {
                $clean[$m] = $m;
            }
        }
        if ($clean === []) {
            return InstallmentEstimator::DEFAULT_MONTHS;
        }
        sort($clean, SORT_NUMERIC);
        return array_values($clean);
    }

    /**
     * Term buttons for the card layout: every allowed term when they fit,
     * else the usual ones (6, 12, 24, 36, 48) the bank allows, always
     * with the default term.
     *
     * @param list<int> $terms sorted
     * @return list<int>
     */
    public static function cardTerms(array $terms, int $default): array
    {
        if (count($terms) <= self::CARD_MAX_BUTTONS) {
            return $terms;
        }
        $picked = array_values(array_intersect($terms, self::CARD_TERMS));
        if (!in_array($default, $picked, true)) {
            $picked[] = $default;
        }
        if (count($picked) < 3) {
            return array_slice($terms, 0, self::CARD_MAX_BUTTONS);
        }
        $picked = array_unique($picked);
        sort($picked, SORT_NUMERIC);
        return array_values($picked);
    }

    /**
     * Equal payments in whole tetri that add up to the price; the first
     * payment takes what doesn't divide (100 in 3 → 33.34 + 33.33 + 33.33).
     *
     * @return array{first:float, rest:float}
     */
    public static function splitAmounts(float $price, int $parts): array
    {
        $parts = max(1, $parts);
        $cents = (int) round($price * 100);
        $rest = intdiv($cents, $parts);
        return ['first' => ($cents - $rest * ($parts - 1)) / 100, 'rest' => $rest / 100];
    }

    public static function inRange(float $price, float $min, float $max): bool
    {
        if ($price <= 0) {
            return false;
        }
        if ($min > 0 && $price < $min) {
            return false;
        }
        return !($max > 0 && $price > $max);
    }

    /** Whether any price between `$low` and `$high` falls in the range. */
    public static function overlapsRange(float $low, float $high, float $min, float $max): bool
    {
        if ($high <= 0) {
            return false;
        }
        if ($min > 0 && $high < $min) {
            return false;
        }
        return !($max > 0 && $low > $max);
    }

    /** `#rgb` / `#rrggbb` (any case) as lower-case `#rrggbb`, else null. */
    public static function hexColour(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^#([0-9a-f]{3})$/', $value, $m)) {
            return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }
        return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : null;
    }

    public static function tint(string $hex, float $alpha): string
    {
        $hex = self::hexColour($hex) ?? '#111827';
        return sprintf(
            'rgba(%d,%d,%d,%s)',
            hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)),
            hexdec(substr($hex, 5, 2)),
            rtrim(rtrim(number_format($alpha, 2, '.', ''), '0'), '.')
        );
    }

    private static function uniqueId(): string
    {
        return function_exists('wp_unique_id') ? wp_unique_id('codeon-pc-') : uniqid('codeon-pc-');
    }

    /**
     * Stylesheet + script. Called on `wp_enqueue_scripts` for product
     * pages with the widget on, and again from render() for shortcodes
     * elsewhere (printed in the footer then). Idempotent.
     */
    public static function enqueueAssets(): void
    {
        if (!function_exists('wp_enqueue_style') || !function_exists('plugin_dir_url')) {
            return;
        }
        // plugin_dir_url(__FILE__) → …/vendor/codeon/framework/src/WooCommerce/Payments/
        $base = rtrim((string) dirname(plugin_dir_url(__FILE__), 3), '/') . '/assets/';
        wp_enqueue_style(self::HANDLE, $base . 'css/' . self::HANDLE . '.css', [], self::ASSET_VERSION);
        wp_enqueue_script(self::HANDLE, $base . 'js/' . self::HANDLE . '.js', [], self::ASSET_VERSION, true);
    }
}
