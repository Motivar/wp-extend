<?php

/**
 * The Template for display search results
 */
if (!defined('ABSPATH')) {
 exit; // Exit if accessed directly
}

global $post;
?>
<div class="ewp-search-result" id="<?php echo $post->ID; ?>">
 <h2><?php echo $post->post_title; ?></h2>
 <a href="<?php echo get_permalink(($post->ID)); ?>" target="_blank"><?php echo __('view more', 'extend-wp'); ?></a>
</div>