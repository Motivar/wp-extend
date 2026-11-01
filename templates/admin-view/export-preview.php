<?php
/**
 * Export preview panel of the Import/Export admin screen: the exported JSON or
 * PHP, with Copy and Download buttons. Hidden until an export ran.
 *
 * Rendered by Extend_WP_Import_Export::import_export_fields() as the value of an
 * `html` field. assets/js/modules/ewp-import-export.js fills the <code> element
 * (textContent) and listens for clicks on the `data-ewp-export-*` buttons.
 *
 * @var array $variables {
 *     @type string $title Panel heading.
 * }
 *
 * @since 1.15.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="ewp-export-preview" data-ewp-export-preview hidden>
    <div class="ewp-export-preview__bar">
        <strong class="ewp-export-preview__title"><?php echo esc_html($variables['title']); ?></strong>
        <div class="ewp-export-preview__actions">
            <button type="button" class="button" data-ewp-export-copy><?php esc_html_e('Copy', 'extend-wp'); ?></button>
            <button type="button" class="button button-primary" data-ewp-export-download><?php esc_html_e('Download', 'extend-wp'); ?></button>
        </div>
    </div>
    <p class="ewp-export-preview__notice" data-ewp-export-notice role="status" hidden></p>
    <pre class="ewp-export-preview__code" tabindex="0"><code data-ewp-export-code></code></pre>
</div>
