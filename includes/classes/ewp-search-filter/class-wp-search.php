<?php
if (!defined('ABSPATH')) {
  exit;
}

/**
 * with this class we set custom db structure to save the custom taxonomies and post types
 */
require_once 'functions.php';
class Extend_WP_Search_Filters
{
  public function __construct()
  {

    add_filter('awm_register_content_db', [$this, 'register_defaults']);
    add_shortcode('ewp_search', [$this, 'ewp_display_search']);
    add_action('init', array($this, 'registerScripts'), 0);
    add_action('wp_enqueue_scripts', array($this, 'addScripts'), 10);
    add_action('rest_api_init', [$this, 'rest_endpoints'], 10);
  }

  /**
   * register the endpoint based on each search
   */
  public function rest_endpoints()
  {
    /*check if we have filters*/
    $filters = awm_get_db_content('ewp_search');
    if (empty($filters)) {
      return true;
    }
    /*set the rest endpoint for each filter*/
    foreach ($filters as $filter) {
      $rest[$filter['content_id']] = array(
        'endpoint' => $filter['content_id'],
        'namespace' =>  'ewp-filter',
        'method' => 'get',
        'args' => array(
          'id' => array(
            'description'       => sprintf(__('The id of the search filter', 'ewp'), $filter['content_id']),
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => $filter['content_id'],
            'required' => true
          )
        ),
        'php_callback' => [$this, 'get_results'],
      );
    }
    /*call class*/
    $d_api = new AWM_Dynamic_API($rest);
    $d_api->register_routes();
  }

  private function query_prepare($params, $conf)
  {
    /*constuct the query*/
    $args = array(
      'post_type' => $conf['post_types'],
      'post_status' => 'publish',
      'posts_per_page' => $conf['limit'],
      'orderby' => 'ID',
      'order' => 'ASC',
      'tax_query' => array(),
      'meta_query' => array(),
      'paged' => isset($params['paged']) ? $params['paged'] : 1, /*check the paged parameter*/
      'suppress_filters' => false,
    );
    /*build the query dynamic query*/

    foreach ($conf['query_fields'] as $constructor) {
      $request_key = $constructor['query_key'];
      if (isset($params[$request_key]) && !empty($params[$request_key])) {
        $search_terms = is_array($params[$request_key]) ? array_filter($params[$request_key]) : array($params[$request_key]);
        switch ($constructor['query_type']) {
          case 'taxonomy':
            $tax_query = array(
              'taxonomy' => $constructor['taxonomy'][0],
              'field' => 'term_id',
              'terms' => $search_terms,
            );
            if (count($constructor['taxonomy']) > 1) {
              $tax_query = array('relation' => 'or');
              foreach ($constructor['taxonomy'] as $taxonomy) {
                $tax_query[] = array(
                  'taxonomy' => $taxonomy,
                  'field' => 'term_id',
                  'terms' => $search_terms,
                );
              }
            }
            $args['tax_query'][] = $tax_query;
            break;
          case 'meta':
            $args['meta_query'][] = array(
              'key' => $constructor['meta_key'],
              'compare' => $constructor['meta_compare'],
              'value' => $params[$request_key],
            );
            break;
          case 'post':
            switch ($constructor['search_type']) {
              case 'search':
                $args['s'] = $params[$request_key];
                break;
              case 'date_from':
                $args['date_query'] = array(
                  'after' => date('c', strtotime($params[$request_key]))
                );
                break;
              case 'date_to':
                $args['date_query'] = array(
                  'before' => date(
                    'c',
                    strtotime($params[$request_key])
                  ),
                );
                break;
            }

            break;
        }
      }
    }
    /*check for wpml and language parameter*/

    global $sitepress;
    if ($sitepress && isset($params['lang'])) {
      $sitepress->switch_lang($params['lang'], true);
    }
    /**
     * change the search filter query qrgs
     *
     * with this filter we change the arguments for the WP_Query
     *
     * @since 3.9.0
     *
     * @param array $args the args for the wp query
     * @param array $params the params from the json request
     * @param array $conf the configuration of the search filter
     */
    return apply_filters('ewp_search_query_filter', $args, $params, $conf);
  }


