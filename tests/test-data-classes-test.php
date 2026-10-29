<?php
/**
 * Data classes behind the procedural helpers (EWP\Fields\Data): value reader,
 * field preparer, object types, object search query and display formatter.
 */

use EWP\Fields\Data\Field_Preparer;
use EWP\Fields\Data\Field_Value_Formatter;
use EWP\Fields\Data\Field_Value_Reader;
use EWP\Fields\Data\Object_Search_Query;
use EWP\Fields\Data\Object_Types;

class Test_Data_Classes extends WP_UnitTestCase
{
    /**
     * Reset the object type cache and the GET superglobal around each test.
     *
     * @return void
     */
    public function set_up(): void
    {
        parent::set_up();
        Object_Types::reset();
    }

    /**
     * Clean up the superglobal and filters.
     *
     * @return void
     */
    public function tear_down(): void
    {
        unset($_GET['ewp_data_key']);
        remove_all_filters('awm_show_content_value_filter');
        remove_all_filters('awm_object_type_options');
        remove_all_filters('awm_display_meta_value_filter');
        parent::tear_down();
    }

    /**
     * Test: value() reads meta per view and the wrapper delegates.
     *
     * @return void
     */
    public function test_value_reads_each_view()
    {
        $post = self::factory()->post->create();
        $term = self::factory()->term->create();
        $user = self::factory()->user->create();
        update_post_meta($post, 'k', 'p');
        update_term_meta($term, 'k', 't');
        update_user_meta($user, 'k', 'u');
        update_option('ewp_data_opt', 'o');

        $this->assertSame('p', Field_Value_Reader::value('post', array(), $post, 'k'));
        $this->assertSame('t', Field_Value_Reader::value('term', array(), $term, 'k'));
        $this->assertSame('u', Field_Value_Reader::value('user', array(), $user, 'k'));
        $this->assertSame('o', Field_Value_Reader::value('option', array(), 0, 'ewp_data_opt'));
        $this->assertSame('', Field_Value_Reader::value('post', array(), $post, 'missing'));
        $this->assertSame(0, Field_Value_Reader::value('bogus', array(), $post, 'k'));
        $this->assertSame('p', awm_get_field_value('post', array(), $post, 'k'));
    }

    /**
     * Test: widget and restrict_manage_posts views read from the instance / query string.
     *
     * @return void
     */
    public function test_value_widget_and_restrict_manage_posts()
    {
        $this->assertSame('w', Field_Value_Reader::value('widget', array('widget-key' => 'a'), array('a' => 'w'), 'k'));
        $this->assertSame('', Field_Value_Reader::value('widget', array('widget-key' => 'zz'), array('a' => 'w'), 'k'));

        $_GET['ewp_data_key'] = 'q';
        $this->assertSame('q', Field_Value_Reader::value('restrict_manage_posts', array(), 0, 'ewp_data_key'));
        $this->assertSame('', Field_Value_Reader::value('restrict_manage_posts', array(), 0, 'other'));
    }

    /**
     * Test: the value filter runs with the documented arguments; raw() skips it.
     *
     * @return void
     */
    public function test_value_filter_and_raw()
    {
        $post = self::factory()->post->create();
        update_post_meta($post, 'k', 'p');
        add_filter('awm_show_content_value_filter', function ($val, $id, $meta, $view) {
            return $val . '|' . $meta . '|' . $view;
        }, 10, 4);

        $this->assertSame('p|k|post', Field_Value_Reader::value('post', array(), $post, 'k'));
        $this->assertSame('p', Field_Value_Reader::raw('post', $post, 'k'));
        $this->assertSame('', Field_Value_Reader::raw('widget', $post, 'k'));
        $this->assertSame('p', awm_get_raw_field_value('post', $post, 'k'));
    }

