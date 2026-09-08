<?php

namespace EWP\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base class for every Extend WP ability provider.
 *
 * A provider owns one ability category and declares its abilities as a single
 * definition table, so schemas and handlers cannot drift apart. Shared
 * concerns — category registration, capability checks, annotation metadata,
 * input normalisation and destructive-action confirmation — live here.
 *
 * @package    EWP\Abilities
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.4.0
 */
abstract class EWP_Abilities_Provider
{
    /**
     * Ability category slug owned by this provider.
     *
     * @return string
     *
     * @since 1.4.0
     */
    abstract public function category();

    /**
     * Category registration arguments.
     *
     * @return array
     *
     * @since 1.4.0
     */
    abstract public function category_args();

    /**
     * Ability definitions keyed by ability name.
     *
     * @return array
     *
     * @since 1.4.0
     */
    abstract public function get_definitions();

    /**
     * Register hooks.
     *
     * @return void
     *
     * @since 1.4.0
     */
    public function init()
    {
        add_action('wp_abilities_api_categories_init', [$this, 'register_category'], $this->category_priority());
        add_action('wp_abilities_api_init', [$this, 'register_abilities']);
    }

    /**
     * Priority used when registering the category.
     *
     * Providers that share a category with another module raise this so the
     * owning module registers first.
     *
     * @return int
     *
     * @since 1.4.0
     */
    protected function category_priority()
    {
        return 10;
    }

    /**
     * Register the ability category, unless another module already did.
     *
     * @return void
     *
     * @since 1.4.0
     */
    public function register_category()
    {
        if (!EWP_Abilities::is_enabled()) {
            return;
        }

        if (wp_has_ability_category($this->category())) {
            return;
        }

        wp_register_ability_category($this->category(), $this->category_args());
    }

    /**
     * Register every ability declared by this provider.
     *
     * @return void
     *
     * @since 1.4.0
     */
    public function register_abilities()
    {
        if (!EWP_Abilities::is_enabled()) {
            return;
        }

        if (!wp_has_ability_category($this->category())) {
            return;
        }

        foreach ($this->definitions() as $name => $args) {
            wp_register_ability($name, $args);
        }
    }

    /**
     * Return the filtered ability definitions.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function definitions()
    {
        $definitions = $this->get_definitions();

        /**
         * Filter one provider's ability definitions before registration.
         *
         * The dynamic portion of the hook name is the ability category slug,
         * for example `ewp_abilities_ewp-fields_definitions`.
         *
         * @param array                   $definitions Definitions keyed by ability name.
         * @param EWP_Abilities_Provider  $provider    The provider instance.
         *
         * @since 1.4.0
         */
        $definitions = apply_filters('ewp_abilities_' . $this->category() . '_definitions', $definitions, $this);

        return is_array($definitions) ? $definitions : [];
    }

    /* ---------------------------------------------------------------------
     * Definition helpers
     * ------------------------------------------------------------------ */

    /**
     * Build one ability definition.
     *
     * @param string   $label         Human readable label.
     * @param string   $description   What the ability does and when to use it.
     * @param array    $input_schema  JSON schema for the input.
     * @param array    $output_schema JSON schema for the output.
     * @param callable $callback      Execute callback.
     * @param callable $permission    Permission callback.
     * @param array    $meta          Ability meta, usually from a meta_* helper.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function definition($label, $description, $input_schema, $output_schema, $callback, $permission, $meta)
    {
        return [
            'label'               => $label,
            'description'         => $description,
            'category'            => $this->category(),
            'input_schema'        => $input_schema,
            'output_schema'       => $output_schema,
            'execute_callback'    => $callback,
            'permission_callback' => $permission,
            'meta'                => $meta,
        ];
    }

    /**
     * Annotations for an ability that only reads data.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function meta_readonly()
    {
        return [
            'annotations'  => [
                'readonly'   => true,
                'idempotent' => true,
                'destructive' => false,
            ],
            'show_in_rest' => true,
        ];
    }

    /**
     * Annotations for an ability that writes but does not destroy data.
     *
     * @param bool $idempotent Whether repeating the call has the same effect.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function meta_write($idempotent = false)
    {
        return [
            'annotations'  => [
                'readonly'    => false,
                'idempotent'  => (bool) $idempotent,
                'destructive' => false,
            ],
            'show_in_rest' => true,
        ];
    }

    /**
     * Annotations for an ability that deletes or overwrites data.
     *
     * @param bool $idempotent Whether repeating the call has the same effect.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function meta_destructive($idempotent = true)
    {
        return [
            'annotations'  => [
                'readonly'    => false,
                'idempotent'  => (bool) $idempotent,
                'destructive' => true,
            ],
            'show_in_rest' => true,
        ];
    }

    /* ---------------------------------------------------------------------
     * Runtime helpers
     * ------------------------------------------------------------------ */

    /**
     * Return a permission callback enforcing a single capability.
     *
     * @param string $capability    Capability required.
     * @param string $ability_name  Ability the callback belongs to.
     *
     * @return callable
     *
     * @since 1.4.0
     */
    protected function capability_permission($capability, $ability_name = '')
    {
        return function () use ($capability, $ability_name) {
            return $this->user_can($capability, $ability_name);
        };
    }

    /**
     * Check one capability and return an ability-friendly result.
     *
     * @param string $capability   Capability required.
     * @param string $ability_name Ability being checked.
     *
     * @return true|\WP_Error
     *
     * @since 1.4.0
     */
    protected function user_can($capability, $ability_name = '')
    {
        /**
         * Filter the capability required by an Extend WP ability.
         *
         * @param string $capability   The capability about to be checked.
         * @param string $ability_name The ability name, when known.
         *
         * @since 1.4.0
         */
        $capability = apply_filters('ewp_abilities_capability', $capability, $ability_name);

        if (!current_user_can($capability)) {
            return $this->error(
                'ewp_abilities_forbidden',
                __('You do not have permission to perform this action.', 'extend-wp'),
                403
            );
        }

        return true;
    }

    /**
     * Coerce ability input into an array.
     *
     * @param mixed $input Raw input.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function normalize_input($input)
    {
        return is_array($input) ? $input : [];
    }

    /**
     * Require an explicit confirmation flag for a destructive call.
     *
     * The Abilities API has no confirmation step of its own, so destructive
     * abilities demand a literal `confirm: true` in the input.
     *
     * @param array $input Normalised input.
     *
     * @return true|\WP_Error
     *
     * @since 1.4.0
     */
    protected function require_confirm(array $input)
    {
        if (!empty($input['confirm']) && $input['confirm'] !== 'false') {
            return true;
        }

        return $this->error(
            'ewp_abilities_confirm_required',
            __('This action permanently changes data. Pass confirm: true to proceed.', 'extend-wp'),
            400
        );
    }

    /**
     * Build a WP_Error with an HTTP status attached.
     *
     * @param string $code    Error code.
     * @param string $message Error message.
     * @param int    $status  HTTP status.
     *
     * @return \WP_Error
     *
     * @since 1.4.0
     */
    protected function error($code, $message, $status = 400)
    {
        return new \WP_Error($code, $message, ['status' => $status]);
    }

    /**
     * Schema fragment for the confirmation flag.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function confirm_property()
    {
        return [
            'confirm' => [
                'type'        => 'boolean',
                'description' => __('Must be true. Guards against accidental destructive calls.', 'extend-wp'),
            ],
        ];
    }
}
