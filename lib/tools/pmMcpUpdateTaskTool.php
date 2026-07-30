<?php

/**
 * pm_update_task — partial update of task fields via pmTask::save() (which
 * enforces per-field permissions — edit / assign / set_dates — validates
 * references, logs changes and notifies). Status changes go through
 * pm_move_task, not here. Returns the updated task card.
 *
 * Nullable references (assignee_contact_id, milestone_id, sprint_id, parent_id)
 * accept 0 to clear the field (unassign / backlog / no milestone / detach).
 */
class pmMcpUpdateTaskTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_update_task'; }
    public function getRight()       { return 'pm_update_task'; }
    public function getDescription() { return _wp('Update task fields (subject, description, priority, type, assignee, dates, milestone, sprint, parent, estimate, progress, custom fields). Pass 0 for assignee/milestone/sprint/parent to clear them. Use pm_move_task to change status. Returns the updated task card.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('task_id'),
            'properties' => array(
                'task_id'             => self::taskRefSchema('The task to update.'),
                'subject'             => array('type' => 'string', 'minLength' => 1, 'description' => 'New subject.'),
                'description'         => array('type' => 'string', 'description' => 'New description.'),
                'priority'            => array('type' => 'string', 'description' => 'New priority slug.'),
                'type_slug'           => array('type' => 'string', 'description' => 'New task type slug.'),
                'assignee_contact_id' => array('type' => 'integer', 'minimum' => 0, 'description' => 'New assignee (0 to unassign).'),
                'milestone_id'        => array('type' => 'integer', 'minimum' => 0, 'description' => 'New milestone (0 to clear).'),
                'sprint_id'           => array('type' => 'integer', 'minimum' => 0, 'description' => 'New sprint (0 for backlog).'),
                'parent_id'           => self::taskRefSchema('New parent task, or 0 to detach.'),
                'start_date'          => array('type' => 'string', 'description' => 'Start date (YYYY-MM-DD, empty to clear).'),
                'due_date'            => array('type' => 'string', 'description' => 'Due date (YYYY-MM-DD, empty to clear).'),
                'deadline'            => array('type' => 'string', 'description' => 'Deadline (YYYY-MM-DD, empty to clear).'),
                'estimated_hours'     => array('type' => 'number', 'minimum' => 0, 'description' => 'Estimated hours.'),
                'progress'            => array('type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'description' => 'Progress percent (0-100).'),
                'custom_fields'       => array('type' => 'object', 'description' => 'Map of custom field id => value.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $entity = pmMcpTaskHelper::loadTaskEntity($this->argRef($arguments, 'task_id'), $row);
            $task_id = (int) $row['id'];

            $data = array();

            foreach (array('subject', 'description', 'priority', 'type_slug', 'start_date', 'due_date', 'deadline') as $f) {
                if (array_key_exists($f, $arguments)) {
                    $data[$f] = (string) $arguments[$f];
                }
            }
            // Nullable foreign keys: 0 clears (save() writes NULL for null values).
            foreach (array('assignee_contact_id', 'milestone_id', 'sprint_id') as $f) {
                if (array_key_exists($f, $arguments) && $arguments[$f] !== '') {
                    $v = (int) $arguments[$f];
                    $data[$f] = $v > 0 ? $v : null;
                }
            }
            // The parent may be quoted as a full number, so it cannot go
            // through the plain (int) cast above — that would read "AUTH-31"
            // as 0 and silently detach the task instead of re-parenting it.
            if (array_key_exists('parent_id', $arguments) && $arguments['parent_id'] !== '') {
                $parent_ref = $this->argRef($arguments, 'parent_id');
                $data['parent_id'] = ($parent_ref === '' || $parent_ref === '0')
                    ? null
                    : (int) pmMcpTaskHelper::loadAccessibleTask($parent_ref)['id'];
            }
            if (array_key_exists('progress', $arguments) && $arguments['progress'] !== '') {
                $data['progress'] = (int) $arguments['progress'];
            }
            if (array_key_exists('estimated_hours', $arguments) && $arguments['estimated_hours'] !== '') {
                $data['estimated_hours'] = (float) $arguments['estimated_hours'];
            }
            if (!empty($arguments['custom_fields']) && is_array($arguments['custom_fields'])) {
                $data['_custom_fields'] = $arguments['custom_fields'];
            }

            if (!$data) {
                return $this->softFail('invalid_param', _wp('No fields to update.'));
            }

            // Same one-shot reference report as pm_create_task: save() rejects
            // a foreign milestone / sprint / assignee one per call, naming no
            // alternative.
            $ref_problem = pmMcpTaskHelper::checkProjectRefs((int) $row['project_id'], $data);
            if ($ref_problem !== null) {
                return $this->softFail('invalid_param', $ref_problem['message'], $ref_problem['extra']);
            }

            $entity->save($data, $this->getUserId());

            return $this->ok(array(
                'task_id' => $task_id,
                'task'    => pmMcpTaskHelper::cardById($task_id),
            ));
        });
    }
}
