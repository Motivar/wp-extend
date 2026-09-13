<?php

namespace EWP\Surfaces;

use EWP\Content\Content_Service;
use EWP\Surfaces\Resources\Content_Resource;
use EWP\Surfaces\Resources\Content_Type_Rest_Resource;
use EWP\Surfaces\Resources\Fields_Resource;
use EWP\Surfaces\Resources\Logger_Resource;
use EWP\Surfaces\Resources\Options_Resource;
use EWP\Surfaces\Resources\System_Resource;
use EWP\Surfaces\Resources\Search_Resource;
use EWP\Surfaces\Resources\WP_Content_Resource;
use Motivar\WP\Adapters\Ability_Adapter;
use Motivar\WP\Adapters\Cli_Adapter;
use Motivar\WP\Adapters\Rest_Adapter;
use Motivar\WP\Context;
use Motivar\WP\Kit;
use Motivar\WP\Operation;
use Motivar\WP\Registry;
use Motivar\WP\Resource;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Declares the plugin's resources with the Motivar WP kit and projects
 * each onto REST, WP-CLI and the Abilities API.
 *
 * Adding a feature means adding a Resource here (or through the
 * `ewp_surfaces_resources` filter); the three surfaces are generated.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class EWP_Surfaces
{
    /** @var EWP_Surfaces|null */
    private static $instance = null;
    /** @var Registry|null */
    private $registry = null;
    /** @var Content_Service|null */
    private $service = null;

    /**
     * @return EWP_Surfaces
     *
     * @since 1.5.0
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Boot once the kit is ready and bridge the legacy capability filter.
     *
     * @return void
     *
     * @since 1.5.0
     */
    public function init()
    {
        Kit::on_ready([$this, 'boot']);
        add_filter('mwp_operation_capability', [$this, 'reemit_ability_capability'], 10, 4);
        add_filter('mwp_ability_definitions', [$this, 'reemit_logger_definitions'], 10, 2);
    }

    /**
     * Build the registry and register every adapter.
     *
     * @return void
     *
     * @since 1.5.0
     */
    public function boot()
    {
        if ($this->registry !== null) {
            return;
        }

        /*
         * The resource classes extend Motivar\WP\Resource, which only
         * autoloads once the kit has booted (plugins_loaded -100), so they
         * are loaded here rather than when Setup.php requires this file.
         */
        require_once __DIR__ . '/class-content-schema.php';
        require_once __DIR__ . '/class-library-fields.php';
        require_once __DIR__ . '/class-field-vocabulary.php';
        require_once __DIR__ . '/resources/class-content-resource.php';
        require_once __DIR__ . '/resources/class-content-type-rest-resource.php';
        require_once __DIR__ . '/resources/class-typed-content-resource.php';
        require_once __DIR__ . '/resources/class-fields-resource.php';
        require_once __DIR__ . '/resources/class-wp-content-resource.php';
        require_once __DIR__ . '/resources/class-search-resource.php';
        require_once __DIR__ . '/resources/class-logger-resource.php';
        require_once __DIR__ . '/class-system-service.php';
        require_once __DIR__ . '/resources/class-system-resource.php';
        require_once __DIR__ . '/resources/class-options-resource.php';

        $this->service = new Content_Service();
        $this->registry = new Registry();

        /**
         * Filter the resources Extend WP exposes on REST, WP-CLI and the
         * Abilities API. Replaces the `ewp_abilities_providers` filter.
         *
         * @param Resource[]      $resources Resources keyed by name.
         * @param Content_Service $service   Shared content service.
         *
         * @since 1.5.0
         */
        $resources = apply_filters('ewp_surfaces_resources', [
            'content'    => new Content_Resource($this->service),
            'fields'     => new Fields_Resource($this->service),
            'wp-content' => new WP_Content_Resource($this->service),
            'search'     => new Search_Resource($this->service),
            'logger'     => new Logger_Resource(new \EWP\Logger\EWP_Logger_Query()),
            'options'    => new Options_Resource(),
            'system'     => new System_Resource(new System_Service($this->service)),
        ], $this->service);

        foreach ($resources as $resource) {
            if ($resource instanceof Resource) {
                $this->registry->add($resource);
            }
        }

        $this->registry->ready();

        foreach ($this->registry->all() as $resource) {
            $this->register_adapters($resource);
        }
    }

    /**
     * Project one resource onto the three surfaces.
     *
     * @param Resource $resource Resource to expose.
     *
     * @return void
     *
     * @since 1.5.0
     */
    public function register_adapters(Resource $resource)
    {
        (new Rest_Adapter($resource))->register();
        (new Cli_Adapter($resource))->register();
        (new Ability_Adapter($resource, ['EWP\\Abilities\\EWP_Abilities', 'is_enabled']))->register();
    }

    /**
     * Register the REST routes of one content type.
     *
     * Called by AWM_Add_Content_DB_Setup on `rest_api_init` for every
     * registered type, and by test fixtures for types created on demand.
     *
     * @param string $content_type Content type id.
     * @param string $prefix       REST namespace.
     * @param string $data_id      Route base.
     *
     * @return void
     *
     * @since 1.5.0
     */
    public function register_content_type_routes($content_type, $prefix, $data_id)
    {
        $registry = $this->registry();
        if ($registry === null) {
            return;
        }

        $resource = new Content_Type_Rest_Resource($content_type, $prefix, $data_id, $this->service);
        $registry->add($resource);
        (new Rest_Adapter($resource))->register();
    }

    /**
     * @return Registry|null Null until the kit has booted.
     *
     * @since 1.5.0
     */
    public function registry()
    {
        if ($this->registry === null && Kit::is_booted()) {
            $this->boot();
        }

        return $this->registry;
    }

    /**
     * @return Content_Service|null
     *
     * @since 1.5.0
     */
    public function service()
    {
        return $this->service;
    }

    /**
     * Keep the `ewp_logger_ability_definitions` filter working for the logger abilities.
     *
     * @param array    $definitions Definitions keyed by ability name.
     * @param Resource $resource    Owning resource.
     *
     * @return array
     *
     * @since 1.5.0
     */
    public function reemit_logger_definitions(array $definitions, Resource $resource)
    {
        if ($resource->name() !== 'logger') {
            return $definitions;
        }

        /**
         * Filter the EWP Logger ability definitions before registration.
         *
         * @param array $definitions Ability definitions keyed by ability name.
         *
         * @since 1.3.0
         */
        return apply_filters('ewp_logger_ability_definitions', $definitions);
    }

    /**
     * Keep the `ewp_abilities_capability` filter working for kit-registered abilities.
     *
     * @param mixed     $resolved Resolved capability.
     * @param Operation $op       Operation.
     * @param array     $input    Normalised input.
     * @param Context   $ctx      Invocation context.
     *
     * @return mixed
     *
     * @since 1.5.0
     */
    public function reemit_ability_capability($resolved, Operation $op, array $input, Context $ctx)
    {
        if ($ctx->surface() !== Context::ABILITY || !is_string($resolved)) {
            return $resolved;
        }

        $resource = $op->resource();
        $name     = $resource !== null && $resource->ability_category() !== null
            ? $resource->ability_category() . '/' . $op->ability_slug()
            : '';

        return apply_filters('ewp_abilities_capability', $resolved, $name);
    }
}

EWP_Surfaces::instance()->init();
