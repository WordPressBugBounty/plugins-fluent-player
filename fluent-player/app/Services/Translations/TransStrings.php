<?php

namespace FluentPlayer\App\Services\Translations;

if (!defined('ABSPATH')) exit;

class TransStrings
{
    public static function getStrings(): array
    {
        $translations = require __DIR__ . '/admin-translations.php';
        return apply_filters("fluent_player/admin_translations", $translations, []);
    }

    public static function getFrontendStrings(): array
    {
        $translations = require __DIR__ . '/frontend-translations.php';
        return apply_filters("fluent_player/frontend_translations", $translations, []);
    }
}
