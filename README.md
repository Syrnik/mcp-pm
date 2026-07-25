# Project Management MCP Plugin

MCP tools for the Webasyst **Project Management** app (`wa-apps/pm`). Lets LLM
agents work with projects, tasks, assignees, sprints, milestones and the
project wiki through the MCP JSON-RPC protocol.

> **Status: feature-complete.** All 28 tools across the Read, Tasks, Projects
> and Wiki right groups are implemented, plus the PHPUnit suite and ru_RU/en_US
> localization (stages #78.1 – #78.8 of the "MCP for PM" project).

## Architecture

- `lib/config/plugin.php` — plugin metadata; binds `mcp_tool_registry_v1`
  (`registerTools`) and `mcp_plugin_rights_v1` (`registerRights`).
- `lib/config/requirements.php` — requires `app.mcp >= 1.0.1` and
  `app.pm >= 0.25.0`.
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

## Tool groups (planned, 28 tools)

| Group    | Right group   | Tools |
|----------|---------------|-------|
| Read     | `pm.read`     | list_projects, get_project, list_statuses, get_workflow, list_project_users, list_tags, list_milestones |
| Tasks    | `pm.tasks`    | list_tasks, get_task, list_task_comments, create_task, update_task, move_task, assign_task, add_task_comment, manage_checklist, manage_watchers, delete_task |
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

## Dependencies

- Webasyst MCP app (`app.mcp` >= 1.0.1)
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
