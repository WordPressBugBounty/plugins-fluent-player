<?php

namespace FluentPlayer\App\Modules\MCP\Support;

class Mutation
{
    /**
     * Runs one write behind WriteGuard and records it in the activity log.
     * dry_run returns a preview + confirm_token bound to $fingerprint; the confirmed call applies once.
     *
     * @param string   $tool        Ability name.
     * @param array    $params      Tool params (dry_run, confirm_token, idempotency_key, ...).
     * @param string   $entityKey   Stable target id, e.g. "media:42".
     * @param string|callable $fingerprint Current state of the target (or a callable read after the lease is taken).
     * @param callable $preview     Returns the preview payload.
     * @param callable $apply       Performs the write; returns an envelope array or WP_Error.
     * @param string|callable $target Activity-log target, or callable($result) for ids known only after create.
     * @return array|\WP_Error
     */
    public static function runGuarded($tool, array $params, $entityKey, $fingerprint, callable $preview, callable $apply, $target)
    {
        // Writes reuse REST controllers; refuse up front (e.g. WP-CLI stdio) so no confirm token is spent.
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return MCPHelper::error(ErrorCodes::TOOL_FAILED, __('Fluent Player MCP writes run only over the HTTP endpoint.', 'fluent-player'));
        }

        // The token and idempotency record are bound to the exact change requested, not just the item.
        $requestHash = self::requestHash($params);
        $currentFingerprint = function () use ($fingerprint, $requestHash) {
            return (is_callable($fingerprint) ? $fingerprint() : $fingerprint) . '|' . $requestHash;
        };

        if (!empty($params['dry_run'])) {
            return WriteGuard::preview($tool, $entityKey, $currentFingerprint(), $preview());
        }

        $idemKey = self::idempotencyKey($params);

        $replay = WriteGuard::replay($tool, $idemKey);
        if (null !== $replay) {
            return $replay;
        }

        // Spending the token, checking idempotency and writing must be one critical section.
        $receipt = WriteGuard::claim($tool, $entityKey);
        if (!$receipt) {
            $replay = WriteGuard::replay($tool, $idemKey);
            if (null !== $replay) {
                return $replay;
            }

            return MCPHelper::error(
                ErrorCodes::CONCURRENT_REQUEST,
                __('An identical write is already in progress. Retry with the same idempotency_key to collect its result.', 'fluent-player'),
                ['retryable' => true]
            );
        }

        $released = false;

        try {
            $token = isset($params['confirm_token']) ? $params['confirm_token'] : '';
            $confirm = WriteGuard::confirm($tool, $entityKey, $currentFingerprint(), $token);
            if (is_wp_error($confirm)) {
                return $confirm;
            }

            $result = WriteGuard::idempotent($tool, $idemKey, $apply);

            WriteGuard::release($tool, $entityKey, $receipt);
            $released = true;

            ActivityLog::record($tool, is_callable($target) ? $target($result) : $target, $params, $result);

            return $result;
        } finally {
            // A throw from $apply must not strand the claim for CLAIM_TTL.
            if (!$released) {
                WriteGuard::release($tool, $entityKey, $receipt);
            }
        }
    }

    /**
     * The first result of a confirmed write retried with the same idempotency_key, or null.
     * The ability wrapper calls it before the tool runs, because a tool's own checks fail once its write applied.
     *
     * @return array|\WP_Error|null
     */
    public static function replay($tool, array $params)
    {
        if (!empty($params['dry_run'])) {
            return null;
        }

        return WriteGuard::replay($tool, self::idempotencyKey($params));
    }

    private static function idempotencyKey(array $params)
    {
        if (!isset($params['idempotency_key']) || '' === (string) $params['idempotency_key']) {
            return '';
        }

        return $params['idempotency_key'] . '|' . self::requestHash($params);
    }

    private static function requestHash(array $params)
    {
        unset($params['dry_run'], $params['confirm_token'], $params['idempotency_key']);
        self::sortKeys($params);

        return md5((string) wp_json_encode($params));
    }

    private static function sortKeys(array &$value)
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                self::sortKeys($item);
            }
        }
    }
}
