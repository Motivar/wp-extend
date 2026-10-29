<?php
/**
 * Field template: media field (single file / image or gallery).
 *
 * Override in a theme at `extend-wp/fields/media.php`. Lines end in `?>` so
 * PHP drops the newline: the markup must not gain whitespace text nodes.
 *
 * @var array $variables {
 *     @type string $meta          Meta key / field name.
 *     @type bool   $is_single     Single-file mode (input name without `[]`).
 *     @type int    $max_images    Maximum number of files (0 = unlimited).
 *     @type string $allowed_types Media library type filter.
 *     @type array  $items         Previews: each `array( 'id' => int, 'thumb' => string )`.
 *     @type string $button_label  Translated add / select button label.
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$meta       = $variables['meta'];
$input_name = $variables['is_single'] ? $meta : $meta . '[]';
?>
<div class="awm-media-field<?php echo $variables['is_single'] ? ' awm-media-field--single' : ''; ?>" id="<?php echo esc_attr($meta); ?>-field" data-id="<?php echo esc_attr($meta); ?>" data-max="<?php echo esc_attr($variables['max_images']); ?>" data-library="<?php echo esc_attr($variables['allowed_types']); ?>"><ul class="awm-gallery-images-list"><?php
foreach ($variables['items'] as $item) :
?><li class="awm-gallery-image" data-image-id="<?php echo esc_attr($item['id']); ?>"><div class="awm-img-wrapper"><img src="<?php echo esc_url($item['thumb']); ?>" alt=""></div><a href="#" class="awm-remove-image"><?php echo esc_html__('Remove', 'extend-wp'); ?></a><input type="hidden" name="<?php echo esc_attr($input_name); ?>" value="<?php echo esc_attr($item['id']); ?>"></li><?php
endforeach;
?></ul><button type="button" class="button awm-media-field-button" data-id="<?php echo esc_attr($meta); ?>" data-max="<?php echo esc_attr($variables['max_images']); ?>"><?php echo esc_html($variables['button_label']); ?></button></div><?php
