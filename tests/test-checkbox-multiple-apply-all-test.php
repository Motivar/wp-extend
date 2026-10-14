<?php
/**
 * checkbox_multiple apply-all option: the default toggle mode, the exclusive
 * "All" mode (apply_all_mode / apply_all_label), and how the search filter
 * passes those settings through and ignores empty request values.
 */

class Test_Checkbox_Multiple_Apply_All extends WP_UnitTestCase
{
    /**
     * Render one checkbox_multiple field and index its checkboxes by data-value.
     *
     * @param array $extra Field keys merged over the base definition.
     * @param mixed $value Selected value(s); null renders without a value.
     * @return array{html:string,inputs:array<string,DOMElement>,wrapper:DOMElement|null}
     */
    private function render(array $extra = [], $value = null)
    {
        $field = $extra + [
            'case' => 'checkbox_multiple',
            'label' => 'Categories',
            'options' => [
                'news' => ['label' => 'News'],
                'events' => ['label' => 'Events'],
            ],
        ];
        if ($value !== null) {
            $field['attributes']['value'] = $value;
        }
        $html = awm_show_content(['ewp_cat' => $field]);

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        $inputs = [];
        foreach ($xpath->query('//input[@type="checkbox"]') as $input) {
            $inputs[$input->getAttribute('data-value')] = $input;
        }
        $wrapper = $xpath->query('//div[contains(concat(" ", @class, " "), " checkbox_multiple ")]')->item(0);

        return ['html' => $html, 'inputs' => $inputs, 'wrapper' => $wrapper];
    }

    /**
     * The label text next to a checkbox.
     *
     * @param DOMElement $input The checkbox.
     * @return string
     */
    private function label_text(DOMElement $input)
    {
        return trim($input->parentNode->textContent);
    }

    /**
     * Whether the checkbox's label carries the `selected` class.
     *
     * @param DOMElement $input The checkbox.
     * @return bool
     */
    private function label_selected(DOMElement $input)
    {
        return in_array('selected', preg_split('/\s+/', $input->parentNode->getAttribute('class')), true);
    }

    /**
     * Default mode is unchanged except that the apply-all input no longer posts (typo fix).
     *
     * @return void
     */
    public function test_default_toggle_mode()
    {
        $r = $this->render([], ['events']);
        $all = $r['inputs']['awm_apply_all'];

        $this->assertSame('Select All', $this->label_text($all));
        $this->assertSame('Deselect All', $all->getAttribute('data-extra'));
        $this->assertFalse($all->hasAttribute('data-apply-all-mode'));
        $this->assertSame('', $all->getAttribute('name'), 'the apply-all input must not post a value');
        $this->assertSame('', $all->getAttribute('value'));
        $this->assertFalse($all->hasAttribute('checked'));

        $this->assertSame('ewp_cat[]', $r['inputs']['news']->getAttribute('name'));
        $this->assertTrue($r['inputs']['events']->hasAttribute('checked'));
        $this->assertTrue($this->label_selected($r['inputs']['events']));
        $this->assertFalse($r['inputs']['news']->hasAttribute('checked'));
        $this->assertFalse($r['wrapper']->hasAttribute('data-apply-all-mode'));
    }

    /**
     * disable_apply_all still removes the option.
     *
     * @return void
     */
    public function test_disable_apply_all()
    {
        $r = $this->render(['disable_apply_all' => true, 'apply_all_mode' => 'exclusive']);
        $this->assertArrayNotHasKey('awm_apply_all', $r['inputs']);
        $this->assertCount(2, $r['inputs']);
        $this->assertFalse($r['wrapper']->hasAttribute('data-apply-all-mode'));
    }

    /**
     * Exclusive mode with nothing selected: "All" is checked, has no name and no Deselect swap.
     *
     * @return void
     */
    public function test_exclusive_mode_without_selection_checks_all()
    {
        $r = $this->render(['apply_all_mode' => 'exclusive']);
        $all = $r['inputs']['awm_apply_all'];

        $this->assertSame('', $all->getAttribute('name'));
        $this->assertFalse($all->hasAttribute('data-extra'));
        $this->assertSame('exclusive', $all->getAttribute('data-apply-all-mode'));
        $this->assertSame('exclusive', $r['wrapper']->getAttribute('data-apply-all-mode'));
        $this->assertTrue($all->hasAttribute('checked'));
        $this->assertTrue($this->label_selected($all));
        $this->assertFalse($r['inputs']['news']->hasAttribute('checked'));
        $this->assertFalse($r['inputs']['events']->hasAttribute('checked'));
        $this->assertSame('Select All', $this->label_text($all));
    }

    /**
     * Exclusive mode with a selected option (request value or archive pre-select): "All" is unchecked.
     *
     * @return void
     */
    public function test_exclusive_mode_with_selection_unchecks_all()
    {
        foreach ([['news'], 'news'] as $value) {
            $r = $this->render(['apply_all_mode' => 'exclusive'], $value);
            $this->assertFalse($r['inputs']['awm_apply_all']->hasAttribute('checked'));
            $this->assertFalse($this->label_selected($r['inputs']['awm_apply_all']));
            $this->assertTrue($r['inputs']['news']->hasAttribute('checked'));
            $this->assertSame('ewp_cat[]', $r['inputs']['news']->getAttribute('name'));
        }
    }

