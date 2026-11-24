<?php

/**
 * Exercise the shipping administration REST API in a disposable WooCommerce store.
 *
 * From an isolated WordPress installation with environment type local or development:
 * wp eval-file wp-content/plugins/codeon-shipping-rules/tests/Integration/rest-lifecycle.php --user=<id>
 *
 * The user must have the manage_woocommerce capability. The fixture creates its own
 * temporary shipping zone and removes it after running.
 */
if (!defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), ['local', 'development'], true)) {
	throw new RuntimeException('Run the shipping fixtures using WP-CLI on an isolated development store.');
}
if (!class_exists('WC_Shipping_Zone') || !current_user_can('manage_woocommerce')) {
	throw new RuntimeException('Enable WooCommerce and pass a user with manage_woocommerce to WP-CLI.');
}
$adminId = get_current_user_id();

function codeon_shipping_fixture_expect(bool $pass, string $message): void
{
	if (!$pass) {
		throw new RuntimeException($message);
	}
}

function codeon_shipping_fixture_api(string $verb, string $route, array $body = [], int $status = 200): array
{
	$request = new WP_REST_Request($verb, '/codeon-shipping-rules/v1' . $route);
	if ($body) {
		$request->set_header('content-type', 'application/json');
		$request->set_body(wp_json_encode($body));
	}
	$response = rest_do_request($request);
	$data = $response->get_data();
	codeon_shipping_fixture_expect($response->get_status() === $status, $verb . ' ' . $route . ' returned ' . $response->get_status() . ': ' . wp_json_encode($data));
	return $data;
}

set_error_handler(static function ($severity, $message, $file, $line): bool {
	if (($severity & error_reporting()) && str_contains($file, '/codeon-shipping-rules/')) {
		throw new ErrorException($message, 0, $severity, $file, $line);
	}
	return false;
});

