<?php

namespace EWP\Fields;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolved, render-ready state of one field.
 *
 * Built by awm_show_content() after the shared preparation every field goes
 * through (value lookup, default, id override, attributes, required flag) and
 * handed to a Field_Type. Immutable: field types derive a copy with
 * with_value() instead of mutating it.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Field_Context
{
    /** @var string Input name (the field's meta key). */
    private $name;

    /** @var string DOM id (attributes.id override, else the meta key). */
    private $id;

    /** @var mixed Resolved value (stored, attribute value or default). */
    private $value;

    /** @var array Prepared field definition (awm_prepare_field() output). */
    private $field;

    /** @var array<string, string> Extra HTML attributes, unescaped (Field_Attributes::prepare()). */
    private $attributes;

    /** @var string Space-separated input classes. */
    private $classes;

    /** @var bool Whether the input carries required="true". */
    private $required;

    /** @var string View: post|term|user|option|widget|restrict_manage_posts|none. */
    private $view;

    /** @var mixed Object id (post/term/user id, 0, or the widget instance array). */
    private $object_id;

    /**
     * @param string $name       Input name (meta key).
     * @param string $id         DOM id.
     * @param mixed  $value      Resolved value.
     * @param array  $field      Prepared field definition.
     * @param array  $attributes Extra HTML attributes, key => unescaped value.
     * @param string $classes    Space-separated input classes.
     * @param bool   $required   Whether the field is required.
     * @param string $view       Render view (see $view property).
     * @param mixed  $object_id  Object id the field renders against.
     */
    public function __construct($name, $id, $value, array $field, array $attributes, $classes, $required, $view, $object_id)
    {
        $this->name       = (string) $name;
        $this->id         = (string) $id;
        $this->value      = $value;
        $this->field      = $field;
        $this->attributes = $attributes;
        $this->classes    = (string) $classes;
        $this->required   = (bool) $required;
        $this->view       = (string) $view;
        $this->object_id  = $object_id;
    }

    /**
     * Copy of this context with a different value.
     *
     * @param mixed $value New value.
     * @return self
     */
    public function with_value($value)
    {
        $copy        = clone $this;
        $copy->value = $value;
        return $copy;
    }

    /**
     * Input name.
     *
     * @return string
     */
    public function name()
    {
        return $this->name;
    }

    /**
     * DOM id.
     *
     * @return string
     */
    public function id()
    {
        return $this->id;
    }

    /**
     * Resolved value.
     *
     * @return mixed
     */
    public function value()
    {
        return $this->value;
    }

    /**
     * Prepared field definition, or one key of it.
     *
     * @param string|null $key     Key to read; null returns the whole definition.
     * @param mixed       $default Returned when the key is not set.
     * @return mixed
     */
    public function field($key = null, $default = null)
    {
        if (null === $key) {
            return $this->field;
        }
        return isset($this->field[$key]) ? $this->field[$key] : $default;
    }

    /**
     * Extra HTML attributes, unescaped.
     *
     * @return array<string, string>
     */
    public function attributes()
    {
        return $this->attributes;
    }

    /**
     * Space-separated input classes.
     *
     * @return string
     */
    public function classes()
    {
        return $this->classes;
    }

    /**
     * Whether the field is required.
     *
     * @return bool
     */
    public function required()
    {
        return $this->required;
    }

    /**
     * Render view.
     *
     * @return string
     */
    public function view()
    {
        return $this->view;
    }

    /**
     * Object id the field renders against.
     *
     * @return mixed
     */
    public function object_id()
    {
        return $this->object_id;
    }
}
