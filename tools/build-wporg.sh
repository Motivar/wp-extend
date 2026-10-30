#!/usr/bin/env bash
#
# Build the WordPress.org package of Extend WP from the current commit.
#
#   tools/build-wporg.sh [output-dir]      (default: /tmp/extend-wp-wporg)
#
# Produces <output-dir>/extend-wp/ and <output-dir>/extend-wp.zip from the
# tracked files of HEAD (git archive), so local changes never ship, without
# development files: tests, tools, docs, examples, notes (HOOKS.md stays), CI and git hooks,
# npm / Composer / PHPCS / PHPUnit configuration, and lib/bin (the self-test
# command-line runner). The built JS (build/) ships next to its sources
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
