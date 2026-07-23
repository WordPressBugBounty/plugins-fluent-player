<?php

namespace FluentPlayer\App\Services;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\PageBuilders\AbstractPageBuilder;

/**
 * Registry + boot for page-builder integrations. Mirrors IntegrationService:
 * builders register their class, and boot() instantiates each, skipping any
 * whose builder plugin is not active. Third parties can add builders via the
 * fluent_player/page_builders filter.
 */
class PageBuilderService
{
    /** @var array<string,string> class-name => class-name */
    protected static $builders = [];

    /** @var array|null Per-request memo of the block editor vars every builder panel localizes. */
    protected static $blockVars = null;

    /**
     * The block editor vars (media list, default settings, SVG icons, languages, REST
     * config) that every builder's settings panel localizes. Building them runs a media
     * query plus icon/language assembly and is identical within a request, so the result
     * is memoized — a single Divi VB or Elementor editor load would otherwise rebuild it
     * several times (once per enqueued panel).
     *
     * @return array
     */
    public static function blockVars(): array
    {
        if (self::$blockVars === null) {
            self::$blockVars = (new \FluentPlayer\App\Blocks\MediaBlock())->getEditorVars();
        }

        return self::$blockVars;
    }

    /** Clear the memoized block editor vars. Test/seam helper; see RefreshDatabase. */
    public static function flushBlockVars(): void
    {
        self::$blockVars = null;
    }

    /**
     * Register page-builder adapter classes (must extend AbstractPageBuilder).
     * Must run at plugin load: an adapter with pre-init hook needs may expose a
     * static registerEarly(), invoked immediately (once per class).
     *
     * @param string|string[] $classNames
     */
    public static function register($classNames): void
    {
        foreach ((array) $classNames as $className) {
            if (isset(self::$builders[$className])) {
                continue;
            }

            self::$builders[$className] = $className;

            if (method_exists($className, 'registerEarly')) {
                $className::registerEarly();
            }
        }
    }

    /** @return array<int,string> */
    public static function getBuilders(): array
    {
        return array_values(apply_filters('fluent_player/page_builders', self::$builders));
    }

    /** Instantiate + register each active builder. Safe to call once on init. */
    public static function boot(): void
    {
        foreach (self::getBuilders() as $className) {
            if (!is_string($className) || !class_exists($className)) {
                continue;
            }

            $builder = new $className();
            if ($builder instanceof AbstractPageBuilder && $builder->isActive()) {
                $builder->register();
            }
        }
    }
}
