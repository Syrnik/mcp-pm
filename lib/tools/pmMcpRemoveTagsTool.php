<?php

/**
 * pm_remove_tags — detach tags from a task by name, leaving its other tags and
 * every other field alone. Requires the task.edit permission.
 *
 * The tag itself survives in the project: this unlinks it from one task, it
 * does not delete it. Removing a tag the task does not carry is not an error —
 * the task ends up in the requested state either way, and the names that
 * changed nothing come back in `not_on_task` so a typo is still visible.
 */
class pmMcpRemoveTagsTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_remove_tags'; }
    public function getRight()       { return 'pm_remove_tags'; }
    public function getDescription() { return _wp('Detach tags from a task by name, keeping its other tags. The tag stays in the project. Requires the task.edit permission. Names the task does not carry are reported in not_on_task rather than failing. Returns the task\'s tags after the change.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('task_id', 'tags'),
            'properties' => array(
                'task_id' => self::taskRefSchema('The task to untag.'),
                'tags'    => array(
                    'type'        => 'array',
                    'minItems'    => 1,
                    'items'       => array('type' => 'string', 'minLength' => 1, 'maxLength' => pmMcpTagHelper::NAME_MAX_LENGTH),
                    'description' => 'Tag names to remove. Matching is case-insensitive. Names the task does not carry are ignored and listed in not_on_task.',
                ),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $entity = pmMcpTaskHelper::loadTaskEntity($this->argRef($arguments, 'task_id'), $row);
            $task_id = (int) $row['id'];
            $project_id = (int) $row['project_id'];

            if (!$entity->canEdit($this->getUserId())) {
                return $this->softFail('access_denied', _wp('You do not have permission to edit this task\'s tags.'));
            }

            $names = pmMcpTagHelper::normalizeNames($arguments['tags'] ?? array());
            if (!$names) {
                return $this->softFail('invalid_param', _wp('tags must name at least one tag.'));
            }

            // A name the project never had detaches nothing, exactly like a name
            // the task does not carry — both belong in not_on_task, not in an
            // error the agent has to interpret.
            $resolved = pmMcpTagHelper::resolveExisting($project_id, $names);
            $result = pmMcpTagHelper::applyToTask($row, $resolved['tags'], 'remove', $this->getUserId());

            return $this->ok(array(
                'task_id'     => $task_id,
                'removed'     => $result['removed'],
                'not_on_task' => array_merge($result['not_on_task'], $resolved['unknown']),
                'tags'        => pmMcpTagHelper::forTask($task_id),
            ));
        });
    }
}
