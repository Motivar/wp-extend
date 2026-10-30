<?php
/**
 * Settings › Permalinks: the slug input of a post type or taxonomy registered
 * through Extend WP's UI (option `{slug}_slug`).
 *
 * Rendered by the add_settings_field() callback in
 * EWP_WP_Content_Installer (includes/classes/ewp-wp-content/class-wp-content-installer.php).
 *
 * @var array $variables {
 *     @type string $slug  Post type / taxonomy key (input name is `{slug}_slug`).
 *     @type string $value Saved slug ('' for none).
 * }
 *
 * @since 1.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$name = $variables['slug'] . '_slug';
?>
<input type="text" class="regular-text" name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($variables['value']); ?>" placeholder="<?php echo esc_attr($variables['slug']); ?>"/>
