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
 *     @type string      $show_when   Encoded `show-when` rules (single-quoted JSON), empty for none.
 * }
 *
 * @since 1.14.0
 * @since 1.15.0 `show_when`: html fields honour `show-when` like every other case.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="awm-meta-html awm-meta-field" id="<?php echo esc_attr($variables['id']); ?>"<?php if (!empty($variables['show_when'])) : ?> show-when="<?php echo esc_attr($variables['show_when']); ?>"<?php endif; ?>><?php
if (null !== $variables['label']) :
?><label for="<?php echo esc_attr($variables['key']); ?>" class="awm-input-label"><?php echo wp_kses_post($variables['label']); ?></label><?php
    echo $variables['explanation']; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered by fields/explanation.
endif;
echo $variables['value']; // phpcs:ignore WordPress.Security.EscapeOutput -- developer markup, printed raw by design.
?></div><?php
