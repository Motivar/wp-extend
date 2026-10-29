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
 * Case `message`: a read-only box with the field's `value` as its body.
 *
 * Renders nothing (the row wrapper stays) when the value is empty. Markup
 * lives in templates/fields/message-box.php.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Message_Field implements Field_Type
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
        return 'message';
    }

    /**
     * Render the message box.
     *
     * @param Field_Context $context Resolved field state.
     * @return Field_Output
     */
    public function render(Field_Context $context)
    {
        $body = $context->field('value');
        if (empty($body)) {
            return new Field_Output('');
        }
        return new Field_Output($this->templates->render('fields/message-box', self::variables($context, (string) $body)));
    }

    /**
     * Template variables of a message box with an inner body.
     *
     * @param Field_Context $context Resolved field state.
     * @param string        $body    Body HTML.
     * @return array
     */
    public static function variables(Field_Context $context, $body)
    {
        return array(
            'id'          => $context->id(),
            'label'       => (string) $context->field('label', ''),
            'explanation' => $context->explanation(),
            'body'        => $body,
            'inner'       => true,
        );
    }
}
