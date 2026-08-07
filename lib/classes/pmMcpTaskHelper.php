<?php

/**
 * Shared helpers for the pm task read tools (Stage 2): resolving the set of
 * projects a listing may span, loading a task with a project-membership check,
 * and serialising task rows and the full task card.
 *
 * Access model mirrors pmMcpProjectHelper: an app-admin sees every project;
 * everyone else only the projects they are a member of. A task is visible iff
 * its project is.
 *
 * Task references
 * ---------------
 * pm displays a task as its project's number: "AUTH-32" (prefix mode) or
 * "32-AUTH" (postfix mode), falling back to "#32" for a project without a
 * prefix. Users and LLM clients quote that form, so every tool argument naming
 * a task accepts it as well as the bare id — see parseTaskRef().
 */
class pmMcpTaskHelper
{
    /**
     * project_id => array{prefix: string, mode: string, separator: string}.
     * Primed once per request: task listings format a number per row, and
     * parsing a reference scans every known prefix.
     *
     * @var array|null
     */
    protected static $number_config = null;

    /**
     * Per-project number settings, keyed by project id.
     *
     * @param bool $reload  Force a re-read (a project created after the cache
     *                      was primed).
     * @return array
     */
    protected static function numberConfigs($reload = false)
    {
        if (self::$number_config === null || $reload) {
            $config = array();
            $rows = (new pmProjectModel())->select('id, prefix, number_mode, number_separator')->fetchAll('id');
            foreach ($rows as $id => $row) {
                $config[(int) $id] = array(
                    'prefix'    => trim((string) ($row['prefix'] ?? '')),
                    'mode'      => ($row['number_mode'] ?? 'prefix') === 'postfix' ? 'postfix' : 'prefix',
                    'separator' => (string) ($row['number_separator'] ?? '-'),
                );
            }
            self::$number_config = $config;
        }
        return self::$number_config;
    }

    /**
     * Number settings of one project, re-reading once on a cache miss so a
     * project created mid-request (the test suite does this) still formats.
     */
    protected static function numberConfig($project_id)
    {
        $project_id = (int) $project_id;
        $config = self::numberConfigs();
        if (!isset($config[$project_id])) {
            $config = self::numberConfigs(true);
        }
        if (!isset($config[$project_id])) {
            // Deleted project with surviving rows: remember the fallback so the
            // miss does not re-query for every subsequent row.
            self::$number_config[$project_id] = array('prefix' => '', 'mode' => 'prefix', 'separator' => '-');
        }
        return self::$number_config[$project_id];
    }

    /**
     * Drop the cached number settings. Called after a project is created or its
     * prefix changed within the same request.
     */
    public static function resetNumberConfig()
    {
        self::$number_config = null;
    }

    /**
     * The task's number as pm displays it: "AUTH-32", "32-AUTH" or "#32".
     *
     * @param int $task_id
     * @param int $project_id
     * @return string
     */
    public static function formatNumber($task_id, $project_id)
    {
        $task_id = (int) $task_id;
        $config = self::numberConfig($project_id);
        if ($config['prefix'] === '') {
            return '#' . $task_id;
        }
        return $config['mode'] === 'postfix'
            ? $task_id . $config['separator'] . $config['prefix']
            : $config['prefix'] . $config['separator'] . $task_id;
    }

