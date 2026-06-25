<?php

namespace FluentPlayer\App\Hooks\Handlers;
if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Foundation\Application;
use FluentPlayer\App\Hooks\Handlers\ScheduledCleanupHandler;

class DeactivationHandler
{
    protected $app = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }
    
    public function handle()
    {
        ScheduledCleanupHandler::unschedule();
        flush_rewrite_rules();
    }
}
