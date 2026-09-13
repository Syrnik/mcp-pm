<?php

/**
 * pm_create_sprint — create a sprint spanning one or more projects, with its
 * per-project workflow subset and auto-fill statuses. New sprints are always
 * created "planned"; use pm_manage_sprint to activate one. Requires the
 * sprint.edit permission in at least one of the given projects, and
 * membership in every one of them (a caller cannot attach a project it
 * cannot access, unlike pm's own saveOneAction).
 */
class pmMcpCreateSprintTool extends pmMcpToolBase
{
    const MIN_DURATION_WEEKS = 1;
    const MAX_DURATION_WEEKS = 52;

    public function getName()        { return 'pm_create_sprint'; }
    public function getRight()       { return 'pm_create_sprint'; }
    public function getDescription() { return _wp('Create a sprint spanning one or more projects, with a per-project workflow subset and auto-fill statuses. Always created "planned" — use pm_manage_sprint to activate it. Requires the sprint.edit permission in at least one project and membership in every project listed. Returns the created sprint card.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('project_ids', 'name'),
            'properties' => array(
                'project_ids' => array(
                    'type'        => 'array',
                    'minItems'    => 1,
                    'uniqueItems' => true,
                    'items'       => array('type' => 'integer', 'minimum' => 1),
                    'description' => 'Projects this sprint spans. A sprint may cover several projects; you must be a member of every one you list.',
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
            $project_ids_raw = is_array($arguments['project_ids'] ?? null) ? $arguments['project_ids'] : array();
            $project_ids = array_values(array_unique(array_filter(array_map('intval', $project_ids_raw), function ($id) {
                return $id > 0;
            })));
            if (!$project_ids) {
                return $this->softFail('invalid_param', _wp('At least one project is required.'));
            }

            foreach ($project_ids as $pid) {
                pmMcpProjectHelper::loadAccessibleProject($pid);
            }
            pmMcpSprintHelper::requireCanEdit($project_ids);

            $name = strip_tags($this->argString($arguments, 'name'));
            if ($name === '') {
                return $this->softFail('invalid_param', _wp('Sprint name is required.'));
            }

            $workflow_items = array();
            if (array_key_exists('workflows', $arguments)) {
                $workflow_items = pmMcpSprintHelper::resolveWorkflowItems($arguments['workflows'], $project_ids, $error);
                if ($workflow_items === null) {
                    return $this->softFail($error[0], $error[1], $error[2]);
                }
            }

            $fill_status_ids = array();
            if (array_key_exists('fill_status_ids', $arguments)) {
                $raw = is_array($arguments['fill_status_ids']) ? $arguments['fill_status_ids'] : array();
                $fill_status_ids = array_values(array_unique(array_map('intval', $raw)));
                $allowed = pmMcpSprintHelper::allowedFillStatusIds($project_ids, $workflow_items);
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

            $sprint_id = pmSprint::save($data, $project_ids, $workflow_items, $fill_status_ids);

            // Matches the pm UI: the activity feed entry lives on the first
            // listed project only (pmSprints.actions.php::saveOneAction).
            pmTask::log($project_ids[0], null, $this->getUserId(), 'sprint_created', array('sprint_name' => $name));

            $card = pmMcpSprintHelper::cardById($sprint_id, pmMcpProjectHelper::accessibleProjectIds());

            return $this->ok(array(
                'sprint_id' => (int) $sprint_id,
                'sprint'    => $card,
            ));
        });
    }
}
