<?php
/**
 * Block select options load lazily (1.5.8): option callbacks never run while
 * blocks are gathered and registered on every request, only when the block
 * editor payload is built; WordPress gets a `type` + `default` schema; the
 * editor payload carries no PHP callables; `object_search` selects never
 * list their options.
 */

class Test_Block_Lazy_Options extends WP_UnitTestCase
{
    use EWP_Block_Fixture;

    /**
     * Reset the fixture state around each test.
     *
     * @return void
     */
    public function tear_down()
    {
        $this->drop_blocks();
        parent::tear_down();
    }

    /**
     * One block with a callback select, a static select and an input.
     *
     * @param string $name Block name.
     * @return array
     */
    private function block_with_callback_select($name = 'lazyblock')
    {
        return [
            'namespace' => 'ewptest',
            'name' => $name,
            'title' => 'Lazy block',
            'version' => 1,
            'render_callback' => [$this, 'render'],
            'attributes' => [
                'post_ids' => [
                    'label' => 'Posts',
                    'case' => 'select',
                    'attributes' => ['multiple' => true],
                    'callback' => self::counting_callback('posts', ['7' => ['label' => 'Seven', 'filter_values' => ['post']]]),
                ],
                'layout' => [
                    'case' => 'select',
                    'options' => ['grid' => ['label' => 'Grid']],
                    'default' => 'grid',
                ],
                'title' => ['case' => 'input', 'type' => 'text', 'label' => 'Title'],
            ],
        ];
    }

    /**
     * Render callback of the fixture blocks.
     *
     * @param array $attributes Attributes.
     * @return string
     */
    public function render($attributes)
    {
        return 'rendered';
    }

    /**
     * Test: Prepare field without resolving keeps the callback.
     *
     * @return void
     */
    public function test_prepare_field_without_resolving_keeps_the_callback()
    {
        $field = awm_prepare_field(['case' => 'term', 'taxonomy' => 'category'], 'lib', false);

        $this->assertSame('select', $field['case']);
        $this->assertSame('awmTaxonomyFieldsForInput', $field['callback']);
        $this->assertArrayNotHasKey('options', $field, 'The shorthand term case no longer queries terms when it is only prepared.');

        $resolved = awm_resolve_field_options($field);
        $this->assertArrayHasKey('options', $resolved);
    }

    /**
     * Test: Prepare field resolves by default.
     *
     * @return void
     */
    public function test_prepare_field_resolves_by_default()
    {
        $field = awm_prepare_field(['case' => 'select', 'callback' => self::counting_callback('default')]);

        $this->assertSame(1, self::calls('default'));
        $this->assertSame(['a' => ['label' => 'A']], $field['options']);
    }

    /**
     * Test: Content type and option page cases resolve through callbacks.
     *
     * @return void
     */
    public function test_content_type_and_option_page_cases_resolve_through_callbacks()
    {
        $types = awm_prepare_field(['case' => 'ewp_content_types'], '', false);
        $pages = awm_prepare_field(['case' => 'ewp_option_pages'], '', false);

        $this->assertSame('awm_ewp_content_type_options', $types['callback']);
        $this->assertSame('awm_ewp_option_page_options', $pages['callback']);
        $this->assertArrayNotHasKey('options', $types);
        $this->assertIsArray(awm_resolve_field_options($types)['options']);
    }

    /**
     * Test: Gathering and registering on an admin request runs no option callback.
     *
     * @return void
     */
    public function test_gathering_and_registering_on_an_admin_request_runs_no_option_callback()
    {
        $this->as_admin_screen();
        $this->use_blocks(['lazy' => $this->block_with_callback_select()]);

        $blocks = EWP_Dynamic_Blocks::instance()->gather_blocks();
        EWP_Dynamic_Blocks::instance()->register_blocks();

        $this->assertSame(0, self::calls('posts'), 'The option callback runs only for the editor payload.');
        $this->assertSame([], $blocks['lazy']['attributes']['post_ids']['options']);
        $this->assertSame('array', $blocks['lazy']['attributes']['post_ids']['type']);
        $this->assertSame([['option' => 'grid', 'label' => 'Grid']], $blocks['lazy']['attributes']['layout']['options'], 'Static options stay.');
    }

