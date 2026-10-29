<?php
/**
 * Field template: case `html` with a value (printed raw: developer markup).
 *
 * Override in a theme at `extend-wp/fields/html.php`. Lines end in `?>` so
 * PHP drops the newline: the markup must not gain whitespace text nodes.
 *
 * @var array $variables {
 *     @type string      $key         Meta key (label target).
 *     @type string      $id          Box id (meta key without leading `_`).
 *     @type string|null $label       Label when `show_label`, null for none.
 *     @type string      $explanation Explanation markup.
 *     @type string      $value       The HTML to show.
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="awm-meta-html awm-meta-field" id="<?php echo esc_attr($variables['id']); ?>"><?php
if (null !== $variables['label']) :
?><label for="<?php echo esc_attr($variables['key']); ?>" class="awm-input-label"><?php echo wp_kses_post($variables['label']); ?></label><?php
    echo $variables['explanation']; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered by fields/explanation.
endif;
echo $variables['value']; // phpcs:ignore WordPress.Security.EscapeOutput -- developer markup, printed raw by design.
?></div><?php
