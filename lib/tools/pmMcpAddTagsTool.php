<?php

/**
 * pm_add_tags — attach tags to a task by name, leaving every other field and
 * the tags already on the task alone. Requires the task.edit permission, the
 * same gate the pm UI puts on its tag box.
 *
 * A name the project does not have is refused with the project's tags
 * attached, unless the caller passes create_missing_tags. Use pm_update_task's
 * `tags` field to replace the whole set instead of adding to it.
 */
class pmMcpAddTagsTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_add_tags'; }
    public function getRight()       { return 'pm_add_tags'; }
    public function getDescription() { return _wp('Attach tags to a task by name, keeping the tags it already has. Requires the task.edit permission. A name the project does not have is refused with the available tags listed, unless create_missing_tags is true. Returns the task\'s tags after the change.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('task_id', 'tags'),
            'properties' => array(
                'task_id' => self::taskRefSchema('The task to tag.'),
                'tags'    => array(
                    'type'        => 'array',
                    'minItems'    => 1,
                    'items'       => array('type' => 'string', 'minLength' => 1, 'maxLength' => pmMcpTagHelper::NAME_MAX_LENGTH),
                    'description' => 'Tag names to add. Matching is case-insensitive; tags already on the task are left as they are.',
                ),
                'create_missing_tags' => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Create tags the project does not have yet. Default false: an unknown name is refused with the project\'s available_tags instead, so a typo does not become a new tag.',
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
                return $this->softFail('invalid_param', _wp('tags must name at least one tag. Use pm_remove_tags to detach tags.'));
            }

            // Check before writing anything: an unknown name must not leave
            // half the batch attached.
            $create_missing = $this->argBool($arguments, 'create_missing_tags');
            if (!$create_missing) {
                $missing = pmMcpTagHelper::missingNames($project_id, $names);
                if ($missing) {
                    $failure = pmMcpTagHelper::missingTagsFailure($project_id, $missing);
                    return $this->softFail('invalid_param', $failure['message'], $failure['extra']);
                }
            }

            $tags = pmMcpTagHelper::resolveOrCreate($project_id, $names, $create_missing);
            $result = pmMcpTagHelper::applyToTask($row, $tags, 'add', $this->getUserId());

            return $this->ok(array(
                'task_id' => $task_id,
                'added'   => $result['added'],
                'tags'    => pmMcpTagHelper::forTask($task_id),
            ));
        });
    }
}
