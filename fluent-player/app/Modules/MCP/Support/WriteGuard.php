<?php

namespace FluentPlayer\App\Modules\MCP\Support;

/**
 * Dry-run → confirm_token → idempotency_key flow that every MCP write goes through.
 * Ported from Fluent Forms' MCP WriteGuard.
 */
class WriteGuard
{
    const CONFIRM_TTL = 300;

    const REPLACED_TOKENS_KEPT = 5;

    const IDEM_TTL = 86400;

    // A lease, so a request that dies mid-write cannot block its entity forever.
    const CLAIM_TTL = 120;

    const CLAIM_PREFIX = 'flp_mcp_claim_';

    const IN_FLIGHT = '__flp_mcp_in_flight';

    /** The params every guarded write shares; merge into a tool's input_schema properties. */
    public static function schemaProps()
    {
        return [
            'dry_run'         => ['type' => 'boolean', 'description' => 'Preview the change without writing; returns a confirm_token.'],
            'confirm_token'   => ['type' => 'string', 'description' => 'The token from the dry_run preview, required to execute.'],
            'idempotency_key' => ['type' => 'string', 'description' => 'Optional; a retry with the same key will not act twice.'],
        ];
    }

    public static function preview($tool, $entityKey, $fingerprint, array $preview)
    {
        $token = substr(wp_hash($tool . '|' . $entityKey . '|' . $fingerprint . '|' . wp_generate_uuid4()), 0, 32);

        // Remember the tokens this preview replaces, so confirming one of them gets a clear reason.
        $previous = get_transient(self::confirmKey($tool, $entityKey));
        $replaced = [];
        if (is_array($previous) && !empty($previous['token'])) {
            $replaced = array_merge([$previous['token']], isset($previous['replaced']) ? (array) $previous['replaced'] : []);
        }

        set_transient(self::confirmKey($tool, $entityKey), [
            'token'       => $token,
            'fingerprint' => $fingerprint,
            'replaced'    => array_slice($replaced, 0, self::REPLACED_TOKENS_KEPT),
        ], self::CONFIRM_TTL);

        return MCPHelper::envelope(__('Dry run: nothing was changed yet.', 'fluent-player'), [
            'dry_run'            => true,
            'preview'            => $preview,
            'confirm_token'      => $token,
            'expires_in_seconds' => self::CONFIRM_TTL,
            'next_step'          => 'Show this preview to the user. If they approve, call this tool again with the same parameters plus confirm_token (and an idempotency_key).',
        ]);
    }

    /** @return true|\WP_Error */
    public static function confirm($tool, $entityKey, $currentFingerprint, $token)
    {
        if (empty($token)) {
            return MCPHelper::error(
                ErrorCodes::CONFIRMATION_REQUIRED,
                __('This action changes data. Call again with dry_run:true to preview, then pass the returned confirm_token to execute.', 'fluent-player'),
                ['next_step' => 'set dry_run:true']
            );
        }

        $stored = get_transient(self::confirmKey($tool, $entityKey));

        if (!is_array($stored) || empty($stored['token'])) {
            return MCPHelper::error(
                ErrorCodes::CONFIRMATION_EXPIRED,
                __('Your confirmation has expired. Run a fresh dry_run to preview and get a new confirm_token.', 'fluent-player'),
                ['next_step' => 'set dry_run:true']
            );
        }

        if (!hash_equals((string) $stored['token'], (string) $token)) {
            if (in_array((string) $token, isset($stored['replaced']) ? (array) $stored['replaced'] : [], true)) {
                return MCPHelper::error(
                    ErrorCodes::CONFIRMATION_INVALID,
                    __('A newer preview replaced this confirm_token. Run the dry_run again and confirm with its new token.', 'fluent-player'),
                    ['next_step' => 'set dry_run:true']
                );
            }

            return MCPHelper::error(
                ErrorCodes::CONFIRMATION_INVALID,
                __('The confirm_token does not match. Run a fresh dry_run.', 'fluent-player'),
                ['next_step' => 'set dry_run:true']
            );
        }

        delete_transient(self::confirmKey($tool, $entityKey));

        if ((string) $stored['fingerprint'] !== (string) $currentFingerprint) {
            return MCPHelper::error(
                ErrorCodes::STATE_CHANGED,
                __('The item changed since you previewed it. Run a fresh dry_run to see the current state before executing.', 'fluent-player'),
                ['next_step' => 'set dry_run:true']
            );
        }

        return true;
    }

