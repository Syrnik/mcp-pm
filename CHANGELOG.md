# Changelog

All notable changes to the **pm MCP plugin** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **`pm_update_task_comment`** (Task PMCP-395). There was no way to fix a typo
  or correct a comment once posted — `pm_add_task_comment` only appends, and
  the only edit path lived in the backend's own JSON controller, never exposed
  over MCP. The new tool mirrors that controller's `editAction()` exactly:
  `text` can only be changed by the comment's own author, with no override for
  project admins/managers or app-admins — matching what was actually requested
  rather than the looser rule `pm_delete_task_comment` would use if it existed.
  `comment_id` must belong to the `task_id` given, so a stale or foreign id is
  refused with `not_found` rather than silently rewriting a comment on the
  wrong task.

## [1.3.0] - 2026-08-02

### Added
- **Tags can be written, not just read** (Task PMCP-366). Until now the pm
  domain could list a project's tags, show them on a task card and filter by
  them — but nothing could put one on a task, so an agent had to hand the job
  back to a human and ask them to open the interface. Four ways in, each with
  its own semantics: `tags` on `pm_create_task` (the new task starts with
  exactly these), the new `pm_add_tags` and `pm_remove_tags` (change one tag,
  leave the rest alone), and `tags` on `pm_update_task`, which replaces the
  whole set the way every other field of that tool replaces its value — an
  empty array clears it.

  All four take tag **names**, not ids: an agent knows the word it wants and
  almost never the number behind it. Matching is case-insensitive and happens
  against the project's tag list rather than through an SQL lookup, so it does
  not depend on the column's collation and resolves the same way on every
  install. Tags are project-scoped, so a name is only ever looked for in the
  task's own project.

  A name the project does not have is **refused** by default, with
  `missing_tags` and the project's own `available_tags` attached — a typo costs
  one corrected call, not a stray tag nobody notices. Passing
  `create_missing_tags: true` creates it instead; that is the deliberate switch
  for the case the strict default cannot serve, a project whose tag list is
  still empty. The names are resolved *before* anything is written, so a
  rejected tag leaves neither a half-made task nor a subject renamed by the
  same call. `pm_remove_tags` is deliberately lenient in the other direction: a
  tag the task does not carry is reported in `not_on_task` rather than raised,
  and the tag itself stays in the project — this unlinks, it does not delete.

  Writes are gated on `task.edit` (`task.create` for tags passed at creation),
  matching where the pm interface puts its own gate, and every change is
  written to the task history in the same shape the interface writes, so the
  activity log reads identically whoever made the change.

### Fixed
- The store description's per-group tool counts were the wrong way round —
  "read 9 / tasks 12" against right groups that actually split 12 / 9. The
  page sells a token's scope as something you can read straight off the tool
  list, so the figures had to match it. Store copy only; the shipped plugin
  was never affected.

## [1.2.0] - 2026-07-30

### Added
- The plugin now publishes **skills** — agent-facing markdown documentation
  served through the MCP app's skill registry (Task PMCP-234). Until now an
  agent had only the tool schemas to go on, and the schemas cannot say that a
  status change goes through `pm_move_task` rather than `pm_update_task`, that
  a relation between two tasks is one row read from both ends, that a
  milestone from another project comes back with the project's own options
  attached, or that a wiki page created without `published` is a draft nobody
  else sees. Four documents, split along the plugin's right groups so a
  read-only token has no reason to fetch the wiki page: `pm-basics` (the
  `ok`/error envelope and its codes, the two permission layers, the `AUTH-32`
  reference syntax, the "`0` clears a reference" rule), `pm-tasks` (list
  filters and their `-1` sentinels, creating and changing tasks, comments,
  checklist, watchers, relations, deletion), `pm-projects` (what is writable
  versus backend-only, statuses and workflow transitions, roles and ownership,
  sprints and the backlog) and `pm-wiki` (sections versus articles, the two
  access gates and per-page visibility, publication flags, moves). Clients
  reach them via MCP `resources/list` / `resources/read`
  (`skill://pm/<skill_id>`), via `GET /mcp/skill/pm/<skill_id>.md`, or from the
  mcp backend's Skills page.

  The declared paths are built with `DIRECTORY_SEPARATOR` rather than the
  forward slash `mcpPlugin`'s docblock shows: `mcpSkillRegistry` gates the path
  against `'skills' . DIRECTORY_SEPARATOR` and drops a non-matching one without
  raising anything, so on Windows the documented spelling yields an empty
  `resources/list`; the rejection is logged to `wa-log/mcp.log` as
  `rejected unsafe path`, which reads like a traversal attempt rather than a
  separator mismatch. The two forms are identical on POSIX. A test runs the
  registry's own resolver so the discrepancy cannot come back unnoticed.

### Changed
- Requires the mcp app **1.2.0** or newer, up from 1.0.1. The skill registry
  the plugin now registers with was introduced in that release; on an older mcp
  the plugin would declare documentation the core has no way to serve.

