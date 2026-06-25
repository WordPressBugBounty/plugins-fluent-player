<?php

namespace FluentPlayer\App\Utils\Enqueuer;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\App;

abstract class Enqueuer
{
    private static string $resourceDirectory = 'resources/';

    public static function getResourceDirectory(){
        return static::$resourceDirectory;
    }
    private function enqueueStyle($handle, $src, $dependency = [], $version = null){}
    private function enqueueStaticStyle($handle, $src, $dependency = [], $version = null){}
    private function enqueueScript($handle, $src, $dependency = [], $version = null, $inFooter = false){}
    private function enqueueStaticScript($handle, $src, $dependency = [], $version = null, $inFooter = false){}

    static function getEnqueuePath($path = ''){}

    static function getAssetPath(): string
    {
        return App::getInstance()['url.assets'];
    }

    static function getProductionFilePath($assetFile) {
        if (empty($assetFile) || !isset($assetFile['file']) || empty($assetFile['file'])) {
            // Handle unexpected input
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug logging for development
                error_log('Unexpected input in getProductionFilePath: ' . print_r($assetFile, true));
            }
            return static::getAssetPath();
        }

        return static::getAssetPath() . $assetFile['file'];
    }
}