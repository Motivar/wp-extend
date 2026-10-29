<?php
/**
 * Field template: case `awm_modal` (trigger button; the modal itself is
 * built by the admin script).
 *
 * Override in a theme at `extend-wp/fields/modal.php`. Lines end in `?>` so
 * PHP drops the newline: the markup must not gain whitespace text nodes.
 *
 * @var array $variables See \EWP\Fields\Types\Modal_Field::button_args().
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="awm-modal-field-wrap" id="awm-modal-wrap-<?php echo esc_attr($variables['modal_id']); ?>"><button type="button" class="awm-modal-trigger <?php echo esc_attr($variables['button_class']); ?>" data-modal-id="<?php echo esc_attr($variables['modal_id']); ?>" data-meta-key="<?php echo esc_attr($variables['meta_key']); ?>" data-view="<?php echo esc_attr($variables['view']); ?>" data-object-id="<?php echo esc_attr($variables['object_id']); ?>" data-modal-title="<?php echo esc_attr($variables['title']); ?>"<?php
if ('' !== $variables['option_page']) :
?> data-option-page="<?php echo esc_attr($variables['option_page']); ?>"<?php
endif;
?>><?php echo esc_html($variables['button_label']); ?></button></div><?php
