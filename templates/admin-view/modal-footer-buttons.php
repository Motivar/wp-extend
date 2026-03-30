<?php
/**
 * Modal Footer Buttons Template
 *
 * Renders the default Save/Cancel buttons for modal footers.
 * Can be filtered via 'awm_modal_footer_buttons_html' to customize button output.
 *
 * Variables:
 * @var array  $save_button_classes  CSS classes for save button
 * @var string $save_text            Save button text
 * @var array  $cancel_button_classes CSS classes for cancel button
 * @var string $cancel_text          Cancel button text
 * @var string $modal_id             Modal identifier
 * @var array  $args                 Field arguments
 *
 * @package ExtendWP
 * @since   1.2.0
 */

if (!defined('ABSPATH')) {
	exit;
}
?>

<button type="button" class="<?php echo esc_attr(implode(' ', $save_button_classes)); ?>">
	<?php echo esc_html($save_text); ?>
</button>
<button type="button" class="<?php echo esc_attr(implode(' ', $cancel_button_classes)); ?>">
	<?php echo esc_html($cancel_text); ?>
</button>
