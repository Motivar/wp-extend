<?php
/**
 * Field template: action buttons of one `repeater` row (inner markup of
 * `div.awm-actions`; the result is filtered by `awm_show_actions_html`).
 *
 * Override in a theme at `extend-wp/fields/repeater-actions.php`. Lines end
 * in `?>` so PHP drops the newline. The `data-ewp-action` attributes are
 * handled by a delegated listener in the repeater module.
 *
 * @var array $variables {
 *     @type string $item        Row item name used in the remove/add labels.
 *     @type bool   $is_template Clone-source row (also gets the add button).
 *     @type string $data_id     Repeater element id.
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$item = $variables['item'];
?>
<div class="awm-repeater-clone" data-ewp-action="repeater-clone"><span class="awm_action "><?php esc_html_e('Clone', 'extend-wp'); ?></span></div><div class="awm-repeater-move-up" data-ewp-action="repeater-up"><span class="awm_action "><?php esc_html_e('Move up', 'extend-wp'); ?></span></div><div class="awm-repeater-move-down" data-ewp-action="repeater-down"><span class="awm_action "><?php esc_html_e('Move down', 'extend-wp'); ?></span></div><div class="awm-repeater-remove"><span class="awm_action awm-remove" data-ewp-action="repeater-remove"><?php echo wp_kses_post(__('Remove', 'extend-wp') . ' ' . $item); ?></span></div><?php
if ($variables['is_template']) :
?><div class="awm-repeater-add" data-id="<?php echo esc_attr($variables['data_id']); ?>"><span class="awm_action awm-add" data-ewp-action="repeater-add"><?php echo wp_kses_post(__('Add', 'extend-wp') . ' ' . $item); ?></span></div><?php
endif;
