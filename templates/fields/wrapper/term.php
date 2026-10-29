<?php
/**
 * Field row wrapper: the edit-term table.
 *
 * Override in a theme at `extend-wp/fields/wrapper/term.php`. Lines end in `?>` so
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
<tr class="form-field term-group-wrap awm-meta-term-field" data-input="<?php echo esc_attr($variables['dom_id']); ?>"><th scope="row" class="<?php echo esc_attr($variables['classes']); ?>" data-input="<?php echo esc_attr($variables['dom_id']); ?>" data-type="<?php echo esc_attr($variables['case']); ?>"><label for="<?php echo esc_attr($variables['dom_id']); ?>" class="awm-input-label"><?php echo wp_kses_post($variables['label']); ?></label><?php
echo $variables['explanation']; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered by fields/explanation.
?></th><td class="awm-term-input"><?php
echo $variables['html']; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered field.
?></td></tr><?php
