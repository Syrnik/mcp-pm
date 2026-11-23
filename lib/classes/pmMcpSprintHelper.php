<?php

/**
 * Shared helpers for the pm sprint tools (read: Stage 6; write: Stage 8).
 *
 * NOTE on the data model: sprints are M:N over projects. `pm_sprint` carries
 * no `project_id` column at all — the link lives in `pm_sprint_project`, the
 * per-project workflow subset in `pm_sprint_workflow`, and the auto-fill
 * source statuses in `pm_sprint_fill_status`. A sprint spans one project as
 * often as several; access is granted to anyone who is a member of at least
 * one of its projects (mirrors pmSprint::canEdit() and the pm backend's
 * cross-project sprint board).
 *
 * A sprint with NO rows in pm_sprint_project is an "all projects" sprint
 * (pm `c33fbd3`, #290): pmSprintModel reports project_ids = null and pm
 * treats it as covering every active project (pmSprint::getCoveredProjectIds()).
 * The helper keeps that null intact until narrowToAccessible() expands it to
 * the covered set the caller can see and records `_all_projects`, so every
 * formatted row carries `all_projects` and a plain-array `project_ids`.
 *
 * `project_id` is kept on every formatted row as a deprecated convenience
 * alias for `project_ids[0]` — computed from the (possibly access-narrowed)
 * set the caller can see, so it never leaks the id of a project the caller
 * has no access to and stays internally consistent with `project_ids`.
 * `project_ids` is the field to use for anything that needs the full set.
 */
class pmMcpSprintHelper
{
    // ── Read: serialisation ──

    /**
     * Serialise a pm_sprint row (as returned by pmSprintModel/loadSprints,
     * i.e. already decorated with project_ids, fill_status_ids and workflows).
     */
    public static function formatSprintRow(array $s)
    {
        $all_projects = self::isAllProjects($s);
        $project_ids = array_map('intval', (array) ($s['project_ids'] ?? array()));
        $row = array(
            'id'                   => (int) $s['id'],
            'name'                 => $s['name'],
            'goal'                 => $s['goal'] ?? '',
            'status'               => $s['status'],
            'start_date'           => $s['start_date'] ?? null,
            'end_date'             => $s['end_date'] ?? null,
            'duration_weeks'       => (int) ($s['duration'] ?? 1),
            'sort'                 => (int) ($s['sort'] ?? 0),
            'previous_sprint_id'   => !empty($s['previous_sprint_id']) ? (int) $s['previous_sprint_id'] : null,
            // Deprecated: the first project you can see. Use project_ids for
            // the full set — a sprint can span several projects.
            'project_id'           => $project_ids ? $project_ids[0] : null,
            // "All projects" sprint (no pm_sprint_project rows): covers every
            // active project; project_ids then lists the covered ones you can see.
            'all_projects'         => $all_projects,
            'project_ids'          => $project_ids,
            'project_count'        => count($project_ids),
            'hidden_project_count' => (int) ($s['_hidden_project_count'] ?? 0),
            'workflows'            => self::formatWorkflowSelection($s['workflows'] ?? array(), $project_ids),
            'fill_status_ids'      => array_map('intval', $s['fill_status_ids'] ?? array()),
            'automation'           => array(
                'auto_create_next' => (bool) ($s['auto_create_next'] ?? 0),
                'auto_close'       => (bool) ($s['auto_close'] ?? 0),
                'move_unfinished'  => (bool) ($s['move_unfinished'] ?? 0),
                'auto_fill'        => (bool) ($s['auto_fill'] ?? 0),
            ),
        );
        if (array_key_exists('_can_edit', $s)) {
            $row['can_edit'] = (bool) $s['_can_edit'];
        }
        return $row;
    }

    /**
     * Turn the [project_id => [workflow_slug, ...]] map pmSprintModel decorates
     * rows with into the list form the tools take as input: one entry per
     * project, in project_ids order. An empty workflow_ids means "all of that
     * project's workflows" (pm's own fallback, see saveOneAction:219-221).
     *
     * @param array $wf_map      [project_id => [slug, ...]]
     * @param int[] $project_ids
     * @return array[]  [{project_id, workflow_ids}, ...]
     */
    public static function formatWorkflowSelection(array $wf_map, array $project_ids)
    {
        $out = array();
        foreach ($project_ids as $pid) {
            $out[] = array(
                'project_id'   => (int) $pid,
                'workflow_ids' => array_values($wf_map[$pid] ?? array()),
            );
        }
        return $out;
    }

