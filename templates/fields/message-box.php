<?php
/**
 * Field template: message box shared by cases `message`, `function` and `awm_gallery`.
 *
 * Override in a theme at `extend-wp/fields/message-box.php`. Lines end in `?>`
 * so PHP drops the newline: the markup must not gain whitespace text nodes.
 *
 * @var array $variables {
 *     @type string $id          DOM id of the box.
 *     @type string $label       Label HTML.
 *     @type string $explanation Pre-rendered explanation HTML, '' for none.
 *     @type string $body        Body HTML.
 *     @type bool   $inner       Wrap the body in `.awm-meta-message-inner` (and sanitise it); false prints trusted markup (media field) as is.
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

?>
<div class="awm-meta-message" id="<?php echo esc_attr($variables['id']); ?>"><div class="awm-meta-message-label"><?php echo wp_kses_post($variables['label']) . $variables['explanation']; ?></div><?php
if ($variables['inner']) :
?><div class="awm-meta-message-inner"><?php echo $variables['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- developer markup: message value or callback output (may contain form elements/scripts), printed raw as before. ?></div><?php
else :
    echo $variables['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped by Media_Field_Renderer.
endif;
?></div><?php
