<?php

namespace FluentPlayer\App\Integrations\FluentCart;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Helpers\Helper;
use FluentPlayer\Framework\Support\Arr;

/**
 * FluentCart commerce layer — product lookup for the block-editor picker and
 * server-side rendering of the overlay card shown over the player.
 *
 * The card is composed here rather than delegated to FluentCart's shop card
 * ([fluent_cart_product_card]): that card is built for a catalog grid, brings
 * its own context-dependent button, and is too tall for a 16:9 overlay. The
 * two action buttons ARE FluentCart's own shortcodes, so stock state, catalog
 * mode, checkout URLs and the delegated click handlers stay FluentCart's.
 */
class FluentCartService
{
    const FREE_CONTENT_TYPES = ['product', 'subscription'];

    const PRO_CONTENT_TYPES = ['products_grid', 'subscriptions_grid', 'coupon'];

    const GRID_CONTENT_TYPES = ['products_grid', 'subscriptions_grid'];

    const SUBSCRIPTION_CONTENT_TYPES = ['subscription', 'subscriptions_grid'];

    /**
     * Grid shapes, as ROWS x COLUMNS — the way the label reads aloud, so "1 x 2"
     * is one row of two side by side. Page size falls out of the shape rather
     * than being a separate setting that could disagree with it.
     */
    const GRID_LAYOUTS = [
        '1x2' => ['columns' => 2, 'per_page' => 2],
        '2x2' => ['columns' => 2, 'per_page' => 4],
        '2x3' => ['columns' => 3, 'per_page' => 6],
    ];

    const DEFAULT_GRID_LAYOUT = '2x2';

    /**
     * Most products a grid layer may carry.
     *
     * Layers are server-rendered up front, so the whole selection ships with the
     * page; the cap is what keeps that payload sane. Off-page images are lazy, so
     * the network cost of the pages behind the first is near zero.
     */
    const MAX_GRID_PRODUCTS = 12;

    /** Most coupons the picker will load at once, mirroring the products cap. */
    const MAX_COUPONS = 100;

    const MIN_CARD_HEIGHT = 20;

    const MAX_BUTTON_BORDER = 10;

    const MAX_BUTTON_PADDING = 20;

    const MIN_BUTTON_RADIUS = 1;
    const MAX_BUTTON_RADIUS = 20;

    const MAX_CONTAINER_PADDING = 40;

    const MIN_CONTAINER_RADIUS = 1;
    const MAX_CONTAINER_RADIUS = 20;

    /**
     * Untouched buttons should look like FluentCart's own, not like something
     * this plugin invented. Mirrors its direct-checkout button
     * (resources/public/buttons/direct-checkout/style/style.scss): #253241 fill,
     * white text, border-less, py-3 px-5.
     */
    const BUY_NOW_DEFAULTS = [
        'bg_color'      => '#253241',
        'text_color'    => '#ffffff',
        'border_color'  => '',
        'border_width'  => 0,
        'padding'       => 12,
        'border_radius' => 6,
    ];

    const COPY_BUTTON_DEFAULTS = [
        'bg_color'      => '#111827',
        'text_color'    => '#ffffff',
        'border_color'  => '',
        'border_width'  => 0,
        'padding'       => 12,
        'border_radius' => 6,
    ];

    /**
     * Mirrors FluentCart's add-to-cart button
     * (resources/public/buttons/add-to-cart/style/style.scss): white fill,
     * #2f3448 text, 1px #d6dae1 border.
     */
    const ADD_TO_CART_DEFAULTS = [
        'bg_color'      => '#ffffff',
        'text_color'    => '#2f3448',
        'border_color'  => '#d6dae1',
        'border_width'  => 1,
        'padding'       => 12,
        'border_radius' => 6,
    ];

    public static function isActive()
    {
        return defined('FLUENTCART_VERSION');
    }

    /**
     * Whether a layer sub-type may render on this install.
     *
     * Enforced server-side as well as in the editor so hand-edited block markup
     * cannot unlock a Pro sub-type.
     *
     * @param string $fcContent
     * @return bool
     */
    public static function isContentTypeAllowed($fcContent)
    {
        if (in_array($fcContent, self::FREE_CONTENT_TYPES, true)) {
            return true;
        }

        if (in_array($fcContent, self::PRO_CONTENT_TYPES, true)) {
            return Helper::hasPro();
        }

        return false;
    }

