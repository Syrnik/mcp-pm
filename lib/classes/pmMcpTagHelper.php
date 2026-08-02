<?php

/**
 * Tag writes shared by pm_create_task, pm_update_task, pm_add_tags and
 * pm_remove_tags.
 *
 * Tags in pm are project-scoped: pm_tag carries a project_id and pm_task_tag
 * links a task to one of its own project's tags. There is no global tag list,
 * so every name resolves within one project and a name that exists in another
 * project is, here, simply missing.
 *
 * Names, not ids
 * --------------
 * All four tools take tag *names*. An agent knows the word it wants ("bug"),
 * almost never the id behind it, and pm_list_tags is a call it would otherwise
 * have to make first every single time.
 *
 * Matching is case-insensitive and done in PHP against the project's tag list
 * rather than by an SQL lookup per name: the comparison is then the same on
 * every install regardless of the column's collation, one query covers a whole
 * batch, and when a project already holds duplicate names (nothing stops it —
 * pm_tag has no unique key on (project_id, name)) the lowest id wins
 * consistently instead of "whichever row the server returned first".
 *
 * Permissions are the caller's business: pm gates tag editing on the task's
 * own task.edit (see the task UI, where the tag box is inside `canEdit`), and
 * pm_create_task's tags ride along with task.create. The helper writes what it
 * is told.
 */
class pmMcpTagHelper
{
    /**
     * pm_tag.name is varchar(100). MySQL would silently truncate anything
     * longer (or error in strict mode); either way the agent gets back a tag it
     * did not ask for, so refuse it up front.
     */
    const NAME_MAX_LENGTH = 100;

    /**
     * Colour given to a tag created through the tools. Same value the pm task
     * UI uses when a typed-in tag turns out to be new, so a tag created by an
     * agent is indistinguishable from one created by a human.
     */
    const DEFAULT_COLOR = '#66cc66';

    /** How many of a project's tags an error names before it stops listing. */
    const MAX_LISTED = 50;

    /**
     * Clean up the caller's `tags` array: trim, drop empties, reject what
     * cannot be a tag name, and de-duplicate case-insensitively (keeping the
     * first spelling).
     *
     * @param mixed $raw  The raw argument value.
     * @return string[]   Possibly empty (a deliberate "clear every tag").
     * @throws waAPIException invalid_param on a non-list, a non-scalar item or
     *                        a name longer than the column allows.
     */
    public static function normalizeNames($raw)
    {
        if (!is_array($raw)) {
            throw new waAPIException('invalid_param', _wp('tags must be an array of tag names.'), 400);
        }

        $names = array();
        $seen = array();
        foreach ($raw as $item) {
            if (is_array($item) || is_object($item) || is_bool($item) || $item === null) {
                throw new waAPIException('invalid_param', _wp('tags must be an array of tag names (strings).'), 400);
            }
            $name = trim((string) $item);
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name, 'UTF-8') > self::NAME_MAX_LENGTH) {
                throw new waAPIException(
                    'invalid_param',
                    sprintf(_wp('Tag name "%s" is longer than %d characters.'), $name, self::NAME_MAX_LENGTH),
                    400
                );
            }
            $key = mb_strtolower($name, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $names[] = $name;
        }

