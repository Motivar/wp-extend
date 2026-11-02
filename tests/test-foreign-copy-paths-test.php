<?php
/**
 * Regression: a second (older, bundled) copy of extend-wp defines awm_path / awm_url first.
 * This copy must still find its own templates and assets (incident 2026-11-02, 1.14.1).
 *
 * The foreign copy is simulated by tests/bootstrap.php when EWP_TEST_FOREIGN_COPY_DIR is set; the
 * tests re-run themselves in a child PHPUnit process with that variable, since constants are per process.
 */
class Test_Foreign_Copy_Paths extends WP_UnitTestCase
{
    /** @var string Plugin root. */
    private static function root()
    {
        return dirname(__DIR__) . '/';
    }

    /** Own-location constants point at this copy. */
    public function test_own_location_constants()
    {
        $this->assertSame(self::root(), EWP_PLUGIN_DIR);
        $this->assertNotSame('', EWP_PLUGIN_URL);
    }

    /** The default template base dir never depends on awm_path. */
    public function test_template_loader_locates_container()
    {
        $this->assertNotSame('', (new \EWP\Fields\Template_Loader())->locate('fields/container'));
    }

    /** Hidden inputs of Filox's booking form render with id and attach-to. */
    public function test_hidden_inputs_render()
    {
        $html = awm_show_content(array(
            'flx_check_in'  => array('case' => 'input', 'type' => 'hidden', 'label' => 'In', 'class' => array('flx_date_pick'), 'attributes' => array('attach-to' => 'F', 'id' => 'flx_check_in_F')),
            'flx_check_out' => array('case' => 'input', 'type' => 'hidden', 'label' => 'Out', 'class' => array('flx_date_pick'), 'attributes' => array('attach-to' => 'F', 'id' => 'flx_check_out_F')),
        ));
        $this->assertSame(2, preg_match_all('/<input[^>]*type="hidden"/', $html));
        $this->assertStringContainsString('id="flx_check_in_F"', $html);
        $this->assertStringContainsString('id="flx_check_out_F"', $html);
        $this->assertStringContainsString('attach-to="F"', $html);
    }

    /** Re-run the three checks with awm_path pointing at a directory without templates. */
    public function test_with_foreign_awm_path()
    {
        if (getenv('EWP_TEST_FOREIGN_COPY_DIR')) {
            $this->assertStringStartsNotWith(untrailingslashit(self::root()), awm_path);
            $this->assertFileDoesNotExist(awm_path . 'templates/fields/container.php');
            $this->assertNotSame(awm_path, EWP_PLUGIN_DIR);
            return;
        }
        $dir = sys_get_temp_dir() . '/ewp-foreign-copy-' . getmypid();
        wp_mkdir_p($dir);
        $cmd = sprintf(
            'cd %s && EWP_TEST_FOREIGN_COPY_DIR=%s WP_CORE_DIR=%s WP_TESTS_DB_HOST=%s tests/vendor/bin/phpunit -c phpunit.xml.dist --filter %s 2>&1',
            escapeshellarg(self::root()),
            escapeshellarg($dir),
            escapeshellarg((string) getenv('WP_CORE_DIR')),
            escapeshellarg((string) (getenv('WP_TESTS_DB_HOST') ?: 'localhost')),
            escapeshellarg('Test_Foreign_Copy_Paths')
        );
        exec($cmd, $out, $code);
        rmdir($dir);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    /** No source file builds a path/URL from the collision-prone constants. */
    public function test_sources_do_not_use_awm_path_or_url()
    {
        $offenders = array();
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root() . 'includes', FilesystemIterator::SKIP_DOTS));
        foreach (array_merge(iterator_to_array($it), glob(self::root() . 'templates/*/*.php'), glob(self::root() . 'templates/*/*/*.php')) as $file) {
            $path = (string) $file;
            if (substr($path, -4) !== '.php' || strpos($path, 'class-template-loader.php') !== false) {
                continue;
            }
            if (preg_match('/\bawm_(path|url)\b/', (string) file_get_contents($path))) {
                $offenders[] = $path;
            }
        }
        $this->assertSame(array(), $offenders);
    }
}
