<?php
/**
 * List table: the navigation bar above/below the rows (bulk actions, filters, pagination).
 *
 * Rendered by AWM_List_Table (includes/classes/awm-list-tables/class-list-table.php),
 * the legacy WP_List_Table view of a custom content type (`ewp_content_list_ui` → `legacy`).
 *
 * @var array $variables {
 *     @type string $which     top|bottom.
 *     @type string $bulk_html Bulk actions ('' when there are no rows).
 *     @type string $extra_html Filters (filters.php output, top only).
 *     @type string $pagination_html Pagination.
 * }
 *
 * @since 1.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="tablenav <?php echo esc_attr($variables['which']); ?>">
    <?php if ('' !== $variables['bulk_html']) : ?>
        <div class="alignleft actions bulkactions"><?php echo $variables['bulk_html']; // phpcs:ignore WordPress.Security.EscapeOutput -- core output. ?></div>
    <?php endif; ?>
    <?php echo $variables['extra_html'] . $variables['pagination_html']; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered template / core output. ?>
    <br class="clear"/>
</div>
