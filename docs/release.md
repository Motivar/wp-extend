# Releasing Extend WP

A release is a **version tag** on the merged commit. `build/` is committed on the branch, so nothing is compiled in CI. The tag comes from the shared plugin-release template (`tools/motivar-cicd-files`, `.gitlab-ci-plugin-release.yml`, README "Plugin release"), the same one Filox uses. Sites and plugins that require `motivar/extend-wp` by version constraint get the tag (`composer.json` has no `version` field; Composer takes it from the tag).

## Cutting a release

1. Bump the version and prepare the changelog:

   ```bash
   composer version-bump          # 1.14.3 -> 1.14.4 (patch)
   composer version-bump minor    # 1.14.3 -> 1.15.0
   composer version-bump major    # 1.14.3 -> 2.0.0
   composer version-bump 1.15.0   # exact version
   ```

   `tools/bump-version.sh` sets the version in the `Version:` header of `extend-wp.php` (the source of truth) and in `package.json`, runs `npm run build` so `build/version.php` matches, and turns `[Unreleased]` in `CHANGELOG.md` into a dated section for the new version. It only edits files; it does not commit.
2. Review `CHANGELOG.md`, commit the result with your change and merge to the default branch (the pre-push hook runs `composer test`).
3. Open the default-branch pipeline in GitLab and run the manual **`release_build`** job. `scripts/plugin_release.sh` reads the `Version:` header, refuses an existing tag, a header that is not `X.Y.Z`, uncommitted changes or a `composer.json` `version` field, tags the merged commit and pushes only the tag.

## Configuration

`.gitlab-ci.yml` sets `RELEASE_SKIP_BUILD: "1"`. Without it the shared script would run `npm ci && npm run build` (`package.json` has a `build` script) and then fail to commit an unchanged `build/`. `RELEASE_SKIP_BUILD` is handled by `scripts/plugin_release.sh` in `motivar-cicd-files` (MR !14). The token is the group's `GITLAB_SCHEDULE_UPDATE_TOKEN`; nothing to configure per project.

## Checks

- Before pushing, confirm the three versions agree: `extend-wp.php`, `package.json` and `build/version.php`. `composer version-bump` keeps them in step.
- Sites install a release once their `composer.json` requires a version constraint (`^1.15`) instead of `dev-main`. Switch a site's constraint only after the first tag exists. Fleet packages pulled as `dev-main` are validated by the `canary_sites` template.

## Hooks and surfaces

The release does not change any REST route, CLI command, ability or hook; those are documented in `HOOKS.md` and `docs/surfaces.md`.
