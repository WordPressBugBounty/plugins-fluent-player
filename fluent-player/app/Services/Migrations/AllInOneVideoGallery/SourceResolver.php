<?php

namespace FluentPlayer\App\Services\Migrations\AllInOneVideoGallery;

if (!defined('ABSPATH')) {
    exit;
}

use FluentPlayer\Framework\Support\Arr;

/**
 * Turns one `aiovg_videos` row into a single provider/src decision.
 *
 * AIOVG can populate mp4 + webm + ogv + sources[] + hls + dash on the SAME video,
 * while FluentPlayer media carry exactly one `src` (SettingsSanitizer::getMediaRules).
 * Something therefore has to be chosen and the rest reported.
 *
 * The rule: attachment-backed sources win. An attachment id lets the imported media
 * use provider=wordpress, keep `attachment_id`, and read a real duration from
 * `_wp_attachment_metadata`, none of which a bare URL can offer.
 *
 * Everything else in the AIOVG integration — the Scanner's counts, and the field
 * mapping — is built on this one function, so the precedence lives in exactly
 * one place.
 */
class SourceResolver
{
    /**
     * AIOVG `type` values that map onto a FluentPlayer provider.
     * MediaService::ALLOWED_PROVIDERS has no dailymotion/rumble/facebook/embedcode.
     */
    const PROVIDER_TYPES = [
        'youtube' => 'youtube',
        'vimeo'   => 'vimeo',
    ];

    const UNSUPPORTED_TYPES = ['dailymotion', 'rumble', 'facebook', 'embedcode'];

    /**
     * Derived from AIOVG's own mime map (public/video.php:851+). Detection is by
     * extension because AIOVG has no audio type — an audio episode is simply an
     * .mp3 sitting in the `mp4` field.
     */
    const AUDIO_EXTENSIONS = ['mp3', 'm4a', 'wav', 'ogg', 'oga', 'aac', 'flac', 'opus', 'weba'];

    /**
     * Upper bound on post_content scanned for embedded media. Long enough for any
     * realistic post body, short enough that the regexes cannot become a slow path.
     */
    const MAX_CONTENT_BYTES = 65536;

    /** @var array<string,int> Per-request memo for attachment_url_to_postid(). */
    private static $attachmentIdCache = [];

    /** @var array<int,string[]> post id => embedded media URLs. */
    private static $contentUrlCache = [];

    /** Quality labels AIOVG users type by hand that carry no digits. */
    const NAMED_QUALITIES = ['8k' => 4320, '4k' => 2160, '2k' => 1440, 'hd' => 720, 'sd' => 480];

    /**
     * @param int $postId
     * @return array
     */
    public static function resolve($postId)
    {
        $post = get_post($postId);

        // AIOVG meta keys are UNPREFIXED (`type`, `mp4`, `duration`), so they collide
        // with other plugins' meta. Without this guard the resolver would happily
        // "migrate" an unrelated post that happens to carry a `mp4` key.
        if (!$post || $post->post_type !== 'aiovg_videos') {
            return self::rejected('not_an_aiovg_video');
        }

        $meta = self::readMeta($postId);
        $type = Arr::get($meta, 'type', 'default');
        $type = $type !== '' ? $type : 'default';

        // Bunny first: AIOVG stores Bunny videos as type=default WITH a populated
        // mp4 whose URL embeds the video id (includes/player/base.php:371). Running
        // the self-hosted chain first would migrate them as plain external MP4s and
        // throw the Bunny identity away.
        $bunnyVideoId = trim((string) Arr::get($meta, 'bunny_stream_video_id', ''));
        if ($bunnyVideoId !== '' && $bunnyVideoId !== '0') {
            $bunnySrc = trim((string) Arr::get($meta, 'mp4', ''));

            // AIOVG keeps the Bunny playback URL in mp4. Without it there is nothing
            // to play, and marking the row migratable would promise the preview an
            // import that migrateSingle then silently skips on empty src.
            if ($bunnySrc === '') {
                $rejected = self::rejected('no_source');
                $rejected['aiovg_type'] = 'default';

                return $rejected;
            }

            return self::accepted([
                'provider'       => 'bunny',
                'src'            => $bunnySrc,
                'bunny_video_id' => $bunnyVideoId,
            ], $meta, $postId);
        }

        if (in_array($type, self::UNSUPPORTED_TYPES, true)) {
            $rejected = self::rejected('unsupported_provider');
            $rejected['aiovg_type'] = $type;

            return $rejected;
        }

        if (isset(self::PROVIDER_TYPES[$type])) {
            $src = trim((string) Arr::get($meta, $type, ''));

            if ($src === '') {
                $rejected = self::rejected('no_source');
                $rejected['aiovg_type'] = $type;

                return $rejected;
            }

            return self::accepted([
                'provider' => self::PROVIDER_TYPES[$type],
                'src'      => $src,
            ], $meta, $postId);
        }

        return self::resolveSelfHosted($meta, $postId);
    }

