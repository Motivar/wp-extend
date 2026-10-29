<?php

namespace EWP\Fields\Types;

use EWP\Fields\Field_Context;
use EWP\Fields\Field_Attributes;
use EWP\Fields\Field_Output;
use EWP\Fields\Field_Type;
use EWP\Fields\Template_Loader;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Case `button`: a link styled as a button (`link`, default `#`).
 *
 * The element id is the meta key without leading underscores. Markup lives in
 * templates/fields/button.php.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Button_Field implements Field_Type
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
        return 'button';
    }

    /**
     * Render the button link.
     *
     * @param Field_Context $context Resolved field state.
     * @return Field_Output
     */
    public function render(Field_Context $context)
    {
        return new Field_Output($this->templates->render('fields/button', array(
            'id'         => ltrim($context->name(), '_'),
            'link'       => (string) $context->field('link', '#'),
            'label'      => (string) $context->field('label', ''),
            'classes'    => $context->classes(),
            'attributes' => Field_Attributes::render($context->attributes()),
        )));
    }
}
