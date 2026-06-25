<?php
if (!defined('ABSPATH')) exit;

/**
 * All registered action's handlers should be in app\Hooks\Handlers,
 * addAction is similar to add_action and addCustomAction is just a
 * wrapper over add_action which will add a prefix to the hook name
 * using the plugin slug to make it unique in all wordpress plugins,
 * ex: $app->addCustomAction('foo', ['FooHandler', 'handleFoo']) is
 * equivalent to add_action('slug-foo', ['FooHandler', 'handleFoo']).
 */

/**
 * @var $app FluentPlayer\Framework\Foundation\Application
 */

$app->addAction('admin_menu', ['FluentPlayer\App\Hooks\Handlers\AdminMenuHandler', 'handle']);

$app->addAction('admin_notices', function () {
    if (!defined('FLUENT_PLAYER_PRO_VERSION') || !current_user_can('activate_plugins')) {
        return;
    }

    if (version_compare(FLUENT_PLAYER_PRO_VERSION, FLUENT_PLAYER_MIN_PRO_VERSION, '>=')) {
        return;
    }

    echo '<div class="notice notice-error"><p>';
    echo esc_html(sprintf(
        /* translators: %s: minimum recommended FluentPlayer Pro version */
        __('Your FluentPlayer Pro is outdated. Please update FluentPlayer Pro to version %s or higher for full compatibility.', 'fluent-player'),
        FLUENT_PLAYER_MIN_PRO_VERSION
    ));
    echo '</p></div>';
});

$app->addFilter(
    'plugin_action_links_' . plugin_basename(FLUENT_PLAYER_DIR_FILE),
    ['FluentPlayer\App\Hooks\Handlers\AdminMenuHandler', 'addPluginActionLinks']
);

/**
 * Enable this line if you want to use custom post types
 */

$app->addAction('init', ['FluentPlayer\App\Hooks\Handlers\CPTHandler', 'registerPostTypes']);

$app->addAction('init', ['FluentPlayer\App\Hooks\Handlers\FluentPlayerMediaCPT', 'addRewriteRules'], 20);
$app->addAction('init', ['FluentPlayer\App\Hooks\Handlers\CPTHandler', 'maybeFlushRules'], 30);

/**
 * Register Gutenberg blocks
 *
 * Initialize default preset if none exists
 */
$app->addAction('init', ['FluentPlayer\App\Hooks\Handlers\BlocksHandler', 'handle']);

/**
 * Initialize default preset if none exists
 */
//$app->addAction('init', ['FluentPlayer\App\Models\Preset', 'maybeCreateDefaultPreset']);



$app->addAction('init', ['FluentPlayer\App\Hooks\Handlers\EmailCollectionHandler', 'handle']);

/**
 * Daily scheduled cleanup — individual tasks hook into this event
 */
$app->addAction('fluent_player/daily_cleanup', ['FluentPlayer\App\Models\Media', 'cleanupAutoDrafts']);

// Ensure daily cleanup cron is scheduled (covers plugin updates without reactivation)
if (!wp_next_scheduled('fluent_player/daily_cleanup')) {
    \FluentPlayer\App\Hooks\Handlers\ScheduledCleanupHandler::schedule();
}

/**
 * After media was deleted: remove related email collections.
 */
$app->addAction('fluent_player/after_delete_media', function ($mediaId) {
    $mediaId = absint($mediaId);
    if ($mediaId <= 0) {
        return;
    }
    \FluentPlayer\App\Models\EmailCollection::query()->where('media_id', $mediaId)->delete();
}, 10, 1);

/**
 * Also clean up when a fluent_player_media post is deleted directly via WP admin (not plugin API).
 */
$app->addAction('before_delete_post', function ($postId) {
    if (get_post_type($postId) !== 'fluent_player_media') {
        return;
    }
    $mediaId = absint($postId);
    if ($mediaId <= 0) {
        return;
    }
    \FluentPlayer\App\Models\EmailCollection::query()->where('media_id', $mediaId)->delete();
    $settings = get_post_meta($mediaId, 'settings', true);
    // Allow addons to clean up managed media assets while settings/meta still exist.
    do_action('fluent_player/before_delete_media', $mediaId, $settings);
    if (!\FluentPlayer\App\Http\Controllers\MediaController::hasProStoryboardOwnership()) {
        // Keep a Free fallback so old storyboard assets are still removable when Pro is inactive.
        \FluentPlayer\App\Http\Controllers\MediaController::cleanupGeneratedStoryboard($settings, $mediaId);
    }
});

$app->addAction('deleted_post', function ($postId, $post) {
    if (!$post || $post->post_type !== 'fluent_player_media') {
        return;
    }

    $mediaId = absint($postId);
    if ($mediaId <= 0) {
        return;
    }

    // Fire the same hook that Media::delete() uses once WordPress has actually deleted the post.
    do_action('fluent_player/after_delete_media', $mediaId);
}, 10, 2);

/**
 * This is being used to update the slug when idle.
 */
$app->addFilter('heartbeat_send', function($response, $data) use ($app) {
	$key = $app->config->get('app.slug');
	$response[$key] = wp_create_nonce('wp_rest');
    return $response;
}, 10, 2);

/**
 * Register Google Analytics integration
 */
