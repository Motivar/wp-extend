<?php

if (!defined('ABSPATH')) {
 exit;
}

if (!function_exists('awm_transient_key_name')) {
 function awm_transient_key_name($name)
 {
  $locale = get_locale();
  $name = $name . '_' . $locale;
  $md5 = md5($name);

  $name = substr($md5, strlen($md5) - 6);
  return apply_filters('awm_transient_key_names_filter', $name);
 }
}



if (!function_exists('awm_delete_transient')) {
 /**
  * with this function we delete the transient
  * @param string $name the name of the transient
  * @param integer $post_id the post id
  */
 function awm_delete_transient($name, $post_id = 0)
 {

  if ($post_id != 0) {
   $name .= '_' . $post_id;
  }
  $name = awm_transient_key_name($name);
  delete_transient($name);
 }
}


if (!function_exists('awm_set_transient')) {
 /**
  * with this function we delete the transient
  * @param string $name the name of the transient
  * @param array|object $value the value of the product
  * @param integer $post_id the post id
  * @param integer $hours hours to set for the transient
  * @param string $group the group which the transient belongs
  */
 function awm_set_transient($name, $value, $post_id = 0, $hours = 12, $group = '')
 {
  if ($post_id != 0) {
   $name .= '_' . $post_id;
  }
  $name = awm_transient_key_name($name);
  set_transient($name, $value, $hours * HOUR_IN_SECONDS);
  if (!empty($group)) {
   $group_keys = get_option('awm_transient_groups') ?: array();
   if (!isset($group_keys[$group])) {
    $group_keys[$group] = array();
   }
   if (!in_array($name, $group_keys[$group])) {
    $group_keys[$group][] = $name;
   }
   update_option('awm_transient_groups', $group_keys, false);
  }
 }
}



if (!function_exists('awm_get_transient')) {
 /**
  * with this function we delete the transient
  * @param string $name the name of the transient
  * @param integer $post_id the post id
  */
 function awm_get_transient($name, $post_id = 0)
 {
  if ($post_id != 0) {
   $name .= '_' . $post_id;
  }
  $name = awm_transient_key_name($name);
  $result = get_transient($name);
  return $result;
 }
}

if (!function_exists('awm_delete_transient_group')) {
 /**
  * with this function we delete the transient
  * @param string $group the name of the group
  */
 function awm_delete_transient_group($group)
 {
  if (empty($group)) {
   return;
  }
  $group_keys = get_option('awm_transient_groups') ?: array();
  if (isset($group_keys[$group])) {
   foreach ($group_keys[$group] as $transient) {
    delete_transient($transient);
   }
  }
 }
}


if (!function_exists('awm_delete_transient_all')) {
 /**
  * with this function we delete the transient
  */
 function awm_delete_transient_all()
 {
  $group_keys = get_option('awm_transient_groups') ?: array();
  if (!empty($group_keys)) {
   foreach ($group_keys as $group_id => $group) {
   foreach ($group as $transient) {
    delete_transient($transient);
   }
    awm_delete_transient_group($group_id);
  }
  }
  update_option('ewp_user_caps_version', strtotime('now'), false);
  delete_option('ewp_user_caps_version_old');
  flush_rewrite_rules();
  wp_cache_flush();
 }
}