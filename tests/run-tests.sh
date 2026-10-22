#!/usr/bin/env bash
#
# The project's test command: `composer test` runs this.
#
# Runs the JS unit tests on the host (`npm run test:js`, Jest; skipped without
# Node 18+), the prepared-SQL lint (phpcs.xml.dist, WPCS from tests/composer.json),
# then both suites inside the DDEV web container against the isolated
# wp_extend_tests database — the PHPUnit suite (tests/) and the self-test
# suite (every REST route, WP-CLI command and ability listed in
# includes/classes/ewp-self-test/manifest.json) through
# lib/mammothwp/wp-self-test/bin/run.php. Used by the pre-push hook
# (installed from the same package) and by hand.
#
# Exit codes: 0 all green; 1 a suite failed; 0 with a warning when DDEV is
# not available, since that is an infrastructure gap, not a code problem.

set -uo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

PLUGIN_DIR="wp-content/plugins/$(basename "$REPO_ROOT")"
RUNNER="lib/mammothwp/wp-self-test/bin/run.php"
[ -f "$RUNNER" ] || RUNNER="tests/self-test-runner.php"

# Already inside the DDEV web container: `ddev` there is a stub that prints a
# hint and exits 0, which would report a pass without running anything.
if [ "${IS_DDEV_PROJECT:-}" = "true" ]; then
    cd "/var/www/html/$PLUGIN_DIR" || exit 1
    { [ -x "tests/vendor/bin/phpunit" ] && [ -x "tests/vendor/bin/phpcs" ]; } || (cd tests && composer install --no-interaction --prefer-dist) || exit 1
    echo "[tests] Running the prepared-SQL lint (phpcs.xml.dist) inside DDEV..."
    tests/vendor/bin/phpcs --standard=phpcs.xml.dist -q || exit 1
    echo "[tests] Running PHPUnit (tests/) inside DDEV..."
    WP_CORE_DIR=/var/www/html WP_TESTS_DB_HOST=db tests/vendor/bin/phpunit -c phpunit.xml.dist || exit 1
    echo "[tests] Running the self-test suite ($RUNNER) inside DDEV..."
    WP_CORE_DIR=/var/www/html WP_TESTS_DB_HOST=db php "$RUNNER" || exit 1
    echo "[tests] All tests passed."
    exit 0
fi

if ! command -v ddev >/dev/null 2>&1; then
    echo "[tests] ddev not found on PATH — skipping (not a failure)."
    exit 0
fi

if ! ddev exec true >/dev/null 2>&1; then
    echo "[tests] DDEV project isn't running (\`ddev start\`) — skipping (not a failure)."
    exit 0
fi

echo "[tests] Ensuring the test toolchain is installed..."
if [ ! -x "tests/vendor/bin/phpunit" ] || [ ! -x "tests/vendor/bin/phpcs" ]; then
    ddev exec bash -c "cd $PLUGIN_DIR/tests && composer install --no-interaction --prefer-dist" || {
        echo "[tests] Could not install the test toolchain (tests/composer.json)."
        exit 1
    }
fi

echo "[tests] Ensuring the wp_extend_tests database exists..."
ddev exec mysql -h db -u db -pdb -e "SELECT 1;" wp_extend_tests >/dev/null 2>&1 || \
    ddev exec mysql -h db -u root -proot -e "CREATE DATABASE IF NOT EXISTS wp_extend_tests CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON wp_extend_tests.* TO 'db'@'%'; FLUSH PRIVILEGES;" >/dev/null 2>&1 || {
        echo "[tests] Could not prepare the test database."
        exit 1
    }

# JS unit tests (Jest via @wordpress/scripts) run on the host: the DDEV web
# container's Node is too old for @wordpress/scripts 30. Skipped, not failed,
# when Node 18+ or node_modules is missing.
NODE_MAJOR="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)"
if [ -d node_modules/@wordpress/scripts ] && [ "$NODE_MAJOR" -ge 18 ]; then
    echo "[tests] Running the JS unit tests (npm run test:js)..."
    npm run --silent test:js || exit 1
else
    echo "[tests] JS unit tests skipped (needs Node 18+ and npm install)."
fi

echo "[tests] Running the prepared-SQL lint (phpcs.xml.dist)..."
ddev exec bash -c "cd $PLUGIN_DIR && tests/vendor/bin/phpcs --standard=phpcs.xml.dist -q" || exit 1

echo "[tests] Running PHPUnit (tests/)..."
ddev exec bash -c "cd $PLUGIN_DIR && WP_CORE_DIR=/var/www/html WP_TESTS_DB_HOST=db tests/vendor/bin/phpunit -c phpunit.xml.dist" || exit 1

echo "[tests] Running the self-test suite ($RUNNER)..."
ddev exec bash -c "cd $PLUGIN_DIR && WP_CORE_DIR=/var/www/html WP_TESTS_DB_HOST=db php $RUNNER" || exit 1

echo "[tests] All tests passed."
