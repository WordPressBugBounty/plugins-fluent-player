<?php

namespace FluentPlayer\App\Http\Controllers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Models\Media;
use FluentPlayer\Framework\Http\Request\Request;
use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Sanitizer;
use FluentPlayer\Framework\Validator\ValidationException;
use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Services\SettingsSanitizer;
use FluentPlayer\App\Services\SettingsService;

class MediaController extends Controller
{
    protected $pendingStoryboardCleanup = null;

    protected static function getStoryboardServiceClass()
    {
        // Free orchestrates media lifecycle, but Pro owns storyboard business logic when available.
        $serviceClass = '\FluentPlayerPro\App\Services\SubtitleService';

        return class_exists($serviceClass) ? $serviceClass : null;
    }

    public static function hasProStoryboardOwnership()
    {
        $serviceClass = self::getStoryboardServiceClass();

        return $serviceClass
            && method_exists($serviceClass, 'cleanupGeneratedStoryboard')
            && method_exists($serviceClass, 'hasMediaSourceChanged')
            && method_exists($serviceClass, 'preserveManagedMediaSettings');
    }

    public function get(Request $request)
    {
        return Media::paginate($request);
    }

    public function search(Request $request)
    {
        return Media::search($request->get());
    }

    public function find($id)
    {
        $media = Media::find($id);

        if (!$media) {
            return $this->sendError(['message' => 'Media not found'], 404);
        }

        $mediaId = (int) $id;
        $media->view_url = function_exists('get_permalink') ? get_permalink($mediaId) : '';
        $media->post_content = get_post_field('post_content', $mediaId);
        if (taxonomy_exists('flp_media_tag')) {
            $tags = wp_get_object_terms($mediaId, 'flp_media_tag', ['fields' => 'names']);
            $media->tags = is_wp_error($tags) ? [] : $tags;
        } else {
            $media->tags = [];
        }

        // Apply signed URLs for block editor preview (CDN token auth, DRM)
        $media->settings = apply_filters('fluent_player/player_settings', $media->settings);

        return $this->sendSuccess(['media' => $media]);
    }

    public function store(Request $request)
    {
        try {
            $media = $this->prepareMedia($request->all())
                ->save();
            $this->runPendingStoryboardCleanup();
            do_action('fluent_player/after_save_media', $media->ID, $request->all());
            $media = $this->prepareResponseMedia($media);
            return $this->sendSuccess([ 'success' => true, 'message' => __('Media Created', 'fluent-player'), 'media' => $media ]);
        } catch (ValidationException $e) {
            $this->pendingStoryboardCleanup = null;
            return $this->sendError($e->errors());
        } catch (\Exception $e) {

            $this->pendingStoryboardCleanup = null;
            return $this->sendError(['message' => __('Failed to save media', 'fluent-player')]);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $payload = $request->all();
            if (!is_array($payload)) {
                $payload = [];
            }

            // The route media id is the source of truth for updates. Request payloads
            // may already contain merged route params, and custom clients could send a
            // conflicting body id. Normalize to the route id before prepareMedia().
            $payload['id'] = absint($id);

            $media = $this->prepareMedia($payload)
                ->save();
            $this->runPendingStoryboardCleanup();
            do_action('fluent_player/after_save_media', $media->ID, $payload);
            $media = $this->prepareResponseMedia($media);
            return $this->sendSuccess([ 'success' => true, 'message' => __('Media Updated', 'fluent-player'), 'media' => $media]);
        } catch (ValidationException $e) {
            $this->pendingStoryboardCleanup = null;
            return $this->sendError($e->errors());
        } catch (\Exception $e) {

            $this->pendingStoryboardCleanup = null;
            return $this->sendError(['message' => __('Failed to update media', 'fluent-player')]);
        }
    }

    /**
     * @throws ValidationException
     * @return Media
     */
    protected function prepareMedia($data)
    {
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Data must be an array');
        }

        $settings = Arr::get($data, 'settings');
        // Decode JSON settings if needed
        if ($settings && !is_array($settings)) {
            $data['settings'] = \json_decode($settings, true);
        }

        $data = $this->sanitizeMediaData($data);

