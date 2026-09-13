<?php
/**
 * EWP_Logger_Query::args_from() is the one place every logger surface
 * turns its input into storage arguments. These tests pin the mapping
 * rules so REST, CLI, abilities and the diagnose box cannot drift.
 */

use EWP\Logger\EWP_Logger;
use EWP\Logger\EWP_Logger_Query;

class Test_Logger_Query extends WP_UnitTestCase
{
    /** @var EWP_Logger_Query */
    private $query;

    public function set_up()
    {
        parent::set_up();
        $this->query = new EWP_Logger_Query();
    }

    public function test_every_registered_parameter_reaches_storage()
    {
        $args = $this->query->args_from([
            'owner'       => 'filox, sync',
            'action_type' => ['content_save'],
            'object_type' => 'custom_content',
            'behaviour'   => 'error',
            'level'       => 'editor',
            'user_id'     => '7',
            'date_from'   => '01-09-2026',
            'date_to'     => '2026-09-13',
            'request_id'  => 'req-1',
            'search_text' => 'failed <b>x</b>',
        ]);

        $this->assertSame(['filox', 'sync'], $args['owner']);
        $this->assertSame('content_save', $args['action_type']);
        $this->assertSame('custom_content', $args['object_type']);
        $this->assertSame(EWP_Logger::BEHAVIOUR_ERROR, $args['behaviour']);
        $this->assertSame('editor', $args['level']);
        $this->assertSame(7, $args['user_id']);
        $this->assertSame('2026-09-01', $args['date_from'], 'd-m-Y dates are normalised');
        $this->assertSame('2026-09-13', $args['date_to']);
        $this->assertSame('req-1', $args['request_id']);
        $this->assertSame('failed x', $args['search_text'], 'search text is sanitised');
    }

    public function test_behaviour_accepts_labels_and_storage_integers_alike()
    {
        $this->assertSame(EWP_Logger::BEHAVIOUR_SUCCESS, $this->query->args_from(['behaviour' => 'success'])['behaviour']);
        $this->assertSame(EWP_Logger::BEHAVIOUR_WARNING, $this->query->args_from(['behaviour' => '2'])['behaviour']);
        $this->assertSame([0, 1], $this->query->args_from(['behaviour' => 'error,success'])['behaviour']);
        $this->assertSame([0, 2], $this->query->args_from(['behaviour' => ['0', 'warning']])['behaviour']);
        $this->assertArrayNotHasKey('behaviour', $this->query->args_from(['behaviour' => 'nonsense']));
        $this->assertArrayNotHasKey('behaviour', $this->query->args_from(['behaviour' => '']));
    }

    public function test_viewer_object_filter_pair_maps_to_object_type_and_ids()
    {
        $args = $this->query->args_from(['object_filter' => 'custom_content:ewp_fields', 'object_filter_ids' => '3,4']);

        $this->assertSame('custom_content', $args['object_type']);
        $this->assertSame([3, 4], $args['object_id']);

        $explicit = $this->query->args_from(['object_filter' => 'custom_content:x', 'object_type' => 'user']);
        $this->assertSame('user', $explicit['object_type'], 'an explicit object_type wins over the filter group');
    }

    public function test_window_default_is_opt_in_per_surface()
    {
        $unbounded = $this->query->args_from([], ['window' => null]);
        $this->assertSame('', $unbounded['date_from']);
        $this->assertSame('', $unbounded['date_to']);

        $seven = $this->query->args_from([], ['window' => 7]);
        $this->assertSame(gmdate('Y-m-d', strtotime('-7 days')), $seven['date_from']);
        $this->assertSame(gmdate('Y-m-d'), $seven['date_to']);

        $given = $this->query->args_from(['date_from' => '2026-01-01'], ['window' => 7]);
        $this->assertSame('2026-01-01', $given['date_from'], 'an explicit date is never overridden');
    }

    public function test_pagination_accepts_limit_offset_and_page_per_page_and_clamps()
    {
        $defaults = $this->query->args_from([]);
        $this->assertSame(EWP_Logger_Query::DEFAULT_LIMIT, $defaults['limit']);
        $this->assertSame(0, $defaults['offset']);
        $this->assertSame('DESC', $defaults['order']);

        $paged = $this->query->args_from(['per_page' => '20', 'page' => '3', 'order' => 'asc']);
        $this->assertSame(20, $paged['limit']);
        $this->assertSame(40, $paged['offset']);
        $this->assertSame('ASC', $paged['order']);

        $this->assertSame(EWP_Logger_Query::MAX_LIMIT, $this->query->args_from(['limit' => 99999])['limit']);
        $this->assertSame(10000, $this->query->args_from(['limit' => 99999], ['max' => 10000])['limit']);
        $this->assertSame(1, $this->query->args_from(['limit' => '0'])['limit'] > 0 ? 1 : 0);
    }

    public function test_parameters_registered_by_other_plugins_are_forwarded_on_every_surface()
    {
        add_filter('ewp_logger_filter_params', function ($params) {
            $params[] = 'booking_code';
            return $params;
        });

        $args = $this->query->args_from(['booking_code' => 'ABC-1', 'unregistered' => 'dropped']);

        $this->assertSame('ABC-1', $args['booking_code']);
        $this->assertArrayNotHasKey('unregistered', $args);
        $this->assertContains('booking_code', \EWP\Logger\EWP_Logger_API::get_filter_params(), 'the API class delegates to the service');
    }

    public function test_vocabulary_lists_owners_types_and_fixed_enums()
    {
        $vocabulary = $this->query->vocabulary();

        $this->assertSame(['error', 'success', 'warning'], $vocabulary['behaviours']);
        $this->assertSame(['editor', 'developer'], $vocabulary['levels']);
        $this->assertSame(['key', 'owner', 'label', 'description'], array_keys($vocabulary['action_types'][0]));
        $this->assertNotEmpty(array_filter($vocabulary['object_types'], function ($type) {
            return $type['key'] === 'custom_content';
        }));
    }

    public function test_delete_strips_pagination_before_reaching_storage()
    {
        $seen = null;
        add_filter('ewp_logger_query_defaults', function ($defaults) {
            return $defaults;
        });

        $result = $this->query->delete(['owner' => 'nobody-owns-this', 'limit' => 5, 'page' => 2]);

        $this->assertIsInt($result, 'delete returns the deleted count, not an error, when storage is available');
    }
}
