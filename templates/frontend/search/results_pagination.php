<?php

/**
 * The Template for display search results
 */
if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

global $ewp_search_query;
global $ewp_config;
$current_page = max(1, isset($ewp_search_query->query_vars['paged']) ? $ewp_search_query->query_vars['paged'] : 1);
?>
<div class="ewp-search-pagination"><?php
                                    switch ($ewp_config['load_type']) {
                                      case 'button':
                                        if ($current_page < $ewp_search_query->max_num_pages) {
                                          echo '<button class="ewp-load-more" data-page="' . $current_page . '" data-max-pages="' . $ewp_search_query->max_num_pages . '">' . __($ewp_config['load_type_button'], 'extend-wp') . '</button>';
                                        }
                                        break;
                                      default:
                                        echo paginate_links(array(
                                          'format' => 'page/%#%/',
                                          'current' => $current_page,
                                          'total' => $ewp_search_query->max_num_pages,
                                          'prev_text' => __('« prev', 'extend-wp'),
                                          'next_text' => __('next »', 'extend-wp'),
                                          'show_all' => true
                                        ));
                                        break;
                                    }
                                    ?></div>