### Fixed
- The release bundle ships the compiled gettext catalogues again — in practice,
  for the first time. `.gitignore` hid `locale/**/*.mo` because the catalogues
  are built in CI and never committed, but `compress-app-plugin.php` merges
  `.gitignore` into its own exclude list, so the rule also stripped the files
  `msgfmt` had just produced. Every release up to this one therefore shipped
  `.po` sources only, and the Russian translations never reached an
  installation. Nothing generates a `.mo` on a development machine, so the rule
  was buying no quiet in `git status` — only a broken package. Dropped.

## [1.1.1] - 2026-07-30

### Fixed
- `pm_create_task` no longer reads as if a milestone were mandatory (Task #320).
  `milestone_id`, `sprint_id` and `assignee_contact_id` are optional, but the
  schema declared `minimum: 1` on all three — the opposite of `pm_update_task`,
  where 0 has always meant "clear this field". An agent that guessed a milestone
  id, was told it "does not belong to this project", and then retried with 0 —
  the natural way to spell "none" — got `Argument "milestone_id" must be >= 1`
  and concluded, reasonably, that some non-zero milestone was required. All
  three now accept 0 as "leave empty", matching `pm_update_task`, and the tool
  description says so. The same `minimum: 1` on the optional `parent_id` of
  `pm_create_project` and `pm_create_wiki_page` is relaxed for the same reason.
- A milestone, sprint or assignee that belongs to another project is now
  reported with the project's own options — `available_milestones`,
  `available_sprints`, `available_participants` — and all mismatches come back
  in one answer instead of one per call. Previously `pmTask::create()` rejected
  the first offending reference with a bare "Milestone does not belong to this
  project", which told the caller neither what to pass instead nor that a
  project with no milestones at all reads exactly the same as a wrong id;
  fixing one reference only surfaced the next. `pm_update_task` gets the same
  treatment.

## [1.1.0] - 2026-07-26

### Added
- `pm_manage_dependencies` — link and unlink tasks (Task #78.13). Until now the
  plugin could only *read* relations, so an agent asked to link two tasks had
  nothing to call and fell back to a mention in one task's description: a
  one-sided link the other task never learned about. The tool states the
  relation from `task_id`'s point of view — `depends_on`, `blocks`, `duplicates`,
  `relates_to`, with a scheduling `type` (`FS`/`SS`/`FF`/`SF`) for the
  directional ones — and writes the single `pm_task_dependency` row that pm reads
  from both ends, picking the direction the relation implies: "A blocks B" is
  stored on B, so A's card shows `blocks` and B's shows `depends_on`. Relations
  are mutual by construction; no mirrored row is created (it would duplicate the
  relation in both cards). One relation per pair: an identical request is
  idempotent (`already_exists`), a competing one — including the reverse
  dependency, which would make the pair block itself — is refused with
  `conflict`. Removal takes either the other task or a `dependency_id`, works
  from either end, and refuses an id that does not involve the named task. Both
  tasks' relations come back in the response, and the row's `task.edit`
  permission is enforced (the app's own AJAX endpoint checks nothing).

- Task arguments accept the full task number, not just the id (Task #78.11).
  `task_id` (every task tool) and `parent_id` (`pm_create_task`,
  `pm_update_task`) now resolve `AUTH-32`, `auth 32`, `AUTH32`, `32-AUTH`
  (postfix projects) and `#32` as readily as `32`: case, spaces and punctuation
  are ignored, and a prefix containing digits is read against the projects'
  actual prefixes rather than guessed. An agent told "look at AUTH-32" no longer
  has to hunt for the numeric id first.
- Every serialised task carries `full_number` — the number as pm displays it
  (`AUTH-32`, `32-AUTH`, or `#32` for a project with no prefix) — in listings,
  task cards and subtasks, so the id an agent reads back is the one a user
  recognises. `pm_delete_task` and `pm_list_task_comments` echo it too.
- `pm_list_tasks` resolves a `search` term that reads as a task reference,
  returning that task alongside the subject matches.

### Changed
- Relations in `pm_get_task` are annotated for agents: every entry now names the
  other task under one predictable key (`related_task_id`,
  `related_full_number`, `related_project_id`) and states the `relation` and the
  `inverse_relation` the counterpart sees. Previously the counterpart hid behind
  a different key per section (`depends_on_task_id`, `task_id`,
  `related_task_id`) and carried a bare id with nothing to quote back.
- A task reference whose prefix names another project is refused with
  `not_found` instead of returning that project's task of the same id; the
  error message states the task's real number.

### Fixed
- `pm_move_task` records the status change in the task's history (Task #78.14).
  A move made through the tool left the task in the new status but no trace of
  who moved it, or from where: the app's own status change goes through
  `pmTask::save()`, which writes the `status_changed` activity entry, while the
  `pmTask::moveToStatus()` path the tool uses only updates the row and notifies.
  The entry now appears with the same from/to status names the app renders, so
  agent-made moves read like any other in the task history and the project
  activity feed. Should a future pm version log the move itself, the tool
  detects the entry and does not duplicate it.

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

