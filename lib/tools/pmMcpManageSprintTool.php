<?php

/**
 * pm_manage_sprint — the sprint lifecycle actions that pm gates with their own
 * rules rather than a plain field write: activate (planned -> active,
 * optionally auto-filling from the backlog), complete (active -> completed,
 * renamed with its date range, applying auto_create_next / move_unfinished)
 * and delete (removes the sprint, detaching — not deleting — its tasks).
 *
 * pmSprint::activate() has no status guard of its own (it would happily
 * re-activate a completed sprint), so this tool enforces "planned" before
 * calling it. pmSprint::complete() returns null both on refusal and on a
 * normal completion with auto_create_next off, so success is judged by
 * re-reading the sprint's status, not by the return value.
 */
class pmMcpManageSprintTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_manage_sprint'; }
    public function getRight()       { return 'pm_manage_sprint'; }
    public function getDescription() { return _wp('Activate, complete or delete a sprint. activate: planned -> active, optionally pulling in backlog tasks (auto_fill). complete: active -> completed, renames the sprint with its date range and applies auto_create_next / move_unfinished. delete: removes the sprint and detaches (does not delete) its tasks — requires confirm. Requires the sprint.edit permission.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('sprint_id', 'action'),
            'properties' => array(
                'sprint_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Sprint id.'),
                'action'    => array(
                    'type'        => 'string',
                    'enum'        => array('activate', 'complete', 'delete'),
                    'description' => 'activate: planned -> active, optionally auto-filling from the backlog. complete: active -> completed; renames the sprint with its date range; applies auto_create_next and move_unfinished. delete: removes the sprint and detaches its tasks.',
                ),
                'confirm' => array('type' => 'boolean', 'description' => 'Required (true) for action=delete. Ignored by activate and complete.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $sprint_id = $this->argInt($arguments, 'sprint_id');
            $action = $this->argString($arguments, 'action');

            $stored = (new pmSprintModel())->getById($sprint_id);
            if (!$stored) {
                return $this->softFail('not_found', _wp('Sprint not found.'));
            }

            $accessible = pmMcpProjectHelper::accessibleProjectIds();
            $stored_pids = array_map('intval', $stored['project_ids'] ?? array());
            if ($accessible !== null && !array_intersect($stored_pids, $accessible)) {
                return $this->softFail('access_denied', _wp('You are not a member of any of this sprint\'s projects.'));
            }

            // Always checked, even for a sprint with no linked projects — pm's
            // own activateAction skips the check in exactly that case; we don't.
            pmMcpSprintHelper::requireCanEdit($stored_pids);

            switch ($action) {
                case 'activate':
                    return $this->activate($sprint_id, $stored, $accessible);
                case 'complete':
                    return $this->complete($sprint_id, $stored, $accessible, $stored_pids);
                case 'delete':
                    return $this->delete($sprint_id, $stored, $stored_pids, $arguments);
                default:
                    return $this->softFail('invalid_param', sprintf(_wp('Unknown action "%s".'), $action));
            }
        });
    }

    protected function activate($sprint_id, array $stored, ?array $accessible)
    {
        if ($stored['status'] !== 'planned') {
            return $this->softFail(
                'conflict',
                _wp('Only planned sprints can be activated.'),
                array('current_status' => $stored['status'])
            );
        }

        $auto_fill = (bool) ($stored['auto_fill'] ?? 0);
        $filled = (int) pmSprint::activate($sprint_id);

        $response = array(
            'action'            => 'activate',
            'sprint_id'         => (int) $sprint_id,
            'sprint'            => pmMcpSprintHelper::cardById($sprint_id, $accessible),
            'filled_task_count' => $filled,
            'auto_fill'         => $auto_fill,
        );
        if ($auto_fill && $filled === 500) {
            $response['warnings'] = array(array(
                'code'    => 'fill_limit_reached',
                'message' => _wp('Auto-fill stopped at its 500-task limit; more matching backlog tasks may remain unassigned.'),
            ));
        }

        return $this->ok($response);
    }

    protected function complete($sprint_id, array $stored, ?array $accessible, array $stored_pids)
    {
        if ($stored['status'] !== 'active') {
            return $this->softFail(
                'conflict',
                _wp('Only active sprints can be completed.'),
                array('current_status' => $stored['status'])
            );
        }

        $move_unfinished = (bool) ($stored['move_unfinished'] ?? 0);
        // Captured before complete() moves anything, with the exact same
        // "not a closed status" condition pmSprint::complete() itself uses —
        // both to report an exact count and to fix up the core bug below.
        $unfinished_ids = $move_unfinished ? pmMcpSprintHelper::openTaskIdsIn($sprint_id) : array();
        $original_name = $stored['name'];

        $new_sprint = pmSprint::complete($sprint_id);

        // pmSprint::complete() returns null both on refusal (already excluded
        // above by the status check) and on a plain completion with
        // auto_create_next off — so null here is not itself a failure signal.
        // Confirm success by re-reading the row.
        $reloaded = (new pmSprintModel())->getById($sprint_id);
        if (!$reloaded || $reloaded['status'] !== 'completed') {
            return $this->softFail('app_error', _wp('The sprint was not completed.'));
        }

        if ($stored_pids) {
            // Original name, before complete() appended the date range —
            // matches what the pm UI logs on the same transition.
            pmTask::log($stored_pids[0], null, $this->getUserId(), 'sprint_completed', array('sprint_name' => $original_name));
        }

        $moved_unfinished = null;
        if ($move_unfinished) {
            // complete()'s move_unfinished step lands these tasks on
            // sprint_id = 0 (the backlog) when there is no next sprint —
            // pm_task.sprint_id is NOT NULL DEFAULT 0 since pm 0.33.5, so
            // that is the correct terminal state, not a fix-up target.
            $moved_unfinished = array(
                'task_count'       => count($unfinished_ids),
                // Explicit "backlog" rather than a bare null target_sprint_id,
                // so a caller isn't left inferring that pm_task.sprint_id
                // became NULL from an absent field.
                'target'           => $new_sprint ? 'new_sprint' : 'backlog',
                'target_sprint_id' => $new_sprint ? (int) $new_sprint['id'] : null,
            );
        }

        return $this->ok(array(
            'action'           => 'complete',
            'sprint_id'        => (int) $sprint_id,
            'completed'        => true,
            'sprint'           => pmMcpSprintHelper::cardById($sprint_id, $accessible),
            'new_sprint'       => $new_sprint ? pmMcpSprintHelper::cardById((int) $new_sprint['id'], $accessible) : null,
            'moved_unfinished' => $moved_unfinished,
        ));
    }

    protected function delete($sprint_id, array $stored, array $stored_pids, array $arguments)
    {
        if (!$this->assertConfirm($arguments, $error)) {
            return $error;
        }

        $affected = pmMcpSprintHelper::countTasksIn($sprint_id);

        pmSprint::delete($sprint_id);

        return $this->ok(array(
            'action'               => 'delete',
            'sprint_id'            => (int) $sprint_id,
            'deleted'              => true,
            'name'                 => $stored['name'],
            'project_ids'          => $stored_pids,
            'detached_task_count'  => $affected['count'],
            'sample_task_ids'      => $affected['sample_task_ids'],
        ));
    }
}
