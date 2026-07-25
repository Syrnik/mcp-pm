<?php

/**
 * pm_delete_task — permanently delete a task and its dependents (subtasks,
 * tags, comments, custom fields, external links) via pmTask::deleteWithCleanup(),
 * which enforces the task.delete permission. Destructive: requires confirm=true.
 */
class pmMcpDeleteTaskTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_delete_task'; }
    public function getRight()       { return 'pm_delete_task'; }
    public function getDescription() { return _wp('Permanently delete a task and its subtasks, comments, tags and custom fields. Requires the task.delete permission and explicit confirm=true. This cannot be undone.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('task_id', 'confirm'),
            'properties' => array(
                'task_id' => self::taskRefSchema('The task to delete.'),
                'confirm' => array('type' => 'boolean', 'description' => 'Must be true to proceed with the irreversible delete.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            if (!$this->assertConfirm($arguments, $error)) {
                return $error;
            }

            $entity = pmMcpTaskHelper::loadTaskEntity($this->argRef($arguments, 'task_id'), $row);
            $task_id = (int) $row['id'];
            $full_number = pmMcpTaskHelper::formatNumber($task_id, (int) $row['project_id']);

            $entity->deleteWithCleanup($this->getUserId());

            return $this->ok(array(
                'task_id'     => $task_id,
                'full_number' => $full_number,
                'deleted'     => true,
            ));
        });
    }
}
