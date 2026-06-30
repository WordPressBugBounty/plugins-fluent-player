<?php

namespace FluentPlayer\App\Blocks;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Http\Controllers\MediaController;
use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Models\Post;
use FluentPlayer\App\Services\MediaService;
use FluentPlayer\App\Services\SettingsService;
use FluentPlayer\App\Services\UnlockService;
use FluentPlayer\App\Utils\Enqueuer\Vite;
use FluentPlayer\Framework\Support\Arr;

class FluentCommunityMediaBlock
{
    /**
     * Register the block specifically for FluentCommunity
     */
    public function register()
    {
        if (!defined('FLUENT_COMMUNITY_PLUGIN_VERSION')) {
            return;
        }
        
        // MediaBlock assets
        add_action('fluent_enqueue_block_editor_assets', [$this, 'enqueueMediaBlockAssets']);
        
        // Allow Fluent Player assets
        add_filter('fluent_com_editor/asset_listed_slugs', [$this, 'addFluentPlayerAssetSlugs']);
        add_filter('fluent_community/asset_listed_slugs', [$this, 'addFluentPlayerAssetSlugs']);
        
        // Add Fluent Player media block
        add_filter('fluent_community/allowed_block_types', [$this, 'addFluentPlayerBlockType']);
        
        // Media update action
        add_action('fluent_community/lesson/additional_media_updated', [$this, 'handleAdditionalMediaUpdated'], 10, 3);
        
        // Add Fluent Player assets to FluentCommunity's portal data system (CLEAN APPROACH)
        add_filter('fluent_community/portal_data_vars', [$this, 'addFluentPlayerToPortalData'], 10, 1);



        // Inject FluentPlayer assets into the editor-canvas iframe for apiVersion 3 compatibility.
        // FluentCommunity builds __unstableResolvedAssets manually (doesn't use WP's _wp_get_iframed_editor_assets),
        // so we inject our block script + CSS via their settings filter.
        add_filter('fluent_community/block_editor_settings', [$this, 'injectIframeAssets'], 100);

        // Add autoplay permission to Fluent Community's editor iframes so YouTube can play.
        // Needed on BOTH the portal page (outer iframe) and the WP admin editor (editor-canvas).
        add_action('fluent_community/portal_footer', [$this, 'injectIframePermissions']);
        add_action('fluent_community/block_editor_head', [$this, 'injectIframePermissions']);

        // Inject CSS when FluentPlayer blocks are processed
        add_filter('the_content', [$this, 'injectFluentPlayerCSS'], 999, 1);

        // Invalidate the cached media list when a media post is saved or its status changes
        add_action('save_post_fluent_player_media', function() {
            delete_transient('fp_fc_media_items_' . FLUENT_PLAYER_VERSION);
        });

        // AJAX handler for fetching media data
        add_action('wp_ajax_fluent_player_get_media_data', [$this, 'handleGetMediaDataAjax']);
        add_action('wp_ajax_nopriv_fluent_player_get_media_data', [$this, 'handleGetMediaDataAjax']);
    }
    
    /**
     * Check if we're in FluentCommunity context
     * @return bool True if in FluentCommunity context, false otherwise
     */
    private function isFluentCommunityContext($data = [])
    {
        // Early exit if FluentCommunity is not active
        if (!defined('FLUENT_COMMUNITY_PLUGIN_VERSION')) {
            return false;
        }
		
		// Return false if in admin page
	    if ($data && is_array($data) && strpos(Arr::get($data, 'url', ''), '/admin/') !== false) {
            return false;
        }
        
        // Check for FluentCommunity actions/hooks
        if (did_action('fluent_community/portal_loaded') ||
            doing_action('fluent_community/portal_loaded') ||
            did_action('fluent_enqueue_block_editor_assets')) {
            return true;
        }
        
        // Check for FluentCommunity URL parameters
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checking for parameter existence, not processing form data
        if (isset($_GET['manage-courses']) ||
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checking for parameter existence, not processing form data
            isset($_REQUEST['manage-courses']) ||
            get_query_var('fcom_route')) {
            return true;
        }
        
        // Check for FluentCommunity URL patterns (frontend only)
        if (!is_admin()) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on next line
            $requestUri = isset($_SERVER['REQUEST_URI']) ? \wp_unslash($_SERVER['REQUEST_URI']) : '';
            $currentUrl = \sanitize_text_field($requestUri);
            $portalSlug = defined('FLUENT_COMMUNITY_PORTAL_SLUG') ? FLUENT_COMMUNITY_PORTAL_SLUG : 'portal';
            
            return strpos($currentUrl, '/' . $portalSlug . '/') !== false ||
                strpos($currentUrl, '/course/') !== false ||
                strpos($currentUrl, '/lessons/') !== false;
        }
        
