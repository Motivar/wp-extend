<?php
/**
 * Field template: case `object_id_filter`.
 *
 * Override in a theme at `extend-wp/fields/object-id-filter.php`. Lines end
 * in `?>` so PHP drops the newline: the markup must not gain whitespace text
 * nodes. The JS module reads `data-config` and the classes/data attributes.
 *
 * @var array $variables {
 *     @type \EWP\Fields\Field_Context $context      Resolved field state.
 *     @type string                    $label        Field label.
 *     @type string                    $js_config    JSON config, double quotes swapped for single (attribute-safe).
 *     @type array                     $type_groups  Per group: `label`, `options` (`value`, `label`, `selected`).
 *     @type string                    $id_field     Meta key of the ids.
 *     @type int                       $min_chars    Minimum search characters.
 *     @type bool                      $disabled     Id select disabled (no type saved yet).
 *     @type array                     $saved_ids    Preloaded options: `id`, `label`.
 *     @type string[]                  $save_markers Meta keys posted as `awm_custom_meta[]` (none in view `none`).
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$context  = $variables['context'];
$id_field = $variables['id_field'];
?>
<div class="awm-object-id-filter-wrap" data-config="<?php echo $variables['js_config']; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON without double quotes. ?>"><div class="awm-object-type-field"><label for="<?php echo esc_attr($context->id()); ?>" class="awm-input-label"><span><?php echo wp_kses_post($variables['label']); ?></span></label><?php echo wp_kses_post($context->explanation()); ?><select name="<?php echo esc_attr($context->name()); ?>" id="<?php echo esc_attr($context->id()); ?>" class="awm-object-type-select" awm-skip-selectr="1"><option value="" data-placeholder="true"><?php echo esc_html__('— Select type —', 'extend-wp'); ?></option><?php
foreach ($variables['type_groups'] as $group) :
?><optgroup label="<?php echo esc_attr($group['label']); ?>"><?php
    foreach ($group['options'] as $option) :
?><option value="<?php echo esc_attr($option['value']); ?>"<?php echo $option['selected'] ? ' selected' : ''; ?>><?php echo esc_html($option['label']); ?></option><?php
    endforeach;
?></optgroup><?php
endforeach;
?></select></div><div class="awm-object-id-field"><label for="<?php echo esc_attr($id_field); ?>" class="awm-input-label"><span><?php esc_html_e('Select objects', 'extend-wp'); ?></span></label><span class="awm-explanation"><?php
/* translators: %d: minimum number of characters before search triggers */
echo esc_html(sprintf(_n('Type at least %d character to search.', 'Type at least %d characters to search.', $variables['min_chars'], 'extend-wp'), $variables['min_chars']));
?></span><select name="<?php echo esc_attr($id_field); ?>[]" id="<?php echo esc_attr($id_field); ?>" multiple class="awm-object-id-search" awm-skip-selectr="1"<?php echo $variables['disabled'] ? ' disabled' : ''; ?> data-object-type-target="<?php echo esc_attr($context->id()); ?>" data-min-chars="<?php echo esc_attr($variables['min_chars']); ?>"><?php
foreach ($variables['saved_ids'] as $option) :
?><option value="<?php echo absint($option['id']); ?>" selected><?php echo esc_html($option['label']); ?></option><?php
endforeach;
?></select></div></div><?php
foreach ($variables['save_markers'] as $meta_key) :
?><input type="hidden" name="awm_custom_meta[]" value="<?php echo esc_attr($meta_key); ?>"/><?php
endforeach;
