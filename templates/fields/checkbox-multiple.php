<?php
/**
 * Field template: case `checkbox_multiple` (title, explanation and options).
 *
 * Override in a theme at `extend-wp/fields/checkbox-multiple.php`. Lines end in
 * `?>` so PHP drops the newline. The single spaces inside each option block are
 * the whitespace the legacy `checkbox_multiple.php` template produced; they are
 * kept so the markup stays identical.
 *
 * @var array $variables {
 *     @type \EWP\Fields\Field_Context $context Resolved field state.
 *     @type array                     $rows    Options: `id`, `name`, `value`, `data_value`, `checked`,
 *                                              `attributes` (escaped), `extra` (escaped), `label`.
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$context = $variables['context'];
?>
<label class="awm-checkboxes-title"><span><?php echo wp_kses_post($context->field('label', '')); ?></span></label><?php echo wp_kses_post($context->explanation()); ?><div class="awm-options-wrapper"><?php
foreach ($variables['rows'] as $row) :
?><div class="awm-multiple-checkbox"> <div class="insider"><label id="label_<?php echo esc_attr($row['id']); ?>" for="<?php echo esc_attr($row['id']); ?>" class="awm-input-label <?php echo $row['checked'] ? 'selected' : ''; ?>"><input type="checkbox" name="<?php echo esc_attr($row['name']); ?>" id="<?php echo esc_attr($row['id']); ?>" value="<?php echo esc_attr($row['value']); ?>" <?php echo $row['attributes']; ?> <?php echo $row['checked'] ? 'checked' : ''; ?> class="<?php echo esc_attr($context->classes()); ?>" <?php echo $row['extra']; ?> data-value="<?php echo esc_attr($row['data_value']); ?>" /><span><?php echo wp_kses_post((string) $row['label']); ?></span></label> </div> </div><?php
endforeach;
?></div><?php