    /**
     * Attachment-backed candidates first, then plain URLs, HLS last.
     *
     * @param array $meta
     * @param int   $postId
     * @return array
     */
    private static function resolveSelfHosted(array $meta, $postId)
    {
        $candidates = self::selfHostedCandidates($meta);

        // AIOVG's Source fields are the canonical home, but a video can also be
        // added straight into the post body with a core [video]/[audio] shortcode or
        // a Gutenberg media block. AIOVG's own player ignores those (it reads the
        // meta) — they render only because the single-video template outputs
        // the_content — but the media is real, so reporting "no source" would strand
        // a library the user can plainly see. Meta still wins when both exist.
        if (!$candidates) {
            $candidates = self::postContentCandidates($postId);
        }

        if (!$candidates) {
            $rejected = self::rejected('no_source');
            $rejected['aiovg_type'] = 'default';

            return $rejected;
        }

        // Stable sort: attachment-backed beats URL-only, then higher quality wins,
        // then declaration order. usort() is not stable before PHP 8, so the index
        // is the final tie-break.
        usort($candidates, function ($a, $b) {
            if ($a['hasAttachment'] !== $b['hasAttachment']) {
                return $a['hasAttachment'] ? -1 : 1;
            }
            if ($a['rank'] !== $b['rank']) {
                return $b['rank'] <=> $a['rank'];
            }

            return $a['index'] <=> $b['index'];
        });

        $chosen = array_shift($candidates);

        return self::accepted([
            'provider'      => $chosen['attachmentId'] ? 'wordpress' : 'external',
            'src'           => $chosen['src'],
            'attachment_id' => $chosen['attachmentId'],
            'dropped'       => count($candidates),
        ], $meta, $postId);
    }

    /**
     * Every playable source on the row, in declaration order.
     *
     * @param array $meta
     * @return array<int,array>
     */
    private static function selfHostedCandidates(array $meta)
    {
        $candidates = [];
        $index = 0;

        $add = function ($src, $attachmentId, $quality) use (&$candidates, &$index) {
            $src = trim((string) $src);
            if ($src === '') {
                return;
            }

            $candidates[] = [
                'src'           => $src,
                'attachmentId'  => (int) $attachmentId,
                'hasAttachment' => (int) $attachmentId > 0,
                'rank'          => self::qualityRank($quality),
                'index'         => $index++,
            ];
        };

        $add(Arr::get($meta, 'mp4', ''), Arr::get($meta, 'mp4_id', 0), Arr::get($meta, 'quality_level', ''));

        $sources = Arr::get($meta, 'sources', []);
        if (is_array($sources)) {
            foreach ($sources as $source) {
                if (is_array($source)) {
                    $add(
                        Arr::get($source, 'src', ''),
                        Arr::get($source, 'src_id', 0),
                        Arr::get($source, 'quality', '')
                    );
                }
            }
        }

        // AIOVG gates these behind their own checkbox; an unchecked box leaves a
        // stale URL behind that the player ignores, so honour the flag.
        if (Arr::get($meta, 'has_webm')) {
            $add(Arr::get($meta, 'webm', ''), Arr::get($meta, 'webm_id', 0), '');
        }
        if (Arr::get($meta, 'has_ogv')) {
            $add(Arr::get($meta, 'ogv', ''), Arr::get($meta, 'ogv_id', 0), '');
        }

        // HLS/DASH rank lowest: adaptive manifests carry no attachment id, so
        // choosing one would cost the attachment_id and duration.
        $add(Arr::get($meta, 'hls', ''), 0, '');
        $add(Arr::get($meta, 'dash', ''), 0, '');

        return $candidates;
    }

    /**
     * Sources embedded in post_content: core [video]/[audio] shortcodes and the
     * <video>/<audio> tags Gutenberg's media blocks emit.
     *
     * @param int $postId
     * @return array<int,array>
     */
    /**
     * Drop the per-request memos. Tests mutate post content between scans.
     *
     * @return void
     */
    public static function flushCache()
    {
        self::$attachmentIdCache = [];
        self::$contentUrlCache = [];
    }

