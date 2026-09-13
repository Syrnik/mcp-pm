# pm: projects, members, workflows and sprints

Read [`pm-basics`](skill://pm/pm-basics) first. This page covers the container
around tasks: projects, who is in them, the workflows that define statuses, and
milestones/sprints/tags.

## What is writable and what is not

| Entity | Read | Write |
|--------|------|-------|
| Project | `pm_list_projects`, `pm_get_project` | `pm_create_project`, `pm_update_project` |
| Participants | `pm_list_project_users` | `pm_add_project_user`, `pm_remove_project_user` |
| Workflows / statuses | `pm_get_workflow`, `pm_list_statuses` | — configured in the pm backend only |
| Milestones | `pm_list_milestones` | — backend only |
| Sprints | `pm_list_sprints`, `pm_get_sprint` | `pm_create_sprint`, `pm_update_sprint`, `pm_manage_sprint` (activate/complete/delete); tasks move between sprints via `pm_update_task` |
| Tags | `pm_list_tags` | `pm_add_tags`, `pm_remove_tags`, the `tags` field of `pm_create_task` / `pm_update_task` — see [`pm-tasks`](skill://pm/pm-tasks) |

When a human asks for a new milestone, say it has to be created in the
Project Management app — do not improvise it as a task or a wiki page.
Sprints and tags are the exceptions: a tag the project lacks can be created
along the way, but only when the call says so explicitly
(`create_missing_tags`), and sprints are covered below.

## Reading a project

`pm_get_project` returns four things at once, and is usually all the
reconnaissance you need:

- `project` — name, status (`planned`/`active`/`completed`), owner, dates,
  colour/icon, `content_format` (the markup tasks and wiki pages are written
  in), the task-numbering settings (`prefix`, `number_mode`,
  `number_separator`) and `workflow_ids`;
- `participants` — every member with `contact_id`, name and `role`;
- `workflows` — each attached workflow with its statuses (global id, the
  workflow-local `name`, the `global_name`, `is_closed`) and the `transitions`
  matrix;
- `milestones`.

`pm_list_projects` returns the short form and takes an optional
`status` filter. Project ids are per-install — resolve by name.

## Statuses and workflows

Statuses are **global to the installation**; a workflow decides which of them
it uses, what they are called locally, and which transitions are allowed.

- `pm_list_statuses` → every global status. Pass `workflow_id` to also get that
  workflow's local names and transition matrix.
- `pm_get_workflow` → the same for every workflow attached to one project.

A status's `is_closed` flag is what marks a task as finished — there is no
separate "closed" field on a task.

## Creating and updating a project

`pm_create_project` needs only a `name`; everything else has a default:

- the **owner** defaults to the calling contact and is added as a project
  **admin** — always, so the creator can keep working with the project;
- `workflow_ids` defaults to the first configured workflow. An unknown slug is
  refused with `available_workflows`;
- `status` defaults to `planned`;
- `prefix` / `number_mode` (`prefix` or `postfix`) / `number_separator` decide
  how tasks are numbered (`AUTH-32` vs `32-AUTH`). Set the prefix at creation
  if you can — changing it later renumbers how every task is *displayed*;
- `parent_id` makes it a sub-project; omit or `0` for a top-level one.

`pm_update_project` requires **project administrator** rights and is partial —
only the keys you send change. Two shapes to keep straight:

- `description`, `start_date`, `end_date` are cleared with an **empty string**;
- `workflow_ids` is a **replacement set**, not an addition, and must be
  non-empty;
- `owner_contact_id` reassigns ownership — do this before trying to remove the
  current owner from the project.

## Members and roles

Roles are configured per install; the defaults are `admin`, `manager`,
`member`, `viewer`. The role decides every task and wiki permission
(`task.create`, `task.edit`, `task.assign`, `task.change_status`,
`task.delete`, `task.manage_watchers`, `wiki.view`, `wiki.edit`).

`pm_add_project_user` **both adds and re-roles**: calling it for an existing
member changes their role (the response's `action` says `added` or `updated`).
An unknown role slug comes back with `available_roles`. Requires project admin
rights.

`pm_remove_project_user` needs `confirm: true` and project admin rights. The
**project owner cannot be removed** — reassign ownership with
`pm_update_project` first, or you will get `conflict`.

Note the ordering rule that catches agents out: a contact must be a project
participant **before** they can be assigned a task. Add them first, then
assign.

## Sprints and the backlog

A sprint is **not owned by one project** — it can span several
(`pm_sprint_project` is a many-to-many link), each with its own workflow
subset. `project_id` on a sprint row is a deprecated convenience alias for
"the first project *you* can see" — use `project_ids` for the full set, and
never treat `project_id` as authoritative.

- `pm_list_sprints` takes an *optional* `project_id` (omit it to span every
  project you can access — a sprint spanning several is listed once),
  `status`, and `limit`/`offset`. Sprints come back **active first, then
  planned, then completed**, so the current sprint is normally the first row.
- `pm_get_sprint` returns one sprint's card: `project_ids`, the per-project
  `workflows` (`[{project_id, workflow_ids}]` — an empty `workflow_ids` for a
  project means "all of that project's workflows"), `fill_status_ids` (which
  statuses auto-fill draws from), and the `automation` flags
  `auto_create_next`, `auto_close`, `move_unfinished`, `auto_fill`. Access is
  granted if you are a member of *any* linked project; projects you cannot
  access are stripped from `project_ids`/`workflows` (`hidden_project_count`
  says how many). Because of that stripping, **never round-trip a read
  response as a write request** — see below.

### Creating and updating

`pm_create_sprint` needs `project_ids` (non-empty) and `name`; it always
starts `planned`. `workflows` and `fill_status_ids` are optional — omit
`workflows` for a project and it gets every workflow attached to that
project; a `fill_status_id` must belong to the (selected-or-default)
workflows of at least one listed project, or the call is refused with
`available_fill_status_ids`.

`pm_update_sprint` is partial, but with a trap that does not exist on other
`pm_update_*` tools: `project_ids`, `workflows` and `fill_status_ids` are each
a **replacement set for the key you pass** — pm stores each as
delete-all-then-reinsert, so an update tool has to re-supply what it isn't
changing. The rule that keeps this safe: **omit a key entirely to leave it
untouched; only pass it when you mean to replace the whole set.** Concretely:

- Renaming a sprint → `{sprint_id, name}`. Nothing else in the call.
- Adding a project to a two-project sprint → read the card first, then pass
  `project_ids` as the **old set plus the new id**, not just the new id.
- Same for `workflows`: to add a workflow for one project without touching
  another project's selection, read the card's `workflows`, add the one
  entry, and pass the whole list back.
- A project you cannot access is *never* dropped by this tool, even if your
  `project_ids` omits it — you cannot use a partial view to detach a sprint
  from a project you have no role in.
- `status` is **not** a field of this tool. Activating, completing or
  deleting a sprint goes through `pm_manage_sprint` — flipping status directly
  would skip auto-fill, the completed-sprint rename, and move-unfinished.
- Shrinking `project_ids` does not unlink that project's tasks from the
  sprint — they stay attached but drop off the sprint board, since the board
  is filtered by project. The response's `warnings` flags this
  (`tasks_hidden_from_board`) with the affected task count; re-add the
  project or move the tasks with `pm_update_task` (`sprint_id: 0`) instead of
  ignoring the warning.

### Lifecycle: activate, complete, delete

`pm_manage_sprint` takes `sprint_id` and `action` (`activate`/`complete`/`delete`):

- **activate** — only from `planned`; a completed or already-active sprint is
  refused with `conflict`. When the sprint's `auto_fill` is on, it also pulls
  matching backlog tasks in (capped at 500 — check `filled_task_count`
  against that cap before assuming everything eligible was pulled in).
- **complete** — only from `active`; renames the sprint with its date range.
  `auto_create_next` copies its projects/workflows/settings into a new
  `planned` (or already-`active`, if the dates say so) sprint, returned as
  `new_sprint`. `move_unfinished` moves open tasks into that new sprint —
  **or into the backlog if `auto_create_next` is off** (`moved_unfinished.target`
  says which; a bare `target_sprint_id: null` means backlog).
- **delete** — needs `confirm: true`. Removes the sprint and **detaches its
  tasks** (`sprint_id` cleared) — it does not delete them.

**The backlog is not a sprint.** It is the absence of one. To list it, call
`pm_list_tasks` with `sprint_id: -1` (tasks with no sprint, open statuses). To
move a task to the backlog, `pm_update_task` with `sprint_id: 0`.

## Milestones and tags

`pm_list_milestones` returns the project's milestones with their status and
dates; `pm_list_tags` its tags with ids and colours. A milestone or tag from
*another* project is always refused: both are project-scoped, and two projects
that both have a "bug" tag have two unrelated tags.

A milestone is referenced by **id**, so `pm_list_milestones` is the only place
to get one. Tags are the opposite — every tool that writes them takes **names**
(see [`pm-tasks`](skill://pm/pm-tasks)), so `pm_list_tags` is worth a call only
to see what a project already uses, or to turn a name into the `tag_id` that
`pm_list_tasks` filters on.

Related: [`pm-tasks`](skill://pm/pm-tasks) · [`pm-wiki`](skill://pm/pm-wiki)
