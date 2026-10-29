<?php
/**
 * Field row wrapper: post meta boxes, options pages and other views.
 *
 * Override in a theme at `extend-wp/fields/wrapper/default.php`. Lines end in `?>` so
 * PHP drops the newline: the markup must not gain whitespace text nodes.
 *
 * @var array $variables {
 *     @type string $html        Field markup (label, input, save marker).
 *     @type string $dom_id      Element id.
 *     @type string $case        Field case.
 *     @type string $classes     Row classes.
 *     @type string $attributes  Escaped extra row attributes (`show-when`, …).
 *     @type string $label       Field label ('' for none).
 *     @type string $explanation Explanation markup.
 *     @type string $information Admin tooltip text ('' for none; default wrapper only).
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="<?php echo esc_attr($variables['classes']); ?>" data-input="<?php echo esc_attr($variables['dom_id']); ?>" data-type="<?php echo esc_attr($variables['case']); ?>" <?php echo $variables['attributes']; ?> id="awm-element-<?php echo esc_attr($variables['dom_id']); ?>"><?php
echo $variables['html']; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered field.
if ('' !== $variables['information']) :
?><div class="flx-tippy-admin-message"><span class="flx_icon flx-icon-gps" data-message="<?php echo esc_attr($variables['information']); ?>"></span></div><?php
endif;
?></div><?php