    /**
     * Narrow a decorated sprint row's project_ids/workflows down to the
     * projects the caller can access, and record how many were hidden.
     * $accessible === null means no restriction (pm app-admin): unchanged.
     *
     * @param array      $s
     * @param int[]|null $accessible
     * @return array
     */
    public static function narrowToAccessible(array $s, ?array $accessible)
    {
        // Expand an "all projects" sprint (project_ids === null) into the
        // projects it actually covers, remembering the mode.
        $s['_all_projects'] = self::isAllProjects($s);
        $s['project_ids'] = self::coveredProjectIds($s);
        if ($accessible === null) {
            $s['_hidden_project_count'] = 0;
            return $s;
        }
        $project_ids = $s['project_ids'];
        $visible = array_values(array_intersect($project_ids, $accessible));
        $s['_hidden_project_count'] = count($project_ids) - count($visible);
        $s['project_ids'] = $visible;
        if (!empty($s['workflows'])) {
            $filtered = array();
            foreach ($s['workflows'] as $pid => $wf_ids) {
                if (in_array((int) $pid, $visible, true)) {
                    $filtered[(int) $pid] = $wf_ids;
                }
            }
            $s['workflows'] = $filtered;
        }
        return $s;
    }

    /**
     * Bulk-load sprints without N+1 queries: one query for the pm_sprint rows,
     * then one getBySprintIds() call per relation table. Scoping is done on
     * the covered project set (pmSprint::getCoveredProjectIds()), the same way
     * pm's REST pm.sprint.list does it, so an "all projects" sprint (no
     * pm_sprint_project rows, project_ids = null) is found under any active
     * project and by any member of one.
     *
     * @param int|null   $project_id  Restrict to sprints covering this project.
     * @param int[]|null $accessible  The caller's accessible project ids, or
     *                                null for no restriction (pm app-admin).
     * @return array  pm_sprint rows keyed by id, decorated with project_ids
     *                (null = all projects), fill_status_ids and workflows.
     */
    public static function loadSprints($project_id = null, ?array $accessible = null)
    {
        if ($accessible !== null && !$accessible) {
            return array();
        }

        $sprints = (new pmSprintModel())->query("SELECT * FROM pm_sprint")->fetchAll('id');
        if (!$sprints) {
            return array();
        }

        $ids = array_keys($sprints);
        $projects      = (new pmSprintProjectModel())->getBySprintIds($ids);
        $fill_statuses = (new pmSprintFillStatusModel())->getBySprintIds($ids);
        $workflows     = (new pmSprintWorkflowModel())->getBySprintIds($ids);
        $active_ids    = self::activeProjectIds();

        $out = array();
        foreach ($sprints as $sid => $s) {
            $sid = (int) $sid;
            $s['project_ids']     = isset($projects[$sid]) ? array_map('intval', $projects[$sid]) : null;
            $s['fill_status_ids'] = $fill_statuses[$sid] ?? array();
            $s['workflows']       = $workflows[$sid] ?? array();

            $covered = $s['project_ids'] ?? $active_ids;
            if ($project_id && !in_array((int) $project_id, $covered, true)) {
                continue;
            }
            if ($accessible !== null && !array_intersect($covered, $accessible)) {
                continue;
            }
            $out[$sid] = $s;
        }

        return $out;
    }

    // ── "All projects" sprints ──

    /** Whether a (raw or narrowed) sprint row is an "all projects" sprint. */
    public static function isAllProjects(array $s)
    {
        if (array_key_exists('_all_projects', $s)) {
            return (bool) $s['_all_projects'];
        }
        return array_key_exists('project_ids', $s) && $s['project_ids'] === null;
    }

    /**
     * The projects a sprint covers: its own list, or every active project for
     * an "all projects" sprint — the rule of pmSprint::getCoveredProjectIds().
     *
     * @return int[]
     */
    public static function coveredProjectIds(array $s)
    {
        if (array_key_exists('project_ids', $s) && $s['project_ids'] === null) {
            return self::activeProjectIds();
        }
        return array_map('intval', (array) ($s['project_ids'] ?? array()));
    }

    /** @return int[] ids of active projects — what an "all projects" sprint covers. */
    public static function activeProjectIds()
    {
        return array_map(function ($p) {
            return (int) $p['id'];
        }, (new pmProjectModel())->getActiveProjects());
    }

