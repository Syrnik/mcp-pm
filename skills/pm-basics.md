# pm: conventions every tool shares

The `pm_*` tools expose the Webasyst **Project Management** app. This page
covers what is true of *all* of them; the per-area pages
(`pm-tasks`, `pm-projects`, `pm-wiki`) cover the individual tools.

## Who you are acting as

Every call runs as the contact the MCP token was issued for (`act_as`). That
contact's **project membership and role** decide what you can see and change —
not the token alone. Two gates apply in order:

1. **Token rights.** One right per tool, named exactly like the tool
   (`pm_list_tasks`, `pm_create_task`, …). A missing right means the tool is
   not in `tools/list` at all.
2. **pm's own permissions.** The project role of the `act_as` contact
   (`admin`, `manager`, `member`, `viewer` by default) and its per-permission
   flags — `task.create`, `task.edit`, `task.assign`, `task.change_status`,
   `task.delete`, `task.manage_watchers`, `wiki.view`, `wiki.edit`.

So a granted right is permission to *try*; the answer can still be
`access_denied`. A token with `act_as = 0` (no user) is rejected by every tool.

## The response envelope

Every tool returns a flat JSON object with an `ok` flag:

```json
{"ok": true,  "task": { … }}
{"ok": false, "error_code": "not_found", "error_message": "Task not found."}
```

`ok: false` is a **normal answer, not a transport error** — read it and react.
The codes you will meet:

| `error_code`       | Means                                                        | What to do |
|--------------------|--------------------------------------------------------------|------------|
| `invalid_param`    | An argument is missing, malformed or points somewhere else    | Read the extra keys — a mismatch often ships the valid options (`available_workflows`, `available_milestones`, `available_sprints`, `available_participants`, `available_roles`) |
| `not_found`        | The project/task/page/sprint does not exist, or is invisible to you | Re-discover the id; do not retry the same one |
| `access_denied`    | Your project role lacks the permission, or you are not a member | Stop; ask a human. Retrying will not help |
| `confirm_required` | A destructive tool was called without `confirm: true`         | Confirm with the user, then repeat with `confirm: true` |
| `conflict`         | The change contradicts existing state (e.g. a competing task relation, removing the project owner) | Resolve the stated conflict first |
| `db_error`, `app_error`, `internal_error`, `api_error` | Something failed below the tool | Do not retry blindly; report it |

A failing call **changes nothing** — the tools do not half-apply a write.

## Discover ids, never guess them

Project, milestone, sprint, tag, status and wiki-page ids are per-installation
auto-increment values. They differ between installs and change when data is
reseeded. Always resolve by name through a `list_*` tool first:

```
pm_list_projects            → project_id
pm_get_project              → participants, workflows, milestones in one call
pm_list_statuses            → status ids (+ a workflow's transition matrix)
pm_list_tags / pm_list_milestones / pm_list_sprints
```

The one exception is **tags on a task**: those are written by name, not by id
(see [`pm-tasks`](skill://pm/pm-tasks)). `pm_list_tags` is still how you learn
what a project uses, and how you get the `tag_id` that `pm_list_tasks` filters
on.

`pm_get_project` is the cheapest way to warm up: it returns the project card,
its participants with roles, every attached workflow with its statuses and
transitions, and the milestones — enough to create or move a task without
further reads.

## Tasks are quoted by number, not by id

pm shows a task as its project's number — `AUTH-32` for a project with prefix
`AUTH`, `32-AUTH` in postfix mode, `#32` for a project with no prefix — and
that is the form people quote at you. Every argument naming a task (`task_id`,
`parent_id`, `related_task_id`) accepts either shape:

```
32      AUTH-32      auth 32      AUTH32      32-AUTH      #32
```

Case, spaces and punctuation are ignored. A number whose prefix belongs to a
*different* project is refused with `not_found` rather than silently returning
that project's task of the same id.

Every task in a response carries its number back as `full_number`, in listings,
cards and subtasks alike — quote that when you report to a human.

## `0` clears a reference; omitting it leaves it alone

For the nullable references — `assignee_contact_id`, `milestone_id`,
`sprint_id`, `parent_id` (and `parent_id` on projects and wiki pages) — the
convention is the same in create and update tools:

- **omit the key** → leave the field as it is (create: leave it empty),
- **pass `0`** → explicitly clear it (unassign / no milestone / backlog /
  top-level).

Nullable *text* fields (`description`, `start_date`, `end_date`, `due_date`,
`deadline`) are cleared with an **empty string**, not with `0`.

Update tools are partial: a key you do not send is not touched.

## Destructive tools need `confirm: true`

`pm_delete_task` and `pm_remove_project_user` refuse to run without
`confirm: true` and answer `confirm_required`. That guard exists so the
confirmation is a deliberate act — get the human's agreement first, then send
it. `pm_delete_task` also removes the task's subtasks, comments, tags and
custom fields, and cannot be undone.

## Dates, text and formatting

- All dates are `YYYY-MM-DD` strings. Timestamps come back as
  `YYYY-MM-DD HH:MM:SS` in the install's timezone.
- Descriptions and wiki content are stored in the project's `content_format`
  (`md` by default — see `content_format` on the project card and on
  `pm_list_wiki_pages`). Write **Markdown** unless the project says otherwise;
  the app renders it, so do not pre-render HTML.
- `pm_create_project` / `pm_update_project` strip HTML tags from the project
  name (nothing to do with task tags).

## A safe working order

1. `pm_list_projects` → pick the project by name.
2. `pm_get_project` → ids for statuses, workflows, milestones, participants.
3. `pm_list_tasks` → find the tasks you were asked about.
4. `pm_get_task` → the full card, including `allowed_statuses` (what you may
   move it to) before you change anything.
5. Write with the narrow tool for the job — see `pm-tasks`.

Related: [`pm-tasks`](skill://pm/pm-tasks) ·
[`pm-projects`](skill://pm/pm-projects) · [`pm-wiki`](skill://pm/pm-wiki)
