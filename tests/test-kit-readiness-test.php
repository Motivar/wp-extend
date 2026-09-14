<?php
/**
 * The bundled gnnpls/wp-kit must stay independent of this plugin: nothing
 * under lib/gnnpls/wp-kit/src may reference extend-wp symbols or globals,
 * so a copy bundled by another plugin behaves identically. Also pins the
 * bundled copy to the version Composer locked.
 */
class Test_Kit_Readiness extends WP_UnitTestCase
{
    const KIT = '/lib/gnnpls/wp-kit';

    /** No awm_/ewp_/EWP/AWM symbol may appear in the bundled kit sources. */
    public function test_kit_sources_reference_no_extend_wp_symbols()
    {
        $offenders = [];
        foreach ($this->kit_files() as $file) {
            $source = file_get_contents($file);
            if (preg_match('/\b(awm_|ewp_|EWP\\\\|AWM_|Extend_WP|extend-wp)/', $source, $match)) {
                $offenders[] = basename($file) . ': ' . $match[0];
            }
        }

        $this->assertSame([], $offenders, 'lib/gnnpls/wp-kit/src must not depend on extend-wp.');
    }

    /** The kit reaches WordPress through its APIs only, never `global $`. */
    public function test_kit_sources_use_no_php_globals()
    {
        $offenders = [];
        foreach ($this->kit_files() as $file) {
            if (preg_match('/^\s*global\s+\$/m', file_get_contents($file))) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'lib/gnnpls/wp-kit/src must not use `global $`.');
    }

    /** Every kit file carries an ABSPATH guard and @since tags. */
    public function test_every_kit_file_is_guarded_and_versioned()
    {
        foreach ($this->kit_files() as $file) {
            $source = file_get_contents($file);
            $this->assertStringContainsString("defined('ABSPATH')", $source, basename($file) . ' needs an ABSPATH guard');
            $this->assertStringContainsString('@since', $source, basename($file) . ' needs @since tags');
        }
    }

    /** The bundled copy in lib/ is the version composer.lock pins. */
    public function test_kit_version_matches_the_locked_package()
    {
        $composer = json_decode(file_get_contents(dirname(__DIR__) . self::KIT . '/composer.json'), true);
        $lock     = json_decode(file_get_contents(dirname(__DIR__) . '/composer.lock'), true);
        $locked   = array_values(array_filter($lock['packages'], function ($package) {
            return $package['name'] === 'gnnpls/wp-kit';
        }));

        $this->assertNotEmpty($locked, 'gnnpls/wp-kit must be in composer.lock');
        $this->assertSame('v' . require dirname(__DIR__) . self::KIT . '/version.php', $locked[0]['version']);
        $this->assertSame('gnnpls/wp-kit', $composer['name']);
        $this->assertSame(['bootstrap.php'], $composer['autoload']['files']);
        $this->assertArrayNotHasKey('psr-4', $composer['autoload'], 'production autoload must stay loader-owned');
    }

    /**
     * @return string[]
     */
    private function kit_files()
    {
        $root  = dirname(__DIR__) . self::KIT . '/src';
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