    /**
     * Test: shorthand cases become a select with a callback; resolve_options runs it.
     *
     * @return void
     */
    public function test_preparer_shorthand_callbacks()
    {
        $a = Field_Preparer::prepare(array('case' => 'postType', 'post_type' => 'page'), '', false);
        $this->assertSame('select', $a['case']);
        $this->assertSame('awmPostFieldsForInput', $a['callback']);
        $this->assertSame(array('page', '-1', array()), $a['callback_variables']);

        $a = Field_Preparer::prepare(array('case' => 'user_roles', 'view' => 'radio', 'exclude' => array('x')), '', false);
        $this->assertSame('radio', $a['case']);
        $this->assertSame(array(array('x')), $a['callback_variables']);

        $a = Field_Preparer::prepare(array('case' => 'term', 'taxonomy' => 'category', 'number' => 5), 'lib', false);
        $this->assertSame(array('category', 5, array(), 'term_id', 'lib', false), $a['callback_variables']);

        $a = Field_Preparer::prepare(array('case' => 'ewp_content', 'content_type' => 'ct'), '', false);
        $this->assertSame(array('ct', array()), $a['callback_variables']);

        $a = Field_Preparer::prepare(array('case' => 'ewp_content_types'), '', false);
        $this->assertSame('awm_ewp_content_type_options', $a['callback']);
        $this->assertArrayNotHasKey('callback_variables', $a);

        $a = Field_Preparer::prepare(array('case' => 'ewp_option_pages'), '', false);
        $this->assertSame('awm_ewp_option_page_options', $a['callback']);

        $a = Field_Preparer::prepare(array('case' => 'taxonomies'), '', false);
        $this->assertSame('awmTaxObjectsForInput', $a['callback']);
        $a = Field_Preparer::prepare(array('case' => 'post_types'), '', false);
        $this->assertSame('awmPostObjectsForInput', $a['callback']);
        $a = Field_Preparer::prepare(array('case' => 'user'), '', false);
        $this->assertSame(array(array(), '-1', array()), $a['callback_variables']);
    }

    /**
     * Test: date becomes a text input with the date-picker class and filtered params.
     *
     * @return void
     */
    public function test_preparer_date_case()
    {
        add_filter('awm_cl_date_params', function ($params) {
            $params['extra'] = "it's";
            return $params;
        });
        $a = Field_Preparer::prepare(array('case' => 'date', 'class' => array('x'), 'date-params' => array('a' => 1)));
        remove_all_filters('awm_cl_date_params');

        $this->assertSame('input', $a['case']);
        $this->assertSame('text', $a['type']);
        $this->assertSame(array('x', 'awm_cl_date'), $a['class']);
        $this->assertSame("{'a':1,'extra':'it's'}", $a['attributes']['date-params']);
    }

    /**
     * Test: resolve_options retries the callback without arguments when the first call is empty.
     *
     * @return void
     */
    public function test_preparer_resolve_options()
    {
        $this->assertSame(array('case' => 'select'), Field_Preparer::resolve_options(array('case' => 'select')));

        $a = Field_Preparer::resolve_options(array('callback' => array(__CLASS__, 'options_by_args'), 'callback_variables' => array('x')));
        $this->assertSame(array('x' => array('label' => 'x')), $a['options']);

        $a = Field_Preparer::resolve_options(array('callback' => array(__CLASS__, 'options_by_args'), 'callback_variables' => array('')));
        $this->assertSame(array('d' => array('label' => 'd')), $a['options']);

        $a = Field_Preparer::resolve_options(array('callback' => array(__CLASS__, 'options_by_args')));
        $this->assertSame(array('d' => array('label' => 'd')), $a['options']);
    }

    /**
     * Options callback fixture.
     *
     * @param string $key Optional key.
     * @return array
     */
    public static function options_by_args($key = '')
    {
        return $key === '' ? array('d' => array('label' => 'd')) : array($key => array('label' => $key));
    }

    /**
     * Test: content type and option page option lists.
     *
     * @return void
     */
    public function test_preparer_option_lists()
    {
        $this->assertSame(\AWM_Content_DB::get_instance()->get_content_types(), Field_Preparer::content_type_options());
        $this->assertSame(Field_Preparer::content_type_options(), awm_ewp_content_type_options());

        $pages = \EWP_Options_Portability::instance()->get_exportable_pages();
        $opts  = Field_Preparer::option_page_options();
        $this->assertSame(array_keys($pages), array_keys($opts));
        foreach ($opts as $opt) {
            $this->assertMatchesRegularExpression('/\(\d+ fields?\)$/', $opt['label']);
        }
        $this->assertSame($opts, awm_ewp_option_page_options());
    }

