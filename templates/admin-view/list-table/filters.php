<?php
/**
 * List table: the restrict filters and their Filter button (top navigation). Styled by assets/css/admin/ewp-list-table.scss.
 *
 * Rendered by AWM_List_Table (includes/classes/awm-list-tables/class-list-table.php),
 * the legacy WP_List_Table view of a custom content type (`ewp_content_list_ui` → `legacy`).
 *
 * @var array $variables {
 *     @type string $filters_html Rendered filters (awm_show_content(…, 'restrict_manage_posts')).
 *     @type string $page_id      List page id (posted as awm_restict_custom_list).
 *     @type string $submit_html  submit_button() output.
 * }
 *
 * @since 1.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="alignleft actions">
    <div class="ewp-list-filters"><?php echo $variables['filters_html']; // phpcs:ignore WordPress.Security.EscapeOutput -- awm_show_content() output. ?></div>
    <input type="hidden" name="awm_restict_custom_list" id="awm_restict_custom_list" value="<?php echo esc_attr($variables['page_id']); ?>"/>
    <?php echo $variables['submit_html']; // phpcs:ignore WordPress.Security.EscapeOutput -- core submit_button() output. ?>
</div>
