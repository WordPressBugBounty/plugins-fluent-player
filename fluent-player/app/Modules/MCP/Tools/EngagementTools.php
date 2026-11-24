<?php

namespace FluentPlayer\App\Modules\MCP\Tools;

use FluentPlayer\App\Models\EmailCollection;
use FluentPlayer\App\Modules\MCP\Support\ActivityLog;
use FluentPlayer\App\Modules\MCP\Support\ErrorCodes;
use FluentPlayer\App\Modules\MCP\Support\MCPHelper;

class EngagementTools
{
    const TOP_MEDIA = 20;

    public static function definitions()
    {
        $group = __('Engagement', 'fluent-player');
        $date = ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$', 'description' => 'YYYY-MM-DD'];

        return [
            'fluent-player/get-email-leads' => [
                'label'            => __('Get Email Leads', 'fluent-player'),
                'description'      => __('Emails collected by email-capture forms (preset-level and layers): totals, leads per media and a page of leads. Emails are masked unless include_full_emails is true (that read is logged).', 'fluent-player'),
                'group'            => $group,
                'capability'       => 'manage_options',
                'annotations'      => ['readonly' => true],
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => array_merge([
                        'media_id'            => ['type' => 'integer', 'minimum' => 1],
                        'date_from'           => $date,
                        'date_to'             => $date,
                        'include_full_emails' => ['type' => 'boolean', 'default' => false],
                    ], MCPHelper::pageSchema()),
                ],
                'execute_callback' => [self::class, 'getEmailLeads'],
            ],
        ];
    }

    public static function getEmailLeads($params)
    {
        $paging = MCPHelper::pagination($params);
        $fullEmails = !empty($params['include_full_emails']);

        $query = EmailCollection::query();
        if (!empty($params['media_id'])) {
            $query->where('media_id', (int) $params['media_id']);
        }
        if (!empty($params['date_from'])) {
            $query->where('created_at', '>=', $params['date_from'] . ' 00:00:00');
        }
        if (!empty($params['date_to'])) {
            $query->where('created_at', '<=', $params['date_to'] . ' 23:59:59');
        }

        $total = (clone $query)->count();

        $byMedia = [];
        $counts = (clone $query)
            ->selectRaw('media_id, COUNT(*) as leads')
            ->groupBy('media_id')
            ->orderBy('leads', 'DESC')
            ->limit(self::TOP_MEDIA)
            ->get();
        foreach ($counts as $count) {
            $byMedia[] = [
                'media_id' => (int) $count->media_id,
                'title'    => $count->media_id ? MCPHelper::untrusted(MCPHelper::plainTitle($count->media_id)) : '',
                'leads'    => (int) $count->leads,
            ];
        }

        $rows = (clone $query)
            ->select(['id', 'email', 'media_id', 'video_time', 'device', 'created_at'])
            ->orderBy('id', 'DESC')
            ->offset(($paging['page'] - 1) * $paging['per_page'])
            ->limit($paging['per_page'])
            ->get();

        $leads = [];
        foreach ($rows as $row) {
            $leads[] = [
                'id'         => (int) $row->id,
                'email'      => $fullEmails ? MCPHelper::untrusted($row->email) : self::maskEmail($row->email),
                'media_id'   => (int) $row->media_id,
                'video_time' => (float) $row->video_time,
                'device'     => (string) $row->device,
                'created_at' => (string) $row->created_at,
            ];
        }

        $meta = MCPHelper::pagingMeta($paging['page'], $paging['per_page'], $total);
        if ($fullEmails || $byMedia) {
            $meta['content_warning'] = MCPHelper::CONTENT_WARNING;
        }

        $result = MCPHelper::envelope(
            /* translators: %d: number of leads */
            sprintf(_n('%d lead.', '%d leads.', $total, 'fluent-player'), $total),
            ['total' => $total, 'by_media' => $byMedia, 'leads' => $leads],
            $meta
        );

        if ($fullEmails) {
            ActivityLog::record('fluent-player/get-email-leads', 'leads', $params, $result);
        }

        return $result;
    }

    public static function maskEmail($email)
    {
        $email = (string) $email;
        $at = strrpos($email, '@');
        if (false === $at || 0 === $at) {
            return '***';
        }

        return mb_substr($email, 0, 1) . '***' . substr($email, $at);
    }
}
