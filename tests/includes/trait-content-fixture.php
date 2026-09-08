<?php
/**
 * Shared test fixture: register a throwaway `awm_register_content_db`
 * content type (with real DB tables) without waiting for the `init`
 * action, since by the time a test method runs, WordPress's own `init`
 * has already fired once during tests/bootstrap.php.
 *
 * Must be called from a class's static `setUpBeforeClass()`, not from an
 * instance `set_up()`/test method: WP_UnitTestCase::start_transaction()
 * (called from the instance `set_up()`) filters every `CREATE TABLE`
 * query into `CREATE TEMPORARY TABLE` for the duration of a test, so a
 * table created mid-test is session-scoped and gets dropped by the next
 * test's cleanup — later writes against it then fail with "table does
 * not exist". Creating the table in setUpBeforeClass runs before that
 * filter is registered, so it is a real, permanent table; per-test
 * row-level isolation still comes from the normal transaction rollback.
 */
trait EWP_Test_Content_Fixture
{
    /**
     * Register a content type and create its DB tables synchronously.
     *
     * @param array $overrides Structure overrides merged over a minimal default (one required field, two statuses).
     *
     * @return string The generated content type id, e.g. `ewp_phpunit_ab12cd`.
     */
    protected static function create_content_type_fixture(array $overrides = [])
    {
        $key = 'phpunit_' . substr(md5(uniqid('', true)), 0, 8);

        $structure = array_replace_recursive([
            'list_name'          => 'PHPUnit Fixture',
            'list_name_singular' => 'PHPUnit Fixture Item',
            'capability'         => 'edit_posts',
            'statuses'           => [
                'enabled'  => ['label' => 'Enabled'],
                'disabled' => ['label' => 'Disabled'],
            ],
            'metaboxes'          => [
                'main' => [
                    'title'   => 'Main',
                    'library' => [
                        'example_field' => [
                            'case'     => 'input',
                            'label'    => 'Example field',
                            'required' => false,
                        ],
                        'required_field' => [
                            'case'     => 'input',
                            'label'    => 'Required field',
                            'required' => true,
                        ],
                    ],
                ],
            ],
        ], $overrides);

        $setup = new AWM_Add_Content_DB_Setup();
        $setup->init(['key' => $key, 'structure' => $structure]);
        // Table creation normally runs on admin_init; call it directly, and
        // only from setUpBeforeClass (see the trait docblock above), so the
        // tables exist as real (non-temporary) tables before any test uses them.
        $setup->on_load();

        $prefix = isset($structure['custom_prefix']) ? $structure['custom_prefix'] : 'ewp';

        return $prefix . '_' . strtolower(awm_clean_string($key));
    }

    /**
     * Re-fire rest_api_init so routes added via create_content_type_fixture()
     * (registered through add_action('rest_api_init', ...) inside
     * AWM_Add_Content_DB_Setup::init(), after WordPress's own rest_api_init
     * already fired once during bootstrap) actually get registered.
     *
     * @return void
     */
    protected static function refresh_rest_routes()
    {
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action('rest_api_init', $wp_rest_server);
    }
}
