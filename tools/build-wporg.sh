#!/usr/bin/env bash
#
# Build the WordPress.org package of Extend WP from the current commit.
#
#   tools/build-wporg.sh [output-dir]      (default: /tmp/extend-wp-wporg)
#
# Produces <output-dir>/extend-wp/ and <output-dir>/extend-wp.zip from the
# tracked files of HEAD (git archive), so local changes never ship, without
# development files: tests, tools, docs, examples, notes (HOOKS.md stays), CI and git hooks,
# npm / Composer / PHPCS / PHPUnit configuration, lib/bin (the self-test
# command-line runner) and the mammothwp/wp-self-test package; Composer's
# autoloader is regenerated without it and patched for Plugin Check. The built JS (build/) ships next to its sources
# (assets/, src/), as WordPress.org asks for human-readable code.
#
# Nothing in the repository is changed.

set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
OUT="${1:-/tmp/extend-wp-wporg}"
PKG="$OUT/extend-wp"

cd "$ROOT"
if ! git diff --quiet HEAD; then
    echo "Note: uncommitted changes are not packaged (the build uses HEAD)." >&2
fi

rm -rf "$PKG" "$OUT/extend-wp.zip"
mkdir -p "$PKG"
git archive HEAD | tar -x -C "$PKG"

cd "$PKG"

# mammothwp/wp-self-test out: a development tool (off on production) that Extend WP only uses
# after class_exists() checks. The autoloader is regenerated offline from the installed
# packages without it (no platform_check.php either: WordPress checks Requires PHP).
# mammothwp/wp-kit stays: REST, WP-CLI and abilities are built on it.
rm -rf lib/mammothwp/wp-self-test
php <<'PHP'
<?php
$f = 'composer.json';
$c = json_decode(file_get_contents($f), true);
unset($c['require']['mammothwp/wp-self-test'], $c['scripts']);
$c['config']['platform-check'] = false;
file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$f = 'lib/composer/installed.json';
$i = json_decode(file_get_contents($f), true);
$before = count($i['packages']);
$i['packages'] = array_values(array_filter($i['packages'], function ($p) { return $p['name'] !== 'mammothwp/wp-self-test'; }));
if (count($i['packages']) !== $before - 1) { fwrite(STDERR, "mammothwp/wp-self-test not in $f\n"); exit(1); }
$i['dev-package-names'] = array_values(array_diff($i['dev-package-names'] ?? array(), array('mammothwp/wp-self-test')));
file_put_contents($f, json_encode($i, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
// installed.php (InstalledVersions' data) is not rewritten by dump-autoload.
$f = 'lib/composer/installed.php';
$s = preg_replace("/^        'mammothwp\\/wp-self-test' => array\\(.*?^        \\),\\R/ms", '', file_get_contents($f), 1, $n);
if ($n !== 1) { fwrite(STDERR, "mammothwp/wp-self-test not in $f\n"); exit(1); }
file_put_contents($f, $s);
PHP
composer dump-autoload --no-dev --optimize --no-scripts --quiet
if grep -rq "wp-self-test" lib/composer/autoload_*.php; then
    echo "mammothwp/wp-self-test is still in the autoloader." >&2
    exit 1
fi

# Composer's generated autoloader, patched for Plugin Check: direct-access guards on the
# files that only return data, no PHP < 5.6 message in lib/autoload.php, escaped package
# names in InstalledVersions' exceptions (same step as Filox's build).
php <<'PHP'
<?php
$guard = "<?php\n\nif (!defined('ABSPATH')) {\n    exit;\n}\n";
$fail = function ($msg) { fwrite(STDERR, "Autoloader patch: $msg\n"); exit(1); };
$f = 'lib/autoload.php';
$s = file_get_contents($f);
$s = preg_replace('/^if \(PHP_VERSION_ID < 50600\) \{.*?^\}\R\R?/ms', '', $s, 1, $n);
if ($n !== 1) { $fail("PHP version check not found in $f"); }
file_put_contents($f, preg_replace('/^<\?php\R/', $guard, $s, 1));
$files = array_merge(glob('lib/composer/autoload_{classmap,files,namespaces,psr4}.php', GLOB_BRACE), array('lib/composer/installed.php'));
foreach ($files as $f) {
    $s = preg_replace('/^<\?php\s/', $guard, file_get_contents($f), 1, $n);
    if ($n !== 1) { $fail("no opening tag in $f"); }
    file_put_contents($f, $s);
}
$f = 'lib/composer/InstalledVersions.php';
$s = str_replace("'Package \"' . \$packageName . '\" is not installed'", "'Package \"' . esc_html(\$packageName) . '\" is not installed'", file_get_contents($f), $n);
if ($n < 1) { $fail("exception message not found in $f"); }
file_put_contents($f, $s);
PHP

rm -rf tests tools docs examples .ai .github .githooks node_modules lib/bin
# HOOKS.md stays: readme.txt points developers to it.
find . -maxdepth 1 -name '*.md' ! -name HOOKS.md -delete
rm -f .gitlab-ci.yml .gitignore .gitattributes webpack.config.js package.json package-lock.json \
      composer.json composer.lock phpcs.xml.dist phpunit.xml.dist

cd "$OUT"
zip -qr extend-wp.zip extend-wp
SIZE=$(du -k extend-wp.zip | cut -f1)
echo "Built $OUT/extend-wp.zip (${SIZE} KB) from $(git -C "$ROOT" rev-parse --short HEAD)."
if [ "$SIZE" -gt 10240 ]; then
    echo "Warning: over 10 MB, the WordPress.org upload limit for a new plugin." >&2
fi
