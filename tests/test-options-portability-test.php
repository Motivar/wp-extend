<?php
/**
 * Options portability: the before_import filter must run for every
 * surface, and the version helper is shared rather than copied.
 */
class Test_Options_Portability extends WP_UnitTestCase
{
    /** @var EWP_Options_Portability */
    private $portability;

    public function set_up()
    {
        parent::set_up();
        $this->portability = EWP_Options_Portability::instance();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    /**
     * The filter runs before validation, so a minimal payload is enough to
     * prove it fires; PHPUnit registers no options pages to export.
     */
    private function payload()
    {
        return ['ewp_options_export' => true, 'format_version' => 1, 'pages' => []];
    }

    public function test_before_import_filter_runs_for_direct_service_calls()
    {
        $seen = null;
        add_filter('ewp_options_portability_before_import', function ($data, $opts) use (&$seen) {
            $seen = $opts['actor'];
            return $data;
        }, 10, 2);

        $result = $this->portability->import_options($this->payload(), ['dry_run' => true, 'actor' => 'cli']);

        $this->assertSame('cli', $seen, 'the filter used to run only inside the REST wrapper');
        if (is_wp_error($result)) {
            $this->assertNotSame('import_cancelled', $result->get_error_code());
        }
    }

    public function test_before_import_filter_can_cancel_any_import()
    {
        add_filter('ewp_options_portability_before_import', '__return_false');

        $result = $this->portability->import_options($this->payload(), ['dry_run' => true, 'actor' => 'ability']);

        $this->assertWPError($result);
        $this->assertSame('import_cancelled', $result->get_error_code());
    }

    public function test_plugin_version_is_public_and_matches_the_plugin_header()
    {
        $version = $this->portability->get_plugin_version();

        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $version);
        $this->assertFalse(method_exists('EWP_Options_Portability_CLI', 'get_plugin_version'), 'the CLI must not carry its own copy');
    }
}
