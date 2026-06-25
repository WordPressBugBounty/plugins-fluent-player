<?php

namespace FluentPlayer\App\Services\Migrations\PrestoPlayer;

if (!defined('ABSPATH')) {
    exit;
}

use FluentPlayer\App\Services\SettingsService;

class SettingsMigrator
{
    /**
     * Migrate Presto Player global settings to Fluent Player
     *
     * Merges PP values into FP settings. PP values overwrite existing FP values
     * for the same key. A pre-migration snapshot is saved for revert.
     *
     * @return array
     */
    public static function migrate()
    {
        $ppOptions = [
            'presto_player_branding' => get_option('presto_player_branding', []),
            'presto_player_youtube'  => get_option('presto_player_youtube', []),
        ];

        if (\FluentPlayer\App\Helpers\Helper::hasPro()) {
            $ppOptions['presto_player_google_analytics'] = get_option('presto_player_google_analytics', []);
        }

        $mappedSettings = FieldMapper::mapGlobalSettings($ppOptions);

        if (empty($mappedSettings)) {
            return [
                'migrated' => false,
                'message'  => __('No Presto Player settings found to migrate.', 'fluent-player'),
            ];
        }

        $currentSettings = SettingsService::getSettings();

        if (!get_option(Scanner::SETTINGS_BACKUP_OPTION)) {
            update_option(Scanner::SETTINGS_BACKUP_OPTION, $currentSettings, false);
        }

        $mergedSettings = self::mergeSettings($currentSettings, $mappedSettings);

        SettingsService::saveSettings($mergedSettings);

        $map = get_option(Scanner::MAP_OPTION, []);
        $map['settings'] = true;
        update_option(Scanner::MAP_OPTION, $map, false);

        $sections = array_keys($mappedSettings);

        return [
            'migrated' => true,
            'sections' => $sections,
            'message'  => sprintf(
                /* translators: %s: comma-separated list of settings sections that were migrated. */
                __('Settings migrated: %s', 'fluent-player'),
                implode(', ', $sections)
            ),
        ];
    }

    /**
     * Revert settings to pre-migration state
     *
     * @return bool Whether settings were restored
     */
    public static function revert()
    {
        $snapshot = get_option(Scanner::SETTINGS_BACKUP_OPTION);

        if (empty($snapshot) || !is_array($snapshot)) {
            return false;
        }

        SettingsService::saveSettings($snapshot);
        delete_option(Scanner::SETTINGS_BACKUP_OPTION);

        return true;
    }

    /**
     * Merge mapped PP settings into current FP settings.
     * Overwrites values in sections that PP provides.
     *
     * @param array $currentSettings
     * @param array $mappedSettings
     * @return array
     */
    private static function mergeSettings($currentSettings, $mappedSettings)
    {
        foreach ($mappedSettings as $section => $values) {
            if (!is_array($values)) {
                continue;
            }

            if (!isset($currentSettings[$section])) {
                $currentSettings[$section] = [];
            }

            foreach ($values as $key => $value) {
                if ($value !== null && $value !== '') {
                    $currentSettings[$section][$key] = $value;
                }
            }
        }

        return $currentSettings;
    }
}
