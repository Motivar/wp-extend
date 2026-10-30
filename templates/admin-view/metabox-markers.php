<?php
/**
 * Hidden inputs that tell the save handlers which Extend WP box was posted
 * (`awm_metabox[]`) and for which object kind (`awm_metabox_case`).
 *
 * Included by templates/admin-view/meta-box.php.
 *
 * @var array $variables {
 *     @type string $box_id Box key.
 *     @type string $case   post|term|user.
 * }
 *
 * @since 1.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<input type="hidden" name="awm_metabox[]" value="<?php echo esc_attr($variables['box_id']); ?>"/>
<input type="hidden" name="awm_metabox_case" value="<?php echo esc_attr($variables['case']); ?>"/>
