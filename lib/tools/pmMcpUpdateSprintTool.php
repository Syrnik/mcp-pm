<?php

/**
 * pm_update_sprint — partial update of a sprint's properties, project set,
 * workflow selection and auto-fill statuses. Only the fields you supply
 * change; project_ids/workflows/fill_status_ids are each a REPLACEMENT SET
 * for the key it names — omit the key entirely to keep the current value.
 *
 * pmSprint::save() always rewrites project_ids, workflows and fill_status_ids
 * as a whole (delete-all-then-reinsert in the domain layer), so anything you
 * omit here is read back from the stored sprint and re-supplied before the
 * call — the tool never lets an omitted relation silently disappear.
 *
 * Projects you cannot access are never removed by this tool, even if you
 * pass a project_ids list that excludes them: a caller only sees and edits
 * what it is a member of, and cannot use that partial view to detach a
 * sprint from projects it has no role in (pm's own saveOneAction has this
 * hole — it trusts the posted project_ids alone).
 *
 * Rights mirror pm #595: the sprint fields need sprint.edit in at least one
 * covered project; every project added or removed needs it too; turning a
 * sprint into an "all projects" one (all_projects: true), or changing the
 * projects of one, is for the pm administrator only; workflow selections of
 * projects where the caller lacks sprint.edit keep their stored value.
 *
 * status is deliberately not settable here: pm lets saveOneAction flip it
 * directly, bypassing the activate/complete gates and every side effect
 * (auto-fill, auto-create-next, move-unfinished). Use pm_manage_sprint.
 */
class pmMcpUpdateSprintTool extends pmMcpToolBase
{
    const MIN_DURATION_WEEKS = 1;
    const MAX_DURATION_WEEKS = 52;

