<?php

/**
 * The Template for displayin input field checkbox_multiple
 */
if (!defined('ABSPATH')) {
 exit; // Exit if accessed directly
}

global $ewp_input_vars;

/*'<div class="awm-multiple-checkbox"><div class="insider"><label id="label_' . $input_id . '" for="' . $input_id . '" class="awm-input-label ' . $chk_label_class . '" ><input type="checkbox" name="' . $value_name . '" id="' . $input_id . '" value="' . $valueInside . '" ' . $extraa . $chk_ex . ' class="' . $class . '"' . $extraLabel . ' data-value="' . $dlm . '"/><span>' . $dlmm['label'] . '</span></label></div></div>'*/


?>
<div class="awm-multiple-checkbox">
 <div class="insider"><label id="label_<?php echo esc_attr($ewp_input_vars['input_id']); ?>" for="<?php echo esc_attr($ewp_input_vars['input_id']); ?>" class="awm-input-label <?php echo esc_attr($ewp_input_vars['chk_label_class']); ?>"><input type="checkbox" name="<?php echo esc_attr($ewp_input_vars['value_name']); ?>" id="<?php echo esc_attr($ewp_input_vars['input_id']); ?>" value="<?php echo esc_attr($ewp_input_vars['valueInside']); ?>" <?php echo $ewp_input_vars['extraa']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute pairs escaped with esc_attr() in awm_show_content(). ?> <?php echo $ewp_input_vars['chk_ex']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static ' checked' or ''. ?> class="<?php echo esc_attr($ewp_input_vars['class']); ?>" <?php echo $ewp_input_vars['extraLabel']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- data-extra built with esc_attr() in awm_show_content(). ?> data-value="<?php echo esc_attr($ewp_input_vars['dlm']); ?>" /><span><?php echo wp_kses_post($ewp_input_vars['dlmm']['label']); ?></span></label>
 </div>
</div>