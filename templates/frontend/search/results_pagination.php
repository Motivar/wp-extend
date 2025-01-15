<?php

/**
 * The Template for display search results
 */
if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

global $ewp_search_query;
global $ewp_config;

if (!isset($ewp_config['pagination_styles']['load_type'])) {
  $ewp_config['pagination_styles']['load_type'] = 'pagination';
}
$current_page = get_query_var('paged') ? get_query_var('paged') : 1;

?>

<div class="ewp-search-pagination">
 <?php
  switch ($ewp_config['pagination_styles']['load_type']) {
    case 'none':
      break;
    case 'button':
      if ($current_page < $ewp_search_query->get('max_num_pages')) {
        echo '<button class="ewp-load-more" data-page="' . $current_page . '" data-max-pages="' . $ewp_search_query->get('max_num_pages') . '">' . __($ewp_config['pagination_styles']['load_type_button'], 'extend-wp') . '</button>';
      }
      break;
    default:
      $pagination_links_args = apply_filters('ewp_pagination_links_args_filter', array(
        'format' => 'page/%#%/',
        'current' => $current_page,
        'total' => $ewp_search_query->get('max_num_pages'),
        'prev_text' => __('« prev', 'extend-wp'),
        'next_text' => __('next »', 'extend-wp'),
        'show_all' => true
      ));
      echo paginate_links($pagination_links_args);
      break;
  }
  ?></div>