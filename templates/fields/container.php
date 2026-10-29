<?php
/**
 * Field partial: the container around a rendered library.
 *
 * Override in a theme at `extend-wp/fields/container.php`. Lines end in `?>` so
 * PHP drops the newline: the markup must not gain whitespace text nodes.
 *
 * @var array $variables {
 *     @type string $id    Container id (library `awm-id`, '' for none).
 *     @type int    $count Rows counted (wrapped rows without `awm_no_show`).
 *     @type string $html  Rendered rows.
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="<?php echo esc_attr($variables['id']); ?>" class="awm-show-content" count="<?php echo esc_attr($variables['count']); ?>"><?php
echo $variables['html']; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered rows.
?></div><?php
