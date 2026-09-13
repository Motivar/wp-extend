<?php
/**
 * One Field must project consistently onto REST args, the WP-CLI synopsis
 * and JSON Schema, and normalise raw input the same way for all three.
 */

use Motivar\WP\Field;
use Motivar\WP\Field_Map;

class Test_Kit_Field_Map extends WP_UnitTestCase
{
    public function test_int_field_projects_bounds_and_default_onto_all_three_surfaces()
    {
        $limit = Field::int('limit')->default_value(50)->min(1)->max(200)->describe('How many rows.');

        $rest = Field_Map::to_rest_args([$limit])['limit'];
        $this->assertSame('integer', $rest['type']);
        $this->assertSame(50, $rest['default']);
        $this->assertSame(1, $rest['minimum']);
        $this->assertSame(200, $rest['maximum']);
        $this->assertSame('How many rows.', $rest['description']);
        $this->assertFalse($rest['required']);
        $this->assertIsCallable($rest['sanitize_callback']);
        $this->assertIsCallable($rest['validate_callback']);
        $this->assertSame(7, call_user_func($rest['sanitize_callback'], '7'));
        $this->assertWPError(call_user_func($rest['validate_callback'], '999'));

        $schema = Field_Map::to_json_schema([$limit]);
        $this->assertSame('object', $schema['type']);
        $this->assertSame(['type' => 'integer', 'description' => 'How many rows.', 'default' => 50, 'minimum' => 1, 'maximum' => 200], $schema['properties']['limit']);
        $this->assertArrayNotHasKey('required', $schema);

        $synopsis = Field_Map::to_synopsis([$limit]);
        $this->assertSame(['type' => 'assoc', 'name' => 'limit', 'optional' => true, 'description' => 'How many rows.'], $synopsis[0]);
        $this->assertStringContainsString('[--limit=<limit>]', Field_Map::describe_cli([$limit]));
    }

    public function test_cli_name_overrides_the_dashed_flag_on_the_cli_only()
    {
        $type = Field::string('content_type')->cli_name('type');

        $this->assertSame('type', Field_Map::to_synopsis([$type])[0]['name']);
        $this->assertSame(['content_type' => 'ewp_fields'], Field_Map::from_cli([$type], [], ['type' => 'ewp_fields']));
        $this->assertArrayHasKey('content_type', Field_Map::to_rest_args([$type]));
        $this->assertArrayHasKey('content_type', Field_Map::to_json_schema([$type])['properties']);
    }

    public function test_bool_field_is_a_cli_flag_and_a_boolean_elsewhere()
    {
        $flag = Field::bool('with_meta');

        $this->assertSame('boolean', Field_Map::to_rest_args([$flag])['with_meta']['type']);
        $this->assertSame('flag', Field_Map::to_synopsis([$flag])[0]['type']);
        $this->assertSame('with-meta', Field_Map::to_synopsis([$flag])[0]['name']);
        $this->assertSame(['with_meta' => true], Field_Map::from_cli([$flag], [], ['with-meta' => true]));
        $this->assertFalse($flag->normalize('false'));
        $this->assertTrue($flag->normalize('1'));
        $this->assertWPError($flag->normalize('maybe'));
    }

    public function test_multiple_enum_accepts_comma_strings_and_rejects_unknown_values()
    {
        $status = Field::enum('status', ['enabled', 'disabled'])->multiple();

        $this->assertSame('array', Field_Map::to_json_property($status)['type'], 'abilities receive JSON, so a list is a plain array');
        $this->assertSame(['array', 'string'], Field_Map::to_rest_args([$status])['status']['type'], 'REST also accepts comma separated strings');
        $this->assertSame(['type' => 'string', 'enum' => ['enabled', 'disabled']], Field_Map::to_json_property($status)['items']);
        $this->assertSame(['enabled', 'disabled'], $status->normalize('enabled, disabled'));
        $this->assertTrue($status->validate(['enabled']));
        $this->assertWPError($status->validate(['archived']));
        $this->assertSame(['enabled', 'disabled'], Field_Map::to_synopsis([$status])[0]['options']);
    }

