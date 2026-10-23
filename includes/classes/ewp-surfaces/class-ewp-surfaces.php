<?php

namespace EWP\Surfaces;

use EWP\Content\Content_Service;
use EWP\Surfaces\Resources\Content_Resource;
use EWP\Surfaces\Resources\Content_Type_Rest_Resource;
use EWP\Surfaces\Resources\Content_Portability_Resource;
use EWP\Surfaces\Resources\Object_Search_Resource;
use EWP\Surfaces\Resources\Block_Preview_Resource;
use EWP\Surfaces\Resources\Field_Builder_Resource;
use EWP\Surfaces\Resources\Fields_Resource;
use EWP\Surfaces\Resources\Options_Resource;
use EWP\Surfaces\Resources\System_Resource;
use EWP\Surfaces\Resources\Search_Resource;
use EWP\Surfaces\Resources\WP_Content_Resource;
use MammothWP\WpKit\Adapters\Ability_Adapter;
use MammothWP\WpKit\Adapters\Cli_Adapter;
use MammothWP\WpKit\Adapters\Rest_Adapter;
use MammothWP\WpKit\Context;
use MammothWP\WpKit\Kit;
use MammothWP\WpKit\Operation;
use MammothWP\WpKit\Registry;
use MammothWP\WpKit\Resource;

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
        Kit::on_ready([$this, 'attach']);
        add_filter('mmwp_kit_operation_capability', [$this, 'reemit_ability_capability'], 10, 4);
    }

    /**
     * Defer building the registry until a surface actually needs it.
     *
     * Declaring the resources loads ~25 classes and instantiates every
     * service; a front-end or plain admin request uses none of them. The
     * registry is built on the first REST server, the first Abilities API
     * initialisation, under WP-CLI, or when registry() is called.
     *
     * Every trigger runs at priority -1 so the adapters' own callbacks
     * (priority 10/20 on the same actions) still run within that action.
     *
     * @return void
     *
     * @since 1.5.1
     */
    public function attach()
    {
        if (defined('WP_CLI') && WP_CLI) {
            $this->boot();
            return;
        }

        add_action('rest_api_init', [$this, 'boot'], -1);
        add_action('wp_abilities_api_categories_init', [$this, 'boot'], -1);
        add_action('wp_abilities_api_init', [$this, 'boot'], -1);
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
         * The resource classes extend MammothWP\WpKit\Resource, which only
         * autoloads once the kit has booted (plugins_loaded -100), so they
         * are loaded here rather than when Setup.php requires this file.
         */
        // Skipped by Setup.php on front-end requests; needed here whenever
        // the registry boots (e.g. an abilities call from a front-end request).
        require_once dirname(__DIR__) . '/awm-api/class-awm-api.php';
        require_once dirname(__DIR__) . '/ewp-options-portability/class-options-portability.php';
        require_once __DIR__ . '/class-content-schema.php';
        require_once __DIR__ . '/class-library-fields.php';
        require_once __DIR__ . '/class-field-vocabulary.php';
        require_once __DIR__ . '/resources/class-content-resource.php';
        require_once __DIR__ . '/resources/class-content-type-rest-resource.php';
        require_once __DIR__ . '/resources/class-typed-content-resource.php';
        require_once __DIR__ . '/resources/class-fields-resource.php';
        require_once __DIR__ . '/resources/class-wp-content-resource.php';
        require_once __DIR__ . '/resources/class-search-resource.php';
        require_once __DIR__ . '/class-system-service.php';
        require_once __DIR__ . '/resources/class-system-resource.php';
        require_once __DIR__ . '/resources/class-options-resource.php';
        require_once dirname(__DIR__) . '/ewp-content/class-content-portability.php';
        require_once dirname(__DIR__) . '/ewp-search/class-object-search.php';
        require_once __DIR__ . '/resources/class-content-portability-resource.php';
        require_once __DIR__ . '/resources/class-object-search-resource.php';
        require_once __DIR__ . '/resources/class-field-builder-resource.php';
        require_once __DIR__ . '/resources/class-recently-seen-resource.php';
        require_once __DIR__ . '/resources/class-block-preview-resource.php';

        $this->service = new Content_Service();
        $this->registry = new Registry();

        /**
         * Filter the resources Extend WP exposes on REST, WP-CLI and the
         * Abilities API.
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
            'options'    => new Options_Resource(),
            'system'     => new System_Resource(new System_Service($this->service)),
            'content-portability' => new Content_Portability_Resource(new \EWP\Content\Content_Portability()),
            'objects'    => new Object_Search_Resource(new \EWP\Search\Object_Search()),
            'field-builder' => new Field_Builder_Resource(new \AWM_API()),
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
        $rest = new Rest_Adapter($resource);
        if (doing_action('rest_api_init')) {
            // The registry is being built by the priority -1 trigger: the
            // adapter's hook fires later in this same action, so calling
            // register() (which also registers immediately because the
            // action already started) would add every route twice.
            add_action('rest_api_init', [$rest, 'register_routes']);
        } else {
            $rest->register();
        }
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
     * Register the editor preview route of one dynamic block.
     *
     * Called by EWP_Dynamic_Blocks on `rest_api_init` for every gathered
     * block, and by test fixtures for blocks declared on demand.
     *
     * @param array               $block  Block definition from gather_blocks().
     * @param \EWP_Dynamic_Blocks $blocks Block registry that renders it.
     *
     * @return void
     *
     * @since 1.5.0
     */
    public function register_block_preview_routes(array $block, \EWP_Dynamic_Blocks $blocks)
    {
        $registry = $this->registry();
        if ($registry === null || empty($block['namespace']) || empty($block['name'])) {
            return;
        }

        $resource = new Block_Preview_Resource($block, $blocks);
        $registry->add($resource);
        (new Rest_Adapter($resource))->register();
    }

    /**
     * A callable that runs one operation with WP-CLI style arguments, for
     * in-process invocation by tests and the self-test suite.
     *
     * @param string $resource  Resource name.
     * @param string $operation Operation key.
     *
     * @return callable fn(array $args, array $assoc_args)
     *
     * @since 1.5.0
     */
    public static function cli($resource, $operation)
    {
        return function ($args = [], $assoc_args = []) use ($resource, $operation) {
            $registry = self::instance()->registry();
            $found    = $registry ? $registry->find($resource, $operation) : null;

            if ($found === null) {
                \WP_CLI::error(sprintf('Unknown operation %s/%s.', $resource, $operation));
                return null;
            }

            return \MammothWP\WpKit\Adapters\Cli_Adapter::invoke($found, (array) $args, (array) $assoc_args);
        };
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
