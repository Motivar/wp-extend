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
 * Case `image`: label, explanation and a single-file media field.
 *
 * The row wrapper gets `awm-custom-image-meta`. Markup lives in
 * templates/fields/image.php and templates/fields/media.php.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Image_Field implements Field_Type
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
        return 'image';
    }

    /**
     * Render the image field.
     *
     * @param Field_Context $context Resolved field state.
     * @return Field_Output
     */
    public function render(Field_Context $context)
    {
        Media_Field_Renderer::enqueue();
        $media = new Media_Field_Renderer($this->templates);
        $html  = $this->templates->render('fields/image', array(
            'id'          => $context->id(),
            'label'       => (string) $context->field('label', ''),
            'explanation' => $context->explanation(),
            'media'       => $media->render($context->name(), $context->value(), 1),
        ));
        return (new Field_Output($html))->with_wrapper_classes(array('awm-custom-image-meta'));
    }
}
