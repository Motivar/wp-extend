<?php
/**
 * Registers a Resource's operations as REST routes.
 *
 * @package MammothWP\WpKit
 * @since   0.1.0
 */

namespace MammothWP\WpKit\Adapters;

use MammothWP\WpKit\Context;
use MammothWP\WpKit\Field_Map;
use MammothWP\WpKit\Inventory;
use MammothWP\WpKit\Operation;
use MammothWP\WpKit\Resource;

if (!defined('ABSPATH')) {
    exit;
}

final class Rest_Adapter
{
    /** @var Resource */
    private $resource;

    /**
     * @param Resource $resource Resource to expose.
     */
    public function __construct(Resource $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Hook route registration (or register now if rest_api_init already ran).
     *
     * @return void
     *
     * @since 0.1.0
     */
    public function register()
    {
        if (!function_exists('register_rest_route')) {
            return;
        }

        // Future servers (tests rebuild them) get the routes through the action;
        // a server that already exists gets them now.
        add_action('rest_api_init', [$this, 'register_routes']);

        if (did_action('rest_api_init')) {
            $this->register_routes();
        }
    }

    /**
     * Register every REST binding of every REST-enabled operation.
     *
     * @return void
     *
     * @since 0.1.0
     */
    public function register_routes()
    {
        $namespace = $this->resource->rest_namespace();
        if ($namespace === null || $namespace === '') {
            return;
        }

        foreach ($this->resource->ops() as $operation) {
            if (!$operation->is_on(Context::REST)) {
                continue;
            }

            foreach ($operation->rest_bindings() as $binding) {
                register_rest_route(
                    $namespace,
                    Inventory::join($this->resource->rest_base(), $binding['path']),
                    $this->route_args($operation, $binding)
                );
            }
        }
    }

    /**
     * @param Operation $operation Operation.
     * @param array     $binding   REST binding.
     *
     * @return array register_rest_route() arguments.
     */
    private function route_args(Operation $operation, array $binding)
    {
        $status = $binding['status'];

        return [
            'methods'             => $binding['method'],
            'callback'            => function ($request) use ($operation, $status) {
                return $this->dispatch($operation, $request, $status);
            },
            'permission_callback' => function ($request) use ($operation) {
                return $operation->authorize($this->input_from($request), Context::rest($request));
            },
            'args'                => Field_Map::to_rest_args($operation->fields()),
        ];
    }

    /**
     * Run the operation and shape the response.
     *
     * A WP_Error passes through with its own status. Any other result gets the operation's declared
     * success status, except a \WP_HTTP_Response the service built with a status other than 200:
     * that status (and the response's headers) is the service's answer and is kept, so a wrapped
     * legacy handler returning e.g. `new WP_REST_Response($body, 503)` still answers 503 (0.3.3).
     *
     * @param Operation        $operation Operation.
     * @param \WP_REST_Request $request   Request.
     * @param int              $status    Declared success status.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    private function dispatch(Operation $operation, $request, $status)
    {
        $result = $operation->run($this->input_from($request), Context::rest($request));

        if ($result instanceof \WP_Error) {
            return $result;
        }

        $response = rest_ensure_response($result);
        if (!self::has_own_status($result)) {
            $response->set_status($status);
        }

        return $response;
    }

    /**
     * Whether a service result carries a status of its own that must not be replaced.
     *
     * 200 is the \WP_HTTP_Response default, so it counts as "no status chosen" and the declared
     * success status (e.g. 201 or 204) still applies.
     *
     * @param mixed $result Operation result.
     *
     * @return bool
     *
     * @since 0.3.3
     */
    private static function has_own_status($result)
    {
        return $result instanceof \WP_HTTP_Response && (int) $result->get_status() !== 200;
    }

    /**
     * @param \WP_REST_Request $request Request.
     *
     * @return array URL, query and body parameters merged.
     */
    private function input_from($request)
    {
        $params = $request->get_params();

        return is_array($params) ? $params : [];
    }
}
