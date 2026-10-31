<?php

/**
 * The Template for the front-end search form box.
 *
 * Variables (in $variables): id (int), async (string), orientation (string),
 * options (array, the search configuration), form_html (string, rendered
 * fields, already escaped by awm_show_content()), button_apply, button_reset.
 */
if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}
$ewp_search_id = absint($variables['id']);
$ewp_search_async = (string) $variables['async'];
?>
<div id="ewp-search-<?php echo $ewp_search_id; ?>" class="ewp-search-box <?php echo esc_attr($ewp_search_async); ?> <?php echo esc_attr($variables['orientation']); ?>" options="<?php echo esc_attr(str_replace('"', '\'', wp_json_encode($variables['options']))); ?>" search-id="<?php echo $ewp_search_id; ?>"><form id="ewp-search-form-<?php echo $ewp_search_id; ?>"><?php echo $variables['form_html']; ?></form>
<?php if ('not_async' === $ewp_search_async) { ?>
  <div class="ewp-search-actions"><div class="ewp-search-actions-wrapper"><div class="ewp-search-apply" data-ewp-action="search-apply" data-ewp-search-id="<?php echo $ewp_search_id; ?>"><?php echo esc_html($variables['button_apply']); ?></div><div class="ewp-search-reset" data-ewp-action="search-reset" data-ewp-search-id="<?php echo $ewp_search_id; ?>"><?php echo esc_html($variables['button_reset']); ?></div></div></div>
<?php } ?>
</div>
