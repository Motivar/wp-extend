<?php

namespace EWP\SelfTest\Cases;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A throwaway custom content type (real tables, REST routes registered on
 * demand) shared by the cases that need somewhere safe to write.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
trait Fixture_Content_Type
{
    /**
     * Register the fixture type and create its tables and REST routes now.
     *
     * @param string $key       Raw key (`selftest_xxxxxx`).
     * @param string $prefix    Content prefix / REST namespace.
     * @param array  $overrides Optional. Registration keys merged over the default structure (since 1.11.0, e.g. `surfaces`).
     *
     * @return void
     */
    protected function register_fixture_type($key, $prefix, array $overrides = [])
    {
        $structure = [
            'list_name'          => 'Self-test fixture',
            'list_name_singular' => 'Self-test row',
            'capability'         => 'manage_options',
            'custom_prefix'      => $prefix,
            'statuses'           => [
                'enabled'  => ['label' => 'Enabled'],
                'disabled' => ['label' => 'Disabled'],
            ],
            'metaboxes'          => [
                'main' => [
                    'title'   => 'Main',
                    'library' => [
                        'required_field' => ['case' => 'input', 'label' => 'Required field', 'required' => true],
                        'note'           => ['case' => 'input', 'label' => 'Note', 'required' => false],
                    ],
                ],
            ],
        ];

        $structure = array_replace($structure, $overrides);

        $setup = new \AWM_Add_Content_DB_Setup();
        $setup->init(['key' => $key, 'structure' => $structure]);
        $setup->on_load();

        rest_get_server();
        $setup->rest_endpoints();
    }

    /**
     * Delete leftover rows, drop the fixture tables and forget the type.
     *
     * @param string $type Content type id (`{prefix}_selftest_xxxxxx`).
     *
     * @return string[] Messages.
     */
    protected function cleanup_fixture_type($type)
    {
        global $wpdb;

        $type     = (string) $type;
        $messages = [];

        if ($type === '' || strpos($type, 'selftest_') === false) {
            return [__('Nothing to clean: no fixture content type recorded.', 'extend-wp')];
        }

        $rows = function_exists('awm_get_db_content') ? awm_get_db_content($type, ['fields' => ['content_id'], 'limit' => 500]) : [];
        if (!empty($rows) && is_array($rows)) {
            $ids = array_map(function ($row) {
                return (int) $row['content_id'];
            }, $rows);
            awm_custom_content_delete($type, $ids);
            $messages[] = sprintf(/* translators: %1$d: number of rows, %2$s: table name */ __('Deleted %1$d leftover row(s) from %2$s.', 'extend-wp'), count($ids), $type);
        }

        foreach (['_data', '_main'] as $suffix) {
            $table = $type . $suffix;
            // Also deletes the version option and invalidates cached reads of the table (since 1.6.2).
            \AWM_DB_Creator::drop_table($table);
            $messages[]= sprintf(/* translators: %s: table name */ __('Dropped table %s.', 'extend-wp'), $wpdb->prefix . $table);
        }

        if (class_exists('AWM_Add_Content_DB_Setup') && isset(\AWM_Add_Content_DB_Setup::$ewp_data_configuration[$type])) {
            unset(\AWM_Add_Content_DB_Setup::$ewp_data_configuration[$type]);
        }

        if (function_exists('ewp_flush_cache')) {
            ewp_flush_cache();
            $messages[] = __('Flushed the Extend WP cache.', 'extend-wp');
        }

        return $messages;
    }
}
