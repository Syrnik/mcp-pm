# pm: the project wiki

Read [`pm-basics`](skill://pm/pm-basics) first. Four tools:
`pm_list_wiki_pages`, `pm_get_wiki_page`, `pm_create_wiki_page`,
`pm_update_wiki_page`.

## Two kinds of page

- **`article`** — has a `content` body. This is what you normally create.
- **`section`** — a folder. It **never stores content**: content passed for a
  section is dropped, and switching an article to a section **clears its body**
  for good. Use sections only to group pages.

Pages form a tree through `parent_id` (`null`/`0` = top level). There is no
depth limit, but keep it shallow — a wiki an agent has to walk is a wiki nobody
reads.

## Access: two gates, both enforced

1. **The project permission.** You need `wiki.view` to read or `wiki.edit` to
   write, from your project role. A non-member is denied outright (unless they
   are a Webasyst app admin).
2. **Per-page visibility**, applied on top:

   | Page state | Visible to |
   |------------|-----------|
   | not published (draft) | its author, and project admins/managers |
   | published + `is_public` | everyone with `wiki.view` |
   | published, not public, with `access_roles` | those roles, plus admins/managers |
   | published, not public, no `access_roles` | everyone with `wiki.view` |

`pm_list_wiki_pages` filters the tree to what *you* may see, so a page missing
from the listing is not necessarily missing from the wiki. `pm_get_wiki_page`
applies the same rule and answers `access_denied` for a page you cannot see.

## Listing

```json
{"project_id": 9}
```

Returns:

- `pages` — flat array of page metadata, **no content** (keeps the tree cheap);
- `children` — a map of `parent_id → [child ids]`, rebuilt over the visible set
  only. Key `"0"` holds the top-level pages;
- `content_format` — the markup the project uses (`md` by default);
- `can_edit` — whether you hold `wiki.edit`, so you can tell up front whether a
  write will be accepted.

Read a body with `pm_get_wiki_page` and its `page_id`; that is the only tool
that returns `content`.

## Creating

```json
{
  "project_id": 9,
  "title": "MCP skills: how they are published",
  "type": "article",
  "content": "## Registry\n\nMarkdown, per the project's content_format.",
  "parent_id": 0,
  "published": true
}
```

- Requires `wiki.edit`.
- `published` defaults to **`false`** — a page you create without it is a draft
  only you and the project admins/managers can see. If the point was to
  document something for the team, pass `published: true`.
- `is_public` defaults to `false`, which for a published page with no
  `access_roles` still means "everyone who can view the wiki". Set
  `access_roles: ["member","viewer"]` to narrow it to specific roles; an
  unknown role slug is refused with the valid ones attached.
- `parent_id` must be a page in the **same project**; omit or `0` for the top
  level. New pages are appended at the end of their parent.

## Updating and moving

`pm_update_wiki_page` is partial — send only what changes. It handles moves
too: `parent_id` re-parents the page (`0` makes it top-level). A page cannot be
moved under itself or its own descendant, and cannot cross projects.

- `access_roles: []` clears the role restriction (back to "everyone with
  `wiki.view`"); it does not hide the page.
- `type: "section"` clears the body — do not use it to "archive" an article.
- `title` cannot be set to an empty string.

## Wiki or task?

Wiki pages are the right home for durable knowledge — decisions, conventions,
how-tos. Working notes about a specific piece of work belong in the task
(`pm_add_task_comment`, or the task description). Do not write a plan to the
wiki when the human asked for it in a task, and do not paste transient status
updates into the wiki.

Related: [`pm-basics`](skill://pm/pm-basics) ·
[`pm-projects`](skill://pm/pm-projects)
