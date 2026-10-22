<?php
/**
 * DataViews admin list of a custom content type (EWP_Content_List_UI::render()).
 *
 * The module `build/modules/ewp-dataviews-list.js` mounts on `.ewp-dataviews-list`
 * (loaded by the Dynamic Asset Loader) and reads its configuration from
 * `data-config`. The <noscript> / fallback link leads to the legacy list.
 *
 * @var array $args   List arguments.
 * @var array $config EWP_Content_List_UI::config() output.
 *
 * @package extend-wp
 * @since   1.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap ewp-dataviews-wrap">
    <h1 class="wp-heading-inline"><?php echo esc_html(ucwords($config['labels']['title'])); ?></h1>
    <?php if (!empty($config['newUrl'])) : ?>
        <a class="page-title-action" href="<?php echo esc_url($config['newUrl']); ?>">
            <?php
            /* translators: %s: singular item name, e.g. "Field group". */
            echo esc_html(sprintf(__('New %s', 'extend-wp'), $config['labels']['singular']));
            ?>
        </a>
    <?php endif; ?>
    <hr class="wp-header-end">

    <div class="ewp-dataviews-list" data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
        <p class="ewp-dataviews-list__loading"><?php esc_html_e('Loading…', 'extend-wp'); ?></p>
    </div>

    <p class="ewp-dataviews-list__legacy">
        <a href="<?php echo esc_url($config['legacyUrl']); ?>"><?php esc_html_e('Switch to the classic list', 'extend-wp'); ?></a>
    </p>
</div>
