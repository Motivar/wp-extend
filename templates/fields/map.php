<?php
/**
 * Field template: case `map` (search box, map canvas and lat/lng/address inputs).
 *
 * Override in a theme at `extend-wp/fields/map.php`. Lines end in `?>` so PHP
 * drops the newline: the markup must not gain whitespace text nodes.
 *
 * @var array $variables {
 *     @type string $meta     Input name (meta key).
 *     @type string $id       DOM id base.
 *     @type string $lat      Latitude.
 *     @type string $lng      Longitude.
 *     @type string $address  Address.
 *     @type bool   $required Search box carries required="true".
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$id = esc_attr($variables['id']);
?>
<input id="awm_map<?php echo $id; ?>_search_box" class="controls" type="text" placeholder="<?php echo esc_attr__('Type to search', 'extend-wp'); ?>" value="<?php echo esc_attr($variables['address']); ?>" <?php echo $variables['required'] ? 'required="true"' : ''; ?> onkeypress="return noenter()"><div class="awm_map" id="awm_map<?php echo $id; ?>"></div><input type="hidden" name="<?php echo esc_attr($variables['meta']); ?>[lat]" id="awm_map<?php echo $id; ?>_lat" value="<?php echo esc_attr($variables['lat']); ?>" /><input type="hidden" name="<?php echo esc_attr($variables['meta']); ?>[lng]" id="awm_map<?php echo $id; ?>_lng" value="<?php echo esc_attr($variables['lng']); ?>" /><input type="hidden" name="<?php echo esc_attr($variables['meta']); ?>[address]" id="awm_map<?php echo $id; ?>_address" value="<?php echo esc_attr($variables['address']); ?>" /><?php
