<?php

namespace EWP\Surfaces;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The REST-health route inventory, behind `GET extend-wp/v1/rest-health/plugins`,
 * `POST|GET .../rest-health/endpoints`, `wp ewp rest-health plugins|endpoints`
 * and the `ewp-rest-health/*` abilities.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class Rest_Health_Inventory
{
    /** @var \EWP_REST_Health_Discovery */
    private $discovery;

    /**
     * @param \EWP_REST_Health_Discovery|null $discovery Discovery service.
     */
    public function __construct($discovery = null)
    {
        $this->discovery = $discovery ?: new \EWP_REST_Health_Discovery();
    }

    /**
     * Active plugins with the REST namespaces attributed to each.
     *
     * @param bool $refresh Clear the namespace scan cache first.
     *
     * @return array List of `{path, name, dir, namespaces}`.
     *
     * @since 1.5.0
     */
    public function plugins($refresh = false)
    {
        if ($refresh) {
            $this->discovery->clear_map_cache();
        }

        $map    = $this->discovery->build_namespace_plugin_map();
        $result = [];

        foreach ($this->discovery->get_active_plugins() as $path => $meta) {
            $namespaces = array_keys(array_filter($map, function ($plugin) use ($path) {
                return $plugin === $path;
            }));
            $result[] = [
                'path'       => $path,
                'name'       => $meta['name'],
                'dir'        => $meta['dir'],
                'namespaces' => array_values($namespaces),
            ];
        }

        return $result;
    }

    /**
     * Routes registered by the given plugins.
     *
     * @param array $plugins Plugin paths as reported by plugins().
     *
     * @return array|\WP_Error List of route descriptors.
     *
     * @since 1.5.0
     */
    public function endpoints(array $plugins)
    {
        $plugins = array_values(array_filter(array_map('strval', $plugins)));
        if ($plugins === []) {
            return new \WP_Error('ewp_rest_health_no_plugins', __('No plugins specified.', 'extend-wp'), ['status' => 400]);
        }

        return array_values($this->discovery->get_routes_for_plugins($plugins));
    }
}
