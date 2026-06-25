<?php
if (!defined('ABSPATH')) exit;

$router->namespace('FluentPlayer\App\Http\Controllers')->group(function($router) {
	require_once __DIR__ . "/api.php";
});
