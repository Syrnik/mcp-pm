<?php

/**
 * pm_create_task — create a task in a project via pmTask::create() (which
 * enforces task.create, validates every referenced entity, logs the activity
 * and fires notifications). Returns the new task's full card.
 */
class pmMcpCreateTaskTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_create_task'; }
    public function getRight()       { return 'pm_create_task'; }
    public function getDescription() { return _wp('Create a task in a project. Requires project membership with the task.create permission. workflow_id is required unless the project has exactly one workflow; assignee, milestone and sprint are optional — omit them (or pass 0) to leave them empty. Returns the created task card.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('project_id', 'subject'),
            'properties' => array(
                'project_id'          => array('type' => 'integer', 'minimum' => 1, 'description' => 'Target project id.'),
                'subject'             => array('type' => 'string', 'minLength' => 1, 'description' => 'Task subject.'),
                'description'         => array('type' => 'string', 'description' => 'Task description (project content format, usually Markdown).'),
                'workflow_id'         => array('type' => 'string', 'description' => 'Workflow slug. Optional when the project has a single workflow.'),
                'status_id'           => array('type' => 'integer', 'minimum' => 1, 'description' => 'Initial status id. Defaults to the first status when omitted.'),
                'priority'            => array('type' => 'string', 'description' => 'Priority slug (e.g. low, normal, high). Defaults to normal.'),
                'type_slug'           => array('type' => 'string', 'description' => 'Task type slug.'),
                'assignee_contact_id' => array('type' => 'integer', 'minimum' => 0, 'description' => 'Assignee contact id (must be a project participant). Optional: omit or 0 leaves the task unassigned.'),
                'milestone_id'        => array('type' => 'integer', 'minimum' => 0, 'description' => 'Milestone id (must belong to the project). Optional: omit or 0 for no milestone.'),
                'sprint_id'           => array('type' => 'integer', 'minimum' => 0, 'description' => 'Sprint id (must belong to the project). Optional: omit or 0 puts the task in the backlog.'),
                'parent_id'           => self::taskRefSchema('Parent task for a subtask (same project). Optional: omit or 0 for a top-level task.'),
                'start_date'          => array('type' => 'string', 'description' => 'Start date (YYYY-MM-DD).'),
                'due_date'            => array('type' => 'string', 'description' => 'Due date (YYYY-MM-DD).'),
                'deadline'            => array('type' => 'string', 'description' => 'Hard deadline (YYYY-MM-DD).'),
                'estimated_hours'     => array('type' => 'number', 'minimum' => 0, 'description' => 'Estimated hours.'),
                'progress'            => array('type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'description' => 'Progress percent (0-100).'),
                'custom_fields'       => array('type' => 'object', 'description' => 'Map of custom field id => value.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $project_id = $this->argInt($arguments, 'project_id');
            pmMcpProjectHelper::loadAccessibleProject($project_id);
            pmMcpTaskHelper::requireProjectRole($project_id);

            $subject = $this->argString($arguments, 'subject');
            if ($subject === '') {
                return $this->softFail('invalid_param', _wp('Task subject is required.'));
            }

            // Resolve the workflow: required, but auto-selected when the project
            // has exactly one.
            $workflow_id = $this->argString($arguments, 'workflow_id');
            if ($workflow_id === '') {
                $project_wfs = (new pmProjectModel())->getWorkflows($project_id);
                if (count($project_wfs) === 1) {
                    $workflow_id = reset($project_wfs);
                } else {
                    return $this->softFail(
                        'invalid_param',
                        _wp('workflow_id is required (the project has multiple workflows).'),
                        array('available_workflows' => array_values($project_wfs))
                    );
                }
            }

            $data = array(
                'project_id'          => $project_id,
                'subject'             => $subject,
                'description'         => $this->argString($arguments, 'description'),
                'workflow_id'         => $workflow_id,
                'priority'            => $this->argString($arguments, 'priority', 'normal'),
                'status_id'           => $this->argInt($arguments, 'status_id'),
                'progress'            => $this->argInt($arguments, 'progress'),
            );
            foreach (array('type_slug', 'start_date', 'due_date', 'deadline') as $f) {
                $v = $this->argString($arguments, $f);
                if ($v !== '') {
                    $data[$f] = $v;
                }
            }
            foreach (array('assignee_contact_id', 'milestone_id', 'sprint_id') as $f) {
                $v = $this->argInt($arguments, $f);
                $data[$f] = $v > 0 ? $v : null;
            }
            // The parent may be quoted as a full number; resolving it here also
            // rejects a parent the caller cannot see before pmTask::create()
            // does its own same-project check.
            $parent_ref = $this->argRef($arguments, 'parent_id');
            $data['parent_id'] = ($parent_ref === '' || $parent_ref === '0')
                ? null
                : (int) pmMcpTaskHelper::loadAccessibleTask($parent_ref)['id'];
            if (isset($arguments['estimated_hours']) && $arguments['estimated_hours'] !== '') {
                $data['estimated_hours'] = (float) $arguments['estimated_hours'];
            }
            if (!empty($arguments['custom_fields']) && is_array($arguments['custom_fields'])) {
                $data['_custom_fields'] = $arguments['custom_fields'];
            }

            // Report every reference that does not fit the project in one
            // answer, with the project's own options — pmTask::create() would
            // otherwise reject them one per call and name no alternative.
            $ref_problem = pmMcpTaskHelper::checkProjectRefs($project_id, $data);
            if ($ref_problem !== null) {
                return $this->softFail('invalid_param', $ref_problem['message'], $ref_problem['extra']);
            }

            $task_id = pmTask::create($data, $this->getUserId());

            return $this->ok(array(
                'task_id' => (int) $task_id,
                'task'    => pmMcpTaskHelper::cardById($task_id),
            ));
        });
    }
}
