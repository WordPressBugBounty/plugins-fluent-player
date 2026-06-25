<?php

namespace FluentPlayer\App\Hooks\Helpers;
if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;

class PlayerHelper
{
    /**
     * Format seconds to VTT time format (HH:MM:SS.mmm)
     *
     * @param float $seconds The time in seconds
     * @return string Formatted time string in VTT format
     */
    public static function formatVttTime($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(fmod($seconds, 3600) / 60);
        $secs = floor(fmod($seconds, 60));
        $ms = floor(($seconds - floor($seconds)) * 1000);
        
        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $secs, $ms);
    }

    /**
     * Generate VTT content for chapters
     *
     * @param array $chapters Array of chapter objects with startTime, endTime, and title
     * @return string VTT content
     */
    public static function generateChaptersVtt($chapters)
    {
        if (empty($chapters)) {
            return '';
        }
        
        $vtt = "WEBVTT\n";
        $vtt .= "Kind: chapters\n";
        $vtt .= "Language: en\n\n";
        
        foreach ($chapters as $chapter) {
            $startTime = self::formatVttTime(Arr::get($chapter, 'startTime', 0));
            $endTime = self::formatVttTime(Arr::get($chapter, 'endTime', 0));
            $title = Arr::get($chapter, 'title', '');
            
            $vtt .= "{$startTime} --> {$endTime}\n";
            $vtt .= "{$title}\n\n";
        }
        
        return $vtt;
    }
    
    /**
     * Generate base64 encoded VTT content
     *
     * @param string $vtt VTT content
     * @return string Base64 encoded VTT content with data URI
     */
    public static function generateVttDataUri($vtt)
    {
        return 'data:text/vtt;base64,' . base64_encode($vtt);
    }
}