    /**
     * Project ids where the current user's role passes the sprint.edit
     * permission, in one query. null for a pm app-admin (no restriction).
     * Used only to decorate rows with `can_edit`; the authoritative gate for
     * an actual write is requireCanEdit()/pmSprint::canEdit().
     *
     * @return int[]|null
     */
    public static function editableProjectIds()
    {
        if (wa()->getUser()->isAdmin('pm')) {
            return null;
        }
        $contact_id = wa()->getUser()->getId();
        $rows = (new pmProjectUserModel())->query(
            "SELECT project_id, role FROM pm_project_user WHERE contact_id = i:cid",
            array('cid' => $contact_id)
        )->fetchAll();
        $ids = array();
        foreach ($rows as $r) {
            if (pmHelper::hasPermission($r['role'], 'sprint.edit')) {
                $ids[] = (int) $r['project_id'];
            }
        }
        return $ids;
    }

    /**
     * Whether a sprint (given its visible project_ids) is editable, against an
     * already-computed editableProjectIds() set (null = app-admin, no restriction).
     */
    private static function canEditRow(array $project_ids, ?array $editable)
    {
        return $editable === null || (bool) array_intersect($project_ids, $editable);
    }

    /**
     * Decorate a batch of already-formatted sprint rows with `can_edit`,
     * computing the editable set once instead of per row.
     *
     * @param array[] $rows  Rows as returned by formatSprintRow(), mutated in place.
     */
    public static function decorateCanEdit(array &$rows)
    {
        $editable = self::editableProjectIds();
        foreach ($rows as &$row) {
            $row['can_edit'] = self::canEditRow($row['project_ids'], $editable);
        }
        unset($row);
    }

    /**
     * Require that the current user can edit sprints for at least one of the
     * given projects (pm app-admin, or the sprint.edit permission).
     *
     * @param int[] $project_ids
     * @throws waRightsException  Mapped to access_denied by safeExecute().
     */
    public static function requireCanEdit(array $project_ids)
    {
        if (!pmSprint::canEdit(wa()->getUser()->getId(), $project_ids)) {
            throw new waRightsException(_wp('You need the sprint.edit permission in at least one of these projects.'));
        }
    }

    /**
     * Require the sprint.edit permission in EVERY one of the given projects
     * (pm app-admin passes) — pm's rule for creating a sprint for a project
     * set, changing its composition and deleting it (pm #595, rules 1, 3, 6).
     *
     * @param int[]  $project_ids
     * @param string $message  Error text for the access_denied response.
     * @throws waRightsException  Mapped to access_denied by safeExecute().
     */
    public static function requireCanEditAll(array $project_ids, $message)
    {
        if (!pmSprint::canEditAll(wa()->getUser()->getId(), array_values($project_ids))) {
            throw new waRightsException($message);
        }
    }

    /**
     * Require pm app-admin: only the app administrator may make a sprint
     * "all projects" or change the composition of one (pm #595, rules 2, 3).
     *
     * @throws waRightsException
     */
    public static function requireAppAdmin($message)
    {
        if (!wa()->getUser()->isAdmin('pm')) {
            throw new waRightsException($message);
        }
    }

    /**
     * Full sprint card: the row plus expanded projects, auto-fill statuses
     * and workflow display names.
     *
     * @param array      $s             pm_sprint row (decorated; already
     *                                   narrowed by the caller if needed).
     * @param array|null $all_statuses  pm_status rows keyed by id (loaded when null).
     * @param array|null $project_rows  pm_project rows keyed by id, preloaded
     *                                  to avoid a getById() per project when
     *                                  formatting several cards.
     */
    public static function formatSprintCard(array $s, ?array $all_statuses = null, ?array $project_rows = null)
    {
        $card = self::formatSprintRow($s);

        // Expand linked projects (id, name, color, icon).
        $projects = array();
        foreach ($card['project_ids'] as $pid) {
            $p = $project_rows !== null ? ($project_rows[$pid] ?? null) : (new pmProjectModel())->getById($pid);
            if ($p) {
                $projects[] = array(
                    'id'    => (int) $p['id'],
                    'name'  => $p['name'],
                    'color' => $p['color'] ?? null,
                    'icon'  => $p['icon'] ?? null,
                );
            }
        }
        $card['projects'] = $projects;

        // Expand the auto-fill source statuses (id + name).
        if ($all_statuses === null) {
            $all_statuses = (new pmStatusModel())->getAllStatuses();
        }
        $fill = array();
        foreach ($card['fill_status_ids'] as $sid) {
            if (isset($all_statuses[$sid])) {
                $fill[] = pmMcpWorkflowHelper::formatStatus($all_statuses[$sid]);
            }
        }
        $card['fill_statuses'] = $fill;

        // Display names for the workflow slugs named in `workflows`.
        $all_workflows = pmWorkflow::getWorkflows();
        $workflow_names = array();
        foreach ($card['workflows'] as $entry) {
            foreach ($entry['workflow_ids'] as $wf_id) {
                if (!isset($workflow_names[$wf_id])) {
                    $workflow_names[$wf_id] = isset($all_workflows[$wf_id]) ? $all_workflows[$wf_id]['name'] : $wf_id;
                }
            }
        }
        $card['workflow_names'] = $workflow_names;

        return $card;
    }

