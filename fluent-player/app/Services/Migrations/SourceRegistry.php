<?php

namespace FluentPlayer\App\Services\Migrations;

if (!defined('ABSPATH')) {
    exit;
}

use FluentPlayer\App\Services\Migrations\Contracts\MigrationSourceInterface;
use FluentPlayer\App\Services\Migrations\PrestoPlayer\PrestoPlayerSource;
use FluentPlayer\App\Services\Migrations\AllInOneVideoGallery\AiovgSource;

/**
 * Holds the importable sources for the current request.
 *
 * `presto` stays the default key so any caller that omits ?source= — the shipped
 * admin UI, WP-CLI, the Functional Cest — keeps hitting Presto Player exactly as
 * before this registry existed.
 */
class SourceRegistry
{
    const DEFAULT_KEY = 'presto';

    /**
     * Built-in keys no third party may take over.
     *
     * The write paths (migrateMedia, reset) dispatch on the resolved key and reset
     * DELETES real posts, so a hijacked built-in is a data-loss vector rather than
     * a display quirk.
     */
    const RESERVED_KEYS = ['presto', 'aiovg'];

    /** @var array<string,MigrationSourceInterface>|null Null until booted. */
    private static $sources = null;

    /**
     * @return string
     */
    public static function defaultKey()
    {
        return self::DEFAULT_KEY;
    }

    /**
     * @param MigrationSourceInterface $source
     * @return void
     */
    public static function register(MigrationSourceInterface $source)
    {
        self::boot();

        // Same guard as the filter path in boot(): register() is the other public
        // door, and leaving it open made the protection meaningless.
        if (in_array(sanitize_key($source->key()), self::RESERVED_KEYS, true)) {
            return;
        }

        self::add(self::$sources, $source);
    }

    /**
     * @return string[]
     */
    public static function reservedKeys()
    {
        return self::RESERVED_KEYS;
    }

    /**
     * Single place that normalises a source's key. register() and boot() both go
     * through it, so a source declaring `AcmePlayer` cannot be stored under a key
     * that get()'s sanitize_key() lookup will never match.
     *
     * @param array<string,MigrationSourceInterface> $sources
     * @param MigrationSourceInterface               $source
     * @return void
     */
    private static function add(array &$sources, MigrationSourceInterface $source)
    {
        $key = sanitize_key($source->key());

        if ($key === '') {
            return;
        }

        $sources[$key] = $source;
    }

    /**
     * @return array<string,MigrationSourceInterface>
     */
    public static function all()
    {
        self::boot();

        return self::$sources;
    }

    /**
     * @param string $key
     * @return MigrationSourceInterface|null
     */
    public static function get($key)
    {
        $sources = self::all();
        $key = sanitize_key($key);

        return isset($sources[$key]) ? $sources[$key] : null;
    }

    /**
     * Sources with something worth showing — one card each.
     *
     * @return array<string,MigrationSourceInterface>
     */
    public static function detected()
    {
        return array_filter(self::all(), function ($source) {
            return $source->isAvailable();
        });
    }

    /**
     * Drop the resolved set so the next call re-runs the filter. Test seam, and the
     * way a late `add_filter` gets picked up.
     *
     * @return void
     */
    public static function reset()
    {
        self::$sources = null;
    }

    /**
     * Resolve built-ins plus anything a third party adds, once per request.
     *
     * @return void
     */
    private static function boot()
    {
        if (self::$sources !== null) {
            return;
        }

        $builtIn = [];
        foreach ([new PrestoPlayerSource(), new AiovgSource()] as $source) {
            self::add($builtIn, $source);
        }

        // Assign before filtering so a filter callback that calls back into the
        // registry sees the built-ins rather than recursing.
        self::$sources = $builtIn;

        $filtered = apply_filters('fluent_player/migration_sources', $builtIn);

        if (!is_array($filtered)) {
            return;
        }

        foreach ($filtered as $source) {
            // A filter returning junk must not be able to remove the built-in
            // sources or hand the controller something it cannot call.
            if (!$source instanceof MigrationSourceInterface) {
                continue;
            }

            // A filter must not be able to REPLACE a built-in — see RESERVED_KEYS.
            if (in_array(sanitize_key($source->key()), self::RESERVED_KEYS, true)) {
                continue;
            }

            // Merge into the live array, not a copy of $builtIn: a callback that
            // called register() during this filter already wrote there, and
            // overwriting would silently discard that registration.
            self::add(self::$sources, $source);
        }
    }
}