    /**
     * Cached result of an earlier run with the same idempotency key, or null.
     * Checked before the single-use confirm token, which a lost-response retry has already spent.
     */
    public static function replay($tool, $key)
    {
        if (empty($key)) {
            return null;
        }

        $cached = get_transient(self::idemKey($tool, $key));
        if (false === $cached) {
            return null;
        }

        if (is_array($cached) && !empty($cached[self::IN_FLIGHT])) {
            return MCPHelper::error(
                ErrorCodes::EXECUTION_UNKNOWN,
                __('An earlier attempt with this idempotency_key started but its outcome was never recorded. Check the current state; if the change is not there, retry with a NEW idempotency_key.', 'fluent-player'),
                ['next_step' => 'verify the current state, then retry with a new idempotency_key']
            );
        }

        return is_array($cached) ? array_merge($cached, ['idempotent_replay' => true]) : $cached;
    }

    /** Records the attempt before running, so a crash mid-write is reported as unknown rather than repeated. */
    public static function idempotent($tool, $key, callable $apply)
    {
        if (empty($key)) {
            return $apply();
        }

        $cacheKey = self::idemKey($tool, $key);
        set_transient($cacheKey, [self::IN_FLIGHT => true, 'started_at' => time()], self::IDEM_TTL);

        $result = $apply();

        if (is_wp_error($result)) {
            delete_transient($cacheKey);
        } else {
            set_transient($cacheKey, $result, self::IDEM_TTL);
        }

        return $result;
    }

    /**
     * Takes the exclusive right to run this (tool, entity) write.
     * Atomic through the UNIQUE option_name index: INSERT IGNORE succeeds for exactly one caller.
     *
     * @return string|false Receipt to pass to release(), or false when another request holds it.
     */
    public static function claim($tool, $entityKey)
    {
        global $wpdb;

        $key = self::claimKey($tool, $entityKey);
        $receipt = wp_generate_uuid4() . '|' . (time() + self::CLAIM_TTL);

        if (self::insertClaim($key, $receipt)) {
            return $receipt;
        }

        $observed = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $key
        ));

        // Compare-and-swap an expired lease so only one of two reclaimers wins.
        if (null !== $observed && self::expiryOf($observed) <= time()) {
            $won = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $receipt,
                $key,
                $observed
            ));

            if ($won) {
                self::sweepExpiredClaims($key);
                return $receipt;
            }
        }

        // The holder may have released between the two statements above.
        if (self::insertClaim($key, $receipt)) {
            return $receipt;
        }

        self::sweepExpiredClaims($key);

        return false;
    }

    /** @return bool False when the lease expired mid-write and someone else took it over. */
    public static function release($tool, $entityKey, $receipt)
    {
        global $wpdb;

        if (empty($receipt)) {
            return false;
        }

        return (bool) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            self::claimKey($tool, $entityKey),
            $receipt
        ));
    }

    private static function insertClaim($key, $receipt)
    {
        global $wpdb;

        return (bool) $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $key,
            $receipt
        ));
    }

    private static function expiryOf($receipt)
    {
        $pos = strrpos((string) $receipt, '|');

        return false === $pos ? 0 : (int) substr((string) $receipt, $pos + 1);
    }

    private static function sweepExpiredClaims($exceptKey)
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options}
              WHERE option_name LIKE %s
                AND option_name != %s
                AND CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) < %d
              LIMIT 50",
            $wpdb->esc_like(self::CLAIM_PREFIX) . '%',
            $exceptKey,
            time()
        ));
    }

    // Per item, not per tool or user: every write to the same item contends for one claim.
    private static function claimKey($tool, $entityKey)
    {
        return self::CLAIM_PREFIX . md5($entityKey);
    }

    private static function confirmKey($tool, $entityKey)
    {
        return 'flp_mcp_confirm_' . get_current_user_id() . '_' . md5($tool . '|' . $entityKey);
    }

    // Not per item: the write is stored under the resolved item, but a retry is looked up from the raw input
    // (a preset name, not its slug). $key already carries the hash of every param.
    private static function idemKey($tool, $key)
    {
        return 'flp_mcp_idem_' . get_current_user_id() . '_' . md5($tool . '|' . $key);
    }
}
