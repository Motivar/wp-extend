<?php
/**
 * Field template: case `button` (a link styled as a button).
 *
 * Override in a theme at `extend-wp/fields/button.php`. Lines end in `?>` so
 * PHP drops the newline: the markup must not gain whitespace text nodes.
 *
 * @var array $variables {
 *     @type string $id         Element id (meta key without leading underscores).
 *     @type string $link       Link target.
 *     @type string $label      Label HTML.
 *     @type string $classes    Space-separated classes.
 *     @type string $attributes Escaped `key="value"` attribute markup.
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

?>
<a href="<?php echo esc_url($variables['link']); ?>" id="<?php echo esc_attr($variables['id']); ?>" title="<?php echo esc_attr(wp_strip_all_tags($variables['label'])); ?>" class="<?php echo esc_attr($variables['classes']); ?>" <?php echo $variables['attributes']; ?>><?php echo wp_kses_post($variables['label']); ?></a><?php
