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
 * Case `map`: a Google-map picker; the value is `array( lat, lng, address )`.
 *
 * The row wrapper gets `awm-cls-100`. Markup lives in templates/fields/map.php.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Map_Field implements Field_Type
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
        return 'map';
    }

    /**
     * Render the map picker.
     *
     * @param Field_Context $context Resolved field state.
     * @return Field_Output
     */
    public function render(Field_Context $context)
    {
        $value = self::normalise($context->value());
        $html  = $this->templates->render('fields/map', array(
            'meta'     => $context->name(),
            'id'       => $context->id(),
            'lat'      => $value['lat'],
            'lng'      => $value['lng'],
            'address'  => $value['address'],
            'required' => $context->required(),
        ));
        return (new Field_Output($html))->with_wrapper_classes(array('awm-cls-100'));
    }

    /**
     * Pick lat, lng and address from a stored value; missing parts are ''.
     *
     * @param mixed $value Stored value.
     * @return array{lat:string, lng:string, address:string}
     */
    public static function normalise($value)
    {
        $value = is_array($value) ? $value : array();
        return array(
            'lat'     => !empty($value['lat']) ? $value['lat'] : '',
            'lng'     => !empty($value['lng']) ? $value['lng'] : '',
            'address' => !empty($value['address']) ? $value['address'] : '',
        );
    }
}
