<?php

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// The update cache is not merchant data.
delete_site_transient('codeon_sr_github_release');

if (!defined('CODEON_SHIPPING_RULES_DELETE_DATA') || !CODEON_SHIPPING_RULES_DELETE_DATA) {
    return;
}

$clean = static function (): void {
    global $wpdb;
    $pattern = $wpdb->esc_like('codeon_sr_') . '%';
    $options = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern));
    foreach ($options as $option) {
        delete_option($option);
    }
    wp_unschedule_hook('codeon_sr_prune_revisions');
    if (class_exists('WC_Cache_Helper')) {
        WC_Cache_Helper::get_transient_version('shipping', true);
    }
};

if (is_multisite()) {
    $offset = 0;
    do {
        $sites = get_sites(['fields' => 'ids', 'number' => 100, 'offset' => $offset]);
        foreach ($sites as $site) {
            switch_to_blog((int) $site);
            $clean();
            restore_current_blog();
        }
        $offset += count($sites);
    } while (count($sites) === 100);
} else {
    $clean();
}
