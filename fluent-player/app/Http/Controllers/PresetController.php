<?php

namespace FluentPlayer\App\Http\Controllers;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Services\PresetService;

class PresetController extends Controller
{
    public function get()
    {
        return array_values(PresetService::all());
    }

    public function find($slug)
    {
        $preset = PresetService::find($slug);
        if (!$preset) {
            return $this->sendError(['message' => 'Preset not found'], 404);
        }
        return $preset;
    }
}
