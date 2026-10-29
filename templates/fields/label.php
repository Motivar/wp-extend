<?php
/**
 * Field partial: the shared label of inputs, selects, textareas and radios.
 *
 * Override in a theme at `extend-wp/fields/label.php`. Lines end in `?>` so
 * PHP drops the newline: the markup must not gain whitespace text nodes.
 *
 * @var array $variables {
 *     @type string $for         Element id the label points to.
 *     @type string $label       Label (may contain basic HTML).
 *     @type string $explanation Explanation markup ('' for none).
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<label for="<?php echo esc_attr($variables['for']); ?>" class="awm-input-label"><span><?php echo wp_kses_post($variables['label']); ?></span></label><?php
echo $variables['explanation']; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered by fields/explanation.
