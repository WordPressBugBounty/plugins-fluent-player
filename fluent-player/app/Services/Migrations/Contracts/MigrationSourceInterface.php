<?php

namespace FluentPlayer\App\Services\Migrations\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A plugin FluentPlayer can import from.
 *
 * SCOPE: detection and preview only. `GET migration/sources`, `detect` and `scan`
 * are fully polymorphic through SourceRegistry, so a source registered via the
 * `fluent_player/migration_sources` filter is listed, detected and previewed with
 * no core change. The WRITE paths (`POST migration/media`, `POST migration/reset`)
 * still dispatch on an explicit source key in MigrationController, because each
 * importer's payload and side effects differ too much to share a signature today.
 * A third-party source will therefore surface in the UI but return 400 on import —
 * declare `capabilities()` accordingly until those paths are generalised.
 *
 * Implementations must be cheap to construct: the registry instantiates every
 * registered source on each request just to ask isAvailable().
 */
interface MigrationSourceInterface
{
    /**
     * Stable machine key used in REST params, option names and the migration map.
     * Must be lowercase a-z0-9 and underscores only.
     *
     * @return string
     */
    public function key(): string;

    /**
     * Human-readable plugin name for the card heading. Not translated here — the
     * value is a proper noun (e.g. "Presto Player").
     *
     * @return string
     */
    public function label(): string;

    /**
     * Installed version of the source plugin, for the badge beside the heading.
     * Empty string when unknown.
     *
     * @return string
     */
    public function version();

    /**
     * Whether this source has anything worth showing — the plugin is installed, or
     * a prior migration left state behind.
     *
     * Called for every source on every admin request (it gates the Migration nav
     * item), so keep it to an option read, a constant check, or at worst a single
     * indexed COUNT that the implementation memoises per request. Never a table
     * scan or a per-row loop.
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * Which migration steps this source supports, drawn from:
     * settings, presets, media, playlists, visits, email_submissions, content_rewrite.
     * The UI renders one card row per capability.
     *
     * @return string[]
     */
    public function capabilities(): array;

    /**
     * Detection payload for the card: counts, version, active state, and any
     * "cannot migrate" notes.
     *
     * @return array
     */
    public function detect(): array;

    /**
     * Itemised preview for the review step.
     *
     * @param array $filters e.g. ['type' => 'youtube', 'search' => 'intro']
     * @return array
     */
    public function scan(array $filters = []): array;
}
