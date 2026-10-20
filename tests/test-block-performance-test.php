<?php
/**
 * Block performance under extreme content (1.5.7): with 1,500 posts and a
 * block whose select callback lists every one of them (the Filox Post Slider
 * shape behind the onparnassos.gr incident), admin requests, front-end
 * renders and the block editor payload must stay within their time and query
 * budgets. A failure here fails `composer test`, which blocks `git push`.
 *
 * Budgets are milliseconds and can be raised on slow machines with the
 * EWP_PERF_REGISTER_MS, EWP_PERF_RENDER_MS and EWP_PERF_EDITOR_MS environment
 * variables; the query budgets are fixed.
 *
 * @group performance
 */

class Test_Block_Performance extends WP_UnitTestCase
{
    use EWP_Block_Fixture;

    /** Posts seeded for the class. */
    const POSTS = 1500;

    /** Post type the posts are seeded in. */
    const POST_TYPE = 'ewp_perf_item';

    /** Blocks on the front-end test page. */
    const BLOCKS_ON_PAGE = 12;

    /**
     * Register the post type and bulk-insert the posts (one query, so the
     * fixture itself stays fast).
     *
     * @return void
     */
    public static function wpSetUpBeforeClass()
    {
        global $wpdb;
        self::register_post_type();
        $now = current_time('mysql');
        $gmt = current_time('mysql', true);
        $rows = [];
        for ($i = 1; $i <= self::POSTS; $i++) {
            $rows[] = $wpdb->prepare('(1, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)', $now, $gmt, 'Body ' . $i, 'Perf item ' . $i, '', 'publish', 'perf-item-' . $i, $now, $gmt, '', '', self::POST_TYPE);
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $wpdb->query("INSERT INTO {$wpdb->posts} (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_name, post_modified, post_modified_gmt, to_ping, pinged, post_type) VALUES " . implode(',', $chunk));
        }
    }

    /**
     * Register the post type the fixture posts use.
     *
     * @return void
     */
    private static function register_post_type()
    {
        if (!post_type_exists(self::POST_TYPE)) {
            register_post_type(self::POST_TYPE, ['public' => true, 'label' => 'Perf items', 'show_in_rest' => true]);
        }
    }

    /**
     * Re-register the post type (the hook table is restored between tests).
     *
     * @return void
     */
    public function set_up()
    {
        parent::set_up();
        self::register_post_type();
    }

    /**
     * Remove the fixture blocks.
     *
     * @return void
     */
    public function tear_down()
    {
        $this->drop_blocks();
        parent::tear_down();
    }

    /**
     * The heavy option callback: every seeded post, titles through
     * get_the_title(), as the Filox Post Slider listed them.
     *
     * @return array Post id => array('label' => title).
     */
    public static function all_posts_options()
    {
        self::$callback_calls['all_posts'] = (isset(self::$callback_calls['all_posts']) ? self::$callback_calls['all_posts'] : 0) + 1;
        $options = [];
        foreach (get_posts(['post_type' => self::POST_TYPE, 'posts_per_page' => -1, 'suppress_filters' => false]) as $post) {
            $options[(string) $post->ID] = ['label' => get_the_title($post), 'filter_values' => [self::POST_TYPE]];
        }
        return $options;
    }

    /**
     * Render callback: the titles of the selected posts.
     *
     * @param array $attributes Block attributes.
     * @return string
     */
    public static function render_selected($attributes)
    {
        $ids = array_filter(array_map('absint', (array) (isset($attributes['post_ids']) ? $attributes['post_ids'] : [])));
        if (empty($ids)) {
            return '';
        }
        $titles = wp_list_pluck(get_posts(['post_type' => self::POST_TYPE, 'post__in' => $ids, 'orderby' => 'post__in', 'posts_per_page' => count($ids)]), 'post_title');
        return '<ul><li>' . implode('</li><li>', array_map('esc_html', $titles)) . '</li></ul>';
    }

    /**
     * Five slider-like blocks: two with the heavy callback select (one as
     * `object_search` with the callback as an old-EWP fallback), plus term,
     * user and static selects.
     *
     * @return array
     */
    private function heavy_blocks()
    {
        $blocks = [];
        foreach (['heavyone', 'heavytwo', 'heavythree', 'heavyfour', 'heavyfive'] as $index => $name) {
            $post_ids = [
                'label' => 'Posts',
                'case' => 'select',
                'attributes' => ['multiple' => true],
                'callback' => [__CLASS__, 'all_posts_options'],
            ];
            if ($index % 2 === 1) {
                $post_ids['object_search'] = ['type_from' => 'post_type'];
            }
            $blocks[$name] = [
                'namespace' => 'ewpperf',
                'name' => $name,
                'title' => 'Perf ' . $name,
                'version' => 1,
                'render_callback' => [__CLASS__, 'render_selected'],
                'attributes' => [
                    'post_type' => ['case' => 'select', 'options' => [self::POST_TYPE => ['label' => 'Perf items']], 'default' => self::POST_TYPE],
                    'post_ids' => $post_ids,
                    'term' => ['case' => 'term', 'taxonomy' => 'category', 'number' => '-1'],
                    'author' => ['case' => 'user', 'number' => '-1'],
                    'layout' => ['case' => 'select', 'options' => ['grid' => ['label' => 'Grid'], 'list' => ['label' => 'List']]],
                ],
            ];
        }
        return $blocks;
    }

