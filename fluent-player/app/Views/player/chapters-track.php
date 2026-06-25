<?php
/**
 * Chapters Track
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Hooks\Helpers\PlayerHelper;
use FluentPlayer\Framework\Support\Arr;

// Get chapters from settings
$chapters = Arr::get($settings, 'chapters', []);

// Generate VTT content for chapters
$vtt = PlayerHelper::generateChaptersVtt($chapters);

// Base64 encode the VTT content
$vttBase64 = PlayerHelper::generateVttDataUri($vtt);
?>

<track kind="chapters" label="Chapters" src="<?php echo esc_attr($vttBase64); ?>" default />