<?php

namespace FluentPlayer\App\Integrations\FluentCrm;

if (!defined('ABSPATH')) exit;

use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentPlayer\App\Models\Media;
use FluentPlayer\Framework\Support\Arr;

/**
 * Shared field builders, option sources, event matching and subscriber resolution
 * for both behavior families (media milestones + layer events), so the triggers,
 * benchmarks and condition group stay identical.
 */
class Helper
{
    const MEDIA_OPTION_LIMIT = 300;

    /**
     * Server-owned media duration — the ONLY denominator the coverage math trusts.
     * Shared by the milestone evaluator and the funnel conditions so the two can
     * never disagree about whether a media has a measurable length. A client-reported
     * duration is never admitted here.
     */
    public static function serverDuration($mediaId)
    {
        $settings = Media::getMediaSettings($mediaId);
        $duration = (float) Arr::get($settings, 'duration', 0);
        if ($duration <= 0) {
            $provider = (string) Arr::get($settings, 'provider', '');
            $duration = (float) Arr::get($settings, $provider . '.duration', 0);
        }
        if ($duration <= 0) {
            $attachmentId = (int) Arr::get($settings, 'attachment_id', 0);
            if ($attachmentId) {
                $meta = wp_get_attachment_metadata($attachmentId);
                $duration = (float) Arr::get((array) $meta, 'length', 0);
            }
        }
        return $duration;
    }

    public static function infoLine($text)
    {
        return '<p style="margin:2px 0 0;font-size:12.5px;line-height:1.55;color:#8f9aa3;">' . $text . '</p>';
    }

    public static function noticeBox($tone, $text)
    {
        $tones = [
            'safe' => ['#27ae60', 'rgba(39,174,96,0.08)'],
            'info' => ['#3498db', 'rgba(52,152,219,0.08)'],
            'warn' => ['#f39c12', 'rgba(243,156,18,0.10)'],
        ];
        $t = Arr::get($tones, $tone, $tones['info']);
        return '<div style="margin-top:4px;padding:10px 12px;border-radius:6px;border-left:3px solid ' . $t[0] . ';background:' . $t[1] . ';font-size:13px;line-height:1.55;">' . $text . '</div>';
    }

    public static function sectionHeading($text)
    {
        return '<h4 style="margin:16px 0 0;font-size:12px;font-weight:600;letter-spacing:0.5px;text-transform:uppercase;opacity:0.7;">' . $text . '</h4>';
    }

    public static function milestoneInfoFields()
    {
        $map = [
            'started'   => __('Fires once the viewer has watched at least one second of the media — a light engagement signal.', 'fluent-player'),
            'q25'       => __('Fires when 25% of the media has genuinely been watched. Skipping ahead does not count, so accidental clicks are filtered out.', 'fluent-player'),
            'q50'       => __('Fires when half of the media has genuinely been watched — a reliable indicator of real interest.', 'fluent-player'),
            'q75'       => __('Fires when 75% of the media has genuinely been watched — a highly engaged viewer, well suited to sales alerts or follow-up sequences.', 'fluent-player'),
            'completed' => __('Fires at the completion threshold selected below. Viewers often skip outros, so 100% is rarely reached.', 'fluent-player'),
        ];

        $fields = [];
        foreach ($map as $value => $text) {
            $fields['milestone_info_' . $value] = [
                'type'       => 'html',
                'info'       => self::infoLine($text),
                'dependency' => ['depends_on' => 'milestone', 'operator' => '=', 'value' => $value],
            ];
        }
        return $fields;
    }

    public static function thresholdInfoField()
    {
        return [
            'completion_threshold_info' => [
                'type'       => 'html',
                'info'       => self::infoLine(\FluentPlayer\App\Services\Behavior\TimelineFields::thresholdHelpText()),
                'dependency' => ['depends_on' => 'milestone', 'operator' => '=', 'value' => 'completed'],
            ],
        ];
    }

