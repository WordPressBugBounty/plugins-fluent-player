<?php

namespace FluentPlayer\App\Utils\Enqueuer;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\App;
use FluentPlayer\Framework\Support\Arr;
class Vite extends Enqueuer
{
    /**
     * @method static enqueueScript(string $handle, string $src, array $dependency = [], string|null $version = null, bool|null $inFooter = false)
     * @method static enqueueStyle(string $handle, string $src, array $dependency = [], string|null $version = null)
     */

    private array $moduleScripts = [];
    private bool $isScriptFilterAdded = false;
    private static string $viteHostProtocol = 'http://';
    private static string $viteHost = 'localhost';
    private static string $vitePort = '8880';

    protected static $instance = null;
    protected static $lastJsHandel = null;

    private $manifestData = null;

    public static function __callStatic($method, $params)
    {
        if (static::$instance == null) {
            static::$instance = new static();
            if (!self::isOnDevMode()) {
                (static::$instance)->loadViteManifest();
            }
        }
        return call_user_func_array(array(static::$instance, $method), $params);
    }

    private function loadViteManifest()
    {
        if (!empty((static::$instance)->manifestData)) {
            return;
        }

        $manifestPath = App::make('path.assets') . 'manifest.json';

        if (!file_exists($manifestPath)) {
            throw new \Exception('Vite Manifest Not Found. Run : npm run dev or npm run prod');
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading manifest file for build process, not user-uploaded content
        $manifestFile = fopen($manifestPath, "r");
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading manifest file for build process, not user-uploaded content
        $manifestData = fread($manifestFile, filesize($manifestPath));
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing file handle opened above
        fclose($manifestFile);
        (static::$instance)->manifestData = json_decode($manifestData, true);
    }

    private function enqueueScript($handle, $src, $dependency = [], $version = null, $inFooter = false)
    {
        if (in_array($handle, (static::$instance)->moduleScripts)) {
            if (self::isOnDevMode()) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Only used in development mode for debugging
                $callerReference = (debug_backtrace()[2]);
                $fileParts = explode('plugins', $callerReference['file']);
                $fileName = isset($fileParts[1]) ? $fileParts[1] : $callerReference['file'];
                $line = $callerReference['line'];
                //throw new \Exception("This handel Has been used'. 'Filename: $fileName Line: $line");
            }
        }

        (static::$instance)->moduleScripts[] = $handle;

        static::$lastJsHandel = $handle;

        if (!(static::$instance)->isScriptFilterAdded) {
            add_filter('script_loader_tag', function ($tag, $handle, $src) {
                return (static::$instance)->addModuleToScript($tag, $handle, $src);
            }, 10, 3);
            (static::$instance)->isScriptFilterAdded = true;
        }


        if (!static::isOnDevMode()) {
            $assetFile = (static::$instance)->getFileFromManifest($src);
            $srcPath = static::getProductionFilePath($assetFile);
            static::enqueueDependentRecursiveStyle($assetFile);
        } else {
            // Add timestamp for cache busting in dev mode
            $srcPath = static::getVitePath() . $src . '?t=' . time();
        }

        wp_enqueue_script(
            $handle,
            $srcPath,
            $dependency,
            $version,
            $inFooter
        );
        return $this;
    }

    private function getFileFromManifest($src)
    {

        if (isset((static::$instance)->manifestData[static::getResourceDirectory() . $src])) {
            return (static::$instance)->manifestData[static::getResourceDirectory() . $src];
        }

        if (static::isOnDevMode()) {
            throw new \Exception(sprintf(
                '%s file not found in vite manifest, Make sure it is in rollupOptions input and build again',
                esc_html($src)
            ));
        }

        return '';
    }