    /**
     * Term ids arrive as strings from a request and as ints from the archive pre-select.
     *
     * @return void
     */
    public function test_exclusive_mode_with_numeric_keys()
    {
        $extra = [
            'apply_all_mode' => 'exclusive',
            'options' => [12 => ['label' => 'News'], 15 => ['label' => 'Events']],
        ];
        foreach ([15, '15', ['15']] as $value) {
            $r = $this->render($extra, $value);
            $this->assertFalse($r['inputs']['awm_apply_all']->hasAttribute('checked'));
            $this->assertTrue($r['inputs']['15']->hasAttribute('checked'));
        }
        $r = $this->render($extra, ['']);
        $this->assertTrue($r['inputs']['awm_apply_all']->hasAttribute('checked'), 'an empty value is no selection');
    }

    /**
     * apply_all_label replaces the label in both modes.
     *
     * @return void
     */
    public function test_custom_label()
    {
        $r = $this->render(['apply_all_mode' => 'exclusive', 'apply_all_label' => 'All categories']);
        $this->assertSame('All categories', $this->label_text($r['inputs']['awm_apply_all']));

        $r = $this->render(['apply_all_label' => 'Everything']);
        $this->assertSame('Everything', $this->label_text($r['inputs']['awm_apply_all']));
        $this->assertSame('Deselect All', $r['inputs']['awm_apply_all']->getAttribute('data-extra'));
    }

    /**
     * An unknown mode falls back to toggle.
     *
     * @return void
     */
    public function test_unknown_mode_falls_back_to_toggle()
    {
        $r = $this->render(['apply_all_mode' => 'bogus']);
        $this->assertSame('Deselect All', $r['inputs']['awm_apply_all']->getAttribute('data-extra'));
        $this->assertFalse($r['inputs']['awm_apply_all']->hasAttribute('checked'));
    }

    /**
     * Call a private method of the search filter class.
     *
     * @param string $method Method name.
     * @param array  $args   Arguments.
     * @return mixed
     */
    private function search_call($method, array $args)
    {
        $search = new Extend_WP_Search_Filters();
        $reflection = new ReflectionMethod($search, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($search, $args);
    }

    /**
     * The search filter normalises the stored admin values before handing them to the field.
     *
     * @return void
     */
    public function test_search_prepare_apply_all()
    {
        $field = $this->search_call('prepare_apply_all', [[
            'disable_apply_all' => '',
            'apply_all_mode' => 'exclusive',
            'apply_all_label' => 'All',
        ]]);
        $this->assertArrayNotHasKey('disable_apply_all', $field);
        $this->assertSame('exclusive', $field['apply_all_mode']);
        $this->assertSame('All', $field['apply_all_label']);

        $field = $this->search_call('prepare_apply_all', [['disable_apply_all' => '1', 'apply_all_mode' => 'nope', 'apply_all_label' => '']]);
        $this->assertSame('1', $field['disable_apply_all']);
        $this->assertArrayNotHasKey('apply_all_mode', $field);
        $this->assertArrayNotHasKey('apply_all_label', $field);
    }

    /**
     * The admin "Field type configuration" of a search filter row offers the apply-all settings for term and checkbox_multiple only.
     *
     * @return void
     */
    public function test_search_case_fields_choices()
    {
        $search = new Extend_WP_Search_Filters();
        $fields = $search->case_fields_choices(awmInputFields(), 'query_fields');
        foreach (['term', 'checkbox_multiple'] as $case) {
            foreach (['apply_all_mode', 'apply_all_label', 'disable_apply_all'] as $key) {
                $this->assertArrayHasKey($key, $fields[$case]['field-choices'], "$case.$key");
            }
        }
        $this->assertArrayHasKey('taxonomy', $fields['term']['field-choices'], 'existing settings are kept');
        $this->assertArrayNotHasKey('apply_all_mode', $fields['select']['field-choices']);

        $this->assertSame(awmInputFields(), $search->case_fields_choices(awmInputFields(), 'awm_fields'), 'field groups are unchanged');
    }

    /**
     * Empty entries in a request array (and an all-empty array) add no tax_query clause.
     *
     * @return void
     */
    public function test_search_query_ignores_empty_array_values()
    {
        $term = self::factory()->term->create(['taxonomy' => 'category', 'name' => 'Apply All News']);
        $conf = [
            'post_types' => ['post'],
            'limit' => 10,
            'query_fields' => [
                ['query_key' => 'ewp_cat', 'query_type' => 'taxonomy', 'taxonomy' => ['category'], 'compare_type' => 'in'],
            ],
        ];

        foreach ([[], ['ewp_cat' => ['']], ['ewp_cat' => ''], ['ewp_cat' => ['', null]]] as $params) {
            $args = $this->search_call('query_prepare', [$params, $conf]);
            $this->assertSame([], $args['query']['tax_query'], wp_json_encode($params));
            $this->assertSame([], $args['terms']);
        }

        $seen = null;
        $spy = function ($result, $params) use (&$seen) {
            $seen = $params;
            return $result;
        };
        add_filter('ewp_search_query_filter', $spy, 10, 2);
        $args = $this->search_call('query_prepare', [['ewp_cat' => ['', (string) $term]], $conf]);
        remove_filter('ewp_search_query_filter', $spy, 10);
        $this->assertSame(['ewp_cat' => ['', (string) $term]], $seen, 'ewp_search_query_filter receives the request params unchanged');
        $this->assertCount(1, $args['query']['tax_query']);
        $this->assertSame([(string) $term], array_values($args['query']['tax_query'][0]['terms']));
        $this->assertSame('Apply All News', $args['terms']['ewp_cat']);
    }
}