    /**
     * Reload a sprint by id and format it as a card, narrowed to what the
     * caller can see. Used as the response of all three write tools, so the
     * card always reflects the persisted state rather than what was posted.
     *
     * @param int        $sprint_id
     * @param int[]|null $accessible  pmMcpProjectHelper::accessibleProjectIds().
     * @return array|null  null when the sprint no longer exists.
     */
    public static function cardById($sprint_id, ?array $accessible)
    {
        $s = (new pmSprintModel())->getById((int) $sprint_id);
        if (!$s) {
            return null;
        }
        $s = self::narrowToAccessible($s, $accessible);
        $card = self::formatSprintCard($s);
        $card['can_edit'] = self::canEditRow($card['project_ids'], self::editableProjectIds());
        return $card;
    }

    /**
     * Sort sprints the way the pm UI does: active, then planned, then completed;
     * within a group by start_date (completed newest-first, others oldest-first).
     */
    public static function sortSprints(array &$rows)
    {
        $order = array('active' => 0, 'planned' => 1, 'completed' => 2);
        usort($rows, function ($a, $b) use ($order) {
            $sa = $order[$a['status']] ?? 99;
            $sb = $order[$b['status']] ?? 99;
            if ($sa !== $sb) {
                return $sa - $sb;
            }
            if ($a['status'] === 'completed') {
                return strcmp($b['start_date'] ?? '', $a['start_date'] ?? '');
            }
            return strcmp($a['start_date'] ?? '', $b['start_date'] ?? '');
        });
    }

    // ── Write: validation helpers ──

    /**
     * Turn the tool's {project_id, workflow_ids}[] input into the flat
     * [project_id, workflow_id][] list pmSprint::save() expects, validating
     * each slug against the project's attached workflows exactly like
     * pmSprints.actions.php::saveOneAction() does. Entries naming a project
     * outside $project_ids are ignored (mirrors saveOneAction:196), matching
     * how the pm UI silently drops a workflow selection for a project you
     * removed from the sprint in the same request.
     *
     * @param mixed $input        The raw `workflows` argument.
     * @param int[] $project_ids  The sprint's final project set.
     * @param array $error_out    Set to [code, message, extra] on failure.
     * @return array[]|null  Flat workflow_items, or null on validation error.
     */
    public static function resolveWorkflowItems($input, array $project_ids, ?array &$error_out = null)
    {
        $error_out = null;
        if (!is_array($input)) {
            $error_out = array('invalid_param', _wp('workflows must be a list of {project_id, workflow_ids} objects.'), array());
            return null;
        }

        $project_model = new pmProjectModel();
        $items = array();

        foreach ($input as $entry) {
            if (!is_array($entry) || !isset($entry['project_id'])) {
                continue;
            }
            $pid = (int) $entry['project_id'];
            if (!in_array($pid, $project_ids, true)) {
                continue;
            }
            $wf_ids = isset($entry['workflow_ids']) && is_array($entry['workflow_ids']) ? $entry['workflow_ids'] : array();
            $project_wf = $project_model->getWorkflows($pid);
            foreach ($wf_ids as $wf_id) {
                $wf_id = trim((string) $wf_id);
                if ($wf_id === '') {
                    continue;
                }
                if (!in_array($wf_id, $project_wf, true)) {
                    $error_out = array(
                        'invalid_param',
                        sprintf(_wp('Workflow "%1$s" is not attached to project %2$d.'), $wf_id, $pid),
                        array('project_id' => $pid, 'available_workflows' => $project_wf),
                    );
                    return null;
                }
                $items[] = array('project_id' => $pid, 'workflow_id' => $wf_id);
            }
        }

        return $items;
    }

