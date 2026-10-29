<?php
/**
 * Field template: case `input` (every type except hidden).
 *
 * Override in a theme at `extend-wp/fields/input.php`. Lines end in `?>` so
 * PHP drops the newline: the markup must not gain whitespace text nodes.
 *
 * @var array $variables {
 *     @type \EWP\Fields\Field_Context $context       Resolved field state (value already normalised).
 *     @type string                    $type          Input type attribute.
 *     @type bool                      $checked       Checkbox is checked.
 *     @type string                    $after_message HTML shown after the input, '' for none.
 *     @type bool                      $encrypt       Emit the `ewp_encrypt[]` marker.
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$context = $variables['context'];
$id      = $context->id();
?>
<div class="input-wrapper"><input type="<?php echo esc_attr($variables['type']); ?>"
    name="<?php echo esc_attr($context->name()); ?>"
    id="<?php echo esc_attr($id); ?>"
    value="<?php echo esc_attr($context->value()); ?>"
    <?php echo \EWP\Fields\Field_Attributes::render($context->attributes()); ?>
    <?php echo $variables['checked'] ? 'checked' : ''; ?>
    class="<?php echo esc_attr($context->classes()); ?>"
    <?php echo $context->required() ? 'required="true"' : ''; ?>/><?php
if ($variables['after_message']) :
?><span class="awm-after-message"><label for="<?php echo esc_attr($id); ?>"><?php echo wp_kses_post($variables['after_message']); ?></label></span><?php
endif;
if ('password' === $variables['type']) :
?><div class="eye" data-toggle="password" data-id="<?php echo esc_attr($id); ?>"></div><?php
endif;
?></div><?php
if ($variables['encrypt']) :
?><input type="hidden" name="ewp_encrypt[]" value="<?php echo esc_attr($context->name()); ?>"><?php
endif;
