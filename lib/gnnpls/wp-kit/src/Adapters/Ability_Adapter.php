<?php
/**
 * Registers a Resource's operations with the WordPress Abilities API.
 *
 * @package Gnnpls\WP
 * @since   0.1.0
 */

namespace Gnnpls\WP\Adapters;

use Gnnpls\WP\Confirm;
use Gnnpls\WP\Context;
use Gnnpls\WP\Field_Map;
use Gnnpls\WP\Operation;
use Gnnpls\WP\Resource;

if (!defined('ABSPATH')) {
    exit;
}

final class Ability_Adapter
{
    /** @var Resource */
    private $resource;
    /** @var callable|null */
    private $supported;

    /**
     * @param Resource      $resource  Resource to expose.
     * @param callable|null $supported Returns false to skip registration (host gate).
     */
    public function __construct(Resource $resource, ?callable $supported = null)
    {
        $this->resource  = $resource;
        $this->supported = $supported;
    }

    /**
     * Hook category and ability registration.
     *
     * @return void
     *
     * @since 0.1.0
     */
    public function register()
    {
        if (!function_exists('wp_register_ability') || $this->resource->ability_category() === null) {
            return;
        }

        add_action('wp_abilities_api_categories_init', [$this, 'register_category'], 20);
        add_action('wp_abilities_api_init', [$this, 'register_abilities']);
    }

    /**
     * Register the category unless another module already did.
     *
     * @return void
     *
     * @since 0.1.0
     */
    public function register_category()
    {
        if (!$this->is_supported()) {
            return;
        }

        $slug = $this->resource->ability_category();
        if (function_exists('wp_has_ability_category') && wp_has_ability_category($slug)) {
            return;
        }

        wp_register_ability_category($slug, $this->resource->ability_category_args());
    }

    /**
     * Register one ability per ability-enabled operation.
     *
     * @return void
     *
     * @since 0.1.0
     */
    public function register_abilities()
    {
        if (!$this->is_supported()) {
            return;
        }

        $definitions = [];
        foreach ($this->resource->ops() as $operation) {
            if (!$operation->is_on(Context::ABILITY) || $operation->ability_slug() === null) {
                continue;
            }
            $definitions[$this->resource->ability_category() . '/' . $operation->ability_slug()] = $this->definition($operation);
        }

        /**
         * Filter the ability definitions of one resource before registration.
         *
         * @param array<string,array> $definitions Definitions keyed by ability name.
         * @param Resource            $resource    Owning resource.
         *
         * @since 0.1.0
         */
        $definitions = apply_filters('mwp_ability_definitions', $definitions, $this->resource);

        foreach ($definitions as $name => $args) {
            wp_register_ability($name, $args);
        }
    }

    /**
     * @param Operation $operation Operation.
     *
     * @return array wp_register_ability() arguments.
     */
    private function definition(Operation $operation)
    {
        $fields = $operation->fields();
        if (in_array(Context::ABILITY, $operation->confirm_surfaces(), true)) {
            $fields[] = Confirm::field()->required();
        }

        return [
            'label'               => $operation->label_of(),
            'description'         => $operation->description_of(),
            'category'            => $this->resource->ability_category(),
            'input_schema'        => Field_Map::to_json_schema($fields),
            'output_schema'       => $operation->output_schema(),
            'execute_callback'    => function ($input = null) use ($operation) {
                return $operation->run(is_array($input) ? $input : [], Context::ability($input));
            },
            'permission_callback' => function ($input = null) use ($operation) {
                return $operation->authorize(is_array($input) ? $input : [], Context::ability($input));
            },
            'meta'                => [
                'annotations'  => $operation->annotations_of(),
                'show_in_rest' => true,
            ],
        ];
    }

    /**
     * @return bool
     */
    private function is_supported()
    {
        return $this->supported === null || (bool) call_user_func($this->supported);
    }
}