    public function test_positional_int_list_consumes_every_remaining_cli_argument()
    {
        $ids = Field::int_list('ids')->positional()->required();

        $this->assertSame(['ids' => ['1', '2', '3']], Field_Map::from_cli([$ids], ['1', '2', '3'], []));
        $this->assertSame([1, 2, 3], $ids->normalize(['1', '2', '3']));
        $this->assertSame([4, 5], $ids->normalize('4,5'));
        $this->assertWPError($ids->normalize(['x']));
        $this->assertWPError($ids->validate([]));

        $item = Field_Map::to_synopsis([$ids])[0];
        $this->assertSame('positional', $item['type']);
        $this->assertTrue($item['repeating']);
        $this->assertFalse($item['optional']);
        $this->assertStringContainsString('<ids>...', Field_Map::describe_cli([$ids]));
        $this->assertSame(['array', 'string'], Field_Map::to_rest_args([$ids])['ids']['type']);
    }

    public function test_object_field_decodes_json_and_normalises_declared_children()
    {
        $meta = Field::object('meta')->properties([Field::int('count')]);

        $this->assertSame(['count' => 3, 'free' => 'x'], $meta->normalize('{"count":"3","free":"x"}'));
        $this->assertWPError($meta->normalize('not json'));
        $this->assertWPError($meta->normalize(42));

        $property = Field_Map::to_json_property($meta);
        $this->assertSame('object', $property['type']);
        $this->assertTrue($property['additionalProperties']);
        $this->assertSame('integer', $property['properties']['count']['type']);
    }

    public function test_required_string_reports_a_400_parameter_error_when_missing()
    {
        $title = Field::string('title')->required()->max_length(5);

        $error = $title->validate($title->normalize(null));
        $this->assertWPError($error);
        $this->assertSame('mwp_invalid_param', $error->get_error_code());
        $this->assertSame(400, $error->get_error_data()['status']);
        $this->assertSame('title', $error->get_error_data()['param']);
        $this->assertWPError($title->validate('toolong'));
        $this->assertTrue(Field_Map::to_rest_args([$title])['title']['required']);
        $this->assertSame(['title'], Field_Map::to_json_schema([$title])['required']);
    }

    public function test_lists_are_plain_arrays_for_abilities_but_accept_strings_over_rest()
    {
        $ids = Field::int_list('ids');

        $this->assertSame('array', Field_Map::to_json_property($ids)['type']);
        $this->assertSame(['array', 'string'], Field_Map::to_rest_args([$ids])['ids']['type']);
    }

    public function test_custom_field_emits_its_schema_verbatim_and_decodes_json_input()
    {
        $schema = ['description' => 'Rows', 'type' => 'array', 'items' => ['type' => 'object']];
        $rows   = Field::custom('rows', $schema);

        $this->assertSame($schema, Field_Map::to_json_property($rows));
        $this->assertSame('array', Field_Map::to_rest_args([$rows])['rows']['type']);
        $this->assertSame([['a' => 1]], $rows->normalize('[{"a":1}]'));
        $this->assertSame('kept', Field::custom('x', ['type' => 'string'])->normalize('kept'));
    }

    public function test_object_additional_properties_can_be_closed()
    {
        $closed = Field::object('order_by')->properties([Field::string('column')])->additional_properties(false);

        $property = Field_Map::to_json_property($closed);
        $this->assertFalse($property['additionalProperties']);
        $this->assertSame(['column'], array_keys($property['properties']));
    }

    public function test_confirm_flag_is_appended_to_synopsis_only_when_requested()
    {
        $names = array_column(Field_Map::to_synopsis([], true), 'name');
        $this->assertContains('yes', $names);
        $this->assertContains('format', $names);
        $this->assertNotContains('yes', array_column(Field_Map::to_synopsis([], false), 'name'));
    }
}
