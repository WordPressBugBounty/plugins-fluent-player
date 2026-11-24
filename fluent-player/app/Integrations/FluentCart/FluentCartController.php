<?php

namespace FluentPlayer\App\Integrations\FluentCart;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Http\Controllers\Controller;
use FluentPlayer\App\Services\MediaService;
use FluentPlayer\Framework\Http\Request\Request;

class FluentCartController extends Controller
{
    /**
     * Get FluentCart products for the layer picker
     *
     * @param Request $request
     * @return \WP_REST_Response
     */
    public function getFluentCartProducts(Request $request)
    {
        try {
            return $this->sendSuccess(FluentCartService::getProducts(
                $request->get('search', ''),
                $request->get('page', 1),
                $request->get('per_page', 20),
                rest_sanitize_boolean($request->get('subscriptions_only', false))
            ));
        } catch (\Exception $e) {
            self::logFluentCartFailure('products', $e, [
                'search' => $request->get('search', ''),
                'page'   => $request->get('page', 1),
            ]);

            return $this->sendError(['message' => __('Failed to load products', 'fluent-player')], 400);
        }
    }

    /**
     * Get redeemable FluentCart coupons for the layer picker
     *
     * @param Request $request
     * @return \WP_REST_Response
     */
    public function getFluentCartCoupons(Request $request)
    {
        try {
            return $this->sendSuccess(FluentCartService::getCoupons(
                $request->get('search', ''),
                $request->get('page', 1),
                $request->get('per_page', 0) ?: FluentCartService::MAX_COUPONS
            ));
        } catch (\Exception $e) {
            self::logFluentCartFailure('coupons', $e);

            if (!FluentCartService::canListCoupons()) {
                return $this->sendError(
                    ['message' => __('You are not allowed to list coupons', 'fluent-player')],
                    403
                );
            }

            return $this->sendError(['message' => __('Failed to load coupons', 'fluent-player')], 400);
        }
    }

