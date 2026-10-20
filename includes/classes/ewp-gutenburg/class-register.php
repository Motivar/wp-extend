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
   * Block definitions from `ewp_gutenburg_blocks_filter`, names cleaned and
   * sorted, attributes not prepared yet. Null until first gathered.
   *
   * @var array|null
   */
  private static $definitions = null;

  /**
   * Gathered blocks without resolved option callbacks (registration, REST).
   *
   * @var array|null
   */
  static $blocks = null;

  /**
   * Gathered blocks with select options resolved (block editor payload).
   *
   * @var array|null
   */
  private static $editor_blocks = null;

  /**
   * Keys of a field definition that hold PHP callables. They never reach the
   * editor payload: json-encoding an object callback would serialise the
   * object's public properties.
   */
  const CALLABLE_KEYS = array('callback', 'callback_variables', 'render_callback');

  /** @var EWP_Dynamic_Blocks|null The instance Setup created. */
  private static $instance = null;

  /**
   * The registry instance created at load time.
   *
   * @return EWP_Dynamic_Blocks
   *
   * @since 1.5.0
   */
  public static function instance()
  {
    if (self::$instance === null) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * Constructor hooks into WordPress to register blocks, scripts, and REST API endpoints.
   */
  public function __construct()
  {
    if (self::$instance === null) {
      self::$instance = $this;
    }
    // Register blocks and scripts during WordPress initialization.
    add_action('init', [$this, 'register_blocks']);
    add_action('init', [$this, 'register_script']);

    // The editor payload (with resolved select options) is only built for the block editor.
    add_action('enqueue_block_editor_assets', [$this, 'load_scripts']);

    // Register REST API endpoints for the blocks.
    add_action('rest_api_init', [$this, 'rest_endpoints']);
    // Register for field UI usage - delayed to init to prevent early translation loading
    add_action('init', function () {
      add_filter('awm_position_options_filter', [$this, 'awm_position_options_filter']);
    });
  }

  /*
  * Add block creation options to the field UI
  */
  public function awm_position_options_filter($options)
  {
    // Prevent translation loading before init action
    if (!did_action('init')) {
      return $options;
    }

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
   * Declares one REST-only preview resource per block
   * (`GET {namespace}/{name}/preview`, generated by the kit).
   *
   * Runs on `rest_api_init`, like the content-type routes, because blocks
   * are gathered on `init` and must not be cached earlier.
   */
  public function rest_endpoints()
  {
    $surfaces = \EWP\Surfaces\EWP_Surfaces::instance();

    foreach ($this->gather_blocks() as $block) {
      $surfaces->register_block_preview_routes($block, $this);
    }
  }

  /**
   * Hands the blocks to the editor script, on `enqueue_block_editor_assets`.
   *
   * This is the only place select option callbacks run: the payload is built
   * from editor_blocks() and only for the block editor (post, site and widget
   * editors), never on front-end or other admin requests.
   *
   * @return void
   *
   * @since 1.5.8 Hooked on `enqueue_block_editor_assets` only; uses editor_blocks().
   */
  public function load_scripts()
  {
    $blocks = $this->editor_blocks();
    if (empty($blocks)) {
      return;
    }
    wp_localize_script('ewp-gutenberg-blocks', 'ewp_blocks', $blocks);
    wp_enqueue_script('ewp-gutenberg-blocks');
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

      $block_name = $block['namespace'] . '/' . $block['name'];
      if (WP_Block_Type_Registry::get_instance()->is_registered($block_name)) {
        continue; // Skip this block as it's already registered
      }

      register_block_type($block_name, array(
        'api_version' => 3,
        'attributes' => $this->attribute_schema(isset($block['attributes']) ? $block['attributes'] : array()),
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
        'icon' => $this->block_icon($block),
      ));
    }
    return $blocks;
  }
  /**
   * The attribute schema WordPress needs for a block: `type` and `default`
   * per attribute. Labels, options and the other editor settings travel in
   * the editor payload only, so they stay out of `/wp/v2/block-types` and the
   * block type registry.
   *
   * @param array $attributes Prepared attributes (see prepare_attributes()).
   * @return array<string, array{type: string, default: mixed}>
   *
   * @since 1.5.8
   */
  private function attribute_schema($attributes)
  {
    $schema = array();
    foreach ((array) $attributes as $key => $attribute) {
      $schema[$key] = array(
        'type' => isset($attribute['type']) ? $attribute['type'] : 'string',
        'default' => isset($attribute['default']) ? $attribute['default'] : '',
      );
    }
    return $schema;
  }

  /**
   * The icon a block registers with: its own `icon`, or `admin-site` when unset or empty.
   *
   * @param array $block Block definition from gather_blocks().
   * @return string|array Dashicon slug, SVG markup or icon object.
   */
  private function block_icon(array $block)
  {
    return !empty($block['icon']) ? $block['icon'] : 'admin-site';
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
   * Render a block preview for the editor.
   *
   * Only the block's registered render callback runs. Before 1.5.0 the
   * request could name any existing PHP function via a `php_callback`
   * argument.
   *
   * @param array $attributes Block attributes (every request parameter).
   * @param array $block      Block definition from gather_blocks().
   *
   * @return string|WP_Error Rendered HTML, wrapped with data attributes when the block has a script.
   *
   * @since 1.5.0 Replaces handle_rest_callback(WP_REST_Request).
   */
  public function render_preview(array $attributes, array $block)
  {
    unset($attributes['php_callback']);

    $validated_callback = isset($block['render_callback']) ? $this->check_callback($block['render_callback']) : '';

    if (empty($validated_callback)) {
      return new WP_Error('ewp_block_invalid_callback', __('Invalid or missing render callback', 'extend-wp'), ['status' => 400]);
    }

    $content = call_user_func_array($validated_callback, array($attributes));

    // Wrap content with data attributes for event handling
    if (!empty($content) && !empty($block['script'])) {
      $data_attrs = ' data-block-script="' . esc_attr($block['script']) . '"'
        . ' data-block-namespace="' . esc_attr($block['namespace']) . '"'
        . ' data-block-name="' . esc_attr($block['name']) . '"';
      $content = '<div class="ewp-dynamic-block"' . $data_attrs . '>' . $content . '</div>';
    }

    return (string) $content;
  }



  /**
   * Prepares block attributes: field case to editor control, attribute type
   * and default.
   *
   * Select options are only resolved with `$resolve_options` (the editor
   * payload). Without it a select keeps its static `options`, if it declared
   * any, and its `callback` is never called, so registering blocks on every
   * request does not run option queries. A select with `object_search` never
   * resolves its options: the editor searches them over REST.
   *
   * @param array|string $attributes      Block attributes, or a function name returning them.
   * @param string       $block_name      Name of the block being processed.
   * @param bool         $resolve_options Optional. Run select option callbacks. Default false.
   * @return array Prepared attributes.
   *
   * @since 1.5.8 Added `$resolve_options` and `object_search`.
   */
  private function prepare_attributes($attributes, $block_name, $resolve_options = false)
  {
    $prepared_attributes = array();

    if (!is_array($attributes) && is_string($attributes) && function_exists($attributes)) {
      $attributes = call_user_func($attributes);
    }
    if (!is_array($attributes) || empty($attributes)) {
      return array();
    }
    foreach ($attributes as $key => $attribute) {
      $search = $this->object_search_config($attribute);
      $attribute = awm_prepare_field($attribute, $block_name, $resolve_options && $search === null);
      $type = 'string';
      $render_type = 'string';
      switch ($attribute['case']) {
        case 'select':
          $render_type = 'select';
          if ($search !== null) {
            $render_type = 'object_search';
            $attribute['object_search'] = $search;
            $attribute['options'] = array();
          }
          $options = array();
          foreach ((array) (isset($attribute['options']) ? $attribute['options'] : array()) as $option_key => $option) {
            $prepared_option = array('option' => $option_key, 'label' => $option['label']);
            // Dependent options: when the field sets `filter_options_by` (another
            // attribute key), an option with `filter_values` is only offered while
            // that attribute holds one of these values. Options without it are
            // always offered. Applied client-side in src/index.js.
            if (isset($option['filter_values']) && is_array($option['filter_values'])) {
              $prepared_option['filter_values'] = array_values(array_map('strval', $option['filter_values']));
            }
            $options[] = $prepared_option;
          }
          $attribute['options'] = $options;
          // Detect multiple attribute and propagate to JS
          $is_multiple = isset($attribute['attributes']['multiple']) && $attribute['attributes']['multiple'];
          if ($is_multiple) {
            $type = 'array';
            $attribute['multiple'] = true;
          }
          break;
        case 'input':
          $attribute_type = (isset($attribute['type']) && !empty($attribute['type'])) ? $attribute['type'] : 'text'; // Default to 'text' if not set
          switch ($attribute_type) {
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
        case 'awm_gallery':
          $render_type = 'gallery';
          $type = 'array';
          break;
        case 'image':
          $render_type = 'image';
          $type = 'string';
          break;
      }
      $prepared_attributes[$key] = $attribute;
      $prepared_attributes[$key]['type'] = $type;
      $prepared_attributes[$key]['render_type'] = $render_type;
      $prepared_attributes[$key]['default'] = isset($attribute['default']) ? $attribute['default'] : ($type === 'array' ? array() : '');
    }

    return $prepared_attributes;
  }

  /**
   * Normalised `object_search` settings of a select attribute, or null when
   * it has none.
   *
   * A select with `object_search` gets a search-as-you-type control in the
   * block editor (`extend-wp/v1/objects/search`) instead of a full option
   * list. Settings:
   * - `object_type` (string) fixed `{group}:{slug}`, e.g. `post_type:page`.
   * - `type_from` (string) attribute whose value is the slug to search,
   *   e.g. `post_type`; used when `object_type` is empty.
   * - `group` (string) `post_type` (default), `taxonomy` or `custom_content`,
   *   prefixed to the `type_from` value.
   * - `limit` (int) results per search, default 20.
   * - `search_meta` (bool) also match meta values, default false.
   *
   * @param array $attribute Field definition.
   * @return array|null
   *
   * @since 1.5.8
   */
  private function object_search_config($attribute)
  {
    if (!is_array($attribute) || empty($attribute['object_search'])) {
      return null;
    }
    $config = is_array($attribute['object_search']) ? $attribute['object_search'] : array();
    $group = isset($config['group']) ? sanitize_key($config['group']) : 'post_type';
    return array(
      'object_type' => isset($config['object_type']) ? (string) $config['object_type'] : '',
      'type_from' => isset($config['type_from']) ? (string) $config['type_from'] : '',
      'group' => in_array($group, array('post_type', 'taxonomy', 'custom_content'), true) ? $group : 'post_type',
      'limit' => isset($config['limit']) ? max(1, absint($config['limit'])) : 20,
      'search_meta' => !empty($config['search_meta']),
    );
  }

  /**
   * A gathered block without its PHP callables, for the editor payload.
   *
   * @param array $block Gathered block.
   * @return array
   *
   * @since 1.5.8
   */
  private function editor_block($block)
  {
    foreach (self::CALLABLE_KEYS as $callable_key) {
      unset($block[$callable_key]);
    }
    foreach ((array) (isset($block['attributes']) ? $block['attributes'] : array()) as $key => $attribute) {
      foreach (self::CALLABLE_KEYS as $callable_key) {
        unset($block['attributes'][$key][$callable_key]);
      }
    }
    return $block;
  }

  /**
   * Block definitions from `ewp_gutenburg_blocks_filter`, sorted by
   * `priority` with namespace and name cleaned. The filter runs once per
   * request.
   *
   * @return array
   *
   * @since 1.5.8
   */
  private function definitions()
  {
    if (self::$definitions !== null) {
      return self::$definitions;
    }
    /**
     * Blocks to register. Runs on every request (on `init`), so block
     * definitions must stay cheap: list select options through a field
     * `callback`, which only runs for the block editor, or `object_search`.
     *
     * @param array $blocks Block id => definition.
     */
    $blocks = apply_filters('ewp_gutenburg_blocks_filter', array());
    if (empty($blocks) || !is_array($blocks)) {
      self::$definitions = array();
      return self::$definitions;
    }
    uasort($blocks, function ($a, $b) {
      $first = isset($a['priority']) ? $a['priority'] : 100;
      $second = isset($b['priority']) ? $b['priority'] : 100;
      return $first - $second;
    });
    foreach ($blocks as &$block) {
      $block['namespace'] = preg_replace("/[^a-zA-Z0-9]/", "", awm_clean_string($block['namespace']));
      $block['name'] = preg_replace("/[^a-zA-Z0-9]/", "", awm_clean_string($block['name']));
    }
    unset($block);
    self::$definitions = $blocks;
    return self::$definitions;
  }

  /**
   * Blocks with prepared attributes, select option callbacks not run.
   * Used for registration and the preview routes on every request.
   *
   * @return array Processed blocks ready for registration.
   *
   * @since 1.5.8 Option callbacks are no longer resolved here (see editor_blocks()).
   */
  public function gather_blocks()
  {
    if (self::$blocks !== null) {
      return self::$blocks;
    }
    self::$blocks = $this->prepare_blocks(false);
    return self::$blocks;
  }

  /**
   * Blocks for the editor script: select options resolved, PHP callables
   * removed. Only load_scripts() (block editor) builds this.
   *
   * @return array
   *
   * @since 1.5.8
   */
  public function editor_blocks()
  {
    if (self::$editor_blocks !== null) {
      return self::$editor_blocks;
    }
    self::$editor_blocks = array_map(array($this, 'editor_block'), $this->prepare_blocks(true));
    return self::$editor_blocks;
  }

  /**
   * The block definitions with their attributes prepared.
   *
   * @param bool $resolve_options Run select option callbacks.
   * @return array
   */
  private function prepare_blocks($resolve_options)
  {
    $blocks = $this->definitions();
    foreach ($blocks as &$block) {
      $block['attributes'] = $this->prepare_attributes(isset($block['attributes']) ? $block['attributes'] : array(), $block['name'], $resolve_options);
    }
    unset($block);
    return $blocks;
  }

  /**
   * Forget the gathered blocks so the next call reads the filter again.
   * For tests and for code that adds blocks after `init`.
   *
   * @return void
   *
   * @since 1.5.8
   */
  public static function reset()
  {
    self::$definitions = null;
    self::$blocks = null;
    self::$editor_blocks = null;
  }
}

// Setup the Theme Customizer settings and contro

new EWP_Dynamic_Blocks();