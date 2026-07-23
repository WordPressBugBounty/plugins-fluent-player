<?php

namespace FluentPlayer\App\Integrations\FluentCrm;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;

class BehaviorState
{
    const STATE_TTL = 21600;
    const META_BUCKET = 'FluentPlayerBehavior';

    public static function defaults()
    {
        // layers = timestamp anchors; layers_fired = emitted set (reset on migration for catch-up)
        return ['union' => [], 'watched' => 0.0, 'ts' => 0, 'fired' => [], 'layers' => [], 'layers_fired' => []];
    }

    public static function resolveScope($contact, $userId, $anonId)
    {
        if ($contact) {
            // user_id lets a contact reclaim state saved under its earlier user scope
            return ['type' => 'contact', 'id' => (int) $contact->id, 'user_id' => (int) $userId];
        }
        if ($userId) {
            return ['type' => 'user', 'id' => (int) $userId];
        }
        // per-browser (first-party cookie), never per IP — a NAT bucket leaks across visitors
        return ['type' => 'anon', 'id' => (string) $anonId];
    }

    public static function load(array $scope, $mediaId, $anonId)
    {
        $raw = null;
        if ($scope['type'] === 'user') {
            $raw = get_user_meta($scope['id'], self::metaKey($mediaId), true);
        } elseif ($scope['type'] === 'contact') {
            $meta = fluentcrm_get_meta($scope['id'], self::META_BUCKET, self::metaKey($mediaId));
            $raw = ($meta && !empty($meta->value)) ? json_decode($meta->value, true) : null;

            // migrate earlier user-scope state (fired set included) so milestones
            // don't re-fire and watch progress isn't reset at the scope boundary
            if (!is_array($raw) || !Arr::has($raw, 'ts')) {
                $migrated = self::migrateUserState($scope, $mediaId);
                if ($migrated !== null) {
                    return $migrated;
                }
            }
        } elseif ($scope['type'] === 'anon' && $scope['id'] !== '') {
            $raw = get_transient(self::anonKey($mediaId, $scope['id']));
        }

        if (is_array($raw) && Arr::has($raw, 'ts')) {
            return array_merge(self::defaults(), $raw);
        }

        $state = self::defaults();
        // on identify, adopt only this browser's own anon bucket — never a shared IP one
        if ($scope['type'] !== 'anon' && $anonId !== '') {
            $anonKey = self::anonKey($mediaId, $anonId);
            $anonRaw = get_transient($anonKey);
            if (is_array($anonRaw) && Arr::has($anonRaw, 'ts')) {
                // transfer evidence + anchors; fired stays empty so milestones catch up
                $state['union'] = Arr::get($anonRaw, 'union', []);
                $state['watched'] = (float) Arr::get($anonRaw, 'watched', 0.0);
                $state['ts'] = (int) $anonRaw['ts'];
                $state['layers'] = (array) Arr::get($anonRaw, 'layers', []);
                delete_transient($anonKey);
            }
        }
        return $state;
    }

    public static function save(array $scope, $mediaId, array $state)
    {
        $payload = array_intersect_key(array_merge(self::defaults(), $state), self::defaults());

        if ($scope['type'] === 'user') {
            update_user_meta($scope['id'], self::metaKey($mediaId), $payload);
        } elseif ($scope['type'] === 'contact') {
            fluentcrm_update_meta($scope['id'], self::META_BUCKET, self::metaKey($mediaId), wp_json_encode($payload));
        } elseif ($scope['type'] === 'anon' && $scope['id'] !== '') {
            set_transient(self::anonKey($mediaId, $scope['id']), $payload, self::STATE_TTL);
        }
    }

    // adopt user-scope state into contact meta, then clear it so it migrates once
    protected static function migrateUserState(array $scope, $mediaId)
    {
        $userId = (int) Arr::get($scope, 'user_id');
        if (!$userId) {
            return null;
        }

        $userRaw = get_user_meta($userId, self::metaKey($mediaId), true);
        if (!is_array($userRaw) || !Arr::has($userRaw, 'ts')) {
            return null;
        }

        $state = array_merge(self::defaults(), $userRaw);
        self::save($scope, $mediaId, $state);
        delete_user_meta($userId, self::metaKey($mediaId));

        return $state;
    }

    /**
     * FluentCRM does not cascade third-party meta when contacts are deleted,
     * so drop each contact's stored watch state along with the contact.
     */
    public static function onContactsDeleted($contactIds)
    {
        foreach ((array) $contactIds as $contactId) {
            fluentcrm_delete_meta((int) $contactId, self::META_BUCKET);
        }
    }

    /**
     * Drop every scope's stored state for a deleted media so no orphan rows remain:
     * user-meta (all users), contact meta (all contacts), and anon transients.
     */
    public static function onMediaDeleted($mediaId)
    {
        $mediaId = (int) $mediaId;
        if (!$mediaId) {
            return;
        }
        global $wpdb;
        $key = self::metaKey($mediaId);

        delete_metadata('user', 0, $key, '', true);

        if (class_exists(\FluentCrm\App\Models\Meta::class)) {
            \FluentCrm\App\Models\Meta::where('object_type', self::META_BUCKET)
                ->where('key', $key)
                ->delete();
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk transient purge by media id
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_flp_behavior_state_') . '%' . $wpdb->esc_like('_' . $mediaId),
            $wpdb->esc_like('_transient_timeout_flp_behavior_state_') . '%' . $wpdb->esc_like('_' . $mediaId)
        ));
    }

    public static function metaKey($mediaId)
    {
        return '_flp_behavior_state_' . (int) $mediaId;
    }

    protected static function anonKey($mediaId, $anonId)
    {
        return 'flp_behavior_state_' . md5((string) $anonId) . '_' . (int) $mediaId;
    }
}
