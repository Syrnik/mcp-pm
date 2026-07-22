<?php

/**
 * pm_manage_checklist — add, rename, complete/uncomplete or delete a checklist
 * item on a task. All mutations require the task.edit permission (mirrors the
 * pm REST checklist API). Returns the task's checklist after the change.
 */
class pmMcpManageChecklistTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_manage_checklist'; }
    public function getRight()       { return 'pm_manage_checklist'; }
    public function getDescription() { return _wp('Manage a task checklist: add, rename, complete, uncomplete or delete an item. Requires the task.edit permission. Returns the updated checklist.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('task_id', 'action'),
            'properties' => array(
                'task_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Task id.'),
                'action'  => array(
                    'type'        => 'string',
                    'enum'        => array('add', 'update', 'complete', 'uncomplete', 'delete'),
                    'description' => 'add (needs text), update (needs item_id + text), complete/uncomplete/delete (need item_id).',
                ),
                'item_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Checklist item id (for update/complete/uncomplete/delete).'),
                'text'    => array('type' => 'string', 'minLength' => 1, 'description' => 'Item text (for add/update).'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $task_id = $this->argInt($arguments, 'task_id');
            $action  = $this->argString($arguments, 'action');
            $entity = pmMcpTaskHelper::loadTaskEntity($task_id, $row);

            if (!$entity->canEdit($this->getUserId())) {
                return $this->softFail('access_denied', _wp('You do not have permission to edit this task\'s checklist.'));
            }

            $model = new pmChecklistModel();

            // Item-scoped actions: load the item and confirm it belongs to this task.
            $item = null;
            if (in_array($action, array('update', 'complete', 'uncomplete', 'delete'), true)) {
                $item_id = $this->argInt($arguments, 'item_id');
                if ($item_id <= 0) {
                    return $this->softFail('invalid_param', _wp('item_id is required for this action.'));
                }
                $item = $model->getById($item_id);
                if (!$item || (int) $item['task_id'] !== $task_id) {
                    return $this->softFail('not_found', _wp('Checklist item not found on this task.'));
                }
            }

            switch ($action) {
                case 'add':
                    $text = $this->argString($arguments, 'text');
                    if ($text === '') {
                        return $this->softFail('invalid_param', _wp('text is required to add a checklist item.'));
                    }
                    $new_id = $model->add($task_id, $text);
                    pmTask::log($row['project_id'], $task_id, $this->getUserId(), 'checklist_item_added', array('item_text' => $text));
                    break;

                case 'update':
                    $text = $this->argString($arguments, 'text');
                    if ($text === '') {
                        return $this->softFail('invalid_param', _wp('text is required to update a checklist item.'));
                    }
                    $model->updateText($item['id'], $text);
                    break;

                case 'complete':
                    if (empty($item['is_completed'])) {
                        $model->toggle($item['id'], $this->getUserId());
                    }
                    break;

                case 'uncomplete':
                    if (!empty($item['is_completed'])) {
                        $model->toggle($item['id'], $this->getUserId());
                    }
                    break;

                case 'delete':
                    $model->deleteById($item['id']);
                    break;
            }

            // Re-serialise the checklist (same shape as pm_get_task.checklist).
            $checklist = $model->getByTask($task_id);
            foreach ($checklist as &$ci) {
                $ci['id']           = (int) $ci['id'];
                $ci['task_id']      = (int) $ci['task_id'];
                $ci['is_completed'] = (int) $ci['is_completed'];
                $ci['sort']         = (float) $ci['sort'];
            }
            unset($ci);

            $result = array(
                'task_id'   => $task_id,
                'action'    => $action,
                'checklist' => $checklist,
            );
            if ($action === 'add') {
                $result['item_id'] = (int) $new_id;
            }
            return $this->ok($result);
        });
    }
}
