<?php

namespace FluentPlayer\App\Http\Controllers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Http\Request\Request;
use FluentPlayer\Framework\Support\Sanitizer;

class YouTubeController extends Controller
{
    /**
     * Get YouTube channel information including subscriber count
     *
     * @param Request $request
     * @return \WP_REST_Response
     */
    public function getChannelInfo(Request $request)
    {
        // Sanitize input data using framework's Sanitizer
        $sanitizedData = Sanitizer::sanitize($request->all(), [
            'channel_id' => 'sanitizeTextField'
        ]);

        $channelId = $sanitizedData['channel_id'] ?? '';

        if (empty($channelId)) {
            return $this->sendError([
                'success' => false,
                'message' => __('Channel ID is required', 'fluent-player')
            ], 400);
        }

        // Validate channel ID format (YouTube channel IDs are alphanumeric with specific patterns)
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $channelId)) {
            return $this->sendError([
                'success' => false,
                'message' => __('Invalid channel ID format', 'fluent-player')
            ], 400);
        }

        // Fall back to scraping method
        $scrapingResult = $this->getChannelInfoFromScraping($channelId);

        if ($scrapingResult['success']) {
            return $this->sendSuccess($scrapingResult);
        } else {
            return $this->sendError([
                'success' => false,
                'message' => $scrapingResult['message']
            ], 404);
        }
    }

    /**
     * Get channel info by scraping YouTube
     *
     * @param string $channelId
     * @return array
     */
    private function getChannelInfoFromScraping($channelId)
    {
        try {
            // Fetch channel page
            $channelUrl = "https://www.youtube.com/channel/{$channelId}?hl=en";
            $response = wp_remote_get($channelUrl, [
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                'timeout'    => 15,
                'headers'    => [
                    'Accept-Language' => 'en-US,en;q=0.9',
                ]
            ]);

            if (is_wp_error($response)) {
                return [
                    'success' => false,
                    'message' => __('Failed to connect to YouTube', 'fluent-player')
                ];
            }

            $html = wp_remote_retrieve_body($response);

            // Extract subscriber count from the HTML
            $subscriberCount = $this->extractSubscriberCount($html);

            if (!$subscriberCount) {
                return [
                    'success' => false,
                    'message' => __('Could not retrieve subscriber count', 'fluent-player')
                ];
            }

            return [
                'success'          => true,
                'subscriber_count' => $subscriberCount,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => __('Error fetching channel info. Please try again later.', 'fluent-player')
            ];
        }
    }

    /**
     * Extract subscriber count from YouTube channel HTML
     *
     * @param string $html
     * @return string|null
     */
    private function extractSubscriberCount($html)
    {
        // Pattern for the exact JSON structure you provided
        if (preg_match('/"metadataParts":\[\{"text":\{"content":"([^"]+subscribers[^"]*)"\}/', $html, $matches)) {
            return $this->extractNumericSubscriberCount($matches[1]);
        }

        if (preg_match('/"subscriberCountText":\{"runs":\[\{"text":"([^"]+)"\}\]\}/', $html, $matches)) {
            return $this->extractNumericSubscriberCount($matches[1]);
        }

        return null;
    }

    /**
     * Extract the numeric portion from a YouTube subscriber label.
     *
     * @param string $subscriberText
     * @return string|null
     */
    private function extractNumericSubscriberCount($subscriberText)
    {
        if (preg_match('/(\d+(?:,\d+)*(?:\.\d+)?(?:[KMB])?)/i', $subscriberText, $numberMatches)) {
            return $numberMatches[1];
        }

        return null;
    }
}
