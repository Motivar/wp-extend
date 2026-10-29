<?php
/**
 * Field template: case `image` (label, explanation and the single media field).
 *
 * Override in a theme at `extend-wp/fields/image.php`. Lines end in `?>` so
 * PHP drops the newline: the markup must not gain whitespace text nodes.
 *
 * @var array $variables {
 *     @type string $id          DOM id the label points at.
 *     @type string $label       Label HTML.
 *     @type string $explanation Pre-rendered explanation HTML, '' for none.
 *     @type string $media       Media field HTML (escaped by Media_Field_Renderer).
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

?>
<label for="<?php echo esc_attr($variables['id']); ?>" class="awm-input-label"><?php echo wp_kses_post($variables['label']); ?></label><?php
echo $variables['explanation'];
echo $variables['media']; // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped by Media_Field_Renderer.
