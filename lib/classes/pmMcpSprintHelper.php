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
        $project_ids = array_map('intval', $s['project_ids'] ?? array());
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
        if ($accessible === null) {
            $s['_hidden_project_count'] = 0;
            return $s;
        }
        $project_ids = array_map('intval', $s['project_ids'] ?? array());
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
     * Bulk-load sprints without N+1 queries: one query for the pm_sprint rows
     * (scoped by project when asked), then one getBySprintIds() call per
     * relation table.
     *
     * @param int|null   $project_id  Restrict to sprints linked to this project.
     * @param int[]|null $accessible  When $project_id is omitted: the caller's
     *                                accessible project ids, or null for no
     *                                restriction (pm app-admin).
     * @return array  pm_sprint rows keyed by id, decorated with project_ids,
     *                fill_status_ids and workflows.
     */
    public static function loadSprints($project_id = null, ?array $accessible = null)
    {
        $sprint_model = new pmSprintModel();

        if ($project_id) {
            $sprints = $sprint_model->query(
                "SELECT s.* FROM pm_sprint s
                 JOIN pm_sprint_project sp ON s.id = sp.sprint_id
                 WHERE sp.project_id = i:pid",
                array('pid' => (int) $project_id)
            )->fetchAll('id');
        } elseif ($accessible === null) {
            $sprints = $sprint_model->query("SELECT * FROM pm_sprint")->fetchAll('id');
        } elseif (!$accessible) {
            return array();
        } else {
            $sprints = $sprint_model->query(
                "SELECT DISTINCT s.* FROM pm_sprint s
                 JOIN pm_sprint_project sp ON s.id = sp.sprint_id
                 WHERE sp.project_id IN (i:ids)",
                array('ids' => $accessible)
            )->fetchAll('id');
        }

        if (!$sprints) {
            return array();
        }

        $ids = array_keys($sprints);
        $projects      = (new pmSprintProjectModel())->getBySprintIds($ids);
        $fill_statuses = (new pmSprintFillStatusModel())->getBySprintIds($ids);
        $workflows     = (new pmSprintWorkflowModel())->getBySprintIds($ids);

        foreach ($sprints as &$s) {
            $sid = (int) $s['id'];
            $s['project_ids']     = $projects[$sid] ?? array();
            $s['fill_status_ids'] = $fill_statuses[$sid] ?? array();
            $s['workflows']       = $workflows[$sid] ?? array();
        }
        unset($s);

        return $sprints;
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

    /**
     * Work around a bug in pmSprint::complete()'s move_unfinished step: its
     * "UPDATE pm_task SET sprint_id = i:target ..." binds $target through the
     * 'i' (integer) placeholder type, which casts PHP null to 0 rather than
     * emitting SQL NULL (waDbStatement::getQuery(), case 'i': `(int) $value`).
     * When there is no next sprint to move tasks into, every task named in
     * $task_ids ends up with sprint_id = 0 instead of NULL — invisible both
     * from its old sprint and from the true backlog, since every other pm
     * query for "no sprint" checks `sprint_id IS NULL` (e.g.
     * pmTaskModel::getByStatus()'s sprint_id=-1 filter). This does not go
     * through the same raw-SQL path — updateById() resolves the column's
     * nullability and writes SQL NULL correctly — so it is safe to use as the
     * fix-up.
     *
     * Only touches tasks pm_manage_sprint itself just asked complete() to
     * move (captured via openTaskIdsIn() beforehand), so it never reaches a
     * task with a legitimate sprint_id = 0 from anything else.
     *
     * @param int[] $task_ids  From openTaskIdsIn(), captured before calling pmSprint::complete().
     */
    public static function fixOrphanedByCoreBug(array $task_ids)
    {
        if (!$task_ids) {
            return;
        }
        $task_model = new pmTaskModel();
        $orphaned = $task_model->query(
            "SELECT id FROM pm_task WHERE id IN (i:ids) AND sprint_id = 0",
            array('ids' => $task_ids)
        )->fetchAll('id');
        foreach (array_keys($orphaned) as $task_id) {
            $task_model->updateById((int) $task_id, array('sprint_id' => null));
        }
    }
}
