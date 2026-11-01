<?php
/**
 * Automatic configuration sync (EWP\Content\Config_Sync): legacy single file unchanged, one file per
 * object, layout detection on import, stale deletion, signature skip, migration, the dormant-option
 * guard, and the content_id behaviour of Content_Portability::import().
 *
 * The folder lives under WP_CONTENT_DIR (the sync resolves its path relative to it) and is removed
 * after every test.
 */

require_once __DIR__ . '/includes/trait-content-fixture.php';

use EWP\Content\Config_Sync;
use EWP\Content\Content_Portability;

class Test_Config_Sync extends WP_UnitTestCase
{
    use EWP_Test_Content_Fixture;

    /** @var string Fixture content type id. */
    private static $type;

    /** @var string Folder relative to wp-content. */
    private $rel;

    /** @var string Absolute, slash-terminated folder. */
    private $dir;

    /** @var Config_Sync */
    private $sync;

    /** @var \EWP\Content\Content_Service */
    private $service;

    /**
     * Fixture type; its tables are created here (see EWP_Test_Content_Fixture).
     *
     * @return void
     */
    public static function set_up_before_class()
    {
        parent::set_up_before_class();
        self::$type = self::create_content_type_fixture();
    }

    /**
     * Fresh folder, services and notices per test; auto export off until a test turns it on.
     *
     * @return void
     */
    public function set_up()
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->rel = 'ewp-sync-test-' . substr(md5(uniqid('', true)), 0, 8);
        $this->dir = untrailingslashit(WP_CONTENT_DIR) . '/' . $this->rel . '/';
        wp_mkdir_p($this->dir);
        $this->sync    = new Config_Sync();
        $this->service = new \EWP\Content\Content_Service();
        Config_Sync::reset_notices();
        delete_option(Config_Sync::OPTION_SIGNATURE);
        delete_option(Config_Sync::OPTION_HASHES);
    }

    /**
     * Remove the folder and the rows.
     *
     * @return void
     */
    public function tear_down()
    {
        $this->remove_tree(rtrim($this->dir, '/'));
        parent::tear_down();
    }

    /**
     * @param string $path Folder to delete.
     *
     * @return void
     */
    private function remove_tree($path)
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            is_dir($path . '/' . $entry) ? $this->remove_tree($path . '/' . $entry) : unlink($path . '/' . $entry);
        }
        rmdir($path);
    }

    /**
     * Turn auto export on for the fixture type.
     *
     * @param string|null $layout `single`, `per_object`, or null to leave the key out.
     *
     * @return void
     */
    private function enable_export($layout)
    {
        $settings = ['store' => true, 'types' => [self::$type], 'path' => $this->rel];
        if ($layout !== null) {
            $settings['layout'] = $layout;
        }
        update_option(Config_Sync::OPTION_EXPORT, $settings);
    }

    /**
     * Turn auto export off (stored option and filter) so deleting rows leaves the files alone.
     *
     * @return void
     */
    private function disable_export()
    {
        delete_option(Config_Sync::OPTION_EXPORT);
        remove_all_filters('ewp_auto_export_settings_filter');
    }

    /**
     * @param string $title Row title.
     *
     * @return int Row id.
     */
    private function create_row($title)
    {
        $row = $this->service->create_item(self::$type, $title, 'enabled', ['required_field' => $title]);
        $this->assertIsArray($row);

        return (int) $row['id'];
    }

    /**
     * @return string[] Row files of the fixture type, relative to the folder's ewp-config/type.
     */
    private function object_files()
    {
        return array_map('basename', (array) glob($this->dir . 'ewp-config/' . self::$type . '/*.json'));
    }

    /**
     * @param int $id Row id.
     *
     * @return string Hash of the row.
     */
    private function hash_of($id)
    {
        $row = awm_get_db_content(self::$type, ['include' => [$id]]);

        return (string) $row[0]['hash'];
    }

    /**
     * Rows of the fixture type.
     *
     * @return int
     */
    private function row_count()
    {
        return count((array) awm_get_db_content(self::$type, ['limit' => -1]));
    }

    /**
     * Delete every row of the fixture type.
     *
     * @return void
     */
    private function delete_all_rows()
    {
        $ids = array_map('intval', array_column((array) awm_get_db_content(self::$type, ['limit' => -1]), 'content_id'));
        if ($ids) {
            awm_custom_content_delete(self::$type, $ids);
        }
    }

    public function test_single_layout_export_and_import_are_unchanged()
    {
        add_filter('ewp_auto_export_settings_filter', function () {
            return ['store' => true, 'types' => [self::$type], 'path' => $this->rel];
        });
        $this->create_row('Legacy row');

        $file = $this->dir . Config_Sync::LEGACY_FILE;
        $this->assertFileExists($file);
        $this->assertDirectoryDoesNotExist($this->dir . Config_Sync::DIR);
        $data = json_decode(file_get_contents($file), true);
        $this->assertArrayHasKey(self::$type, $data);
        $this->assertArrayHasKey('modified', $data);

        $this->disable_export();
        $this->delete_all_rows();
        $result = $this->sync->import(['path' => $this->rel]);

        $this->assertSame('legacy', $result['format']);
        $this->assertSame('imported', $result['status']);
        $this->assertSame(1, $this->row_count());
    }

    public function test_filter_settings_without_layout_keep_the_single_file()
    {
        add_filter('ewp_auto_export_settings_filter', function () {
            return ['store' => true, 'types' => [self::$type], 'path' => $this->rel];
        });
        $this->create_row('Filter row');

        $this->assertFileExists($this->dir . Config_Sync::LEGACY_FILE);
        $this->assertSame(Config_Sync::LAYOUT_SINGLE, $this->sync->export_settings()['layout']);
    }

    public function test_per_object_save_writes_only_that_rows_file()
    {
        $this->enable_export('per_object');
        $first  = $this->create_row('One');
        $second = $this->create_row('Two');

        $this->assertFileExists($this->dir . 'ewp-config/format.json');
        $this->assertSame(['format' => 2], json_decode(file_get_contents($this->dir . 'ewp-config/format.json'), true));
        $this->assertFileDoesNotExist($this->dir . Config_Sync::LEGACY_FILE);
        $this->assertCount(2, $this->object_files());

        $first_file  = $this->dir . 'ewp-config/' . self::$type . '/' . $this->hash_of($first) . '.json';
        $second_file = $this->dir . 'ewp-config/' . self::$type . '/' . $this->hash_of($second) . '.json';
        $before      = file_get_contents($second_file);
        $this->service->update_item(self::$type, $first, ['meta' => ['required_field' => 'changed']]);

        $this->assertSame($before, file_get_contents($second_file), 'saving one row must not touch another row\'s file');
        $this->assertStringContainsString('changed', file_get_contents($first_file));
    }

    public function test_per_object_delete_reconciles_the_type_folder()
    {
        $this->enable_export('per_object');
        $keep = $this->create_row('Keep');
        $drop = $this->create_row('Drop');
        $this->assertCount(2, $this->object_files());

        $this->service->delete_items(self::$type, [$drop]);

        $this->assertSame([$this->hash_of($keep) . '.json'], $this->object_files());
    }

    public function test_output_is_deterministic()
    {
        $a = $this->sync->encode_json(['b' => 1, 'a' => ['z' => 1, 'y' => [3, 1, 2]]]);
        $b = $this->sync->encode_json(['a' => ['y' => [3, 1, 2], 'z' => 1], 'b' => 1]);
        $this->assertSame($a, $b);
        $this->assertSame("\n", substr($a, -1));
        $this->assertStringContainsString('"y": [', $a);
        $this->assertSame($this->sync->encode_json(['u' => 'é/ü']), "{\n    \"u\": \"é/ü\"\n}\n");

        $this->enable_export('per_object');
        $this->create_row('Stable');
        $file  = $this->dir . 'ewp-config/' . self::$type . '/' . $this->object_files()[0];
        $bytes = file_get_contents($file);
        $row   = json_decode($bytes, true);
        $this->assertArrayNotHasKey('modified_top', $row);
        $this->assertArrayNotHasKey('content_id', $row);
        $this->assertSame($row, json_decode($bytes, true));

        $summary = $this->sync->export($this->sync->export_settings());
        $this->assertSame(0, $summary['written'], 'a re-export of unchanged rows writes nothing');
        $this->assertSame($bytes, file_get_contents($file));
    }

    public function test_import_detects_the_layout()
    {
        $this->assertSame('none', $this->sync->detect_format($this->dir));
        $this->assertWPError($this->sync->import(['path' => $this->rel]));

        $this->enable_export('single');
        $this->create_row('Detect');
        $this->assertSame('legacy', $this->sync->detect_format($this->dir));

        $this->sync->export(['types' => [self::$type], 'path' => $this->rel, 'layout' => 'per_object']);
        $this->assertSame('both', $this->sync->detect_format($this->dir));

        $this->disable_export();
        $this->delete_all_rows();
        $result = $this->sync->import(['path' => $this->rel]);
        $this->assertSame('v2', $result['format'], 'the per object folder wins over the legacy file');
        $this->assertSame(1, $this->row_count());
        $messages = array_column(Config_Sync::pending_notices(), 'message');
        $this->assertNotEmpty(preg_grep('/ignored/', $messages));

        unlink($this->dir . Config_Sync::LEGACY_FILE);
        $this->assertSame('v2', $this->sync->detect_format($this->dir));
    }

    public function test_import_skips_an_unchanged_source()
    {
        $this->enable_export('per_object');
        $this->create_row('Signature');
        $this->disable_export();
        $this->delete_all_rows();

        $this->assertSame('imported', $this->sync->import(['path' => $this->rel])['status']);
        $this->assertSame('unchanged', $this->sync->import(['path' => $this->rel])['status']);
        $this->assertSame('imported', $this->sync->import(['path' => $this->rel, 'force' => true])['status']);

        $file = $this->dir . 'ewp-config/' . self::$type . '/' . $this->object_files()[0];
        file_put_contents($file, file_get_contents($file) . "\n");
        clearstatcache();
        touch($file, time() + 10);
        $this->assertSame('imported', $this->sync->import(['path' => $this->rel])['status']);
    }

    public function test_stale_deletion_only_removes_rows_previously_auto_imported()
    {
        $this->enable_export('per_object');
        $a = $this->create_row('Imported A');
        $b = $this->create_row('Imported B');
        $hash_b = $this->hash_of($b);
        $this->disable_export();
        $this->delete_all_rows();

        $this->sync->import(['path' => $this->rel]);
        $this->assertSame(2, $this->row_count());
        $local = $this->create_row('Local only');

        unlink($this->dir . 'ewp-config/' . self::$type . '/' . $hash_b . '.json');
        $result = $this->sync->import(['path' => $this->rel]);

        $this->assertSame(1, $result['deleted']);
        $hashes = array_column((array) awm_get_db_content(self::$type, ['limit' => -1]), 'hash');
        $this->assertNotContains($hash_b, $hashes);
        $this->assertCount(2, $hashes, 'the other imported row and the locally created row survive');
        $this->assertNotEmpty(awm_get_db_content(self::$type, ['include' => [$local]]));
        $this->assertNotEmpty($a);
    }

    public function test_hashes_are_shared_by_both_layouts_so_switching_never_duplicates()
    {
        $this->enable_export('single');
        $this->create_row('Switch');
        $this->sync->import(['path' => $this->rel, 'force' => true]);
        $this->assertSame(1, $this->row_count());

        $this->sync->export(['types' => [self::$type], 'path' => $this->rel, 'layout' => 'per_object']);
        unlink($this->dir . Config_Sync::LEGACY_FILE);
        $this->sync->import(['path' => $this->rel, 'force' => true]);

        $this->assertSame(1, $this->row_count());
    }

    public function test_migrate_writes_files_retires_the_legacy_file_and_saves_the_layout()
    {
        $this->enable_export('single');
        $this->create_row('Migrate one');
        $this->create_row('Migrate two');
        $this->assertFileExists($this->dir . Config_Sync::LEGACY_FILE);

        $result = $this->sync->migrate();

        $this->assertNotWPError($result);
        $this->assertTrue($result['legacy_renamed']);
        $this->assertTrue($result['layout_saved']);
        $this->assertFileDoesNotExist($this->dir . Config_Sync::LEGACY_FILE);
        $this->assertFileExists($this->dir . Config_Sync::LEGACY_BACKUP);
        $this->assertCount(2, $this->object_files());
        $this->assertSame('per_object', get_option(Config_Sync::OPTION_EXPORT)['layout']);
        $this->assertSame('per_object', $this->sync->status()['layout']);
        $this->assertSame(2, $this->sync->status()['per_object_files']);
    }

    public function test_a_stored_export_setting_without_layout_stays_dormant()
    {
        update_option(Config_Sync::OPTION_EXPORT, ['store' => true, 'types' => [self::$type], 'path' => $this->rel]);
        $this->assertTrue($this->sync->has_dormant_export_settings());
        $this->assertFalse($this->sync->export_settings()['store']);
        $this->create_row('Dormant');
        $this->assertFileDoesNotExist($this->dir . Config_Sync::LEGACY_FILE);
        $this->assertDirectoryDoesNotExist($this->dir . Config_Sync::DIR);

        $this->enable_export('single');
        $this->assertFalse($this->sync->has_dormant_export_settings());
        $this->create_row('Awake');
        $this->assertFileExists($this->dir . Config_Sync::LEGACY_FILE);
    }

    public function test_a_bad_path_raises_a_notice_instead_of_ending_the_request()
    {
        update_option(Config_Sync::OPTION_EXPORT, ['store' => true, 'types' => [self::$type], 'path' => $this->rel . '/missing', 'layout' => 'per_object']);

        $id = $this->create_row('Survives');
        $this->assertGreaterThan(0, $id, 'the save went through');

        $result = $this->sync->handle_saved(self::$type, $id);
        $this->assertWPError($result);
        $stored = get_transient(Config_Sync::TRANSIENT_NOTICES);
        $this->assertNotEmpty($stored);
        $this->assertSame('error', reset($stored)['level']);
    }

    public function test_paths_cannot_climb_out_of_wp_content()
    {
        $this->assertWPError($this->sync->resolve_dir('../uploads'));
        $this->assertWPError($this->sync->resolve_dir(''));
        $this->assertSame(untrailingslashit(WP_CONTENT_DIR) . '/a/b/', $this->sync->resolve_dir('/a/b/'));
    }

    public function test_the_filename_filter_is_honoured_and_sanitised()
    {
        add_filter('ewp_config_sync_filename', function ($name, $type, $row) {
            return 'row-' . $row['content_id'] . '.json';
        }, 10, 3);
        $this->enable_export('per_object');
        $id = $this->create_row('Named');
        $this->assertSame(['row-' . $id . '.json'], $this->object_files());

        remove_all_filters('ewp_config_sync_filename');
        add_filter('ewp_config_sync_filename', function () {
            return '../escape.json';
        });
        $this->service->update_item(self::$type, $id, ['meta' => ['required_field' => 'again']]);
        $this->assertContains($this->hash_of($id) . '.json', $this->object_files());
        $this->assertFileDoesNotExist($this->dir . 'ewp-config/escape.json');
    }

    public function test_importing_a_row_never_overwrites_a_different_row_with_the_same_content_id()
    {
        $portability = new Content_Portability();
        $existing    = $this->create_row('Target row');
        $existing_hash = $this->hash_of($existing);
        $incoming    = [[
            'content_id'    => $existing,
            'content_title' => 'Source row',
            'status'        => 'enabled',
            'hash'          => md5('another-environment'),
            'meta'          => ['required_field' => 'source'],
        ]];

        $result = $portability->import(self::$type, $incoming);

        $this->assertNotWPError($result);
        $this->assertSame(2, $this->row_count(), 'a different hash is a new row, whatever its content_id');
        $kept = awm_get_db_content(self::$type, ['include_hashes' => [$existing_hash]]);
        $this->assertSame('Target row', $kept[0]['content_title']);

        $again = [[
            'content_id'    => 999999,
            'content_title' => 'Renamed on source',
            'status'        => 'enabled',
            'hash'          => $existing_hash,
        ]];
        $portability->import(self::$type, $again);
        $this->assertSame(2, $this->row_count(), 'a known hash updates in place and keeps the local content_id');
        $updated = awm_get_db_content(self::$type, ['include_hashes' => [$existing_hash]]);
        $this->assertSame($existing, (int) $updated[0]['content_id']);
        $this->assertSame('Renamed on source', $updated[0]['content_title']);
    }

    /**
     * The per object export of the Import/Export screen (zip) holds exactly the files the auto export writes.
     *
     * @return void
     */
    public function test_per_object_export_files_match_the_auto_export()
    {
        $this->enable_export('per_object');
        $this->create_row('Zip one');
        $this->create_row('Zip two');

        $built = (new Content_Portability())->export([self::$type], ['layout' => 'per_object']);
        $this->assertIsArray($built);
        $this->assertSame([self::$type => 2], $built['types']);
        $this->assertSame("{\n    \"format\": 2\n}\n", $built['files']['ewp-config/format.json']);

        $on_disk = [];
        foreach ((array) glob($this->dir . 'ewp-config/' . self::$type . '/*.json') as $file) {
            $on_disk['ewp-config/' . self::$type . '/' . basename($file)] = file_get_contents($file);
        }
        $from_export = $built['files'];
        unset($from_export['ewp-config/format.json']);
        ksort($on_disk);
        ksort($from_export);
        $this->assertCount(2, $on_disk);
        $this->assertSame($on_disk, $from_export, 'zip entries are byte-identical to the auto exported files');

        $this->assertInstanceOf(\WP_Error::class, (new Content_Portability())->export(['no_such_type_xyz'], ['layout' => 'per_object']));
    }
}
