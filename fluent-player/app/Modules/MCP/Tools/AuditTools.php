<?php

namespace FluentPlayer\App\Modules\MCP\Tools;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\App\Models\Media;
use FluentPlayer\App\Modules\MCP\Support\MCPHelper;
use FluentPlayer\App\Services\MediaService;

class AuditTools
{
    // These platforms can serve their own captions, so missing Fluent Player tracks is only informational.
    const PLATFORM_PROVIDERS = ['youtube', 'vimeo'];

    public static function definitions()
    {
        return [
            'fluent-player/audit-media-accessibility' => [
                'label'            => __('Audit Media Accessibility', 'fluent-player'),
                'description'      => __('Check media for accessibility problems: missing captions, autoplay with sound, missing title, missing poster. Pass media_ids, or omit them to audit a page of published media.', 'fluent-player'),
                'group'            => __('Audit', 'fluent-player'),
                'capability'       => Helper::authoringCapability(),
                'annotations'      => ['readonly' => true],
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => array_merge([
                        'media_ids' => [
                            'type'     => 'array',
                            'items'    => ['type' => 'integer', 'minimum' => 1],
                            'maxItems' => MCPHelper::MAX_PER_PAGE,
                        ],
                    ], MCPHelper::pageSchema()),
                ],
                'execute_callback' => [self::class, 'auditMediaAccessibility'],
            ],
        ];
    }

    public static function auditMediaAccessibility($params)
    {
        $paging = MCPHelper::pagination($params, 50);
        $meta = [];

        if (!empty($params['media_ids'])) {
            $medias = [];
            foreach (array_unique(array_map('absint', $params['media_ids'])) as $id) {
                $media = Media::find($id);
                if ($media && current_user_can('edit_post', $id)) {
                    $medias[] = $media;
                }
            }
        } else {
            $paginator = (new MediaService())->paginate([
                'page'     => $paging['page'],
                'per_page' => $paging['per_page'],
                'status'   => 'publish',
            ]);
            $medias = $paginator->items();
            $meta = MCPHelper::pagingMeta($paging['page'], $paging['per_page'], $paginator->total());
        }

        $rows = [];
        $withIssues = 0;
        foreach ($medias as $media) {
            $issues = self::findIssues(is_array($media->settings) ? $media->settings : []);
            $withIssues += $issues ? 1 : 0;
            $rows[] = [
                'id'     => (int) $media->ID,
                'title'  => MCPHelper::untrusted((string) $media->post_title),
                'issues' => $issues,
            ];
        }

        return MCPHelper::envelope(
            /* translators: 1: media with issues, 2: media checked */
            sprintf(__('%1$d of %2$d media have accessibility issues.', 'fluent-player'), $withIssues, count($rows)),
            ['media_checked' => count($rows), 'media_with_issues' => $withIssues, 'media' => $rows],
            $meta + ['content_warning' => MCPHelper::CONTENT_WARNING]
        );
    }

    private static function findIssues(array $settings)
    {
        $issues = [];
        $provider = isset($settings['provider']) ? $settings['provider'] : '';
        $isPlatform = in_array($provider, self::PLATFORM_PROVIDERS, true);
        $isAudio = (isset($settings['viewType']) && 'audio' === $settings['viewType'])
            || (isset($settings['mediaType']) && 'audio' === $settings['mediaType']);

        if (!$isAudio && empty($settings['subtitles'])) {
            $issues[] = [
                'code'     => 'no_captions',
                'severity' => $isPlatform ? 'info' : 'high',
                'wcag'     => '1.2.2',
                'fix'      => $isPlatform
                    ? 'Make sure captions are enabled on the hosting platform, or upload a WebVTT track.'
                    : 'Upload a WebVTT caption track for this video.',
            ];
        }

        if (!empty($settings['autoplay']) && empty($settings['mutedAutoplay'])) {
            $issues[] = [
                'code'     => 'autoplay_with_sound',
                'severity' => 'high',
                'wcag'     => '1.4.2',
                'fix'      => 'Turn on muted autoplay, or turn autoplay off.',
            ];
        }

        if (empty($settings['title']) || '' === trim((string) $settings['title'])) {
            $issues[] = [
                'code'     => 'no_title',
                'severity' => 'medium',
                'wcag'     => '4.1.2',
                'fix'      => 'Give the media a descriptive title; it labels the player for screen readers.',
            ];
        }

        if (!$isAudio && !$isPlatform && empty($settings['posterSrc'])) {
            $issues[] = [
                'code'     => 'no_poster',
                'severity' => 'low',
                'wcag'     => '',
                'fix'      => 'Add a poster image so the player is not blank before playback.',
            ];
        }

        return $issues;
    }
}
