<?php
/**
 * Field template: case `radio`.
 *
 * Override in a theme at `extend-wp/fields/radio.php`. Lines end in `?>` so
 * PHP drops the newline: the markup must not gain whitespace text nodes.
 *
 * @var array $variables {
 *     @type \EWP\Fields\Field_Context $context    Resolved field state.
 *     @type bool                      $wrapper    Wrap each option in `div.awm-radio-option`.
 *     @type array                     $options    Rows: `value`, `id`, `checked`, `required`, `label`.
 *     @type string                    $after_text HTML appended after the options (`awm_radio_after_text`).
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$context = $variables['context'];
?>
<div class="awm-radio-options"><?php
foreach ($variables['options'] as $option) :
    if ($variables['wrapper']) :
?><div class="awm-radio-option"><?php
    endif;
?><input type="radio" name="<?php echo esc_attr($context->name()); ?>" id="<?php echo esc_attr($option['id']); ?>" value="<?php echo esc_attr($option['value']); ?>" <?php echo $option['checked'] ? 'checked="checked"' : ''; ?> <?php echo $option['required'] ? 'required="true"' : ''; ?>/><label class="awm-radio-option-label" for="<?php echo esc_attr($option['id']); ?>"><span class="awm-radio-label"><?php echo wp_kses_post($option['label']); ?></span></label><?php
    if ($variables['wrapper']) :
?></div><?php
    endif;
endforeach;
?></div><?php
echo $variables['after_text']; // phpcs:ignore WordPress.Security.EscapeOutput -- developer markup from the awm_radio_after_text filter.
