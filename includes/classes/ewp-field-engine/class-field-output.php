<?php

namespace EWP\Fields;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What a Field_Type hands back to awm_show_content(): the field's markup
 * plus its effect on the view's row wrapper. Immutable; the `with*`
 * methods return copies.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Field_Output
{
    /** @var string Field markup. */
    private $html;

    /** @var bool Wrap the markup in the view's row wrapper. */
    private $wrapper = true;

    /** @var string[] Extra classes for the row wrapper. */
    private $wrapper_classes = array();

    /** @var string[] Extra attribute markup (`key="value"`, already escaped) for the row wrapper. */
    private $wrapper_attributes = array();

    /** @var bool Render nothing for this field (not even the wrapper). */
    private $suppressed = false;

    /** @var bool Emit the automatic `awm_custom_meta[]` save marker. */
    private $meta_marker = true;

    /**
     * @param string $html Field markup.
     */
    public function __construct($html)
    {
        $this->html = (string) $html;
    }

    /**
     * Copy that renders bare, without the row wrapper (e.g. hidden inputs).
     *
     * @return self
     */
    public function without_wrapper()
    {
        $copy          = clone $this;
        $copy->wrapper = false;
        return $copy;
    }

    /**
     * Copy with extra row-wrapper classes.
     *
     * @param string[] $classes Class names.
     * @return self
     */
    public function with_wrapper_classes(array $classes)
    {
        $copy                  = clone $this;
        $copy->wrapper_classes = array_merge($copy->wrapper_classes, $classes);
        return $copy;
    }

    /**
     * Copy with extra row-wrapper attributes.
     *
     * @param string[] $attributes Escaped `key="value"` markup per attribute.
     * @return self
     */
    public function with_wrapper_attributes(array $attributes)
    {
        $copy                     = clone $this;
        $copy->wrapper_attributes = array_merge($copy->wrapper_attributes, $attributes);
        return $copy;
    }

    /**
     * Copy that renders nothing (e.g. a select without options in list-table filters).
     *
     * @return self
     */
    public function suppressed()
    {
        $copy             = clone $this;
        $copy->suppressed = true;
        return $copy;
    }

    /**
     * Copy without the automatic `awm_custom_meta[]` save marker (the field
     * saves elsewhere, or emits its own markers).
     *
     * @return self
     */
    public function without_meta_marker()
    {
        $copy              = clone $this;
        $copy->meta_marker = false;
        return $copy;
    }

    /**
     * Field markup.
     *
     * @return string
     */
    public function html()
    {
        return $this->html;
    }

    /**
     * Whether the row wrapper is added.
     *
     * @return bool
     */
    public function has_wrapper()
    {
        return $this->wrapper;
    }

    /**
     * Extra row-wrapper classes.
     *
     * @return string[]
     */
    public function wrapper_classes()
    {
        return $this->wrapper_classes;
    }

    /**
     * Extra row-wrapper attribute markup.
     *
     * @return string[]
     */
    public function wrapper_attributes()
    {
        return $this->wrapper_attributes;
    }

    /**
     * Whether the field renders nothing.
     *
     * @return bool
     */
    public function is_suppressed()
    {
        return $this->suppressed;
    }

    /**
     * Whether awm_show_content() adds the `awm_custom_meta[]` save marker.
     *
     * @return bool
     */
    public function has_meta_marker()
    {
        return $this->meta_marker;
    }
}
