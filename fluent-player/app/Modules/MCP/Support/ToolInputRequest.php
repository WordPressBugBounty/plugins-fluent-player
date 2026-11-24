<?php

namespace FluentPlayer\App\Modules\MCP\Support;

use FluentPlayer\Framework\Http\Request\Request;

/**
 * A request that carries only the MCP tool's input.
 *
 * The base Request also merges the current REST request and the raw JSON body; inside the MCP endpoint
 * that is the JSON-RPC envelope, whose "id" would be read as a media id and overwrite that item.
 */
class ToolInputRequest extends Request
{
    protected function inputs()
    {
        return $this->safe === true ? $this->validated : $this->request;
    }

    public function json($key = null, $default = null)
    {
        return null === $key ? [] : $default;
    }
}
