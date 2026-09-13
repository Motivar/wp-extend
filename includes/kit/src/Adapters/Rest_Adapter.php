<?php
/**
 * Registers a Resource's operations as REST routes.
 *
 * @package Motivar\WP
 * @since   0.1.0
 */

namespace Motivar\WP\Adapters;

use Motivar\WP\Context;
use Motivar\WP\Field_Map;
use Motivar\WP\Inventory;
use Motivar\WP\Operation;
use Motivar\WP\Resource;

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

        if (did_action('rest_api_init')) {
            $this->register_routes();
            return;
        }

        add_action('rest_api_init', [$this, 'register_routes']);
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
     * @param Operation        $operation Operation.
     * @param \WP_REST_Request $request   Request.
     * @param int              $status    Success status.
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
        $response->set_status($status);

        return $response;
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