        return false;
    }
    
    /**
     * Check if we're specifically on a FluentCommunity lesson page
     */
    private function isFluentCommunityLessonPage()
    {
        // Check if FluentCommunity is active
        if (!defined('FLUENT_COMMUNITY_PLUGIN_VERSION')) {
            return false;
        }
        
        // Only check on frontend (not admin)
        if (is_admin()) {
            return false;
        }
        
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on next line
        $requestUri = isset($_SERVER['REQUEST_URI']) ? \wp_unslash($_SERVER['REQUEST_URI']) : '';
        $currentUrl = \sanitize_text_field($requestUri);
        // Check for lesson page patterns
        $isLessonPage = strpos($currentUrl, '/lessons/') !== false &&
            strpos($currentUrl, '/view') !== false;
        
        // Also check for course lesson patterns
        $isCourseLesson = strpos($currentUrl, '/course/') !== false &&
            strpos($currentUrl, '/lessons/') !== false;
        
        return $isLessonPage || $isCourseLesson;
    }
    
    
    /**
     * Add Fluent Player asset slugs to FluentCommunity's allowed assets list
     */
    public function addFluentPlayerAssetSlugs($approvedSlugs)
    {
        $approvedSlugs[] = '\/fluent-player\/';

        $pluginDir = dirname(plugin_basename(FLUENT_PLAYER_DIR_FILE));
        if ($pluginDir && '.' !== $pluginDir) {
            $approvedSlugs[] = '\/' . preg_quote($pluginDir, '#') . '\/';
        }

        return $approvedSlugs;
    }
    
    /**
     * Add Fluent Player media block to FluentCommunity's allowed block types
     */
    public function addFluentPlayerBlockType($allowedBlockTypes)
    {
        $allowedBlockTypes[] = 'fluent-player/media';
        $allowedBlockTypes[] = 'fluent-player/timed-content';
        return apply_filters('fluent_player/fluent_community_allowed_blocks', $allowedBlockTypes);
    }
    
    /**
     * Handle FluentCommunity lesson additional media update
     * This method processes Fluent Player media data sent from the frontend
     * and ensures preset settings are properly applied.
     */
    public function handleAdditionalMediaUpdated($requestData, $lesson, $updateData)
    {
        $fluentPlayerMedia = $this->mergeLessonMediaUpdateData($requestData, $updateData);
        if (empty($fluentPlayerMedia)) {
            return;
        }

        foreach ($fluentPlayerMedia as $mediaData) {
            if (!isset($mediaData['mediaId'])) {
                continue;
            }

            $mediaId = absint($mediaData['mediaId']);
            if (
                !$mediaId
                || get_post_type($mediaId) !== Media::$postType
                || !current_user_can('edit_post', $mediaId)
            ) {
                continue;
            }
            if (!empty($mediaData['post_content']) && is_string($mediaData['post_content'])) {
                $this->updateMediaPostContent($mediaId, $mediaData['post_content']);
            }

            $frontendSettings = $mediaData['settings'] ?? null;
            if ($frontendSettings && is_array($frontendSettings)) {
                $sanitized = MediaController::sanitizeMediaData(['settings' => $frontendSettings]);
                $frontendSettings = $sanitized['settings'];
                $this->updateManagedMediaSettings($mediaId, $frontendSettings);
            }
        }
    }

    private function mergeLessonMediaUpdateData($requestData, $updateData)
    {
        $mergedMedia = [];
        $requestMedia = isset($requestData['fluent_player_media']) && is_array($requestData['fluent_player_media'])
            ? $requestData['fluent_player_media']
            : [];

        foreach ($requestMedia as $mediaData) {
            $mediaId = absint($mediaData['mediaId'] ?? 0);
            if (!$mediaId) {
                continue;
            }

            $mergedMedia[$mediaId] = $mediaData;
        }

        foreach ($this->extractMediaBlocksFromLessonContent(Arr::get($updateData, 'message', '')) as $mediaData) {
            $mediaId = absint($mediaData['mediaId'] ?? 0);
            if (!$mediaId) {
                continue;
            }

            $mergedMedia[$mediaId] = array_merge($mergedMedia[$mediaId] ?? [], $mediaData);
        }

        return array_values($mergedMedia);
    }

    private function extractMediaBlocksFromLessonContent($content)
    {
        if (!is_string($content) || !$content) {
            return [];
        }

        $mediaBlocks = [];
        $walker = function ($blocks) use (&$walker, &$mediaBlocks) {
            foreach ((array) $blocks as $block) {
                if (($block['blockName'] ?? '') === 'fluent-player/media') {
                    $mediaId = absint(Arr::get($block, 'attrs.mediaId'));
                    if ($mediaId) {
                        $mediaBlocks[$mediaId] = [
                            'mediaId'      => $mediaId,
                            'post_content' => serialize_blocks([$block]),
                        ];
                    }
                }

                if (!empty($block['innerBlocks'])) {
                    $walker($block['innerBlocks']);
                }
            }
        };

        $walker(parse_blocks($content));

        return array_values($mediaBlocks);
    }

    private function updateMediaPostContent($mediaId, $postContent)
    {
        $normalizedPostContent = $this->normalizeMediaPostContent($postContent);
        if (!$normalizedPostContent) {
            return;
        }

        wp_update_post([
            'ID'           => $mediaId,
            'post_content' => $normalizedPostContent,
        ]);
    }

    private function normalizeMediaPostContent($postContent)
    {
        if (!is_string($postContent)) {
            return '';
        }

        $blocks = parse_blocks(wp_unslash($postContent));
        if (empty($blocks)) {
            return '';
        }

        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === 'fluent-player/media') {
                return serialize_blocks([$block]);
            }
        }

        return '';
    }

    /**
     * Reconcile managed storyboard settings before saving FluentCommunity lesson updates.
     * This keeps the lesson flow aligned with the shared MediaController lifecycle.
     */
    private function updateManagedMediaSettings($mediaId, array $frontendSettings)
    {
        $existingSettings = $this->getNormalizedMediaSettings($mediaId);
        $cleanupSettings = null;

        if (MediaController::hasMediaSourceChanged($existingSettings, $frontendSettings)) {
            $cleanupSettings = $existingSettings;
            unset(
                $frontendSettings['thumbnails'],
                $frontendSettings['storyboard_attachment_id'],
                $frontendSettings['storyboard_generation_status'],
                $frontendSettings['storyboard_generation_message']
            );
        } else {
            $frontendSettings = MediaController::preserveManagedMediaSettings($existingSettings, $frontendSettings);
        }

        $mergedSettings = Media::mergePresetSettings($frontendSettings);
        update_post_meta($mediaId, 'settings', $mergedSettings);

        if ($cleanupSettings && $this->didPersistMediaSettings($mediaId, $mergedSettings)) {
            MediaController::cleanupGeneratedStoryboard($cleanupSettings, $mediaId);
        }
    }

    private function getNormalizedMediaSettings($mediaId)
    {
        $settings = get_post_meta($mediaId, 'settings', true);

        if (is_string($settings)) {
            $settings = Helper::safeUnserialize($settings);
        }

        return is_array($settings) ? $settings : [];
    }

    private function didPersistMediaSettings($mediaId, array $expectedSettings)
    {
        return $this->getNormalizedMediaSettings($mediaId) == $expectedSettings;
    }
    
    /**
     * Inject FluentPlayer script and CSS into the editor-canvas iframe's resolved assets.
     * WordPress's _wp_get_iframed_editor_assets() doesn't collect our assets in the
     * FluentCommunity context, so we add them directly to __unstableResolvedAssets.
     *
     * @param array $settings Block editor settings
     * @return array Modified settings
     */
    public function injectIframeAssets($settings)
    {
        // Inject a lightweight Vidstack-only loader (no WP dependencies) into the canvas iframe.
        // The full block script runs in the parent frame; this just registers custom elements.
        $loaderUrl = Vite::getEnqueuePath('blocks/media/vidstack-loader.js');
        $styleUrl = Vite::getEnqueuePath('scss/fluent-player-block.scss');
        $isDevMode = Vite::isOnDevMode();
        $ver = FLUENT_PLAYER_VERSION;

        if (!empty($settings['__unstableResolvedAssets'])) {
            if (!empty($loaderUrl) && strpos($settings['__unstableResolvedAssets']['scripts'] ?? '', 'vidstack-loader') === false) {
                // Always type="module" — the loader uses ES imports for Vidstack
                $verParam = $isDevMode ? '' : '?ver=' . $ver;
                $settings['__unstableResolvedAssets']['scripts'] .= '<script type="module" crossorigin="anonymous" src="' . esc_url($loaderUrl . $verParam) . '" id="fp-vidstack-loader-js"></script>';
            }
            if (!empty($styleUrl) && strpos($settings['__unstableResolvedAssets']['styles'] ?? '', 'fluent-player') === false) {
                $verParam = $isDevMode ? '' : '?ver=' . $ver;
                $settings['__unstableResolvedAssets']['styles'] .= '<link rel="stylesheet" href="' . esc_url($styleUrl . $verParam) . '" id="fluent-player-block-style-css" />';
            }
        }

        // Allow pro and other addons to inject additional assets into the canvas iframe
        $settings = apply_filters('fluent_player/fluent_community_iframe_assets', $settings, $isDevMode, $ver);

        return $settings;
    }

    /**
     * Add autoplay/fullscreen permissions to Fluent Community's editor iframe.
     * Without these, YouTube videos can't play inside the nested iframe chain
     * (portal → WP admin iframe → editor-canvas → YouTube iframe).
     */
    public function injectIframePermissions()
    {
        ?>
        <script>
        (function() {
            // Guard: only patch once per document — this method is hooked to two actions
            // (portal_footer and block_editor_head) and may run in the same context twice.
            if (window._fluentPlayerPermsPatcher) return;
            window._fluentPlayerPermsPatcher = true;
            var perms = 'autoplay; fullscreen; encrypted-media; picture-in-picture; accelerometer; gyroscope';
            // Intercept setAttribute to add required permissions when a YouTube or Vimeo
            // src is assigned to an iframe. This is more targeted than patching createElement
            // for all iframes — it does not affect ads, social embeds, or other third-party
            // iframes that are not video providers.
            //
            // Timing: src and allow must be set in the same synchronous block so the browser
            // applies the policy before making the network request for the embed URL.
            // Setting allow immediately after setting src (same tick) satisfies this.
            var origSetAttr = Element.prototype.setAttribute;
            Element.prototype.setAttribute = function(attr, val) {
                // Set allow BEFORE src so the browser applies the permissions policy
                // before it starts the navigation request for the embed URL.
                if (this.tagName === 'IFRAME' && attr.toLowerCase() === 'src' &&
                    /youtube\.com|youtu\.be|vimeo\.com/.test(val)) {
                    var existing = this.getAttribute('allow') || '';
                    var missing = perms.split('; ').filter(function(p) {
                        return existing.indexOf(p.trim()) === -1;
                    });
                    if (missing.length) {
                        origSetAttr.call(this, 'allow', existing ? existing + '; ' + missing.join('; ') : missing.join('; '));
                    }
                }
                origSetAttr.call(this, attr, val);
            };
        })();
        </script>
        <?php
    }

    /**
     * Enqueue MediaBlock assets for FluentCommunity context
     */
    public function enqueueMediaBlockAssets()
    {
        // Only enqueue if we're in FluentCommunity context
        if (!$this->isFluentCommunityContext()) {
            return;
        }
        
        // Prevent double loading by checking if assets are already enqueued
        if (wp_script_is('fluent-player-block-editor', 'enqueued')) {
            return;
        }
        
        // Load the full MediaBlock instead of standalone version
        $mediaBlock = new MediaBlock();
        
        // Enqueue the full block editor assets (this will load the JSX version)
        $mediaBlock->enqueueBlockEditorAssets();
        
        // Prepare block editor data specifically for FluentCommunity
        $this->prepareFluentCommunityBlockData();

        // Allow pro and other addons to enqueue additional block assets for the FC editor
        do_action('fluent_player/fluent_community_enqueue_block_assets');
    }
    
    /**
     * Prepare block editor data specifically for FluentCommunity
     */
    private function prepareFluentCommunityBlockData()
    {
        // Cache the formatted media items — the paginate query is the expensive part.
        // Nonces and user-specific data (rest info) are built fresh each time.
        $mediaCacheKey = 'fp_fc_media_items_' . FLUENT_PLAYER_VERSION;
        $mediaItems = get_transient($mediaCacheKey);
        if ($mediaItems === false) {
            $mediaService = new MediaService();
            $paginator = $mediaService->paginate(['per_page' => 100, 'status' => 'publish']);
            $mediaItems = $this->getMediaItems($paginator->getCollection());
            set_transient($mediaCacheKey, $mediaItems, 5 * MINUTE_IN_SECONDS);
        }

        $defaultSettings = SettingsService::getMediaDefaultSettings();

        $mediaBlockVars = [
            'mediaItems'        => $mediaItems,
            'rest'              => Helper::getRestInfo(),
            'pluginUrl'         => FLUENT_PLAYER_URL,
            'defaultSettings'   => $defaultSettings,
            'hasPro'            => Helper::hasPro(),
            'subtitleApi'       => $this->getSubtitleApiFlags(),
            'isFluentCommunity' => true,
            'context'           => 'fluent-community',
            'version'           => FLUENT_PLAYER_VERSION,
            'languages'         => Helper::getLanguages(),
            'languageFlags'     => Helper::getTopLanguagesWithFlags(),
        ];

        $mediaBlockVars = apply_filters('fluent_player/fluent_community_block_vars', $mediaBlockVars, $defaultSettings);

        // Use wp_add_inline_script (before) rather than wp_localize_script so the vars
        // reach FluentCommunity's isolated editor-canvas iframe via __unstableResolvedAssets.
        wp_add_inline_script(
            'fluent-player-block-editor',
            'window.fluentPlayerBlockVars = ' . wp_json_encode($mediaBlockVars) . ';',
            'before'
        );
    }

    private function getSubtitleApiFlags()
    {
        return [
            'upload'            => false,
            'delete'            => false,
            'youtubeImport'     => false,
            'youtubeStoryboard' => false,
            'maxTrackSelection' => 10,
        ];
    }
    
    /**
     * Format media items for the block editor
     */
    private function getMediaItems($mediaItems)
    {
        $formattedItems = [];
        foreach ($mediaItems as $item) {
            $formattedItems[$item->ID] = [
                'value'    => $item->ID,
                'label'    => ($item->post_title ?: 'Media') . ' (#' . $item->ID . ')',
                'settings' => $item->settings,
            ];
        }
        return $formattedItems;
    }
    /**
     * Get centralized FluentPlayer configuration
     * Eliminates duplication across multiple methods
     */
    private function getFluentPlayerConfig()
    {
        return [
            'ajax_url'   => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('fluent_player_frontend'),
            'rest_url'   => rest_url('fluent-player/v2/'),
            'rest_nonce' => wp_create_nonce('wp_rest'),
            'context'    => 'fluent-community',
            'version'    => FLUENT_PLAYER_VERSION,
            'serverLang' => MediaService::detectUserLanguage(),
        ];
    }

    /**
     * Add Fluent Player assets to FluentCommunity's portal data system
     * This method integrates with FluentCommunity's native asset loading system
     * by adding our CSS/JS files and variables to the portal data array.
     * This is the cleanest approach as it uses FluentCommunity's own enqueueing system.
     *
     * @param array $data Portal data containing css_files, js_files, and js_vars
     *
     * @return array Modified portal data with Fluent Player assets
     */
    public function addFluentPlayerToPortalData($data)
    {
        // Only add if we're in FluentCommunity context
        if (!$this->isFluentCommunityContext($data)) {
            return $data;
        }
        
        // Add CSS file to FluentCommunity's CSS loading system
        $data['css_files']['fluent_player_css'] = [
            'url' => Vite::getEnqueuePath('scss/public/fluent-player.scss')
        ];
        
        // Add JS file to FluentCommunity's JS loading system
        $data['js_files']['fluent_player_js'] = [
            'url'  => Vite::getEnqueuePath('js/fluent-player.js'),
            'deps' => []
        ];

        // Keyed by the canonical timed-content handle (not a bespoke literal) so
        // the FC-portal loader registers the asset under the SAME handle string the
        // WP-enqueue path uses. FluentCommunity's js_files and wp_enqueue_script are
        // separate namespaces — this does not dedupe across them — but FC keys its
        // own array by this string, and keeping it consistent prevents the third
        // distinct handle that caused the duplicate.
        $data['js_files'][Helper::TIMED_CONTENT_SCRIPT_HANDLE] = [
            'url'  => Vite::getEnqueuePath('js/timed-content-frontend.js'),
            'deps' => []
        ];
        
        // Add Fluent Player configuration to JS variables
        if (!isset($data['js_vars'])) {
            $data['js_vars'] = [];
        }
        
        // Use centralized configuration
        $data['js_vars']['fluent_player'] = $this->getFluentPlayerConfig();

        // Allow pro and other addons to inject their own assets into FC's loading system.
        // Standard wp_enqueue_script() calls inside shortcodes are not output by the FC portal
        // because it uses portal_data_vars instead of wp_footer(). Any addon that has frontend
        // assets that need to run on FC lesson pages must register them via this filter.
        $data = apply_filters('fluent_player/fluent_community_portal_data', $data);

        return $data;
    }

    /**
     * Inject FluentPlayer CSS when content contains FluentPlayer blocks
     * This runs during the_content filter processing
     */
    public function injectFluentPlayerCSS($content)
    {
        // Only process in FluentCommunity context
        if (!$this->isFluentCommunityContext()) {
            return $content;
        }
        
        // Check if content contains FluentPlayer blocks or shortcodes
        if (strpos($content, 'fluent-player') === false &&
            strpos($content, 'media-player') === false) {
            return $content;
        }
        
        
        // Extract media IDs from content
        $mediaIds = $this->extractMediaIdsFromContent($content);
        
        
        if (empty($mediaIds)) {
            return $content;
        }
        
        // Generate CSS and inject it directly into the content
        $css = $this->generateCSSForMediaIds($mediaIds);
        
        if (!empty($css)) {
            // Inject CSS at the beginning of the content
            $content = "<style id=\"fluent-player-lesson-css\">\n{$css}\n</style>\n" . $content;
        }

        return $content;
    }
    
    /**
     * Extract media IDs from content
     */
    private function extractMediaIdsFromContent($content)
    {
        $mediaIds = [];
        
        // Extract from shortcodes: [fluentplayer id="123"] or [fluentmedia id="123"]
        if (preg_match_all('/\[fluent(?:player|media)[^\]]*id=["\']?(\d+)["\']?[^\]]*\]/', $content, $matches)) {
            $mediaIds = array_merge($mediaIds, $matches[1]);
        }
        
        // Extract from block comments: <!-- wp:fluent-player/media {"mediaId":123} -->
        if (preg_match_all('/wp:fluent-player\/media[^}]*"mediaId":(\d+)/', $content, $matches)) {
            $mediaIds = array_merge($mediaIds, $matches[1]);
        }
        
        // Extract from rendered HTML: id="fluent_player_123"
        if (preg_match_all('/id=["\']fluent_player_(\d+)["\']/', $content, $matches)) {
            $mediaIds = array_merge($mediaIds, $matches[1]);
        }
        
        // Extract from data attributes: data-var_name="fluent_player_123"
        if (preg_match_all('/data-var_name=["\']fluent_player_(\d+)["\']/', $content, $matches)) {
            $mediaIds = array_merge($mediaIds, $matches[1]);
        }
        
        return array_unique(array_map('intval', $mediaIds));
    }
    
    /**
     * Generate CSS for multiple media IDs
     */
    private function generateCSSForMediaIds($mediaIds)
    {
        static $generatedMediaIds = [];
        $css = '';

        // Filter out already-generated IDs
        $idsToLoad = [];
        foreach ($mediaIds as $mediaId) {
            if (!isset($generatedMediaIds[$mediaId])) {
                $idsToLoad[] = $mediaId;
            }
        }

        if (empty($idsToLoad)) {
            return $css;
        }

        // Batch-load all media in a single query instead of N individual finds (PERF-05)
        $medias = Post::where('post_type', Media::$postType)
            ->select(['post_title', 'ID', 'post_status', 'post_date'])
            ->whereIn('ID', $idsToLoad)
            ->get();

        Media::batchLoadMediaSettings($medias->all());

        $mediaMap = [];
        foreach ($medias as $m) {
            $m->settings = Media::getMediaSettings($m);
            $mediaMap[$m->ID] = $m;
        }

        foreach ($mediaIds as $mediaId) {
            if (isset($generatedMediaIds[$mediaId])) {
                continue;
            }
            $generatedMediaIds[$mediaId] = true;

            $media = $mediaMap[$mediaId] ?? null;
            if (!$media) {
                continue;
            }

            // Use MediaService to get properly prepared media data
            $mediaData = MediaService::prepareMediaForFrontend($media, 'fluent-community');
            $defaultSettings = $mediaData['default_settings'];

            // Generate the CSS for this media using Helper::enqueuePlayerStyles
            $mediaCss = Helper::enqueuePlayerStyles($mediaId, $mediaData['media']->settings, $defaultSettings, true);

            if (!empty($mediaCss)) {
                $css .= "/* FluentPlayer CSS for Media {$mediaId} */\n";
                $css .= $mediaCss . "\n";
            }
        }
        
        // Add global override for media-player aspect ratio only on FluentCommunity lesson pages
        // Exclude audio players which should maintain their auto height
        if (!empty($css) && $this->isFluentCommunityLessonPage()) {
            $css .= "\n/* Global media-player aspect ratio override for FluentCommunity lessons (video only) */\n";
            $css .= "media-player:not([data-view-type='audio']) {\n";
            $css .= "    aspect-ratio: inherit !important;\n";
            $css .= "}\n";
        }
        
        return $css;
    }
    


    /**
     * AJAX handler for fetching media data
     */
    public function handleGetMediaDataAjax()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verification and sanitization happen on next lines
        $nonceRaw = isset($_POST['nonce']) ? \wp_unslash($_POST['nonce']) : '';
        $nonce = \sanitize_text_field($nonceRaw);
        if (!wp_verify_nonce($nonce, 'fluent_player_frontend')) {
            wp_send_json(['error' => __('Invalid nonce', 'fluent-player')]);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above
        $mediaId = isset($_POST['media_id']) ? intval(\wp_unslash($_POST['media_id'])) : 0;
        if (!$mediaId) {
            wp_send_json(['error' => __('Invalid media ID', 'fluent-player')]);
            return;
        }

        $media = Media::findVisible($mediaId);

        if (!$media) {
            wp_send_json(['error' => __('Media not found', 'fluent-player')]);
            return;
        }

        // nopriv endpoint — don't return a password-protected media's src unless unlocked.
        if (post_password_required($mediaId) && !UnlockService::cookieUnlocked($mediaId)) {
            wp_send_json(['error' => __('This media is password protected.', 'fluent-player')]);
            return;
        }

        $mediaData = MediaService::prepareMediaForFrontend($media, 'fluent-community');

        // Apply signed CDN/streaming URLs only for users who can edit the media.
        // This endpoint is public (wp_ajax_nopriv_) and the nonce is visible in portal
        // page source — anyone can call it. CDN signed URLs and DRM tokens must not be
        // returned to unauthenticated or unprivileged users.
        if (current_user_can('edit_post', $mediaId)) {
            $mediaData['media']->settings = apply_filters('fluent_player/player_settings', $mediaData['media']->settings);
        } else {
            $mediaData = $this->filterSensitiveMediaData($mediaData);
        }

        wp_send_json($mediaData);
    }

    /**
     * Remove sensitive provider/internal config from media data for guest users.
     * Keeps UI-facing fields (headline, button text, colors) intact so the
     * frontend form still renders; actual provider integration runs server-side.
     */
    private function filterSensitiveMediaData($mediaData)
    {
        $media = $mediaData['media'] ?? null;
        $settings = $media ? $media->settings : null;
        if (!is_array($settings)) {
            return $mediaData;
        }

        if (!empty($settings['email_capture']['providers']) && is_array($settings['email_capture']['providers'])) {
            foreach ($settings['email_capture']['providers'] as &$provider) {
                unset($provider['config']);
            }
            unset($provider);
        }

        if (!empty($settings['layers']) && is_array($settings['layers'])) {
            foreach ($settings['layers'] as &$layer) {
                if (!empty($layer['email_capture']['providers']) && is_array($layer['email_capture']['providers'])) {
                    foreach ($layer['email_capture']['providers'] as &$provider) {
                        unset($provider['config']);
                    }
                    unset($provider);
                }
            }
            unset($layer);
        }

        $media->settings = $settings;

        return $mediaData;
    }
}
