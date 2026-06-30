<?php defined('ABSPATH') or die(__FILE__);

/**
Plugin Name: FluentPlayer – Video Player With Forms & Lead Capture
Description: FluentPlayer - A Media Player for WordPress
Version: 1.0.9
Author: techjewel, wpmanageninja
Author URI: https://fluentplayer.com
License: GPLv2 or later
Text Domain: fluent-player
Domain Path: /language
*/

defined('ABSPATH') or die;

defined('FLUENT_PLAYER') or define('FLUENT_PLAYER', true);
define('FLUENT_PLAYER_DIR_PATH', plugin_dir_path(__FILE__));
define('FLUENT_PLAYER_URL', plugin_dir_url(__FILE__));
define('FLUENT_PLAYER_DIR_FILE', __FILE__);

defined('FLUENT_PLAYER_VERSION') or define('FLUENT_PLAYER_VERSION', '1.0.9');
define('FLUENT_PLAYER_DB_VERSION', '1.2.3');
defined('FLUENT_PLAYER_MIN_PRO_VERSION') or define('FLUENT_PLAYER_MIN_PRO_VERSION', '1.0.7');
/*************** Code IS Poetry **************/
return (function($_) {

    return $_(__FILE__);
})(
    require __DIR__.'/boot/app.php',

    require __DIR__.'/vendor/autoload.php'
);
/************ Built With WPFluent *************/
