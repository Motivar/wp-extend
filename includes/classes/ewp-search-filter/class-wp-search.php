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
    add_filter('ewp_register_dynamic_assets', [$this, 'register_dynamic_assets']);
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
    $ewp_search_query_terms = array();
    $args = array(
      'post_type' => $conf['post_types'],
      'post_status' => 'publish',
      'posts_per_page' => $conf['limit'],
      'orderby'        => isset($conf['orderby']) ?  $conf['orderby'] : 'modified',// Order by the last modified date.
      'order'          => isset($conf['order']) ?  $conf['order'] : 'DESC', // Order by the last modified date.
      'tax_query' => array(),
      'date_query' => array(),
      'meta_query' => array(),
      'paged' => isset($params['paged']) ? $params['paged'] : 1, /*check the paged parameter*/
      'suppress_filters' => false,
    );
    /*build the query dynamic query*/

    foreach ($conf['query_fields'] as $constructor) {
      $request_key = $constructor['query_key'];
      if (isset($params[$request_key]) && !empty($params[$request_key])) {
        $search_terms = is_array($params[$request_key]) ? array_filter($params[$request_key]) : array($params[$request_key]);
        $search_term = $params[$request_key];
        switch ($constructor['query_type']) {
          case 'taxonomy':
            $search_term = array();
            $tax_query = array(
              'taxonomy' => $constructor['taxonomy'][0],
              'field' => 'term_id',
              'terms' => $search_terms,
            );
            foreach ($search_terms as $term) {
              $search_term[] = get_term($term, $constructor['taxonomy'][0])->name;
            }
            if (count($constructor['taxonomy']) > 1) {
              $tax_query = array('relation' => 'or');
              $search_term = array();
              foreach ($constructor['taxonomy'] as $taxonomy) {
                $tax_query[] = array(
                  'taxonomy' => $taxonomy,
                  'field' => 'term_id',
                  'terms' => $search_terms,
                );
                foreach ($search_terms as $term) {
                  $search_term[] = get_term($term, $taxonomy)->name;
                }
              }
            }
            $search_term = implode(',', $search_term);
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
              case 'orderby':
                $args['orderby'] = $params[$request_key];
                break;
              case 'order':
                $args['order'] = $params[$request_key];
                break;
              case 'search':
                $args['s'] = $params[$request_key];
                break;
              case 'date_from':
                $args['date_query']['after'] = date('c', strtotime($params[$request_key]));
                break;
              case 'date_to':
                $args['date_query']['before'] = date(
                  'c',
                  strtotime($params[$request_key])
                );
                break;
            }

            break;
        }
        $ewp_search_query_terms[$request_key] = (isset($constructor['explanation']) && !empty($constructor['explanation'])) ? sprintf(__('%s %s', 'extend-wp'), $constructor['explanation'], $search_term) : $search_term;
      }
    }
    /*check for wpml and language parameter*/

    global $sitepress;
    if ($sitepress && isset($params['lang'])) {
      $sitepress->switch_lang($params['lang'], true);
    }


    /*check if we have sorting*/
    if (isset($conf['sorting']['show'])) {
      if (isset($params['ewp_sorting']) && !empty($params['ewp_sorting'])) {
        $sorting = $conf['sorting']['options'][$params['ewp_sorting']];
        $args['orderby'] = $sorting['orderby'];
        $args['order'] = $sorting['order'];
        if (!empty($sorting['meta_key']) && !empty($sorting['meta_type'])) {
          $args['meta_key'] = $sorting['meta_key'];
          $args['meta_type'] = $sorting['meta_type'];
        }
      }
    }



    /**
     * Filter: 'ewp_search_query_filter'
     *
     * This filter allows customization of the WP_Query arguments for the search filter.
     *
     * @param array $args The arguments for the WP_Query.
     *   - query: array The query parameters for WP_Query.
     *   - terms: array The terms for the search query.
     * @param array $params The parameters passed in the REST request.
     * @param array $conf The configuration for the search filter.
     */

    return apply_filters('ewp_search_query_filter', array('query' => $args, 'terms' => $ewp_search_query_terms), $params, $conf);
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
      global $ewp_search_id;
      $ewp_search_id = $params['id'];
      $args = $this->query_prepare($params, $conf);
      /*get and set global the results*/
      global $ewp_search_query;
      global $ewp_config;
      global $ewp_params;
      global $ewp_args;
      $ewp_params = $params;
      $ewp_config = $conf;
      $ewp_args =  $args;
      $ewp_search_query = new WP_Query($args['query']);
      /*set the content files */
      $content = array();
      if ($conf['show_search_terms']) {
        global $ewp_search_query_terms;
        $ewp_search_query_terms = $args['terms'];
        $content[] = awm_parse_template(awm_path . 'templates/frontend/search/results-terms.php');
      }
      $main_content = __($conf['empty_results_message'], 'extend-wp');
      if (!empty($ewp_search_query->posts)) {
        $main_content = awm_parse_template(awm_path . 'templates/frontend/search/results.php');
      }
      $content[] = $main_content;
      return rest_ensure_response(new WP_REST_Response(implode('', $content)), 200);
    }
    return rest_ensure_response(new WP_REST_Response(false), 400);
  }

  /**
   * Register search filter script with Dynamic Asset Loader
   * 
   * This method uses the Dynamic Asset Loader to load the search script
   * only when a search form is present on the page, improving performance.
   * 
   * @param array $assets Existing registered assets
   * @return array Modified assets array with search script
   * 
   * @hook ewp_register_dynamic_assets
   */
  public function register_dynamic_assets($assets)
  {
    $version = 0.02;

    $assets[] = array(
      'handle' => 'ewp-search',
      'selector' => '.ewp-search-box',
      'type' => 'script',
      'src' => awm_url . 'assets/js/public/ewp-search-script.js',
      'version' => $version,
      'dependencies' => array(),
      'in_footer' => true,
      'defer' => true,
      'localize' => array(
        'objectName' => 'ewpSearch',
        'data' => array(
          'restUrl' => rest_url('ewp-filter/'),
          'nonce' => wp_create_nonce('wp_rest'),
          'ajaxUrl' => admin_url('admin-ajax.php'),
          'strings' => array(
            'loading' => __('Loading...', 'extend-wp'),
            'noResults' => __('No results found', 'extend-wp'),
            'error' => __('An error occurred', 'extend-wp')
          )
        )
      )
    );

    return $assets;
  }

  private function prepare_form_fields($input_fields, $id, $hash)
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

      switch ($field['case']) {
        case 'select':
        case 'radio':
          $options = array();
          if (!empty($field['options'])) {
            foreach ($field['options'] as $option) {
              $options[$option['option']] = array('label' => __($option['label'], 'extend-wp'));
            }
          }
          $field['options'] = $options;
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
        'attributes' => array('value' => $sitepress->get_current_language())
      );
    }
    /**
     * Filter: 'ewp_search_prepare_form_fields_filter'
     *
     * This filter allows customization of form fields for the search filter.
     *
     * @param array $form_fields The prepared form fields.
     * @param array $input_fields The raw input fields.
     * @param string $id The ID of the search filter.
     */
    return apply_filters('ewp_search_prepare_form_fields_filter', $form_fields, $input_fields, $id, $hash);
  }


  public function ewp_display_search($atts)
  {
    extract(shortcode_atts(array(
      'id' => '',
      'hash' => ''
    ), $atts));
    /*check if empty*/
    if (empty($id) && empty($hash)) {
      return '';
    }

    if (empty($hash)) {
      $hash = awm_get_db_content('ewp_search', array('include' => $id));
      if (empty($hash)) {
        return '';
      }
      $hash = $hash[0]['hash'];
    }

    if (empty($id) && !empty($hash)) {
      $id = awm_get_db_content('ewp_search', array('include_hashes' => $hash));
      if (empty($id)) {
        return '';
      }
      $id = $id[0]['content_id'];
    }

    /*get the fields*/
    $fields = awm_get_db_content_meta('ewp_search', $id);

    /* check if fields isset*/
    if (!isset($fields['query_fields'])) {
      return '';
    }
    /*check the input fields*/
    $input_fields = $fields['query_fields'];
    $form_fields = $this->prepare_form_fields($input_fields, $id, $hash);
    /*check if we have in Request the query keys*/
    if (isset($_REQUEST)) {
      foreach ($_REQUEST as $key => $value) {
        $key = preg_replace('/ewp_/', '', $key, 1);
        if (isset($form_fields[$key])) {
          $form_fields[$key]['attributes']['value'] = $value;
        }
      }
    }


    /*check if we have sorting optio in the form*/

    if (isset($fields['sorting']['show']) && $fields['sorting']['show'] == 'form') {
      $box = ewp_search_sorting_filter($fields['sorting']);
      switch ($fields['sorting']['form_position']) {
        case 'top':
          $form_fields = array_merge($box, $form_fields);
          break;
        case 'bottom':
          $form_fields = array_merge($form_fields, $box);
          break;
      }
    }

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
      'capability' => 'activate_plugins',
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
          'title' => __('Search filter various configuration', 'extend-wp'),
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
    if (!isset($_REQUEST['id']) || empty($_REQUEST['id']) || $_REQUEST['page'] != 'ewp_search_form') {
      return array();
    }
    $guidelines = array(  
      array(
        'text' => __('Change the card path of the displayed result', 'extend-wp'),
        'code' => 'add_filter("ewp_search_result_path",$path,$wp_query);'
      ),
      array(
        'text' => __('Change the card pagination path', 'extend-wp'),
        'code' => 'add_filter("ewp_search_result_pagination_path",$path,$wp_query);'
      ),
      array(
        'text' => __('Javascript trigger event after results ', 'extend-wp'),
        'code' => 'document.addEventListener("ewp_search_results_loaded", function(e) {});'
      ),
      array(
        'text' => __('Javascript init function (useful for ajax page transitions) ', 'extend-wp'),
        'code' => 'ewp_search_forms();'
      ),
      array(
        'text' => __('You can pre-select the values of the search filter by adding the query parameter in the url', 'extend-wp'),
        'code' => __('?<strong>ewp_</strong>query_key=value&<strong>ewp_</strong>query_key2=value2', 'extend-wp')
      )
    );
    $html = '<div class="awm-dev-info">';
    $counter = 0;
    foreach ($guidelines as $guideline) {
      $html .= '<div>' . $counter . '. ' . $guideline['text'] . '<br><code>' . $guideline['code'] . '</code></div>';
      $counter++;
    }
    $html .= '</div>';

    $field = awm_get_db_content('ewp_search', array('include' => $_REQUEST['id']));
    $hash = $field[0]['hash'];
    return array(
      'html' => array(
        'case' => 'html',
        'label' => __('How to user the search filter', 'extend-wp'),
        'show_label' => true,
        'value' => '<div class="awm-dev-info"><div>' . sprintf(__('Use the shortcode <strong>[ewp_search id="%s"]</strong> or <strong>[ewp_search hash="%s"]</strong>, and place it where you wish. If the dom element which you have set to show the results does not exists, then no action will be triggered as you interact with the form.', 'extend-wp'), (isset($_REQUEST['id']) ? $_REQUEST['id'] : '-'), $hash) . '</div></div>'
      ),
      'html2' => array(
        'case' => 'html',
        'label' => __('Hooks you can use', 'extend-wp'),
        'show_label' => true,
        'value' => $html
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
      'orderby' => array(
        'case' => 'select',
        'removeEmpty' => true,
        'options' => array(
          'modified' => array('label' => __('Modified', 'extend-wp')),
          'date' => array('label' => __('Date', 'extend-wp')),
          'title' => array('label' => __('Title', 'extend-wp')),
          'rand' => array('label' => __('Random', 'extend-wp')),
        ),
      ),
      'order' => array(
        'case' => 'select',
        'removeEmpty' => true,
        'options' => array(
          'ASC' => array('label' => __('Ascending', 'extend-wp')),
          'DESC' => array('label' => __('Descending', 'extend-wp')),
        ),
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
      ),
      'run_on_load_empty' => array(
        'case' => 'input',
        'type' => 'checkbox',
        'label' => __('Execute on page load even if form is empty', 'extend-wp'),
        'show-when' => array('run_on_load' => array('values' => true)),
      ),
      'show_search_terms' => array(
        'removeEmpty' => true,
        'label' => __('Show search terms', 'extend-wp'),
        'case' => 'input',
        'type' => 'checkbox',
      ),
      'show_search_results_number' => array(
        'removeEmpty' => true,
        'label' => __('Show search results number', 'extend-wp'),
        'case' => 'input',
        'type' => 'checkbox',
      ),
      'pagination_styles' => array(
        'case' => 'section',
        'label' => __('Pagination configuration', 'extend-wp'),
        'include' => array(
          'load_type' => array(
            'removeEmpty' => true,
            'label' => __('Load posts style', 'extend-wp'),
            'case' => 'select',
            'options' => array(
              'none' => array('label' => __('None', 'extend-wp')),
              'pagination' => array('label' => __('Pagination', 'extend-wp')),
              'button' => array('label' => __('Button', 'extend-wp')),
            ),
          ),
          'load_type_button' => array(
            'case' => 'input',
            'type' => 'text',
            'label_class' => array('awm-needed'),
            'label' => __('Load button label', 'extend-wp'),
            'show-when' => array('load_type' => array('values' => array('button' => true))),
          )
        ),
      ),
      'sorting' => array(
        'case' => 'section',
        'label' => __('Sorting configuration', 'extend-wp'),
        'include' => array(
          'show' => array(
            'removeEmpty' => true,
            'label' => __('Show sorting', 'extend-wp'),
            'case' => 'select',
            'options' => array(
              'none' => array('label' => __('None', 'extend-wp')),
              'form' => array('label' => __('Search form', 'extend-wp')),
              'results' => array('label' => __('Results', 'extend-wp')),
            ),
          ),
          'form_position' => array(
            'removeEmpty' => true,
            'label' => __('Form position', 'extend-wp'),
            'case' => 'select',
            'options' => array(
              'top' => array('label' => __('Top', 'extend-wp')),
              'bottom' => array('label' => __('Bottom', 'extend-wp')),
            ),
            'show-when' => array('show' => array('values' => array('form' => true))),
          ),

          'options' => array(
            'case' => 'repeater',
            'item_name' => __('Sorting Option', 'extend-wp'),
            'show-when' => array('show' => array('values' => array('form' => true, 'results' => true))),
            'prePopulated' => $this->sorting_defaults(),
            'include' => array(
              'label' => array(
                'label' => __('Label', 'extend-wp'),
                'case' => 'input',
                'type' => 'text',
                'label_class' => array('awm-needed'),
              ),
              'orderby' => array(
                'label' => __('Order By', 'extend-wp'),
                'case' => 'input',
                'type' => 'text',
                'label_class' => array('awm-needed'),
              ),
              'order' => array(
                'removeEmpty' => true,
                'label' => __('Order', 'extend-wp'),
                'case' => 'select',
                'options' => array(
                  'ASC' => array('label' => __('Ascending', 'extend-wp')),
                  'DESC' => array('label' => __('Descending', 'extend-wp')),
                ),
                'label_class' => array('awm-needed'),
              ),
              'meta_type' => array(
                'removeEmpty' => true,
                'label' => __('Meta type', 'extend-wp'),
                'case' => 'select',
                'options' => array(
                  'none' => array('label' => __('None', 'extend-wp')),
                  'numeric' => array('label' => __('Numeric', 'extend-wp')),
                  'char' => array('label' => __('Char', 'extend-wp')),

                ),
              ), 'meta_key' => array(
                'label' => __('Meta key', 'extend-wp'),
                'case' => 'input',
                'type' => 'text',
                'explanation' => __('The meta key to order by (if meta type != none', 'extend-wp'),

              )
            ),
          )
        ),
      ),

    );
    return $metas;
  }

  public function sorting_defaults()
  {
    return array(
      array(
        'label' => __('Name ⇈', 'extend-wp'),
        'orderby' => 'title',
        'order' => 'ASC',
        'awm_key' => 1
      ),
      array(
        'label' => __('Name ⇊', 'extend-wp'),
        'orderby' => 'title',
        'order' => 'DESC',
        'awm_key' => 2
      ),
      array(
        'label' => __('Update date ⇈', 'extend-wp'),
        'orderby' => 'modified',
        'order' => 'ASC',
        'awm_key' => 3
      ),
      array(
        'label' => __('Update date ⇊', 'extend-wp'),
        'orderby' => 'modified',
        'order' => 'DESC',
        'awm_key' => 4
      ),

    );
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
    /**
     * Filter: 'ewp_search_fields_configuration_filter'
     *
     * This filter allows customization of the fields configuration for the search filter.
     *
     * @param array $metas The configuration for the search fields.
     */
    return apply_filters('ewp_search_fields_configuration_filter', $metas);
  }
}


new Extend_WP_Search_Filters();