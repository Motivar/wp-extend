<?php
/**
 * Field template: one row of a `repeater`.
 *
 * Override in a theme at `extend-wp/fields/repeater-row.php`. Lines end in
 * `?>` so PHP drops the newline: the markup must not gain whitespace text
 * nodes. The admin script relies on the classes and data attributes.
 *
 * @var array $variables {
 *     @type string      $dom_id      Row element id.
 *     @type int|string  $counter     Row index, or `template`.
 *     @type string      $data_id     Repeater element id.
 *     @type bool        $is_template Clone-source row (gets `temp-source`).
 *     @type string      $title       Row title ('' for none).
 *     @type string      $inputs      Rendered row fields (awm_show_content() output).
 *     @type string|null $actions     Filtered action buttons HTML, null when `hide_buttons`.
 * }
 *
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="<?php echo esc_attr($variables['dom_id']); ?>" class="awm-repeater-content <?php echo $variables['is_template'] ? 'temp-source' : ''; ?>" data-counter="<?php echo esc_attr($variables['counter']); ?>" data-id="<?php echo esc_attr($variables['data_id']); ?>"><div class="awm-repeater-inputs"><?php
if ('' !== $variables['title']) :
?><div class="row-title"><?php echo wp_kses_post($variables['title']); ?></div><?php
endif;
echo $variables['inputs']; // phpcs:ignore WordPress.Security.EscapeOutput -- awm_show_content() output.
?></div><?php
if (null !== $variables['actions']) :
?><div class="awm-actions"><?php echo $variables['actions']; // phpcs:ignore WordPress.Security.EscapeOutput -- awm_show_actions_html output (developer markup). ?></div><?php
endif;
?></div><?php
