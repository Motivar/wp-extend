<?php

/**
 * The Template for display search results terms
 */
if (!defined('ABSPATH')) {
 exit; // Exit if accessed directly
}
global $ewp_search_query_terms, $ewp_search_id;
if (empty($ewp_search_query_terms)) {
 return;
}
?>
<div class="ewp-search-terms-display">
 <?php
 foreach ($ewp_search_query_terms as $term_key => $term_data) {
 ?>
  <div class="ewp-search-term-wrapper" form-id="<?php echo $term_key; ?>">
   <div class="label"><?php echo $term_data; ?></div>
   <div class="action" onclick="ewp_search_remove_filter('<?php echo $term_key; ?>',<?php echo $ewp_search_id; ?>)"><span aria-hidden=" true">&times;</span></div>
  </div>
 <?php
 }
 ?>
</div>