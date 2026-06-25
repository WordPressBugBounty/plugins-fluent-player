<?php

namespace FluentPlayer\App\Services\Smartcode;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Support\Arr;
use FluentPlayer\Framework\Support\Str;

/**
 * Resolves Fluent Player shortcodes ({{namespace.key|fallback}}) in a string.
 * Namespaces this parser does not own (e.g. FluentCRM's {{contact.*}}) are
 * returned untouched for a later parser to handle.
 */
class SmartcodeParser
{
    public static function parse($text, $context = [])
    {
        if (!is_string($text) || $text === '' || !Str::contains($text, '{{')) {
            return $text;
        }

        $namespaces = SmartcodeRegistry::namespaces();

        return preg_replace_callback('/\{\{(.*?)\}\}/', function ($matches) use ($namespaces, $context) {
            return self::replace($matches, $namespaces, $context);
        }, $text);
    }

    protected static function replace($matches, $namespaces, $context)
    {
        $inner = trim($matches[1]);
        if ($inner === '' || !Str::contains($inner, '.')) {
            return $matches[0];
        }

        $namespace = trim(Str::before($inner, '.'));
        $rest      = trim(Str::after($inner, '.'));

        // key | fallback | transformer(reserved)
        $segments = explode('|', $rest);
        $key      = trim($segments[0]);
        $fallback = isset($segments[1]) ? trim($segments[1]) : '';

        if (!Arr::exists($namespaces, $namespace)) {
            // Not ours — leave intact for a later parser (e.g. FluentCRM).
            return $matches[0];
        }

        $resolver = Arr::get($namespaces, $namespace . '.resolver');
        if (!is_callable($resolver)) {
            return $matches[0];
        }

        $value = call_user_func($resolver, $key, $fallback, $context);

        return $value === null ? '' : (string) $value;
    }
}
