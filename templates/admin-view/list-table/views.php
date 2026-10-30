<?php
/**
 * List table: links that filter the list by status (All + one per status with rows).
 *
 * Rendered by AWM_List_Table (includes/classes/awm-list-tables/class-list-table.php),
 * the legacy WP_List_Table view of a custom content type (`ewp_content_list_ui` → `legacy`).
 *
 * @var array $variables {
 *     @type array $views Each `key`, `url`, `label` (may contain basic HTML), `count`, `current` (bool).
 * }
 *
 * @since 1.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="ewp-statuses">
    <ul class="subsubsub" id="extend-wp-custom-views">
        <?php foreach ($variables['views'] as $index => $view) : ?>
            <li class="<?php echo esc_attr($view['key']); ?>"><?php echo $index ? ' | ' : ''; ?><a href="<?php echo esc_url($view['url']); ?>"<?php echo $view['current'] ? ' class="current" aria-current="page"' : ''; ?>><?php echo wp_kses_post($view['label']); ?> <span class="count">(<?php echo absint($view['count']); ?>)</span></a></li>
        <?php endforeach; ?>
    </ul>
</div>
