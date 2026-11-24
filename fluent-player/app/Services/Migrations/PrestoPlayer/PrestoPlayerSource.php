<?php

namespace FluentPlayer\App\Services\Migrations\PrestoPlayer;

if (!defined('ABSPATH')) {
    exit;
}

use FluentPlayer\App\Services\Migrations\Contracts\MigrationSourceInterface;
use FluentPlayer\Framework\Support\Arr;

/**
 * Presto Player as a registry source.
 *
 * A thin adapter only — every method delegates to the existing Scanner, which is
 * left exactly as it was. Nothing here changes what Presto migration does; it just
 * makes Presto reachable by key alongside other sources.
 */
class PrestoPlayerSource implements MigrationSourceInterface
{
    /**
     * Scanner::detect() runs several COUNT queries, and both version() and the
     * controller want its output in the same request.
     *
     * @var array|null
     */
    private $detected = null;

    public function key(): string
    {
        return 'presto';
    }

    public function label(): string
    {
        return 'Presto Player';
    }

    public function version()
    {
        // Read the plugin header rather than delegating to detect(): that runs five
        // LIKE aggregates over wp_posts plus preset/visit/playlist counts, and
        // /migration/sources calls this for every detected source just to render a
        // version badge.
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach (get_plugins() as $path => $info) {
            if (strpos($path, 'presto-player') !== false) {
                return (string) Arr::get($info, 'Version', '');
            }
        }

        return '';
    }

    public function isAvailable(): bool
    {
        // Deliberately Scanner::shouldShow() and not detect(): this is called for
        // every source on every request, and shouldShow() is a single option read
        // plus a constant check.
        return (bool) Scanner::shouldShow();
    }

    public function capabilities(): array
    {
        return [
            'settings',
            'presets',
            'media',
            'playlists',
            'visits',
            'email_submissions',
            'content_rewrite',
        ];
    }

    public function detect(): array
    {
        if ($this->detected === null) {
            $this->detected = Scanner::detect();
        }

        return $this->detected;
    }

    public function scan(array $filters = []): array
    {
        return Scanner::scan(
            Arr::get($filters, 'type', 'all'),
            Arr::get($filters, 'search', '')
        );
    }
}
