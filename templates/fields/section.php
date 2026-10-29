<?php
/**
 * Field template: case `section`.
 *
 * Override in a theme at `extend-wp/fields/section.php`. Lines end in `?>` so
 * PHP drops the newline: the markup must not gain whitespace text nodes.
 *
 * @var array $variables {
 *     @type \EWP\Fields\Field_Context $context Resolved field state.
 *     @type string                    $inner   Rendered nested fields (awm_show_content() output).
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$context = $variables['context'];
$label   = $context->field('label');
?>
<div class="awm-inner-section"><?php
if (null !== $label) :
?><div class="section-header"><?php echo wp_kses_post($label . $context->explanation()); ?></div><?php
endif;
?><div class="awm-inner-section-content"><?php echo $variables['inner']; // phpcs:ignore WordPress.Security.EscapeOutput -- awm_show_content() output. ?></div></div><?php
