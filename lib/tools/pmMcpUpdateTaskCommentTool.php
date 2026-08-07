<?php

/**
 * pm_update_task_comment — edit the text of an existing comment. Mirrors the
 * backend's pmCommentActions::editAction(): only the comment's own author may
 * edit it, with no override for project admins/managers or app-admins. Project
 * membership is still required to name the task in the first place.
 */
class pmMcpUpdateTaskCommentTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_update_task_comment'; }
    public function getRight()       { return 'pm_update_task_comment'; }
    public function getDescription() { return _wp('Edit the text of a comment on a task. Only the comment\'s own author may edit it. Returns the updated comment.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('task_id', 'comment_id', 'text'),
            'properties' => array(
                'task_id'    => self::taskRefSchema('The task the comment belongs to.'),
                'comment_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'The comment to edit.'),
                'text'       => array('type' => 'string', 'minLength' => 1, 'description' => 'New comment text.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $text = $this->argString($arguments, 'text');
            if ($text === '') {
                return $this->softFail('invalid_param', _wp('Comment text is required.'));
            }

            $task = pmMcpTaskHelper::loadAccessibleTask($this->argRef($arguments, 'task_id'));
            $task_id = (int) $task['id'];

            $comment_id = $this->argInt($arguments, 'comment_id');
            $comment_model = new pmCommentModel();
            $comment = $comment_model->getById($comment_id);
            if (!$comment || (int) $comment['task_id'] !== $task_id) {
                return $this->softFail('not_found', _wp('Comment not found on this task.'));
            }

            if ((int) $comment['contact_id'] !== $this->getUserId()) {
                return $this->softFail('access_denied', _wp('You can only edit your own comments.'));
            }

            $comment_model->updateById($comment_id, array(
                'text'            => $text,
                'update_datetime' => date('Y-m-d H:i:s'),
            ));

            $updated = $comment_model->query(
                "SELECT c.*, wc.name AS contact_name, wc.photo AS contact_photo
                 FROM pm_comment c
                 LEFT JOIN wa_contact wc ON c.contact_id = wc.id
                 WHERE c.id = i:id",
                array('id' => $comment_id)
            )->fetchAssoc();

            return $this->ok(array(
                'comment' => array(
                    'id'              => (int) $updated['id'],
                    'task_id'         => $task_id,
                    'contact_id'      => (int) $updated['contact_id'],
                    'contact_name'    => $updated['contact_name'] ?? '',
                    'text'            => $updated['text'],
                    'is_internal'     => (bool) $updated['is_internal'],
                    'create_datetime' => $updated['create_datetime'] ?? null,
                    'update_datetime' => $updated['update_datetime'] ?? null,
                ),
            ));
        });
    }
}
