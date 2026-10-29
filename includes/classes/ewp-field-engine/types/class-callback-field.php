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
 * Case `function`: a message box whose body is the output of the field's
 * `callback`, called with the object id.
 *
 * Renders nothing (the row wrapper stays) when the callback is not a defined
 * function. Markup lives in templates/fields/message-box.php.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Callback_Field implements Field_Type
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
        return 'function';
    }

    /**
     * Render the callback's output in a message box.
     *
     * @param Field_Context $context Resolved field state.
     * @return Field_Output
     */
    public function render(Field_Context $context)
    {
        $callback = $context->field('callback');
        if (!is_string($callback) || !function_exists($callback)) {
            return new Field_Output('');
        }
        $body = call_user_func_array($callback, array($context->object_id()));
        return new Field_Output($this->templates->render('fields/message-box', Message_Field::variables($context, (string) $body)));
    }
}
