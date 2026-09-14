<?php
/**
 * Holds every declared Resource for the adapters and the inventory.
 *
 * @package Gnnpls\WP
 * @since   0.1.0
 */

namespace Gnnpls\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Registry
{
    /** @var array<string,Resource> */
    private $resources = [];

    /**
     * @param Resource $resource Resource to add (replaces one with the same name).
     *
     * @return Registry
     *
     * @since 0.1.0
     */
    public function add(Resource $resource)
    {
        $this->resources[$resource->name()] = $resource;
        return $this;
    }

    /**
     * @return array<string,Resource>
     *
     * @since 0.1.0
     */
    public function all()
    {
        return $this->resources;
    }

    /**
     * @param string $name Resource name.
     *
     * @return Resource|null
     *
     * @since 0.1.0
     */
    public function get($name)
    {
        return isset($this->resources[$name]) ? $this->resources[$name] : null;
    }

    /**
     * @param string $resource  Resource name.
     * @param string $operation Operation name.
     *
     * @return Operation|null
     *
     * @since 0.1.0
     */
    public function find($resource, $operation)
    {
        $found = $this->get($resource);
        if ($found === null) {
            return null;
        }

        $ops = $found->ops();

        return isset($ops[$operation]) ? $ops[$operation] : null;
    }

    /**
     * Announce the registry to hosts and adapters.
     *
     * @return Registry
     *
     * @since 0.1.0
     */
    public function ready()
    {
        if (function_exists('do_action')) {
            /**
             * Fires once every resource has been declared.
             *
             * @param Registry $registry The registry.
             *
             * @since 0.1.0
             */
            do_action('mwp_registry_ready', $this);
        }

        return $this;
    }
}
