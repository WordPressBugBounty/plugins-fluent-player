<?php
if (!defined('ABSPATH')) {
    exit;
}

use FluentPlayer\Framework\Foundation\Application;
use FluentPlayer\App\Hooks\Handlers\ActivationHandler;
use FluentPlayer\App\Hooks\Handlers\DeactivationHandler;

return function ($file) {
    /**
     * Load custom error handler only in debug mode
     * This follows WordPress debugging best practices by:
     * 1. Only loading error handler when WP_DEBUG is true
     * 2. Not directly modifying error_reporting levels
     * 3. Respecting WordPress debug configuration
     */
    $errorHandler = __DIR__ . '/error_handler.php';
    if (defined('WP_DEBUG') && WP_DEBUG && file_exists($errorHandler)) {
        require_once $errorHandler;
    }

    $app = new Application($file);

    register_activation_hook($file, function () use ($app) {
        ($app->make(ActivationHandler::class))->handle();
    });

    register_deactivation_hook($file, function () use ($app) {
        ($app->make(DeactivationHandler::class))->handle();
    });

    add_action('plugins_loaded', function () use ($app) {
        do_action('fluent_player/loaded', $app);

        // Run migrations if DB version is stale
        $currentDBVersion = get_option('fluent_player_db_version');
        if (!$currentDBVersion || version_compare($currentDBVersion, FLUENT_PLAYER_DB_VERSION, '<')) {
            \FluentPlayer\Database\DBMigrator::run();
            update_option('fluent_player_db_version', FLUENT_PLAYER_DB_VERSION, 'no');
        }
    });

    return $app;
};
