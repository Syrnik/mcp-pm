<?php

/**
 * pm_assign_task — set or clear a task's assignee via pmTask::save(), which
 * enforces the task.assign permission, validates that the assignee is a project
 * participant, logs and notifies. Pass assignee_contact_id = 0 to unassign.
 */
class pmMcpAssignTaskTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_assign_task'; }
    public function getRight()       { return 'pm_assign_task'; }
    public function getDescription() { return _wp('Assign a task to a contact, or unassign it with assignee_contact_id = 0. The assignee must be a project participant. Requires the task.assign permission. Returns the updated task card.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('task_id', 'assignee_contact_id'),
            'properties' => array(
                'task_id'             => self::taskRefSchema('The task to assign.'),
                'assignee_contact_id' => array('type' => 'integer', 'minimum' => 0, 'description' => 'Contact id to assign, or 0 to unassign.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $assignee = $this->argInt($arguments, 'assignee_contact_id');
            $entity = pmMcpTaskHelper::loadTaskEntity($this->argRef($arguments, 'task_id'), $row);
            $task_id = (int) $row['id'];

            $entity->save(array('assignee_contact_id' => $assignee > 0 ? $assignee : null), $this->getUserId());

            return $this->ok(array(
                'task_id'             => $task_id,
                'assignee_contact_id' => $assignee > 0 ? $assignee : null,
                'task'                => pmMcpTaskHelper::cardById($task_id),
            ));
        });
    }
}
