<?php
/**
 * The "Update actions" side box of a custom content edit form: status,
 * the delete link and the save button, then the row's id and hash in a
 * "Row details" disclosure (collapsed by default, native <details>).
 *
 * Rendered by AWM_Add_Custom_List::update_box(). Styled in
 * assets/css/admin/ewp-admin-ui.scss (`.ewp-status-box`). Scripts rely on
 * `#publish` (form validation, awm-forms-module.js) and
 * `.awm-delete-content-id` (delete confirmation).
 *
 * @var array $variables {
 *     @type string $status_html Rendered status field ('' when the content type has no statuses).
 *     @type string $delete_url  Nonced delete URL ('' when the row is new or deleting is off).
 *     @type string $save_text   Button label (Save / Update).
 *     @type array  $details     "Row details" entries (AWM_Add_Custom_List::row_details(), filter
 *                               `ewp_content_row_details`): slug => label, value, code, html.
 *                               The disclosure is hidden when empty.
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
    <?php if (!empty($variables['details'])) : ?>
        <details class="ewp-status-box__meta">
            <summary><?php esc_html_e('Row details', 'extend-wp'); ?></summary>
            <dl>
                <?php foreach ($variables['details'] as $slug => $entry) : ?>
                    <dt><?php echo esc_html($entry['label']); ?></dt>
                    <dd data-detail="<?php echo esc_attr($slug); ?>">
                        <?php
                        $value = $entry['html'] ? wp_kses_post($entry['value']) : esc_html($entry['value']);
                        echo $entry['code'] ? '<code>' . $value . '</code>' : $value; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped on the line above.
                        ?>
                    </dd>
                <?php endforeach; ?>
            </dl>
        </details>
    <?php endif; ?>
</div>
