<?php
/**
 * Guards the self-test manifest (includes/classes/ewp-self-test/manifest.json),
 * the single source of truth shared by the dashboard, `wp ewp self-test`,
 * the ewp-self-test abilities, the pre-push hook and CI.
 *
 * The cases themselves are NOT executed here: the content case creates real
 * tables, which WP_UnitTestCase would rewrite into temporary ones. They run
 * through tests/run-self-test.php instead.
 */
class Test_Self_Test_Manifest extends WP_UnitTestCase
{
    public function test_manifest_loads_and_every_case_class_exists()
    {
        $manifest    = new \EWP\SelfTest\EWP_Self_Test_Manifest();
        $definitions = $manifest->definitions();

        $this->assertIsArray($definitions, is_wp_error($definitions) ? $definitions->get_error_message() : '');
        $this->assertNotEmpty($definitions);

        foreach ($definitions as $id => $entry) {
            $this->assertTrue(class_exists($entry['class']), "Case {$id}: class {$entry['class']} missing");
            $this->assertNotEmpty($entry['layers'], "Case {$id}: declares no layers");
            $this->assertArrayHasKey('covers', $entry, "Case {$id}: declares no coverage");
        }
    }

    public function test_every_case_previews_without_side_effects()
    {
        global $wpdb;

        $runner = \EWP\SelfTest\EWP_Self_Test::instance()->runner();
        $before = count($wpdb->get_col('SHOW TABLES'));

        $preview = $runner->preview();

        $this->assertIsArray($preview);
        foreach ($preview as $case) {
            $this->assertNotEmpty($case['steps'], "Case {$case['id']} has no preview steps");
        }

        $this->assertSame($before, count($wpdb->get_col('SHOW TABLES')), 'preview() must not create tables');
    }

    public function test_every_plugin_cli_command_is_covered_by_the_manifest()
    {
        $covered = [];
        foreach ((new \EWP\SelfTest\EWP_Self_Test_Manifest())->definitions() as $entry) {
            foreach ($entry['covers']['cli'] as $command) {
                $covered[] = preg_replace('/\s+--.*$/', '', $command);
            }
        }

        $registered = array_map(function ($entry) {
            return $entry['command'];
        }, \WP_CLI\StubRecorder::$commands);

        $uncovered = array_filter(array_unique($registered), function ($command) use ($covered) {
            // The self-test's own commands are the runner, not a surface to cover.
            return strpos($command, 'ewp self-test') !== 0 && !in_array($command, $covered, true);
        });

        $this->assertSame([], array_values($uncovered), 'Registered WP-CLI commands missing from manifest.json "covers.cli"');
    }

    public function test_rest_routes_are_only_registered_when_ui_is_enabled()
    {
        $routes = rest_get_server()->get_routes();

        if (\EWP\SelfTest\EWP_Self_Test::ui_enabled()) {
            $this->assertArrayHasKey('/extend-wp/v1/self-test/run', $routes);
        } else {
            $this->assertArrayNotHasKey('/extend-wp/v1/self-test/run', $routes);
        }
    }
}
