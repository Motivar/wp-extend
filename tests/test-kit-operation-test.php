<?php
/**
 * Operation::run() is the one execution path every surface shares:
 * normalise, validate, authorise, confirm, hooks, service call.
 */

use Motivar\WP\Context;
use Motivar\WP\Field;
use Motivar\WP\Inventory;
use Motivar\WP\Operation;
use Motivar\WP\Registry;
use Motivar\WP\Resource;

class Test_Kit_Operation extends WP_UnitTestCase
{
    /** @var Resource */
    private $resource;

    public function set_up()
    {
        parent::set_up();
        $this->resource = $this->make_resource();
        wp_set_current_user(0);
    }

    public function test_unattended_cli_is_trusted_and_leftover_input_reaches_the_array_parameter()
    {
        $op     = $this->resource->ops()['list'];
        $result = $op->run(['content_type' => 'ewp_fields', 'limit' => '7'], Context::cli([], []));

        $this->assertSame(['content_type' => 'ewp_fields', 'args' => ['limit' => 7]], $result);
    }

    public function test_default_values_are_applied_before_the_service_runs()
    {
        $op     = $this->resource->ops()['list'];
        $result = $op->run(['content_type' => 'x'], Context::cli([], []));

        $this->assertSame(['limit' => 5], $result['args']);
    }

    public function test_missing_required_input_is_a_400_before_any_authorisation()
    {
        $op    = $this->resource->ops()['list'];
        $error = $op->run([], Context::ability([]));

        $this->assertWPError($error);
        $this->assertSame('mwp_invalid_param', $error->get_error_code());
        $this->assertSame(400, $error->get_error_data()['status']);
    }

    public function test_subscriber_is_forbidden_on_rest_and_ability_but_admin_passes()
    {
        $op = $this->resource->ops()['list'];

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $error = $op->run(['content_type' => 'x'], Context::ability([]));
        $this->assertWPError($error);
        $this->assertSame('mwp_forbidden', $error->get_error_code());
        $this->assertSame(403, $error->get_error_data()['status']);
        $this->assertWPError($op->authorize(['content_type' => 'x'], Context::make('rest')));

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertIsArray($op->run(['content_type' => 'x'], Context::ability([])));
        $this->assertTrue($op->authorize(['content_type' => 'x'], Context::make('rest')));
    }

    public function test_cli_with_a_low_privilege_user_is_enforced_like_any_other_surface()
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $error = $this->resource->ops()['list']->run(['content_type' => 'x'], Context::cli([], []));

        $this->assertWPError($error);
        $this->assertSame('mwp_forbidden', $error->get_error_code());
    }

    public function test_public_capability_lets_anonymous_callers_through()
    {
        $this->assertIsArray($this->resource->ops()['public']->run(['content_type' => 'x'], Context::ability([])));
    }

    public function test_destructive_operation_requires_confirm_on_ability_only()
    {
        $op = $this->resource->ops()['delete'];

        $error = $op->run(['ids' => [1]], Context::ability([]));
        $this->assertWPError($error);
        $this->assertSame('mwp_confirm_required', $error->get_error_code());

        $this->assertSame(['ids' => [1]], $op->run(['ids' => [1], 'confirm' => true], Context::ability([])));
        $this->assertSame(['ids' => [2]], $op->run(['ids' => '2'], Context::cli([], [])));
    }

    public function test_hooks_fire_around_the_service_call_and_can_reshape_the_result()
    {
        $seen = [];
        add_action('mwp_operation_before_run', function (Operation $op, array $input) use (&$seen) {
            $seen[] = [$op->name(), $input];
        }, 10, 2);
        add_filter('mwp_operation_result', function ($result, Operation $op) {
            return ['wrapped' => $op->name(), 'inner' => $result];
        }, 10, 2);

        $result = $this->resource->ops()['list']->run(['content_type' => 'x'], Context::cli([], []));

        $this->assertSame([['list', ['content_type' => 'x', 'limit' => 5]]], $seen);
        $this->assertSame('list', $result['wrapped']);
    }

    public function test_service_exceptions_become_a_500_error_instead_of_escaping()
    {
        $error = $this->resource->ops()['kaboom']->run([], Context::cli([], []));

        $this->assertWPError($error);
        $this->assertSame('mwp_operation_failed', $error->get_error_code());
        $this->assertSame(500, $error->get_error_data()['status']);
    }

    public function test_resource_default_capability_and_inventory_shape()
    {
        $registry = (new Registry())->add($this->resource);
        $rows     = Inventory::export($registry);
        $by_name  = array_column($rows, null, 'operation');

        $this->assertSame('manage_options', $this->resource->ops()['list']->capability_resolver());
        $this->assertSame(['GET /kit-test/v1/things'], $by_name['list']['surfaces']['rest']);
        $this->assertSame(['kit-test things list'], $by_name['list']['surfaces']['cli']);
        $this->assertSame(['kit-test-things/list-things'], $by_name['list']['surfaces']['ability']);
        $this->assertSame([], $by_name['public']['surfaces']['rest']);
        $this->assertSame('served elsewhere', $by_name['public']['excluded']['rest']);
        $this->assertSame(['ability'], $by_name['delete']['confirm']);
        $this->assertSame(['kit-test/v1' => ['things']], Inventory::rest_namespaces($registry));
    }

    /**
     * @return Resource
     */
    private function make_resource()
    {
        $service = new class {
            public function list_items($content_type, array $args = [])
            {
                return ['content_type' => $content_type, 'args' => $args];
            }
            public function delete_items(array $ids)
            {
                return ['ids' => $ids];
            }
            public function boom()
            {
                throw new RuntimeException('boom');
            }
        };

        return new class($service) extends Resource {
            private $svc;
            public function __construct($svc)
            {
                $this->svc = $svc;
            }
            public function name()
            {
                return 'things';
            }
            public function service()
            {
                return $this->svc;
            }
            public function rest_namespace()
            {
                return 'kit-test/v1';
            }
            public function cli_base()
            {
                return 'kit-test things';
            }
            public function ability_category()
            {
                return 'kit-test-things';
            }
            public function operations()
            {
                return [
                    'list'   => Operation::read('list_items')
                        ->input([Field::string('content_type')->required(), Field::int('limit')->default_value(5)])
                        ->rest('GET')->cli('list')->ability('list-things'),
                    'public' => Operation::read('list_items')
                        ->input([Field::string('content_type')])
                        ->capability(true)
                        ->surfaces(['cli', 'ability'], 'served elsewhere')
                        ->cli('public')->ability('public-things'),
                    'delete' => Operation::destructive('delete_items')
                        ->input([Field::int_list('ids')->required()])
                        ->capability(true)
                        ->rest('DELETE')->cli('delete')->ability('delete-things'),
                    'kaboom' => Operation::read('boom')->capability(true)->cli('kaboom'),
                ];
            }
        };
    }
}
