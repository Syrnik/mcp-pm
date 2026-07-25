<?php

/**
 * pm_list_task_comments — comments on a task, oldest first, decorated with the
 * author's name and photo. Requires membership of the task's project.
 */
class pmMcpListTaskCommentsTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_list_task_comments'; }
    public function getRight()       { return 'pm_list_task_comments'; }
    public function getDescription() { return _wp('List the comments of a task, oldest first, with author name and the internal flag. Requires membership of the task\'s project.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('task_id'),
            'properties' => array(
                'task_id' => self::taskRefSchema('The task whose comments to list.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $task = pmMcpTaskHelper::loadAccessibleTask($this->argRef($arguments, 'task_id'));

            $comments = array();
            foreach ((new pmCommentModel())->getByTask((int) $task['id']) as $c) {
                $comments[] = array(
                    'id'              => (int) $c['id'],
                    'contact_id'      => (int) $c['contact_id'],
                    'contact_name'    => $c['contact_name'] ?? '',
                    'contact_photo'   => $c['contact_photo'] ?? null,
                    'text'            => $c['text'],
                    'is_internal'     => (bool) $c['is_internal'],
                    'create_datetime' => $c['create_datetime'],
                    'update_datetime' => $c['update_datetime'] ?? null,
                );
            }

            return $this->ok(array(
                'task_id'     => (int) $task['id'],
                'full_number' => pmMcpTaskHelper::formatNumber((int) $task['id'], (int) $task['project_id']),
                'comments'    => $comments,
                'count'       => count($comments),
            ));
        });
    }
}