    /**
     * Test: Registered attributes carry only type and default.
     *
     * @return void
     */
    public function test_registered_attributes_carry_only_type_and_default()
    {
        $this->use_blocks(['lazy' => $this->block_with_callback_select()]);
        EWP_Dynamic_Blocks::instance()->register_blocks();

        $type = WP_Block_Type_Registry::get_instance()->get_registered('ewptest/lazyblock');

        $this->assertNotNull($type);
        $this->assertSame([
            'post_ids' => ['type' => 'array', 'default' => []],
            'layout' => ['type' => 'string', 'default' => 'grid'],
            'title' => ['type' => 'string', 'default' => ''],
        ], array_intersect_key($type->attributes, array_flip(['post_ids', 'layout', 'title'])));
    }

    /**
     * Test: Editor payload resolves options once and drops callables.
     *
     * @return void
     */
    public function test_editor_payload_resolves_options_once_and_drops_callables()
    {
        $this->use_blocks(['lazy' => $this->block_with_callback_select()]);
        $registry = EWP_Dynamic_Blocks::instance();

        $first = $registry->editor_blocks();
        $registry->editor_blocks();

        $this->assertSame(1, self::calls('posts'), 'The editor payload is built once per request.');
        $this->assertSame([['option' => 7, 'label' => 'Seven', 'filter_values' => ['post']]], $first['lazy']['attributes']['post_ids']['options']);
        $this->assertArrayNotHasKey('render_callback', $first['lazy']);
        foreach ($first['lazy']['attributes'] as $attribute) {
            $this->assertArrayNotHasKey('callback', $attribute);
            $this->assertArrayNotHasKey('callback_variables', $attribute);
        }
        $this->assertNotFalse(wp_json_encode($first), 'The payload json-encodes without objects.');
    }

    /**
     * Test: Object search select never lists options.
     *
     * @return void
     */
    public function test_object_search_select_never_lists_options()
    {
        $block = $this->block_with_callback_select('searchblock');
        $block['attributes']['post_type'] = ['case' => 'select', 'options' => ['post' => ['label' => 'Posts']]];
        $block['attributes']['post_ids']['object_search'] = ['type_from' => 'post_type', 'limit' => 15];
        $this->use_blocks(['search' => $block]);

        $editor = EWP_Dynamic_Blocks::instance()->editor_blocks();
        $field = $editor['search']['attributes']['post_ids'];

        $this->assertSame(0, self::calls('posts'), 'The callback stays as a fallback for older EWP versions only.');
        $this->assertSame('object_search', $field['render_type']);
        $this->assertSame([], $field['options']);
        $this->assertSame('array', $field['type']);
        $this->assertTrue($field['multiple']);
        $this->assertSame(['object_type' => '', 'type_from' => 'post_type', 'group' => 'post_type', 'limit' => 15, 'search_meta' => false], $field['object_search']);
    }

    /**
     * Test: Blocks are sorted by priority.
     *
     * @return void
     */
    public function test_blocks_are_sorted_by_priority()
    {
        $late = $this->block_with_callback_select('lateblock');
        $late['priority'] = 200;
        $early = $this->block_with_callback_select('earlyblock');
        $early['priority'] = 5;
        $this->use_blocks(['late' => $late, 'early' => $early]);

        $this->assertSame(['early', 'late'], array_keys(EWP_Dynamic_Blocks::instance()->gather_blocks()));
    }

    /**
     * Test: An empty block list is cached.
     *
     * @return void
     */
    public function test_an_empty_block_list_is_cached()
    {
        $runs = 0;
        $count = function ($blocks) use (&$runs) {
            $runs++;
            return [];
        };
        add_filter('ewp_gutenburg_blocks_filter', $count, PHP_INT_MAX);
        EWP_Dynamic_Blocks::reset();

        EWP_Dynamic_Blocks::instance()->gather_blocks();
        EWP_Dynamic_Blocks::instance()->gather_blocks();
        EWP_Dynamic_Blocks::instance()->editor_blocks();
        remove_filter('ewp_gutenburg_blocks_filter', $count, PHP_INT_MAX);

        $this->assertSame(1, $runs);
    }

    /**
     * Test: The editor payload is not built on the front end.
     *
     * @return void
     */
    public function test_the_editor_payload_is_not_built_on_the_front_end()
    {
        $this->assertFalse(has_action('wp_enqueue_scripts', [EWP_Dynamic_Blocks::instance(), 'load_scripts']));
        $this->assertNotFalse(has_action('enqueue_block_editor_assets', [EWP_Dynamic_Blocks::instance(), 'load_scripts']));
    }
}