    /**
     * Test: config() fills defaults and respects overrides.
     *
     * @return void
     */
    public function test_object_types_config()
    {
        $c = Object_Types::config(array(), 'meta');
        $this->assertSame(array('post_types', 'taxonomies', 'custom_content'), $c['allowed_types']);
        $this->assertSame('meta_ids', $c['id_field_name']);
        $this->assertSame(2, $c['min_search_chars']);
        $this->assertSame(20, $c['max_results']);
        $this->assertTrue($c['search_meta']);

        $c = Object_Types::config(array('allowed_types' => array('taxonomies'), 'id_field_name' => 'x', 'max_results' => '-7', 'search_meta' => 0), 'meta');
        $this->assertSame(array('taxonomies'), $c['allowed_types']);
        $this->assertSame('x', $c['id_field_name']);
        $this->assertSame(7, $c['max_results']);
        $this->assertFalse($c['search_meta']);
        $this->assertSame($c, awm_object_id_filter_config(array('allowed_types' => array('taxonomies'), 'id_field_name' => 'x', 'max_results' => '-7', 'search_meta' => 0), 'meta'));
    }

    /**
     * Test: options() filters by allowed_types and slugs, and drops empty optgroups.
     *
     * @return void
     */
    public function test_object_types_options()
    {
        $config  = Object_Types::config(array('allowed_types' => array('post_types'), 'post_types' => array('page')), 'm');
        $options = Object_Types::options($config);
        $this->assertSame(array('post_type:page', 'optgroups'), array_keys($options));
        $this->assertSame('post_type', $options['post_type:page']['optgroup']);
        $this->assertSame(array('post_type'), array_keys($options['optgroups']));

        $config  = Object_Types::config(array('allowed_types' => array('taxonomies', 'post_types'), 'post_types' => array('nope'), 'taxonomies' => array('category')), 'm');
        $options = Object_Types::options($config);
        $this->assertSame(array('taxonomy:category', 'optgroups'), array_keys($options));
        $this->assertSame(array('taxonomy'), array_keys($options['optgroups']));
        $this->assertSame($options, awm_get_object_type_options($config));
    }

    /**
     * Test: both type option filters fire, the named one only with a field name.
     *
     * @return void
     */
    public function test_object_types_filters()
    {
        $config = Object_Types::config(array('allowed_types' => array('taxonomies'), 'taxonomies' => array('category')), 'm');
        add_filter('awm_object_type_options', function ($o, $c, $name) {
            $o['all'] = $name;
            return $o;
        }, 10, 3);
        add_filter('awm_object_type_options_fld', function ($o) {
            $o['named'] = true;
            return $o;
        });

        $plain = Object_Types::options($config);
        $this->assertArrayNotHasKey('named', $plain);
        $this->assertSame('', $plain['all']);

        $named = Object_Types::options($config, 'fld');
        $this->assertTrue($named['named']);
        $this->assertSame('fld', $named['all']);
        remove_all_filters('awm_object_type_options_fld');
    }

    /**
     * Test: post_type search by title, include, exclude and unknown type.
     *
     * @return void
     */
    public function test_search_post_type_branch()
    {
        $a = self::factory()->post->create(array('post_title' => 'Zebra alpha', 'post_type' => 'page'));
        $b = self::factory()->post->create(array('post_title' => 'Zebra beta', 'post_type' => 'page'));
        $m = self::factory()->post->create(array('post_title' => 'Plain', 'post_type' => 'page'));
        update_post_meta($m, 'k', 'zebra-meta');

        $ids = wp_list_pluck(Object_Search_Query::search('post_type', 'page', 'Zebra'), 'id');
        $this->assertEqualSets(array($a, $b, $m), $ids);

        $ids = wp_list_pluck(Object_Search_Query::search('post_type', 'page', 'Zebra', array('exclude' => array($a), 'search_meta' => false)), 'id');
        $this->assertSame(array($b), $ids);

        $rows = Object_Search_Query::search('post_type', 'page', '', array('include' => array($a)));
        $this->assertSame(array(array('id' => $a, 'label' => 'Zebra alpha', 'type' => 'post_type:page')), $rows);

        $this->assertSame(array(), Object_Search_Query::search('post_type', 'no_such_type', 'x'));
        $this->assertSame(array(), Object_Search_Query::search('user', 'x', 'x'));
        $this->assertCount(1, awm_object_search_query('post_type', 'page', 'Zebra', array('limit' => 1, 'search_meta' => false)));
    }

