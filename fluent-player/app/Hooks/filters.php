<?php

if (!defined('ABSPATH')) exit;

/**
 * All registered filter's handlers should be in app\Hooks\Handlers,
 * addFilter is similar to add_filter and addCustomFlter is just a
 * wrapper over add_filter which will add a prefix to the hook name
 * using the plugin slug to make it unique in all wordpress plugins,
 * ex: $app->addCustomFilter('foo', ['FooHandler', 'handleFoo']) is
 * equivalent to add_filter('slug-foo', ['FooHandler', 'handleFoo']).
 */

/**
 * @var $app FluentPlayer\Framework\Foundation\Application
 */

// Add form types status to block vars
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed -- Filter hook name is a valid string, not a PHP identifier
$app->addFilter('fluent_player/media_block_vars', function($vars) {
    $vars['formTypes'] = \FluentPlayer\App\Services\LayerService::getFormTypesStatus();
    return $vars;
});

$app->addFilter('fluent_player/admin_vars', function($vars) {
    $vars['show_migration'] = \FluentPlayer\App\Services\Migrations\PrestoPlayer\Scanner::shouldShow();
    return $vars;
});

add_filter('fluent_player/parse_smartcodes', function ($text, $context = []) {
    return \FluentPlayer\App\Services\Smartcode\SmartcodeParser::parse($text, $context);
}, 10, 2);