    /**
     * Get FluentCart layer preview HTML
     *
     * @param Request $request
     * @return \WP_REST_Response
     */
    public function getFluentCartPreview(Request $request)
    {
        try {
            $productIds = array_filter(array_map(
                'intval',
                explode(',', (string) $request->get('product_ids', ''))
            ));

            $layer = [
                'fc_content'   => sanitize_text_field($request->get('fc_content', 'product')),
                'product_id'   => intval($request->get('product_id', 0)),
                'variation_id' => intval($request->get('variation_id', 0)),
                'product_ids'  => array_values($productIds),
                'per_page'     => intval($request->get('per_page', 0)),
                'grid_layout'  => sanitize_text_field($request->get('grid_layout', '')),
                'coupon_id'    => intval($request->get('coupon_id', 0)),
                'coupon_headline' => self::previewText($request->get('coupon_headline', ''), 'ksesPost'),
                'title'        => self::previewText($request->get('title', '')),
                'position'     => sanitize_text_field($request->get('position', '')),
                'width'        => intval($request->get('width', 0)),
                'height'       => intval($request->get('height', 0)),
                'bg_color'     => sanitize_text_field($request->get('bg_color', '')),
                'text_color'   => sanitize_text_field($request->get('text_color', '')),
                'allow_skip'   => rest_sanitize_boolean($request->get('allow_skip', false)),

                'copy_button_text'  => sanitize_text_field($request->get('copy_button_text', '')),
                'buy_now_text'      => sanitize_text_field($request->get('buy_now_text', '')),
                'add_to_cart_text'  => sanitize_text_field($request->get('add_to_cart_text', '')),
                'button_bg_color'   => sanitize_text_field($request->get('button_bg_color', '')),
                'button_text_color' => sanitize_text_field($request->get('button_text_color', '')),
                'show_buy_now'      => rest_sanitize_boolean($request->get('show_buy_now', true)),
                'show_add_to_cart'  => rest_sanitize_boolean($request->get('show_add_to_cart', true)),

                // Style groups arrive JSON-encoded; the service normalises and
                // clamps every field before any of it reaches a style attribute.
                'buy_now_style'     => self::decodeStyleGroup($request->get('buy_now_style', '')),
                'add_to_cart_style' => self::decodeStyleGroup($request->get('add_to_cart_style', '')),
                'copy_button_style' => self::decodeStyleGroup($request->get('copy_button_style', '')),
            ];

            // Empty strings would override the renderer's defaults with nothing.
            $layer = array_filter($layer, function ($value) {
                return $value !== '' && $value !== 0;
            });

            // Added after the filter: 0 is a legitimate value for both (no padding,
            // square corners), so they must survive it — but an absent key still
            // has to fall through to the renderer's default rather than becoming 0.
            foreach (['container_padding', 'container_radius'] as $sizing) {
                $raw = $request->get($sizing, '');
                if ($raw !== '' && $raw !== null) {
                    $layer[$sizing] = intval($raw);
                }
            }

            // Checked before rendering: a coupon card carries the code, so an
            // author could otherwise walk `coupon_id` and read every code the
            // picker refused to list.
            if (!FluentCartService::canPreviewContentType(isset($layer['fc_content']) ? $layer['fc_content'] : 'product')) {
                return $this->sendError(
                    ['message' => __('You are not allowed to preview coupons', 'fluent-player')],
                    403
                );
            }

            $html = FluentCartService::getPreview($layer);

            return $this->sendSuccess([
                'html'       => $html,
                'requested'  => count($layer['product_ids'] ?? []),
                'rendered'   => FluentCartService::countRenderedCards($html),
                // Read defensively: array_filter above drops a 0 product id, which is
                // exactly what a coupon or grid preview carries.
                'product_id' => (int) ($layer['product_id'] ?? 0),
            ]);
        } catch (\Exception $e) {
            self::logFluentCartFailure('preview', $e, [
                'fc_content' => $request->get('fc_content', ''),
                'product_id' => $request->get('product_id', 0),
                'coupon_id'  => $request->get('coupon_id', 0),
            ]);

            return $this->sendError(['message' => __('Failed to load product preview', 'fluent-player')], 400);
        }
    }

    /**
     * Records why a FluentCart authoring call failed.
     *
     * These endpoints collapse every cause — plugin inactive, a schema change, an
     * authorization denial — into one generic message, so without this a store
     * owner reporting "the product picker is empty" leaves no server-side trace
     * to work from. Follows the handler convention already used elsewhere in the
     * plugin: debug builds only, message text only.
     *
     * @param string     $surface
     * @param \Exception $e
     * @param array      $context
     * @return void
     */
    private static function logFluentCartFailure($surface, $e, $context = [])
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $parts = [];
        foreach ($context as $key => $value) {
            $parts[] = $key . '=' . (is_scalar($value) ? $value : gettype($value));
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log(sprintf(
            '[fluent-player] FluentCart %s failed (%s): %s',
            $surface,
            implode(' ', $parts),
            $e->getMessage()
        ));
    }

    /**
     * Author-facing text on its way into the preview.
     *
     * The frontend resolves smartcodes during render, via
     * `MediaService::parseSmartcodes()`. This endpoint builds its own layer array
     * from the request, so without this the editor showed `{{user.email}}` while
     * the viewer saw a real address — and the FluentCart preview is the one
     * preview that is server-rendered, so it is the one that can match.
     *
     * @param string $value
     * @param string $sanitizer 'ksesPost' to keep inline markup, otherwise plain text
     * @return string
     */
    private static function previewText($value, $sanitizer = 'text')
    {
        $value = MediaService::parseSmartcodes((string) $value);

        return $sanitizer === 'ksesPost' ? wp_kses_post($value) : sanitize_text_field($value);
    }

    /**
     * Decode a JSON-encoded button style group from the preview request.
     *
     * @param mixed $value
     * @return array
     */
    private static function decodeStyleGroup($value)
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode(wp_unslash($value), true);

        return is_array($decoded) ? $decoded : [];
    }
}