    /**
     * Test: taxonomy search by name, by term meta, include and unknown taxonomy.
     *
     * @return void
     */
    public function test_search_taxonomy_branch()
    {
        $t1 = self::factory()->term->create(array('taxonomy' => 'category', 'name' => 'Quokka one'));
        $t2 = self::factory()->term->create(array('taxonomy' => 'category', 'name' => 'Other'));
        update_term_meta($t2, 'k', 'quokka-meta');

        $ids = wp_list_pluck(Object_Search_Query::search('taxonomy', 'category', 'Quokka'), 'id');
        $this->assertEqualSets(array($t1, $t2), $ids);

        $ids = wp_list_pluck(Object_Search_Query::search('taxonomy', 'category', 'Quokka', array('search_meta' => false)), 'id');
        $this->assertSame(array($t1), $ids);

        $ids = wp_list_pluck(Object_Search_Query::search('taxonomy', 'category', 'Quokka', array('exclude' => array($t2))), 'id');
        $this->assertSame(array($t1), $ids);

        $rows = Object_Search_Query::search('taxonomy', 'category', '', array('include' => array($t2)));
        $this->assertSame(array(array('id' => $t2, 'label' => 'Other', 'type' => 'taxonomy:category')), $rows);

        $this->assertSame(array(), Object_Search_Query::search('taxonomy', 'no_such_tax', 'x'));
    }

    /**
     * Test: custom content search only accepts registered content types and clean slugs.
     *
     * @return void
     */
    public function test_search_custom_content_rejects_unregistered_types()
    {
        $this->assertSame(array(), Object_Search_Query::search('custom_content', 'not_registered', 'x'));
        $this->assertSame(array(), Object_Search_Query::search('custom_content', 'bad slug; DROP', 'x'));
        $this->assertSame(array(), Object_Search_Query::search('custom_content', 'Upper', 'x'));
    }

    /**
     * Test: the search filters (args, max limit, results) fire.
     *
     * @return void
     */
    public function test_search_filters()
    {
        $a = self::factory()->post->create(array('post_title' => 'Filterable', 'post_type' => 'page'));
        self::factory()->post->create(array('post_title' => 'Filterable two', 'post_type' => 'page'));

        add_filter('awm_object_search_max_limit', '__return_zero');
        $this->assertCount(1, Object_Search_Query::search('post_type', 'page', 'Filterable', array('limit' => 50, 'search_meta' => false)));
        remove_filter('awm_object_search_max_limit', '__return_zero');

        $filter = function ($results) {
            return array('replaced');
        };
        add_filter('awm_object_search_results', $filter);
        $this->assertSame(array('replaced'), Object_Search_Query::search('post_type', 'page', 'Filterable'));
        remove_filter('awm_object_search_results', $filter);
        $this->assertNotEmpty($a);
    }

    /**
     * Test: formatter for stored values per list case.
     *
     * @return void
     */
    public function test_formatter_stored_values_and_simple_cases()
    {
        $post = self::factory()->post->create();
        update_post_meta($post, 'flag', '1');
        update_post_meta($post, 'link', 'https://example.com');

        $this->assertSame('Yes', Field_Value_Formatter::display('flag', array('case' => 'input', 'type' => 'checkbox'), $post));
        $this->assertSame('No', Field_Value_Formatter::display('none', array('case' => 'input', 'type' => 'checkbox'), $post));
        $this->assertSame('<a href="https://example.com" target="_blank">https://example.com</a>', Field_Value_Formatter::display('link', array('case' => 'input', 'type' => 'url'), $post));
        $this->assertSame('', Field_Value_Formatter::display('none', array('case' => 'input', 'type' => 'url'), $post));
        $this->assertSame('1', Field_Value_Formatter::display('flag', array('case' => 'input', 'type' => 'text'), $post));
        $this->assertSame('x', awm_display_meta_value('k', array('case' => 'input', 'type' => 'text'), 0, 'x'));
    }

