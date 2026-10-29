<?php
/**
 * Field template: case `repeater` (container).
 *
 * Override in a theme at `extend-wp/fields/repeater.php`. Lines end in `?>`
 * so PHP drops the newline: the markup must not gain whitespace text nodes.
 *
 * @var array $variables {
 *     @type \EWP\Fields\Field_Context $context     Resolved field state.
 *     @type int                       $field_count Number of fields per row (without `awm_key`).
 *     @type int|string                $max_rows    `maxrows`, '' for unlimited.
 *     @type string                    $rows        Rendered rows (repeater-row.php), template row last.
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$context = $variables['context'];
?>
<div class="awm-repeater" data-count="<?php echo esc_attr($variables['field_count']); ?>" data-id="<?php echo esc_attr($context->id()); ?>" maxrows="<?php echo esc_attr($variables['max_rows']); ?>"><div class="awm-repeater-title"><?php echo wp_kses_post($context->field('label', '') . $context->explanation()); ?></div><div class="awm-repeater-contents"><?php
echo $variables['rows']; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered rows.
?></div></div><?php