        $mediaId = absint(Arr::get($data, 'id'));
        if ($mediaId) {
            $existingMedia = Media::find($mediaId);
            $existingSettings = is_array($existingMedia ? $existingMedia->settings : null) ? $existingMedia->settings : [];
            $incomingSettings = Arr::get($data, 'settings');

            if (!is_array($incomingSettings)) {
                $incomingSettings = $existingSettings;
            }

            if (self::hasMediaSourceChanged($existingSettings, $incomingSettings)) {
                $this->pendingStoryboardCleanup = [
                    'mediaId' => $mediaId,
                    'settings' => $existingSettings,
                ];
                unset(
                    $incomingSettings['thumbnails'],
                    $incomingSettings['storyboard_attachment_id'],
                    $incomingSettings['storyboard_generation_status'],
                    $incomingSettings['storyboard_generation_message']
                );
            } else {
                $incomingSettings = self::preserveManagedMediaSettings($existingSettings, $incomingSettings);
            }

            $data['settings'] = $incomingSettings;
        }

        $settingsRule = (empty(Arr::get($data, 'post_content')) && !$mediaId) ? 'required|array' : 'array';
        $validationRules = [
            'settings'                              => $settingsRule,
            'settings.viewType'                     => 'string|in:video,audio,youtube,vimeo',
            'settings.preset_slug'                  => 'required',
            'settings.src'                          => 'required|url',
            'settings.provider'                     => 'required|string',
            'settings.attachment_id'                => 'required_if:settings.provider,wordpress',
            'settings.post_status'                  => 'string|in:publish,private,draft,auto-draft',
            'settings.language'                     => 'nullable|string',
            'settings.language_mappings'            => 'array',
            'settings.language_mappings.*.language' => 'string',
            'settings.language_mappings.*.media_id' => 'integer',
            'settings.language_mappings.*.id'       => 'integer',
            'settings.show_language_switcher'       => 'nullable',
            // Added validation for critical fields
            'settings.loadStrategy'                 => 'nullable|string|in:eager,visible,idle,play,poster',
            'settings.preload'                      => 'nullable|string|in:none,metadata,auto',
            'settings.mediaType'                    => 'nullable|string|in:video,audio',
            'settings.streamType'                   => 'nullable|string|in:on-demand,live,live:dvr',
            'settings.aspectRatio'                  => 'nullable|string',
        ];
        $validationMessages = [
            'settings.attachment_id.required_if' => 'The WordPress attachment ID is required when the provider is WordPress.',
            'settings.post_status.in'            => 'The post status must be one of "publish", "private", "draft", or "auto-draft".',
        ];

        $data = $this->validate($data, $validationRules, $validationMessages);

        $defaultPreload = SettingsService::getDefaultPreload(Arr::get($data, 'settings', []), SettingsService::getSettings());

        $data['settings'] = array_merge([
            'chapters' => [],
            'overlays' => [],
            'language_mappings' => [],
            'viewType' => 'video',
            // Added defaults for critical fields
            'mediaType' => 'video',
            'streamType' => 'on-demand',
            'loadStrategy' => 'visible',
            'preload' => $defaultPreload,
        ], Arr::get($data, 'settings', []));

        $media = new Media();
        if ($id = Arr::get($data, 'id')) {
            $media->id = $id;
        }
        $media->title = Arr::get($data, 'settings.title');

        // On update, if post_status isn't provided, preserve the existing status
        // instead of defaulting to 'draft'. This prevents overwriting visibility
        // changes made via the WordPress editor (e.g. public/private toggle).
        $postStatus = Arr::get($data, 'settings.post_status');
        if (!$postStatus && $media->id) {
            $postStatus = get_post_status($media->id) ?: 'draft';
        }
        $media->post_status = $postStatus ?: 'draft';