    /**
     * Test: formatter for message/html (with strip bypassing the filter), function, term, user and postType.
     *
     * @return void
     */
    public function test_formatter_message_function_and_objects()
    {
        add_filter('awm_display_meta_value_filter', function ($v) {
            return '[' . $v . ']';
        });
        $this->assertSame('[hello]', Field_Value_Formatter::display('m', array('case' => 'message', 'value' => 'hello'), 0));
        $this->assertSame('hello', Field_Value_Formatter::display('m', array('case' => 'html', 'value' => 'hello', 'strip' => true), 0));
        $this->assertSame('[]', Field_Value_Formatter::display('m', array('case' => 'html'), 0));
        remove_all_filters('awm_display_meta_value_filter');

        $this->assertSame('cb7', Field_Value_Formatter::display('f', array('case' => 'function', 'callback' => 'ewp_data_test_callback'), 7));
        $this->assertFalse(Field_Value_Formatter::display('f', array('case' => 'function', 'callback' => 'missing_fn'), 7));

        $term = self::factory()->term->create(array('taxonomy' => 'category', 'name' => 'Cat X'));
        $this->assertSame('Cat X', Field_Value_Formatter::display('t', array('case' => 'term', 'taxonomy' => 'category'), 0, $term));

        $user = self::factory()->user->create(array('display_name' => 'Dee'));
        $html = Field_Value_Formatter::display('u', array('case' => 'user'), 0, $user);
        $this->assertStringContainsString('user-edit.php?user_id=' . $user, $html);
        $this->assertStringContainsString('>Dee</a>', $html);
        $this->assertSame(999999, Field_Value_Formatter::display('u', array('case' => 'user'), 0, 999999));

        $post = self::factory()->post->create(array('post_title' => 'Pt'));
        $html = Field_Value_Formatter::display('p', array('case' => 'postType'), 0, array($post, ''));
        $this->assertStringContainsString('>Pt</a>,-', $html);

        $this->assertSame('Pages,Posts', str_replace(', ', ',', Field_Value_Formatter::display('pt', array('case' => 'post_types'), 0, array('page', 'post'))));
    }

    /**
     * Test: formatter for select, checkbox_multiple (incl. callbacks with optgroups) and repeater.
     *
     * @return void
     */
    public function test_formatter_options_and_repeater()
    {
        $data = array('case' => 'select', 'options' => array('a' => array('label' => 'A'), 'b' => array('label' => 'B')));
        $this->assertSame('A<br>B', Field_Value_Formatter::display('s', $data, 0, array('a', 'b', 'zz', '')));
        $this->assertSame('A', Field_Value_Formatter::display('s', $data, 0, 'a'));

        $cb = array('case' => 'checkbox_multiple', 'callback' => array(__CLASS__, 'grouped_options'));
        $this->assertSame('G', Field_Value_Formatter::display('s', $cb, 0, 'g'));

        $rep = array(
            'case'    => 'repeater',
            'include' => array(
                'flag'  => array('label' => 'Flag', 'case' => 'input', 'type' => 'checkbox', 'admin_list' => true),
                'skip'  => array('label' => 'Skip', 'case' => 'input', 'type' => 'text'),
                'name'  => array('label' => 'Name', 'case' => 'input', 'type' => 'text', 'admin_list' => true),
            ),
        );
        $rows = array(array('flag' => '1', 'name' => 'n1'), array('name' => 'n2'));
        $this->assertSame('Flag: Yes | Name: n1<br>Flag:  | Name: n2', Field_Value_Formatter::display('r', $rep, 0, $rows));
        $this->assertSame(array(), Field_Value_Formatter::display('r', $rep, 0, array()) ?: array());
    }

    /**
     * Grouped options callback fixture.
     *
     * @return array
     */
    public static function grouped_options()
    {
        return array('options' => array('g' => array('label' => 'G')), 'optgroups' => array('x' => array('label' => 'X')));
    }
}

if (!function_exists('ewp_data_test_callback')) {
    /**
     * Callback fixture returning the object id.
     *
     * @param int $id Object id.
     * @return string
     */
    function ewp_data_test_callback($id)
    {
        return 'cb' . $id;
    }
}
