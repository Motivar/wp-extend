<?php

namespace EWP\Fields\Types;

use EWP\Fields\Field_Context;
use EWP\Fields\Field_Output;
use EWP\Fields\Field_Type;
use EWP\Fields\Template_Loader;
use EWP\Fields\Media_Field_Renderer;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Case `awm_gallery`: a message box holding the unlimited media field.
 *
 * Markup lives in templates/fields/message-box.php and templates/fields/media.php.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Gallery_Field implements Field_Type
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
        return 'awm_gallery';
    }

    /**
     * Render the gallery.
     *
     * @param Field_Context $context Resolved field state.
     * @return Field_Output
     */
    public function render(Field_Context $context)
    {
        Media_Field_Renderer::enqueue();
        $media = new Media_Field_Renderer($this->templates);
        $html  = $this->templates->render('fields/message-box', array(
            'id'          => $context->id(),
            'label'       => (string) $context->field('label', ''),
            'explanation' => $context->explanation(),
            'body'        => $media->render($context->name(), self::ids($context->value()), 0),
            'inner'       => false,
        ));
        return new Field_Output($html);
    }

    /**
     * Gallery value as a list: empty → none, scalar → one.
     *
     * @param mixed $value Resolved value.
     * @return array
     */
    public static function ids($value)
    {
        if (empty($value)) {
            return array();
        }
        return is_array($value) ? $value : array($value);
    }
}