    public static function nonContactNoticeFields()
    {
        return [
            'on_non_contact_info_skip' => [
                'type'       => 'html',
                'info'       => self::noticeBox('safe', __('Safe default. Existing contacts enter this automation normally. Viewers who are not in your CRM yet are skipped — no contact is created and no actions run for them.', 'fluent-player')),
                'dependency' => ['depends_on' => 'on_non_contact', 'operator' => '=', 'value' => 'skip'],
            ],
            'on_non_contact_info_pending' => [
                'type'       => 'html',
                'info'       => self::noticeBox('info', __('The viewer is added as a pending contact and receives a double opt-in email. They only become subscribed after confirming — recommended where explicit consent is required, such as under GDPR.', 'fluent-player')),
                'dependency' => ['depends_on' => 'on_non_contact', 'operator' => '=', 'value' => 'pending'],
            ],
        ];
    }

    public static function runMultipleNoticeFields($onceNote = '', $offNote = '')
    {
        $onText = __('The contact re-enters this automation every time this event happens again — all of its actions run again as well.', 'fluent-player');
        if ($onceNote) {
            $onText .= ' ' . $onceNote;
        }
        if ($offNote === '') {
            $offNote = __('Runs once per contact — good for one-time journeys like welcome emails or coupons.', 'fluent-player');
        }

        return [
            'run_multiple_info_on' => [
                'type'       => 'html',
                'info'       => self::noticeBox('warn', $onText),
                'dependency' => ['depends_on' => 'run_multiple', 'operator' => '=', 'value' => 'yes'],
            ],
            'run_multiple_info_off' => [
                'type'       => 'html',
                'info'       => self::infoLine($offNote),
                'dependency' => ['depends_on' => 'run_multiple', 'operator' => '!=', 'value' => 'yes'],
            ],
        ];
    }

    public static function iconSvg()
    {
        return '<svg width="20" height="20" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">'
            . '<path d="M28.8 0H3.2C1.43269 0 0 1.43269 0 3.2V28.8C0 30.5673 1.43269 32 3.2 32H28.8C30.5673 32 32 30.5673 32 28.8V3.2C32 1.43269 30.5673 0 28.8 0Z" fill="#DD1F13"/>'
            . '<path d="M19.2289 11.3156L11.9206 7.09616C10.5631 6.31241 8.86624 7.29209 8.86624 8.85959V13.7716C8.86624 15.3391 10.5631 16.3188 11.9206 15.535L19.2289 11.3156ZM22.7029 18.6774C24.7644 17.4872 24.7644 14.5115 22.7028 13.3213C22.488 13.1973 22.2234 13.1973 22.0086 13.3213L10.1126 20.1895C9.34137 20.6348 8.86624 21.4577 8.86624 22.3483C8.86624 24.2673 10.9436 25.4667 12.6054 24.5072L22.7029 18.6774Z" fill="#fff"/>'
            . '</svg>';
    }

    public static function milestoneOptions()
    {
        return [
            ['id' => 'started', 'title' => __('Started', 'fluent-player')],
            ['id' => 'q25', 'title' => __('Reached 25%', 'fluent-player')],
            ['id' => 'q50', 'title' => __('Reached 50%', 'fluent-player')],
            ['id' => 'q75', 'title' => __('Reached 75%', 'fluent-player')],
            ['id' => 'completed', 'title' => __('Completed', 'fluent-player')],
        ];
    }

    public static function thresholdOptions()
    {
        return \FluentPlayer\App\Services\Behavior\TimelineFields::thresholdPairs();
    }

    public static function onNonContactOptions()
    {
        return [
            ['id' => 'skip', 'title' => __('Skip (recommended)', 'fluent-player')],
            ['id' => 'pending', 'title' => __('Create as pending', 'fluent-player')],
        ];
    }

    public static function mediaOptions()
    {
        $options = [];
        foreach (self::mediaPosts() as $post) {
            $options[] = [
                'id'    => (string) $post->ID,
                'title' => self::mediaTitle($post),
            ];
        }
        return self::disambiguateTitles($options);
    }

    private static function mediaPosts()
    {
        // draft/private media stay listed (suffixed) — hiding them would render
        // already-saved selections as raw numeric IDs in the picker chips
        return get_posts([
            'post_type'   => Media::$postType,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'numberposts' => self::MEDIA_OPTION_LIMIT,
            'orderby'     => 'ID',
            'order'       => 'DESC',
        ]);
    }

