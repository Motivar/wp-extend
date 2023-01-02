[
    {
        "name": "ewp_column_{$this->page_id}_column_content_filter",
        "summary": null,
        "desc": null,
        "since": null,
        "params": [
            {
                "name": "''",
                "type": null,
                "description": null
            },
            {
                "name": "$item",
                "type": null,
                "description": null
            },
            {
                "name": "$column_name",
                "type": null,
                "description": null
            }
        ],
        "file": ".\/includes\/awm-list-tables\/class-list-table.php"
    },
    {
        "name": "ewp_row_actions_{$this->page_id}_filter",
        "summary": null,
        "desc": null,
        "since": null,
        "params": [
            {
                "name": "$actions",
                "type": null,
                "description": null
            },
            {
                "name": "$item",
                "type": null,
                "description": null
            }
        ],
        "file": ".\/includes\/awm-list-tables\/class-list-table.php"
    },
    {
        "name": "ewp_query_fields_filter",
        "summary": "function to show the fields available for user to choose and create a form",
        "desc": "",
        "since": null,
        "params": [
            {
                "name": "array('post' => array('label' => __('WP Post object', 'extend-wp')), 'meta' => array('label' => __('WP Meta', 'extend-wp'), 'field-choices' => array('meta_key' => array('label' => __('Meta key', 'extend-wp'), 'case' => 'input', 'type' => 'text', 'label_class' => array('awm-needed')), 'meta_compare' => array('label' => __('Compare function', 'extend-wp'), 'case' => 'input', 'type' => 'text', 'label_class' => array('awm-needed')))), 'taxonomy' => array('label' => __('WP taxonomy', 'extend-wp'), 'field-choices' => array('compare_type' => array('label' => __('Compare operator', 'extend-wp'), 'case' => 'select', 'options' => array('in' => array('label' => 'IN'), 'not_in' => array('label' => 'NOT IN')), 'label_class' => array('awm-needed')))))",
                "type": null,
                "description": null
            }
        ],
        "file": ".\/includes\/ewp-search\/functions.php"
    },
    {
        "name": "ewp_search_query_filter",
        "summary": "change the search filter query qrgs",
        "desc": "with this filter we change the arguments for the WP_Query",
        "since": "3.9.0",
        "params": [
            {
                "name": "$args",
                "type": "array",
                "description": "the args for the wp query"
            },
            {
                "name": "$params",
                "type": "array",
                "description": "the params from the json request"
            },
            {
                "name": "$conf",
                "type": "array",
                "description": "the configuration of the search filter"
            }
        ],
        "file": ".\/includes\/ewp-search\/class-wp-search.php"
    },
    {
        "name": "ewp_search_fields_configuration_filter",
        "summary": null,
        "desc": null,
        "since": null,
        "params": [
            {
                "name": "$metas",
                "type": null,
                "description": null
            }
        ],
        "file": ".\/includes\/ewp-search\/class-wp-search.php"
    },
    {
        "name": "ewp_whitelabel_filter",
        "summary": null,
        "desc": null,
        "since": null,
        "params": [
            {
                "name": "__('Extend WP', 'extend-wp')",
                "type": null,
                "description": null
            }
        ],
        "file": ".\/includes\/awm-content-db-api\/class-defaults.php"
    },
    {
        "name": "ewp_roles_access_filter",
        "summary": null,
        "desc": null,
        "since": null,
        "params": [
            {
                "name": "$connections",
                "type": null,
                "description": null
            }
        ],
        "file": ".\/includes\/ewp-wp-content\/ewp_wp_functions.php"
    },
    {
        "name": "ewp_get_taxonomies_filter",
        "summary": null,
        "desc": null,
        "since": null,
        "params": [
            {
                "name": "$taxes",
                "type": null,
                "description": null
            },
            {
                "name": "$taxonomies",
                "type": null,
                "description": null
            }
        ],
        "file": ".\/includes\/ewp-wp-content\/class-wp-content.php"
    },
    {
        "name": "ewp_get_post_types_filter",
        "summary": null,
        "desc": null,
        "since": null,
        "params": [
            {
                "name": "$types",
                "type": null,
                "description": null
            },
            {
                "name": "$post_types",
                "type": null,
                "description": null
            }
        ],
        "file": ".\/includes\/ewp-wp-content\/class-wp-content.php"
    },
    {
        "name": "ewp_post_type_users_filter",
        "summary": null,
        "desc": null,
        "since": null,
        "params": [
            {
                "name": "array('fullAccess' => array('case' => 'user_roles', 'exclude' => array('administrator'), 'attributes' => array('multiple' => true), 'label' => __('Select user roles with full edit access', 'extend-wp')), 'semiAccess' => array('case' => 'user_roles', 'exclude' => array('administrator'), 'attributes' => array('multiple' => true), 'label' => __('Select user roles with restricted edit access ', 'extend-wp')))",
                "type": null,
                "description": null
            }
        ],
        "file": ".\/includes\/ewp-wp-content\/class-wp-content.php"
    },
    {
        "name": "ewp_post_type_fields_creation_filter",
        "summary": null,
        "desc": null,
        "since": null,
        "params": [
            {
                "name": "array('post_name' => array('label' => __('Post label', 'extend-wp'), 'case' => 'input', 'type' => 'text', 'class' => array('awm-lowercase'), 'attributes' => array('title' => 'English only!', 'pattern' => '[\\\\x00-\\\\x7F]+'), 'label_class' => array('awm-needed')), 'plural' => array('label' => __('Name plural', 'extend-wp'), 'case' => 'input', 'type' => 'text', 'label_class' => array('awm-needed')), 'singular' => array('label' => __('Name singular', 'extend-wp'), 'case' => 'input', 'type' => 'text', 'label_class' => array('awm-needed')), 'prefix' => array('label' => __('Registration prefix', 'extend-wp'), 'case' => 'input', 'type' => 'text'), 'gallery' => array('label' => __('Has gallery', 'extend-wp'), 'case' => 'input', 'type' => 'checkbox'), 'custom_template' => array('label' => __('Is public', 'extend-wp'), 'case' => 'input', 'type' => 'checkbox', 'admin_list' => true), 'args' => array('label' => __('Args', 'extend-wp'), 'case' => 'select', 'options' => array('title' => array('label' => __('title', 'extend-wp')), 'editor' => array('label' => __('editor', 'extend-wp')), 'author' => array('label' => __('author', 'extend-wp')), 'thumbnail' => array('label' => __('thumbnail', 'extend-wp')), 'excerpt' => array('label' => __('excerpt', 'extend-wp')), 'trackbacks' => array('label' => __('trackbacks', 'extend-wp')), 'custom-fields' => array('label' => __('custom-fields', 'extend-wp')), 'comments' => array('label' => __('comments', 'extend-wp')), 'revisions' => array('label' => __('revisions', 'extend-wp')), 'page-attributes' => array('label' => __('page-attributes', 'extend-wp')), 'post-formats' => array('label' => __('post-formats', 'extend-wp'))), 'attributes' => array('multiple' => true)), 'taxonomies' => array('case' => 'taxonomies', 'label' => __('Attach taxonomies', 'extend-wp'), 'attributes' => array('multiple' => 1)), 'extra_slug' => array('label' => __('With front (taxonomy_label)', 'extend-wp'), 'case' => 'input', 'type' => 'text'), 'description' => array('label' => __('Desription', 'extend-wp'), 'case' => 'textarea'))",
                "type": null,
                "description": null
            }
        ],
        "file": ".\/includes\/ewp-wp-content\/class-wp-content.php"
    },
    {
        "name": "ewp_taxonomy_fields_creation_filter",
        "summary": null,
        "desc": null,
        "since": null,
        "params": [
            {
                "name": "array('taxonomy_name' => array('label' => __('Taxonomy name', 'extend-wp'), 'case' => 'input', 'type' => 'text', 'class' => array('awm-lowercase'), 'attributes' => array('title' => 'English only!', 'pattern' => '[\\\\x00-\\\\x7F]+'), 'label_class' => array('awm-needed')), 'name' => array('label' => __('Name plural', 'extend-wp'), 'case' => 'input', 'type' => 'text', 'label_class' => array('awm-needed')), 'label' => array('label' => __('Name singular', 'extend-wp'), 'case' => 'input', 'type' => 'text', 'label_class' => array('awm-needed')), 'prefix' => array('label' => __('Registration prefix', 'extend-wp'), 'case' => 'input', 'type' => 'text'), 'post_types' => array('label' => __('Post types', 'extend-wp'), 'case' => 'post_types', 'attributes' => array('multiple' => true), 'label_class' => array('awm-needed'), 'admin_list' => true), 'template' => array('label' => __('Template path', 'extend-wp'), 'case' => 'input', 'type' => 'text', 'explanation' => __('if you create ewp_{taxonomy_name}.php it will be used. Otherwise use path (from plugins\/ or themes\/). If none archive.php will be used.', 'extend-wp')), 'show_admin_column' => array('label' => __('Show in admin list', 'extend-wp'), 'case' => 'input', 'type' => 'checkbox'))",
                "type": null,
                "description": null
            }
        ],
        "file": ".\/includes\/ewp-wp-content\/class-wp-content.php"
    },
    {
        "name": "ewp_search_result_path",
        "summary": null,
        "desc": null,
        "since": null,
        "params": [
            {
                "name": "awm_path . 'templates\/frontend\/search\/result.php'",
                "type": null,
                "description": null
            },
            {
                "name": "$ewp_search_query",
                "type": null,
                "description": null
            }
        ],
        "file": ".\/templates\/frontend\/search\/results.php"
    },
    {
        "name": "ewp_search_result_pagination_path",
        "summary": null,
        "desc": null,
        "since": null,
        "params": [
            {
                "name": "awm_path . 'templates\/frontend\/search\/results_pagination.php'",
                "type": null,
                "description": null
            },
            {
                "name": "$ewp_search_query",
                "type": null,
                "description": null
            }
        ],
        "file": ".\/templates\/frontend\/search\/results.php"
    }
]