  public function get_results($request)
  {
    if (isset($request)) {
      $params = $request->get_params();
      /*get the filter configuration*/
      $conf = awm_get_db_content_meta('ewp_search', $params['id']);
      if (empty($conf)) {
        return rest_ensure_response(new WP_REST_Response(false), 400);
      }
      $args = $this->query_prepare($params, $conf);
      /*get and set global the results*/
      global $ewp_search_query;
      $ewp_search_query = new WP_Query($args);
      $content = __($conf['empty_results_message'], 'extend-wp');
      if (empty(!$ewp_search_query->posts)) {
        $content = awm_parse_template(awm_path . 'templates/frontend/search/results.php');
      }
      return rest_ensure_response(new WP_REST_Response($content), 200);
    }
    return rest_ensure_response(new WP_REST_Response(false), 400);
  }

  /**
   * 
   * register styles and script for tippy
   */
  public function registerScripts()
  {
    $version = 0.02;
    //wp_register_style('filox-custom-inputs', flx_url . 'assets/public/css/custom-inputs/custom_inputs.min.css', false, $version);
    wp_register_script('ewp-search', awm_url . 'assets/js/public/ewp-search-script.js', array(), $version, true);
  }
  /**
   * add scripts to run for admin and frontened
   */
  public function addScripts()
  {

    wp_enqueue_script('ewp-search');
  }

  private function prepare_form_fields($input_fields, $id)
  {
    $form_fields = array();
    foreach ($input_fields as &$field) {
      $key = $field['query_key'];
      $field['exclude_meta'] = true;
      /*check for archives to pre-select the values*/
      if (isset($field['label'])) {
        $field['label'] = __($field['label'], 'extend-wp');
      }
      /*fix the attributes*/
      $attributes = array();
      if (isset($field['attributes'])) {
        foreach ($field['attributes'] as $attribute) {
          if (!empty($attribute['label']) && !empty($attribute['value'])) {
            $attributes[$attribute['label']] = $attribute['value'];
          }
        }
      }
      $field['attributes'] = $attributes;


      switch ($field['case']) {
        case 'term':
          if (is_tax()) {
            $obj = get_queried_object();
            if (isset($obj->term_id) && !empty($obj->term_id) && in_array($obj->taxonomy, $field['taxonomy'])) {
              $field['attributes']['value'] = $obj->term_id;
            }
          }
          break;
      }
      $form_fields[$key] = $field;
    }
    /**check for wpml */
    global $sitepress;
    if ($sitepress) {
      $form_fields['lang'] = array(
        'case' => 'input',
        'type' => 'hidden',
        'exclude_meta' => true,
        'attributes' => array('value' => $sitepress->get_default_language())
      );
    }
    return apply_filters('ewp_search_prepare_form_fields_filter', $form_fields, $input_fields, $id);
  }


  public function ewp_display_search($atts)
  {
    extract(shortcode_atts(array(
      'id' => '',
    ), $atts));
    /*check if empty*/
    if (empty($id)) {
      return '';
    }
    /*get the fields*/
    $fields = awm_get_db_content_meta('ewp_search', $id);

    /* check if fields isset*/
    if (!isset($fields['query_fields'])) {
      return '';
    }
    /*check the input fields*/
    $input_fields = $fields['query_fields'];
    $form_fields = $this->prepare_form_fields($input_fields, $id);
    unset($fields['query_fields']);
    $form = '<div id="ewp-search-' . $id . '" class="ewp-search-box ' . $fields['async'] . ' ' . $fields['orientation'] . '" options="' . htmlspecialchars(str_replace('"', '\'', json_encode($fields))) . '" search-id="' . $id . '"><form id="ewp-search-form-' . $id . '">' . awm_show_content(($form_fields)) . '</form>';
    /*check whether search engine is async or not*/
    switch ($fields['async']) {
      case 'not_async':
        $form .= '<div class="ewp-search-actions"><div class="ewp-search-actions-wrapper"><div class="ewp-search-apply" onclick="ewp_apply_search_form(' . $id . ')">' . __($fields['button_apply'], 'extend-wp') . '</div><div class="ewp-search-reset"  onclick="ewp_reset_search_form(' . $id . ')">' . __($fields['button_reset'], 'extend-wp') . '</div></div></div>';
        break;
    }


    $form .= '</div>';
    return $form;
  }

