<?php
/**
 * Field template: case `textarea` (plain textarea or WordPress editor).
 *
 * Override in a theme at `extend-wp/fields/textarea.php`. Lines end in `?>` so
 * PHP drops the newline: the markup must not gain whitespace text nodes.
 *
 * @var array $variables {
 *     @type \EWP\Fields\Field_Context $context     Resolved field state (value already stripped when `awm_strip_html` is set).
 *     @type string|null               $editor_html wp_editor() markup, null for a plain textarea.
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$context = $variables['context'];
if (null !== $variables['editor_html']) :
    echo $variables['editor_html']; // phpcs:ignore WordPress.Security.EscapeOutput -- WordPress core editor markup.
    return;
endif;
?>
<textarea rows="5" name="<?php echo esc_attr($context->name()); ?>"
    id="<?php echo esc_attr($context->id()); ?>"
    class="<?php echo esc_attr($context->classes()); ?>"
    <?php echo $context->required() ? 'required="true"' : ''; ?>
    <?php echo \EWP\Fields\Field_Attributes::render($context->attributes()); ?>
    ><?php echo esc_textarea($context->value()); ?></textarea><?php
