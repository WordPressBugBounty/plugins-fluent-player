<?php

namespace FluentPlayer\App\Hooks\Handlers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Blocks\MediaBlock;

class BlocksHandler
{
    public function handle()
    {
        (new MediaBlock())->register();
    }
}
