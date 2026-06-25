<?php
if (!defined('ABSPATH')) exit;

/**
 * @var $router FluentPlayer\Framework\Http\Router
 */

$router->prefix('media')->withPolicy('MediaPolicy')->group(function ($router) {
    $router->get('/', 'MediaController@get');
    $router->get('/metadata', 'MediaController@getMetadata');
    $router->get('/search', 'MediaController@search');
    $router->get('/tags', 'MediaController@getTags');
    $router->post('/tags', 'MediaController@createTag');
    $router->put('/tags', 'MediaController@renameTag');
    $router->delete('/tags', 'MediaController@deleteTag');
    $router->get('{id}', 'MediaController@find');
    $router->post('/', 'MediaController@store');
    $router->put('{id}', 'MediaController@update');
    $router->delete('{id}', 'MediaController@delete');
});

$router->prefix('presets')->withPolicy('PresetPolicy')->group(function ($router) {
    $router->get('/', 'PresetController@get');
    $router->get('{slug}', 'PresetController@find');
});

$router->prefix('settings')->withPolicy('SettingsPolicy')->group(function ($router) {
    $router->get('/', 'SettingsController@get');
    $router->put('/', 'SettingsController@update');
    $router->post('/reset', 'SettingsController@reset');
});

$router->prefix('integrations')->withPolicy('SettingsPolicy')->group(function ($router) {
    $router->get('/', 'IntegrationController@getIntegrations');
    $router->get('/fields', 'IntegrationController@getIntegrationFields');
    $router->post('{integration}', 'IntegrationController@saveIntegrationSettings');
    $router->post('{integration}/test-connection', 'IntegrationController@testConnection');
});

// Email Provider Routes
$router->prefix('email-providers')->withPolicy('SettingsPolicy')->group(function ($router) {
    $router->get('/', 'EmailProviderController@getProvidersSettings');
    $router->post('/', 'EmailProviderController@saveProviderSettings');
    $router->get('/export-emails', 'EmailProviderController@exportEmails');
    $router->get('/{provider}/{resource}', 'EmailProviderController@getProviderResource');
    $router->post('/{provider}/validate-field/{field}', 'EmailProviderController@validateProviderField');
});

// YouTube API Routes
$router->prefix('youtube')->withPolicy('SettingsPolicy')->group(function ($router) {
    $router->get('/channel-info', 'YouTubeController@getChannelInfo');
});

// Layer API Routes
$router->prefix('layer')->withPolicy('LayerPolicy')->group(function ($router) {
    $router->get('/forms/{type}', 'LayerController@getForms');
    $router->get('/form-preview', 'LayerController@getFormPreview');
    $router->get('/shortcode-preview', 'LayerController@getShortcodePreview');
});

// Smartcode API Routes
$router->prefix('smartcodes')->withPolicy('SettingsPolicy')->group(function ($router) {
    $router->get('/', 'SmartcodeController@get');
});

// Migration API Routes
$router->prefix('migration')->withPolicy('MigrationPolicy')->group(function ($router) {
    $router->get('/detect', 'MigrationController@detect');
    $router->get('/scan', 'MigrationController@scan');
    $router->post('/presets', 'MigrationController@migratePresets');
    $router->post('/settings', 'MigrationController@migrateSettings');
    $router->post('/media', 'MigrationController@migrateMedia');
    $router->post('/playlists', 'MigrationController@migratePlaylists');
    $router->post('/visits', 'MigrationController@migrateVisits');
    $router->post('/email-submissions', 'MigrationController@migrateEmailSubmissions');
    $router->post('/content-rewrite', 'MigrationController@rewriteContent');
    $router->post('/reset', 'MigrationController@reset');
});
