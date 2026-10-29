<?php

namespace EWP\Fields;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One field `case` (input, select, textarea, …): turns a Field_Context into
 * a Field_Output, normally through a template under templates/fields/.
 *
 * Register implementations on the `ewp_register_field_types` action.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
interface Field_Type
{
    /**
     * The field `case` this type renders.
     *
     * @return string
     */
    public function case_name();

    /**
     * Render the field's own markup (label and row wrapper are added by
     * awm_show_content(), shaped by the returned output's wrapper settings).
     *
     * @param Field_Context $context Resolved field state.
     * @return Field_Output
     */
    public function render(Field_Context $context);
}
