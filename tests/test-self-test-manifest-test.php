<?php
/**
 * Guards the self-test manifest (includes/classes/ewp-self-test/manifest.json),
 * the single source of truth shared by the dashboard, `wp ewp self-test`,
 * the ewp-self-test abilities, the pre-push hook and CI.
 *
 * The cases themselves are NOT executed here: the content case creates real
 * tables, which WP_UnitTestCase would rewrite into temporary ones. They run
 * through tests/self-test-runner.php instead.
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

    /**
     * Every route, command and ability generated from the surfaces
     * registry must be claimed by some case's `covers`.
     */
    public function test_every_registry_surface_is_covered_by_the_manifest()
    {
        $covered  = $this->covered();
        $registry = \EWP\Surfaces\EWP_Surfaces::instance()->registry();
        $missing  = [];

        foreach ($registry->all() as $resource) {
            $per_type = $resource instanceof \EWP\Surfaces\Resources\Content_Type_Rest_Resource;
            foreach ($resource->ops() as $op) {
                foreach (\Motivar\WP\Inventory::rest_routes($resource, $op) as $route) {
                    $path = $this->normalise_route(preg_replace('/^[A-Z]+ /', '', $route), $per_type ? $resource : null);
                    if (!in_array($path, $covered['rest'], true)) {
                        $missing[] = 'rest: ' . $path;
                    }
                }
                foreach (\Motivar\WP\Inventory::cli_commands($resource, $op) as $command) {
                    if (!in_array($command, $covered['cli'], true)) {
                        $missing[] = 'cli: ' . $command;
                    }
                }
                foreach (\Motivar\WP\Inventory::abilities($resource, $op) as $ability) {
                    if (!in_array($ability, $covered['ability'], true)) {
                        $missing[] = 'ability: ' . $ability;
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'Surfaces generated from the registry that no manifest case covers');
    }

    /**
     * Every registered command must be covered, including any a module still
     * registers by hand.
     */
    public function test_every_plugin_cli_command_is_covered_by_the_manifest()
    {
        \WP_CLI\StubRecorder::reset();
        foreach (\EWP\Surfaces\EWP_Surfaces::instance()->registry()->all() as $resource) {
            (new \Motivar\WP\Adapters\Cli_Adapter($resource))->register_commands();
        }

        $covered    = $this->covered()['cli'];
        $registered = array_unique(array_column(\WP_CLI\StubRecorder::$commands, 'command'));
        $uncovered  = array_filter($registered, function ($command) use ($covered) {
            return strpos($command, 'ewp self-test') !== 0 && !in_array($command, $covered, true);
        });

        $this->assertSame([], array_values($uncovered), 'Registered WP-CLI commands missing from manifest.json "covers.cli"');
    }

    /**
     * A REST route of this plugin that is neither generated from the
     * registry nor covered by a case must be listed in rest-only.json with
     * a reason.
     */
    public function test_every_other_plugin_rest_route_is_covered_or_allowlisted()
    {
        $covered    = $this->covered()['rest'];
        $allowlist  = json_decode(file_get_contents(dirname(__DIR__) . '/includes/classes/ewp-self-test/rest-only.json'), true);
        $allowed    = array_keys($allowlist['routes']);
        $namespaces = ['extend-wp/v1', 'ewp/v1', 'ewp', 'ewp-filter'];
        $generated  = [];

        $registry = \EWP\Surfaces\EWP_Surfaces::instance()->registry();
        foreach ($registry->all() as $resource) {
            $per_type = $resource instanceof \EWP\Surfaces\Resources\Content_Type_Rest_Resource;
            foreach ($resource->ops() as $op) {
                foreach (\Motivar\WP\Inventory::rest_routes($resource, $op) as $route) {
                    $generated[] = $this->normalise_route(preg_replace('/^[A-Z]+ /', '', $route), $per_type ? $resource : null);
                }
            }
        }

        $unexplained = [];
        foreach (array_keys(rest_get_server()->get_routes()) as $key) {
            $namespace = $this->namespace_of($key, $namespaces);
            if ($namespace === null || trim($key, '/') === $namespace) {
                continue;
            }
            $path = $this->normalise_route($key, $namespace === 'ewp' ? 'content-type' : null);
            if (in_array($path, $generated, true) || in_array($path, $covered, true) || in_array($path, $allowed, true)) {
                continue;
            }
            $unexplained[] = $path;
        }

        $this->assertSame([], array_values(array_unique($unexplained)), 'REST routes that are neither generated from a resource, covered by a case, nor explained in rest-only.json');
    }

    /**
     * @return array{rest:string[],cli:string[],ability:string[]}
     */
    private function covered()
    {
        $covered = ['rest' => [], 'cli' => [], 'ability' => []];
        foreach ((new \EWP\SelfTest\EWP_Self_Test_Manifest())->definitions() as $entry) {
            foreach (['rest', 'cli', 'ability'] as $layer) {
                foreach ((array) ($entry['covers'][$layer] ?? []) as $item) {
                    $covered[$layer][] = $layer === 'cli' ? preg_replace('/\s+--.*$/', '', $item) : trim($item, '/');
                }
            }
        }

        return $covered;
    }

    /**
     * `/extend-wp/v1/logs/entry/(?P<log_id>[^/]+)` → `extend-wp/v1/logs/entry/{log_id}`;
     * per-type content routes → `{prefix}/{type}/…`.
     *
     * @param string      $route   Route key or inventory path.
     * @param mixed       $content Content_Type_Rest_Resource, the string 'content-type', or null.
     *
     * @return string
     */
    private function normalise_route($route, $content = null)
    {
        $path = preg_replace('/\(\?P<([a-z_]+)>[^)]*\)/', '{$1}', trim($route, '/'));

        if ($content !== null) {
            $segments = explode('/', $path);
            $segments[0] = '{prefix}';
            if (isset($segments[1])) {
                $segments[1] = '{type}';
            }
            $path = implode('/', $segments);
        }

        return $path;
    }

    /**
     * @param string   $route      Route key.
     * @param string[] $namespaces Namespaces of this plugin.
     *
     * @return string|null
     */
    private function namespace_of($route, array $namespaces)
    {
        usort($namespaces, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        foreach ($namespaces as $namespace) {
            if ($route === '/' . $namespace || strpos($route, '/' . $namespace . '/') === 0) {
                return $namespace;
            }
        }

        return null;
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
