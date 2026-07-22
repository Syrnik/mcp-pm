# Changelog

All notable changes to the **pm MCP plugin** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-07-22

### Added
- Stage 7 — tests, localization, release prep (Task #78.8). PHPUnit 9 suite
  (`phpunit.xml`, `tests/init.php`): a smoke test (the whole 28-tool surface
  registers, every tool has a matching right, schemas are well-formed),
  DB-independent unit tests (argument coercers, schema validation, wiki access
  roles and page-visibility logic) and live-DB integration tests for the
  project, task, wiki and sprint tools (each runs as the install admin in a
  throwaway project that is torn down afterwards). The bootstrap boots `pm`
  before `mcp` so pm stays the current app for its static-cached config lookups
  (`pmWorkflow`/`pmRoleModel`) without the argv-clobbering side effects of
  `wa('pm', 1)`/`waSystem::setActive()` under the PHPUnit CLI. Full `mcp_pm`
  localization: `locale/ru_RU` (139 Russian strings) and `locale/en_US`
  generated via `php wa.php locale mcp/plugins/pm`; `.mo` files are compiled by
  the release GitHub Action, not committed. README expanded with tests,
  localization and token-setup sections. Verified end to end: with the plugin
  enabled, `mcpToolRegistry::createDefault()` returns all 28 tools through the
  `mcp_tool_registry_v1` event.
- Stage 6 — sprint read tools (Task #78.7): `pm_list_sprints`, `pm_get_sprint`.
  Read-only, registered under the `pm.read` group. Sprints are **per-project**:
  the pm schema still carries the `pm_sprint_project` M:N link and a matching
  model from an abandoned cross-project design (a note on the task confirmed the
  developer dropped it; leftover `$sprint['project_id']` references in
  pmSprints.actions delete/complete are artifacts of that half-migration), so
  the tools read through `pmSprintModel::getByProject()`/`getById()` but treat a
  sprint as belonging to a single project. Both tools gate on project
  membership. The card expands the linked project(s), the auto-fill source
  statuses (with names) and the automation flags (auto-create-next, auto-close,
  move-unfinished, auto-fill); listing is ordered active → planned → completed
  like the pm UI. Moving a task in/out of a sprint stays with `pm_update_task`
  (`sprint_id`, null = backlog) — no separate write tool. New helper class
  `pmMcpSprintHelper`.
- Stage 5 — wiki tools (Task #78.6): `pm_list_wiki_pages`, `pm_get_wiki_page`,
  `pm_create_wiki_page`, `pm_update_wiki_page`. The wiki has no domain class, so
  these mirror `pmWikiActions` and write via `pmWikiPageModel` directly. Access
  is two-level: the `wiki.view`/`wiki.edit` permission gates the project, and
  `pmMcpWikiHelper::canUserSeePage` (a faithful port of the controller's
  visibility rule) filters each page — unpublished pages show only to their
  author and to admins/managers; published pages restricted by `access_roles`
  only to those roles. Sections never store content; `pm_update_wiki_page`
  supports re-parenting with a self/descendant cycle guard (`isSafeParent`) and
  `parent_id=0` to move a page to the top level. `access_roles` accepts a slug
  list and is validated against `pmRoleModel`. The tree listing returns
  `children` as a map keyed by parent id (`"0"` = top level). New helper class
  `pmMcpWikiHelper` and an `argBool()` helper on the tool base. Rights
  registered under a new `pm.wiki` group.
- Stage 4 — write tools for projects (Task #78.5): `pm_create_project`,
  `pm_update_project`, `pm_add_project_user`, `pm_remove_project_user`. Projects
  have no `pmTask`-style domain class, so these mirror `pmProjectsSaveController`
  and write via `pmProjectModel` / `pmProjectUserModel` directly. Access:
  `pm_create_project` requires pm app access (the owner, defaulting to the
  caller, is added as project admin); the other three require the caller to be a
  project administrator. `pm_add_project_user` is upsert-style (adds, or changes
  an existing member's role); `pm_remove_project_user` requires `confirm: true`
  and refuses to remove the project owner. Workflow attachments are validated
  against the configured workflow slugs and roles against `pmRoleModel`. New
  helpers on `pmMcpProjectHelper` (`fullCard`, `requireAppAccess`,
  `requireProjectAdmin`). Rights registered under a new `pm.projects` group.
- Stage 3 — write tools for tasks (Task #78.4): `pm_create_task`,
  `pm_update_task`, `pm_move_task`, `pm_assign_task`, `pm_add_task_comment`,
  `pm_manage_checklist`, `pm_manage_watchers`, `pm_delete_task`. All mutations go
  through the `pmTask` domain class (create/save/moveToStatus/addComment/
  addParticipant/deleteWithCleanup), so permission checks, reference validation,
  workflow transitions, activity logging and notifications are honoured. Nullable
  references (assignee/milestone/sprint/parent) accept `0` to clear;
  `pm_delete_task` requires explicit `confirm: true`. Write helpers added to
  `pmMcpTaskHelper` (`requireProjectRole`, `loadTaskEntity`, `cardById`).
  Comment gating fixes the pm REST quirk where the immutable-role `readonly`
  flag also blocks admins. Rights registered under a new `pm.tasks` group.
- Stage 2 — read tools for tasks (Task #78.3): `pm_list_tasks`, `pm_get_task`,
  `pm_list_task_comments`. `pm_list_tasks` reuses `pmTaskModel::getByStatus()`
  (project/status/assignee/sprint/milestone/type/priority/tag/search filters,
  `-1` sentinels for unassigned/backlog/no-milestone) and adds offset/limit
  pagination over one project or every project the user can access. `pm_get_task`
  returns the full card: tags, subtasks, milestone, participants, checklist,
  dependencies, custom fields, logged time and the workflow transitions
  available to the caller. New shared helper `pmMcpTaskHelper` (project-set
  resolution, task access check, row/card serialisation). Rights registered
  under the `pm.read` group.
- Stage 1 — read tools for projects and reference data (Task #78.2):
  `pm_list_projects`, `pm_get_project`, `pm_list_statuses`, `pm_get_workflow`,
  `pm_list_project_users`, `pm_list_tags`, `pm_list_milestones`. All results
  are filtered by project membership (pm app-admins see every project). Shared
  helpers `pmMcpProjectHelper` (access + serialisation) and
  `pmMcpWorkflowHelper` (statuses + transition matrix). Rights registered
  under the `pm.read` group.

## [0.1.0] - 2026-07-22

### Added
- Stage 0 scaffold (Task #78.1):
  - Plugin bootstrap `mcpPmPlugin` (`lib/mcpPm.plugin.php`) with eager class
    loading and `wa('pm')` boot.
  - Shared tool base class `pmMcpToolBase` (`lib/classes/pmMcpToolBase.php`):
    `ok()`/`softFail()` envelope, schema validation via `mcpSchemaValidator`,
    `assertConfirm()` for destructive operations, and `safeExecute()` with
    user restoration and anonymous-caller (`act_as = 0`) rejection.
  - Config: `lib/config/plugin.php` (event handlers) and
    `lib/config/requirements.php` (`app.mcp >= 1.0.1`, `app.pm >= 0.25.0`).
  - Plugin icons, `README.md` and protective `.htaccess` files.
- Packaging: standalone `compress-app-plugin.php` and
  `lib/config/exclude.php` (keeps dev-only files out of the shipped archive).
- CI: GitHub Actions for PHP 7.4–8.5 compatibility checks and tag-triggered
  releases.
- `AGENTS.md` contributor guide (Keep a Changelog, Conventional Commits,
  task-reference conventions).

