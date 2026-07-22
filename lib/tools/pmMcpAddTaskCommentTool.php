<?php

/**
 * pm_add_task_comment — add a comment via pmTask::addComment() (logs and fires
 * the comment_added notification). Readonly project roles are rejected, matching
 * the pm REST API. The is_internal flag is applied after insert, since the
 * domain method always creates public comments.
 */
class pmMcpAddTaskCommentTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_add_task_comment'; }
    public function getRight()       { return 'pm_add_task_comment'; }
    public function getDescription() { return _wp('Add a comment to a task. Requires a non-readonly project role. Set is_internal=true for an internal (staff-only) note. Returns the created comment.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('task_id', 'text'),
            'properties' => array(
                'task_id'     => array('type' => 'integer', 'minimum' => 1, 'description' => 'Task id.'),
                'text'        => array('type' => 'string', 'minLength' => 1, 'description' => 'Comment text.'),
                'is_internal' => array('type' => 'boolean', 'description' => 'Mark the comment as internal. Defaults to false.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $task_id = $this->argInt($arguments, 'task_id');
            $text    = $this->argString($arguments, 'text');
            if ($text === '') {
                return $this->softFail('invalid_param', _wp('Comment text is required.'));
            }

            $entity = pmMcpTaskHelper::loadTaskEntity($task_id, $row);

            // A project role is required to comment. View-only roles cannot.
            // NOTE: the role config's `readonly` flag marks a *system* role
            // whose permissions are immutable — it is set on both `viewer`
            // (genuinely read-only) and `admin` (full access), so it cannot be
            // used alone to gate commenting. Admins always may; other roles are
            // gated by the domain's canComment() (task.view based).
            $role = pmMcpTaskHelper::requireProjectRole((int) $row['project_id']);
            $is_admin = $role === 'admin' || wa()->getUser()->isAdmin();
            if (!$is_admin) {
                $roles = (new pmRoleModel())->getAllRoles();
                if (!empty($roles[$role]['readonly']) || !$entity->canComment($this->getUserId())) {
                    return $this->softFail('access_denied', _wp('Your project role cannot add comments.'));
                }
            }

            $comment = $entity->addComment($text, $this->getUserId());

            $is_internal = !empty($arguments['is_internal']) && $arguments['is_internal'] === true;
            if ($is_internal) {
                (new pmCommentModel())->updateById((int) $comment['id'], array('is_internal' => 1));
            }

            return $this->ok(array(
                'comment' => array(
                    'id'              => (int) $comment['id'],
                    'task_id'         => $task_id,
                    'contact_id'      => (int) $comment['contact_id'],
                    'contact_name'    => $comment['contact_name'] ?? '',
                    'text'            => $comment['text'],
                    'is_internal'     => $is_internal,
                    'create_datetime' => $comment['create_datetime'] ?? null,
                ),
            ));
        });
    }
}
