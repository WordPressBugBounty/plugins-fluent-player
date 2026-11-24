<?php
/**
 * Social preview tags (Open Graph and Twitter Card) for a shared video page.
 *
 * @var array $tags Tag name => content.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

if (!defined('ABSPATH')) exit;

foreach ($tags as $name => $content) : ?>
<meta <?php echo strpos($name, 'og:') === 0 ? 'property' : 'name'; ?>="<?php echo esc_attr($name); ?>" content="<?php echo esc_attr($content); ?>">
<?php endforeach;
