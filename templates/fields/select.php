<?php
/**
 * Field template: case `select`.
 *
 * Override in a theme at `extend-wp/fields/select.php`. Lines end in `?>` so
 * PHP drops the newline: the markup must not gain whitespace text nodes.
 *
 * @var array $variables {
 *     @type \EWP\Fields\Field_Context $context     Resolved field state.
 *     @type string                    $name        `name` attribute (with `[]` for multiple selects).
 *     @type string                    $attributes  Extra attributes, already escaped.
 *     @type array|null                $placeholder `label` and `data_html` of the empty first option, null for none.
 *     @type array                     $entries     Option and optgroup entries (`kind`: option, group_start, group_end).
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$context = $variables['context'];
?>
<select name="<?php echo esc_attr($variables['name']); ?>"
    id="<?php echo esc_attr($context->id()); ?>"
    class="<?php echo esc_attr($context->classes()); ?>"
    <?php echo $variables['attributes']; ?>
    <?php echo $context->required() ? 'required="true"' : ''; ?>><?php
if ($variables['placeholder']) :
?><option value="" data-html="<?php echo $variables['placeholder']['data_html']; ?>" data-placeholder="true"><?php echo wp_kses_post($variables['placeholder']['label']); ?></option><?php
endif;
foreach ($variables['entries'] as $entry) :
    if ('group_end' === $entry['kind']) :
?></optgroup><?php
    elseif ('group_start' === $entry['kind']) :
?><optgroup id="<?php echo esc_attr($entry['id']); ?>" label="<?php echo esc_attr($entry['label']); ?>" data-html="<?php echo $entry['data_html']; ?>" options="<?php echo esc_attr($entry['options']); ?>"><?php
    else :
?><option value="<?php echo esc_attr($entry['value']); ?>" <?php echo $entry['selected'] ? 'selected' : ''; ?> <?php echo $entry['extra']; ?> <?php echo $entry['data_html'] ? 'data-html="' . $entry['data_html'] . '"' : ''; ?> ><?php echo wp_kses_post($entry['label']); ?></option><?php
    endif;
endforeach;
?></select><?php