        $media->settings = Arr::get($data, 'settings', []);
        if (array_key_exists('post_content', $data)) {
            $media->post_content = Arr::get($data, 'post_content');
        }
        return $media;
    }

     public static function sanitizeMediaData($data)
    {
        $data = Sanitizer::sanitize($data, [
            'id' => 'intval',
        ]);

        if (isset($data['settings']) && is_array($data['settings'])) {
            $data['settings'] = SettingsSanitizer::sanitizeMediaSettings($data['settings']);
        }

        if (array_key_exists('post_content', $data)) {
            $data['post_content'] = self::normalizeMediaPostContent($data['post_content']);
        }

        return $data;
    }

    protected static function normalizeMediaPostContent($postContent)
    {
        if (!is_string($postContent)) {
            return '';
        }

        $postContent = wp_unslash($postContent);
        $blocks = parse_blocks($postContent);
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

    public function getTags(Request $request)
    {
        return $this->dispatchMediaTagRequest('get', 'getTags', $request);
    }

    public function createTag(Request $request)
    {
        return $this->dispatchMediaTagRequest('create', 'createTag', $request);
    }

    protected function prepareResponseMedia($media)
    {
        if (taxonomy_exists('flp_media_tag')) {
            $tags = wp_get_object_terms($media->ID, 'flp_media_tag', ['fields' => 'names']);
            $media->tags = is_wp_error($tags) ? [] : $tags;
        } else {
            $media->tags = [];
        }

        return $media;
    }

    public function renameTag(Request $request)
    {
        return $this->dispatchMediaTagRequest('rename', 'renameTag', $request);
    }

    public function deleteTag(Request $request)
    {
        return $this->dispatchMediaTagRequest('delete', 'deleteTag', $request);
    }

    protected function dispatchMediaTagRequest($action, $legacyMethod, Request $request)
    {
        /**
         * Let Pro own media tag behavior while Free keeps the REST routes and fallback UX.
         *
         * New Pro builds consume this filter. If no Pro filter is registered,
         * fall back to the legacy direct controller call so older Pro builds
         * keep working with newer Free.
         */
        if (has_filter('fluent_player/media_tags_request')) {
            return apply_filters('fluent_player/media_tags_request', $this->sendTagsProFeatureError(), $action, $request);
        }

        return $this->forwardToLegacyProTagController($legacyMethod, $request);
    }

    protected function forwardToLegacyProTagController($method, Request $request)
    {
        $controller = $this->getLegacyProTagController();

        if (!$controller || !method_exists($controller, $method)) {
            return $this->sendTagsProFeatureError();
        }

        return $controller->{$method}($request);
    }

    protected function getLegacyProTagController()
    {
        $controllerClass = 'FluentPlayerPro\App\Http\Controllers\TagController';

        if (!Helper::hasPro() || !class_exists($controllerClass)) {
            return null;
        }

        return new $controllerClass();
    }

    protected function sendTagsProFeatureError()
    {
        return $this->sendError(['message' => __('Tags are a Pro feature', 'fluent-player')], 403);
    }

    public function delete($id)
    {
        $media = Media::deleteById($id);

        if (!$media) {
            return $this->sendError(['message' => 'Media not found'], 404);
        }

        return $this->sendSuccess(['message' => __('Media moved to trash', 'fluent-player')]);
    }

    public function restore($id)
    {
        $restored = Media::restoreById($id);

        if (!$restored) {
            return $this->sendError(['message' => 'Media not found'], 404);
        }

        return $this->sendSuccess(['message' => __('Media restored', 'fluent-player')]);
    }

    public function forceDelete($id)
    {
        $deleted = Media::forceDeleteById($id);

        if (!$deleted) {
            return $this->sendError(['message' => 'Media not found'], 404);
        }

        return $this->sendSuccess(['message' => __('Media permanently deleted', 'fluent-player')]);
    }

    /**
     * Apply one bulk action to many media items. Free handles the status
     * lifecycle; tag/playlist actions are dispatched to Pro via a filter.
     *
     * @return \WP_REST_Response
     */
    public function handleBulkActions(Request $request)
    {
        $result = (new \FluentPlayer\App\Services\MediaService())->manageBulkActions($request);

        if (is_wp_error($result)) {
            $status = (int) ($result->get_error_data()['status'] ?? 422);
            return $this->sendError(['message' => $result->get_error_message()], $status);
        }

        return $this->sendSuccess($result);
    }

    /**
     * Get video metadata using WordPress oEmbed
     *
     * @param Request $request
     * @return \WP_REST_Response
     */
    public function getMetadata(Request $request)
    {
        $url = $request->get('url');
        if (empty($url)) {
            return $this->sendError(['message' => __('URL is required', 'fluent-player')], 400);
        }
        $url = esc_url_raw($url);
        if (!$url) {
            return $this->sendError(['message' => __('Invalid URL', 'fluent-player')], 400);
        }
        // Normalize YouTube URL variants to standard watch format
        $url = preg_replace('/(?:m|music)\.youtube\.com/', 'www.youtube.com', $url);
        $url = preg_replace('/youtube\.com\/(?:live|shorts)\/([^?&#]+)/', 'youtube.com/watch?v=$1', $url);
        $oembed = new \WP_oEmbed();
        $data = $oembed->get_data($url, ['discover' => false]);
        if ($data && !is_wp_error($data)) {
            $response = (array) $data;
            $response['url'] = $url;
            // Upgrade YouTube thumbnail to maxresdefault if available
            if (!empty($response['provider_name']) && $response['provider_name'] === 'YouTube') {
                $response['thumbnail_url'] = self::getYouTubeBestThumbnail($url, $response['thumbnail_url'] ?? '');
            }
            return $this->sendSuccess([
                'success' => true,
                'metaData' => $response
            ]);
        }

        // Fallback for YouTube: construct metadata from video ID
        if (preg_match('/(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|live\/|shorts\/|watch\?v=))([^&\n?#]+)/', $url, $ytMatch)) {
            return $this->sendSuccess([
                'success'  => true,
                'metaData' => [
                    'url'           => $url,
                    'title'         => '',
                    'thumbnail_url' => self::getYouTubeBestThumbnail($url),
                    'provider_name' => 'YouTube',
                    'type'          => 'video',
                ]
            ]);
        }

        return $this->sendError(['message' => __('Could not fetch metadata for this URL', 'fluent-player')], 404);
    }

    /**
     * Try maxresdefault.jpg (1280x720) first, fall back to sddefault (640x480),
     * then hqdefault (480x360) for YouTube thumbnails.
     */
    public static function getYouTubeBestThumbnail($url, $fallback = '')
    {
        if (!preg_match('/(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|live\/|shorts\/|watch\?v=))([^&\n?#]+)/', $url, $match)) {
            return $fallback;
        }
        $videoId = $match[1];
        $base = 'https://img.youtube.com/vi/' . $videoId . '/';

        // Try highest quality first — maxresdefault only exists for HD videos
        $maxRes = $base . 'maxresdefault.jpg';
        $headers = wp_remote_head($maxRes, ['timeout' => 3, 'redirection' => 0]);
        if (!is_wp_error($headers) && wp_remote_retrieve_response_code($headers) === 200) {
            return $maxRes;
        }

        // sddefault (640x480) is a good middle ground
        $sdDefault = $base . 'sddefault.jpg';
        $headers = wp_remote_head($sdDefault, ['timeout' => 3, 'redirection' => 0]);
        if (!is_wp_error($headers) && wp_remote_retrieve_response_code($headers) === 200) {
            return $sdDefault;
        }

        return $fallback ?: $base . 'hqdefault.jpg';
    }

    public static function preserveManagedMediaSettings($existingSettings, $incomingSettings)
    {
        $serviceClass = self::getStoryboardServiceClass();
        if ($serviceClass && method_exists($serviceClass, 'preserveManagedMediaSettings')) {
            return $serviceClass::preserveManagedMediaSettings($existingSettings, $incomingSettings);
        }

        $existingSettings = is_array($existingSettings) ? $existingSettings : [];
        $incomingSettings = is_array($incomingSettings) ? $incomingSettings : [];

        if (empty($incomingSettings['subtitles']) && !empty($existingSettings['subtitles'])) {
            $incomingSettings['subtitles'] = $existingSettings['subtitles'];
        }

        foreach ([
            'thumbnails',
            'storyboard_attachment_id',
            'storyboard_generation_status',
            'storyboard_generation_message',
        ] as $key) {
            if (empty($incomingSettings[$key]) && !empty($existingSettings[$key])) {
                $incomingSettings[$key] = $existingSettings[$key];
            }
        }

        return $incomingSettings;
    }

    protected function runPendingStoryboardCleanup()
    {
        $pendingCleanup = $this->pendingStoryboardCleanup;
        $this->pendingStoryboardCleanup = null;

        if (empty($pendingCleanup['mediaId']) || !isset($pendingCleanup['settings'])) {
            return;
        }

        self::cleanupGeneratedStoryboard($pendingCleanup['settings'], $pendingCleanup['mediaId']);
    }

    public static function hasMediaSourceChanged($existingSettings, $incomingSettings)
    {
        $serviceClass = self::getStoryboardServiceClass();
        if ($serviceClass && method_exists($serviceClass, 'hasMediaSourceChanged')) {
            return $serviceClass::hasMediaSourceChanged($existingSettings, $incomingSettings);
        }

        $existingSignature = self::buildMediaSourceSignature($existingSettings);
        $incomingSignature = self::buildMediaSourceSignature($incomingSettings);

        if (!$existingSignature || !$incomingSignature) {
            return false;
        }

        return $existingSignature !== $incomingSignature;
    }

    public static function cleanupGeneratedStoryboard(&$settings, $mediaId, $deleteAttachment = true)
    {
        $serviceClass = self::getStoryboardServiceClass();
        if ($serviceClass && method_exists($serviceClass, 'cleanupGeneratedStoryboard')) {
            return $serviceClass::cleanupGeneratedStoryboard($settings, $mediaId, $deleteAttachment);
        }

        $settings = is_array($settings) ? $settings : [];
        $mediaId = absint($mediaId);
        $attachmentId = absint(Arr::get($settings, 'storyboard_attachment_id', 0));
        $warning = '';
        $deleted = false;
        $cleared = false;
        $assetDirName = $attachmentId ? sanitize_file_name((string) get_post_meta($attachmentId, '_fluent_player_generated_storyboard_asset_dir', true)) : '';
        // Only managed storyboard attachments are allowed to mutate shared thumbnail fields.
        $isManagedStoryboard = $attachmentId && self::canDeleteManagedStoryboardAttachment($attachmentId, $mediaId);

        if ($deleteAttachment && $isManagedStoryboard) {
            if ($assetDirName && !self::cleanupStoryboardAssetDirectory($assetDirName)) {
                $warning = __('Hover preview cleanup could not delete the storyboard images.', 'fluent-player');
            }
            $deleted = (bool) wp_delete_attachment($attachmentId, true);
            if (!$deleted) {
                $warning = trim($warning . ' ' . __('Hover preview cleanup could not delete the previous storyboard attachment.', 'fluent-player'));
            }
        }

        if ($isManagedStoryboard) {
            unset($settings['thumbnails'], $settings['storyboard_attachment_id']);
            $cleared = true;
        }

        return [
            'deleted' => $deleted,
            'warning' => $warning,
            'cleared' => $cleared,
        ];
    }

    public static function canDeleteManagedStoryboardAttachment($attachmentId, $mediaId)
    {
        $attachmentId = absint($attachmentId);
        $mediaId = absint($mediaId);

        if (!$attachmentId || !$mediaId || !get_post($attachmentId)) {
            return false;
        }

        $managedMediaId = absint(get_post_meta($attachmentId, '_fluent_player_generated_storyboard_media_id', true));

        return $managedMediaId === $mediaId;
    }

    protected static function cleanupStoryboardAssetDirectory($assetDirName)
    {
        $assetDirName = sanitize_file_name((string) $assetDirName);
        if (!$assetDirName) {
            return true;
        }

        $uploadDir = wp_upload_dir();
        $baseStoryboardDir = trailingslashit($uploadDir['basedir']) . 'fluent-player/storyboards/';
        $assetDirPath = trailingslashit($baseStoryboardDir) . $assetDirName;

        if (!file_exists($assetDirPath) || !is_dir($assetDirPath)) {
            return true;
        }

        if (strpos(wp_normalize_path($assetDirPath), wp_normalize_path($baseStoryboardDir)) !== 0) {
            return false;
        }

        $files = array_diff(scandir($assetDirPath) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $filePath = trailingslashit($assetDirPath) . $file;
            if (is_dir($filePath)) {
                return false;
            }

            wp_delete_file($filePath);
            if (file_exists($filePath)) {
                return false;
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Scoped to our own uploads/fluent-player/storyboards subtree.
        return @rmdir($assetDirPath);
    }

    protected static function buildMediaSourceSignature($settings)
    {
        $settings = is_array($settings) ? $settings : [];
        $provider = sanitize_text_field((string) Arr::get($settings, 'provider', ''));
        $src = trim((string) Arr::get($settings, 'src', ''));

        if (!$provider || !$src) {
            return '';
        }

        if ($provider === 'youtube') {
            $videoId = self::extractYouTubeVideoId($src);
            return $videoId ? 'youtube:' . $videoId : 'youtube:' . $src;
        }

        return $provider . ':' . $src;
    }

    protected static function extractYouTubeVideoId($url)
    {
        $url = is_string($url) ? trim($url) : '';
        if (!$url) {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url)) {
            return $url;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $host = strtolower((string) Arr::get($parts, 'host', ''));
        $path = trim((string) Arr::get($parts, 'path', ''), '/');
        parse_str((string) Arr::get($parts, 'query', ''), $query);

        if (!empty($query['v']) && preg_match('/^[A-Za-z0-9_-]{11}$/', (string) $query['v'])) {
            return (string) $query['v'];
        }

        if ($host === 'youtu.be' && preg_match('/^[A-Za-z0-9_-]{11}$/', $path)) {
            return $path;
        }

        foreach (['embed/', 'v/', 'live/', 'shorts/'] as $prefix) {
            if (strpos($path, $prefix) === 0) {
                $candidate = substr($path, strlen($prefix));
                if (preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate)) {
                    return $candidate;
                }
            }
        }

        return '';
    }
}
