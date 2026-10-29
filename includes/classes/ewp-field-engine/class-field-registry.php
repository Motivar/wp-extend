<?php

namespace EWP\Fields;

use EWP\Fields\Types\Checkbox_Multiple_Field;
use EWP\Fields\Types\Input_Field;
use EWP\Fields\Types\Radio_Field;
use EWP\Fields\Types\Select_Field;
use EWP\Fields\Types\Textarea_Field;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Field `case` => Field_Type map used by awm_show_content().
 *
 * Cases without a registered type still render through the legacy switch in
 * library.php while they are migrated. Built lazily on first lookup: the
 * defaults register first, then `ewp_register_field_types` lets other plugins
 * add or replace types.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Field_Registry
{
    /** @var self|null Shared instance used by the procedural API. */
    private static $instance;

    /** @var array<string, Field_Type> Types per case. */
    private $types = array();

    /** @var bool Defaults registered and the action fired. */
    private $booted = false;

    /** @var Template_Loader Loader handed to the default types. */
    private $templates;

    /**
     * @param Template_Loader|null $templates Loader for the default types (default: plugin templates).
     */
    public function __construct(?Template_Loader $templates = null)
    {
        $this->templates = $templates ?: new Template_Loader();
    }

    /**
     * Shared instance.
     *
     * @return self
     */
    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Drop the shared instance (tests, or after changing template filters).
     *
     * @return void
     */
    public static function reset()
    {
        self::$instance = null;
    }

    /**
     * Register or replace the type for its case.
     *
     * @param Field_Type $type Type to register.
     * @return void
     */
    public function register(Field_Type $type)
    {
        $this->types[$type->case_name()] = $type;
    }

    /**
     * Type registered for a case.
     *
     * @param string $case Field case.
     * @return Field_Type|null Null when the case still uses the legacy renderer.
     */
    public function get($case)
    {
        $this->boot();
        return isset($this->types[$case]) ? $this->types[$case] : null;
    }

    /**
     * Register the default types once, then let other code add theirs.
     *
     * @return void
     */
    private function boot()
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        $this->register(new Input_Field($this->templates));
        $this->register(new Textarea_Field($this->templates));
        $this->register(new Select_Field($this->templates));
        $this->register(new Radio_Field($this->templates));
        $this->register(new Checkbox_Multiple_Field($this->templates));

        /**
         * Register or replace field types rendered by awm_show_content().
         *
         * @since 1.11.0
         *
         * @param Field_Registry $registry Call `$registry->register( new My_Type() )`.
         */
        do_action('ewp_register_field_types', $this);
    }
}
