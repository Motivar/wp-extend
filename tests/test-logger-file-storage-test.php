<?php
/**
 * Logger file storage (1.9.0): private file names, the per-file entry
 * limit, migration of the public pre-1.9.0 `.log` files, and redaction.
 *
 * Before 1.9.0 every day went to `uploads/ewp-logs/ewp-log-{date}.log`,
 * protected only by `.htaccess`, which nginx ignores: the files could be
 * downloaded by anyone and grew without limit.
 */
class Test_Logger_File_Storage extends WP_UnitTestCase
{
    /** @var string */
    private $dir;

    /** @var \EWP\Logger\EWP_Logger_File */
    private $storage;

    /**
     * Per-test fixture.
     *
     * @return void
     */
    public function set_up()
    {
        parent::set_up();
        $this->dir = sys_get_temp_dir() . '/ewp-logs-storage-' . getmypid() . '-' . wp_generate_password(6, false);
        $dir       = $this->dir;
        add_filter('ewp_logger_file_directory', function () use ($dir) {
            return $dir;
        });
        $this->storage = new \EWP\Logger\EWP_Logger_File();
    }

    /**
     * Remove per-test state.
     *
     * @return void
     */
    public function tear_down()
    {
        foreach ((array) glob($this->dir . '/{,.}*', GLOB_BRACE) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->dir);
        parent::tear_down();
    }

    /**
     * Build log entries for one day.
     *
     * @param int    $count How many.
     * @param string $date  Day (Y-m-d).
     * @return array[]
     */
    private function entries($count, $date = '2026-10-22')
    {
        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $entries[] = [
                'owner'       => 'extend-wp',
                'action_type' => 'phpunit',
                'message'     => 'entry ' . $i,
                'created_at'  => $date . ' 10:00:' . str_pad((string) ($i % 60), 2, '0', STR_PAD_LEFT),
            ];
        }
        return $entries;
    }

    /**
     * Log files of the directory (without the index), by base name.
     *
     * @return string[]
     */
    private function log_files()
    {
        $files = array_map('basename', (array) glob($this->dir . '/ewp-log-2*.php'));
        sort($files);
        return $files;
    }

    /**
     * Files are keyed php files that exit before the data.
     *
     * @return void
     */
    public function test_files_are_keyed_php_files_that_exit_before_the_data()
    {
        $this->assertTrue($this->storage->insert($this->entries(3)));

        $files = $this->log_files();
        $this->assertCount(1, $files);
        $this->assertSame('ewp-log-2026-10-22-' . \EWP\Private_Files::key() . '.php', $files[0]);
        $this->assertStringStartsWith("<?php exit; ?>\n", file_get_contents($this->dir . '/' . $files[0]));
        $this->assertSame(3, $this->storage->count(['date_from' => '2026-10-22', 'date_to' => '2026-10-22']));
    }

    /**
     * A day rotates to a new file at the entry limit.
     *
     * @return void
     */
    public function test_a_day_rotates_to_a_new_file_at_the_entry_limit()
    {
        add_filter('ewp_logger_max_entries_per_file', function () {
            return 100;
        });

        // Two writes, so the second one continues from the index.
        $this->storage->insert($this->entries(150));
        $this->storage->insert($this->entries(100));

        $files = $this->log_files();
        $this->assertCount(3, $files);
        $key = \EWP\Private_Files::key();
        $this->assertSame(["ewp-log-2026-10-22-$key-2.php", "ewp-log-2026-10-22-$key-3.php", "ewp-log-2026-10-22-$key.php"], $files);

        $lines = array_map(function ($file) {
            return count(file($this->dir . '/' . $file, FILE_SKIP_EMPTY_LINES)) - 1;
        }, $files);
        $this->assertSame([100, 50, 100], $lines, 'parts 2, 3 and 1 hold 100, 50 and 100 entries');

        $this->assertSame(250, $this->storage->count([]));
        $this->assertCount(250, $this->storage->query(['limit' => 1000]));
    }

    /**
     * The limit is never below 100.
     *
     * @return void
     */
    public function test_the_limit_is_never_below_100()
    {
        add_filter('ewp_logger_max_entries_per_file', '__return_zero');
        $this->assertSame(100, \EWP\Logger\EWP_Logger_File::max_entries_per_file());
    }

    /**
     * Legacy public log files are moved and still readable.
     *
     * @return void
     */
    public function test_legacy_public_log_files_are_moved_and_still_readable()
    {
        wp_mkdir_p($this->dir);
        $legacy = $this->dir . '/ewp-log-2026-10-20.log';
        file_put_contents($legacy, wp_json_encode(['log_id' => 'a', 'owner' => 'extend-wp', 'action_type' => 'old', 'message' => 'old', 'created_at' => '2026-10-20 09:00:00']) . "\n");

        $this->assertSame(1, $this->storage->count([]));
        $this->assertFileDoesNotExist($legacy);
        $this->assertSame(['ewp-log-2026-10-20-' . \EWP\Private_Files::key() . '.php'], $this->log_files());
    }

    /**
     * Delete by filters keeps the guard line.
     *
     * @return void
     */
    public function test_delete_by_filters_keeps_the_guard_line()
    {
        $this->storage->insert($this->entries(3));
        $this->assertSame(1, $this->storage->delete_by_filters(['search_text' => 'entry 1']));

        $file = $this->dir . '/' . $this->log_files()[0];
        $this->assertStringStartsWith("<?php exit; ?>\n", file_get_contents($file));
        $this->assertSame(2, $this->storage->count([]));
    }

    /**
     * Secret values are redacted before storage.
     *
     * @return void
     */
    public function test_secret_values_are_redacted_before_storage()
    {
        $redacted = \EWP\Logger\EWP_Logger::redact([
            'user_pass'  => 'hunter2',
            'api_key'    => 'sk-123',
            'nested'     => ['Authorization' => 'Bearer x', 'passengers' => 2],
            'meta_key'   => 'price',
        ]);

        $this->assertSame('[redacted]', $redacted['user_pass']);
        $this->assertSame('[redacted]', $redacted['api_key']);
        $this->assertSame('[redacted]', $redacted['nested']['Authorization']);
        $this->assertSame(2, $redacted['nested']['passengers']);
        $this->assertSame('price', $redacted['meta_key']);
    }
}