    public static function enqueueDependentRecursiveStyle($file) {
        if (empty($file) || !is_array($file)) {
            return;
        }

        $assetPath = static::getAssetPath();
        if (isset($file['css']) && is_array($file['css'])) {
            foreach ($file['css'] as $key => $path) {
                if (empty($path)) {
                    continue;
                }

                wp_enqueue_style(
                    $file['file'] . '_' . $key . '_css',
                    $assetPath . $path,
                    [],
                    FLUENT_PLAYER_VERSION
                );
            }
        }
    }



    static function with($params)
    {
        if (!is_array($params) || !Arr::isAssoc($params) || empty(static::$lastJsHandel)) {
            static::$lastJsHandel = null;
            return;
        }

        foreach ($params as $key => $val) {
            wp_localize_script(static::$lastJsHandel, $key, $val);
        }
        static::$lastJsHandel = null;
    }

    private function enqueueStyle($handle, $src, $dependency = [], $version = null)
    {
        if (!static::isOnDevMode()) {

            $assetFile = (static::$instance)->getFileFromManifest($src);

            // Skip if assetFile is empty
            if (empty($assetFile)) {
                return;
            }

            $srcPath = static::getProductionFilePath($assetFile);

            // Only enqueue if we have a valid path
            if (!empty($srcPath)) {
                wp_enqueue_style($handle, $srcPath, $dependency, $version);
                static::enqueueDependentRecursiveStyle($assetFile);
            }
        } else {
            // Add timestamp for cache busting in dev mode
            $srcPath = static::getVitePath() . $src . '?t=' . time();
            wp_enqueue_style($handle, $srcPath, $dependency, $version);
        }
    }

    private function enqueueStaticScript($handle, $src, $dependency = [], $version = null, $inFooter = false)
    {
        wp_enqueue_script(
            $handle,
            static::getEnqueuePath($src),
            $dependency,
            $version,
            $inFooter
        );
    }

    private function enqueueStaticStyle($handle, $src, $dependency = [], $version = null)
    {
        wp_enqueue_style(
            $handle,
            static::getEnqueuePath($src),
            $dependency,
            $version
        );
    }


    private static ?bool $devModeCache = null;

    static function isOnDevMode(): bool
    {
        if (static::$devModeCache !== null) {
            return static::$devModeCache;
        }

        if (defined('FLUENT_PLAYER_VITE_DEV')) {
            static::$devModeCache = (bool) FLUENT_PLAYER_VITE_DEV;
            return static::$devModeCache;
        }

        static::$devModeCache = App::getInstance()->config->get('app.env') === 'dev';
        return static::$devModeCache;
    }

    static function getVitePath(): string
    {
        return static::$viteHostProtocol . static::$viteHost . ":" . (static::$vitePort) . '/' . (static::getResourceDirectory());
    }

    static function getEnqueuePath($path = ''): string
    {
        if (static::isOnDevMode()) {
            $fullPath = static::getVitePath() . $path;
            // Add timestamp for cache busting in dev mode
            if (!empty($path)) {
                $fullPath .= '?t=' . time();
            }
            return $fullPath;
        }

        // Production mode - resolve through manifest
        if (!empty($path)) {
            // Ensure instance exists and manifest is loaded
            if (static::$instance === null) {
                static::$instance = new static();
                static::$instance->loadViteManifest();
            }
            
            $assetFile = static::$instance->getFileFromManifest($path);
            if (!empty($assetFile)) {
                return static::getProductionFilePath($assetFile);
            }
        }

        return static::getAssetPath() . $path;
    }

    static function getStaticFilePath($path = ''): string
    {
        return static::getEnqueuePath($path);
    }
    private function addModuleToScript($tag, $handle, $src)
    {
        if (in_array($handle, (static::$instance)->moduleScripts)) {
            // Add crossorigin attribute to help with CORS issues
            // Add defer for better performance (non-blocking)
            return wp_get_script_tag(
                [
                    'src' =>  esc_url($src),
                    'type' => 'module',
                    'crossorigin' => 'anonymous',
                    'defer' => true
                ]
            );
        }
        return $tag;
    }
}