        return $names;
    }

    /**
     * A project's tags keyed by lower-cased name. On a duplicate name the
     * lowest id wins, so every call site resolves it the same way.
     *
     * @return array  lower-cased name => pm_tag row.
     */
    protected static function tagsByName($project_id)
    {
        $by_name = array();
        foreach ((new pmTagModel())->getByProject((int) $project_id) as $row) {
            $key = mb_strtolower(trim((string) $row['name']), 'UTF-8');
            if (!isset($by_name[$key]) || (int) $row['id'] < (int) $by_name[$key]['id']) {
                $by_name[$key] = $row;
            }
        }
        return $by_name;
    }

    /**
     * Which of these names the project does not have yet, in the caller's own
     * spelling (so the error quotes what was actually sent).
     *
     * @param int      $project_id
     * @param string[] $names  Already through normalizeNames().
     * @return string[]
     */
    public static function missingNames($project_id, array $names)
    {
        $by_name = self::tagsByName($project_id);
        $missing = array();
        foreach ($names as $name) {
            if (!isset($by_name[mb_strtolower($name, 'UTF-8')])) {
                $missing[] = $name;
            }
        }
        return $missing;
    }

    /**
     * Resolve names to tag ids, creating the ones the project lacks when the
     * caller allowed it.
     *
     * Call missingNames() first and fail there when creation is not allowed:
     * doing the check separately keeps a rejected write from leaving new tags
     * behind in a project whose task was never created.
     *
     * @param int      $project_id
     * @param string[] $names           Already through normalizeNames().
     * @param bool     $create_missing  Create a tag the project does not have.
     * @return array   Stored tag name => tag id, in the caller's order. Keys are
     *                 the names as pm holds them, not as they were typed.
     * @throws waAPIException invalid_param when a name is missing and creation
     *                        was not allowed.
     */
    public static function resolveOrCreate($project_id, array $names, $create_missing)
    {
        $project_id = (int) $project_id;
        $by_name = self::tagsByName($project_id);
        $model = new pmTagModel();

        $resolved = array();
        foreach ($names as $name) {
            $key = mb_strtolower($name, 'UTF-8');
            if (isset($by_name[$key])) {
                $row = $by_name[$key];
                $resolved[(string) $row['name']] = (int) $row['id'];
                continue;
            }
            if (!$create_missing) {
                throw new waAPIException(
                    'invalid_param',
                    sprintf(_wp('Project %d has no tag "%s".'), $project_id, $name),
                    400
                );
            }
            $tag_id = (int) $model->add($project_id, $name, self::DEFAULT_COLOR);
            // Keep the local map in step: two spellings of one new name in the
            // same call must not create the tag twice.
            $by_name[$key] = array('id' => $tag_id, 'name' => $name, 'color' => self::DEFAULT_COLOR);
            $resolved[$name] = $tag_id;
        }

        return $resolved;
    }

    /**
     * Resolve names to the tags the project already has, reporting the rest
     * instead of refusing them.
     *
     * For pm_remove_tags, where a name the project never had and a name the
     * task simply does not carry are the same non-event: both leave the task as
     * it is, and both are worth naming back to the caller rather than raising.
     *
     * @param int      $project_id
     * @param string[] $names  Already through normalizeNames().
     * @return array  array{tags: array<string,int>, unknown: string[]} — stored
     *                name => id for what exists, the caller's spelling for what
     *                does not.
     */
    public static function resolveExisting($project_id, array $names)
    {
        $by_name = self::tagsByName($project_id);

        $tags = array();
        $unknown = array();
        foreach ($names as $name) {
            $key = mb_strtolower($name, 'UTF-8');
            if (isset($by_name[$key])) {
                $tags[(string) $by_name[$key]['name']] = (int) $by_name[$key]['id'];
            } else {
                $unknown[] = $name;
            }
        }

        return array('tags' => $tags, 'unknown' => $unknown);
    }

    /**
     * Describe an unknown-tag rejection: the message plus the project's own
     * tags, so the agent can correct a typo without a second round trip.
     *
     * Follows pmMcpTaskHelper::checkProjectRefs() — an empty available_tags
     * means the project has no tags at all, which is a different situation from
     * a wrong name and reads that way in the message.
     *
     * @param int      $project_id
     * @param string[] $missing  Names the project does not have.
     * @param string   $flag     Name of the argument that would allow creation.
     * @return array   array{message: string, extra: array} for softFail().
     */
    public static function missingTagsFailure($project_id, array $missing, $flag = 'create_missing_tags')
    {
        $available = self::availableTags($project_id);
        $quoted = '"' . implode('", "', $missing) . '"';

        $message = $available
            ? sprintf(_wp('Project %d has no tag %s; see available_tags.'), (int) $project_id, $quoted)
            : sprintf(_wp('Project %d has no tag %s — it has no tags at all yet.'), (int) $project_id, $quoted);
        $message .= ' ' . sprintf(_wp('Pass %s=true to create it, or use an existing name.'), $flag);

        return array(
            'message' => $message,
            'extra'   => array(
                'missing_tags'   => array_values($missing),
                'available_tags' => $available,
            ),
        );
    }

    /**
     * A project's tags in the pm_list_tags shape, capped so a project with
     * hundreds of them does not bury the error message that carries it.
     *
     * @return array
     */
    public static function availableTags($project_id)
    {
        $tags = array();
        foreach ((new pmTagModel())->getByProject((int) $project_id) as $row) {
            if (count($tags) >= self::MAX_LISTED) {
                break;
            }
            $tags[] = array(
                'id'    => (int) $row['id'],
                'name'  => $row['name'],
                'color' => $row['color'] ?? null,
            );
        }
        return $tags;
    }

    /**
     * Attach, detach or replace a task's tags, logging every change the way the
     * pm UI does so the task history reads the same whoever made it.
     *
     * @param array  $task        A pm_task row (needs id and project_id).
     * @param array  $tags        Tag name => id, from resolveOrCreate().
     * @param string $mode        'add' | 'remove' | 'replace'.
     * @param int    $contact_id  Who is making the change.
     * @return array  array{added: string[], removed: string[], not_on_task: string[]}
     *                — names, for the tool's response.
     */
    public static function applyToTask(array $task, array $tags, $mode, $contact_id)
    {
        $task_id    = (int) $task['id'];
        $project_id = (int) $task['project_id'];

        $link_model = new pmTaskTagModel();
        $current    = array();
        foreach ($link_model->getByTask($task_id) as $tag_id => $row) {
            $current[(int) $tag_id] = $row;
        }

        $names_by_id = array();
        foreach ($tags as $name => $tag_id) {
            $names_by_id[(int) $tag_id] = (string) $name;
        }
        $target_ids = array_keys($names_by_id);

        $to_add = array();
        $to_remove = array();
        $not_on_task = array();

        if ($mode === 'remove') {
            foreach ($target_ids as $tag_id) {
                if (isset($current[$tag_id])) {
                    $to_remove[] = $tag_id;
                } else {
                    $not_on_task[] = $names_by_id[$tag_id];
                }
            }
        } else {
            $to_add = array_values(array_diff($target_ids, array_keys($current)));
            if ($mode === 'replace') {
                $to_remove = array_values(array_diff(array_keys($current), $target_ids));
            }
        }

        $added = array();
        foreach ($to_add as $tag_id) {
            $name = $names_by_id[$tag_id] ?? '';
            if ($link_model->add($task_id, $tag_id)) {
                $added[] = $name;
                pmTask::log($project_id, $task_id, $contact_id, 'tag_added', array(
                    'tag_id'   => $tag_id,
                    'tag_name' => $name,
                ));
            }
        }

        $removed = array();
        foreach ($to_remove as $tag_id) {
            $name = $names_by_id[$tag_id] ?? (string) ($current[$tag_id]['name'] ?? '');
            $link_model->remove($task_id, $tag_id);
            $removed[] = $name;
            pmTask::log($project_id, $task_id, $contact_id, 'tag_removed', array(
                'tag_id'   => $tag_id,
                'tag_name' => $name,
            ));
        }

        return array(
            'added'       => $added,
            'removed'     => $removed,
            'not_on_task' => $not_on_task,
        );
    }

    /**
     * A task's tags in the same shape pm_get_task puts on the card.
     *
     * @return array
     */
    public static function forTask($task_id)
    {
        $tags = array();
        foreach ((new pmTaskTagModel())->getByTask((int) $task_id) as $tag_id => $row) {
            $tags[] = array(
                'id'    => (int) $tag_id,
                'name'  => $row['name'],
                'color' => $row['color'] ?? null,
            );
        }
        return $tags;
    }
}
