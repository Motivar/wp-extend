#!/usr/bin/env bash
#
# The project's test command: `composer test` runs this.
#
# Runs both suites inside the DDEV web container against the isolated
# wp_extend_tests database — the PHPUnit suite (tests/) and the self-test
# suite (every REST route, WP-CLI command and ability listed in
# includes/classes/ewp-self-test/manifest.json) through
# lib/motivar/wp-self-test/bin/run.php. Used by the pre-push hook
# (installed from the same package) and by hand.
#
# Exit codes: 0 all green; 1 a suite failed; 0 with a warning when DDEV is
# not available, since that is an infrastructure gap, not a code problem.

set -uo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

PLUGIN_DIR="wp-content/plugins/$(basename "$REPO_ROOT")"
RUNNER="lib/motivar/wp-self-test/bin/run.php"
[ -f "$RUNNER" ] || RUNNER="tests/self-test-runner.php"

if ! command -v ddev >/dev/null 2>&1; then
    echo "[tests] ddev not found on PATH — skipping (not a failure)."
    exit 0
fi

if ! ddev exec true >/dev/null 2>&1; then
    echo "[tests] DDEV project isn't running (\`ddev start\`) — skipping (not a failure)."
    exit 0
fi

echo "[tests] Ensuring the test toolchain is installed..."
if [ ! -x "tests/vendor/bin/phpunit" ]; then
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

echo "[tests] Running PHPUnit (tests/)..."
ddev exec bash -c "cd $PLUGIN_DIR && WP_CORE_DIR=/var/www/html WP_TESTS_DB_HOST=db tests/vendor/bin/phpunit -c phpunit.xml.dist" || exit 1

echo "[tests] Running the self-test suite ($RUNNER)..."
ddev exec bash -c "cd $PLUGIN_DIR && WP_CORE_DIR=/var/www/html WP_TESTS_DB_HOST=db php $RUNNER" || exit 1

echo "[tests] All tests passed."
