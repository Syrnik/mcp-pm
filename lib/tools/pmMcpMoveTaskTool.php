<?php

/**
 * pm_move_task — change a task's status via pmTask::moveToStatus(), which
 * enforces task.change_status and the workflow's allowed transitions for the
 * caller's role, and manages completed_datetime. Returns the updated card.
 *
 * Activity log
 * ------------
 * moveToStatus() updates the row and notifies, but — unlike pmTask::save(),
 * the path pm's own UI takes — writes no `status_changed` entry, so a move made
 * through this tool would leave no trace in the task's history. The tool logs
 * it, in the same shape pm's history renderer expects (from_id / to_id /
 * from_name / to_name). See pmMcpManageChecklistTool for the same pattern.
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
                'task_id'   => self::taskRefSchema('The task to move.'),
                'status_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Target status id. Must be a permitted transition (see pm_get_task.allowed_statuses).'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $status_id = $this->argInt($arguments, 'status_id');
            $entity = pmMcpTaskHelper::loadTaskEntity($this->argRef($arguments, 'task_id'), $row);
            $task_id = (int) $row['id'];
            $from_status_id = (int) $row['status_id'];

            // High-water mark of the activity log, so the entry written below
            // can be skipped should a future pm version log the move itself.
            $log_model = new pmActivityLogModel();
            $log_mark = (int) $log_model->select('MAX(id)')->fetchField();

            $entity->moveToStatus($status_id, $this->getUserId());

            if ($status_id !== $from_status_id) {
                $this->logStatusChange($log_model, $log_mark, $row, $from_status_id, $status_id);
            }

            return $this->ok(array(
                'task_id'   => $task_id,
                'status_id' => $status_id,
                'task'      => pmMcpTaskHelper::cardById($task_id),
            ));
        });
    }

    /**
     * Record the move in the task's history, unless the domain already did.
     *
     * @param pmActivityLogModel $log_model
     * @param int   $log_mark        Largest log id seen before the move.
     * @param array $row             The pm_task row as it was before the move.
     * @param int   $from_status_id
     * @param int   $to_status_id
     */
    protected function logStatusChange(pmActivityLogModel $log_model, $log_mark, array $row, $from_status_id, $to_status_id)
    {
        $logged = $log_model->select('id')
            ->where("task_id = i:task AND action = 'status_changed' AND id > i:mark", array(
                'task' => (int) $row['id'],
                'mark' => $log_mark,
            ))
            ->limit(1)
            ->fetchField();
        if ($logged) {
            return;
        }

        $status_model = new pmStatusModel();
        $from = $status_model->getById($from_status_id);
        $to = $status_model->getById($to_status_id);

        pmTask::log((int) $row['project_id'], (int) $row['id'], $this->getUserId(), 'status_changed', array(
            'from_id'   => $from['id'] ?? 0,
            'to_id'     => $to['id'] ?? 0,
            'from_name' => $from['name'] ?? '',
            'to_name'   => $to['name'] ?? '',
        ));
    }
}
