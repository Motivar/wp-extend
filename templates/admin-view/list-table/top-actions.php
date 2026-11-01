<?php
/**
 * List table: status views, search box and the bulk-action nonce above the table.
 *
 * Rendered by AWM_List_Table (includes/classes/awm-list-tables/class-list-table.php),
 * the legacy WP_List_Table view of a custom content type (`ewp_content_list_ui` → `legacy`).
 *
 * @var array $variables {
 *     @type string $views_html  Status views (views.php output, '' for none).
 *     @type string $search_html Search box (search-box.php output).
 *     @type string $nonce_html  wp_nonce_field() output for bulk actions.
 * }
 *
 * @since 1.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="ewp-top-actions">
    <?php echo $variables['views_html'] . $variables['search_html'] . $variables['nonce_html']; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered templates / core output. ?>
</div>
