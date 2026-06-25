<?php
if (!defined('ABSPATH')) exit;

/*
 * Require any extra files here. For example:
 * require_once "shortcodes.php";
 */

/**
 * @var $app WPFluent\Foundation\Application
 */

$app->ready(function() use ($app) {
	$app->addAction('heartbeat_send', 'HeartBeat', 10, 2);
});
