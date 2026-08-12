<?php
if (!defined('ABSPATH')) {
 exit;
}

/**
 * Lets a post type or taxonomy inherit the meta boxes of another one.
 *
 * Whether a meta box renders is decided by the `postTypes` / `taxonomies` array
 * carried by every registered box (see AWM_Meta::awm_add_post_meta_boxes() and
 * AWM_Meta::awm_add_term_meta_boxes()). Instead of copying anything, this class
 * appends the inheriting object to those arrays at runtime, so admin columns,
 * the REST api and saving all follow along, and edits on the source are picked
 * up instantly.
 *
 * Must run after Extend_WP_Fields has registered its own boxes: both hook at
 * PHP_INT_MAX and equal priorities fire in registration order, which the
 * require order in Setup.php guarantees.
 */
class EWP_Meta_Inheritance
{

 /**
  * @var EWP_Meta_Inheritance|null the single instance
  */
 protected static $instance = null;

 /**
  * @var array runtime cache of the resolved inheritance maps, keyed by case
  */
 protected $maps = array();

 /**
  * Get the single instance and register the hooks on first call.
  *
  * @return EWP_Meta_Inheritance
  */
 public static function instance()
 {
  if (self::$instance === null) {
   self::$instance = new self();
   self::$instance->init();
  }
  return self::$instance;
 }

 public function init()
 {
  add_filter('awm_add_meta_boxes_filter', array($this, 'inherit_post_boxes'), PHP_INT_MAX);
  add_filter('awm_add_term_meta_boxes_filter', array($this, 'inherit_term_boxes'), PHP_INT_MAX);
 }

 /**
  * Extend the post type meta boxes with the inherited types.
  *
  * @param array $boxes the registered post meta boxes
  * @return array
  */
 public function inherit_post_boxes($boxes)
 {
  return $this->apply($boxes, 'postTypes', $this->map('post_type'));
 }

 /**
  * Extend the taxonomy meta boxes with the inherited taxonomies.
  *
  * @param array $boxes the registered term meta boxes
  * @return array
  */
 public function inherit_term_boxes($boxes)
 {
  return $this->apply($boxes, 'taxonomies', $this->map('taxonomy'));
 }

 /**
  * Append every target whose sources are already attached to a box.
  *
  * @param array  $boxes the registered meta boxes
  * @param string $key   the box key holding the attached objects
  * @param array  $map   target => sources
  * @return array
  */
 protected function apply($boxes, $key, $map)
 {
  if (empty($boxes) || empty($map)) {
   return $boxes;
  }
  foreach ($boxes as $box_id => $box) {
   $attached = isset($box[$key]) && is_array($box[$key]) ? $box[$key] : array();
   if (empty($attached)) {
    continue;
   }
   foreach ($map as $target => $sources) {
    if (in_array($target, $attached, true)) {
     continue;
    }
    if (!empty(array_intersect($sources, $attached))) {
     $attached[] = $target;
    }
   }
   $boxes[$box_id][$key] = array_values(array_unique($attached));
  }
  return $boxes;
 }

 /**
  * Build the target => sources map for a case, with the inheritance chains
  * flattened so A inheriting B which inherits C also gets C's boxes.
  *
  * @param string $case `post_type` or `taxonomy`
  * @return array target => sources
  */
 protected function map($case)
 {
  if (isset($this->maps[$case])) {
   return $this->maps[$case];
  }

  $objects = $case === 'taxonomy'
   ? apply_filters('epw_get_taxonomies', array())
   : apply_filters('epw_get_post_types', array());

  $direct = array();
  if (!empty($objects)) {
   foreach ($objects as $name => $data) {
    $sources = isset($data['inherit_metas']) && is_array($data['inherit_metas']) ? $data['inherit_metas'] : array();
    /*never inherit from self, it would only re-add what is already there*/
    $sources = array_values(array_diff(array_filter($sources), array($name)));
    if (!empty($sources)) {
     $direct[$name] = $sources;
    }
   }
  }

  $map = array();
  foreach ($direct as $target => $sources) {
   $map[$target] = $this->expand($target, $direct);
  }

  /**
   * Filter the resolved meta inheritance map.
   *
   * @param array  $map  target => sources, chains already flattened
   * @param string $case `post_type` or `taxonomy`
   */
  $this->maps[$case] = apply_filters('ewp_meta_inheritance_map_filter', $map, $case);
  return $this->maps[$case];
 }

 /**
  * Walk an inheritance chain, guarding against cycles.
  *
  * @param string $target  the object we resolve the sources for
  * @param array  $direct  target => directly configured sources
  * @param array  $visited the objects already walked in this branch
  * @return array the flattened sources
  */
 protected function expand($target, $direct, $visited = array())
 {
  $visited[] = $target;
  $resolved = array();
  $sources = isset($direct[$target]) ? $direct[$target] : array();
  foreach ($sources as $source) {
   if (in_array($source, $visited, true)) {
    continue;
   }
   $resolved[] = $source;
   $resolved = array_merge($resolved, $this->expand($source, $direct, $visited));
  }
  return array_values(array_unique($resolved));
 }
}

EWP_Meta_Inheritance::instance();
