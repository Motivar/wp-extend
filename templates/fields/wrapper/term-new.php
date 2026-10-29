<?php
/**
 * Field row wrapper: the add-term form.
 *
 * Override in a theme at `extend-wp/fields/wrapper/term-new.php`. Lines end in `?>` so
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
<div class="form-field term-group awm-term-meta-row awm-meta-term-field <?php echo esc_attr($variables['classes']); ?>" <?php echo $variables['attributes']; ?>><?php
echo $variables['html']; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered field.
?></div><?php
