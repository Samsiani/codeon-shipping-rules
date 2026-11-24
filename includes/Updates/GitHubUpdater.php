<?php

declare(strict_types=1);

namespace CodeOn\ShippingRules\Updates;

/**
 * Offers GitHub releases through WordPress's own plugin updater.
 *
 * The plugin header declares `Update URI`, so WordPress asks the
 * `update_plugins_github.com` filter instead of WordPress.org. Each release
 * publishes an `update.json` asset next to the install ZIP. Reading that asset
 * through the release download URL avoids the GitHub API and its per-IP rate
 * limit, which shared hosting reaches quickly.
 */
final class GitHubUpdater
{
    public const SLUG = 'codeon-shipping-rules';
    private const CACHE_KEY = 'codeon_sr_github_release';
    private const CACHE_TTL = HOUR_IN_SECONDS;
    private const FAILURE_TTL = 15 * MINUTE_IN_SECONDS;

    public function __construct(
        private readonly string $pluginFile,
        private readonly string $version,
        private readonly string $updateUri
    ) {
    }

    public function register(): void
    {
        $host = (string) wp_parse_url($this->updateUri, PHP_URL_HOST);
        add_filter('update_plugins_' . $host, [$this, 'update'], 10, 3);
        add_filter('plugins_api', [$this, 'information'], 20, 3);
        add_filter('auto_update_plugin', [$this, 'autoUpdate'], 10, 2);
        add_filter('upgrader_pre_download', [$this, 'verifiedDownload'], 10, 4);
        add_action('delete_site_transient_update_plugins', [$this, 'flush']);
        add_action('upgrader_process_complete', [$this, 'flush']);
        // Dashboard → Updates must see a release published minutes ago.
        add_action('load-update-core.php', [$this, 'refreshOnUpdatesPage'], 9);
    }

    public function refreshOnUpdatesPage(): void
    {
        // The Updates screen always asks GitHub again instead of showing a cached release.
        $this->flush();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag; WordPress core uses the same request to re-check updates.
        if (!empty($_GET['force-check'])) {
            // "Check again" must not be skipped by WordPress's one-minute update-check throttle.
            delete_site_transient('update_plugins');
        }
    }

    public function update(mixed $update, array $pluginData, string $pluginFile): mixed
    {
        if ($pluginFile !== plugin_basename($this->pluginFile)) {
            return $update;
        }
        $release = $this->release();
        if ($release === null) {
            return $update;
        }

        return [
            'slug' => self::SLUG,
            'version' => $release['version'],
            'url' => $this->updateUri,
            'package' => $release['package'],
            'tested' => $release['tested'],
            'requires' => $release['requires'],
            'requires_php' => $release['requires_php'],
            'autoupdate' => self::autoUpdatesEnabled(),
            'icons' => $this->icons(),
        ];
    }

    public function information(mixed $result, string $action, object $args): mixed
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== self::SLUG) {
            return $result;
        }
        $release = $this->release();
        if ($release === null) {
            return $result;
        }

        return (object) [
            'name' => $release['name'],
            'slug' => self::SLUG,
            'version' => $release['version'],
            'author' => '<a href="https://codeon.ge/">CodeOn</a>',
            'homepage' => $this->updateUri,
            'requires' => $release['requires'],
            'requires_php' => $release['requires_php'],
            'tested' => $release['tested'],
            'last_updated' => $release['released'],
            'download_link' => $release['package'],
            'sections' => array_map('wp_kses_post', $release['sections']),
            'icons' => $this->icons(),
        ];
    }

    public function autoUpdate(?bool $update, object $item): ?bool
    {
        if (($item->plugin ?? '') !== plugin_basename($this->pluginFile)) {
            return $update;
        }
        return self::autoUpdatesEnabled() ? true : $update;
    }

    /**
     * Downloads our release ZIP and checks it against the SHA-256 published in update.json.
     */
    public function verifiedDownload(mixed $reply, string $package, object $upgrader, array $hookExtra = []): mixed
    {
        if ($reply !== false) {
            return $reply;
        }
        $release = $this->release();
        if ($release === null || $package !== $release['package']) {
            return $reply;
        }
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $file = download_url($package, 300);
        if (is_wp_error($file)) {
            return $file;
        }
        if (!hash_equals($release['sha256'], (string) hash_file('sha256', $file))) {
            wp_delete_file($file);
            return new \WP_Error('codeon_sr_checksum', __('The downloaded CodeOn Shipping Rules package failed its checksum. The update was not installed.', 'codeon-shipping-rules'));
        }
        return $file;
    }

    public function flush(): void
    {
        delete_site_transient(self::CACHE_KEY);
    }

    public static function autoUpdatesEnabled(): bool
    {
        return !defined('CODEON_SHIPPING_RULES_AUTO_UPDATE') || (bool) constant('CODEON_SHIPPING_RULES_AUTO_UPDATE');
    }

    /**
     * Returns a usable release from a decoded update.json, or null when it is incomplete or points elsewhere.
     *
     * @return array{name:string,version:string,package:string,sha256:string,requires:string,requires_php:string,tested:string,released:string,sections:array<string,string>}|null
     */
    public static function validate(mixed $manifest, string $updateUri): ?array
    {
        if (!is_array($manifest) || ($manifest['slug'] ?? '') !== self::SLUG) {
            return null;
        }
        $version = (string) ($manifest['version'] ?? '');
        $package = (string) ($manifest['package'] ?? '');
        $sha256 = strtolower((string) ($manifest['sha256'] ?? ''));
        $expected = rtrim($updateUri, '/') . '/releases/download/v' . $version . '/' . self::SLUG . '-' . $version . '.zip';
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version) || $package !== $expected || !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            return null;
        }
        $sections = [];
        foreach ((array) ($manifest['sections'] ?? []) as $name => $html) {
            if (is_string($name) && is_string($html)) {
                $sections[$name] = $html;
            }
        }

        return [
            'name' => is_string($manifest['name'] ?? null) ? $manifest['name'] : 'CodeOn Shipping Rules for WooCommerce',
            'version' => $version,
            'package' => $package,
            'sha256' => $sha256,
            'requires' => (string) ($manifest['requires'] ?? ''),
            'requires_php' => (string) ($manifest['requires_php'] ?? ''),
            'tested' => (string) ($manifest['tested'] ?? ''),
            'released' => (string) ($manifest['released'] ?? ''),
            'sections' => $sections,
        ];
    }

    private function release(): ?array
    {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached['release'] ?? null;
        }

        $response = wp_remote_get(rtrim($this->updateUri, '/') . '/releases/latest/download/update.json', [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
            'user-agent' => 'CodeOn-Shipping-Rules/' . $this->version . '; ' . home_url('/'),
        ]);
        $release = null;
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $release = self::validate(json_decode(wp_remote_retrieve_body($response), true), $this->updateUri);
        }
        set_site_transient(self::CACHE_KEY, ['release' => $release], $release === null ? self::FAILURE_TTL : self::CACHE_TTL);

        return $release;
    }

    private function icons(): array
    {
        return [
            '1x' => plugins_url('assets/icon/icon-128x128.png', $this->pluginFile),
            '2x' => plugins_url('assets/icon/icon-256x256.png', $this->pluginFile),
            'svg' => plugins_url('assets/icon/icon.svg', $this->pluginFile),
        ];
    }
}
