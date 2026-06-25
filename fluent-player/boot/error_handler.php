<?php
if (!defined('ABSPATH')) exit;

/**
 * Custom error handler for development environments only.
 * This will only be active when WP_DEBUG is enabled.
 */
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Only active in development mode
    set_error_handler(function($errno, $msg, $fn, $ln) use ($file) {
        // Check if this error type should be reported based on current error_reporting level
        // This respects the error_reporting settings in php.ini or set by WordPress
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting -- Used for error handling logic
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $levels = [
            E_ALL               => "E_ALL",
            E_ERROR             => "E_ERROR",
            E_WARNING           => "E_WARNING",
            E_PARSE             => "E_PARSE",
            E_NOTICE            => "E_NOTICE",
            E_CORE_ERROR        => "E_CORE_ERROR",
            E_CORE_WARNING      => "E_CORE_WARNING",
            E_COMPILE_ERROR     => "E_COMPILE_ERROR",
            E_COMPILE_WARNING   => "E_COMPILE_WARNING",
            E_USER_ERROR        => "E_USER_ERROR",
            E_USER_WARNING      => "E_USER_WARNING",
            E_USER_NOTICE       => "E_USER_NOTICE",
            E_RECOVERABLE_ERROR => "E_RECOVERABLE_ERROR",
            E_DEPRECATED        => "E_DEPRECATED",
            E_USER_DEPRECATED   => "E_USER_DEPRECATED"
        ];

        if (PHP_VERSION_ID < 70400) {
            $levels[E_STRICT] = "E_STRICT";
        }

        $levelName = $levels[$errno] ?? "UNKNOWN ERROR LEVEL";

        // Only process errors from our plugin
        if (strpos($fn, dirname($file) . '/') !== false) {
            // Additional check for WP_DEBUG_LOG to determine how to handle the error
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // Log detailed error information
                $debugInfo = [
                    'Error Level' => $levelName,
                    'Message' => $msg,
                    'File' => $fn,
                    'Line' => $ln,
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Only used in development mode
                    'Backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
                ];

                // Log to debug.log
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only active in development mode
                error_log(sprintf(
                    '[Fluent Player] %s - %s in %s at line %s',
                    $levelName,
                    $msg,
                    $fn,
                    $ln
                ));
            }

            // Only display errors if WP_DEBUG_DISPLAY is true
            if (defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY) {
                throw new ErrorException(sprintf(
                    '%s - %s in %s at line %s',
                    esc_html($levelName),
                    esc_html($msg),
                    esc_html($fn),
                    esc_html((string)$ln)
                ), 500);
            }
        }

        return false;
    });
}
