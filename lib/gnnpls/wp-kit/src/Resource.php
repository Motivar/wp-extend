<?php
/**
 * A named group of operations over one service.
 *
 * Subclasses declare identity and operations; the adapters read everything
 * else from here. A resource with no REST namespace, CLI base or ability
 * category simply is not exposed on that surface.
 *
 * @package Gnnpls\WP
 * @since   0.1.0
 */

namespace Gnnpls\WP;

if (!defined('ABSPATH')) {
    exit;
}

abstract class Resource
{
    /** @var Operation[]|null */
    private $bound = null;
    /** @var object|null */
    private $service_instance = null;

    /**
     * Machine name, e.g. `content`.
     *
     * @return string
     */
    abstract public function name();

    /**
     * The service object or a factory callable returning it.
     *
     * @return object|callable
     */
    abstract public function service();

    /**
     * Operations keyed by name.
     *
     * @return array<string,Operation>
     */
    abstract public function operations();

    /**
     * Human readable label.
     *
     * @return string
     */
    public function label()
    {
        return ucfirst(str_replace(['_', '-'], ' ', $this->name()));
    }

    /**
     * REST namespace, e.g. `my-plugin/v1`. Null disables REST.
     *
     * @return string|null
     */
    public function rest_namespace()
    {
        return null;
    }

    /**
     * REST base path under the namespace.
     *
     * @return string
     */
    public function rest_base()
    {
        return '/' . $this->name();
    }

    /**
     * WP-CLI command prefix, e.g. `my-plugin content`. Null disables CLI.
     *
     * @return string|null
     */
    public function cli_base()
    {
        return null;
    }

    /**
     * Ability category slug, e.g. `my-plugin-content`. Null disables abilities.
     *
     * @return string|null
     */
    public function ability_category()
    {
        return null;
    }

    /**
     * Arguments for wp_register_ability_category().
     *
     * @return array{label:string,description:string}
     */
    public function ability_category_args()
    {
        return ['label' => $this->label(), 'description' => ''];
    }

    /**
     * Default capability resolver for operations that declare none.
     *
     * @return mixed
     */
    public function capability()
    {
        return 'manage_options';
    }

    /**
     * Operations with names and resource bound, memoised.
     *
     * @return array<string,Operation>
     *
     * @since 0.1.0
     */
    final public function ops()
    {
        if ($this->bound !== null) {
            return $this->bound;
        }

        $this->bound = [];
        foreach ($this->operations() as $name => $operation) {
            if (!$operation instanceof Operation) {
                continue;
            }
            $this->bound[$name] = $this->apply_defaults($operation->attach($name, $this));
        }

        return $this->bound;
    }

    /**
     * Resolved service instance, memoised.
     *
     * @return object|null
     *
     * @since 0.1.0
     */
    final public function service_instance()
    {
        if ($this->service_instance !== null) {
            return $this->service_instance;
        }

        $service = $this->service();
        if (is_callable($service) && !is_object($service)) {
            $service = call_user_func($service);
        } elseif ($service instanceof \Closure) {
            $service = $service();
        }

        $this->service_instance = is_object($service) ? $service : null;

        return $this->service_instance;
    }

    /**
     * Fill in resource-level defaults an operation left unset.
     *
     * @param Operation $operation Bound operation.
     *
     * @return Operation
     */
    private function apply_defaults(Operation $operation)
    {
        if ($operation->capability_resolver() === null) {
            $operation->capability($this->capability());
        }

        return $operation;
    }
}
