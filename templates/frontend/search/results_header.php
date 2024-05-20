<?php

/**
 * The Template for display search results
 */
if (!defined('ABSPATH')) {
 exit; // Exit if accessed directly
}
global $ewp_search_query;
global $ewp_config;
global $ewp_params;
$current_page = max(1, isset($ewp_search_query->query_vars['paged']) ? $ewp_search_query->query_vars['paged'] : 1);
?>
<div class="ewp-search-header">
 <div class="ewp-search-header__title">
  <?php echo sprintf(__('Showing %s of %s', 'extend-wp'), $current_page, $ewp_search_query->found_posts); ?>
 </div>
 <?php
 if (isset($ewp_config['sorting']['show']) && $ewp_config['sorting']['show'] == 'results') { ?>
  <div class="ewp-search-header__sorting">
   <?php
   $box = ewp_search_sorting_filter($ewp_config['sorting'], $ewp_params);
   echo awm_show_content($box);
   ?>
  </div>
 <?php } ?>
 <div class="clearfix"></div>
</div>