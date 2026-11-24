<?php

namespace FluentPlayer\App\Modules\MCP\Support;

/**
 * The closed set of machine-readable error codes MCP tools return.
 */
class ErrorCodes
{
    const MISSING_PARAM         = 'missing_param';
    const INVALID_PARAM         = 'invalid_param';
    const FORBIDDEN             = 'forbidden';
    const NOT_FOUND             = 'not_found';
    const TOOL_FAILED           = 'tool_failed';
    const CONFIRMATION_REQUIRED = 'confirmation_required';
    const CONFIRMATION_EXPIRED  = 'confirmation_expired';
    const CONFIRMATION_INVALID  = 'confirmation_invalid';
    const STATE_CHANGED         = 'state_changed';
    const LIMIT_EXCEEDED        = 'limit_exceeded';
    const CONCURRENT_REQUEST    = 'concurrent_request';
    const EXECUTION_UNKNOWN     = 'execution_unknown';

    public static function all()
    {
        return [
            self::MISSING_PARAM,
            self::INVALID_PARAM,
            self::FORBIDDEN,
            self::NOT_FOUND,
            self::TOOL_FAILED,
            self::CONFIRMATION_REQUIRED,
            self::CONFIRMATION_EXPIRED,
            self::CONFIRMATION_INVALID,
            self::STATE_CHANGED,
            self::LIMIT_EXCEEDED,
            self::CONCURRENT_REQUEST,
            self::EXECUTION_UNKNOWN,
        ];
    }
}