    /**
     * Published FluentCart products for the editor picker, paginated so the
     * picker never pulls a whole catalog.
     *
     * @param string $search
     * @param int    $page
     * @param int    $perPage
     * @return array
     * @throws \Exception
     */
    public static function getProducts($search = '', $page = 1, $perPage = 20, $onlySubscriptions = false)
    {
        if (!self::isActive()) {
            throw new \Exception('FluentCart is not active');
        }

        $search  = sanitize_text_field($search);
        $page    = max(1, intval($page));
        $perPage = min(100, max(1, intval($perPage)));

        $query = \FluentCart\App\Models\Product::query()
            ->with([
                'detail',
                'variants' => function ($variantQuery) {
                    $variantQuery->orderBy('serial_index', 'ASC');
                },
            ])
            ->where('post_type', 'fluent-products')
            ->where('post_status', 'publish');

        // FluentCart models a subscription as a variation payment_type, not a
        // product kind, so "subscriptions" means products that sell at least one
        // subscription variation.
        if ($onlySubscriptions) {
            $query->whereHas('variants', function ($variantQuery) {
                $variantQuery->where('payment_type', 'subscription');
            });
        }

        if ($search !== '') {
            global $wpdb;
            $query->where('post_title', 'LIKE', '%' . $wpdb->esc_like($search) . '%');
        }

        $total    = $query->count();
        $products = $query->orderBy('post_title', 'ASC')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        $formatted = [];
        foreach ($products as $product) {
            $variations = self::formatVariations($product);

            if ($onlySubscriptions) {
                // Offering a one-off variation under a "subscription" layer
                // would quietly sell the wrong thing.
                $variations = array_values(array_filter($variations, function ($variation) {
                    return !empty($variation['is_subscription']);
                }));
            }

            $formatted[] = [
                'id'         => intval($product->ID),
                'title'      => $product->post_title,
                'thumbnail'  => $product->thumbnail ? esc_url_raw($product->thumbnail) : '',
                'price'      => self::getFormattedPrice($product),
                'variations' => $variations,
            ];
        }

        return [
            'products' => $formatted,
            'total'    => intval($total),
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Render a FluentCart layer's content by sub-type.
     *
     * Returns an empty string whenever the layer cannot render, so the caller
     * omits its template wrapper entirely rather than emitting an empty shell.
     *
     * @param array $layer
     * @return string
     */
    public static function renderLayer($layer)
    {
        if (!self::isActive()) {
            return '';
        }

        $fcContent = Arr::get($layer, 'fc_content', 'product');
        if (!self::isContentTypeAllowed($fcContent)) {
            return '';
        }

        if (in_array($fcContent, ['product', 'subscription'], true)) {
            return self::renderProductCard($layer);
        }

        if (in_array($fcContent, self::GRID_CONTENT_TYPES, true)) {
            return self::renderProductsGrid($layer);
        }

        if ($fcContent === 'coupon') {
            return self::renderCoupon($layer);
        }

        return '';
    }

    /**
     * Coupons an author can advertise: active and inside their date window.
     *
     * @return array
     * @throws \Exception
     */
    /**
     * Refuses a coupon binding the current user is not allowed to make.
     *
     * The render path cannot enforce this — it serves frontend viewers, who hold
     * no FluentCart permission by design — so the check has to happen when the
     * layer is SAVED. Only the binding is dropped, not the layer, and only when
     * it is new or changed: an author without the coupon permission can still
     * move, restyle and retime a coupon layer an admin set up.
     *
     * @param array $settings Incoming (already sanitized) media settings.
     * @param array $existing Currently stored media settings.
     * @return array
     */
    public static function filterUnauthorizedCouponBindings($settings, $existing = [])
    {
        if (empty($settings['layers']) || !is_array($settings['layers'])) {
            return $settings;
        }

        if (self::canListCoupons()) {
            return $settings;
        }

        // A LIST, not a map keyed by layer id: the allowance belongs to each
        // stored binding and is spent when it is claimed. Keyed by id it was
        // merely consulted, so N incoming layers all claiming one existing id
        // each read as "unchanged" — one authorised binding multiplied into many.
        $storedCoupons = [];
        foreach ((array) Arr::get($existing, 'layers', []) as $layer) {
            if (!is_array($layer) || Arr::get($layer, 'type') !== 'fluentcart') {
                continue;
            }
            if (Arr::get($layer, 'fc_content') !== 'coupon') {
                continue;
            }

            $storedCouponId = intval(Arr::get($layer, 'coupon_id', 0));
            if ($storedCouponId) {
                $storedCoupons[] = [
                    'id'        => (string) Arr::get($layer, 'id'),
                    'coupon_id' => $storedCouponId,
                ];
            }
        }

        foreach ($settings['layers'] as $index => $layer) {
            if (!is_array($layer) || Arr::get($layer, 'type') !== 'fluentcart') {
                continue;
            }
            if (Arr::get($layer, 'fc_content') !== 'coupon') {
                continue;
            }

            $couponId = intval(Arr::get($layer, 'coupon_id', 0));
            if (!$couponId) {
                continue;
            }

            $layerId = (string) Arr::get($layer, 'id');
            $matched = null;
            foreach ($storedCoupons as $key => $stored) {
                if ($stored['id'] === $layerId && $stored['coupon_id'] === $couponId) {
                    $matched = $key;
                    break;
                }
            }

            if ($matched === null) {
                $settings['layers'][$index]['coupon_id'] = 0;
                continue;
            }

            unset($storedCoupons[$matched]);
        }

        return $settings;
    }

    /**
     * Whether the current user may PREVIEW a content type in the editor.
     *
     * Separate from `renderLayer()`, which serves the frontend too: a viewer holds
     * no FluentCart permission by design. Only the coupon card is sensitive —
     * previewing one renders the code — so only that type needs the extra gate.
     * Products are published catalog data.
     *
     * @param string $fcContent
     * @return bool
     */
    public static function canPreviewContentType($fcContent)
    {
        return $fcContent === 'coupon' ? self::canListCoupons() : true;
    }

    /**
     * Whether rendered button markup actually gives the viewer a way to buy.
     *
     * `trim($html) === ''` is not the same question. FluentCart returns admin-only
     * notice strings ("Invalid product/variant…", "Add to Cart is not supported
     * for subscription product") instead of an empty string, each gated on
     * `Helper::isAdminUser()` — so an emptiness check passes for the author
     * previewing the card and fails for every visitor. Asking for a real button
     * is viewer-independent, and also closes the case where a hidden button
     * counts as markup.
     *
     * @param string $html
     * @return bool
     */
    private static function hasUsableAction($html)
    {
        $html = (string) $html;

        if (trim($html) === '' || strpos($html, 'fct-admin-notice') !== false) {
            return false;
        }

        return strpos($html, 'data-fluent-cart-direct-checkout-button') !== false
            || strpos($html, 'data-fluent-cart-add-to-cart-button') !== false;
    }

    /**
     * How many cards a rendered grid actually produced.
     *
     * A grid silently drops products it cannot resolve, so the count of cards is
     * the only honest measure of what the author will get. Counting the output
     * beats re-running the resolution: it cannot disagree with what shipped.
     *
     * @param string $html
     * @return int
     */
    public static function countRenderedCards($html)
    {
        return preg_match_all('/\sdata-fc-card[\s>]/', (string) $html);
    }

    /**
     * Whether the current user may see coupon codes.
     *
     * The authoring capability is `edit_others_posts`, which an Editor holds —
     * and FluentCart deliberately grants an Editor no commerce access at all.
     * Coupon codes are commercially sensitive, so this needs FluentCart's own
     * permission on top, and there is no reason to hand them over on an install
     * where the coupon layer cannot render in the first place.
     *
     * @return bool
     */
    public static function canListCoupons()
    {
        if (!self::isActive() || !self::isContentTypeAllowed('coupon')) {
            return false;
        }

        if (class_exists('\FluentCart\App\Services\Permission\PermissionManager')) {
            return (bool) \FluentCart\App\Services\Permission\PermissionManager::hasPermission('coupons/view');
        }

        return current_user_can('manage_options');
    }

    public static function getCoupons($search = '', $page = 1, $perPage = self::MAX_COUPONS)
    {
        if (!self::canListCoupons()) {
            throw new \Exception(__('You are not allowed to list coupons', 'fluent-player'));
        }

        if (!self::isActive()) {
            throw new \Exception('FluentCart is not active');
        }

        $search  = sanitize_text_field($search);
        $page    = max(1, intval($page));
        $perPage = min(self::MAX_COUPONS, max(1, intval($perPage)));

        $query = \FluentCart\App\Models\Coupon::query()->where('status', 'active');

        // The window is narrowed in SQL where it can be. `end_date` is nullable
        // and FluentCart also writes the MySQL zero-date to mean "no bound", so
        // both have to be allowed through alongside a future date.
        $now = gmdate('Y-m-d H:i:s', current_time('timestamp', true));
        $query->where(function ($q) use ($now) {
            $q->whereNull('end_date')
                ->orWhere('end_date', '=', '0000-00-00 00:00:00')
                ->orWhere('end_date', '>', $now);
        });

        if ($search !== '') {
            global $wpdb;
            $like = '%' . $wpdb->esc_like($search) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('title', 'LIKE', $like)->orWhere('code', 'LIKE', $like);
            });
        }

        $total   = $query->count();
        $coupons = $query->orderBy('title', 'ASC')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        $formatted = [];
        foreach ($coupons as $coupon) {
            // Still re-checked in PHP: the SQL window cannot express `start_date`
            // against the site's clock the way isCouponRedeemable() does.
            if (!self::isCouponRedeemable($coupon)) {
                continue;
            }

            $formatted[] = [
                'id'       => intval($coupon->id),
                'title'    => $coupon->title,
                'code'     => $coupon->code,
                'type'     => $coupon->type,
                'amount'   => $coupon->amount,
                'discount' => self::formatCouponDiscount($coupon),
            ];
        }

        return ['coupons' => $formatted, 'total' => intval($total)];
    }