    /**
     * The set of status ids a sprint may draw auto-fill tasks from, given its
     * final project set and workflow selection: the union, across all
     * projects, of the statuses of the selected workflows (or every workflow
     * attached to the project when none were selected for it). Exact mirror
     * of pmSprints.actions.php::saveOneAction() lines 210-230 — the union is
     * flat across projects, not per-project.
     *
     * @param int[]   $project_ids
     * @param array[] $workflow_items  Flat [project_id, workflow_id][].
     * @return array  [status_id => true]
     */
    public static function allowedFillStatusIds(array $project_ids, array $workflow_items)
    {
        $project_model = new pmProjectModel();
        $all_workflows = pmWorkflow::getWorkflows();
        $allowed = array();

        foreach ($project_ids as $pid) {
            $selected = array();
            foreach ($workflow_items as $item) {
                if ((int) $item['project_id'] === (int) $pid) {
                    $selected[] = $item['workflow_id'];
                }
            }
            if (!$selected) {
                $selected = $project_model->getWorkflows($pid);
            }
            foreach ($selected as $wf_slug) {
                $wf = $all_workflows[$wf_slug] ?? null;
                if ($wf && !empty($wf['statuses'])) {
                    foreach ($wf['statuses'] as $sid) {
                        $allowed[(int) $sid] = true;
                    }
                }
            }
        }

        return $allowed;
    }

    /**
     * @param int[] $ids
     * @param array $allowed    [status_id => true], from allowedFillStatusIds().
     * @param array $error_out  Set to [code, message, extra] on failure.
     * @return bool
     */
    public static function validateFillStatusIds(array $ids, array $allowed, ?array &$error_out = null)
    {
        $error_out = null;
        if (!$ids) {
            return true;
        }
        if (!$allowed) {
            // Distinct from "this id isn't in the set" below: there is no set
            // at all, because none of the listed projects has any workflow
            // attached. available_fill_status_ids would otherwise come back
            // empty with no indication of why there is nothing to choose from.
            $error_out = array(
                'invalid_param',
                _wp('None of the listed projects has a workflow attached, so there are no statuses to auto-fill from. Attach a workflow to the project first.'),
                array('available_fill_status_ids' => array()),
            );
            return false;
        }
        foreach ($ids as $sid) {
            $sid = (int) $sid;
            if (!isset($allowed[$sid])) {
                $error_out = array(
                    'invalid_param',
                    sprintf(_wp('Status %d cannot be used for auto-fill: it does not belong to the selected workflows.'), $sid),
                    array('status_id' => $sid, 'available_fill_status_ids' => array_values(array_keys($allowed))),
                );
                return false;
            }
        }
        return true;
    }

    /**
     * Count tasks currently attached to a sprint, optionally restricted to a
     * subset of projects (used to size the "these tasks are affected"
     * warnings on update/delete without mutating anything).
     *
     * @param int        $sprint_id
     * @param int[]|null $project_ids  Restrict to tasks in these projects.
     * @return array  ['count' => int, 'sample_task_ids' => int[]] (up to 5).
     */
    public static function countTasksIn($sprint_id, ?array $project_ids = null)
    {
        $task_model = new pmTaskModel();
        $params = array('sid' => (int) $sprint_id);
        $where = 'sprint_id = i:sid';
        if ($project_ids !== null) {
            $where .= ' AND project_id IN (i:pids)';
            $params['pids'] = $project_ids;
        }

        $count = (int) $task_model->query("SELECT COUNT(*) AS c FROM pm_task WHERE {$where}", $params)->fetchField('c');
        $sample = array_map('intval', array_column(
            $task_model->query("SELECT id FROM pm_task WHERE {$where} LIMIT 5", $params)->fetchAll(),
            'id'
        ));

        return array('count' => $count, 'sample_task_ids' => $sample);
    }

    /**
     * Ids of a sprint's tasks that pmSprint::complete()'s move_unfinished step
     * would move — same "not a closed status" condition as its own UPDATE
     * (pmSprint.class.php:84-89). Computed before calling complete() so
     * pm_manage_sprint can report an exact count and, when there is no next
     * sprint, correct the fallout of a core bug (see fixOrphanedByCoreBug()).
     *
     * @param int $sprint_id
     * @return int[]
     */
    public static function openTaskIdsIn($sprint_id)
    {
        $task_model = new pmTaskModel();
        return array_map('intval', array_column(
            $task_model->query(
                "SELECT id FROM pm_task
                 WHERE sprint_id = i:sid AND status_id NOT IN (SELECT id FROM pm_status WHERE is_closed = 1)",
                array('sid' => (int) $sprint_id)
            )->fetchAll(),
            'id'
        ));
    }

}