    private static function mediaTitle($post)
    {
        $title = trim(html_entity_decode($post->post_title, ENT_QUOTES | ENT_HTML5));
        if ($title === '') {
            $title = '#' . $post->ID;
        }
        if ($post->post_status !== 'publish') {
            $status = get_post_status_object($post->post_status);
            $title .= ' — ' . strtolower($status ? $status->label : $post->post_status);
        }
        return $title;
    }

    /**
     * Same-titled entries are indistinguishable in the picker, so append the ID
     * to every member of a duplicate title set.
     */
    private static function disambiguateTitles(array $options)
    {
        $counts = array_count_values(array_column($options, 'title'));
        foreach ($options as &$option) {
            if ($counts[$option['title']] > 1) {
                $option['title'] .= ' (#' . $option['id'] . ')';
            }
        }
        return $options;
    }

    public static function layerEventOptions()
    {
        return [
            ['id' => 'seen', 'title' => __('Seen', 'fluent-player')],
            ['id' => 'completed', 'title' => __('Completed', 'fluent-player')],
            ['id' => 'skipped', 'title' => __('Skipped', 'fluent-player')],
        ];
    }

    public static function layerTypeLabels()
    {
        return [
            'ad'        => __('Ad', 'fluent-player'),
            'cta'       => __('CTA', 'fluent-player'),
            'email'     => __('Email Capture', 'fluent-player'),
            'form'      => __('Form', 'fluent-player'),
            'shortcode' => __('Shortcode', 'fluent-player'),
        ];
    }

    /**
     * Human label for a full layer definition — mirrors the block editor's
     * getLayerSubType() (resources/share/helper.js) so timeline entries read the
     * same as the Interactive Layers list (provider name, form title, "Ad #Video").
     * Returns '' when the type is unknown so callers can fall back to the type label.
     *
     * @param array $layer Full layer definition from the media settings.
     * @return string
     */
    public static function layerDisplayLabel(array $layer)
    {
        switch ((string) Arr::get($layer, 'type', '')) {
            case 'form':
                $title = trim((string) Arr::get($layer, 'title', ''));
                return $title !== '' ? $title : (string) Arr::get($layer, 'form_type', '');
            case 'cta':
                // Editor parity: an email CTA without providers is just a "CTA".
                $providers = (array) Arr::get($layer, 'email_capture.providers', []);
                if (Arr::get($layer, 'cta_type') === 'email' && $providers) {
                    $names = [];
                    foreach ($providers as $p) {
                        if (empty($p['enabled'])) {
                            continue;
                        }
                        $names[] = self::emailProviderName((string) Arr::get($p, 'type', ''));
                    }
                    $names = array_values(array_filter($names));
                    return $names ? implode(', ', $names) : __('Email', 'fluent-player');
                }
                return __('CTA', 'fluent-player');
            case 'hotspot':
                $hotspots = (array) Arr::get($layer, 'hotspots', []);
                if ($hotspots) {
                    $hasIcon = $hasDot = false;
                    foreach ($hotspots as $h) {
                        Arr::get($h, 'type') === 'icon' ? $hasIcon = true : $hasDot = true;
                    }
                    $sub = ($hasIcon && $hasDot)
                        ? __('Mixed', 'fluent-player')
                        : ($hasIcon ? __('Icon', 'fluent-player') : __('Dot', 'fluent-player'));
                    /* translators: 1: hotspot count, 2: Icon/Dot/Mixed */
                    return sprintf(__('Hotspot(%1$d) - %2$s', 'fluent-player'), count($hotspots), $sub);
                }
                return __('Hotspot', 'fluent-player');
            case 'ad':
                return Arr::get($layer, 'media_type') === 'video'
                    ? __('Ad #Video', 'fluent-player')
                    : __('Ad #Image', 'fluent-player');
            case 'shortcode':
                $title = trim((string) Arr::get($layer, 'title', ''));
                return $title !== '' ? $title : (string) Arr::get($layer, 'shortcode', '');
            default:
                return '';
        }
    }

