# Project Management MCP Plugin

MCP tools for the Webasyst **Project Management** app (`wa-apps/pm`). Lets LLM
agents work with projects, tasks, assignees, sprints, milestones and the
project wiki through the MCP JSON-RPC protocol.

> **Status: Stage 0 — scaffold.** This commit ships the plugin skeleton only
> (bootstrap, shared tool base class, packaging). Tools are added across the
> stages tracked in the "MCP for PM" project (#78.1 – #78.8).

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

## Dependencies

- Webasyst MCP app (`app.mcp` >= 1.0.1)
- Webasyst Project Management app (`app.pm` >= 0.25.0)

## Activation

Enable the plugin in `wa-config/apps/mcp/plugins.php`:

```php
return array('pm' => true);
```
