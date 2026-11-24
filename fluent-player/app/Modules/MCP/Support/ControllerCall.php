<?php

namespace FluentPlayer\App\Modules\MCP\Support;

use FluentPlayer\Framework\Foundation\App;

class ControllerCall
{
    /**
     * Runs a REST controller action in-process with its own request inputs.
     *
     * Route ids are passed explicitly: a nested rest_do_request() would read them from the
     * shared request singleton and an update silently turns into an insert.
     *
     * @return array|\WP_Error Response data, or a WP_Error carrying the HTTP status.
     */
    public static function callWithInput($controllerClass, $method, array $input, array $routeArgs = [])
    {
        // Outside REST, Controller::validate() swallows a failed validation and returns null.
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return new \WP_Error('fluent_player_mcp_not_rest', __('MCP tools run only inside a REST request.', 'fluent-player'), ['status' => 500]);
        }

        $app = App::getInstance();
        $request = new ToolInputRequest($app, [], $input);
        $controller = $app->make($controllerClass);

        // Some actions take only route ids, e.g. delete($slug).
        $parameters = (new \ReflectionMethod($controller, $method))->getParameters();
        $wantsRequest = $parameters && $parameters[0]->getType() && !$parameters[0]->getType()->isBuiltin();
        $response = $wantsRequest
            ? $controller->{$method}($request, ...$routeArgs)
            : $controller->{$method}(...$routeArgs);

        if (!$response instanceof \WP_REST_Response) {
            return (array) $response;
        }

        $data = (array) $response->get_data();
        $status = $response->get_status();

        if ($status >= 400) {
            $message = isset($data['message']) && is_string($data['message'])
                ? $data['message']
                : __('The request was rejected.', 'fluent-player');

            return new \WP_Error('fluent_player_mcp_rejected', $message, ['status' => $status, 'errors' => $data]);
        }

        return $data;
    }
}