  public function register_defaults($data)
  {
    /*register fields*/
    $data['search'] = array(
      'parent' => 'extend-wp',
      'statuses' => array(
        'enabled' => array('label' => __('Enabled', 'extend-wp')),
        'disabled' => array('label' => __('Disabled', 'extend-wp')),
      ),
      'show_new' => false,
      'list_name' => __('Search filters', 'extend-wp'),
      'list_name_singular' => __('Search Filter', 'extend-wp'),
      'order' => 1,
      'capability' => 'edit_posts',
      'version' => 0.01,
      'metaboxes' => array(
        'display_and_search' => array(
          'title' => __('Search filter configuration', 'extend-wp'),
          'context' => 'normal',
          'priority' => 'high',
          'library' => $this->ewp_search_fields_configuration(),
          'auto_translate' => true,
          'order' => 1,
        ),
        'configuration' => array(
          'title' => __('Search filter configuration', 'extend-wp'),
          'context' => 'normal',
          'priority' => 'high',
          'library' => $this->ewp_search_configuration(),
          'auto_translate' => true,
          'order' => 1,
        ),
        'developer_notes' => array(
          'title' => __('Developer', 'extend-wp'),
          'context' => 'side',
          'priority' => 'low',
          'library' => $this->ewp_search_dev_notes(),
          'order' => 1,
        ),
      )
    );
    return $data;
  }


  public function ewp_search_dev_notes()
  {

    return array(
      'html' => array(
        'case' => 'html',
        'label' => __('How to user the search filter', 'extend-wp'),
        'show_label' => true,
        'value' => '<div class="awm-dev-info"><div>' . sprintf(__('Use the shortcode <strong>[ewp_search id="%s"]</strong>, and place it where you wish. If the dom element which you have set to show the results does not exists, then no action will be triggered as you interact with the form.', 'extend-wp'), (isset($_REQUEST['id']) ? $_REQUEST['id'] : '-')) . '</div></div>'
      ),
      'html2' => array(
        'case' => 'html',
        'label' => __('Hooks you can use', 'extend-wp'),
        'show_label' => true,
        'value' => '<div class="awm-dev-info"><div>1. ' . __('Change the card path of the displayed result', 'extend-wp') . '<br><code>add_filter("ewp_search_result_path",$path,$wp_query);</code></div><div>2. ' . __('Change the card pagination path', 'extend-wp') . '<br><code>add_filter("ewp_search_result_pagination_path",$path,$wp_query);</code></div><div>3. ' . __('Javascript trigger event after results ', 'extend-wp') . '<br><code>document.addEventListener("ewp_search_results_loaded", function(e) {});</code></div><div>3. ' . __('Javascript init function (useful for ajax page transitions) ', 'extend-wp') . '<br><code>ewp_search_forms();</code></div></div>'
      )
    );
  }


