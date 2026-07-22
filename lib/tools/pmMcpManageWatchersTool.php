<?php

/**
 * pm_manage_watchers — add or remove a watcher on a task via
 * pmTask::addParticipant()/removeParticipant(). Managing another contact needs
 * the task.manage_watchers permission; a contact may always remove themselves
 * (mirrors the pm REST participant API). Assignees are managed by
 * pm_assign_task, not here. Returns the task's participants after the change.
 */
class pmMcpManageWatchersTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_manage_watchers'; }
    public function getRight()       { return 'pm_manage_watchers'; }
    public function getDescription() { return _wp('Add or remove a task watcher. Managing other contacts requires the task.manage_watchers permission; a user may always remove themselves. Returns the task participants.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('task_id', 'action', 'contact_id'),
            'properties' => array(
                'task_id'    => array('type' => 'integer', 'minimum' => 1, 'description' => 'Task id.'),
                'action'     => array('type' => 'string', 'enum' => array('add', 'remove'), 'description' => 'Add or remove a watcher.'),
                'contact_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Watcher contact id.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $task_id    = $this->argInt($arguments, 'task_id');
            $action     = $this->argString($arguments, 'action');
            $contact_id = $this->argInt($arguments, 'contact_id');
            if ($contact_id <= 0) {
                return $this->softFail('invalid_param', _wp('contact_id is required.'));
            }

            $entity = pmMcpTaskHelper::loadTaskEntity($task_id, $row);
            $me = $this->getUserId();

            $participant_model = new pmTaskParticipantModel();
            $participants = $participant_model->getByTask($task_id);

            $is_watcher = false;
            foreach ($participants['watchers'] as $w) {
                if ((int) $w['contact_id'] === $contact_id) {
                    $is_watcher = true;
                    break;
                }
            }

            // Permission: managing someone else requires task.manage_watchers.
            // Self-service (adding/removing yourself) is always allowed.
            if ($contact_id !== $me) {
                $role = pmHelper::getContactRole($me, (int) $row['project_id']);
                if (!pmHelper::hasPermission($role, 'task.manage_watchers', $row['type_slug'] ?? null)) {
                    return $this->softFail('access_denied', _wp('You do not have permission to manage watchers.'));
                }
            }

            if ($action === 'add') {
                if (!$is_watcher) {
                    $entity->addParticipant($contact_id, 'watcher', $me);
                }
            } else { // remove
                // Guard: don't let a watcher-remove silently drop an assignee.
                if (!$is_watcher) {
                    $is_assignee = false;
                    foreach ($participants['assignees'] as $a) {
                        if ((int) $a['contact_id'] === $contact_id) {
                            $is_assignee = true;
                            break;
                        }
                    }
                    if ($is_assignee) {
                        return $this->softFail('invalid_param', _wp('That contact is an assignee, not a watcher. Use pm_assign_task.'));
                    }
                    return $this->softFail('not_found', _wp('That contact is not a watcher on this task.'));
                }
                $entity->removeParticipant($contact_id, $me);
            }

            return $this->ok(array(
                'task_id'      => $task_id,
                'action'       => $action,
                'participants' => $participant_model->getByTask($task_id),
            ));
        });
    }
}
