<?php
/**
 * The kit must stay extractable to its own package: nothing under
 * includes/kit/src may reference extend-wp symbols or reach for globals.
 */
class Test_Kit_Readiness extends WP_UnitTestCase
{
    public function test_kit_sources_reference_no_extend_wp_symbols()
    {
        $offenders = [];
        foreach ($this->kit_files() as $file) {
            $source = file_get_contents($file);
            if (preg_match('/\b(awm_|ewp_|EWP\\\\|AWM_|Extend_WP|extend-wp)/', $source, $match)) {
                $offenders[] = basename($file) . ': ' . $match[0];
            }
        }

        $this->assertSame([], $offenders, 'includes/kit/src must not depend on extend-wp.');
    }

    public function test_kit_sources_use_no_php_globals()
    {
        $offenders = [];
        foreach ($this->kit_files() as $file) {
            if (preg_match('/^\s*global\s+\$/m', file_get_contents($file))) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'includes/kit/src must not use `global $`.');
    }

    public function test_every_kit_file_is_guarded_and_versioned()
    {
        foreach ($this->kit_files() as $file) {
            $source = file_get_contents($file);
            $this->assertStringContainsString("defined('ABSPATH')", $source, basename($file) . ' needs an ABSPATH guard');
            $this->assertStringContainsString('@since', $source, basename($file) . ' needs @since tags');
        }
    }

    public function test_kit_version_matches_its_composer_manifest()
    {
        $composer = json_decode(file_get_contents(dirname(__DIR__) . '/includes/kit/composer.json'), true);

        $this->assertSame($composer['version'], require dirname(__DIR__) . '/includes/kit/version.php');
        $this->assertSame('motivar/wp-kit', $composer['name']);
        $this->assertSame(['bootstrap.php'], $composer['autoload']['files']);
        $this->assertArrayNotHasKey('psr-4', $composer['autoload'], 'production autoload must stay loader-owned');
    }

    /**
     * @return string[]
     */
    private function kit_files()
    {
        $root  = dirname(__DIR__) . '/includes/kit/src';
        $files = [];
        $it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($it as $entry) {
            if ($entry->isFile() && substr($entry->getFilename(), -4) === '.php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