    /**
     * Reduce a reference (or a prefix) to comparable form: letters and digits
     * only, upper-cased. "auth-32", "AUTH 32" and "AUTH32" all collapse to
     * "AUTH32", which is what makes the lookup punctuation- and case-blind.
     */
    protected static function normalizeRef($value)
    {
        $stripped = preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $value);
        return mb_strtoupper((string) $stripped, 'UTF-8');
    }

    /**
     * Split a task reference into the id and the prefix it carried, if any.
     *
     * Accepted: 32, "32", "#32", "AUTH-32", "auth 32", "AUTH32", "32-AUTH"
     * (postfix projects). The returned prefix is normalised and empty when the
     * reference was a bare id; assertPrefix() later checks it against the
     * project the id actually belongs to.
     *
     * @param int|string $ref
     * @return array{id: int, prefix: string, raw: string}
     * @throws waAPIException invalid_param when nothing task-shaped is in there.
     */
    public static function parseTaskRef($ref)
    {
        $raw = is_scalar($ref) ? trim((string) $ref) : '';
        if (preg_match('/^\d+$/', $raw)) {
            return array('id' => (int) $raw, 'prefix' => '', 'raw' => $raw);
        }

        $normalized = self::normalizeRef($raw);
        if (preg_match('/^\d+$/', $normalized)) {
            // "#32", "  32  "
            return array('id' => (int) $normalized, 'prefix' => '', 'raw' => $raw);
        }
        if ($normalized === '') {
            throw new waAPIException(
                'invalid_param',
                sprintf(_wp('"%s" is not a task reference. Pass a task id (32) or a full task number (AUTH-32).'), $raw),
                400
            );
        }

        // Known project prefixes first. This is the only branch that reads a
        // prefix containing digits ("A1-32") correctly, since the generic
        // letters/digits split below would take the "1" for part of the id.
        foreach (self::numberConfigs() as $config) {
            $prefix = self::normalizeRef($config['prefix']);
            if ($prefix === '') {
                continue;
            }
            $length = strlen($prefix);
            if (strncmp($normalized, $prefix, $length) === 0 && preg_match('/^\d+$/', substr($normalized, $length))) {
                return array('id' => (int) substr($normalized, $length), 'prefix' => $prefix, 'raw' => $raw);
            }
            if (substr($normalized, -$length) === $prefix && preg_match('/^\d+$/', substr($normalized, 0, -$length))) {
                return array('id' => (int) substr($normalized, 0, -$length), 'prefix' => $prefix, 'raw' => $raw);
            }
        }

        // Generic shape: an unknown prefix around a digit run. Still resolvable
        // — the id carries the lookup, the prefix only has to survive the
        // consistency check.
        if (preg_match('/^(\D+)(\d+)$/u', $normalized, $m)) {
            return array('id' => (int) $m[2], 'prefix' => $m[1], 'raw' => $raw);
        }
        if (preg_match('/^(\d+)(\D+)$/u', $normalized, $m)) {
            return array('id' => (int) $m[1], 'prefix' => $m[2], 'raw' => $raw);
        }

        throw new waAPIException(
            'invalid_param',
            sprintf(_wp('"%s" is not a task reference. Pass a task id (32) or a full task number (AUTH-32).'), $raw),
            400
        );
    }

    /**
     * Refuse a reference whose prefix contradicts the project the id belongs
     * to: "AUTH-32" must not silently return the WEB project's task 32.
     *
     * The check is containment rather than equality so a reference that kept a
     * stray word ("task AUTH-32") still resolves; only a genuinely different
     * prefix — or none at all on the project's side — is rejected.
     *
     * @throws waAPIException not_found on a mismatch.
     */
    protected static function assertPrefix(array $parsed, array $task)
    {
        if ($parsed['prefix'] === '') {
            return;
        }
        $project_prefix = self::normalizeRef(self::numberConfig((int) $task['project_id'])['prefix']);
        if ($project_prefix !== '' && strpos($parsed['prefix'], $project_prefix) !== false) {
            return;
        }
        throw new waAPIException(
            'not_found',
            sprintf(
                _wp('Task "%s" not found: task %d is %s.'),
                $parsed['raw'],
                (int) $task['id'],
                self::formatNumber((int) $task['id'], (int) $task['project_id'])
            ),
            404
        );
    }

    /**
     * Concrete list of project ids a task listing may span.
     *
     * @param int $project_id  A specific project (>0), or 0 for "all the user
     *                         can access".
     * @return int[]           Possibly empty (user is a member of nothing).
     * @throws waAPIException    not_found when a specific project is missing.
     * @throws waRightsException access_denied when the user is not a member.
     */
    public static function resolveProjectIds($project_id)
    {
        $project_id = (int) $project_id;
        if ($project_id > 0) {
            pmMcpProjectHelper::loadAccessibleProject($project_id);
            return array($project_id);
        }

        $accessible = pmMcpProjectHelper::accessibleProjectIds();
        if ($accessible === null) {
            // App-admin: every project.
            $ids = array_keys((new pmProjectModel())->select('id')->fetchAll('id'));
            return array_map('intval', $ids);
        }
        return $accessible;
    }

    /**
     * Load a task by id or full number, enforcing existence and project
     * membership.
     *
     * @param int|string $task_ref  Task id, or a full number ("AUTH-32").
     * @throws waAPIException    not_found when the task does not exist, or the
     *                           reference's prefix names another project.
     * @throws waRightsException access_denied when the project is not accessible.
     * @return array             The pm_task row.
     */
    public static function loadAccessibleTask($task_ref)
    {
        $parsed = self::parseTaskRef($task_ref);

        $task = (new pmTaskModel())->getById($parsed['id']);
        if (!$task) {
            throw new waAPIException('not_found', sprintf(_wp('Task "%s" not found.'), $parsed['raw']), 404);
        }
        if (!pmMcpProjectHelper::canAccess((int) $task['project_id'])) {
            throw new waRightsException(_wp('You are not a member of this task\'s project.'));
        }
        self::assertPrefix($parsed, $task);
        return $task;
    }

    /**
     * loadAccessibleTask() without the throwing: null when the reference is
     * unparseable, unknown or out of reach. For call sites where a task
     * reference is one interpretation among several (pm_list_tasks search).
     *
     * @param int|string $task_ref
     * @return array|null
     */
    public static function findAccessibleTask($task_ref)
    {
        try {
            return self::loadAccessibleTask($task_ref);
        } catch (Exception $e) {
            // Catch Exception, not waException: waAPIException — what an
            // unparseable or unknown reference raises — extends Exception
            // directly.
            return null;
        }
    }

    /**
     * The current user's role in a project, required for every write. pm's
     * domain layer (pmTask::canEdit/canDelete/...) treats a missing project
     * role as "no access" even for an app-admin, so writing needs an explicit
     * membership. Fail with access_denied rather than letting the domain throw
     * a generic waException later.
     *
     * @throws waRightsException when the user is not a member of the project.
     * @return string  The role slug.
     */
    public static function requireProjectRole($project_id)
    {
        $role = pmHelper::getContactRole(wa()->getUser()->getId(), (int) $project_id);
        if (!$role) {
            throw new waRightsException(_wp('You must be a member of this project to perform this action.'));
        }
        return $role;
    }

    /**
     * Check the project-scoped references of a task write — milestone, sprint,
     * assignee — and describe every mismatch at once.
     *
     * pmTask::create()/save() validate the same three, but one at a time and
     * with a bare "does not belong to this project". An agent that guessed an
     * id learns neither what the project actually offers nor that the field was
     * optional to begin with: a project with no milestones reads exactly like a
     * wrong milestone id, and fixing one reference only surfaces the next.
     * Collecting the mismatches here and naming the alternatives turns three
     * failed round-trips into one corrected call.
     *
     * @param int   $project_id
     * @param array $refs  Task data keyed by field name. A null, 0 or absent
     *                     value means "leave the field empty" and is never a
     *                     mismatch.
     * @return array|null  array{message: string, extra: array} for a softFail,
     *                     or null when every reference fits the project.
     */
    public static function checkProjectRefs($project_id, array $refs)
    {
        $project_id = (int) $project_id;
        $messages = array();
        $extra = array();

        $milestone_id = isset($refs['milestone_id']) ? (int) $refs['milestone_id'] : 0;
        if ($milestone_id > 0) {
            $milestones = (new pmMilestoneModel())->getByProject($project_id);
            if (!isset($milestones[$milestone_id])) {
                $options = array();
                foreach ($milestones as $m) {
                    $options[] = array(
                        'id'     => (int) $m['id'],
                        'name'   => $m['name'],
                        'status' => $m['status'] ?? null,
                    );
                }
                $messages[] = $options
                    ? sprintf(_wp('milestone_id %d does not belong to project %d; see available_milestones.'), $milestone_id, $project_id)
                    : sprintf(_wp('milestone_id %d does not belong to project %d, which has no milestones at all.'), $milestone_id, $project_id);
                $extra['available_milestones'] = $options;
            }
        }

        $sprint_id = isset($refs['sprint_id']) ? (int) $refs['sprint_id'] : 0;
        if ($sprint_id > 0) {
            $sprints = (new pmSprintModel())->getByProject($project_id);
            if (!isset($sprints[$sprint_id])) {
                $options = array();
                foreach ($sprints as $s) {
                    $options[] = array(
                        'id'     => (int) $s['id'],
                        'name'   => $s['name'],
                        'status' => $s['status'] ?? null,
                    );
                }
                $messages[] = $options
                    ? sprintf(_wp('sprint_id %d does not belong to project %d; see available_sprints.'), $sprint_id, $project_id)
                    : sprintf(_wp('sprint_id %d does not belong to project %d, which has no sprints at all.'), $sprint_id, $project_id);
                $extra['available_sprints'] = $options;
            }
        }

        $assignee_id = isset($refs['assignee_contact_id']) ? (int) $refs['assignee_contact_id'] : 0;
        if ($assignee_id > 0) {
            $participants = (new pmProjectUserModel())->getByProject($project_id);
            if (!isset($participants[$assignee_id])) {
                $options = array();
                foreach ($participants as $p) {
                    $options[] = array(
                        'contact_id' => (int) $p['contact_id'],
                        'name'       => $p['name'] ?? '',
                        'role'       => $p['role'],
                    );
                }
                $messages[] = sprintf(_wp('assignee_contact_id %d is not a participant of project %d; see available_participants.'), $assignee_id, $project_id);
                $extra['available_participants'] = $options;
            }
        }

        if (!$messages) {
            return null;
        }
        $messages[] = _wp('These fields are optional: omit an argument, or pass 0, to leave it empty.');

        return array(
            'message' => implode(' ', $messages),
            'extra'   => $extra,
        );
    }

    /**
     * Load a task, check project access, and return it as a pmTask domain
     * entity ready for mutation. The raw row is returned by reference-style
     * out-param for callers that also need the array (e.g. type_slug).
     *
     * @param int|string $task_ref  Task id, or a full number ("AUTH-32").
     * @param array|null $row_out   Receives the raw pm_task row.
     * @return pmTask
     */
    public static function loadTaskEntity($task_ref, ?array &$row_out = null)
    {
        $row_out = self::loadAccessibleTask($task_ref);
        return new pmTask($row_out);
    }

    /**
     * Fresh full card for a task id, for returning the post-mutation state.
     */
    public static function cardById($task_id)
    {
        return self::formatTaskCard((new pmTaskModel())->getById((int) $task_id));
    }

    /**
     * Serialise a pm_task row for list output. Mirrors pmTaskListMethod's shape,
     * plus subtasks_count when getByStatus() supplied it.
     *
     * @param array $t
     * @return array
     */
    public static function formatTaskRow(array $t)
    {
        $row = array(
            'id'                  => (int) $t['id'],
            'full_number'         => self::formatNumber((int) $t['id'], (int) $t['project_id']),
            'project_id'          => (int) $t['project_id'],
            'parent_id'           => !empty($t['parent_id']) ? (int) $t['parent_id'] : null,
            'subject'             => $t['subject'],
            'status_id'           => (int) $t['status_id'],
            'workflow_id'         => $t['workflow_id'] ?? null,
            'priority'            => $t['priority'] ?? 'normal',
            'type_slug'           => $t['type_slug'] ?? null,
            'assignee_contact_id' => !empty($t['assignee_contact_id']) ? (int) $t['assignee_contact_id'] : null,
            'milestone_id'        => !empty($t['milestone_id']) ? (int) $t['milestone_id'] : null,
            'sprint_id'           => !empty($t['sprint_id']) ? (int) $t['sprint_id'] : null,
            'start_date'          => $t['start_date'] ?? null,
            'due_date'            => $t['due_date'] ?? null,
            'estimated_hours'     => isset($t['estimated_hours']) && $t['estimated_hours'] !== null ? (float) $t['estimated_hours'] : null,
            'spent_hours'         => (float) ($t['spent_hours'] ?? 0),
            'progress'            => (int) ($t['progress'] ?? 0),
            'sort'                => (int) $t['sort'],
            'create_contact_id'   => (int) $t['create_contact_id'],
            'create_datetime'     => $t['create_datetime'] ?? null,
            'update_datetime'     => $t['update_datetime'] ?? null,
            'completed_datetime'  => $t['completed_datetime'] ?? null,
        );
        if (array_key_exists('subtasks_count', $t)) {
            $row['subtasks_count'] = (int) $t['subtasks_count'];
        }
        return $row;
    }

    /**
     * Serialise the full task card: base fields plus tags, subtasks, milestone,
     * participants, checklist, dependencies, custom fields, time entries and the
     * workflow transitions available to the current user.
     *
     * @param array $task  A pm_task row.
     * @return array
     */
    public static function formatTaskCard(array $task)
    {
        $id = (int) $task['id'];

        $card = self::formatTaskRow($task);
        $card['description'] = $task['description'] ?? '';

        // Tags.
        $card['tags'] = array();
        $task_tags = (new pmTaskModel())->query(
            "SELECT tt.tag_id, t.name, t.color
             FROM pm_task_tag tt
             JOIN pm_tag t ON tt.tag_id = t.id
             WHERE tt.task_id = i:id",
            array('id' => $id)
        )->fetchAll();
        foreach ($task_tags as $tt) {
            $card['tags'][] = array(
                'id'    => (int) $tt['tag_id'],
                'name'  => $tt['name'],
                'color' => $tt['color'] ?? null,
            );
        }

        // Milestone (expanded).
        $card['milestone'] = null;
        if (!empty($task['milestone_id'])) {
            $m = (new pmMilestoneModel())->getById((int) $task['milestone_id']);
            if ($m) {
                $card['milestone'] = array(
                    'id'         => (int) $m['id'],
                    'name'       => $m['name'],
                    'status'     => $m['status'],
                    'start_date' => $m['start_date'] ?? null,
                    'end_date'   => $m['end_date'] ?? null,
                );
            }
        }

        // Subtasks.
        $card['subtasks'] = array();
        foreach ((new pmTaskModel())->getSubtasks($id) as $st) {
            $card['subtasks'][] = array(
                'id'                  => (int) $st['id'],
                'full_number'         => self::formatNumber((int) $st['id'], (int) $task['project_id']),
                'subject'             => $st['subject'],
                'status_id'           => (int) $st['status_id'],
                'assignee_contact_id' => !empty($st['assignee_contact_id']) ? (int) $st['assignee_contact_id'] : null,
                'progress'            => (int) $st['progress'],
                'sort'                => (int) $st['sort'],
            );
        }

        // Participants (assignees + watchers).
        $card['participants'] = (new pmTaskParticipantModel())->getByTask($id);

        // Checklist.
        $checklist = (new pmChecklistModel())->getByTask($id);
        foreach ($checklist as &$ci) {
            $ci['id']           = (int) $ci['id'];
            $ci['task_id']      = (int) $ci['task_id'];
            $ci['is_completed'] = (int) $ci['is_completed'];
            $ci['sort']         = (float) $ci['sort'];
        }
        unset($ci);
        $card['checklist'] = $checklist;

        // Relations (depends_on / blocks / related). One stored row per relation,
        // read from both ends — see pmMcpDependencyHelper.
        $card['dependencies'] = pmMcpDependencyHelper::forTask($id);

        // Links to records in other integrated apps (helpdesk request, crm
        // deal, shop order) — see pmMcpExternalHelper.
        $card['external_links'] = pmMcpExternalHelper::forTask($id);

        // Custom fields: field_id => value map.
        $card['custom_fields'] = (array) (new pmFieldDataModel())->getByTask($id);

        // Time entries and the total logged.
        $entries = array();
        $total = 0.0;
        foreach ((new pmTaskTimesModel())->getByTask($id) as $e) {
            $hours = (float) $e['hours'];
            $total += $hours;
            $entries[] = array(
                'id'              => (int) $e['id'],
                'contact_id'      => (int) $e['contact_id'],
                'contact_name'    => trim(($e['firstname'] ?? '') . ' ' . ($e['lastname'] ?? '')),
                'hours'           => $hours,
                'description'     => $e['description'] ?? '',
                'create_datetime' => $e['create_datetime'] ?? null,
            );
        }
        $card['time_entries'] = $entries;
        $card['logged_hours'] = $total;

        // Workflow transitions available to the current user from here.
        $workflow_id = $task['workflow_id'] ?? null;
        $card['workflow_id'] = $workflow_id;
        $card['allowed_statuses'] = array();
        if ($workflow_id) {
            $user_role = pmHelper::getContactRole(wa()->getUser()->getId(), (int) $task['project_id']);
            $card['allowed_statuses'] = pmWorkflow::getAllowedTransitions(
                (int) $task['status_id'],
                $workflow_id,
                $user_role
            );
        }

        return $card;
    }
}