    public function getName()        { return 'pm_update_sprint'; }
    public function getRight()       { return 'pm_update_sprint'; }
    public function getDescription() { return _wp('Update a sprint\'s name, goal, dates, project set, workflow selection or auto-fill statuses. Only supplied fields change. project_ids/workflows/fill_status_ids are each a replacement set for the key you pass — omit the key to keep the current value; projects you cannot access are never removed. all_projects: true makes the sprint cover every active project; it and changing the projects of such a sprint are for the pm administrator only. Status is not settable here — use pm_manage_sprint to activate, complete or delete. Requires the sprint.edit permission in at least one covered project, and in every project added or removed. Returns the updated sprint card.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('sprint_id'),
            'properties' => array(
                'sprint_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Sprint id.'),
                'project_ids' => array(
                    'type'        => 'array',
                    'minItems'    => 1,
                    'uniqueItems' => true,
                    'items'       => array('type' => 'integer', 'minimum' => 1),
                    'description' => 'Replacement set of projects this sprint spans — everything omitted from the list is removed. Omit this key entirely to keep the current set. Projects you cannot access are never removed, whether or not you list them.',
                ),
                'all_projects' => array(
                    'type'        => 'boolean',
                    'description' => 'true: make this an "all projects" sprint covering every active project (pm administrator only; cannot be combined with project_ids or workflows). false: back to an explicit set — project_ids is then required (pm administrator only for an all-projects sprint). Omit to keep the current mode.',
                ),
                'name' => array('type' => 'string', 'minLength' => 1, 'description' => 'Sprint name.'),
                'goal' => array('type' => 'string', 'description' => 'Sprint goal (empty string clears it).'),
                'start_date' => array('type' => 'string', 'description' => 'Start date (YYYY-MM-DD, empty string clears it). end_date is recomputed from start_date + duration_weeks.'),
                'duration_weeks' => array(
                    'type'        => 'integer',
                    'minimum'     => self::MIN_DURATION_WEEKS,
                    'maximum'     => self::MAX_DURATION_WEEKS,
                    'description' => 'Sprint length in WEEKS. end_date = start_date + duration_weeks*7 - 1 days.',
                ),
                'workflows' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'required'   => array('project_id'),
                        'properties' => array(
                            'project_id'   => array('type' => 'integer', 'minimum' => 1),
                            'workflow_ids' => array('type' => 'array', 'items' => array('type' => 'string')),
                        ),
                    ),
                    'description' => 'Replacement set of per-project workflow selections — everything omitted is removed. Omit this key entirely to keep the current selection. An empty workflow_ids for a project means "all of that project\'s workflows".',
                ),
                'fill_status_ids' => array(
                    'type'        => 'array',
                    'items'       => array('type' => 'integer', 'minimum' => 1),
                    'description' => 'Replacement set of auto-fill source statuses. Omit this key to keep the current set. Must belong to the (possibly just-changed) selected workflows.',
                ),
                'auto_fill'        => array('type' => 'boolean', 'description' => 'On activation, pull backlog tasks in fill_status_ids into the sprint.'),
                'auto_create_next' => array('type' => 'boolean', 'description' => 'On completion, create the following sprint with the same settings.'),
                'auto_close'       => array('type' => 'boolean', 'description' => 'Close the sprint automatically once its end date passes.'),
                'move_unfinished'  => array('type' => 'boolean', 'description' => 'On completion, move unfinished tasks to the next sprint — or to the backlog when auto_create_next is off.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $sprint_id = $this->argInt($arguments, 'sprint_id');
            $stored = (new pmSprintModel())->getById($sprint_id);
            if (!$stored) {
                return $this->softFail('not_found', _wp('Sprint not found.'));
            }

            $accessible   = pmMcpProjectHelper::accessibleProjectIds();
            $stored_all   = pmMcpSprintHelper::isAllProjects($stored);
            // Explicit list ([] for an "all projects" sprint) and what the
            // sprint actually covers (every active project in that mode).
            $stored_pids  = array_map('intval', (array) ($stored['project_ids'] ?? array()));
            $covered_now  = pmMcpSprintHelper::coveredProjectIds($stored);
            $hidden       = $accessible === null ? array() : array_values(array_diff($stored_pids, $accessible));

            if ($accessible !== null && !array_intersect($covered_now, $accessible)) {
                return $this->softFail('access_denied', _wp('You are not a member of any of this sprint\'s projects.'));
            }

            $updated_fields = array();

            // ── composition mode: all_projects / project_ids ──
            $has_all_arg = array_key_exists('all_projects', $arguments);
            $want_all = $has_all_arg ? $this->argBool($arguments, 'all_projects') : null;
            if ($want_all === true && array_key_exists('project_ids', $arguments)) {
                return $this->softFail('invalid_param', _wp('Pass either project_ids or all_projects: true, not both.'));
            }
            if ($want_all === false && !array_key_exists('project_ids', $arguments)) {
                return $this->softFail('invalid_param', _wp('all_projects: false needs project_ids: at least one project is required.'));
            }
            if ($has_all_arg) {
                $updated_fields[] = 'all_projects';
            }
            // pm #595 rules 2-3: making a sprint "all projects", or changing
            // the composition of one, is the pm administrator's call. Keeping
            // the mode as it is needs nothing beyond the field rights.
            if ($want_all === true && !$stored_all) {
                pmMcpSprintHelper::requireAppAdmin(_wp('Only the pm administrator can set a sprint to all projects.'));
            }
            if ($stored_all && array_key_exists('project_ids', $arguments)) {
                pmMcpSprintHelper::requireAppAdmin(_wp('Only the pm administrator can change the projects of an all-projects sprint.'));
            }
            $final_all = $want_all === true || ($stored_all && !array_key_exists('project_ids', $arguments));

            // ── project_ids: merge ──
            if ($final_all) {
                $final = null;
            } elseif (array_key_exists('project_ids', $arguments)) {
                $updated_fields[] = 'project_ids';
                $raw = is_array($arguments['project_ids']) ? $arguments['project_ids'] : array();
                if (!$raw) {
                    return $this->softFail('invalid_param', _wp('project_ids must be a non-empty list. Omit the key entirely to keep the current set.'));
                }
                $requested = array_values(array_unique(array_filter(array_map('intval', $raw), function ($id) {
                    return $id > 0;
                })));
                if (!$requested) {
                    return $this->softFail('invalid_param', _wp('project_ids must be a non-empty list. Omit the key entirely to keep the current set.'));
                }
                foreach ($requested as $pid) {
                    pmMcpProjectHelper::loadAccessibleProject($pid);
                }
                $final = array_values(array_unique(array_merge($requested, $hidden)));
            } else {
                $final = $stored_pids;
            }

            // ── rights (pm #595) ──
            // What the sprint will cover after the save.
            $covered_final = $final === null ? pmMcpSprintHelper::activeProjectIds() : $final;
            if ($final !== null) {
                // Rule 3: every project added or removed needs sprint.edit.
                // (Hidden projects were merged back above, so they never count
                // as removed.) Leaving "all projects" is admin-only, checked above.
                $changed = $stored_all ? array() : array_values(array_unique(array_merge(
                    array_diff($stored_pids, $final),
                    array_diff($final, $stored_pids)
                )));
                if ($changed) {
                    pmMcpSprintHelper::requireCanEditAll($changed, _wp('Changing the projects of a sprint requires the sprint.edit permission in every project added or removed.'));
                }
            }
            // Rule 5: the sprint's own fields need sprint.edit in at least one
            // project it covers.
            pmMcpSprintHelper::requireCanEdit($covered_final);

            // ── workflows: merge ──
            $warnings = array();
            if ($final === null) {
                if (!empty($arguments['workflows'])) {
                    return $this->softFail('invalid_param', _wp('An all-projects sprint has no workflow selection: it uses every workflow of every project. Omit workflows.'));
                }
                $workflow_items = array();
            } elseif (array_key_exists('workflows', $arguments)) {
                $updated_fields[] = 'workflows';
                // pm #595 rule 3: the selection of a project the caller cannot
                // manage (no sprint.edit there — hidden projects included)
                // keeps its stored value; whatever was passed for it is ignored.
                $editable = pmMcpSprintHelper::editableProjectIds();
                $managed = $editable === null ? $final : array_values(array_intersect($final, $editable));
                $workflow_items = pmMcpSprintHelper::resolveWorkflowItems($arguments['workflows'], $managed, $error);
                if ($workflow_items === null) {
                    return $this->softFail($error[0], $error[1], $error[2]);
                }
                $ignored = array();
                foreach ((is_array($arguments['workflows']) ? $arguments['workflows'] : array()) as $entry) {
                    $pid = is_array($entry) ? (int) ($entry['project_id'] ?? 0) : 0;
                    if ($pid && in_array($pid, $final, true) && !in_array($pid, $managed, true)) {
                        $ignored[$pid] = $pid;
                    }
                }
                foreach (($stored['workflows'] ?? array()) as $pid => $wf_ids) {
                    if (in_array((int) $pid, $final, true) && !in_array((int) $pid, $managed, true)) {
                        foreach ((array) $wf_ids as $wf_id) {
                            $workflow_items[] = array('project_id' => (int) $pid, 'workflow_id' => $wf_id);
                        }
                    }
                }
                if ($ignored) {
                    $warnings[] = array(
                        'code'        => 'workflows_locked',
                        'message'     => _wp('You lack the sprint.edit permission in some of the listed projects; their workflow selection was kept as stored.'),
                        'project_ids' => array_values($ignored),
                    );
                }
            } else {
                $workflow_items = array();
                foreach (($stored['workflows'] ?? array()) as $pid => $wf_ids) {
                    foreach ((array) $wf_ids as $wf_id) {
                        $workflow_items[] = array('project_id' => (int) $pid, 'workflow_id' => $wf_id);
                    }
                }
            }
            $workflow_items = array_values(array_filter($workflow_items, function ($item) use ($covered_final) {
                return in_array((int) $item['project_id'], $covered_final, true);
            }));

            // ── fill_status_ids: merge ──
            $stale_fill_status_ids = array();
            if (array_key_exists('fill_status_ids', $arguments)) {
                $updated_fields[] = 'fill_status_ids';
                $raw = is_array($arguments['fill_status_ids']) ? $arguments['fill_status_ids'] : array();
                $fill_status_ids = array_values(array_unique(array_map('intval', $raw)));
                $allowed = pmMcpSprintHelper::allowedFillStatusIds($covered_final, $workflow_items);
                if (!pmMcpSprintHelper::validateFillStatusIds($fill_status_ids, $allowed, $error)) {
                    return $this->softFail($error[0], $error[1], $error[2]);
                }
            } else {
                // Carry stored fill statuses through unvalidated: re-validating
                // untouched data would turn a plain rename into a hard failure
                // whenever the project/workflow set had shrunk. Flag drift as a
                // warning instead (see below).
                $fill_status_ids = array_map('intval', $stored['fill_status_ids'] ?? array());
                $allowed = pmMcpSprintHelper::allowedFillStatusIds($covered_final, $workflow_items);
                $stale_fill_status_ids = array_values(array_diff($fill_status_ids, array_keys($allowed)));
            }

            // ── scalar fields ──
            $data = array('id' => $sprint_id);

            if (array_key_exists('name', $arguments)) {
                $updated_fields[] = 'name';
                $name = strip_tags($this->argString($arguments, 'name'));
                if ($name === '') {
                    return $this->softFail('invalid_param', _wp('Sprint name cannot be empty.'));
                }
                $data['name'] = $name;
            }

            if (array_key_exists('goal', $arguments)) {
                $updated_fields[] = 'goal';
                $goal = $this->argString($arguments, 'goal');
                $data['goal'] = $goal !== '' ? $goal : null;
            }

            // start_date/duration always go to save() together (from the
            // argument when supplied, else the stored value) — pmSprint::save()
            // only recomputes end_date when both are present in $data.
            $start_supplied = array_key_exists('start_date', $arguments);
            $duration_supplied = array_key_exists('duration_weeks', $arguments);
            if ($start_supplied) {
                $updated_fields[] = 'start_date';
            }
            if ($duration_supplied) {
                $updated_fields[] = 'duration_weeks';
            }

            $start_date = $start_supplied ? $this->argString($arguments, 'start_date') : (string) ($stored['start_date'] ?? '');
            $start_date = $start_date !== '' ? $start_date : null;

            $duration = $duration_supplied ? $this->argInt($arguments, 'duration_weeks') : (int) ($stored['duration'] ?: self::MIN_DURATION_WEEKS);
            $duration = max(self::MIN_DURATION_WEEKS, min(self::MAX_DURATION_WEEKS, $duration ?: self::MIN_DURATION_WEEKS));

            $data['start_date'] = $start_date;
            $data['duration']   = $duration;
            if ($start_date === null) {
                // save() skips the end_date recompute when start_date is empty;
                // clear it explicitly so a stale value doesn't linger.
                $data['end_date'] = null;
            }

            foreach (array('auto_fill', 'auto_create_next', 'auto_close', 'move_unfinished') as $flag) {
                if (array_key_exists($flag, $arguments)) {
                    $updated_fields[] = $flag;
                    $data[$flag] = $this->argBool($arguments, $flag) ? 1 : 0;
                }
            }

            if (!$updated_fields) {
                return $this->softFail('invalid_param', _wp('Nothing to update: supply at least one field.'));
            }

            // ── shrink warning: projects leaving the sprint don't unlink their tasks ──
            // Diffed on the covered sets, so leaving "all projects" for a
            // narrower list is caught too.
            $removed = array_values(array_diff($covered_now, $covered_final));
            if ($removed) {
                $affected = pmMcpSprintHelper::countTasksIn($sprint_id, $removed);
                if ($affected['count'] > 0) {
                    $warnings[] = array(
                        'code'                 => 'tasks_hidden_from_board',
                        'message'              => sprintf(
                            _wp('%d task(s) are still attached to this sprint but their project is no longer part of it, so they no longer appear on the sprint board. Move them with pm_update_task (sprint_id: 0) or re-add the project.'),
                            $affected['count']
                        ),
                        'removed_project_ids'  => $removed,
                        'task_count'           => $affected['count'],
                        'sample_task_ids'      => $affected['sample_task_ids'],
                    );
                }
            }
            if ($stale_fill_status_ids) {
                $warnings[] = array(
                    'code'      => 'stale_fill_statuses',
                    'message'   => _wp('Some of this sprint\'s auto-fill statuses no longer belong to its (possibly narrower) workflow selection. They were kept as-is; pass fill_status_ids explicitly to clean them up.'),
                    'status_ids' => $stale_fill_status_ids,
                );
            }

            pmSprint::save($data, $final, $workflow_items, $fill_status_ids);

            $card = pmMcpSprintHelper::cardById($sprint_id, $accessible);

            $response = array(
                'sprint_id'      => $sprint_id,
                'sprint'         => $card,
                'updated_fields' => $updated_fields,
            );
            if ($warnings) {
                $response['warnings'] = $warnings;
            }

            return $this->ok($response);
        });
    }
}
