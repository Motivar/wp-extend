<?php
/**
 * Field template: case `input`, type `hidden` (rendered bare, no label or wrapper).
 *
 * Override in a theme at `extend-wp/fields/input-hidden.php`.
 *
 * @var array $variables {
 *     @type \EWP\Fields\Field_Context $context Resolved field state.
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$context = $variables['context'];
?>
<input type="hidden"
    name="<?php echo esc_attr($context->name()); ?>"
    id="<?php echo esc_attr($context->id()); ?>"
    value="<?php echo esc_attr($context->value()); ?>"
    <?php echo \EWP\Fields\Field_Attributes::render($context->attributes()); ?>
    class="<?php echo esc_attr($context->classes()); ?>"
    <?php echo $context->required() ? 'required="true"' : ''; ?>/><?php
