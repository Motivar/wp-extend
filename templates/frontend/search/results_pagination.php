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
if (isset($_REQUEST['paged'])) {
  $current_page = absint($_REQUEST['paged']);
}
$total_pages = $ewp_search_query->max_num_pages ?: $ewp_search_query->get('max_num_pages');
?>

<div class="ewp-search-pagination">
 <?php
  switch ($ewp_config['pagination_styles']['load_type']) {
    case 'none':
      break;
    case 'button':
      if ($current_page < $total_pages) {
        echo '<button class="ewp-load-more" data-page="' . $current_page . '" data-max-pages="' . $ewp_search_query->get('max_num_pages') . '">' . __($ewp_config['pagination_styles']['load_type_button'], 'extend-wp') . '</button>';
      }
      break;
    default:
      // Ensure the query is executed before checking max_num_pages.

      $pagination_links_args = apply_filters('ewp_pagination_links_args_filter', array(
        'base'      => str_replace(999999999, '%#%', esc_url(get_pagenum_link(999999999))),
        'format'    => '/page/%#%/',
        'current'   => $current_page,
        'total'     => $total_pages,
        'prev_text' => __('« prev', 'extend-wp'),
        'next_text' => __('next »', 'extend-wp'),
        'show_all'  => false,
        'type'      => 'plain',
      ));
      echo paginate_links($pagination_links_args);
      break;
  }
  ?></div>