    /**
     * Budget from the environment, or the default.
     *
     * @param string $name    Environment variable.
     * @param float  $default Default milliseconds.
     * @return float
     */
    private function budget($name, $default)
    {
        $value = getenv($name);
        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * Milliseconds and queries a callable takes.
     *
     * @param callable $run Code to measure.
     * @return array{ms: float, queries: int, result: mixed}
     */
    private function measure(callable $run)
    {
        $queries = get_num_queries();
        $start = microtime(true);
        $result = $run();
        return [
            'ms' => (microtime(true) - $start) * 1000,
            'queries' => get_num_queries() - $queries,
            'result' => $result,
        ];
    }

    /**
     * The fixture really is large, so the budgets below mean something.
     *
     * @return void
     */
    public function test_the_fixture_holds_the_extreme_post_count()
    {
        $this->assertGreaterThanOrEqual(self::POSTS, (int) wp_count_posts(self::POST_TYPE)->publish);
    }

    /**
     * Every wp-admin request (list screens, admin-ajax) gathers and registers
     * the blocks on `init`: no option callback may run and the cost stays
     * flat however many posts exist.
     *
     * @return void
     */
    public function test_admin_request_block_registration_stays_flat_with_1500_posts()
    {
        $this->as_admin_screen('edit-' . self::POST_TYPE);
        $this->use_blocks($this->heavy_blocks());
        self::$callback_calls['all_posts'] = 0;

        $run = $this->measure(function () {
            EWP_Dynamic_Blocks::instance()->gather_blocks();
            return EWP_Dynamic_Blocks::instance()->register_blocks();
        });

        $this->assertSame(0, self::calls('all_posts'), 'Option callbacks must not run on admin requests.');
        $this->assertLessThanOrEqual(2, $run['queries'], 'Block registration must not query content.');
        $this->assertLessThan($this->budget('EWP_PERF_REGISTER_MS', 150), $run['ms'], sprintf('Block registration took %.1f ms on an admin request.', $run['ms']));
    }

    /**
     * A front-end page with many blocks renders within budget and never
     * builds select options.
     *
     * @return void
     */
    public function test_front_end_page_with_many_blocks_renders_within_budget()
    {
        $this->use_blocks($this->heavy_blocks());
        EWP_Dynamic_Blocks::instance()->register_blocks();
        self::$callback_calls['all_posts'] = 0;
        $ids = get_posts(['post_type' => self::POST_TYPE, 'posts_per_page' => 6, 'fields' => 'ids']);
        $content = '';
        for ($i = 0; $i < self::BLOCKS_ON_PAGE; $i++) {
            $name = array_keys($this->heavy_blocks())[$i % 5];
            $content .= '<!-- wp:ewpperf/' . $name . ' ' . wp_json_encode(['post_type' => self::POST_TYPE, 'post_ids' => array_map('strval', $ids)]) . ' /-->' . "\n";
        }

        $run = $this->measure(function () use ($content) {
            return do_blocks($content);
        });

        $this->assertSame(0, self::calls('all_posts'), 'Option callbacks must not run on the front end.');
        $this->assertSame(self::BLOCKS_ON_PAGE, substr_count($run['result'], '<ul>'), 'Every block rendered.');
        $this->assertLessThanOrEqual(self::BLOCKS_ON_PAGE * 3, $run['queries'], 'At most a few queries per block.');
        $this->assertLessThan($this->budget('EWP_PERF_RENDER_MS', 500), $run['ms'], sprintf('The page took %.1f ms to render.', $run['ms']));
    }

    /**
     * The block editor payload: `object_search` selects send no options, and
     * a select still built from the heavy callback runs it exactly once.
     *
     * @return void
     */
    public function test_editor_payload_is_built_once_and_object_search_sends_no_options()
    {
        $this->use_blocks($this->heavy_blocks());
        self::$callback_calls['all_posts'] = 0;

        $run = $this->measure(function () {
            return EWP_Dynamic_Blocks::instance()->editor_blocks();
        });
        EWP_Dynamic_Blocks::instance()->editor_blocks();

        $this->assertSame(3, self::calls('all_posts'), 'Only the three blocks without object_search list posts, once each per request.');
        $this->assertSame([], $run['result']['heavytwo']['attributes']['post_ids']['options']);
        $this->assertSame('object_search', $run['result']['heavytwo']['attributes']['post_ids']['render_type']);
        $this->assertCount(self::POSTS, $run['result']['heavyone']['attributes']['post_ids']['options']);
        $this->assertLessThan($this->budget('EWP_PERF_EDITOR_MS', 3000), $run['ms'], sprintf('The editor payload took %.1f ms.', $run['ms']));
    }

    /**
     * The object search answers one keystroke within budget on the large type
     * and returns no more than the limit.
     *
     * @return void
     */
    public function test_object_search_answers_within_budget_on_1500_posts()
    {
        self::register_post_type();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $request = new WP_REST_Request('GET', '/extend-wp/v1/objects/search');
        $request->set_param('object_type', 'post_type:' . self::POST_TYPE);
        $request->set_param('search', 'Perf item 1');
        $request->set_param('limit', 20);
        $request->set_param('search_meta', false);

        $run = $this->measure(function () use ($request) {
            return rest_do_request($request);
        });

        $this->assertSame(200, $run['result']->get_status());
        $this->assertLessThanOrEqual(20, count($run['result']->get_data()['data']));
        $this->assertLessThan($this->budget('EWP_PERF_RENDER_MS', 500), $run['ms'], sprintf('One search took %.1f ms.', $run['ms']));
    }
}
