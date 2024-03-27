<?php
if (!defined('ABSPATH')) {
  exit;
}

/**
 * class to extend customizer through fields
 */



class EWP_Dynamic_Blocks
{

  /**
   * Constructor
   */
  static $blocks = false;

  public function __construct()
  {
    add_action('init', [$this, 'register_blocks']);
    add_action('init', [$this, 'register_script']);
    add_action('wp_enqueue_scripts', [$this, 'load_scripts']); // For frontend
    add_action(
      'enqueue_block_editor_assets',
      [$this, 'load_scripts']
    );
  }

  public function load_scripts()
  {
    $blocks = $this->gather_blocks();
    if (empty($blocks)) {
      return;
    }
    foreach ($blocks as $block) {
      foreach ($block['additional_scripts'] as $script) {
        // wp_enqueue_script($script['handle']);
      }
    }
    if (!is_admin()) {
      return;
    }
    wp_localize_script('ewp-gutenburg-blocks', 'ewp_blocks', $blocks);
    wp_enqueue_script('ewp-gutenburg-blocks');
  }


  public function register_script()
  {
    $blocks = $this->gather_blocks();
    foreach ($blocks as $block) {
      foreach ($block['additional_scripts'] as $script) {
        // wp_register_script($script['handle'], $script['src'], $script['dependencies'], $script['version'], $script['in_footer']);
      }
    }
    wp_register_script($script['handle'], $script['src'], $script['dependencies'], $script['version'], $script['in_footer']);
    $asset_file = include(awm_url . 'build/index.asset.php');
    wp_register_script(
      'ewp-gutenburg-blocks',
      awm_url . 'build/index.js',
      array('wp-blocks', 'wp-element', 'wp-editor', 'wp-i18n'),
      $asset_file['version'],
      true
    );
  }

  public function register_blocks()
  {
    $blocks = $this->gather_blocks();
    foreach ($blocks as $block) {
      register_block_type($block['namespace'] . '/' . $block['block_name'], array(
        'attributes' => $block['attributes'],
        'style' => $block['style'],
        'render_callback' => $block['render_callback'],
        'version' => $block['version'],
        'dependencies' => $block['dependencies'],
        'additional_scripts' => $block['additional_scripts'],
        'title' => $block['title'],
        'description' => $block['description'],
        'category' => $block['category'],
        'icon' => $block['icon'],
      ));
    }
    return $blocks;
  }

  public function prepare_attributes($attributes)
  {
    $prepared_attributes = array();
    foreach ($attributes as $key => $attribute) {
      $type = 'string';
      $render_type = 'string';
      switch ($attribute['case']) {
        case 'select':
          $render_type = 'select';
          $options = array();
          foreach ($attribute['options'] as $option_key => $option) {
            $options[$option_key] = $option['label'];
          }
          $attribute['options'] = $options;
          break;
        case 'input':
          switch ($attribute['type']) {
            case 'color':
              $render_type = 'color';
              break;
            case 'number':
              $type = 'number';
              $render_type = 'number';
              break;
            case 'checkbox':
              $type = 'boolean';
              $render_type = 'boolean';
              break;
          }
          break;
        case 'textarea':
          $render_type = 'textarea';
          break;
      }
      $prepared_attributes[$key] = $attribute;
      $prepared_attributes[$key]['type'] = $type;
      $prepared_attributes[$key]['render_type'] = $render_type;
      $prepared_attributes[$key]['default'] = isset($attribute['default']) ? $attribute['default'] : '';
    }
    return $prepared_attributes;
  }

  public function gather_blocks()
  {
    /**
     * get all the awm boxes for customizer
     * @param array all the boxes
     * @return array return all the boxes
     */


    if (self::$blocks) {
      return self::$blocks;
    }
    $blocks = apply_filters('ewp_gutenburg_blocks_filter', array());
    /**
     * sort settings by order
     */
    uasort($blocks, function ($a, $b) {
      $first = isset($a['priority']) ? $a['priority'] : 100;
      $second = isset($b['priority']) ? $b['priority'] : 100;
      return $first - $second;
    });

    foreach ($blocks as &$block) {
      $block['attributes'] = $this->prepare_attributes($block['attributes']);
    }

    self::$blocks = $blocks;
    return $blocks;
  }
}

// Setup the Theme Customizer settings and contro

new EWP_Dynamic_Blocks();


add_filter('ewp_gutenburg_blocks_filter', function ($blocks) {

  $blocks['demo'] = array(
    'namespace' => 'ewp-demo',
    'block_name' => 'test',
    'attributes' => array(
      'text' => array(
        'label' => 'text',
        'case' => 'input',
        'type' => 'text',
      ),
      'text2' => array(
        'label' => 'text2',
        'case' => 'input',
        'type' => 'number',
      ),
      'text3' => array(
        'label' => 'text3',
        'case' => 'input',
        'type' => 'checkbox',
      ),
      'select' => array(
        'label' => 'Select',
        'case' => 'select',
        'options' => array(
          'option1' => array('label' => 'Option 1'),
          'option2' => array('label' => 'Option 12'),
          'option3' => array('label' => 'Option 1e'),
        ),
      ),
      'color' => array(
        'label' => 'Colour',
        'case' => 'input',
        'type' => 'color',
      ),
      'textarear' => array(
        'case' => 'textarea',
        'label' => 'Textarea',
      ),

    ),
    'style' => '',
    'render_callback' => 'render_block',
    'version' => '1.0.0',
    'dependencies' => array('wp-blocks', 'wp-element', 'wp-editor'),
    'additional_scripts' => array(),
    'title' => 'Demo Block',
    'description' => 'Demo Block',
    'category' => 'common',
    'icon' => 'admin-site',
  );

  return $blocks;
});
