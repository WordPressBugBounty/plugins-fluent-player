<?php

namespace FluentPlayer\App\Hooks\Handlers;
if (!defined('ABSPATH')) exit;

use FluentPlayer\App\App;

class CPTHandler
{
	/*
	* Add all Custom Post Type classes here to
	* register all of your Custom Post Types.
	*/

	protected $customPostTypes = [
		FluentPlayerMediaCPT::class,
	];

	public function registerPostTypes()
	{
		foreach ($this->customPostTypes as $cpt) {
			App::make($cpt)->registerPostType();
		}
	}

    /**
     * Force a rewrite rules flush on next page load
     */
    public static function forceRewriteRulesFlush() {
        update_option('fluent_player_force_flush_rules', true);
    }

    /**
     * Flush rewrite rules if necessary
     */
    public function maybeFlushRules()
    {
        // Get the stored version
        $stored_version = get_option('fluent_player_rewrite_version', '0');
        $current_version = defined('FLUENT_PLAYER_VERSION') ? FLUENT_PLAYER_VERSION : '1.0';
        // Only flush if:
        // 1. Plugin version has changed, or
        // 2. Rules haven't been added yet, or
        // 3. Force flush flag is set
        if (
            $stored_version !== $current_version ||
            !get_option('fluent_player_rewrite_rules_added') ||
            get_option('fluent_player_force_flush_rules')
        ) {
            // This is expensive, so we only do it when needed
            flush_rewrite_rules();

            // Update version and remove force flag
            update_option('fluent_player_rewrite_version', $current_version);
            update_option('fluent_player_rewrite_rules_added', true);
            delete_option('fluent_player_force_flush_rules');
        }
    }

}