$zone = new WC_Shipping_Zone();
$ids = [];
try {
	wp_set_current_user(0);
	codeon_shipping_fixture_api('GET', '/methods', [], 401);
	wp_set_current_user($adminId);
	$zone->set_zone_name('CodeOn API Fixture Maine');
	$zone->set_zone_order(20);
	$zone->save();
	$zone->add_location('US:ME', 'state');
	$zone->save();
	$zoneId = $zone->get_id();
	$index = codeon_shipping_fixture_api('GET', '/methods');
	codeon_shipping_fixture_expect(in_array($zoneId, array_column($index['zones'], 'id'), true), 'The configurable WooCommerce zone is absent from the API.');

	$creation = codeon_shipping_fixture_api('POST', '/methods', ['zone_id' => $zoneId, 'title' => 'REST Test Delivery']);
	$id = $ids[] = (int) $creation['id'];
	$details = codeon_shipping_fixture_api('GET', "/methods/{$id}");
	codeon_shipping_fixture_expect($details['enabled'] === false && $details['revision'] === 0 && $details['draft_pending'], 'New methods must begin disabled with only a draft.');
	codeon_shipping_fixture_api('PUT', "/methods/{$id}/state", ['enabled' => true], 400);

	$config = $details['draft'];
	$config['rules'] = [['id' => 'rest-fixture', 'conditions' => [['type' => 'always']], 'base' => '7.25']];
	$version = ['revision' => $details['revision'], 'draft_version' => $details['draft_version']];
	$invalid = $config;
	$invalid['rules'][0]['conditions'][0]['type'] = 'unknown';
	codeon_shipping_fixture_api('PUT', "/methods/{$id}/draft", $version + ['config' => $invalid], 400);
	$draft = codeon_shipping_fixture_api('PUT', "/methods/{$id}/draft", $version + ['config' => $config]);
	codeon_shipping_fixture_api('PUT', "/methods/{$id}/draft", $version + ['config' => $config], 409);
	$version = ['revision' => $draft['revision'], 'draft_version' => $draft['draft_version']];
	$published = codeon_shipping_fixture_api('POST', "/methods/{$id}/publish", $version);
	codeon_shipping_fixture_expect($published['revision'] === 1 && !$published['draft_pending'], 'A published method has one live revision and no pending drafts.');
	codeon_shipping_fixture_expect(codeon_shipping_fixture_api('PUT', "/methods/{$id}/state", ['enabled' => true])['enabled'], 'Published method could not be enabled.');
	codeon_shipping_fixture_expect(codeon_shipping_fixture_api('GET', "/methods/{$id}")['enabled'], 'Enabled method was not persisted to its WooCommerce zone.');

	$scenario = ['method_id' => $id, 'scenario' => ['amount' => 65, 'quantity' => 2]];
	$quote = codeon_shipping_fixture_api('POST', '/simulate', $scenario);
	codeon_shipping_fixture_expect((float) $quote['live']['cost'] === 7.25 && (float) $quote['draft']['cost'] === 7.25 && $quote['live']['trace'][0]['matched'], 'The simulator should send the draft and live traces and rates.');

	$config['rules'][0]['base'] = '8.75';
	$version = ['revision' => $published['revision'], 'draft_version' => $published['draft_version']];
	$draft = codeon_shipping_fixture_api('PUT', "/methods/{$id}/draft", $version + ['config' => $config]);
	$quote = codeon_shipping_fixture_api('POST', '/simulate', $scenario);
	codeon_shipping_fixture_expect((float) $quote['live']['cost'] === 7.25 && (float) $quote['draft']['cost'] === 8.75, 'An unpublished draft must leave the active quote untouched.');
	$version = ['revision' => $draft['revision'], 'draft_version' => $draft['draft_version']];
	$published = codeon_shipping_fixture_api('POST', "/methods/{$id}/publish", $version);
	$rollback = codeon_shipping_fixture_api('POST', "/methods/{$id}/rollback", ['revision' => $published['revision'], 'target' => 1]);
	codeon_shipping_fixture_expect($rollback['revision'] === 3 && (float) $rollback['config']['rules'][0]['base'] === 7.25, 'Rollback must restore the target price as a new revision.');

	$export = codeon_shipping_fixture_api('GET', "/export/{$id}");
	$export['data']['methods'][0]['title'] = 'Imported REST Test Delivery';
	$import = ['format' => 'json', 'content' => wp_json_encode($export['data']), 'zone_id' => $zoneId];
	$beforeCount = count($zone->get_shipping_methods());
	$preview = codeon_shipping_fixture_api('POST', '/import', $import + ['apply' => false]);
	codeon_shipping_fixture_expect(count($preview['methods']) === 1 && !$preview['issues'] && !$preview['created'] && count($zone->get_shipping_methods()) === $beforeCount, 'A dry run must validate the export without inserting an instance.');
	$applied = codeon_shipping_fixture_api('POST', '/import', $import + ['apply' => true]);
	$importedId = $ids[] = (int) ($applied['created'][0] ?? 0);
	$imported = codeon_shipping_fixture_api('GET', "/methods/{$importedId}");
	codeon_shipping_fixture_expect($importedId > 0 && !$imported['enabled'] && !$imported['revision'] && (float) $imported['draft']['rules'][0]['base'] === 7.25, 'An applied import must create a disabled draft with the reference price.');
	$repeat = codeon_shipping_fixture_api('POST', '/import', $import + ['apply' => true]);
	codeon_shipping_fixture_expect($repeat['created'] === [$importedId] && count($zone->get_shipping_methods()) === $beforeCount + 1, 'Repeating the same import must reuse its matching draft.');

	$legacy = ['format' => 'flexible', 'content' => wp_json_encode([
		'method_title' => 'Legacy Flat Rule',
		'method_rules' => [['cost_per_order' => '5.55']],
	])];
	$legacyPreview = codeon_shipping_fixture_api('POST', '/import', $legacy);
	codeon_shipping_fixture_expect(!$legacyPreview['methods'][0]['issues'] && (float) $legacyPreview['methods'][0]['config']['rules'][0]['base'] === 5.55, 'Legacy flat rules should normalize without changes to their cost.');
	WP_CLI::success('REST permission, disabled create, validation, stale-write conflict, publish, enable, simulation, rollback, export and dry-run/apply imports passed.');
} finally {
	foreach ($ids as $id) {
		$zone->delete_shipping_method($id);
	}
	if ($zone->get_id()) {
		$zone->delete(true);
	}
	wp_set_current_user($adminId);
	restore_error_handler();
}
