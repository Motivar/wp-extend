<?php
/**
 * The "Update actions" side box of a custom content edit form: status,
 * the delete link and the save button, then the row's id and hash in a
 * "Row details" disclosure (collapsed by default, native <details>).
 *
 * Rendered by AWM_List_Form::update_box(). Styled in
 * assets/css/admin/ewp-admin-ui.scss (`.ewp-status-box`). Scripts rely on
 * `#publish` (form validation, awm-forms-module.js) and
 * `.awm-delete-content-id` (delete confirmation).
 *
 * @var array $variables {
 *     @type string $status_html Rendered status field ('' when the content type has no statuses).
 *     @type string $delete_url  Nonced delete URL ('' when the row is new or deleting is off).
 *     @type string $save_text   Button label (Save / Update).
 *     @type int    $content_id  Row id (0 for a new row).
 *     @type string $hash        Row hash ('' for a new row).
 * }
 *
 * @since 1.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="submitbox ewp-status-box">
    <?php if ('' !== $variables['status_html']) : ?>
        <div class="ewp-status-box__status"><?php echo $variables['status_html']; // phpcs:ignore WordPress.Security.EscapeOutput -- awm_show_content() output. ?></div>
    <?php endif; ?>
    <div id="major-publishing-actions" class="ewp-status-box__actions">
        <div id="delete-action">
            <?php if ('' !== $variables['delete_url']) : ?>
                <a class="submitdelete deletion awm-delete-content-id" href="<?php echo esc_url($variables['delete_url']); ?>"><?php esc_html_e('Delete', 'extend-wp'); ?></a>
            <?php endif; ?>
        </div>
        <div id="publishing-action">
            <span class="spinner"></span>
            <input name="save" type="submit" class="button button-primary button-large" id="publish" value="<?php echo esc_attr($variables['save_text']); ?>">
        </div>
    </div>
    <?php if ($variables['content_id']) : ?>
        <details class="ewp-status-box__meta">
            <summary><?php esc_html_e('Row details', 'extend-wp'); ?></summary>
            <dl>
                <dt><?php esc_html_e('ID', 'extend-wp'); ?></dt>
                <dd><code><?php echo absint($variables['content_id']); ?></code></dd>
                <?php if ('' !== $variables['hash']) : ?>
                    <dt><?php esc_html_e('Hash', 'extend-wp'); ?></dt>
                    <dd><code><?php echo esc_html($variables['hash']); ?></code></dd>
                <?php endif; ?>
            </dl>
        </details>
    <?php endif; ?>
</div>
