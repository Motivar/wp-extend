<?php
/**
 * 1.9.0 security fixes outside the modal routes:
 * - `?ewp_delete_trans` flushed every cache for anonymous visitors;
 * - options import wrote `backup_file` to any server path from REST and
 *   abilities, even on a dry run.
 */
class Test_Security_Hardening extends WP_UnitTestCase
{
    /**
     * Remove per-test state.
     *
     * @return void
     */
    public function tear_down()
    {
        unset($_GET['ewp_delete_trans']);
        parent::tear_down();
    }

    /**
     * Cache flush url is ignored for visitors without manage options.
     *
     * @return void
     */
    public function test_cache_flush_url_is_ignored_for_visitors_without_manage_options()
    {
        $installer = new EWP_WP_Content_Installer();
        $fired     = false;
        add_action('ewp_flush_cache_pre_action', function () use (&$fired) {
            $fired = true;
        });

        $_GET['ewp_delete_trans'] = '1';
        wp_set_current_user(0);
        $installer->maybe_flush_cache_from_url();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $installer->maybe_flush_cache_from_url();

        $this->assertFalse($fired);
    }

    /**
     * Backup file is refused on rest.
     *
     * @return void
     */
    public function test_backup_file_is_refused_on_rest()
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $request = new WP_REST_Request('POST', '/extend-wp/v1/options-portability/import');
        $request->set_param('data', ['pages' => ['x' => ['fields' => ['a' => 1]]]]);
        $request->set_param('dry_run', true);
        $request->set_param('backup_file', sys_get_temp_dir() . '/ewp-phpunit-backup.php');

        $response = rest_get_server()->dispatch($request);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('ewp_options_backup_file_cli_only', $response->get_data()['code']);
        $this->assertFileDoesNotExist(sys_get_temp_dir() . '/ewp-phpunit-backup.php');
    }

    /**
     * Backup file must be json in an existing directory.
     *
     * @return void
     */
    public function test_backup_file_must_be_json_in_an_existing_directory()
    {
        $php = EWP_Options_Portability::write_backup_file(sys_get_temp_dir() . '/ewp-phpunit-backup.php', ['a' => 1]);
        $dir = EWP_Options_Portability::write_backup_file(sys_get_temp_dir() . '/no-such-dir-' . getmypid() . '/b.json', ['a' => 1]);

        $this->assertWPError($php);
        $this->assertWPError($dir);
        $this->assertFileDoesNotExist(sys_get_temp_dir() . '/ewp-phpunit-backup.php');

        $json = sys_get_temp_dir() . '/ewp-phpunit-backup-' . getmypid() . '.json';
        $this->assertTrue(EWP_Options_Portability::write_backup_file($json, ['a' => 1]));
        $this->assertSame(['a' => 1], json_decode(file_get_contents($json), true));
        unlink($json);
    }
}
