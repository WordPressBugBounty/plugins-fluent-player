<?php

namespace FluentPlayer\App\Integrations\FluentCrm;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Services\Behavior\TagScope;
use FluentPlayer\App\Services\Behavior\TimelineFields;
use FluentPlayer\App\Services\EmailProviderService;
use FluentPlayer\Framework\Support\Arr;

/**
 * The CRM destination's contact-timeline settings surface: field
 * composition for the integration config page, sanitize merge for the
 * timeline_* keys, and the two picker endpoints. Composes the neutral
 * builders from Services\Behavior\TimelineFields.
 */
class TimelineSettings
{
    public static function fields()
    {
        $saved = (array) Arr::get(EmailProviderService::getProvidersSettings(), 'fluentcrm', []);
        $tagsAvailable = TagScope::available();
        $showTags = $tagsAvailable || 'tags' === Arr::get($saved, 'timeline_scope');

        $fields = TimelineFields::settingsFields(
            'email-providers/fluentcrm/timeline-media',
            'email-providers/fluentcrm/timeline-layers',
            'timeline_',
            $showTags ? 'email-providers/fluentcrm/timeline-tags' : null,
            $tagsAvailable
        );

        $eventTrackingOn = class_exists(\FluentCrm\App\Services\Helper::class)
            && \FluentCrm\App\Services\Helper::isExperimentalEnabled('event_tracking');

        // without this the toggle looks functional while the tracker refuses every write
        if (defined('FLUENTCRM') && !$eventTrackingOn) {
            array_splice($fields, 1, 0, [[
                'key'        => 'timeline_event_tracking_notice',
                'type'       => 'notice',
                'variant'    => 'warning',
                'text'       => __('Event Tracking is disabled in FluentCRM — timeline entries will not appear until it is enabled under FluentCRM → Add-ons.', 'fluent-player'),
                'link'       => [
                    'url'   => admin_url('admin.php?page=fluentcrm-admin#/add-ons'),
                    'label' => __('Open FluentCRM settings', 'fluent-player'),
                ],
                'indent'     => true,
                'depends_on' => 'timeline_enabled',
            ]]);
        }

        if ($showTags && self::savedRuleHasDeletedTagUnderMatchAll($saved)) {
            $matchIndex = array_search('timeline_tag_match', array_column($fields, 'key'), true);
            array_splice($fields, $matchIndex + 1, 0, [[
                'key'        => 'timeline_tags_deleted_note',
                'type'       => 'notice',
                'variant'    => 'warning',
                'text'       => __('A selected tag was deleted. With "All of the selected tags", no media can match until it is removed from the list.', 'fluent-player'),
                'indent'     => true,
                'depends_on' => ['all' => [
                    'timeline_enabled',
                    ['key' => 'timeline_scope', 'value' => 'tags'],
                    ['key' => 'timeline_tag_match', 'value' => TagScope::MATCH_ALL],
                ]],
            ]]);
        }

        return TimelineFields::decorateSelectedOptions($fields, $saved);
    }

    /**
     * @param array $saved
     * @return bool
     */
    protected static function savedRuleHasDeletedTagUnderMatchAll(array $saved)
    {
        if (TagScope::MATCH_ALL !== Arr::get($saved, 'timeline_tag_match')) {
            return false;
        }
        $ids = TagScope::sanitizeIds(Arr::get($saved, 'timeline_tag_ids', []));
        return $ids && count(TagScope::validIds($ids)) !== count($ids);
    }

    /**
     * Timeline keys merge only when posted — partial saves (preset editor)
     * must not reset stored timeline settings to defaults.
     */
    public static function sanitizeMerge(array $sanitized, array $raw)
    {
        $timeline = TimelineFields::sanitize($raw);
        foreach (array_keys(TimelineFields::defaults()) as $key) {
            if (array_key_exists($key, $raw)) {
                $sanitized[$key] = $timeline[$key];
            }
        }

        return $sanitized;
    }

    /**
     * @return array|null null when the action is not a timeline endpoint
     */
    public static function handleAction($action, $data)
    {
        if ($action === 'timeline-media') {
            return self::mediaOptions($data);
        }
        if ($action === 'timeline-layers') {
            return self::layerGroups($data);
        }
        if ('timeline-tags' === $action) {
            return ['success' => true, 'options' => TagScope::pickerOptions((string) Arr::get($data, 'search', '')), 'has_more' => false];
        }

        return null;
    }

    /**
     * Media options for the "Selected media" picker. Small libraries ship
     * complete in one response (has_more=false) so the client filters
     * locally; past the cap, has_more=true switches the client to server
     * search, which runs a real query instead of filtering the capped list.
     */
    protected static function mediaOptions($data)
    {
        $search = (string) Arr::get($data, 'search', '');

        $statuses = ['publish', 'draft', 'pending', 'private'];
        $counts = (array) wp_count_posts(Media::$postType);
        $total = 0;
        foreach ($statuses as $status) {
            $total += (int) Arr::get($counts, $status, 0);
        }
        $hasMore = $total > Helper::MEDIA_OPTION_LIMIT;

        if ($hasMore && $search !== '') {
            $options = [];
            foreach (get_posts([
                'post_type'   => Media::$postType,
                'post_status' => $statuses,
                's'           => $search,
                'numberposts' => 100,
                'orderby'     => 'title',
                'order'       => 'ASC',
            ]) as $post) {
                $title = trim(html_entity_decode($post->post_title, ENT_QUOTES | ENT_HTML5));
                $options[] = [
                    'value' => (int) $post->ID,
                    'label' => $title !== '' ? $title : '#' . $post->ID,
                ];
            }
            return ['success' => true, 'options' => $options, 'has_more' => true];
        }

        $options = [];
        foreach (Helper::mediaOptions() as $option) {
            if ($search !== '' && stripos($option['title'], $search) === false) {
                continue;
            }
            $options[] = ['value' => (int) $option['id'], 'label' => $option['title']];
        }

        return ['success' => true, 'options' => $options, 'has_more' => $hasMore];
    }

    /**
     * Layer instances grouped by media, limited to the selected media ids.
     */
    protected static function layerGroups($data)
    {
        $hasMediaParam = is_array($data) && array_key_exists('media_ids', $data);
        $mediaIds = Arr::get($data, 'media_ids', []);
        if (is_string($mediaIds)) {
            $mediaIds = explode(',', $mediaIds);
        }
        $mediaIds = array_values(array_filter(array_map('absint', (array) $mediaIds)));

        // the picker scopes to a selection; nothing selected must not list
        // every layer on the site
        if ($hasMediaParam && !$mediaIds) {
            return ['success' => true, 'groups' => []];
        }

        $groups = [];
        foreach (Helper::layerOptionGroups() as $group) {
            $mediaId = (int) substr((string) Arr::get($group, 'slug', ''), 6);
            if ($mediaIds && !in_array($mediaId, $mediaIds, true)) {
                continue;
            }
            $groups[] = [
                'label'   => $group['title'],
                'options' => array_map(function ($option) {
                    return ['value' => $option['id'], 'label' => $option['title']];
                }, $group['options']),
            ];
        }

        return ['success' => true, 'groups' => $groups];
    }
}