  /**
   * metas to configure
   */
  public function ewp_search_configuration()
  {
    $metas = array(
      'post_types' => array(
        'label' => __('Post types to include', 'extend-wp'),
        'case' => 'post_types',
        'attributes' => array('multiple' => true),
        'label_class' => array('awm-needed'),
      ),
      'show_results' => array(
        'case' => 'input',
        'type' => 'text',
        'label_class' => array('awm-needed'),
        'label' => __('The dom element to show the results (like #primary, .main-content > div)', 'extend-wp'),
      ),
      'empty_results_message' => array(
        'case' => 'input',
        'type' => 'text',
        'label_class' => array('awm-needed'),
        'label' => __('Empty results message', 'extend-wp'),
      ),
      'async' => array(
        'removeEmpty' => true,
        'label' => __('Search Method', 'extend-wp'),
        'case' => 'select',
        'options' => array(
          'async' => array('label' => __('Async', 'extend-wp')),
          'not_async' => array('label' => __('Not async', 'extend-wp')),
        ),
      ),

      'orientation' => array(
        'removeEmpty' => true,
        'label' => __('Orientation', 'extend-wp'),
        'case' => 'select',
        'options' => array(
          'horizontal' => array('label' => __('Horizontal', 'extend-wp')),
          'vertical' => array('label' => __('Vertical', 'extend-wp')),
        ),
      ),
      'limit' => array(
        'case' => 'input',
        'type' => 'number',
        'label_class' => array('awm-needed'),
        'label' => __('Results limit', 'extend-wp'),
      ),
      'button_apply' => array(
        'case' => 'input',
        'type' => 'text',
        'label_class' => array('awm-needed'),
        'label' => __('Apply button label', 'extend-wp'),
        'show-when' => array('async' => array('values' => array('not_async' => true))),
      ),
      'button_reset' => array(
        'case' => 'input',
        'type' => 'text',
        'label_class' => array('awm-needed'),
        'label' => __('Reset button label', 'extend-wp'),
        'show-when' => array('async' => array('values' => array('not_async' => true))),
      ),
      'run_on_load' => array(
        'case' => 'input',
        'type' => 'checkbox',
        'label' => __('Execute on page load', 'extend-wp')
      )
    );
    return $metas;
  }

  /**
   * metas to configure
   */
  public function ewp_search_fields_configuration()
  {

    $metas = array(
      'query_fields' => array(
        'item_name' => __('Field', 'extend-wp'),
        'label' => __('Fields', 'extend-wp'),
        'case' => 'repeater',
        'include' => array(
          'label' => array(
            'label' => __('Filter label', 'extend-wp'),
            'case' => 'input',
            'type' => 'text',
            'label_class' => array('awm-needed'),
          ),
          'case' => array(
            'label' => __('Filter input', 'extend-wp'),
            'case' => 'select',
            'options' => awmInputFields(),
            'label_class' => array('awm-needed'),
            'attributes' => array('data-callback' => "awm_get_case_fields"),
          ),
          'case_extras' => array(
            'label' => __('Filter type configuration', 'extend-wp'),
            'case' => 'html',
            'value' => '<div class="awm-field-type-configuration"></div>',
            'exclude_meta' => true,
            'show_label' => true
          ),
          'query_key' => array(
            'label' => __('Query key', 'extend-wp'),
            'case' => 'input',
            'type' => 'text',
            'label_class' => array('awm-needed'),
          ),
          'query_type' => array(
            'label' => __('Query type', 'extend-wp'),
            'case' => 'select',
            'options' => ewp_query_fields(),
            'attributes' => array('data-callback' => "awm_get_query_fields"),
            'label_class' => array('awm-needed'),
          ),
          'query_extras' => array(
            'label' => __('Query configuration', 'extend-wp'),
            'case' => 'html',
            'value' => '<div class="awm-query-type-configuration"></div>',
            'exclude_meta' => true,
            'show_label' => true
          ),
          'attributes' => array(
            'label' => __('Attributes', 'extend-wp'),
            'explanation' => __('like min=0 / onchange=action etc'),
            'minrows' => 0,
            'case' => 'repeater',
            'item_name' => __('Attribute', 'extend-wp'),
            'include' => array(
              'label' => array(
                'label' => __('Attribute label', 'extend-wp'),
                'case' => 'input',
                'type' => 'text',
              ),
              'value' => array(
                'label' => __('Attribute value', 'extend-wp'),
                'case' => 'input',
                'type' => 'text',
              ),
            )
          ),
          'explanation' => array(
            'label' => __('User message', 'extend-wp'),
            'case' => 'input',
            'type' => 'text',
            'attributes' => array('placeholder' => __('Guidelines for the user', 'extend-wp')),
          ),
        )
      )
    );
    return apply_filters('ewp_search_fields_configuration_filter', $metas);
  }
}


new Extend_WP_Search_Filters();


/*
2. rest-api να δω με το pagination τι θα γίνει
*/