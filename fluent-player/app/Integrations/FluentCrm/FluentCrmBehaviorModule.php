<?php

namespace FluentPlayer\App\Integrations\FluentCrm;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\App\Hooks\Handlers\AbstractBehaviorHandler;
use FluentPlayer\App\Hooks\Handlers\MediaMilestoneHandler;

class FluentCrmBehaviorModule
{
    public function register()
    {
        if (!defined('FLUENTCRM')) {
            return $this;
        }

        MediaMilestoneHandler::register();
        \FluentPlayer\App\Hooks\Handlers\LayerEventHandler::register();
        (new BehaviorConditions())->register();

        add_action('init', function () {
            if (!class_exists(\FluentCrm\App\Services\Funnel\BaseTrigger::class)) {
                return;
            }
            new MediaMilestoneTrigger();
            new MediaMilestoneBenchmark();
            new LayerEventTrigger();
            new LayerEventBenchmark();
        }, 9);

        add_filter('fluent_player/global_vars', [$this, 'injectClientConfig']);

        add_filter('fluent_crm/funnel_icons', function ($icons) {
            $icons['fluentplayer'] = ['svg' => Helper::iconSvg()];
            return $icons;
        });

        add_action('admin_head', [$this, 'printBrandIconStyle']);

        add_action('add_option_fluentcrm_funnel_settings', [BehaviorRegistry::class, 'flush']);
        add_action('update_option_fluentcrm_funnel_settings', [BehaviorRegistry::class, 'flush']);
        add_action('add_option_' . \FluentPlayer\App\Services\EmailProviderService::EMAIL_PROVIDERS_SETTINGS_KEY, [BehaviorRegistry::class, 'flush']);
        add_action('update_option_' . \FluentPlayer\App\Services\EmailProviderService::EMAIL_PROVIDERS_SETTINGS_KEY, [BehaviorRegistry::class, 'flush']);
        foreach ([BehaviorRegistry::TRIGGER_MILESTONE, BehaviorRegistry::TRIGGER_LAYER] as $triggerName) {
            add_action('fluent_crm/sequence_created_' . $triggerName, [BehaviorRegistry::class, 'flush']);
            add_action('fluentcrm_funnel_sequence_deleting_' . $triggerName, [BehaviorRegistry::class, 'flush']);
        }

        add_action('fluentcrm_after_subscribers_deleted', [BehaviorState::class, 'onContactsDeleted']);
        add_action('fluent_player/after_delete_media', [BehaviorState::class, 'onMediaDeleted']);
        add_action('fluent_player/daily_cleanup', [AbstractBehaviorHandler::class, 'reapStaleLocks']);

        add_filter('fluent_player/email_provider_meta', [$this, 'extendProviderMeta']);

        TimelineBridge::register();

        return $this;
    }

    /**
     * CRM-side facts the FluentCRM config page needs: whether Event Tracking
     * is enabled (the timeline bridge is a no-op without it), how many
     * automations use our triggers, and the deep links for both.
     */
    public function extendProviderMeta($meta)
    {
        if (!isset($meta['fluentcrm'])) {
            return $meta;
        }

        $eventTrackingEnabled = false;
        if (class_exists(\FluentCrm\App\Services\Helper::class)) {
            $eventTrackingEnabled = \FluentCrm\App\Services\Helper::isExperimentalEnabled('event_tracking');
        }

        $automationCount = 0;
        if (class_exists(\FluentCrm\App\Models\Funnel::class)) {
            try {
                $automationCount = \FluentCrm\App\Models\Funnel::whereIn('trigger_name', [
                    BehaviorRegistry::TRIGGER_MILESTONE,
                    BehaviorRegistry::TRIGGER_LAYER,
                ])->where('status', 'published')->count();
            } catch (\Exception $e) {
                $automationCount = 0;
            }
        }

        $meta['fluentcrm']['crm_behavior'] = [
            'event_tracking_enabled' => (bool) $eventTrackingEnabled,
            'automation_count'       => (int) $automationCount,
            'automation_count_text'  => $automationCount > 0
                ? sprintf(
                    /* translators: %d: number of published automations using Fluent Player triggers */
                    _n('%d automation uses Fluent Player triggers', '%d automations use Fluent Player triggers', $automationCount, 'fluent-player'),
                    $automationCount
                )
                : __('No automations use Fluent Player triggers yet', 'fluent-player'),
            'automation_editor_url'  => admin_url('admin.php?page=fluentcrm-admin#/funnels'),
            'event_tracking_url'     => admin_url('admin.php?page=fluentcrm-admin#/add-ons'),
        ];

        return $meta;
    }

    /**
     * The funnel editor renders sequence/goal node icons from the block's `icon`
     * class only (never the `svg` key), so the brand mark has to ship as CSS.
     */
    public function printBrandIconStyle()
    {
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($page !== 'fluentcrm-admin') {
            return;
        }

        echo '<style id="flp-crm-brand-icon">.fc-icon-flp-brand{display:inline-block;min-width:16px;min-height:16px;background-image:url("data:image/svg+xml,'
            . rawurlencode(Helper::iconSvg())
            . '")!important;background-repeat:no-repeat!important;background-position:center!important;background-size:62%!important;}</style>';
    }

    public function injectClientConfig($vars)
    {
        // active first — consent resolves the CRM contact, which must not run
        // on unarmed sites (zero-cost invariant)
        $config = BehaviorRegistry::clientConfig();
        if (!Arr::isTrue($config, 'active')) {
            return $vars;
        }

        // print-time veto: reporters never attach for non-consenting visitors
        if (!AbstractBehaviorHandler::canReport()) {
            return $vars;
        }

        $config['nonce'] = wp_create_nonce(AbstractBehaviorHandler::NONCE_ACTION);
        $config['identity'] = [
            'isLoggedIn' => is_user_logged_in(),
            'hasContact' => Identity::isCurrentUserContact(),
        ];

        $vars['crm_behavior'] = $config;
        return $vars;
    }

}
