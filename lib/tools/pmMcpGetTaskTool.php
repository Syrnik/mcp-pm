<?php

/**
 * pm_get_task — full task card: base fields, description, tags, subtasks,
 * milestone, participants, checklist, dependencies, custom fields, logged time
 * and the workflow transitions available to the current user.
 */
class pmMcpGetTaskTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_get_task'; }
    public function getRight()       { return 'pm_get_task'; }
    public function getDescription() { return _wp('Get a full task card by id or full task number (AUTH-32): description, tags, subtasks, milestone, participants, checklist, dependencies, custom fields, logged time and allowed status transitions. Requires membership of the task\'s project.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('task_id'),
            'properties' => array(
                'task_id' => self::taskRefSchema('The task to read.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $task = pmMcpTaskHelper::loadAccessibleTask($this->argRef($arguments, 'task_id'));

            return $this->ok(array(
                'task' => pmMcpTaskHelper::formatTaskCard($task),
            ));
        });
    }
}