    /**
     * Display name for an email-capture provider type ("fluentcrm" → "FluentCRM"),
     * from the same registry the editor localizes; falls back to a title-cased type.
     *
     * @param string $type
     * @return string
     */
    protected static function emailProviderName($type)
    {
        if ($type === '' || $type === 'email') {
            return __('Email', 'fluent-player');
        }
        // Registry is rebuilt on every getProvidersMetaData() call; memoize it
        // per request so labeling every layer across the pickers stays O(1).
        static $meta = null;
        if ($meta === null) {
            $meta = (array) \FluentPlayer\App\Services\EmailProviderService::getProvidersMetaData();
        }
        $name = (string) Arr::get($meta, $type . '.name', '');
        if ($name !== '') {
            return $name;
        }
        return implode(' ', array_map('ucfirst', explode('_', $type)));
    }

    public static function layerOptionGroups()
    {
        $typeLabels = self::layerTypeLabels();
        $groups = [];

        foreach (self::mediaPosts() as $post) {
            $layers = Arr::get(Media::getMediaSettings($post->ID), 'layers', []);
            if (!is_array($layers) || empty($layers)) {
                continue;
            }

            $options = [];
            foreach ($layers as $layer) {
                if (!is_array($layer) || empty($layer['id'])) {
                    continue;
                }
                $type = (string) Arr::get($layer, 'type', '');
                // email capture layers are stored as a cta subtype
                if ($type === 'cta' && Arr::get($layer, 'cta_type') === 'email') {
                    $type = 'email';
                }
                if (!isset($typeLabels[$type])) {
                    continue;
                }
                // Same label the editor's Interactive Layers list shows (provider
                // name, form title, "Ad #Video"); type label only if unresolved.
                $label = self::layerDisplayLabel($layer);
                $label = $label !== ''
                    ? trim(html_entity_decode($label, ENT_QUOTES | ENT_HTML5))
                    : $typeLabels[$type];
                $displayTime = (int) Arr::get($layer, 'displayTime', 0);
                $options[] = [
                    'id'    => $post->ID . ':' . $layer['id'],
                    'title' => $label . ' (' . $typeLabels[$type] . ' @ ' . gmdate('i:s', $displayTime) . ')',
                ];
            }

            if ($options) {
                $groups[] = [
                    'title'   => self::mediaTitle($post),
                    'slug'    => 'media-' . $post->ID,
                    'options' => $options,
                ];
            }
        }

        $counts = array_count_values(array_column($groups, 'title'));
        foreach ($groups as &$group) {
            if ($counts[$group['title']] > 1) {
                $group['title'] .= ' (#' . substr($group['slug'], 6) . ')';
            }
        }

        return $groups;
    }

    public static function flatLayerOptions($search = '')
    {
        $flat = [];
        foreach (self::layerOptionGroups() as $group) {
            foreach ($group['options'] as $option) {
                $title = $group['title'] . ' — ' . $option['title'];
                if ($search !== '' && stripos($title, $search) === false) {
                    continue;
                }
                $flat[] = ['id' => $option['id'], 'title' => $title];
            }
        }
        return $flat;
    }

    public static function layerMatches($config, $ctx)
    {
        $config = is_array($config) ? $config : [];

        if ((string) Arr::get($config, 'layer_event', '') !== (string) Arr::get($ctx, 'event', '')) {
            return false;
        }

        $values = Arr::get($config, 'layer_ids', []);
        $values = is_array($values) ? array_map('strval', $values) : [];
        $needle = (int) Arr::get($ctx, 'media_id') . ':' . (string) Arr::get($ctx, 'layer_id');

        return in_array($needle, $values, true);
    }

    public static function layerEventInfoFields()
    {
        $map = [
            'seen'      => __('Fires the first time the layer is shown to the viewer during playback.', 'fluent-player'),
            'completed' => __('What counts as completed depends on the layer type: an ad played to the end, a CTA acted on, or a form / email capture submitted.', 'fluent-player'),
            'skipped'   => __('Fires when the viewer skips or dismisses the layer — for example an ad skip button, or closing a CTA.', 'fluent-player'),
        ];

        $fields = [];
        foreach ($map as $value => $text) {
            $fields['layer_event_info_' . $value] = [
                'type'       => 'html',
                'info'       => self::infoLine($text),
                'dependency' => ['depends_on' => 'layer_event', 'operator' => '=', 'value' => $value],
            ];
        }
        return $fields;
    }

