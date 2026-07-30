# Project Management MCP Plugin

MCP tools for the Webasyst **Project Management** app (`wa-apps/pm`). Lets LLM
agents work with projects, tasks, assignees, sprints, milestones and the
project wiki through the MCP JSON-RPC protocol.

> **Status: feature-complete.** All 28 tools across the Read, Tasks, Projects
> and Wiki right groups are implemented, plus the PHPUnit suite and ru_RU/en_US
> localization (stages #78.1 – #78.8 of the "MCP for PM" project).

## Architecture

- `lib/config/plugin.php` — plugin metadata; binds `mcp_tool_registry_v1`
  (`registerTools`), `mcp_plugin_rights_v1` (`registerRights`) and
  `mcp_skill_registry_v1` (`registerSkills`).
- `lib/config/requirements.php` — requires `app.mcp >= 1.2.0` (the release that
  introduced the skill registry) and `app.pm >= 0.25.0`.
- `skills/*.md` — the agent-facing documentation published through the skill
  registry (see [Skills](#skills)).
- `lib/mcpPm.plugin.php` — `mcpPmPlugin extends mcpPlugin`. Eager-loads all
  `lib/classes` and `lib/tools` classes in the constructor (the MCP app does
  not warm the class index before `registerTools()`), then boots `wa('pm')`.
- `lib/classes/pmMcpToolBase.php` — abstract base for every tool: the
  `ok()`/`softFail()` response envelope, schema validation via
  `mcpSchemaValidator`, `assertConfirm()` for destructive operations, and
  `safeExecute()` which switches the active app to `pm`, restores the
  authenticated user after `wa('pm', 1)`, and maps exceptions onto the
  envelope. Anonymous callers (token `act_as = 0`) are rejected with
  `access_denied` in every tool.

## Tool groups (planned, 29 tools)

| Group    | Right group   | Tools |
|----------|---------------|-------|
| Read     | `pm.read`     | list_projects, get_project, list_statuses, get_workflow, list_project_users, list_tags, list_milestones |
| Tasks    | `pm.tasks`    | list_tasks, get_task, list_task_comments, create_task, update_task, move_task, assign_task, add_task_comment, manage_checklist, manage_watchers, manage_dependencies, delete_task |
| Projects | `pm.projects` | create_project, update_project, add_project_user, remove_project_user |
| Wiki     | `pm.wiki`     | list_wiki_pages, get_wiki_page, create_wiki_page, update_wiki_page |
| Sprints  | `pm.read`     | list_sprints, get_sprint |

The tool name equals its right name (helpdesk convention): a token scope
lists tool names directly.

## Task references

pm shows a task as its project's number — `AUTH-32` for a project with prefix
`AUTH`, `32-AUTH` in postfix mode, `#32` for a project without a prefix — and
that is the form people quote. Every argument naming a task (`task_id`,
`parent_id`) therefore accepts either shape:

```
32            AUTH-32            auth 32            AUTH32            32-AUTH
```

Case, spaces and punctuation are ignored. A reference whose prefix belongs to
another project is rejected with `not_found` rather than silently returning
that project's task of the same id — the error names the task's real number.
`pm_list_tasks` treats a `search` term that reads as a reference the same way,
so `search: "AUTH-32"` returns that task as well as any subject matches.

Every serialised task carries its number back as `full_number`, in listings,
task cards and subtasks alike.

## Task relations

pm stores a relation between two tasks as a **single** `pm_task_dependency` row
and reads it from both ends: the record "A depends on B" is `depends_on` on A's
card and `blocks` on B's; the symmetric kinds (`duplicates`, `relates_to`) show
up under `related` whichever side you look from. A relation is therefore mutual
by construction — a mirrored second row would only duplicate it in both cards.

`pm_manage_dependencies` states the relation from the point of view of the task
in `task_id` and writes the one row that stores it:

| `relation`     | Meaning                                     | Stored row                      | The other task sees |
|----------------|---------------------------------------------|---------------------------------|---------------------|
| `depends_on`   | `task_id` waits for `related_task_id`       | `task_id` → `related_task_id`   | `blocks`            |
| `blocks`       | `related_task_id` waits for `task_id`       | `related_task_id` → `task_id`   | `depends_on`        |
| `duplicates`   | the tasks are duplicates (`DUPLICATES`)     | either direction                | `duplicates`        |
| `relates_to`   | loosely related (`RELATES_TO`)              | either direction                | `relates_to`        |

Directional relations carry a scheduling `type` — `FS` (default), `SS`, `FF` or
`SF`. One relation per pair: repeating an identical request is a no-op
(`already_exists`), and a different relation between the same tasks is refused
with `conflict` until the existing one is removed. Both ends' relations come
back in the response (`dependencies`, `related_dependencies`), and each entry in
a task card names the other task as `related_task_id` /
`related_full_number` / `related_project_id` with its `relation` and
`inverse_relation`.

## Skills

A tool schema can say what an argument is; it cannot say that a status change
goes through `pm_move_task` rather than `pm_update_task`, that a relation
between two tasks is a single row read from both ends, or that a wiki page
created without `published` is a draft nobody else sees. Those are the things an
agent otherwise learns by getting them wrong, so the plugin ships them as
**skills** — markdown documents published through the MCP app's skill registry
(`mcp_skill_registry_v1`, added in mcp 1.2.0).

| Skill id | File | Covers |
|----------|------|--------|
| `pm-basics` | `skills/pm-basics.md` | The `ok`/error envelope and its error codes, the two permission layers, the `AUTH-32` reference syntax, the "`0` clears a reference" rule, a safe working order |
| `pm-tasks` | `skills/pm-tasks.md` | List filters and their `-1` sentinels, creating tasks, why status and assignee have their own tools, comments, checklist, watchers, relations, deletion |
| `pm-projects` | `skills/pm-projects.md` | What is writable vs backend-only, statuses and workflow transitions, roles and ownership, sprints and the backlog |
| `pm-wiki` | `skills/pm-wiki.md` | Sections vs articles, the two access gates and per-page visibility, publication flags, moves |

The split mirrors the right groups, so a token holding only `pm.read` has no
reason to fetch the wiki page. Clients reach them three ways:

- MCP `resources/list` and `resources/read`, URI `skill://pm/<skill_id>`;
- `GET /mcp/skill/pm/<skill_id>.md` (plain HTTP, no auth — skills are
  documentation, not secrets);
- the mcp backend's Skills page.

`registerSkills()` in `lib/mcpPm.plugin.php` is the single source of truth for
which files are published; `tests/pmMcpSkillsTest.php` fails if a declared file
is missing or a file in `skills/` is left unregistered.

One wrinkle worth knowing: the declared paths are built with
`DIRECTORY_SEPARATOR` rather than the forward slash `mcpPlugin`'s docblock
shows, because `mcpSkillRegistry` compares the path against
`'skills' . DIRECTORY_SEPARATOR` and drops a non-matching one — no exception,
just an empty `resources/list` and a `rejected unsafe path` line in
`wa-log/mcp.log`. The two spellings are the same on POSIX; on Windows only the
latter survives.

## Dependencies

- Webasyst MCP app (`app.mcp` >= 1.2.0)
- Webasyst Project Management app (`app.pm` >= 0.25.0)

## Activation

Enable the plugin in `wa-config/apps/mcp/plugins.php` (site config, outside this
repo):

```php
return array('pm' => true);
```

Then issue an MCP token in the mcp app and grant it the `pm` tool rights it
needs (the right name equals the tool name, e.g. `pm_list_tasks`,
`pm_create_task`). The token's `act_as` contact is the user every tool acts as;
project membership and per-role permissions are enforced against that contact.

## Localization

Localized via the `mcp_pm` gettext domain (`ru_RU`, `en_US`); every user-facing
string goes through `_wp()`. See [AGENTS.md](AGENTS.md) for how to re-extract
strings and how `.mo` files are built.

## Contributing

Tests, string extraction, packaging and release are documented in
[AGENTS.md](AGENTS.md).
