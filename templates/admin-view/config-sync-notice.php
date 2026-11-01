<?php
/**
 * Admin notice of the automatic configuration sync (auto export / auto import).
 *
 * Printed by EWP\Content\Config_Sync::render_notices().
 *
 * @var array $variables {
 *     @type string $level   `error`, `warning` or `success`.
 *     @type string $message Message (plain text).
 * }
 *
 * @since 1.15.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$level = in_array($variables['level'], array('error', 'warning', 'success'), true) ? $variables['level'] : 'info';
?>
<div class="notice notice-<?php echo esc_attr($level); ?> is-dismissible ewp-config-sync-notice">
    <p><?php echo esc_html($variables['message']); ?></p>
</div>
