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
| Sprints | `pm_list_sprints`, `pm_get_sprint` | — backend only; tasks move between sprints via `pm_update_task` |
| Tags | `pm_list_tags` | — backend only; tags are attached to tasks in the pm UI |

When a human asks for a new milestone, sprint or tag, say it has to be created
in the Project Management app — do not improvise it as a task or a wiki page.

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

Sprints are read-only here, but you will need their ids constantly.

- `pm_list_sprints` takes a `project_id` and an optional `status`, and returns
  sprints ordered **active first, then planned, then completed** — so the
  current sprint is normally the first row.
- `pm_get_sprint` returns one sprint's card: `fill_status_ids` (which statuses
  auto-fill draws from) and the `automation` flags `auto_create_next`,
  `auto_close`, `move_unfinished`, `auto_fill`. A sprint can span several
  projects (`project_ids`); access is granted if you are a member of any of
  them.

**The backlog is not a sprint.** It is the absence of one. To list it, call
`pm_list_tasks` with `sprint_id: -1` (tasks with no sprint, open statuses). To
move a task to the backlog, `pm_update_task` with `sprint_id: 0`.

## Milestones and tags

`pm_list_milestones` returns the project's milestones with their status and
dates; `pm_list_tags` its tags with ids and colours. Both are the only place to
get the ids that `pm_create_task` / `pm_update_task` / `pm_list_tasks` accept —
and a milestone or tag from *another* project is always refused.

Related: [`pm-tasks`](skill://pm/pm-tasks) · [`pm-wiki`](skill://pm/pm-wiki)
