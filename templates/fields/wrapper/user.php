<?php
/**
 * Field row wrapper: the user profile table. The duplicate `data-input` is legacy markup kept for scripts that may read it.
 *
 * Override in a theme at `extend-wp/fields/wrapper/user.php`. Lines end in `?>` so
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
<tr data-input="<?php echo esc_attr($variables['dom_id']); ?>" <?php echo $variables['attributes']; ?> class="<?php echo esc_attr($variables['classes']); ?>" data-input="<?php echo esc_attr($variables['dom_id']); ?>" data-type="<?php echo esc_attr($variables['case']); ?>"><th><label for="<?php echo esc_attr($variables['dom_id']); ?>" class="awm-input-label"><span><?php echo wp_kses_post($variables['label']); ?></span></label><?php
echo $variables['explanation']; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered by fields/explanation.
?></th><td class="awm-user-input"><?php
echo $variables['html']; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered field.
?></td></tr><?php