$app->addAction('wp_enqueue_scripts', function() {
    $service = new FluentPlayer\App\Services\GoogleAnalyticsService();
    $service->enqueueScript();
});

// Register both shortcode names for compatibility
// [fluentplayer] is the preferred shortcode name (matches plugin branding)
// [fluentmedia] is kept for backward compatibility with existing content
$app->addShortCode('fluentplayer', ['FluentPlayer\App\Hooks\Handlers\MediaShortcodeHandler', 'handle']);
$app->addShortCode('fluentmedia', ['FluentPlayer\App\Hooks\Handlers\MediaShortcodeHandler', 'handle']);

// Front-end only: these handles are never enqueued in admin.
if (!is_admin()) {
    add_filter('style_loader_tag', function($tag, $handle) {
        if ($handle === 'fluent_player_css') {
            return str_replace("media='all'", "media='print' onload=\"this.media='all'\"", $tag) . '<noscript>' . $tag . '</noscript>';
        }
        return $tag;
    }, 10, 2);

    add_filter('script_loader_tag', function($tag, $handle) {
        if ($handle === 'fluent_player-google-analytics' && strpos($tag, 'async') === false) {
            $tag = str_replace('<script ', '<script async ', $tag);
        }

        if ($handle === 'fluent_player' && strpos($tag, 'fetchpriority') === false) {
            $tag = str_replace('<script ', '<script fetchpriority="low" ', $tag);
        }

        return $tag;
    }, 10, 2);
}

$app->addShortCode('fluentplayer_timestamp', function ($atts, $content) {
    $atts = shortcode_atts(
        [
            'time' => '',
            'media_id' => '',
        ],
        $atts
    );
    $time = esc_attr($atts['time']);
    $media_id = esc_attr($atts['media_id']);
    
    $content_safe = wp_kses_post($content);
    return "<fluentplayer-timestamp time='{$time}' media_id='{$media_id}'>{$content_safe}</fluentplayer-timestamp>";
});

$app->addAction('template_redirect', function() use ($app) {
    // @todo better approach for adding custom css
    if ($mediaId = $app->request->get('fluent_player_media_id')) {
        $media = \FluentPlayer\App\Models\Media::find($mediaId);
        if ($media) {
            $mediaData = \FluentPlayer\App\Services\MediaService::prepareMediaForFrontend($media, 'media-page');

            // Enqueue styles at the correct time (before view rendering)
            wp_enqueue_style(
                'fluent_player_css',
                '',
                [],
                FLUENT_PLAYER_VERSION
            );

            \FluentPlayer\App\Helpers\Helper::enqueuePlayerStyles(
                $mediaData['media']->ID,
                $mediaData['media']->settings,
                $mediaData['default_settings']
            );
        }

        $app->view->render('frontend.media-page', [
            'mediaId' => $mediaId,
            'app'     => $app
        ]);
        exit;
    }
});

$app->addAction('fluent_community/portal_loaded', ['\FluentPlayer\App\Blocks\FluentCommunityMediaBlock', 'register']);

$app->addAction('fluent_player/register_email_providers', function() {
    // Initialize providers array
    $providers = [];
    
    // Only register FluentCRM in free version
    if (class_exists('FluentPlayer\App\EmailProviders\FluentCRMProvider')) {
        $providers[] = new FluentPlayer\App\EmailProviders\FluentCRMProvider();
    }
    
    foreach ($providers as $provider) {
        FluentPlayer\App\Services\EmailProviderService::registerProvider($provider);
    }
});

$app->addFilter('fluent_player/email_provider_placeholder_meta', function ($placeholders) {
    if (\FluentPlayer\App\Helpers\Helper::hasPro()) {
        return $placeholders;
    }
    $placeholders['webhook'] = [
        'name' => 'Webhook',
        'description' => __('Send collected emails to any external service via webhooks.', 'fluent-player'),
        'logo' => 'webhook-icon.svg',
        'settings_fields' => []
    ];
    $placeholders['mailchimp'] = [
        'name' => 'Mailchimp',
        'description' => __('Connect FluentPlayer with Mailchimp to add email subscribers to your lists.', 'fluent-player'),
        'logo' => 'mailchimp-icon.svg',
        'settings_fields' => []
    ];
    return $placeholders;
});

/**
 * Redirect the default 'fluent_player_media' CPT admin list page
 * to the custom Fluent Player media library page or playlist page
 */
$app->addAction('admin_init', function () {
    global $pagenow;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect only; no state change; post_type is sanitized
    if (is_admin() && isset($_GET['post_type'])) {
        $post_type = sanitize_text_field(wp_unslash($_GET['post_type']));
        if ('edit.php' === $pagenow && $post_type === 'fluent_player_media') {
            $redirect_url = admin_url('admin.php?page=fluent-player#/');
        } elseif ('edit.php' === $pagenow && $post_type === 'fluent_playlist') {
            $redirect_url = admin_url('admin.php?page=fluent-player#/playlists');
        } elseif ('post-new.php' === $pagenow && $post_type === 'fluent_playlist' && !\FluentPlayer\App\Helpers\Helper::hasPro()) {
            $redirect_url = admin_url('admin.php?page=fluent-player#/playlists');
        }
        if (isset($redirect_url)) {
            wp_safe_redirect($redirect_url);
            exit;
        }
    }
});
