<?php

namespace FluentPlayer\App\Services\Migrations;

if (!defined('ABSPATH')) {
    exit;
}

use FluentPlayer\App\Services\Migrations\PrestoPlayer\Scanner;

/**
 * Resolves WHERE each migration source keeps its source-id => FluentPlayer-id map.
 *
 * Presto Player deliberately keeps the pre-existing `_fluent_player_migration_map`
 * option, untouched and unreshaped. Sites that already ran the Presto import hold
 * live state there, and MigrationController::reset() reads it to decide which posts
 * to delete — so renaming or nesting it would strand real users' imported media.
 * Giving every other source its own suffixed option means there is no data to
 * migrate and no legacy shape to sniff: back-compat holds by construction.
 */
class MigrationMap
{
    /**
     * Sources whose map predates this class and must keep their original option name.
     *
     * NOTE: the Presto flow deliberately still reads and writes
     * `Scanner::MAP_OPTION` directly (MigrationController, and the ten classes under
     * Migrations/PrestoPlayer). Routing it through this class would have meant
     * touching 24 live call sites for no behavioural gain, and the whole point of
     * this design is that Presto's on-disk state is untouched. This entry exists so
     * `optionName('presto')` resolves correctly for anything that DOES go through
     * the abstraction, and so the name lives in exactly one place.
     */
    const LEGACY_OPTIONS = [
        'presto' => Scanner::MAP_OPTION,
    ];

    const OPTION_PREFIX = '_fluent_player_migration_map_';

    /**
     * @param string $source
     * @return string
     */
    public static function optionName($source)
    {
        $key = sanitize_key($source);

        if (isset(self::LEGACY_OPTIONS[$key])) {
            return self::LEGACY_OPTIONS[$key];
        }

        return self::OPTION_PREFIX . $key;
    }

    /**
     * @param string $source
     * @return array Empty when unwritten or corrupt.
     */
    public static function get($source)
    {
        $map = get_option(self::optionName($source), []);

        return is_array($map) ? $map : [];
    }

    /**
     * Stored with autoload=false: a site with thousands of imported items produces a
     * large array that must not load on every request.
     *
     * @param string $source
     * @param array  $map
     * @return void
     */
    public static function put($source, array $map)
    {
        update_option(self::optionName($source), $map, false);
    }

    /**
     * @param string $source
     * @return void
     */
    public static function forget($source)
    {
        delete_option(self::optionName($source));
    }

    /**
     * Read a single top-level bucket, e.g. the media id map.
     *
     * @param string $source
     * @param string $bucket
     * @param mixed  $default
     * @return mixed
     */
    public static function bucket($source, $bucket, $default = [])
    {
        $map = self::get($source);

        return isset($map[$bucket]) ? $map[$bucket] : $default;
    }
}
