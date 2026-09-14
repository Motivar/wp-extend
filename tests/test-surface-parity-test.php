<?php
/**
 * Every operation declared in the surfaces registry must actually be
 * reachable on every surface it enables, and be fully described, so REST,
 * WP-CLI and the Abilities API can never drift apart again.
 */

use EWP\Surfaces\EWP_Surfaces;
use Gnnpls\WP\Adapters\Cli_Adapter;
use Gnnpls\WP\Context;
use Gnnpls\WP\Field;
use Gnnpls\WP\Field_Map;
use Gnnpls\WP\Inventory;

class Test_Surface_Parity extends WP_UnitTestCase
{
    use EWP_Test_Content_Fixture;

    /** @var \Gnnpls\WP\Registry */
    private $registry;

    public function set_up()
    {
        parent::set_up();
        // Other test classes replace the REST server; rebuild it so every hooked registration runs.
        self::refresh_rest_routes();
        $this->registry = EWP_Surfaces::instance()->registry();
        $this->assertNotNull($this->registry, 'the surfaces registry must have booted');
    }

    public function test_every_rest_binding_of_every_operation_is_registered_with_its_method()
    {
        $routes = rest_get_server()->get_routes();

        foreach ($this->registry->all() as $resource) {
            $namespace = $resource->rest_namespace();
            if ($this->is_foreign_fixture($resource)) {
                continue;
            }
            foreach ($resource->ops() as $op) {
                if (!$op->is_on(Context::REST) || $namespace === null) {
                    continue;
                }
                foreach ($op->rest_bindings() as $binding) {
                    $key = '/' . trim($namespace, '/') . Inventory::join($resource->rest_base(), $binding['path']);
                    $this->assertArrayHasKey($key, $routes, "{$resource->name()}/{$op->name()}: route {$key} missing");
                    $methods = [];
                    foreach ($routes[$key] as $handler) {
                        $methods = array_merge($methods, array_keys((array) $handler['methods']));
                    }
                    $this->assertContains($binding['method'], $methods, "{$key} does not accept {$binding['method']}");
                }
            }
        }
    }

    public function test_every_cli_binding_registers_a_command()
    {
        \WP_CLI\StubRecorder::reset();

        foreach ($this->registry->all() as $resource) {
            (new Cli_Adapter($resource))->register_commands();
        }
        $registered = array_column(\WP_CLI\StubRecorder::$commands, 'command');

        foreach ($this->registry->all() as $resource) {
            foreach ($resource->ops() as $op) {
                foreach (Inventory::cli_commands($resource, $op) as $command) {
                    $this->assertContains($command, $registered, "{$resource->name()}/{$op->name()}: command {$command} missing");
                }
            }
        }
    }

    public function test_every_ability_binding_is_registered_with_core()
    {
        if (!\EWP\Abilities\EWP_Abilities::is_enabled()) {
            $this->markTestSkipped('abilities disabled');
        }

        foreach ($this->registry->all() as $resource) {
            foreach ($resource->ops() as $op) {
                foreach (Inventory::abilities($resource, $op) as $ability) {
                    $this->assertTrue(wp_has_ability($ability), "{$resource->name()}/{$op->name()}: ability {$ability} missing");
                }
            }
        }
    }

    public function test_every_operation_and_field_is_documented()
    {
        foreach ($this->registry->all() as $resource) {
            foreach ($resource->ops() as $op) {
                $where = "{$resource->name()}/{$op->name()}";
                $this->assertNotSame('', $op->label_of(), "{$where}: no label");
                $this->assertNotSame('', $op->description_of(), "{$where}: no description");
                $this->assertNotSame([], $op->enabled_surfaces() + $op->surface_reasons(), "{$where}: no surfaces and no reason");

                foreach ($op->fields() as $field) {
                    $this->assert_field_documented($field, $where);
                }

                foreach (Field_Map::to_rest_args($op->fields()) as $name => $arg) {
                    foreach (['type', 'required', 'description', 'sanitize_callback', 'validate_callback'] as $key) {
                        $this->assertArrayHasKey($key, $arg, "{$where}: REST arg {$name} lacks {$key}");
                    }
                }
            }
        }
    }

    public function test_inventory_reports_every_resource_once()
    {
        $rows  = Inventory::export($this->registry);
        $names = array_unique(array_column($rows, 'resource'));

        $this->assertSame(count($this->registry->all()), count($names));
        foreach ($rows as $row) {
            $this->assertSame(['resource', 'operation', 'kind', 'surfaces', 'excluded', 'confirm'], array_keys($row));
        }
    }

    /**
     * Per-type resources registered by other test classes' fixtures stay in
     * the static registry after WP_UnitTestCase restored the hooks that
     * registered their routes; they are covered by tests/test-content-rest-test.php.
     *
     * @param \Gnnpls\WP\Resource $resource Resource.
     *
     * @return bool
     */
    private function is_foreign_fixture($resource)
    {
        return $resource instanceof \EWP\Surfaces\Resources\Content_Type_Rest_Resource
            && strpos($resource->name(), 'phpunit_') !== false;
    }

    /**
     * @param Field  $field Field to check (recursing into object properties).
     * @param string $where Operation label for messages.
     */
    private function assert_field_documented(Field $field, $where)
    {
        $this->assertNotSame('', $field->description() . ($field->schema_of()['description'] ?? ''), "{$where}: field {$field->name()} has no description");
        foreach ($field->properties_of() as $child) {
            $this->assert_field_documented($child, $where . '.' . $field->name());
        }
    }
}
