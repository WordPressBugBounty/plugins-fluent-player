<?php

namespace FluentPlayer\App\Http\Controllers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Http\Request\Request;
use FluentPlayer\App\Services\LayerService;

class LayerController extends Controller
{
    /**
     * Get forms by type
     *
     * @param Request $request
     * @return \WP_REST_Response
     */
    public function getForms(Request $request)
    {
        try {
            return $this->sendSuccess([
                'forms' => LayerService::getFormsByType($request->get('type'))
            ]);
        } catch (\Exception $e) {
            return $this->sendError(['message' => __('Failed to load forms', 'fluent-player')], 400);
        }
    }

    /**
     * Get form preview HTML
     *
     * @param Request $request
     * @return \WP_REST_Response
     */
    public function getFormPreview(Request $request)
    {

        try {
            return $this->sendSuccess([
                'html' => LayerService::getFormsPreview($request->get('type'), $request->get('form_id')),
                'form_type' => $request->get('type'),
                'form_id' => $request->get('form_id')
            ]);
        } catch (\Exception $e) {
            return $this->sendError(['message' => __('Failed to load form preview', 'fluent-player')], 400);
        }
    }

    /**
     * Get shortcode preview HTML
     *
     * @param Request $request
     * @return \WP_REST_Response
     */
    public function getShortcodePreview(Request $request)
    {
        try {
            return $this->sendSuccess([
                'html' => LayerService::getShortcodePreview($request->get('shortcode')),
                'shortcode' => $request->get('shortcode')
            ]);
        } catch (\Exception $e) {
            return $this->sendError(['message' => __('Failed to load shortcode preview', 'fluent-player')], 400);
        }
    }
}