    /**
     * Whether a coupon is active and inside its start/end window right now.
     *
     * Advertising an expired code over a video is worse than showing nothing —
     * the viewer bounces at checkout — so the window is enforced at render time,
     * not just when the author picks the coupon.
     *
     * @param object $coupon
     * @return bool
     */
    /** The MySQL zero-date, which FluentCart uses to mean "no bound". */
    private static function isZeroDate($value)
    {
        return strpos((string) $value, '0000-00-00') === 0;
    }

    public static function isCouponRedeemable($coupon)
    {
        if (empty($coupon) || $coupon->status !== 'active') {
            return false;
        }

        $now = current_time('timestamp', true);

        if (!empty($coupon->start_date) && !self::isZeroDate($coupon->start_date)) {
            $start = strtotime($coupon->start_date . ' UTC');
            if ($start && $now < $start) {
                return false;
            }
        }

        // FluentCart's own Coupon::scopeActive() whitelists the MySQL zero-date as
        // "no end date", so it is a value that occurs in the wild. Left to
        // strtotime it parses to a real (very negative) timestamp and every such
        // coupon reads as permanently expired.
        if (!empty($coupon->end_date) && !self::isZeroDate($coupon->end_date)) {
            $end = strtotime($coupon->end_date . ' UTC');
            if ($end && $now > $end) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalise one button's style group to safe, in-range values.
     *
     * @param mixed  $style
     * @param string $legacyBg    Pre-per-button shared background, used as a fallback.
     * @param string $legacyColor Pre-per-button shared text colour.
     * @return array
     */
    public static function normalizeButtonStyle($style, $legacyBg = '', $legacyColor = '', $defaults = [])
    {
        $style    = is_array($style) ? $style : [];
        $defaults = $defaults ? $defaults : self::BUY_NOW_DEFAULTS;

        $bg    = trim((string) Arr::get($style, 'bg_color', ''));
        $color = trim((string) Arr::get($style, 'text_color', ''));

        // Layers saved before these controls existed carry one shared pair;
        // honouring it keeps live videos looking the way the author left them.
        if ($bg === '') {
            $bg = (string) $legacyBg;
        }
        if ($color === '') {
            $color = (string) $legacyColor;
        }
        if ($bg === '') {
            $bg = $defaults['bg_color'];
        }
        if ($color === '') {
            $color = $defaults['text_color'];
        }

        $borderColor = trim((string) Arr::get($style, 'border_color', ''));
        if ($borderColor === '') {
            $borderColor = $defaults['border_color'];
        }

        return [
            'bg_color'      => self::safeColorOrEmpty($bg),
            'text_color'    => self::safeColorOrEmpty($color),
            'border_color'  => self::safeColorOrEmpty($borderColor),
            'border_width'  => max(0, min(
                self::MAX_BUTTON_BORDER,
                intval(Arr::get($style, 'border_width', $defaults['border_width']))
            )),
            'padding'       => max(0, min(
                self::MAX_BUTTON_PADDING,
                intval(Arr::get($style, 'padding', $defaults['padding']))
            )),
            'border_radius' => max(self::MIN_BUTTON_RADIUS, min(
                self::MAX_BUTTON_RADIUS,
                intval(Arr::get($style, 'border_radius', $defaults['border_radius']))
            )),
        ];
    }

    /**
     * A colour the stylesheet may print, or an empty string.
     *
     * @param mixed $value
     * @return string
     */
    private static function safeColorOrEmpty($value)
    {
        $value = trim((string) $value);
        if ($value === '' || $value === 'transparent') {
            return '';
        }

        $safe = Helper::safeCssColor($value);

        return $safe ? $safe : '';
    }

    /**
     * CSS custom properties for one button, scoped by prefix.
     *
     * FluentCart owns the button markup and its shortcodes accept no styling
     * attributes, so the author's choices travel as custom properties that the
     * stylesheet applies to whatever markup FluentCart produced.
     *
     * @param string $prefix
     * @param array  $style
     * @return string
     */
    private static function buttonStyleVars($prefix, $style)
    {
        $css = '';

        if ($style['bg_color'] !== '') {
            $css .= ' --fp-fc-' . $prefix . '-bg: ' . $style['bg_color'] . ';';
        }
        if ($style['text_color'] !== '') {
            $css .= ' --fp-fc-' . $prefix . '-color: ' . $style['text_color'] . ';';
        }
        if ($style['border_color'] !== '') {
            $css .= ' --fp-fc-' . $prefix . '-border-color: ' . $style['border_color'] . ';';
        }
        // Emitted unconditionally: 0 is meaningful (border-less), and leaving the
        // property unset would fall back to the stylesheet's 1px instead.
        $css .= ' --fp-fc-' . $prefix . '-border-width: ' . $style['border_width'] . 'px;';
        $css .= ' --fp-fc-' . $prefix . '-padding: ' . $style['padding'] . 'px;';
        $css .= ' --fp-fc-' . $prefix . '-radius: ' . $style['border_radius'] . 'px;';

        return $css;
    }

    /**
     * Whether a layer has enough chosen for the preview to render anything.
     *
     * Each content type keeps its selection in a different key, so this has to
     * cover all of them — a type missing here reports "nothing selected" no
     * matter what the author picked.
     *
     * @param array $layer
     * @return bool
     */
    public static function hasPreviewSelection($layer)
    {
        $fcContent = Arr::get($layer, 'fc_content', 'product');

        if (in_array($fcContent, self::GRID_CONTENT_TYPES, true)) {
            return (bool) self::capGridProductIds(Arr::get($layer, 'product_ids', []));
        }

        if ($fcContent === 'coupon') {
            return (bool) intval(Arr::get($layer, 'coupon_id', 0));
        }

        return (bool) intval(Arr::get($layer, 'product_id', 0));
    }

    /**
     * Render the coupon offer card.
     *
     * Hand-built: FluentCart registers `fluent_cart_show_coupon` but its handler
     * has no body, so there is no upstream renderer to delegate to.
     *
     * @param array $layer
     * @return string
     */
    public static function renderCoupon($layer)
    {
        $couponId = intval(Arr::get($layer, 'coupon_id', 0));
        if (!$couponId) {
            return '';
        }

        return self::renderCouponCard(
            \FluentCart\App\Models\Coupon::query()->find($couponId),
            $layer
        );
    }

    /**
     * Builds the coupon card from an already-resolved coupon.
     *
     * Split from the lookup so the markup can be exercised without FluentCart's
     * models being loadable — the card is what the viewer sees, and it is the
     * part that keeps changing.
     *
     * @param object|null $coupon
     * @param array       $layer
     * @return string
     */
    public static function renderCouponCard($coupon, $layer)
    {
        if (!self::isCouponRedeemable($coupon)) {
            return '';
        }

        $couponId = intval(Arr::get($layer, 'coupon_id', 0));

        // Copying is this card's only completion route, and the runtime bails on
        // an empty code before it can report anything — a blocking layer with one
        // inert button. Drop the card instead.
        if (trim((string) $coupon->code) === '') {
            return '';
        }

        $code      = $coupon->code;
        $discount  = self::formatCouponDiscount($coupon);
        $copyLabel = trim((string) Arr::get($layer, 'copy_button_text', ''));
        if ($copyLabel === '') {
            $copyLabel = __('Copy Code', 'fluent-player');
        }

        $headline = trim((string) Arr::get($layer, 'coupon_headline', ''));
        if ($headline === '') {
            // The title is the last resort: FluentCart has a coupon type this card
            // cannot summarise, and a card that does not say what the offer is has
            // nothing to advertise.
            $headline = $discount !== '' ? $discount : trim((string) $coupon->title);
        }

        ob_start();
        ?>
        <div class="fluent-player-layer-fluentcart-content position-<?php echo esc_attr(self::layerPosition($layer)); ?>"
             data-fc-content="coupon"
             data-fc-coupon-id="<?php echo esc_attr($couponId); ?>">

            <div class="fluent-player-layer-fluentcart-box" style="<?php echo esc_attr(self::boxStyleAttribute($layer)); ?>">

            <?php if (!empty($layer['title'])): ?>
                <h3 class="fluent-player-layer-fluentcart-title"><?php echo esc_html($layer['title']); ?></h3>
            <?php endif; ?>

            <div class="fluent-player-layer-fluentcart-coupon">
                <?php if ($headline !== ''): ?>
                    <div class="fluent-player-layer-fluentcart-coupon-headline"><?php
                        // wp_kses_post rather than fluentplayer_sanitize_html: this
                        // line needs inline formatting only, and the shared helper
                        // deliberately keeps `onclick` for the rich-content layers.
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized by wp_kses_post()
                        echo wp_kses_post($headline);
                    ?></div>
                <?php endif; ?>

                <div class="fluent-player-layer-fluentcart-coupon-row">
                    <button type="button"
                            class="fluent-player-layer-fluentcart-coupon-copy"
                            data-fc-coupon-copy
                            data-fc-coupon-value="<?php echo esc_attr($code); ?>">
                        <?php echo esc_html($copyLabel); ?>
                    </button>
                </div>
            </div>

            <p class="fluent-player-layer-fluentcart-confirmation" role="status" aria-live="polite" hidden></p>
            </div>

            <?php if (Arr::get($layer, 'allow_skip', true)): ?>
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output sanitized by fluentplayer_sanitize_html()
                echo fluentplayer_sanitize_html(self::renderSkipButton());
                ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Human-readable discount for a coupon.
     *
     * @param object $coupon
     * @return string
     */
    /**
     * Formats a FluentCart money column for display.
     *
     * FluentCart stores every non-percentage amount in CENTS
     * (`CouponResource::formatAmount()` -> `Helper::toCent()`), so reading the
     * column as a decimal advertises a $10 coupon as "1000 off". Its own
     * `Helper::toDecimal()` does the conversion and adds the store's currency
     * sign; the plain fallback keeps this callable when FluentCart is absent.
     *
     * @param mixed $cents
     * @return string
     */
    /**
     * Author text on its way into a shortcode attribute.
     *
     * `esc_attr()` does not escape square brackets, and WordPress's shortcode
     * parser stops at the first `]` — an author label of "Buy ] now" truncates the
     * shortcode and leaks the remainder into the card as literal text.
     *
     * @param string $text
     * @return string
     */
    private static function shortcodeSafeText($text)
    {
        return trim(str_replace(['[', ']'], '', (string) $text));
    }

    private static function formatMoney($cents)
    {
        if (class_exists('\FluentCart\App\Helpers\Helper')) {
            return (string) \FluentCart\App\Helpers\Helper::toDecimal($cents);
        }

        return rtrim(rtrim(number_format(((float) $cents) / 100, 2, '.', ''), '0'), '.');
    }

    private static function formatCouponDiscount($coupon)
    {
        $amount = $coupon->amount;

        if ($coupon->type === 'percentage') {
            return sprintf(
                /* translators: %s: discount percentage */
                __('%s%% off', 'fluent-player'),
                rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.')
            );
        }

        if ($coupon->type === 'free_shipping') {
            return __('Free shipping', 'fluent-player');
        }

        if ($coupon->type === 'fixed') {
            return sprintf(
                /* translators: %s: discount amount */
                __('%s off', 'fluent-player'),
                self::formatMoney($amount)
            );
        }

        // `buy_x_get_y` lands here — the caller falls back to the coupon title.
        return '';
    }

    /**
     * Columns and page size for a named grid shape.
     *
     * @param string $layout
     * @return array{columns: int, per_page: int}
     */
    public static function gridLayout($layout)
    {
        $layout = (string) $layout;

        return isset(self::GRID_LAYOUTS[$layout])
            ? self::GRID_LAYOUTS[$layout]
            : self::GRID_LAYOUTS[self::DEFAULT_GRID_LAYOUT];
    }

    /**
     * The shape a layer should render in.
     *
     * Layers saved before `grid_layout` existed carry only `per_page`; that is
     * mapped onto the matching shape so live grids keep their page size.
     *
     * @param array $layer
     * @return array{columns: int, per_page: int}
     */
    public static function gridLayoutForLayer($layer)
    {
        $layout = (string) Arr::get($layer, 'grid_layout', '');
        if (isset(self::GRID_LAYOUTS[$layout])) {
            return self::GRID_LAYOUTS[$layout];
        }

        $perPage = intval(Arr::get($layer, 'per_page', 0));
        if ($perPage > 0) {
            foreach (self::GRID_LAYOUTS as $shape) {
                if ($shape['per_page'] === $perPage) {
                    return $shape;
                }
            }
        }

        return self::GRID_LAYOUTS[self::DEFAULT_GRID_LAYOUT];
    }

    /**
     * Normalise and cap a grid's product selection.
     *
     * Enforced in the service, not just the editor, so hand-edited block markup
     * cannot ship a hundred cards into the page.
     *
     * @param mixed $productIds
     * @return array
     */
    public static function capGridProductIds($productIds)
    {
        if (!is_array($productIds)) {
            return [];
        }

        $clean = [];
        foreach ($productIds as $productId) {
            $productId = intval($productId);
            if ($productId > 0 && !in_array($productId, $clean, true)) {
                $clean[] = $productId;
            }
        }

        return array_slice($clean, 0, self::MAX_GRID_PRODUCTS);
    }

    /**
     * Render the single-product overlay card.
     *
     * @param array $layer
     * @return string
     */
    private static function renderProductCard($layer)
    {
        $productId = intval(Arr::get($layer, 'product_id', 0));
        if (!$productId) {
            return '';
        }

        $product = \FluentCart\App\Models\Product::query()
            ->with(['detail', 'variants'])
            ->where('post_type', 'fluent-products')
            ->where('post_status', 'publish')
            ->where('ID', $productId)
            ->first();

        if (!$product) {
            return '';
        }

        $variationId = self::resolveVariationId($product, Arr::get($layer, 'variation_id', 0), $layer);
        if (!$variationId) {
            return '';
        }

        $buttons = self::renderActionButtons($variationId, $layer);

        // Catalog mode, and a simple-variation subscription-only product, make
        // FluentCart's button renderers return nothing at all. A card with no way
        // to buy is not worth covering the video with. (Out of stock is NOT one
        // of these: FluentCart still renders a disabled control, which the runtime
        // handles by refusing to treat a click on it as a purchase.)
        if (!self::hasUsableAction($buttons)) {
            return '';
        }


        ob_start();
        ?>
        <div class="fluent-player-layer-fluentcart-content position-<?php echo esc_attr(self::layerPosition($layer)); ?>"
             data-fc-content="product"
             data-fc-product-id="<?php echo esc_attr($productId); ?>"
             data-fc-variation-id="<?php echo esc_attr($variationId); ?>">

            <div class="fluent-player-layer-fluentcart-box" style="<?php echo esc_attr(self::boxStyleAttribute($layer)); ?>">

            <?php if (!empty($layer['title'])): ?>
                <h3 class="fluent-player-layer-fluentcart-title"><?php echo esc_html($layer['title']); ?></h3>
            <?php endif; ?>

            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in renderCard()
            echo self::renderCard($product, $buttons, false, $variationId);
            ?>

            <p class="fluent-player-layer-fluentcart-confirmation" role="status" aria-live="polite" hidden></p>

            </div>

            <?php if (Arr::get($layer, 'allow_skip', true)): ?>
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output sanitized by fluentplayer_sanitize_html()
                echo fluentplayer_sanitize_html(self::renderSkipButton());
                ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the multi-product grid.
     *
     * Every selected card is rendered up front — layers ship with the page and
     * there is no runtime endpoint for layer content — and paged client-side.
     * Images behind the first page are lazy, so unseen pages cost ~0 network.
     *
     * @param array $layer
     * @return string
     */
    public static function renderProductsGrid($layer)
    {
        $productIds = self::capGridProductIds(Arr::get($layer, 'product_ids', []));
        if (!$productIds) {
            return '';
        }

        $shape   = self::gridLayoutForLayer($layer);
        $perPage = $shape['per_page'];

        $products = \FluentCart\App\Models\Product::query()
            ->with(['detail', 'variants'])
            ->where('post_type', 'fluent-products')
            ->where('post_status', 'publish')
            ->whereIn('ID', $productIds)
            ->get();

        $byId = [];
        foreach ($products as $product) {
            $byId[intval($product->ID)] = $product;
        }

        // Author order is the display order; the query does not preserve it.
        $items = [];
        foreach ($productIds as $productId) {
            if (!isset($byId[$productId])) {
                continue;
            }

            $product     = $byId[$productId];
            $variationId = self::resolveVariationId($product, 0, $layer);
            if (!$variationId) {
                continue;
            }

            $buttons = self::renderActionButtons($variationId, $layer);
            if (!self::hasUsableAction($buttons)) {
                continue;
            }

            $items[] = ['product' => $product, 'buttons' => $buttons, 'variation_id' => $variationId];
        }

        if (!$items) {
            return '';
        }

        $pages      = array_chunk($items, $perPage);
        $totalPages = count($pages);

        ob_start();
        ?>
        <div class="fluent-player-layer-fluentcart-content position-<?php echo esc_attr(self::layerPosition($layer)); ?>"
             data-fc-content="<?php echo esc_attr(Arr::get($layer, 'fc_content', 'products_grid')); ?>"
             data-fc-per-page="<?php echo esc_attr($perPage); ?>"
             style="--fp-fc-grid-cols: <?php echo esc_attr($shape['columns']); ?>;"
             data-fc-total-pages="<?php echo esc_attr($totalPages); ?>">

            <div class="fluent-player-layer-fluentcart-box" style="<?php echo esc_attr(self::boxStyleAttribute($layer, 70)); ?>">

            <?php if (!empty($layer['title'])): ?>
                <h3 class="fluent-player-layer-fluentcart-title"><?php echo esc_html($layer['title']); ?></h3>
            <?php endif; ?>

            <div class="fluent-player-layer-fluentcart-grid">
                <?php foreach ($pages as $pageIndex => $pageItems): ?>
                    <div class="fluent-player-layer-fluentcart-grid-page"
                         data-fc-page-index="<?php echo esc_attr($pageIndex); ?>"
                         <?php echo $pageIndex > 0 ? 'hidden' : ''; ?>>
                        <?php foreach ($pageItems as $item): ?>
                            <?php
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in renderCard()
                            echo self::renderCard($item['product'], $item['buttons'], $pageIndex > 0, $item['variation_id']);
                            ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="fluent-player-layer-fluentcart-pager">
                    <button type="button" data-fc-nav="prev" class="fluent-player-layer-fluentcart-pager-btn"
                            aria-label="<?php echo esc_attr__('Previous products', 'fluent-player'); ?>" disabled>
                        <span aria-hidden="true">&larr;</span>
                    </button>
                    <span class="fluent-player-layer-fluentcart-pager-status"
                          data-fc-page-status
                          role="status"
                          aria-live="polite"><?php
                        echo esc_html(sprintf(
                            /* translators: 1: current page number, 2: total page count */
                            __('Page %1$d of %2$d', 'fluent-player'),
                            1,
                            $totalPages
                        ));
                    ?></span>
                    <button type="button" data-fc-nav="next" class="fluent-player-layer-fluentcart-pager-btn"
                            aria-label="<?php echo esc_attr__('Next products', 'fluent-player'); ?>">
                        <span aria-hidden="true">&rarr;</span>
                    </button>
                </div>
            <?php endif; ?>

            <p class="fluent-player-layer-fluentcart-confirmation" role="status" aria-live="polite" hidden></p>
            </div>

            <?php if (Arr::get($layer, 'allow_skip', true)): ?>
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output sanitized by fluentplayer_sanitize_html()
                echo fluentplayer_sanitize_html(self::renderSkipButton());
                ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render one product card: image, title, price and the action buttons.
     *
     * @param object $product
     * @param string $buttons Pre-rendered FluentCart button markup.
     * @param bool   $lazy    Lazy-load the image (cards behind the first page).
     * @param int    $variationId The variation the buttons sell, so the card
     *                            quotes the price the viewer will actually pay.
     * @return string
     */
    private static function renderCard($product, $buttons, $lazy, $variationId = 0)
    {
        $thumbnail = $product->thumbnail;
        $price     = self::getFormattedPrice($product, $variationId);

        ob_start();
        ?>
        <div class="fluent-player-layer-fluentcart-card" data-fc-card
             data-fc-product-id="<?php echo esc_attr(intval($product->ID)); ?>">
            <?php if ($thumbnail): ?>
                <div class="fluent-player-layer-fluentcart-media">
                    <img src="<?php echo esc_url($thumbnail); ?>" alt=""
                         loading="<?php echo $lazy ? 'lazy' : 'eager'; ?>" />
                </div>
            <?php endif; ?>

            <div class="fluent-player-layer-fluentcart-body">
                <p class="fluent-player-layer-fluentcart-name"><?php echo esc_html($product->post_title); ?></p>
                <?php if ($price !== ''): ?>
                    <p class="fluent-player-layer-fluentcart-price"><?php echo esc_html($price); ?></p>
                <?php endif; ?>

                <div class="fluent-player-layer-fluentcart-actions">
                    <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FluentCart escapes its own button markup
                    echo $buttons;
                    ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Layer position class suffix.
     *
     * @param array $layer
     * @return string
     */
    private static function layerPosition($layer)
    {
        return str_replace('position-', '', Arr::get($layer, 'position', 'center'));
    }

    /**
     * Inline styles for the layer wrapper.
     *
     * @param array $layer
     * @param int   $defaultWidth
     * @return string
     */
    public static function boxStyleAttribute($layer, $defaultWidth = 40)
    {
        $bgColor   = Arr::get($layer, 'bg_color', '');
        $textColor = Arr::get($layer, 'text_color', '');
        $width     = intval(Arr::get($layer, 'width', $defaultWidth));
        $height    = intval(Arr::get($layer, 'height', self::MIN_CARD_HEIGHT));

        // Clamped like every other box dimension: an unclamped width produces an
        // overlay that visually swallows the page.
        $width  = max(10, min(100, $width));
        $styles = 'width: ' . $width . '%; max-width: ' . $width . '%;';

        // min-height rather than height: the authored size is a floor, so a card
        // whose content runs taller grows instead of clipping its own buttons.
        $height = max(self::MIN_CARD_HEIGHT, min(100, $height));
        $styles .= ' min-height: ' . $height . '%;';

        $padding = max(0, min(self::MAX_CONTAINER_PADDING, intval(Arr::get($layer, 'container_padding', 12))));
        $radius  = max(self::MIN_CONTAINER_RADIUS, min(self::MAX_CONTAINER_RADIUS, intval(Arr::get($layer, 'container_radius', 6))));
        $styles .= ' padding: ' . $padding . 'px;';
        $styles .= ' border-radius: ' . $radius . 'px;';

        // Emitted as custom properties too: `inherit` would resolve against the
        // pager wrapper (transparent), not the card, so descendants that must
        // match the card's surface read these instead.
        // Through the same gate the buttons use. A rejected colour must fall
        // through to the stylesheet: emitting `background-color: ;` also defines
        // the custom property as empty, which overrides the stylesheet's own
        // fallback with nothing and renders the card transparent.
        $safeBg = self::safeColorOrEmpty($bgColor);
        if ($safeBg !== '') {
            $styles .= ' background-color: ' . $safeBg . ';';
            $styles .= ' --fp-fc-box-bg: ' . $safeBg . ';';
        }
        $safeText = self::safeColorOrEmpty($textColor);
        if ($safeText !== '') {
            $styles .= ' color: ' . $safeText . ';';
            $styles .= ' --fp-fc-box-color: ' . $safeText . ';';
        }

        // Each button is styled independently; the pre-per-button shared pair is
        // passed through as a fallback so older layers keep their look.
        $legacyBg    = Arr::get($layer, 'button_bg_color', '');
        $legacyColor = Arr::get($layer, 'button_text_color', '');

        $styles .= self::buttonStyleVars('buy', self::normalizeButtonStyle(
            Arr::get($layer, 'buy_now_style', []),
            $legacyBg,
            $legacyColor,
            self::BUY_NOW_DEFAULTS
        ));
        $styles .= self::buttonStyleVars('cart', self::normalizeButtonStyle(
            Arr::get($layer, 'add_to_cart_style', []),
            $legacyBg,
            $legacyColor,
            self::ADD_TO_CART_DEFAULTS
        ));
        $styles .= self::buttonStyleVars('copy', self::normalizeButtonStyle(
            Arr::get($layer, 'copy_button_style', []),
            '',
            '',
            self::COPY_BUTTON_DEFAULTS
        ));

        return $styles;
    }

    /**
     * Render an editor preview of the configured layer.
     *
     * @param array $layer
     * @return string
     * @throws \Exception
     */
    public static function getPreview($layer)
    {
        if (!self::isActive()) {
            throw new \Exception('FluentCart is not active');
        }

        if (!self::hasPreviewSelection($layer)) {
            throw new \Exception('Product is required');
        }

        return self::renderLayer($layer);
    }

    /**
     * Render the Buy Now and Add to Cart buttons via FluentCart's own
     * shortcodes, so stock state, catalog mode and checkout URLs stay theirs.
     *
     * Both buttons render by default, and both carry author-editable labels. A
     * subscription sub-type forces Buy Now only, because FluentCart declines to
     * render an add-to-cart control for one. Turning both off falls back to both
     * on, so a card is never left with nothing to click.
     *
     * Buy Now always opens in a new tab: FluentCart's modal checkout navigates
     * the parent page away on success, which would kill the video behind it.
     *
     * @param int $variationId
     * @return string
     */
    private static function renderActionButtons($variationId, $layer = [])
    {
        // Absent means on: a layer saved before these toggles existed must keep
        // showing both buttons.
        $showBuyNow    = Arr::get($layer, 'show_buy_now', true);
        $showAddToCart = Arr::get($layer, 'show_add_to_cart', true);

        // A subscription is subscribed to, not carted — FluentCart declines to
        // render the button anyway, so asking for it only risks a stray element.
        if (in_array(Arr::get($layer, 'fc_content', 'product'), self::SUBSCRIPTION_CONTENT_TYPES, true)) {
            $showAddToCart = false;
            $showBuyNow    = true;
        }

        // Both off would render a card with no way to buy, which the caller then
        // drops entirely — so fall back to showing both rather than vanishing.
        if (!$showBuyNow && !$showAddToCart) {
            $showBuyNow = true;
            $showAddToCart = true;
        }

        $html = '';

        if ($showBuyNow) {
            $buyText = trim((string) Arr::get($layer, 'buy_now_text', ''));
            if ($buyText === '') {
                $buyText = __('Buy Now', 'fluent-player');
            }

            $html .= self::ensureNoopener(do_shortcode(sprintf(
                '[fluent_cart_checkout_button variation_id="%d" button_text="%s" target="_blank" class="%s"]',
                $variationId,
                esc_attr(self::shortcodeSafeText($buyText)),
                'fluent-player-fc-buy-now'
            )));
        }

        if ($showAddToCart) {
            $cartText = trim((string) Arr::get($layer, 'add_to_cart_text', ''));
            if ($cartText === '') {
                $cartText = __('Add to Cart', 'fluent-player');
            }

            $html .= do_shortcode(sprintf(
                '[fluent_cart_add_to_cart_button variation_id="%d" button_text="%s" class="%s"]',
                $variationId,
                esc_attr(self::shortcodeSafeText($cartText)),
                'fluent-player-fc-add-to-cart'
            ));
        }

        return $html;
    }

    /**
     * Guarantee a `target="_blank"` anchor also carries a non-empty `rel`.
     *
     * Upstream: FluentCart's renderBuyNowButtonBlock() seeds `'rel' => ''` through
     * wp_parse_args before reading it back with Arr::get($atts, 'rel', 'noopener
     * noreferrer'), so the key always exists and its own fallback never applies.
     * Its renderAttributes() then prints empty values as BARE attributes, so the
     * anchor ships a valueless `rel` — which must be removed, not appended to, or
     * the duplicate leaves the empty one winning.
     *
     * @param string $html
     * @return string
     */
    private static function ensureNoopener($html)
    {
        return preg_replace_callback('/<a\s([^>]*)>/i', function ($matches) {
            $attrs = $matches[1];

            if (stripos($attrs, 'target="_blank"') === false) {
                return $matches[0];
            }
            if (preg_match('/(^|\s)rel="[^"]+"/i', $attrs)) {
                return $matches[0];
            }

            // Strips both a bare `rel` and an explicit `rel=""`.
            $attrs = trim(preg_replace('/(^|\s)rel(="")?(?=\s|$)/i', ' ', $attrs));

            return '<a ' . $attrs . ' rel="noopener noreferrer">';
        }, $html);
    }

    /**
     * Resolve the variation to sell: the author's pick when it belongs to this
     * product, otherwise the product's first variant.
     *
     * @param object $product
     * @param mixed  $requestedId
     * @return int
     */
    private static function resolveVariationId($product, $requestedId, $layer = [])
    {
        $requestedId = intval($requestedId);
        $variants    = $product->variants;

        if (!$variants || !count($variants)) {
            return 0;
        }

        // The picker already filters the author's choices down to subscription
        // plans under a subscription sub-type. The fallback below has to apply the
        // same filter, or a product carrying both a one-off and a plan is
        // advertised as a subscription and checked out as a one-time purchase.
        $subscriptionOnly = in_array(
            Arr::get($layer, 'fc_content', 'product'),
            self::SUBSCRIPTION_CONTENT_TYPES,
            true
        );

        $eligible = [];
        foreach ($variants as $variant) {
            if ($subscriptionOnly && $variant->payment_type !== 'subscription') {
                continue;
            }
            $eligible[] = $variant;
        }

        if (!$eligible) {
            return 0;
        }

        if ($requestedId) {
            foreach ($eligible as $variant) {
                if (intval($variant->id) === $requestedId) {
                    return $requestedId;
                }
            }
        }

        return intval($eligible[0]->id);
    }

    /**
     * Variation list for the editor picker.
     *
     * @param object $product
     * @return array
     */
    private static function formatVariations($product)
    {
        $variations = [];

        if (empty($product->variants)) {
            return $variations;
        }

        foreach ($product->variants as $variant) {
            $variations[] = [
                'id'    => intval($variant->id),
                'title' => $variant->variation_title,
                // Drives the editor's gate copy: a subscription can only be
                // verified for a logged-in viewer.
                'is_subscription' => $variant->payment_type === 'subscription',
            ];
        }

        return $variations;
    }

    /**
     * FluentCart's own formatted price for a product, when it exposes one.
     *
     * @param object $product
     * @return string
     */
    private static function getFormattedPrice($product, $variationId = 0)
    {
        // The card's buttons are bound to one variation, so the card has to quote
        // that variation's price. `formatted_min_price` is the product-wide
        // minimum: on a Basic/Pro product pinned to Pro it advertises the Basic
        // price and charges the Pro one.
        $variationId = intval($variationId);
        if ($variationId && !empty($product->variants)) {
            foreach ($product->variants as $variant) {
                if (intval($variant->id) === $variationId && isset($variant->item_price)) {
                    return self::formatMoney($variant->item_price);
                }
            }
        }

        if (empty($product->detail)) {
            return '';
        }

        $price = $product->detail->formatted_min_price;

        return is_scalar($price) ? (string) $price : '';
    }

    /**
     * Skip button, matching the other layer types.
     *
     * @return string
     */
    private static function renderSkipButton()
    {
        return '<button type="button" class="fluent-player-skip-button" data-action="skip" aria-label="'
            . esc_attr__('Skip', 'fluent-player') . '">'
            . esc_html__('Skip', 'fluent-player') . ' <span aria-hidden="true">→</span></button>';
    }
}
