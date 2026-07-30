# pm: tasks, statuses and relations

Read [`pm-basics`](skill://pm/pm-basics) first — the response envelope, the
`AUTH-32` task reference syntax and the "`0` clears a reference" rule are
assumed here.

## Finding tasks

`pm_list_tasks` is the one search entry point. Every filter is optional; with
none, it spans every project the caller can access.

```json
{"project_id": 9, "assignee_contact_id": 6774, "priority": "high", "limit": 50}
```

| Argument | Notes |
|----------|-------|
| `project_id` | Omit to search across all accessible projects |
| `status_id` | A **global** status id — see `pm_list_statuses` |
| `assignee_contact_id` | `-1` = **unassigned** |
| `sprint_id` | `-1` = **the backlog** (no sprint, open statuses only) |
| `milestone_id` | `-1` = **no milestone** |
| `type_slug`, `priority`, `tag_id` | Slugs and ids from the project's own config |
| `search` | Substring of the subject — **and** a task reference: `search: "AUTH-32"` returns that exact task on top of any subject matches |
| `limit`, `offset` | Default 50, max 200. Paginate with `offset`; `total` tells you how many matched |

Results come back in the pm board's own order (`sort`, then `id`), as compact
rows — not full cards. `count` is the size of this page, `total` the size of
the whole result set.

## Reading a task

`pm_get_task` takes an id or a full number and returns everything on the card:
description, tags, subtasks, milestone, sprint, participants (assignees and
watchers), checklist, dependencies, custom fields, time entries — and
`allowed_statuses`.

**`allowed_statuses` is the list of status ids you may move this task to right
now**, computed from the workflow's transition matrix *and* the caller's role.
Read it before calling `pm_move_task`; a target that is not in it will be
refused.

## Creating a task

```json
{
  "project_id": 9,
  "subject": "Publish the pm skills",
  "description": "Markdown body — the project's content_format.",
  "workflow_id": "dvlpmnt",
  "priority": "high"
}
```

Only `project_id` and `subject` are required. The traps:

- **`workflow_id`** is required *unless the project has exactly one workflow*,
  in which case it is filled in for you. When a project has several, omitting
  it returns `invalid_param` with `available_workflows` — pick from that list.
- **`assignee_contact_id`, `milestone_id`, `sprint_id`, `parent_id` are
  optional.** Omit them, or pass `0`. Do not invent an id to fill the slot.
- A milestone, sprint or assignee **belonging to another project** is refused
  with the project's own options attached (`available_milestones`,
  `available_sprints`, `available_participants`), and *all* mismatches are
  reported in one answer. Note that a project with no milestones at all sends
  back an empty `available_milestones` — that means "this project has none",
  not "you guessed wrong".
- An assignee must already be a **participant of the project**. Add them with
  `pm_add_project_user` first.
- `status_id` defaults to the workflow's first status; there is rarely a reason
  to set it explicitly at creation.
- Requires the `task.create` permission on the project.

## Changing a task

Three separate tools, deliberately:

| Change | Tool | Why not `pm_update_task` |
|--------|------|--------------------------|
| Subject, description, priority, type, dates, estimate, progress, milestone, sprint, parent, custom fields | `pm_update_task` | — |
| **Status** | `pm_move_task` | Status changes run through the workflow's transition rules and the `task.change_status` permission, and maintain `completed_datetime`. `pm_update_task` will not do it |
| **Assignee** | `pm_assign_task` | Enforces `task.assign` and the "must be a participant" rule; `assignee_contact_id: 0` unassigns |

`pm_update_task` is partial — send only the keys you are changing. It enforces
per-field permissions (`task.edit`, `task.assign`, `task.set_dates`), so a
partial denial is possible: check the returned card.

`pm_move_task` takes a **global** `status_id`, which must be in the task's
current `allowed_statuses`.

## Comments, checklist, watchers

**`pm_add_task_comment`** — needs a non-read-only project role. Set
`is_internal: true` for a staff-only note; the default is a public comment.
`pm_list_task_comments` returns them oldest-first with the author's name and
the `is_internal` flag.

**`pm_manage_checklist`** — one tool, five actions:

| `action` | Needs |
|----------|-------|
| `add` | `text` |
| `update` | `item_id` + `text` |
| `complete` / `uncomplete` / `delete` | `item_id` |

All of them require `task.edit`, and the whole checklist comes back after the
change. `item_id`s come from `pm_get_task`.

**`pm_manage_watchers`** — `add` / `remove` a `contact_id`. Managing *someone
else* needs `task.manage_watchers`; a contact may always add or remove
themselves. Watchers are not assignees: use `pm_assign_task` for the assignee,
and this tool will refuse to quietly drop one.

## Linking tasks

pm stores a relation between two tasks as **one** row and reads it from both
ends. A relation is therefore mutual by construction — there is no second,
mirrored link to create, and you should never write "blocked by AUTH-31" into a
description as a substitute.

`pm_manage_dependencies` states the relation from `task_id`'s point of view:

| `relation` | Meaning | The other task's card shows |
|------------|---------|-----------------------------|
| `depends_on` | `task_id` waits for `related_task_id` | `blocks` |
| `blocks` | `related_task_id` waits for `task_id` | `depends_on` |
| `duplicates` | the two are duplicates | `duplicates` |
| `relates_to` | loosely related | `relates_to` |

```json
{"task_id": "PMCP-234", "action": "add", "relation": "depends_on",
 "related_task_id": "PMCP-230", "type": "FS"}
```

- `type` is the scheduling type of a directional relation — `FS`
  (finish-to-start, the default), `SS`, `FF`, `SF`. Ignored for `duplicates`
  and `relates_to`.
- **One relation per pair.** Repeating an identical request is a no-op
  (`already_exists: true`). Asking for a *different* relation between the same
  two tasks — including the reverse dependency, which would make the pair block
  itself — is refused with `conflict`. Remove the existing one first.
- `remove` takes either `related_task_id` or a `dependency_id` from
  `pm_get_task.dependencies`, and works from **either end** of the relation. A
  `dependency_id` that does not involve the named task is refused.
- Requires `task.edit`. The response returns both tasks' relations, so you can
  see the mutuality without a second call.

## Deleting a task

`pm_delete_task` needs `confirm: true` and the `task.delete` permission. It
also deletes the task's **subtasks**, comments, tags, custom fields and links.
There is no undo and no trash — confirm with the human first, and prefer moving
the task to a closed status when the intent is merely "this is done/dropped".

## Pitfalls, in short

- Reading `allowed_statuses` before a move saves a round trip.
- `sprint_id: -1` in `pm_list_tasks` means the backlog; a sprint's *id* is
  something else entirely (`pm_list_sprints`).
- `status_id` values are global across the install; a workflow only decides
  which of them it uses and how they connect.
- After a write, the tool returns the updated card — do not re-fetch it.
- A `not_found` on a task you can see in the UI usually means the token's
  `act_as` contact is not a member of that project.
