<?php
/**
 * "Php code" box on the Extend WP › Fields edit form: a button that loads the
 * PHP for the field group into #awm-php-code.
 *
 * Rendered by awm_php_views() (includes/classes/ewp-fields/ewp_field_functions.php)
 * as the value of an `html` field. The button carries `data-ewp-php-code`;
 * assets/js/admin/awm-admin-script.js listens for clicks on it and calls
 * ewp_get_php_code().
 *
 * @var array $variables {
 *     @type int $content_id The ewp_fields row id.
 * }
 *
 * @since 1.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="awm-php-wrapper"><div><button type="button" class="button" data-ewp-php-code="<?php echo absint($variables['content_id']); ?>"><?php esc_html_e('Show php code', 'extend-wp'); ?></button></div><div id="awm-php-code"></div></div><?php
