<?php
// Block direct access to the file for security.
if (!defined('ABSPATH')) {
  exit;
}

/**
 * EWP_Dynamic_Blocks class extends Gutenberg block functionality
 * with dynamic registration and REST API extensions.
 *
 * It aims to provide a framework for registering custom Gutenberg blocks
 * and corresponding REST API endpoints in a secure and optimized manner.
 */
class EWP_Dynamic_Blocks
{
  /**
   * Holds the block information to prevent redundant processing.
   *
   * @var array|bool $blocks Cache for the gathered blocks.
   */
  static $blocks = false;

  /**
   * Constructor hooks into WordPress to register blocks, scripts, and REST API endpoints.
   */
  public function __construct()
  {
    // Register blocks and scripts during WordPress initialization.
    add_action('init', [$this, 'register_blocks']);
    add_action('init', [$this, 'register_script']);

    // Enqueue scripts in the frontend and block editor.
    add_action('wp_enqueue_scripts', [$this, 'load_scripts']);
    add_action('enqueue_block_editor_assets', [$this, 'load_scripts']);

    // Register REST API endpoints for the blocks.
    add_action('rest_api_init', [$this, 'rest_endpoints']);
    // Register for field UI usage
    add_filter('awm_position_options_filter', [$this, 'awm_position_options_filter']);
  }

  /*
  * Add block creation options to the field UI
  */
  public function awm_position_options_filter($options)
  {

    $options['ewp_block'] = array('label' => __('Block creation', 'extend-wp'), 'field-choices' =>
    array(

      'render_callback' => array(
        'label' => __('Render callback', 'extend-wp'),
        'case' => 'input',
        'type' => 'text',
        'explanation' => __('Use a valid php function. Use <b>$attributes</b> as variable in the function.', 'extend-wp'),
        'label_class' => array('awm-needed'),
      ),
      'style' => array(
        'label' => __('Style', 'extend-wp'),
        'case' => 'input',
        'type' => 'text',
        'explanation' => __('The style of the block (handle)', 'extend-wp'),
      ),
      'script' => array(
        'label' => __('Script', 'extend-wp'),
        'case' => 'input',
        'type' => 'text',
        'explanation' => __('The script for the block (handle)', 'extend-wp'),
      ),
      'category' => array(
        'label' => __('Block category', 'extend-wp'),
        'case' => 'select',
        'removeEmpty' => true,
        'options' => array(
          'design' => array('label' => __('Design', 'extend-wp')),
          'text' => array('label' => __('Text', 'extend-wp')),
          'widgets' => array('label' => __('Widgets', 'extend-wp')),
          'embed' => array('label' => __('Embeds', 'extend-wp')),
          'theme' => array('label' => __('Theme', 'extend-wp')),
        ),
        'explanation' => __('The panel name to show if no panel_id is filled in', 'extend-wp'),
      ),
      'namespace' => array(
        'label' => __('Namespace', 'extend-wp'),
        'case' => 'input',
        'type' => 'text',
        'explanation' => __('If you leave empty ewp-block will be used', 'extend-wp'),

      ),
      'name' => array(
        'label' => __('Name', 'extend-wp'),
        'case' => 'input',
        'type' => 'text',
        'explanation' => __('If you leave empty. the id of the field will be used', 'extend-wp'),
      ),
      'description' => array(
        'label' => __('Description', 'extend-wp'),
        'case' => 'input',
        'type' => 'text',
        'explanation' => __('The description of the block', 'extend-wp'),
      ),
      'icon' => array(
        'label' => __('Icon', 'extend-wp'),
        'case' => 'input',
        'type' => 'text',
        'explanation' => __('The icon of the block', 'extend-wp'),
      ),
      'dependencies' => array(
        'case' => 'input',
        'type' => 'text',
        'label' => __('Dependencies', 'extend-wp'),
        'explanation' => __('Split dependencies with comma', 'extend-wp'),

      )
    ));
    return $options;
  }

  /**
   * Registers REST API endpoints for each block.
   */
  public function rest_endpoints()
  {
    $blocks = $this->gather_blocks();

    foreach ($blocks as $block) {
      register_rest_route($block['namespace'] . '/' . $block['name'], '/preview', [
        'methods' => 'GET',
        'auth_callback' => function () {
          return current_user_can('edit_posts');
        },
        'permission_callback' => function () {
          return is_user_logged_in();
        },
        'callback' => [$this, 'handle_rest_callback'],
        'args' => [
          'php_callback' => [
            'type' => 'string|array',
            'default' =>  $block['render_callback'],
          ],
        ],
      ]);
    }
  }

  /**
   * Enqueues scripts for the registered blocks.
   *
   * Dynamically loads additional scripts specified by blocks and
   * ensures they are loaded only where necessary.
   */
  public function load_scripts()
  {
    $blocks = $this->gather_blocks();
    if (empty($blocks) || !is_array($blocks)) {
      return;
    }
    // Conditionally enqueue editor scripts only in admin context.
    if (is_admin()) {
      wp_localize_script('ewp-gutenberg-blocks', 'ewp_blocks', $blocks);
      wp_enqueue_script('ewp-gutenberg-blocks');
    }
  }

