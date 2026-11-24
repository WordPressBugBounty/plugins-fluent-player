<?php

namespace FluentPlayer\App\Modules\MCP\Support;

/**
 * The newest AI changes, one non-autoloaded option row per entry for the settings tab.
 * Each write is a single insert, so concurrent requests can never overwrite each other's entry.
 */
class ActivityLog
{
    const PREFIX = 'flp_mcp_activity_';

    const MAX_ENTRIES = 200;

    const MAX_PRUNE_PER_WRITE = 50;

    const MAX_VALUE_LENGTH = 200;

    /**
     * @param string $tool   Ability name.
     * @param string $target e.g. "media:12".
     * @param array  $params Tool params; redacted and trimmed before storage.
     * @param mixed  $result Tool result or WP_Error.
     */
    public static function record($tool, $target, array $params, $result)
    {
        $user = wp_get_current_user();
        $isError = is_wp_error($result);

        $entry = [
            'time'       => gmdate('c'),
            'user_id'    => (int) $user->ID,
            'user_login' => $user->user_login,
            'tool'       => (string) $tool,
            'target'     => (string) $target,
            'result'     => $isError ? 'error' : 'ok',
            'error_code' => $isError ? $result->get_error_code() : '',
            'params'     => self::redact($params),
        ];

        // Fixed-width microtime keeps option names sortable newest-first.
        $name = self::PREFIX . sprintf('%017.6f', microtime(true)) . '_' . wp_generate_password(6, false, false);
        add_option($name, $entry, '', false);

        self::prune();
    }

    /** Newest first. */
    public static function entries()
    {
        global $wpdb;

        $values = $wpdb->get_col($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name DESC LIMIT %d",
            $wpdb->esc_like(self::PREFIX) . '%',
            self::MAX_ENTRIES
        ));

        $entries = [];
        foreach ($values as $value) {
            $entry = maybe_unserialize($value);
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** Deletes rows older than the newest MAX_ENTRIES, a bounded batch per write. */
    private static function prune()
    {
        global $wpdb;

        $stale = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name DESC LIMIT %d, %d",
            $wpdb->esc_like(self::PREFIX) . '%',
            self::MAX_ENTRIES,
            self::MAX_PRUNE_PER_WRITE
        ));

        foreach ($stale as $name) {
            delete_option($name);
        }
    }

    private static function redact(array $params)
    {
        unset($params['confirm_token'], $params['idempotency_key'], $params['dry_run']);

        $clean = [];
        foreach ($params as $key => $value) {
            if (preg_match('/token|secret|password|signing|api_?key|private/i', (string) $key)) {
                $clean[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $clean[$key] = self::redact($value);
            } elseif (is_string($value) && mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                $clean[$key] = mb_substr($value, 0, self::MAX_VALUE_LENGTH) . '…';
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