    /**
     * Media URLs embedded in a video's post_content, decoded and de-duplicated.
     *
     * Memoised per request: priming and resolving both need this list, and the
     * regex work over MAX_CONTENT_BYTES of content is not free across 5,000 rows.
     *
     * @param int $postId
     * @return string[]
     */
    private static function contentUrls($postId)
    {
        $postId = (int) $postId;

        if (isset(self::$contentUrlCache[$postId])) {
            return self::$contentUrlCache[$postId];
        }

        $post = get_post($postId);
        $content = $post ? (string) $post->post_content : '';

        if ($content === '') {
            return self::$contentUrlCache[$postId] = [];
        }

        // Bound the regex work. On very long content PCRE can hit its backtrack
        // limit, and preg_match_all then returns false — which would look
        // identical to "no sources found" and silently skip a real video.
        if (strlen($content) > self::MAX_CONTENT_BYTES) {
            $content = substr($content, 0, self::MAX_CONTENT_BYTES);
        }

        $urls = [];

        // [video mp4="…"] / [audio mp3="…"] — every source attribute WP core
        // supports, plus the generic `src`.
        if (preg_match_all('/\[(?:video|audio)\b([^\]]*)\]/i', $content, $tags)) {
            foreach ($tags[1] as $attrString) {
                if (preg_match_all('/\b(?:src|mp4|m4v|webm|ogv|flv|mp3|ogg|wma|m4a|wav)\s*=\s*["\']([^"\']+)["\']/i', $attrString, $attrs)) {
                    foreach ($attrs[1] as $url) {
                        $urls[] = $url;
                    }
                }
            }
        }

        // <video src="…"> / <audio src="…"> and their <source src="…"> children.
        if (preg_match_all('/<(?:video|audio|source)\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $content, $tagSrcs)) {
            foreach ($tagSrcs[1] as $url) {
                $urls[] = $url;
            }
        }

        $clean = [];

        foreach (array_unique($urls) as $url) {
            $url = trim(html_entity_decode($url, ENT_QUOTES));

            if ($url !== '') {
                $clean[] = $url;
            }
        }

        return self::$contentUrlCache[$postId] = $clean;
    }

    /**
     * Resolve every embedded URL across a batch of videos in one query.
     *
     * attachment_url_to_postid() is an uncached query per URL. Called from inside
     * the per-video loop that detect()/scan() run over the whole scan window, that
     * is thousands of queries for one request.
     *
     * @param int[] $postIds
     * @return void
     */
    public static function primeContentAttachments(array $postIds)
    {
        $urls = [];

        foreach ($postIds as $postId) {
            foreach (self::contentUrls($postId) as $url) {
                if (!array_key_exists($url, self::$attachmentIdCache)) {
                    $urls[$url] = true;
                }
            }
        }

        if (!$urls) {
            return;
        }

        global $wpdb;

        $uploads = wp_get_upload_dir();
        $baseUrl = isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';

        // Mirror attachment_url_to_postid(): a URL under the uploads base is looked
        // up by its uploads-relative path, anything else by the URL as stored. Both
        // shapes go into ONE query rather than one query per URL.
        $byValue = [];

        foreach (array_keys($urls) as $url) {
            $path = $baseUrl === '' ? '' : self::uploadRelativePath($url, $baseUrl);
            $value = $path !== '' ? $path : $url;
            $byValue[$value][] = $url;
        }

        $found = [];

        foreach (array_chunk(array_keys($byValue), 200) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk attachment lookup; placeholders are generated %s, every value is bound.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_value FROM `{$wpdb->postmeta}`
                 WHERE meta_key = '_wp_attached_file' AND meta_value IN ({$placeholders})",
                $chunk
            ));

            foreach ((array) $rows as $row) {
                if (isset($byValue[$row->meta_value])) {
                    foreach ($byValue[$row->meta_value] as $url) {
                        $found[$url] = (int) $row->post_id;
                    }
                }
            }
        }

