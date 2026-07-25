# AGENTS.md

Guidance for AI agents (and humans) contributing to the **pm MCP plugin**
(`wa-apps/mcp/plugins/pm`). Read this before making changes or committing.

## Repository conventions

This plugin lives in its own Git repository, separate from the host Webasyst
installation. Development happens on `dev`; `master` holds released/stable
state. Tag a commit `vX.Y.Z` to trigger the release workflow.

## Changelog — Keep a Changelog

Every notable change **must** be recorded in [`CHANGELOG.md`](CHANGELOG.md),
which follows the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
format and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

- Add entries under the `## [Unreleased]` section as you work, grouped by type:
  `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`.
- On release, rename `[Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD`, bump the
  `version` in `lib/config/plugin.php` to match, and open a fresh
  `[Unreleased]` section.
- The changelog is written for humans — describe the change, not the diff.

## Commits — Conventional Commits

Commit messages **must** follow the
[Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) spec:

```
<type>[optional scope]: <description>

[optional body]

[optional footer(s)]
```

- Common types: `feat`, `fix`, `docs`, `refactor`, `test`, `chore`, `ci`,
  `build`, `perf`, `style`.
- Keep the description in the imperative mood, lower-case, no trailing period.
- Breaking changes: add `!` after the type/scope (`feat!:`) and/or a
  `BREAKING CHANGE:` footer.

### Task references

When the work implements a tracked task, **mention the task number with a
leading hash** in the commit — either in the description or the body, e.g.:

```
feat(tools): add pm_list_projects read tool

Implements the first read tool of Stage 1. Task #78.2
```

Use the `Task #X.Y` form (hash + dotted task number). This keeps the plugin's
history traceable back to the project tracker.

## Testing

PHPUnit 9 is used **globally** (no composer, no bundled phpunit). Run from the
plugin root:

```
php /path/to/phpunit
```

- `phpunit.xml` — schema 9.3, `bootstrap="tests/init.php"`, coverage over `lib`
  excluding `lib/config`.
- `tests/init.php` — bootstrap. Boots the framework, then **`pm` first** and
  `mcp` second, eager-loads the plugin classes and primes the workflow cache.
  The order matters: the first app booted becomes the current app, and it must
  stay `pm` so pm-relative config lookups (`pmHelper::getConfig` ->
  `pmWorkflow` / `pmRoleModel`, which cache statically) resolve against the pm
  app. Do **not** use `wa('pm', 1)` or `waSystem::setActive('pm')` in the
  bootstrap — both re-initialise the system / reload the locale, which under the
  PHPUnit CLI clobbers argv (PHPUnit then aborts with a usage message) or
  fatals.
- `tests/pmMcpSmokeTest.php` — the whole tool surface registers, every tool has
  a matching right (right name == tool name), schemas are well-formed.
- `tests/pmMcpSchemaTest.php` — DB-independent unit tests: argument coercers,
  schema validation, wiki access-role and page-visibility logic.
- `tests/pmMcpIntegrationTestCase.php` — abstract base for the integration
  tests (does not end in `*Test.php`, so it is required from the bootstrap, not
  auto-discovered). Runs as contact 1, creates a throwaway project in `setUp`
  and tears everything down in `tearDown`.
- `tests/pmMcp{Project,Task,Wiki,Sprint}ToolsTest.php` — integration tests that
  drive the tools against the live DB.
- `tests/pmMcpDependencyToolTest.php` — integration tests for
  `pm_manage_dependencies`: one stored row per relation, both cards reading it,
  idempotent repeats, refused conflicts, removal from either end.
- Keep `.phpunit.result.cache` out of git (see `.gitignore`).

### Fixture seeder

`tests/seed.php` provisions a known **"MCP Test Project"** on the local install
for manual/exploratory checks (it is **not** part of the PHPUnit suite). Run it
from the htdocs root (or anywhere — it resolves its own path):

```
php wa-apps/mcp/plugins/pm/tests/seed.php
```

- It acts as contact **1** (the install admin) and is **idempotent**: each run
  tears the project down and recreates it in a fixed state.
- It seeds the `management` workflow, an admin member, a `v1.0` milestone,
  `bug`/`feature` tags and seven tasks spanning every status and priority, with
  a subtask, checklist, comment, tags and a time entry. Tasks are created via
  `pmTask::create` (real domain path), not raw inserts.
- **Discover by name, not id.** Reseeding assigns fresh auto-increment ids, so
  resolve the project through `pm_list_projects` (or its name) rather than
  hard-coding ids.
- `tests/` is excluded from the release bundle (see below), so fixtures never
  ship.

## Localization

User-facing strings go through `_wp()` under the `mcp_pm` gettext domain
(`locale/<lang>/LC_MESSAGES/mcp_pm.po`). Re-extract from the **install root**
after changing strings:

```
php wa.php locale mcp/plugins/pm
```

- This (re)writes the `.po` files for every locale. Keep each `_wp()` string on
  a **single line with no tabs** — the extractor mishandles multi-line and
  tabbed strings.
- Translate the new `msgid`s in `locale/ru_RU/LC_MESSAGES/mcp_pm.po`; `en_US`
  can stay empty (gettext falls back to the English source `msgid`).
- Do **not** commit `.mo` files — the release workflow compiles them from the
  `.po` files (see below); they are git-ignored.

## Packaging

`compress-app-plugin.php` builds the distributable `pm.tar.gz`. It honours
`lib/config/exclude.php`, which keeps development-only files
(`README.md`, `CHANGELOG.md`, `AGENTS.md`, tests, CI config, this script, …)
out of the shipped archive. When adding a new kind of dev-only file, exclude
it there too.

## Continuous integration

- `.github/workflows/php-version-check.yml` — lints every PHP file across
  PHP 7.4–8.5 on each push and pull request.
- `.github/workflows/release.yml` — on a `vX.Y.Z` tag, compiles gettext
  `.po → .mo`, runs the packaging script and publishes a GitHub Release whose
  body is the tag's annotation message.

Keep the plugin PHP compatible with **7.4 through 8.5**.
