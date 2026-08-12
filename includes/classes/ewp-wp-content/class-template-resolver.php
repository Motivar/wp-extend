<?php
if (!defined('ABSPATH')) {
 exit;
}

/**
 * Resolves a configured "template source" token into a theme template file.
 *
 * A source token is stored by the post type / taxonomy configuration screens as
 * `post_type:{slug}` or `taxonomy:{slug}` (see ewp_template_source_options()).
 * It lets a post type or taxonomy reuse the archive/single template of another
 * object instead of duplicating theme files.
 */
class EWP_Template_Resolver
{

 const SOURCE_POST_TYPE = 'post_type';
 const SOURCE_TAXONOMY = 'taxonomy';

 const CONTEXT_ARCHIVE = 'archive';
 const CONTEXT_SINGLE = 'single';

 /**
  * Locate the template file a source token points to.
  *
  * @param string $source the stored token, ie `post_type:flx_hotel`
  * @param string $context self::CONTEXT_ARCHIVE or self::CONTEXT_SINGLE
  * @return string absolute path of the located template, empty string when none
  */
 public static function locate($source, $context = self::CONTEXT_ARCHIVE)
 {
  $candidates = self::candidates($source, $context);
  if (empty($candidates)) {
   return '';
  }
  return locate_template($candidates);
 }

 /**
  * Build the template file names to look for, in order of preference.
  *
  * @param string $source the stored token
  * @param string $context self::CONTEXT_ARCHIVE or self::CONTEXT_SINGLE
  * @return array list of template file names
  */
 public static function candidates($source, $context)
 {
  $parts = self::parse($source);
  $candidates = array();

  if (!empty($parts)) {
   switch ($parts['type']) {
    case self::SOURCE_POST_TYPE:
     $candidates = $context === self::CONTEXT_SINGLE
      ? array('single-' . $parts['slug'] . '.php', $parts['slug'] . '.php')
      : array('archive-' . $parts['slug'] . '.php', $parts['slug'] . '-archive.php');
     break;
    case self::SOURCE_TAXONOMY:
     $candidates = array('taxonomy-' . $parts['slug'] . '.php');
     break;
   }
  }

  /**
   * Filter the template file names a source token resolves to.
   *
   * @param array  $candidates template file names, in order of preference
   * @param string $source     the stored token
   * @param string $context    self::CONTEXT_ARCHIVE or self::CONTEXT_SINGLE
   */
  return apply_filters('ewp_template_source_candidates', $candidates, $source, $context);
 }

 /**
  * Split a source token into its type and slug.
  *
  * @param string $source the stored token
  * @return array array with `type` and `slug`, empty array when the token is invalid
  */
 protected static function parse($source)
 {
  if (empty($source) || !is_string($source) || strpos($source, ':') === false) {
   return array();
  }
  list($type, $slug) = explode(':', $source, 2);
  if ($slug === '' || !in_array($type, array(self::SOURCE_POST_TYPE, self::SOURCE_TAXONOMY), true)) {
   return array();
  }
  return array('type' => $type, 'slug' => $slug);
 }
}
