<?php

namespace FluentPlayer\App\Hooks\Handlers;
if (!defined('ABSPATH')) exit;

use FluentPlayer\Database\DBMigrator;
use FluentPlayer\Framework\Foundation\Application;
use FluentPlayer\App\Hooks\Handlers\ScheduledCleanupHandler;
use FluentPlayer\App\Services\SettingsService;

class ActivationHandler
{
    protected $app = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }
    
    public function handle($networkWide = false)
    {
        if (!$this->app->isMultisite()) {
            return $this->handleSinglesite();
        } else {
            return $this->handleMultisite($networkWide);
        }
    }

    protected function handleSinglesite()
    {
        $this->activatePlugin();
    }

    protected function handleMultisite($networkWide)
    {
        if ($networkWide) {
            if (is_super_admin()) {
                $sites = get_sites();
                
                foreach ( $sites as $site ) {
                    switch_to_blog( $site->blog_id );
                    $this->activatePlugin();
                    restore_current_blog();
                }
            }
        } else {
            if (current_user_can('activate_plugins')) {
                $this->activatePlugin();
            }
        }
    }

    protected function activatePlugin()
    {
        $isFreshInstall = false === get_option('fluent_player_db_version');

        DBMigrator::run();
        update_option('fluent_player_db_version', FLUENT_PLAYER_DB_VERSION, 'no');
        CPTHandler::forceRewriteRulesFlush();
        ScheduledCleanupHandler::schedule();

        if ($isFreshInstall) {
            SettingsService::seedFreshInstallDefaults();
        }
    }
}
