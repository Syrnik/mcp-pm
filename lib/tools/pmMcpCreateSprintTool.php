<?php

/**
 * pm_create_sprint — create a sprint spanning one or more projects, with its
 * per-project workflow subset and auto-fill statuses. New sprints are always
 * created "planned"; use pm_manage_sprint to activate one.
 *
 * Rights mirror pm #595: a sprint for an explicit project set needs the
 * sprint.edit permission in every project of the set (rule 1) — and
 * membership in each, so a caller cannot attach a project it cannot access;
 * an "all projects" sprint (all_projects: true, no pm_sprint_project rows,
 * covering every active project) can only be created by the pm app admin
 * (rule 2).
 */
class pmMcpCreateSprintTool extends pmMcpToolBase
{
    const MIN_DURATION_WEEKS = 1;
    const MAX_DURATION_WEEKS = 52;

    public function getName()        { return 'pm_create_sprint'; }
    public function getRight()       { return 'pm_create_sprint'; }
    public function getDescription() { return _wp('Create a sprint spanning one or more projects, with a per-project workflow subset and auto-fill statuses. Pass project_ids, or all_projects: true for a sprint covering every active project (pm administrator only). Always created "planned" — use pm_manage_sprint to activate it. Requires the sprint.edit permission in every project listed. Returns the created sprint card.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('name'),
            'properties' => array(
                'project_ids' => array(
                    'type'        => 'array',
                    'minItems'    => 1,
                    'uniqueItems' => true,
                    'items'       => array('type' => 'integer', 'minimum' => 1),
                    'description' => 'Projects this sprint spans. A sprint may cover several projects; you need the sprint.edit permission in every one you list. Required unless all_projects is true.',
                ),
                'all_projects' => array(
                    'type'        => 'boolean',
                    'description' => 'Create an "all projects" sprint: it covers every active project, including ones created later. pm administrator only. Cannot be combined with project_ids or workflows.',
                ),
                'name' => array('type' => 'string', 'minLength' => 1, 'description' => 'Sprint name.'),
                'goal' => array('type' => 'string', 'description' => 'Sprint goal.'),
                'start_date' => array('type' => 'string', 'description' => 'Start date (YYYY-MM-DD). end_date is computed from start_date + duration_weeks and cannot be set directly.'),
                'duration_weeks' => array(
                    'type'        => 'integer',
                    'minimum'     => self::MIN_DURATION_WEEKS,
                    'maximum'     => self::MAX_DURATION_WEEKS,
                    'description' => 'Sprint length in WEEKS (default 1). end_date = start_date + duration_weeks*7 - 1 days.',
                ),
                'workflows' => array(
                    'type'        => 'array',
                    'items'       => array(
                        'type'       => 'object',
                        'required'   => array('project_id'),
                        'properties' => array(
                            'project_id'   => array('type' => 'integer', 'minimum' => 1),
                            'workflow_ids' => array('type' => 'array', 'items' => array('type' => 'string')),
                        ),
                    ),
                    'description' => 'Per-project workflow subset. Omit a project, or give it an empty workflow_ids, to use all of that project\'s workflows.',
                ),
                'fill_status_ids' => array(
                    'type'        => 'array',
                    'items'       => array('type' => 'integer', 'minimum' => 1),
                    'description' => 'Statuses auto-fill draws unassigned tasks from when the sprint is activated. Only effective with auto_fill=true. Must belong to the selected workflows.',
                ),
                'auto_fill'        => array('type' => 'boolean', 'description' => 'On activation, pull backlog tasks in fill_status_ids into the sprint (capped at 500).'),
                'auto_create_next' => array('type' => 'boolean', 'description' => 'On completion, create the following sprint with the same projects, workflows and settings.'),
                'auto_close'       => array('type' => 'boolean', 'description' => 'Close the sprint automatically once its end date passes (via the pm cron).'),
                'move_unfinished'  => array('type' => 'boolean', 'description' => 'On completion, move unfinished tasks to the next sprint — or to the backlog when auto_create_next is off.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $all_projects = $this->argBool($arguments, 'all_projects');
            $project_ids_raw = is_array($arguments['project_ids'] ?? null) ? $arguments['project_ids'] : array();
            $project_ids = array_values(array_unique(array_filter(array_map('intval', $project_ids_raw), function ($id) {
                return $id > 0;
            })));

            if ($all_projects) {
                if ($project_ids_raw) {
                    return $this->softFail('invalid_param', _wp('Pass either project_ids or all_projects, not both.'));
                }
                if (!empty($arguments['workflows'])) {
                    return $this->softFail('invalid_param', _wp('An all-projects sprint has no workflow selection: it uses every workflow of every project. Omit workflows.'));
                }
                pmMcpSprintHelper::requireAppAdmin(_wp('Only the pm administrator can create an all-projects sprint.'));
                // What the sprint covers, for fill-status validation below.
                $scope_ids = pmMcpSprintHelper::activeProjectIds();
            } else {
                if (!$project_ids) {
                    return $this->softFail('invalid_param', _wp('At least one project is required (or pass all_projects: true).'));
                }
                foreach ($project_ids as $pid) {
                    pmMcpProjectHelper::loadAccessibleProject($pid);
                }
                pmMcpSprintHelper::requireCanEditAll($project_ids, _wp('Creating a sprint requires the sprint.edit permission in every project it spans.'));
                $scope_ids = $project_ids;
            }

            $name = strip_tags($this->argString($arguments, 'name'));
            if ($name === '') {
                return $this->softFail('invalid_param', _wp('Sprint name is required.'));
            }

            $workflow_items = array();
            if (!$all_projects && array_key_exists('workflows', $arguments)) {
                $workflow_items = pmMcpSprintHelper::resolveWorkflowItems($arguments['workflows'], $project_ids, $error);
                if ($workflow_items === null) {
                    return $this->softFail($error[0], $error[1], $error[2]);
                }
            }

            $fill_status_ids = array();
            if (array_key_exists('fill_status_ids', $arguments)) {
                $raw = is_array($arguments['fill_status_ids']) ? $arguments['fill_status_ids'] : array();
                $fill_status_ids = array_values(array_unique(array_map('intval', $raw)));
                $allowed = pmMcpSprintHelper::allowedFillStatusIds($scope_ids, $workflow_items);
                if (!pmMcpSprintHelper::validateFillStatusIds($fill_status_ids, $allowed, $error)) {
                    return $this->softFail($error[0], $error[1], $error[2]);
                }
            }

            $duration = $this->argInt($arguments, 'duration_weeks', self::MIN_DURATION_WEEKS);
            $duration = max(self::MIN_DURATION_WEEKS, min(self::MAX_DURATION_WEEKS, $duration));

            $start_date = $this->argString($arguments, 'start_date');
            $goal = $this->argString($arguments, 'goal');

            $data = array(
                'name'             => $name,
                'goal'             => $goal !== '' ? $goal : null,
                'start_date'       => $start_date !== '' ? $start_date : null,
                'duration'         => $duration,
                'status'           => 'planned',
                'auto_close'       => $this->argBool($arguments, 'auto_close') ? 1 : 0,
                'auto_create_next' => $this->argBool($arguments, 'auto_create_next') ? 1 : 0,
                'move_unfinished'  => $this->argBool($arguments, 'move_unfinished') ? 1 : 0,
                'auto_fill'        => $this->argBool($arguments, 'auto_fill') ? 1 : 0,
            );

            // null project set = "all projects" (no pm_sprint_project rows).
            $sprint_id = pmSprint::save($data, $all_projects ? null : $project_ids, $workflow_items, $fill_status_ids);

            // Matches the pm UI: the activity feed entry lives on the first
            // listed project only, and an all-projects sprint is not logged
            // at all (pmSprints.actions.php::saveOneAction).
            if (!$all_projects) {
                pmTask::log($project_ids[0], null, $this->getUserId(), 'sprint_created', array('sprint_name' => $name));
            }

            $card = pmMcpSprintHelper::cardById($sprint_id, pmMcpProjectHelper::accessibleProjectIds());

            return $this->ok(array(
                'sprint_id' => (int) $sprint_id,
                'sprint'    => $card,
            ));
        });
    }
}
