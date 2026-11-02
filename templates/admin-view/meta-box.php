<?php
/**
 * An Extend WP field box on a WordPress screen, in the layout that screen
 * needs, followed by the save markers (metabox-markers.php).
 *
 * Rendered by AWM_Meta::meta_box_html() (includes/classes/class-extend-wp.php)
 * and AWM_Add_Custom_List (custom content forms).
 *
 * Layouts:
 * - `post`      inside a WordPress meta box (title in the box header): explanation + fields.
 * - `content`   inside a custom content form's meta box: fields only.
 * - `term-add`  the add-term form: a titled div.
 * - `term-edit` the edit-term table: a heading row, then the field rows.
 * - `user`      the profile screen: a titled div around a form table.
 *
 * @var array $variables {
 *     @type string $layout      One of the layouts above.
 *     @type string $box_id      Box key.
 *     @type string $title       Box title (may contain basic HTML).
 *     @type string $explanation Explanation markup (awm_show_explanation(), '' for none).
 *     @type string $fields      Rendered fields (awm_show_content() output).
 *     @type string $case        post|term|user, for the save markers.
 * }
 *
 * @since 1.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$box_id      = $variables['box_id'];
$explanation = $variables['explanation']; // phpcs:ignore -- awm_show_explanation() output, echoed below.
$fields      = $variables['fields'];
$markers     = awm_parse_template(EWP_PLUGIN_DIR . 'templates/admin-view/metabox-markers.php', array('box_id' => $box_id, 'case' => $variables['case']));

switch ($variables['layout']) {
    case 'term-add':
        ?>
<div class="awm-term-meta-boxes" id="awm-table-<?php echo esc_attr($box_id); ?>">
    <h2><?php echo wp_kses_post($variables['title']); ?></h2>
    <?php echo $explanation . $fields . $markers; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered markup. ?>
</div>
        <?php
        break;

    case 'term-edit':
        ?>
<tr><td colspan="2"><h2><?php echo wp_kses_post($variables['title']); ?></h2><?php echo $explanation; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered markup. ?></td></tr>
<?php echo $fields . $markers; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered markup. ?>
        <?php
        break;

    case 'user':
        ?>
<div class="awm-user-fields" id="awm-table-<?php echo esc_attr($box_id); ?>">
    <h2><?php echo wp_kses_post($variables['title']); ?></h2>
    <?php echo $explanation; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered markup. ?>
    <table class="form-table"><?php echo $fields; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered markup. ?></table>
    <?php echo $markers; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered markup. ?>
</div>
        <?php
        break;

    case 'content':
        echo $fields . $markers; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered markup.
        break;

    default:
        echo $explanation . $fields . $markers; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered markup.
        break;
}
