<?php

namespace MTVReviews;

if (!defined('ABSPATH')) {
 exit;
}

class Block
{

 public function __construct()
 {
  add_filter('ewp_gutenburg_blocks_filter', [$this, 'block']);
 }

 public function block($blocks)
 {
  $blocks['demo'] = array(
   'namespace' => 'mtv',
   'name' => 'reviews',
   'attributes' => array(
    'taxonomy_ids' => array(
     'label' => 'Taxonomy IDs',
     'case' => 'input',
     'type' => 'text',
    ),
    'amount' => array(
     'label' => 'Number of reviews',
     'case' => 'input',
     'type' => 'number',
    ),
    'select' => array(
     'label' => 'View',
     'case' => 'select',
     'options' => array(
      'v1' => array('label' => 'V1'),
      'v2' => array('label' => 'V2'),
      'v3' => array('label' => 'V3'),
     ),
    )

   ),
   'editor_style' => MTV_REVIEW_PATH . 'assets/css/style.min.css',
   'render_callback' => 'renddder_block',
   'version' => 1,
   'dependencies' => array('wp-blocks', 'wp-element', 'wp-editor'),
   'additional_scripts' => array(),
   'title' => 'Reviews Block',
   'description' => 'Show the reviews block.',
   'category' => 'design',
   'icon' => 'admin-site',
  );

  return $blocks;
 }
}

function renddder_block($attributes)
{
 return '<div>' . implode(',', $attributes) . '</div>';
}
