<?php
if (!defined('ABSPATH')) exit;

/**
 * @var string $slug
 * @var array $menuItems
 * @var string $baseUrl
 * @var string $logo
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed from controller, not global variables

?>
<div id="fluentplayer-app" class="fluent-player-app">
    <div class="fplayer-app">
        <div class="fplayer-main-menu-items">
            <div class="fplayer-navbar">
                <div class="fplayer-navbar-left">
                    <div class="fplayer-menu-logo-holder">
                        <a href="<?php echo esc_url($baseUrl); ?>">
                            <img class="fplayer-logo" src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr__('FluentPlayer', 'fluent-player'); ?>" />
                        </a>
                    </div>
                </div>

                <div class="fplayer-navbar-center">
                    <button type="button" class="fplayer-handheld" aria-label="<?php echo esc_attr__('Toggle navigation menu', 'fluent-player'); ?>"><span class="dashicons dashicons-menu-alt3" aria-hidden="true"></span></button>
                    <ul class="fplayer-menu">
                        <?php foreach ($menuItems as $item): ?>
                            <?php if ($item['key'] === 'settings') { continue; } ?>
                            <li data-key="<?php echo esc_attr($item['key']); ?>" class="fplayer-menu-item <?php echo esc_attr(isset($item['active']) ? 'active-item' : ''); ?> fplayer-item__<?php echo esc_attr($item['key']); ?>"<?php if (!empty($item['hidden'])) { echo ' style="' . esc_attr('display:none') . '"'; } ?>>
                                <a class="fplayer-menu-primary" href="<?php echo esc_url($item['permalink']); ?>">
                                    <?php echo esc_html($item['label']); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="fplayer-navbar-right">
                    <a class="fplayer-navbar-settings-link" href="<?php echo esc_url($baseUrl . 'settings'); ?>" title="<?php echo esc_attr__('Settings', 'fluent-player'); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 15 15" fill="none" aria-hidden="true" focusable="false">
                            <path d="M0 7.2285C0 6.57975 0.0825 5.95125 0.237 5.3505C0.651349 5.37228 1.06365 5.27906 1.42833 5.08115C1.79301 4.88324 2.09586 4.58834 2.3034 4.22906C2.51095 3.86977 2.6151 3.4601 2.60436 3.04532C2.59361 2.63053 2.46837 2.2268 2.2425 1.87875C3.14921 0.986677 4.2681 0.340122 5.49375 0C5.68199 0.370104 5.96898 0.6809 6.32294 0.897983C6.6769 1.11506 7.08402 1.22997 7.49925 1.22997C7.91447 1.22997 8.3216 1.11506 8.67556 0.897983C9.02952 0.6809 9.31651 0.370104 9.50475 0C10.7304 0.340122 11.8493 0.986677 12.756 1.87875C12.5299 2.22687 12.4045 2.63075 12.3936 3.04572C12.3828 3.4607 12.487 3.87057 12.6946 4.23001C12.9023 4.58944 13.2054 4.88442 13.5703 5.08231C13.9352 5.2802 14.3477 5.37328 14.7622 5.35125C14.9167 5.95125 14.9992 6.57975 14.9992 7.2285C14.9992 7.87725 14.9167 8.50575 14.7622 9.1065C14.3478 9.08457 13.9354 9.17768 13.5706 9.37553C13.2059 9.57339 12.9029 9.86827 12.6953 10.2276C12.4876 10.5869 12.3834 10.9966 12.3941 11.4115C12.4048 11.8263 12.5301 12.2301 12.756 12.5782C11.8493 13.4703 10.7304 14.1169 9.50475 14.457C9.31651 14.0869 9.02952 13.7761 8.67556 13.559C8.3216 13.3419 7.91447 13.227 7.49925 13.227C7.08402 13.227 6.6769 13.3419 6.32294 13.559C5.96898 13.7761 5.68199 14.0869 5.49375 14.457C4.2681 14.1169 3.14921 13.4703 2.2425 12.5782C2.46863 12.2301 2.59405 11.8262 2.60488 11.4113C2.61571 10.9963 2.51152 10.5864 2.30386 10.227C2.09619 9.86755 1.79314 9.57257 1.42823 9.37469C1.06332 9.1768 0.650778 9.08372 0.23625 9.10575C0.0825 8.5065 0 7.878 0 7.2285ZM3.603 9.4785C4.0755 10.2967 4.2105 11.238 4.026 12.1215C4.332 12.339 4.6575 12.5272 4.99875 12.684C5.68625 12.0681 6.57698 11.7279 7.5 11.7285C8.445 11.7285 9.3285 12.0817 10.0012 12.684C10.3425 12.5272 10.668 12.339 10.974 12.1215C10.7846 11.2185 10.9352 10.2773 11.397 9.4785C11.858 8.67928 12.5978 8.07837 13.4745 7.791C13.5092 7.4168 13.5092 7.0402 13.4745 6.666C12.5975 6.37879 11.8574 5.77786 11.3962 4.9785C10.9345 4.1797 10.7838 3.23853 10.9732 2.3355C10.6673 2.11794 10.3417 1.92961 10.0005 1.773C9.31319 2.3887 8.42276 2.72896 7.5 2.7285C6.57698 2.72914 5.68625 2.38887 4.99875 1.773C4.6576 1.92962 4.33192 2.11795 4.026 2.3355C4.21542 3.23853 4.06479 4.1797 3.603 4.9785C3.14203 5.77772 2.40224 6.37863 1.5255 6.666C1.49081 7.0402 1.49081 7.4168 1.5255 7.791C2.40252 8.07821 3.1426 8.67914 3.60375 9.4785H3.603ZM7.5 9.4785C6.90326 9.4785 6.33097 9.24145 5.90901 8.81949C5.48705 8.39753 5.25 7.82524 5.25 7.2285C5.25 6.63176 5.48705 6.05947 5.90901 5.63751C6.33097 5.21555 6.90326 4.9785 7.5 4.9785C8.09674 4.9785 8.66903 5.21555 9.09099 5.63751C9.51295 6.05947 9.75 6.63176 9.75 7.2285C9.75 7.82524 9.51295 8.39753 9.09099 8.81949C8.66903 9.24145 8.09674 9.4785 7.5 9.4785ZM7.5 7.9785C7.69891 7.9785 7.88968 7.89948 8.03033 7.75883C8.17098 7.61818 8.25 7.42741 8.25 7.2285C8.25 7.02959 8.17098 6.83882 8.03033 6.69817C7.88968 6.55752 7.69891 6.4785 7.5 6.4785C7.30109 6.4785 7.11032 6.55752 6.96967 6.69817C6.82902 6.83882 6.75 7.02959 6.75 7.2285C6.75 7.42741 6.82902 7.61818 6.96967 7.75883C7.11032 7.89948 7.30109 7.9785 7.5 7.9785Z" fill="#525866"/>
                            </svg>
                        </a>
                        <?php if (!\FluentPlayer\App\Helpers\Helper::hasPro()): ?>
                            <a class="fplayer-pro-badge" href="https://fluentplayer.com/" target="_blank">
                            <span><?php echo esc_html__('Upgrade to Pro', 'fluent-player'); ?></span>
                        </a>
                    <?php else: ?>
                        <a class="fplayer-docs-link" href="https://fluentplayer.com/docs/" target="_blank" title="<?php echo esc_attr__('Documentation', 'fluent-player'); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path>
                                <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>
                            </svg>
                            <span><?php echo esc_html__('Docs', 'fluent-player'); ?></span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="fplayer-body">
            <div id="fplayer_admin_app" class="fplayer-route-wrapper"></div>
        </div>
    </div>
</div>
