<?php

namespace EWP\Fields\Types;

use EWP\Fields\Field_Context;
use EWP\Fields\Field_Output;
use EWP\Fields\Field_Type;
use EWP\Fields\Template_Loader;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Case `input` (text, number, checkbox, time, password, email, hidden, …).
 *
 * Normalises the value per input type; markup lives in
 * templates/fields/input.php and templates/fields/input-hidden.php.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Input_Field implements Field_Type
{
    /** @var Template_Loader Template resolver. */
    private $templates;

    /**
     * @param Template_Loader $templates Template resolver.
     */
    public function __construct(Template_Loader $templates)
    {
        $this->templates = $templates;
    }

    /**
     * Field case handled.
     *
     * @return string
     */
    public function case_name()
    {
        return 'input';
    }

    /**
     * Render the input; hidden inputs render bare, without the row wrapper.
     *
     * @param Field_Context $context Resolved field state.
     * @return Field_Output
     */
    public function render(Field_Context $context)
    {
        $type = self::input_type($context);
        if ('hidden' === $type) {
            $html = $this->templates->render('fields/input-hidden', array('context' => $context));
            return (new Field_Output($html))->without_wrapper();
        }

        return new Field_Output($this->templates->render('fields/input', array(
            'context'       => $context->with_value(self::normalise_value($type, $context->value())),
            'type'          => $type,
            'checked'       => 'checkbox' === $type && 1 == $context->value(),
            'after_message' => (string) $context->field('after_message', ''),
            'encrypt'       => awm_should_encrypt_field($context->field()),
        )));
    }

    /**
     * The input's `type` attribute.
     *
     * @param Field_Context $context Resolved field state.
     * @return string
     */
    private static function input_type(Field_Context $context)
    {
        return (string) $context->field('type', '');
    }

    /**
     * Normalise a value for an input type: number → int, checkbox → 1
     * (checked state is separate), time → `H:i` when stored as a date.
     *
     * @param string $type  Input type.
     * @param mixed  $value Resolved value.
     * @return mixed
     */
    public static function normalise_value($type, $value)
    {
        switch ($type) {
            case 'number':
                return (int) $value;
            case 'checkbox':
                return 1;
            case 'time':
                return self::normalise_time($value);
            default:
                return $value;
        }
    }

    /**
     * Convert a stored date/timestamp string to `H:i`; values already in
     * time format (or empty) pass through.
     *
     * @param mixed $value Stored value.
     * @return mixed
     */
    private static function normalise_time($value)
    {
        if (empty($value) || strpos((string) $value, ':') !== false) {
            return $value;
        }
        return gmdate('H:i', strtotime($value));
    }
}
