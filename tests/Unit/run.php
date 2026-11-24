<?php

declare(strict_types=1);

use CodeOn\ShippingRules\Import\Importer;
use CodeOn\ShippingRules\Import\TierCsv;
use CodeOn\ShippingRules\Rules\Decimal;
use CodeOn\ShippingRules\Rules\Diagnostics;
use CodeOn\ShippingRules\Rules\Evaluator;
use CodeOn\ShippingRules\Rules\Schema;
use CodeOn\ShippingRules\Rules\ValidationMessages;
use CodeOn\ShippingRules\Updates\GitHubUpdater;
use CodeOn\ShippingRules\WooCommerce\RateLabel;

spl_autoload_register(static function (string $class): void {
    $prefix = 'CodeOn\\ShippingRules\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__, 2) . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

function __(string $text, string $domain): string
{
    return $text;
}

const MINUTE_IN_SECONDS = 60;
const HOUR_IN_SECONDS = 3600;

function sanitize_text_field(string $text): string
{
    return trim(strip_tags($text));
}

function get_option(string $key): string|false
{
    return $key === 'woocommerce_tax_display_cart' ? 'excl' : false;
}

$passed = 0;
function equal(string $name, mixed $expected, mixed $actual): void
{
    global $passed;
    if ($actual !== $expected) {
        throw new RuntimeException($name . "\nExpected: " . var_export($expected, true) . "\nGot: " . var_export($actual, true));
    }
    ++$passed;
}

function rejects(string $name, array $input): void
{
    try {
        Schema::normalize($input);
    } catch (InvalidArgumentException) {
        equal($name, true, true);
        return;
    }
    equal($name, true, false);
}

function rule(string $base, array $conditions = [], array $extra = []): array
{
    return $extra + ['base' => $base, 'conditions' => $conditions];
}

function between(string $type, string $min = '', string $max = '', array $extra = []): array
{
    return $extra + ['type' => $type, 'min' => $min, 'max' => $max];
}

function selection(string $type, array $values, string $operator = 'any', string $field = ''): array
{
    return compact('type', 'values', 'operator', 'field');
}

$standard = [
    'product_id' => 42,
    'variation_id' => 88,
    'sku' => 'SKU-88',
    'quantity' => '1',
    'net' => '50',
    'gross' => '60',
    'net_before_discount' => '55',
    'gross_before_discount' => '66',
    'weight' => '8',
    'length' => '5',
    'width' => '5',
    'height' => '5',
    'categories' => ['standard'],
    'tags' => ['everyday'],
    'shipping_class' => 'general',
    'stock_quantity' => '0',
    'stock_status' => 'onbackorder',
    'fields' => ['brand' => 'Makita', 'handling_weight' => '5'],
];
$fragile = [
    'product_id' => 19,
    'quantity' => '1',
    'net' => '50',
    'gross' => '60',
    'net_before_discount' => '55',
    'gross_before_discount' => '66',
    'weight' => '2',
    'length' => '50',
    'width' => '30',
    'height' => '20',
    'categories' => ['fragile'],
    'tags' => ['glass'],
    'shipping_class' => 'breakable',
    'stock_quantity' => null,
    'stock_status' => 'instock',
];
$context = [
    'package' => [$standard, $fragile],
    'cart' => [$standard, $fragile],
    'logged_in' => true,
    'roles' => ['customer', 'wholesale'],
    'day' => 1,
    'time' => '02:15',
    'currency' => 'GEL',
    'decimals' => 2,
    'destination' => [
        'country' => 'GE', 'state' => 'TB', 'city' => 'Tbilisi', 'postcode' => '01 42',
        'municipality' => 'tbilisi', 'settlement' => 'gldani',
    ],
];
$quote = static fn(array $rules, array $configuration = [], array $overrides = []): array =>
    Evaluator::quote(Schema::normalize(['rules' => $rules] + $configuration), $overrides + $context, true);

equal('Decimal shorthand normalization', '-0.15', Decimal::format(Decimal::parse('-.15')));
equal('Decimal half-up', '1.01', Decimal::format(Decimal::parse('1.005'), 2));
equal('Negative half-up', '-1.01', Decimal::format(Decimal::parse('-1.005'), 2));
equal('Fixed-point multiplication', '0.075', Decimal::format(Decimal::multiply(Decimal::parse('50'), Decimal::parse('0.0015'))));
equal('Large fixed-point product without float', '1234567891.2345679',
    Decimal::format(Decimal::multiply(Decimal::parse('10'), Decimal::parse('123456789.123456789'))));
equal('Eight fractional division places', '0.66666667', Decimal::format(Decimal::divide(Decimal::parse('2'), Decimal::parse('3'))));

equal('Empty configuration keeps the native method unavailable', 'no_matching_rule', $quote([])['reason']);
equal('An empty package is unavailable', 'empty_package', $quote([rule('1')], [], ['package' => []])['reason']);
equal('Explicit fallback', '7.00', $quote([], ['fallback' => '7'])['cost']);
equal('Matching zero fee publishes a rate', true, $quote([rule('0')])['available']);
equal('Guest restrictions', 'login_required', $quote([rule('9')], ['logged_in_only' => true], ['logged_in' => false])['reason']);

$bands = [rule('2', [between('price', '', '100')]), rule('3', [between('price', '100')])];
equal('Both inclusive limits match', '5.00', $quote($bands)['cost']);
$bands[0]['conditions'][0]['max_exclusive'] = true;
equal('The exclusive upper bound avoids a collision', '3.00', $quote($bands)['cost']);
equal('Gross value basis', '5.00', $quote([rule('5', [between('price', '120', '120')])], ['value_basis' => 'gross'])['cost']);
equal('Post-discount value basis', '5.00', $quote([rule('5', [between('price_before_discount', '110', '110')])])['cost']);

$weightStep = ['type' => 'step', 'basis' => 'weight', 'amount' => '3', 'step' => '1'];
$mixed = $quote([
    rule('0', [between('weight', '2', '2'), selection('category', ['fragile'])], ['charges' => [$weightStep]]),
    rule('5', [between('weight', '10', '10')]),
]);
equal('Compound price is based on the applicable line subset', '11.00', $mixed['cost']);
equal('Each rule starts with the original scope', 2, $mixed['trace'][1]['line_count']);
equal('AND selector intersection is required', false, $quote([
    rule('1', [selection('category', ['fragile']), selection('tag', ['everyday'])]),
])['available']);
equal('OR selector uses the union', '30.00', $quote([
    rule('0', [selection('category', ['fragile']), selection('tag', ['everyday'])], ['logic' => 'any', 'charges' => [$weightStep]]),
])['cost']);
equal('All requested categories present', true, $quote([rule('1', [selection('category', ['standard', 'fragile'], 'all')])])['available']);
equal('Only rejects extra lines', false, $quote([rule('1', [selection('category', ['standard'], 'only')])])['available']);
equal('None preserves the full eligible scope', 2, $quote([rule('1', [selection('category', ['rare'], 'none')])])['trace'][0]['line_count']);
equal('Any assigned shipping class selects only products that have a class', '24.00', $quote([
    rule('0', [selection('shipping_class', ['assigned'])], ['charges' => [$weightStep]]),
], [], ['package' => [$standard, ['weight' => '2', 'shipping_class' => 0]]])['cost']);
equal('Only assigned classes excludes a mixed package', false, $quote([
    rule('1', [selection('shipping_class', ['assigned'], 'only')]),
], [], ['package' => [$standard, ['weight' => '2', 'shipping_class' => 0]]])['available']);

$single = ['package' => [['quantity' => '1', 'weight' => '2.1']]];
equal('Each started kilogram adds the step amount', '19.00', $quote([rule('10', [], ['charges' => [$weightStep]])], [], $single)['cost']);
$roundDown = ['rounding' => 'down'] + $weightStep;
equal('Configurable step rounding down', '16.00', $quote([rule('10', [], ['charges' => [$roundDown]])], [], $single)['cost']);
$roundNearest = ['rounding' => 'nearest'] + $weightStep;
equal('Configurable step rounding to nearest unit', '19.00', $quote([rule('10', [], ['charges' => [$roundNearest]])], [], [
    'package' => [['quantity' => '1', 'weight' => '2.5']],
])['cost']);
equal('An exact weight step never rounds up an extra step', '16.00', $quote([rule('10', [], ['charges' => [$weightStep]])], [], [
    'package' => [['quantity' => '1', 'weight' => '2.0']],
])['cost']);
equal('Fractions of a currency subunit are accumulated before rounding', '7.50', $quote([
    rule('0', [selection('product', [42])], ['charges' => [['type' => 'step', 'basis' => 'price', 'amount' => '0.0015', 'step' => '0.01']]]),
])['cost']);

$percent = ['type' => 'percent', 'basis' => 'running_cost', 'amount' => '10'];
$price = $quote([
    rule('20'),
    rule('0', [between('running_cost', '20', '20')], ['charges' => [$percent], 'action' => 'stop']),
    rule('0', [], ['action' => 'hide']),
], ['max_cost' => '21']);
equal('Running cost basis and cap', '21.00', $price['cost']);
equal('Priced stop rule retains its cost and ends evaluation', '22', $price['totals']['calculated']);
equal('Hide removes the method', 'hidden', $quote([rule('10'), rule('0', [], ['action' => 'hide'])])['reason']);
equal('Lowest aggregation', '2.00', $quote([rule('5'), rule('2'), rule('6')], ['aggregation' => 'lowest'])['cost']);
equal('Highest aggregation', '6.00', $quote([rule('5'), rule('2'), rule('6')], ['aggregation' => 'highest'])['cost']);
equal('First match wins', '5.00', $quote([rule('5'), rule('2'), rule('6')], ['aggregation' => 'first'])['cost']);
equal('Minimum charge applies to paid matched methods', '7.00', $quote([rule('5')], ['min_cost' => '7'])['cost']);
equal('Negative rate floor', '0.00', $quote([rule('-5')])['cost']);
equal('Unmatched provider quote is preserved', '14.00', $quote([rule('3', [between('weight', '15')])], ['max_cost' => '4'], ['base_rate' => '14'])['cost']);
equal('Provider quote seeds the running-cost calculation', '15.40',
    $quote([rule('0', [between('running_cost', '14', '14')], ['charges' => [$percent]])], [], ['base_rate' => '14'])['cost']);
equal('Provider applied exactly once with lowest adjustment aggregation', '16.00',
    $quote([rule('4'), rule('2')], ['aggregation' => 'lowest'], ['base_rate' => '14'])['cost']);

$manySelectors = [
    selection('product', [42]), selection('variation', [88]), selection('sku', ['sku-88']),
    selection('shipping_class', ['general']), selection('stock_status', ['onbackorder']),
    between('stock_quantity', '0', '0'), selection('product_field', ['makita'], 'any', 'brand'),
    between('product_field_range', '1', '10', ['field' => 'handling_weight']),
    selection('role', ['wholesale']), selection('weekday', [1]), selection('country', ['GE']),
    selection('state', ['TB']), selection('city', ['Tbilisi']), selection('postcode', ['01*']),
    selection('municipality', ['tbilisi']), selection('settlement', ['gldani']), selection('currency', ['GEL']),
    between('time', '23:00', '04:00'),
];
equal('Product, customer, location, inventory, and overnight conditions combine', '8.00', $quote([rule('8', $manySelectors)])['cost']);
equal('Unknown stock cannot match stock zero', false, $quote([rule('8', [between('stock_quantity', '0', '0')])], [], ['package' => [$fragile]])['available']);
equal('Longest edge selects the fragile line', '9.00', $quote([rule('9', [between('max_dimension', '50', '50')])])['cost']);
equal('Total overall dimensions are product level', '9.00', $quote([rule('9', [between('total_dimensions', '100', '100')])])['cost']);
equal('Length, width and height conditions', '9.00', $quote([rule('9', [between('length', '50'), between('width', '30'), between('height', '20')])])['cost']);
equal('Volume multiplies dimensions and item quantities', '9.00', $quote([rule('9', [between('volume', '30125', '30125')])])['cost']);
equal('Dimensional weight chooses a higher actual parcel weight', '9.00', $quote([rule('9', [between('dimensional_weight', '10', '10')])])['cost']);
equal('Dimensional weight may be above actual parcel weight', '9.00', $quote([rule('9', [between('dimensional_weight', '30.125', '30.125')])], ['dim_divisor' => '1000'])['cost']);
equal('Missing physical measures differ from zero', 'missing_measurement',
    $quote([rule('9', [], ['charges' => [$weightStep]])], [], ['package' => [['weight' => null]]])['trace'][0]['reason']);
equal('Negated range excludes the inside', false,
    $quote([rule('9', [between('weight', '9', '11', ['operator' => 'not_between'])])])['available']);

$free = ['mode' => 'amount', 'threshold' => '110', 'notice' => true];
equal('Amount progress is package-aware and has currency precision', ['remaining' => '10.00', 'percent' => 90, 'metric' => 'amount'],
    $quote([rule('9')], ['free' => $free])['progress']);
equal('Before discount reaches the threshold', true, $quote([rule('9')], ['free' => ['before_discount' => true] + $free])['free']);
equal('An otherwise unmatched free method stays unavailable', false, $quote([], ['free' => ['before_discount' => true] + $free])['available']);
equal('Coupon required for AND mode', false,
    $quote([rule('9')], ['free' => ['mode' => 'amount_and_coupon', 'threshold' => '100']])['free']);
equal('Coupon unlocks the reached amount in AND mode', '0.00',
    $quote([rule('9')], ['free' => ['mode' => 'amount_and_coupon', 'threshold' => '100']], ['coupon' => true])['cost']);
equal('Coupon unlocks OR mode before the threshold', true,
    $quote([rule('9')], ['free' => ['mode' => 'amount_or_coupon', 'threshold' => '150']], ['coupon' => true])['free']);
equal('Quantity progress', ['remaining' => '2', 'percent' => 50, 'metric' => 'quantity'],
    $quote([rule('9')], ['free' => ['mode' => 'quantity', 'threshold' => '4', 'notice' => true]])['progress']);
equal('An intrinsically free method has no unnecessary progress notice', null,
    $quote([rule('0')], ['free' => $free])['progress']);
equal('Zero-decimal currency rounds remaining progress up', '1',
    $quote([rule('9')], ['free' => ['threshold' => '100.01'] + $free], ['decimals' => 0])['progress']['remaining']);

$virtual = ['product_id' => 99, 'quantity' => '1', 'net' => '25', 'virtual' => true];
$split = ['package' => [$fragile], 'cart' => [$fragile, $standard, $virtual]];
equal('Each package can use its own subset', '2.00',
    $quote([rule('2', [between('price', '50', '50')])], [], $split)['cost']);
equal('Whole-cart rules include merchandise in another package and virtual lines', '6.00',
    $quote([rule('6', [between('price', '125', '125'), between('weight', '10', '10')])], ['scope' => 'cart'], $split)['cost']);
equal('Rule scope can override the rate scope', '6.00',
    $quote([rule('6', [between('price', '125', '125')], ['scope' => 'cart'])], [], $split)['cost']);

rejects('Unknown condition cannot silently publish', ['rules' => [rule('5', [['type' => 'secret']])]]);
rejects('Invalid shipping divisor cannot publish', ['dim_divisor' => '0']);
rejects('Invalid charge step cannot publish', ['rules' => [rule('5', [], ['charges' => [['step' => '0'] + $weightStep]])]]);
rejects('Invalid monetary punctuation cannot publish', ['max_cost' => '.']);
rejects('Rounding must not emit a decimal that cannot be read back', ['max_cost' => '999999999.999999999']);
equal('Locale decimal separator produces a canonical rule amount', '5.2', Schema::normalize(['fallback' => ' 5,20 '])['fallback']);
rejects('Duplicate IDs cannot publish', ['rules' => [rule('1', [], ['id' => 'dup']), rule('1', [], ['id' => 'dup'])]]);
rejects('Inverted caps cannot publish', ['min_cost' => '10', 'max_cost' => '9']);
rejects('Empty exclusive range cannot publish', ['rules' => [rule('5', [between('price', '2', '2', ['max_exclusive' => true])])]]);
rejects('Protected WooCommerce product fields are not available in ad hoc predicates', [
    'rules' => [rule('1', [selection('product_field', ['x'], 'any', '_protected')])],
]);
equal('Normalized config is stable across reads', Schema::defaults(), Schema::normalize(Schema::defaults()));

$analyze = static fn (array $rules, array $configuration = []): array =>
    array_column(Diagnostics::analyze(Schema::normalize(['rules' => $rules] + $configuration)), 'code');
equal('Inclusive overlap diagnostics', ['bands_overlap'], $analyze([
    rule('2', [between('price', '', '100')]), rule('3', [between('price', '100')]),
]));
equal('Adjacent currency amounts form continuous display bands', [], $analyze([
    rule('2', [between('price', '', '100')]), rule('3', [between('price', '100.01')]),
]));
equal('A one-cent gap in display bands', ['bands_gap'], $analyze([
    rule('2', [between('price', '', '100')]), rule('3', [between('price', '100.02')]),
]));
equal('No proven gap with a configured fallback', [], $analyze([
    rule('2', [between('price', '', '100')]), rule('3', [between('price', '100.02')]),
], ['fallback' => '1']));
equal('Free threshold outside otherwise eligible tariff coverage', ['free_threshold_gap'], $analyze([
    rule('2', [between('price', '', '149.99')]),
], ['free' => ['mode' => 'amount', 'threshold' => '150', 'scope' => 'package']]));

equal('The UI adapter uses a human-readable error field', 'Rule 2 — Enter a decimal number in “Maximum shipping charge”.',
    ValidationMessages::localize('Rule 2: max_cost must be a valid decimal number.'));
equal('Translation adapter preserves unrelated messages', 'Translated or unrelated issue.',
    ValidationMessages::localize('Translated or unrelated issue.'));

$importer = new Importer();
$flexible = $importer->parse('flexible', json_encode([
    'shipping_methods' => [[
        'method_title' => 'Reference delivery',
        'cart_calculation' => 'package',
        'method_rules' => [[
            'cost_per_order' => '5,50',
            'conditions' => [['condition_id' => 'weight', 'min' => '2', 'max' => '3']],
            'additional_costs' => [['based_on' => 'weight', 'additional_cost' => '1.25', 'per_value' => '1']],
            'special_action' => 'stop',
        ]],
    ]],
], JSON_THROW_ON_ERROR));
equal('Flexible JSON imports a supported method without issues', [], $flexible['methods'][0]['issues']);
$imported = $flexible['methods'][0]['config'];
equal('Flexible JSON preserves package scope', 'package', $imported['scope']);
equal('Flexible JSON preserves decimal, weight steps and stop action', '9.25',
    Evaluator::quote($imported, ['package' => [['quantity' => 1, 'weight' => '2.1']]])['cost']);
equal('Flexible JSON preserves stop outcome', 'stop',
    Evaluator::quote($imported, ['package' => [['quantity' => 1, 'weight' => '2.1']]], true)['trace'][0]['action']);

$exported = Schema::normalize(['title' => '=Tariff fixture', 'rules' => [
    rule('4', [between('price', '', '50', ['max_exclusive' => true])], ['label' => '+Lower band']),
    rule('7', [between('price', '50')], ['label' => 'Upper band', 'action' => 'stop']),
]]);
$csv = TierCsv::export($exported);
equal('CSV exporter shields a spreadsheet formula title', true, str_contains($csv, "'=Tariff fixture"));
$roundTrip = $importer->parse('csv', $csv);
equal('CodeOn CSV reimports without issues', [], $roundTrip['methods'][0]['issues']);
$restored = $roundTrip['methods'][0]['config'];
equal('CodeOn CSV restores protected text values', ['=Tariff fixture', '+Lower band'], [$restored['title'], $restored['rules'][0]['label']]);
equal('CodeOn CSV tiers preserve lower endpoint behavior', '4.00', Evaluator::quote($restored, ['package' => [['net' => '49.99']]])['cost']);
equal('CodeOn CSV tiers preserve exclusive boundary behavior', '7.00', Evaluator::quote($restored, ['package' => [['net' => '50.00']]])['cost']);

foreach ([
    ['json', '{ invalid', 'The file contains invalid JSON.'],
    ['csv', "title,base\nToo few\n", 'CSV row 2 has the wrong number of columns.'],
] as [$format, $data, $expected]) {
    try {
        $importer->parse($format, $data);
    } catch (InvalidArgumentException $error) {
        equal('Malformed ' . $format . ' import reports the problem', $expected, $error->getMessage());
        continue;
    }
    throw new RuntimeException('Malformed ' . $format . ' unexpectedly imported.');
}
$unknown = $importer->parse('flexible', '{"method_title":"Unknown test","method_rules":[{"conditions":[{"condition_id":"unsupported"}]}]}');
equal('Unsupported source condition yields a per-method import issue', null, $unknown['methods'][0]['config']);
equal('Unsupported source condition is named in the import issue', true, str_contains($unknown['methods'][0]['issues'][0], 'unsupported'));

// A Flexible Shipping weight table as stored in its method settings: a free band first, then weight tiers with gaps.
$tier = static fn (string $min, string $max, string $cost): array => [
    'conditions' => [['condition_id' => 'weight', 'operator' => 'is', 'min' => $min, 'max' => $max]],
    'cost_per_order' => $cost,
    'additional_costs' => [],
    'special_action' => 'none',
];
$weightTable = $importer->parse('flexible', json_encode(['shipping_methods' => [[
    'method_title' => 'Standard',
    'method_description' => 'Priced by total weight.',
    'method_free_shipping' => '',
    'method_free_shipping_requires' => 'order_amount',
    'method_free_shipping_label' => 'Free',
    'method_calculation_method' => 'lowest',
    'cart_calculation' => 'cart',
    'tax_status' => 'none',
    'prices_include_tax' => 'no',
    'method_visibility' => 'no',
    'method_rules' => json_encode([
        [
            'conditions' => [
                ['condition_id' => 'value', 'operator' => 'is', 'min' => '100.01', 'max' => ''],
                ['condition_id' => 'weight', 'operator' => 'is', 'min' => '0', 'max' => '50'],
            ],
            'cost_per_order' => '0',
            'additional_costs' => [],
            'special_action' => 'stop',
        ],
        $tier('0', '3.5', '6.2'),
        $tier('3.51', '10', '12'),
        $tier('10.1', '20', '21.5'),
        $tier('20.1', '50', '36'),
        $tier('50.1', '100', '60.5'),
        $tier('100.1', '99999', '84.5'),
    ]),
]]], JSON_THROW_ON_ERROR));
equal('Flexible weight table imports without issues', [], $weightTable['methods'][0]['issues']);
$weightConfig = $weightTable['methods'][0]['config'];
equal('Flexible weight table keeps its method settings', ['lowest', 'cart', 'none', false, 'net', 'none'], [
    $weightConfig['aggregation'], $weightConfig['scope'], $weightConfig['tax_status'],
    $weightConfig['cost_includes_tax'], $weightConfig['value_basis'], $weightConfig['free']['mode'],
]);
equal('Flexible free label is shown in brackets on every zero-cost rate', ['Free', true, 'append'], [
    $weightConfig['free']['label'], $weightConfig['free']['label_on_zero'], $weightConfig['free']['label_format'],
]);
foreach ([
    ['50', '0', '6.20'],
    ['100', '2', '6.20'],
    ['100.01', '2', '0.00'],
    ['150', '50', '0.00'],
    ['150', '50.05', null],
    ['150', '60', '60.50'],
    ['20', '3.5', '6.20'],
    ['20', '3.505', null],
    ['20', '3.51', '12.00'],
    ['20', '10', '12.00'],
    ['20', '10.05', null],
    ['20', '10.1', '21.50'],
    ['20', '20.1', '36.00'],
    ['20', '100.1', '84.50'],
    ['20', '100000', null],
] as [$value, $weight, $expected]) {
    $quote = Evaluator::quote($weightConfig, ['package' => [['quantity' => 1, 'net' => $value, 'weight' => $weight]], 'decimals' => 2]);
    equal('Flexible weight table matches at ' . $value . ' and ' . $weight . ' kg', $expected, $quote['available'] ? $quote['cost'] : null);
}
equal('Flexible import counts a missing weight as zero', 'zero', $weightConfig['missing_measurements']);
equal('A product without a weight is priced as zero kilograms', '6.20',
    Evaluator::quote($weightConfig, ['package' => [['quantity' => 1, 'net' => '50', 'weight' => null]], 'decimals' => 2])['cost']);
equal('Two products, one without a weight, are priced by the known weight', '12.00',
    Evaluator::quote($weightConfig, ['package' => [['quantity' => 1, 'net' => '10', 'weight' => null], ['quantity' => 2, 'net' => '20', 'weight' => '2']], 'decimals' => 2])['cost']);
$strict = Schema::normalize(['rules' => [rule('5', [between('weight', '0', '10')])]]);
equal('A method hides its rate for a missing weight by default', 'unavailable', $strict['missing_measurements']);
equal('A default method has no rate for a product without a weight', false,
    Evaluator::quote($strict, ['package' => [['quantity' => 1, 'weight' => null]]])['available']);
rejects('An unknown missing-measurement policy is rejected', ['missing_measurements' => 'guess']);
equal('Flexible weight table rounds half up at a shop precision of zero', '22',
    Evaluator::quote($weightConfig, ['package' => [['quantity' => 1, 'net' => '20', 'weight' => '15']], 'decimals' => 0])['cost']);
$prefixed = $importer->parse('flexible', json_encode(['method_title' => 'Gross', 'prices_include_tax' => 'yes', 'method_rules' => []]));
equal('Flexible prices that include tax keep that setting', true, $prefixed['methods'][0]['config']['cost_includes_tax']);

$appendOnZero = ['label' => 'Free', 'label_on_zero' => true, 'label_format' => 'append'];
equal('Zero-cost rate gets the free label in brackets', 'Standard (Free)',
    RateLabel::compose('Standard', 'Free', $appendOnZero, ['available' => true, 'cost' => '0', 'free' => false]));
equal('Paid rate keeps its title', 'Standard',
    RateLabel::compose('Standard', 'Free', $appendOnZero, ['available' => true, 'cost' => '6', 'free' => false]));
equal('Free-shipping rate replaces the title by default', 'Free',
    RateLabel::compose('Standard', 'Free', ['label' => 'Free'], ['available' => true, 'cost' => '0', 'free' => true]));
equal('Zero-cost rate keeps its title unless the method asks for the label', 'Standard',
    RateLabel::compose('Standard', 'Free', ['label' => 'Free', 'label_on_zero' => false], ['available' => true, 'cost' => '0.00', 'free' => false]));
equal('An empty free label never changes the title', 'Standard',
    RateLabel::compose('Standard', '', $appendOnZero, ['available' => true, 'cost' => '0', 'free' => true]));

$updateUri = 'https://github.com/Samsiani/codeon-shipping-rules';
$manifest = [
    'slug' => 'codeon-shipping-rules',
    'name' => 'CodeOn Shipping Rules for WooCommerce',
    'version' => '1.2.3',
    'package' => $updateUri . '/releases/download/v1.2.3/codeon-shipping-rules-1.2.3.zip',
    'sha256' => str_repeat('ab', 32),
    'requires' => '6.8',
    'requires_php' => '8.1',
    'tested' => '7.1',
    'released' => '2026-01-01T00:00:00Z',
    'sections' => ['changelog' => '<ul><li>Fix</li></ul>', 'bad' => ['not', 'text']],
];
$release = GitHubUpdater::validate($manifest, $updateUri);
equal('Release manifest yields the version and package', ['1.2.3', $manifest['package']], [$release['version'] ?? null, $release['package'] ?? null]);
equal('Release manifest keeps only text sections', ['changelog'], array_keys($release['sections'] ?? []));
foreach ([
    'another repository' => ['package' => 'https://github.com/someone/else/releases/download/v1.2.3/codeon-shipping-rules-1.2.3.zip'],
    'a tag that differs from the version' => ['version' => '1.2.4'],
    'a malformed checksum' => ['sha256' => 'not-a-checksum'],
    'another plugin' => ['slug' => 'other-plugin'],
    'a non-numeric version' => ['version' => '1.2.3-beta', 'package' => $updateUri . '/releases/download/v1.2.3-beta/codeon-shipping-rules-1.2.3-beta.zip'],
] as $case => $override) {
    equal('Release manifest pointing to ' . $case . ' is ignored', null, GitHubUpdater::validate($override + $manifest, $updateUri));
}
equal('A missing manifest is ignored', null, GitHubUpdater::validate(null, $updateUri));

echo sprintf("Passed %d rule, configuration, arithmetic, and import assertions.\n", $passed);
