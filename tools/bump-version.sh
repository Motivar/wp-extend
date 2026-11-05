#!/usr/bin/env bash
#
# Bump the Extend WP version and prepare the release.
#
# Sets the version in the "Version:" header of extend-wp.php (the source of truth) and in
# package.json (webpack writes build/version.php from it), rebuilds so build/version.php
# matches, and turns the "[Unreleased]" CHANGELOG section into the new version's section.
#
# Usage:
#   composer version-bump            1.14.3 -> 1.14.4 (patch, the default)
#   composer version-bump minor      1.14.3 -> 1.15.0
#   composer version-bump major      1.14.3 -> 2.0.0
#   composer version-bump 1.15.0     set an exact version
#
# Only edits files; commit them, merge to the default branch, then run the manual
# "release_build" job. See docs/release.md.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

#######################################
# Print an error and exit.
# Arguments: message
#######################################
die() {
  echo "ERROR: $1" >&2
  exit 1
}

#######################################
# Read the current version from the extend-wp.php header.
# Outputs: the version string
#######################################
current_version() {
  sed -n 's/^Version:[[:space:]]*\([0-9][0-9.]*\).*/\1/p' extend-wp.php | head -n 1
}

#######################################
# Compute the next version.
# Arguments: current version, patch|minor|major|X.Y.Z
# Outputs: the next version string
#######################################
next_version() {
  local major minor patch
  IFS=. read -r major minor patch <<<"$1"
  patch="${patch:-0}"
  case "$2" in
    patch) echo "$major.$minor.$((patch + 1))" ;;
    minor) echo "$major.$((minor + 1)).0" ;;
    major) echo "$((major + 1)).0.0" ;;
    *)
      [[ "$2" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "expected patch, minor, major or X.Y.Z, got '$2'."
      echo "$2"
      ;;
  esac
}

#######################################
# Write the version into extend-wp.php and package.json.
# Arguments: version
#######################################
write_version() {
  VERSION="$1" perl -pi -e 's/^Version:\s*[0-9][0-9.]*/Version: $ENV{VERSION}/' extend-wp.php
  grep -q "^Version: $1$" extend-wp.php || die "extend-wp.php header was not updated."
  VERSION="$1" perl -0pi -e 's/("version":\s*")[0-9][0-9.]*(")/$1$ENV{VERSION}$2/' package.json
  grep -q "\"version\": \"$1\"" package.json || die "package.json version was not updated."
}

#######################################
# Turn "## [Unreleased]" into a dated section for the version, keeping an empty Unreleased on top.
# Arguments: version
#######################################
promote_changelog() {
  grep -q '^## \[Unreleased\]' CHANGELOG.md || die "CHANGELOG.md has no [Unreleased] section."
  VERSION="$1" TODAY="$(date +%F)" perl -pi -e 's/^## \[Unreleased\]\s*$/## [Unreleased]\n\n## [$ENV{VERSION}] - $ENV{TODAY}\n/' CHANGELOG.md
}

main() {
  local current next
  current="$(current_version)"
  [[ -n "$current" ]] || die "no Version: header found in extend-wp.php."
  next="$(next_version "$current" "${1:-patch}")"
  [[ "$next" != "$current" ]] || die "version is already $current."
  ! grep -q "^## \[$next\]" CHANGELOG.md || die "CHANGELOG.md already has a [$next] section."

  write_version "$next"
  promote_changelog "$next"
  npm run build
  grep -q "'$next'" build/version.php || die "build/version.php does not say $next after the build."

  echo "Extend WP $current -> $next (extend-wp.php, package.json, build/, CHANGELOG.md)."
  echo "Next: review CHANGELOG.md, commit, merge to the default branch, run the release_build job."
}

main "$@"