        foreach ($byValue as $list) {
            foreach ($list as $url) {
                $id = isset($found[$url]) ? $found[$url] : 0;

                // Core applies this to every lookup; offload/CDN plugins resolve
                // their own URLs through it, so skipping it would silently stop
                // recognising their attachments.
                self::$attachmentIdCache[$url] = (int) apply_filters('attachment_url_to_postid', $id, $url);
            }
        }
    }

    /**
     * @param string $url
     * @param string $baseUrl
     * @return string Uploads-relative path, or '' when the URL is not under uploads.
     */
    private static function uploadRelativePath($url, $baseUrl)
    {
        $url = preg_replace('#^https?://#i', '', $url);
        $base = rtrim(preg_replace('#^https?://#i', '', $baseUrl), '/') . '/';

        if (strpos($url, $base) !== 0) {
            return '';
        }

        $path = substr($url, strlen($base));
        $path = explode('?', $path, 2)[0];

        return ltrim($path, '/');
    }

    private static function postContentCandidates($postId)
    {
        $urls = self::contentUrls($postId);

        if (!$urls) {
            return [];
        }

        $candidates = [];
        $index = 0;

        foreach ($urls as $url) {

            // Recover the attachment when the file lives in this media library, so
            // the import keeps provider=wordpress and a real duration. Memoised:
            // this is an uncached DB query, and detect()+scan() resolve every row.
            if (!array_key_exists($url, self::$attachmentIdCache)) {
                self::$attachmentIdCache[$url] = (int) attachment_url_to_postid($url);
            }
            $attachmentId = self::$attachmentIdCache[$url];

            $candidates[] = [
                'src'           => $url,
                'attachmentId'  => $attachmentId,
                'hasAttachment' => $attachmentId > 0,
                'rank'          => 0,
                'index'         => $index++,
            ];
        }

        return $candidates;
    }

    /**
     * AIOVG quality labels are free text typed per source, so this is a heuristic,
     * not a lookup: "1080p" outranks "720p" numerically even though it sorts lower
     * alphabetically. Unrecognised labels rank 0 rather than throwing.
     *
     * @param string $quality
     * @return int
     */
    public static function qualityRank($quality)
    {
        $quality = strtolower(trim((string) $quality));

        if ($quality === '') {
            return 0;
        }

        if (isset(self::NAMED_QUALITIES[$quality])) {
            return self::NAMED_QUALITIES[$quality];
        }

        if (preg_match('/^(\d+)\s*p?$/', $quality, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * @param string $src
     * @return bool
     */
    public static function isAudioSrc($src)
    {
        $path = (string) parse_url((string) $src, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::AUDIO_EXTENSIONS, true);
    }

    /**
     * AIOVG stores duration as human time ("1:23", "1:02:03"), not seconds
     * (admin/videos.php via aiovg_convert_seconds_to_human_time).
     *
     * @param string $value
     * @return float
     */
    public static function parseDuration($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 0.0;
        }

        $parts = explode(':', $value);

        foreach ($parts as $part) {
            if (!is_numeric(trim($part))) {
                return 0.0;
            }
        }

        $seconds = 0.0;
        foreach ($parts as $part) {
            $seconds = ($seconds * 60) + (float) trim($part);
        }

        return $seconds;
    }

    /**
     * @param string $reason
     * @return array
     */
    private static function rejected($reason)
    {
        return array_merge(self::blank(), [
            'migratable' => false,
            'reason'     => $reason,
        ]);
    }

    /**
     * Fill in the parts every accepted result shares: media type, poster, duration.
     *
     * @param array $result
     * @param array $meta
     * @param int   $postId
     * @return array
     */
    private static function accepted(array $result, array $meta, $postId)
    {
        $result = array_merge(self::blank(), $result, [
            'migratable' => true,
            'reason'     => '',
            'aiovg_type' => (string) (Arr::get($meta, 'type', 'default') ?: 'default'),
            'post_id'    => (int) $postId,
        ]);

        $result['mediaType'] = self::isAudioSrc($result['src']) ? 'audio' : 'video';
        $result['poster'] = trim((string) Arr::get($meta, 'image', ''));

        $duration = self::parseDuration(Arr::get($meta, 'duration', ''));

        // A real attachment beats AIOVG's hand-entered value, which is often stale
        // or blank.
        if ($result['attachment_id']) {
            $metadata = wp_get_attachment_metadata($result['attachment_id']);
            if (!empty($metadata['length'])) {
                $duration = (float) $metadata['length'];
            }
        }

        $result['duration'] = $duration;

        return $result;
    }

    /**
     * @return array
     */
    private static function blank()
    {
        return [
            'migratable'     => false,
            'reason'         => '',
            'provider'       => '',
            'src'            => '',
            'attachment_id'  => 0,
            'mediaType'      => 'video',
            'poster'         => '',
            'duration'       => 0.0,
            'dropped'        => 0,
            'bunny_video_id' => '',
            'aiovg_type'     => '',
            'post_id'        => 0,
        ];
    }

    /**
     * One meta read per post — get_post_meta() with no key returns every value as
     * a single-element array, which is also the shape AIOVG's own metabox reads.
     *
     * @param int $postId
     * @return array
     */
    private static function readMeta($postId)
    {
        $raw = get_post_meta($postId);
        $meta = [];

        foreach ((array) $raw as $key => $values) {
            $value = isset($values[0]) ? $values[0] : '';
            $meta[$key] = maybe_unserialize($value);
        }

        // `sources` is legitimately an array; every other key is read as a scalar
        // and would cast to the literal string "Array" (plus a PHP warning) if a
        // malformed row stored one.
        foreach ($meta as $key => $value) {
            if ($key !== 'sources' && !is_scalar($value) && $value !== null) {
                $meta[$key] = '';
            }
        }

        return $meta;
    }
}
