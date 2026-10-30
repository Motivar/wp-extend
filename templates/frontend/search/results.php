<?php

/**
 * The Template for display search results
 */
if (!defined('ABSPATH')) {
 exit; // Exit if accessed directly
}
global $ewp_search_query;
global $ewp_search_id;

do_action('ewp_search_results_show', $ewp_search_query);
?>
<div class="ewp-search-results" id="ewp-results-<?php echo absint($ewp_search_id); ?>" data-form="<?php echo absint($ewp_search_id); ?>">
 <?php echo awm_parse_template(awm_path . 'templates/frontend/search/results_header.php'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Extend WP template output; the templates escape their own values. ?>
 <div class="ewp-search-articles">
  <?php while ($ewp_search_query->have_posts()) : $ewp_search_query->the_post();
   global $post;
   echo awm_parse_template(apply_filters('ewp_search_result_path', awm_path . 'templates/frontend/search/result.php', $ewp_search_query)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Extend WP template output; the templates escape their own values.
  endwhile;
  ?>
 </div>
 <?php echo awm_parse_template(apply_filters('ewp_search_result_pagination_path', awm_path . 'templates/frontend/search/results_pagination.php', $ewp_search_query)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Extend WP template output; the templates escape their own values. ?>
 <div class="clearfix"></div>
</div>
<?php wp_reset_postdata(); ?>