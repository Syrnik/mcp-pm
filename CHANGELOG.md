# Changelog

All notable changes to the **pm MCP plugin** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
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

[Unreleased]: https://keepachangelog.com/en/1.1.0/
