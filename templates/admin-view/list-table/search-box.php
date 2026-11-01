<?php
/**
 * List table: the search box (`s`).
 *
 * Rendered by AWM_List_Table (includes/classes/awm-list-tables/class-list-table.php),
 * the legacy WP_List_Table view of a custom content type (`ewp_content_list_ui` → `legacy`).
 *
 * @var array $variables {
 *     @type string $text        Button and label text.
 *     @type string $input_id    Input id.
 *     @type string $value       Current search.
 *     @type string $submit_html submit_button() output.
 * }
 *
 * @since 1.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="ewp-search-box-admin">
    <div class="search-box">
        <label class="screen-reader-text" for="<?php echo esc_attr($variables['input_id']); ?>"><?php echo esc_html($variables['text']); ?></label>
        <input type="search" id="<?php echo esc_attr($variables['input_id']); ?>" name="s" value="<?php echo esc_attr($variables['value']); ?>"/>
        <?php echo $variables['submit_html']; // phpcs:ignore WordPress.Security.EscapeOutput -- core submit_button() output. ?>
    </div>
</div>