  /**
   * Registers the main script for the Gutenberg blocks.
   *
   * Includes script dependencies, versioning, and footer loading optimizations.
   */
  public function register_script()
  {
    $asset_file = include(awm_path . 'build/index.asset.php'); // Ensure awm_path is defined and secure.

    wp_register_script(
      'ewp-gutenberg-blocks',
      awm_url . 'build/index.js', // Ensure awm_url is defined and secure.
      $asset_file['dependencies'],
      $asset_file['version'],
      true // Load in footer for performance.
    );
  }
  /**
   * Registers all custom Gutenberg blocks based on gathered information.
   */
  public function register_blocks()
  {
    $blocks = $this->gather_blocks();
    if (empty($blocks) || !is_array($blocks)) {
      return;
    }
    $core_dependencies = array('wp-blocks', 'wp-element', 'wp-i18n', 'wp-editor');
    foreach ($blocks as $block) {

      $dependencies = array_merge($core_dependencies, isset($block['dependencies']) ? $block['dependencies'] : array());
      register_block_type($block['namespace'] . '/' . $block['name'], array(
        'attributes' => (isset($block['attributes']) && !empty($block['attributes'])) ? $block['attributes'] : array(),
        'editor_style' => isset($block['style']) ? $block['style'] : '',
        'style' => isset($block['style']) ? $block['style'] : '',
        'editor_script' => (isset($block['script']) && !empty($block['script'])) ? $block['script'] : '',
        'script' => (isset($block['script']) && !empty($block['script'])) ? $block['script'] : '',
        'render_callback' => $this->check_callback($block['render_callback']),
        'version' => $block['version'],
        'dependencies' => $dependencies,
        'title' => isset($block['title']) ? $block['title'] : $block['name'],
        'description' => (isset($block['description']) && !empty($block['description'])) ? $block['description'] : '',
        'category' => (isset($block['category']) && !empty($block['category'])) ? $block['category'] : 'common',
        'icon' => (isset($block['icon ']) && !empty($block['icon '])) ? $block['icon'] : 'admin-site',
      ));
    }
    return $blocks;
  }
  /*
    * Check if the callback is valid
    */
  public function check_callback($callback)
  {
    $callback = ((is_string($callback) && function_exists($callback)) ? $callback : (is_array($callback) && method_exists($callback[0], $callback[1]))) ? $callback : '';
    return $callback;
  }
  /**
   * Handles REST API callbacks for block previews.
   *
   * @param WP_REST_Request $request REST API request object.
   * @return WP_REST_Response REST API response object.
   */
  public function handle_rest_callback($request)
  {
    $attributes = $request->get_params();
    $content = call_user_func_array($attributes['php_callback'], array($attributes));
    $response = new WP_REST_Response($content);
    // Set Content-Type header to text/html
    $response->set_headers(['Content-Type' => 'text/html; charset=UTF-8']);
    // Optionally set the status code, default is 200
    $response->set_status(200);
    return $response;
  }



  /**
   * Prepares and sanitizes block attributes for secure block registration.
   *
   * @param array $attributes Block attributes to be sanitized.
   * @param string $block_name Name of the block being processed.
   * @return array Sanitized attributes.
   */
  private function prepare_attributes($attributes, $block_name)
  {
    $prepared_attributes = array();

    if (!is_array($attributes) && is_string($attributes) && function_exists($attributes)) {
      $attributes = call_user_func($attributes);
    }
    if (!is_array($attributes) || empty($attributes)) {
      return array();
    }
    /*
      * Filter attributes
      */


    foreach ($attributes as $key => $attribute) {
      $attribute = awm_prepare_field($attribute, $block_name);
      $type = 'string';
      $render_type = 'string';
      switch ($attribute['case']) {
        case 'select':
          $render_type = 'select';
          $options = array();
          foreach ($attribute['options'] as $option_key => $option) {
            $options[] = array('option' => $option_key, 'label' => $option['label']);
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
          $wp_editor = isset($attribute['wp_editor']) ? $attribute['wp_editor'] : (isset($attribute['attributes']['wp_editor']) ? $attribute['attributes']['wp_editor'] : false);
          $attribute['wp_editor'] = $wp_editor;
          break;
      }
      $prepared_attributes[$key] = $attribute;
      $prepared_attributes[$key]['type'] = $type;
      $prepared_attributes[$key]['render_type'] = $render_type;
      $prepared_attributes[$key]['default'] = isset($attribute['default']) ? $attribute['default'] : '';
    }
    return $prepared_attributes;
  }

  /**
   * Gathers and prepares blocks for registration.
   *
   * @return array Processed blocks ready for registration.
   */
  public function gather_blocks()
  {


    if (self::$blocks) {
      return self::$blocks;
    }
    $blocks = apply_filters('ewp_gutenburg_blocks_filter', array());
    /**
     * sort settings by order
     */
    if (!empty($blocks) && is_array($blocks)) {
      
    uasort($blocks, function ($a, $b) {
        $first = isset($attribute['priority']) ? $attribute['priority'] : 100;
      $second = isset($b['priority']) ? $b['priority'] : 100;
      return $first - $second;
    });

    foreach ($blocks as &$block) {
        $block['namespace'] = preg_replace("/[^a-zA-Z0-9]/", "", awm_clean_string($block['namespace']));
        $block['name'] = preg_replace("/[^a-zA-Z0-9]/", "", awm_clean_string($block['name']));
      $block['attributes'] = $this->prepare_attributes($block['attributes'], $block['name']);
    }
    }
  
    self::$blocks = $blocks;
    return $blocks;
  }
}

// Setup the Theme Customizer settings and contro

new EWP_Dynamic_Blocks();