    /**
     * "What to watch" block shared by the milestone trigger and benchmark:
     * media picker, milestone radio and the threshold radio with their info lines.
     */
    public static function milestoneTargetingFields()
    {
        $fields = [
            'section_watch' => [
                'type' => 'html',
                'info' => self::sectionHeading(__('What to watch', 'fluent-player')),
            ],
            'media_ids' => [
                'type'        => 'multi-select',
                'is_multiple' => true,
                'label'       => __('Target Media', 'fluent-player'),
                'help'        => __('Select the Fluent Player media (video or audio) this automation applies to', 'fluent-player'),
                'placeholder' => __('Any media', 'fluent-player'),
                'options'     => self::mediaOptions(),
                'inline_help' => __('Leave empty to run for any media.', 'fluent-player'),
            ],
            'milestone' => [
                'type'    => 'radio',
                'label'   => __('Watch Milestone', 'fluent-player'),
                'options' => self::milestoneOptions(),
            ],
        ];
        $fields = array_merge($fields, self::milestoneInfoFields());

        $fields['completion_threshold'] = [
            'type'       => 'radio',
            'label'      => __('Completion Threshold', 'fluent-player'),
            'options'    => self::thresholdOptions(),
            'dependency' => [
                'depends_on' => 'milestone',
                'operator'   => '=',
                'value'      => 'completed',
            ],
        ];

        return array_merge($fields, self::thresholdInfoField());
    }

    /**
     * "Which layer interaction" block shared by the layer trigger and benchmark:
     * grouped layer picker and the event radio with its info lines.
     */
    public static function layerTargetingFields()
    {
        $fields = [
            'section_watch' => [
                'type' => 'html',
                'info' => self::sectionHeading(__('Which layer interaction', 'fluent-player')),
            ],
            'layer_ids' => [
                'type'        => 'grouped-select',
                'is_multiple' => true,
                'label'       => __('Target Layers', 'fluent-player'),
                'help'        => __('Select one or more layers, grouped by their media', 'fluent-player'),
                'placeholder' => __('Select layers', 'fluent-player'),
                'options'     => self::layerOptionGroups(),
            ],
            'layer_event' => [
                'type'    => 'radio',
                'label'   => __('Layer Event', 'fluent-player'),
                'options' => self::layerEventOptions(),
            ],
        ];

        return array_merge($fields, self::layerEventInfoFields());
    }

    /**
     * Non-contact policy block shared by all four blocks; the section heading
     * differs between triggers ("Who can enter") and goals ("Entry rules").
     */
    public static function entryFields($heading)
    {
        $fields = [
            'section_entry' => [
                'type' => 'html',
                'info' => self::sectionHeading($heading),
            ],
            'on_non_contact' => [
                'type'    => 'radio',
                'label'   => __('If the viewer is not in your CRM yet', 'fluent-player'),
                'options' => self::onNonContactOptions(),
            ],
        ];

        return array_merge($fields, self::nonContactNoticeFields());
    }

    public static function runMultipleFields($onceNote = '', $checkLabel = '', $offNote = '')
    {
        $fields = [
            'run_multiple' => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => $checkLabel !== '' ? $checkLabel : __('Allow contacts to go through this automation more than once', 'fluent-player'),
            ],
        ];

