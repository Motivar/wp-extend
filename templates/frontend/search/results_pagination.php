<?php

/**
 * The Template for display search results
 */
if (!defined('ABSPATH')) {
 exit; // Exit if accessed directly
}

global $ewp_search_query;
?>
<div class="ewp-search-pagination">

 <?php
 echo paginate_links(array(
  'format' => 'page/%#%/',
  'current' => $ewp_search_query->current,
  'total' => $ewp_search_query->max_num_pages,
  'prev_text' => __('« prev', 'extend-wp'),
  'next_text' => __('next »', 'extend-wp'),
  'show_all' => true
 ));
 ?>
</div>