<?php

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are returned as JSON data or rendered with textContent.

namespace CodeOn\ShippingRules\Storage;

use CodeOn\ShippingRules\Integrations\ShippingTexts;
use CodeOn\ShippingRules\Rules\Schema;
use CodeOn\ShippingRules\WooCommerce\Context;

final class Revisions
{
    private const HISTORY_LIMIT = 12;

    public function state(int $id): array
    {
        return get_option($this->key($id), [
            'revision' => 0,
            'draft_version' => 0,
            'live' => '',
            'draft' => '',
            'history' => [],
            'requirements' => [],
        ]);
    }

    public function live(int $id): array
    {
        return $this->snapshot($id, $this->state($id)['live']);
    }

    public function details(int $id): array
    {
        $state = $this->state($id);

        return [
            'config' => $this->snapshot($id, $state['live']),
            'draft' => $this->snapshot($id, $state['draft']),
            'revision' => $state['revision'],
            'draft_version' => $state['draft_version'],
            'draft_pending' => $state['live'] !== $state['draft'],
            'history' => array_map(static function (array $entry): array {
                unset($entry['snapshot']);
                return $entry;
            }, $state['history']),
        ];
    }

    public function saveDraft(int $id, array $config, int $revision, int $draftVersion): array
    {
        $config = Schema::normalize($config);
        $old = $this->state($id);
        $this->assertVersion($old, $revision, $draftVersion);
        if ($old['draft'] !== '' && $config === $this->snapshot($id, $old['draft'])) {
            return $this->details($id);
        }

        $next = $old;
        $next['draft_version']++;
        $next['draft'] = bin2hex(random_bytes(12));

        if (!add_option($this->snapshotKey($id, $next['draft']), $config, '', false)) {
            throw new \RuntimeException(__('The draft could not be saved. Try again.', 'codeon-shipping-rules'));
        }
        try {
            $this->replace($id, $old, $next);
        } catch (\Throwable $error) {
            delete_option($this->snapshotKey($id, $next['draft']));
            throw $error;
        }
        $this->removeExpired($id, $old, $next);

        return $this->details($id);
    }

    public function publish(int $id, int $revision, int $draftVersion): array
    {
        $old = $this->state($id);
        $this->assertVersion($old, $revision, $draftVersion);
        if ($old['draft'] === '' || $old['draft'] === $old['live']) {
            throw new \InvalidArgumentException(__('Save a new draft before publishing.', 'codeon-shipping-rules'));
        }

        return $this->publishSnapshot($id, $old, $old['draft']);
    }

    public function rollback(int $id, int $revision, int $target): array
    {
        $old = $this->state($id);
        $this->assertVersion($old, $revision, $old['draft_version']);

        foreach ($old['history'] as $entry) {
            if ($entry['revision'] === $target && $entry['snapshot'] !== $old['live']) {
                return $this->publishSnapshot($id, $old, $entry['snapshot'], true);
            }
        }

        throw new \InvalidArgumentException(__('That revision is no longer available for rollback.', 'codeon-shipping-rules'));
    }

    public function hasPublishedMethods(): bool
    {
        return (bool) get_option('codeon_sr_has_published', false);
    }

    private function publishSnapshot(int $id, array $old, string $snapshot, bool $preserveDraft = false): array
    {
        $next = $old;
        $next['revision']++;
        $next['draft_version']++;
        if (!$preserveDraft || $old['live'] === $old['draft']) {
            $next['draft'] = $snapshot;
        }
        $next['live'] = $snapshot;
        $config = $this->snapshot($id, $snapshot);
        $next['requirements'] = Context::requirements($config);
        array_unshift($next['history'], [
            'revision' => $next['revision'],
            'date' => gmdate('c'),
            'user' => get_current_user_id(),
            'snapshot' => $snapshot,
        ]);
        $next['history'] = array_slice($next['history'], 0, self::HISTORY_LIMIT);

        // Enable cache fingerprinting before the newly published revision can be read.
        add_option('codeon_sr_has_published', '1', '', false);
        $this->replace($id, $old, $next);
        $this->removeExpired($id, $old, $next);
        \WC_Cache_Helper::get_transient_version('shipping', true);
        ShippingTexts::register($id, $config);

        return $this->details($id);
    }

    private function assertVersion(array $state, int $revision, int $draftVersion): void
    {
        if ($state['revision'] !== $revision || $state['draft_version'] !== $draftVersion) {
            throw new Conflict(__('This method changed in another session. Reload it before saving.', 'codeon-shipping-rules'));
        }
    }

    private function replace(int $id, array $old, array $next): void
    {
        global $wpdb;
        $key = $this->key($id);

        if ($old['draft_version'] === 0) {
            $rows = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
                $key,
                maybe_serialize($next)
            ));
            if ($rows === 1) {
                wp_cache_delete($key, 'options');
                wp_cache_delete('notoptions', 'options');
                return;
            }
        }

        // Compare the stored generation and update in one database operation.
        $rows = $wpdb->update(
            $wpdb->options,
            ['option_value' => maybe_serialize($next)],
            ['option_name' => $key, 'option_value' => maybe_serialize($old)]
        );
        wp_cache_delete($key, 'options');

        if ($rows !== 1) {
            throw new Conflict(__('This method changed in another session. Reload it before saving.', 'codeon-shipping-rules'));
        }
    }

    private function removeExpired(int $id, array $old, array $next): void
    {
        $oldKeys = [$old['draft'], $old['live'], ...array_column($old['history'], 'snapshot')];
        $newKeys = [$next['draft'], $next['live'], ...array_column($next['history'], 'snapshot')];
        $expired = array_values(array_unique(array_diff($oldKeys, $newKeys, [''])));
        if ($expired) {
            // Keep snapshots available while requests that read the previous pointer finish.
            wp_schedule_single_event(time() + HOUR_IN_SECONDS, 'codeon_sr_prune_revisions', [$id, $expired]);
        }
    }

    public function prune(int $id, array $candidates): void
    {
        $state = $this->state($id);
        $retained = [$state['draft'], $state['live'], ...array_column($state['history'], 'snapshot')];
        foreach (array_diff($candidates, $retained) as $reference) {
            if (preg_match('/^[a-f0-9]{24}$/D', $reference)) {
                delete_option($this->snapshotKey($id, $reference));
            }
        }
    }

    public function delete(int $id): void
    {
        if (get_option($this->key($id), false) === false) {
            return;
        }
        global $wpdb;
        $prefix = $wpdb->esc_like('codeon_sr_snapshot_' . $id . '_') . '%';
        $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix));
        foreach ($names as $name) {
            delete_option($name);
        }
        delete_option($this->key($id));
    }

    private function snapshot(int $id, string $reference): array
    {
        return $reference === '' ? Schema::defaults() : get_option($this->snapshotKey($id, $reference), Schema::defaults());
    }

    private function key(int $id): string
    {
        return 'codeon_sr_method_' . $id;
    }

    private function snapshotKey(int $id, string $reference): string
    {
        return 'codeon_sr_snapshot_' . $id . '_' . $reference;
    }
}