        return array_merge($fields, self::runMultipleNoticeFields($onceNote, $offNote));
    }

    public static function updateTypeConditionFields()
    {
        return [
            'update_type' => [
                'type'    => 'radio',
                'label'   => __('If Contact Already Exists?', 'fluent-player'),
                'help'    => __('Specify what happens if the subscriber already exists', 'fluent-player'),
                'options' => FunnelHelper::getUpdateOptions(),
            ],
        ];
    }

    /**
     * Shared trigger flow: match → resolve subscriber → policies → start funnel.
     */
    public static function handleTriggerEvent($funnel, $ctx, $matches, $triggerName)
    {
        if (!$matches) {
            return;
        }

        $resolved = self::resolveSubscriberData($ctx, $funnel);
        if (!$resolved) {
            return;
        }
        list($subscriberData, $subscriber) = $resolved;

        $config = wp_parse_args((array) $funnel->settings, (array) $funnel->conditions);

        if ($subscriber && Arr::get($config, 'update_type') === 'skip_all_if_exist') {
            return;
        }

        if ($subscriber && FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id)) {
            if (Arr::get($config, 'run_multiple') === 'yes') {
                FunnelHelper::removeSubscribersFromFunnel($funnel->id, [$subscriber->id]);
            } else {
                return;
            }
        }

        // new contacts carry the status resolved from on_non_contact; existing
        // contacts get no status key so their current status is never changed
        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $triggerName,
            'source_ref_id'       => (int) Arr::get($ctx, 'media_id'),
        ]);
    }

    /**
     * Shared benchmark flow: match → resolve subscriber → advance/enter at the goal.
     */
    public static function handleBenchmarkEvent($benchMark, $ctx, $matches, $triggerName)
    {
        if (!$matches) {
            return;
        }

        $resolved = self::resolveSubscriberData($ctx, $benchMark);
        if (!$resolved) {
            return;
        }
        list($subscriberData, $subscriber) = $resolved;

        if (!$subscriber) {
            $subscriber = FunnelHelper::createOrUpdateContact($subscriberData);
            // the benchmark path enters mid-sequence, so send the opt-in the trigger path would
            if ($subscriber && $subscriber->status === 'pending' && method_exists($subscriber, 'sendDoubleOptinEmail')) {
                $subscriber->sendDoubleOptinEmail();
            }
        }
        if (!$subscriber) {
            return;
        }

        (new FunnelProcessor())->startFunnelFromSequencePoint($benchMark, $subscriber, [], [
            'source_trigger_name' => $triggerName,
            'source_ref_id'       => (int) Arr::get($ctx, 'media_id'),
        ]);
    }

    /**
     * Does the event context match this funnel/benchmark's media + milestone config?
     */
    public static function milestoneMatches($config, $ctx)
    {
        $config = is_array($config) ? $config : [];

        $mediaId = (int) Arr::get($ctx, 'media_id');
        $mediaIds = Arr::get($config, 'media_ids', []);
        $mediaIds = is_array($mediaIds) ? $mediaIds : [];
        if (!empty($mediaIds) && !in_array('any', $mediaIds, true)) {
            $ids = array_map('intval', $mediaIds);
            if (!in_array($mediaId, $ids, true)) {
                return false;
            }
        }

        $wanted = (string) Arr::get($config, 'milestone', '');
        $milestone = (string) Arr::get($ctx, 'milestone', '');

        if ($wanted === 'completed') {
            if ($milestone !== 'completed') {
                return false;
            }
            $threshold = (float) Arr::get($config, 'completion_threshold', 0.9);
            // completion events fire once per armed threshold, so match on the event's
            // own boundary — a raw coverage compare would miss the tolerance-capped
            // 100% threshold and could match a funnel to another funnel's event
            $boundary = Arr::get($ctx, 'boundary');
            if ($boundary !== null && $boundary !== '') {
                return abs((float) $boundary - $threshold) < 0.0001;
            }
            // null boundary = genuine ended on unmeasurable media — the ended IS the completion
            return true;
        }

        return $wanted !== '' && $wanted === $milestone;
    }

    /**
     * Resolve the funnel subscriber data + existing subscriber from the event, honouring
     * the "if not a contact yet" policy (never creating silently).
     *
     * @return array|false [$subscriberData, $subscriber|null] or false to skip
     */
    public static function resolveSubscriberData($ctx, $funnel)
    {
        $userId = (int) Arr::get($ctx, 'user_id');
        $email = sanitize_email((string) Arr::get($ctx, 'email'));

        $subscriberData = [];
        if ($userId) {
            $subscriberData = FunnelHelper::prepareUserData($userId);
            if (empty($email)) {
                $email = sanitize_email(Arr::get($subscriberData, 'email', ''));
            }
        }

        if (empty($email) || !is_email($email)) {
            return false;
        }
        $subscriberData['email'] = $email;

        $subscriber = FunnelHelper::getSubscriber($email);

        if (!$subscriber) {
            $onNonContact = Arr::get($funnel->settings, 'on_non_contact', 'skip');
            // clamp "subscribed" down to pending — never silently subscribe
            if ($onNonContact === 'subscribed') {
                $onNonContact = 'pending';
            }
            if ($onNonContact !== 'pending') {
                return false;
            }
            $subscriberData['status'] = 'pending';
            $subscriberData['source'] = 'Fluent Player';
        }

        return [$subscriberData, $subscriber];
    }
}
