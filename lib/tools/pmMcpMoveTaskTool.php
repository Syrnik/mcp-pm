<?php

/**
 * pm_move_task — change a task's status via pmTask::moveToStatus(), which
 * enforces task.change_status and the workflow's allowed transitions for the
 * caller's role, and manages completed_datetime. Returns the updated card.
 */
class pmMcpMoveTaskTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_move_task'; }
    public function getRight()       { return 'pm_move_task'; }
    public function getDescription() { return _wp('Move a task to another status, respecting the workflow transitions available to the caller. Requires the task.change_status permission. Returns the updated task card.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('task_id', 'status_id'),
            'properties' => array(
                'task_id'   => array('type' => 'integer', 'minimum' => 1, 'description' => 'Task id.'),
                'status_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Target status id. Must be a permitted transition (see pm_get_task.allowed_statuses).'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $task_id   = $this->argInt($arguments, 'task_id');
            $status_id = $this->argInt($arguments, 'status_id');
            $entity = pmMcpTaskHelper::loadTaskEntity($task_id);

            $entity->moveToStatus($status_id, $this->getUserId());

            return $this->ok(array(
                'task_id'   => $task_id,
                'status_id' => $status_id,
                'task'      => pmMcpTaskHelper::cardById($task_id),
            ));
        });
    }
}
