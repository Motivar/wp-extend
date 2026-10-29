<?php
/**
 * Field partial: the `awm_custom_meta[]` marker telling the save handlers which keys this form posts.
 *
 * Override in a theme at `extend-wp/fields/save-marker.php`. Lines end in `?>` so
 * PHP drops the newline: the markup must not gain whitespace text nodes.
 *
 * @var array $variables {
 *     @type string $key Meta key.
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<input type="hidden" name="awm_custom_meta[]" value="<?php echo esc_attr($variables['key']); ?>"/><?php
