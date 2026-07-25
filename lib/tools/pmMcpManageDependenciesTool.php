<?php

/**
 * pm_manage_dependencies — link two tasks or unlink them.
 *
 * The relation is described from the point of view of `task_id`: it depends on,
 * blocks, duplicates or relates to `related_task_id`. pm stores that as a single
 * `pm_task_dependency` row whose direction the relation dictates, and both
 * cards read it: "A blocks B" on A is the very same record as "B depends on A"
 * on B. The response returns both sides so the mutuality is visible without a
 * second call.
 *
 * One relation per pair: if the tasks are already linked, an identical request
 * is idempotent (already_exists) and a different relation is refused as a
 * conflict rather than stacked on top. Mirrors pmTaskAction's dependencyAdd /
 * dependencyDelete, plus the task.edit check the app performs client-side.
 */
class pmMcpManageDependenciesTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_manage_dependencies'; }
    public function getRight()       { return 'pm_manage_dependencies'; }
    public function getDescription() { return _wp('Link or unlink two tasks. The relation is stated from task_id\'s side (depends_on, blocks, duplicates, relates_to) and is stored once, so it shows on both cards with the inverse wording. Requires the task.edit permission. Returns the relations of both tasks.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('task_id', 'action'),
            'properties' => array(
                'task_id'         => self::taskRefSchema('The task whose relations to manage; the relation is worded from its point of view.'),
                'action'          => array(
                    'type'        => 'string',
                    'enum'        => array('add', 'remove'),
                    'description' => 'add (needs related_task_id + relation), remove (needs related_task_id or dependency_id).',
                ),
                'related_task_id' => self::taskRefSchema('The task on the other end of the relation.'),
                'relation'        => array(
                    'type'        => 'string',
                    'enum'        => pmMcpDependencyHelper::relations(),
                    'description' => 'How task_id relates to related_task_id: depends_on (related task must finish first), blocks (the reverse), duplicates, relates_to. Required for add; optional filter for remove.',
                ),
                'type'            => array(
                    'type'        => 'string',
                    'enum'        => pmMcpDependencyHelper::DIRECTED_TYPES,
                    'description' => 'Scheduling type of a depends_on/blocks relation: FS (finish-to-start, default), SS, FF, SF. Ignored for duplicates/relates_to.',
                ),
                'dependency_id'   => array('type' => 'integer', 'minimum' => 1, 'description' => 'Existing relation id (from pm_get_task.dependencies) to remove.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $action = $this->argString($arguments, 'action');

            $entity = pmMcpTaskHelper::loadTaskEntity($this->argRef($arguments, 'task_id'), $row);
            $task_id = (int) $row['id'];

            return $action === 'add'
                ? $this->add($arguments, $entity, $row, $task_id)
                : $this->remove($arguments, $entity, $row, $task_id);
        });
    }

    /**
     * Create the one row that stores the relation, in the direction the
     * requested relation implies.
     */
    protected function add(array $arguments, pmTask $entity, array $row, $task_id)
    {
        $relation = $this->argString($arguments, 'relation');
        if (!in_array($relation, pmMcpDependencyHelper::relations(), true)) {
            return $this->softFail('invalid_param', sprintf(
                _wp('relation is required to add a relation. Use one of: %s.'),
                implode(', ', pmMcpDependencyHelper::relations())
            ));
        }

        $related_ref = $this->argRef($arguments, 'related_task_id');
        if ($related_ref === '') {
            return $this->softFail('invalid_param', _wp('related_task_id is required to add a relation.'));
        }
        $related = pmMcpTaskHelper::loadAccessibleTask($related_ref);
        $related_task_id = (int) $related['id'];

        if ($related_task_id === $task_id) {
            return $this->softFail('invalid_param', _wp('A task cannot be related to itself.'));
        }

        $type = pmMcpDependencyHelper::normalizeType($this->argString($arguments, 'type'));
        $target = pmMcpDependencyHelper::rowFor($relation, $task_id, $related_task_id, $type);

        // One relation per pair: an identical request is a no-op, a different
        // one has to be removed first rather than stacked on top of this one.
        foreach (pmMcpDependencyHelper::findBetween($task_id, $related_task_id) as $existing) {
            $described = pmMcpDependencyHelper::describe($existing, $task_id);
            if ($described['relation'] === $relation && $described['type'] === $target['type']) {
                return $this->ok(array_merge(
                    array('action' => 'add', 'already_exists' => true, 'relation' => $described),
                    $this->bothSides($task_id, $related_task_id)
                ));
            }
            return $this->softFail(
                'conflict',
                sprintf(
                    _wp('These tasks are already related: %1$s %2$s %3$s (%4$s). Remove that relation before adding another one.'),
                    $described['task_full_number'],
                    $described['relation'],
                    $described['related_full_number'],
                    $described['type']
                ),
                array('existing_relation' => $described)
            );
        }

        // The row belongs to the task that carries the dependency, so that is
        // the task whose edit permission has to be checked.
        $owner = (int) $target['task_id'] === $task_id ? $entity : new pmTask($related);
        $owner_row = (int) $target['task_id'] === $task_id ? $row : $related;
        if (!$owner->canEdit($this->getUserId())) {
            return $this->softFail('access_denied', sprintf(
                _wp('You do not have permission to edit task %s, which would carry this relation.'),
                pmMcpTaskHelper::formatNumber((int) $owner_row['id'], (int) $owner_row['project_id'])
            ));
        }

        $dependency_id = $owner->addDependency((int) $target['depends_on_task_id'], $target['type']);

        pmTask::log(
            (int) $owner_row['project_id'],
            (int) $owner_row['id'],
            $this->getUserId(),
            'dependency_added',
            array('depends_on_task_id' => (int) $target['depends_on_task_id'], 'type' => $target['type'])
        );

        $stored = (new pmTaskDependencyModel())->getById($dependency_id);

        return $this->ok(array_merge(
            array(
                'action'   => 'add',
                'relation' => pmMcpDependencyHelper::describe($stored, $task_id),
            ),
            $this->bothSides($task_id, $related_task_id)
        ));
    }

    /**
     * Drop the row that stores a relation, addressed either by its id or by the
     * task on the other end.
     */
    protected function remove(array $arguments, pmTask $entity, array $row, $task_id)
    {
        $dependency_id = $this->argInt($arguments, 'dependency_id');
        $related_ref = $this->argRef($arguments, 'related_task_id');
        $relation_filter = $this->argString($arguments, 'relation');

        if ($relation_filter !== '' && !in_array($relation_filter, pmMcpDependencyHelper::relations(), true)) {
            return $this->softFail('invalid_param', sprintf(
                _wp('Unknown relation "%s".'),
                $relation_filter
            ));
        }

        $model = new pmTaskDependencyModel();
        $target = null;

        if ($dependency_id > 0) {
            $target = $model->getById($dependency_id);
            if (!$target) {
                return $this->softFail('not_found', _wp('Relation not found.'));
            }
            // The id must belong to a relation of this task, otherwise a caller
            // could delete a relation between two tasks it never named.
            if ((int) $target['task_id'] !== $task_id && (int) $target['depends_on_task_id'] !== $task_id) {
                return $this->softFail('not_found', _wp('That relation does not involve this task.'));
            }
        } elseif ($related_ref !== '') {
            $related = pmMcpTaskHelper::loadAccessibleTask($related_ref);
            $matches = pmMcpDependencyHelper::findBetween($task_id, (int) $related['id']);
            if ($relation_filter !== '') {
                $matches = array_values(array_filter($matches, function ($candidate) use ($task_id, $relation_filter) {
                    return pmMcpDependencyHelper::relationFromRow($candidate, $task_id) === $relation_filter;
                }));
            }
            if (!$matches) {
                return $this->softFail('not_found', _wp('These tasks are not related.'));
            }
            if (count($matches) > 1) {
                // Legacy data can hold more than one row per pair; make the
                // caller name the one it means instead of guessing.
                return $this->softFail(
                    'ambiguous',
                    _wp('These tasks have several relations. Pass dependency_id to say which one to remove.'),
                    array('relations' => array_map(function ($candidate) use ($task_id) {
                        return pmMcpDependencyHelper::describe($candidate, $task_id);
                    }, $matches))
                );
            }
            $target = $matches[0];
        } else {
            return $this->softFail('invalid_param', _wp('Pass related_task_id or dependency_id to remove a relation.'));
        }

        $described = pmMcpDependencyHelper::describe($target, $task_id);
        $related_task_id = $described['related_task_id'];

        // Same rule as add(): the row belongs to one task, and that task's edit
        // permission governs it.
        $owner_row = (int) $target['task_id'] === $task_id
            ? $row
            : (new pmTaskModel())->getById((int) $target['task_id']);
        if (!$owner_row) {
            return $this->softFail('not_found', _wp('The task carrying this relation no longer exists.'));
        }
        if (!pmMcpProjectHelper::canAccess((int) $owner_row['project_id'])) {
            return $this->softFail('access_denied', _wp('You are not a member of the project carrying this relation.'));
        }
        $owner = (int) $target['task_id'] === $task_id ? $entity : new pmTask($owner_row);
        if (!$owner->canEdit($this->getUserId())) {
            return $this->softFail('access_denied', sprintf(
                _wp('You do not have permission to edit task %s, which carries this relation.'),
                pmMcpTaskHelper::formatNumber((int) $owner_row['id'], (int) $owner_row['project_id'])
            ));
        }

        $owner->removeDependency((int) $target['id']);

        pmTask::log(
            (int) $owner_row['project_id'],
            (int) $owner_row['id'],
            $this->getUserId(),
            'dependency_removed',
            array('depends_on_task_id' => (int) $target['depends_on_task_id'], 'type' => (string) $target['type'])
        );

        return $this->ok(array_merge(
            array(
                'action'           => 'remove',
                'removed'          => true,
                'removed_relation' => $described,
            ),
            $this->bothSides($task_id, $related_task_id)
        ));
    }

    /**
     * The relations of both tasks after the change: one stored row, visible
     * from either end.
     */
    protected function bothSides($task_id, $related_task_id)
    {
        return array(
            'task_id'              => (int) $task_id,
            'related_task_id'      => (int) $related_task_id,
            'dependencies'         => pmMcpDependencyHelper::forTask($task_id),
            'related_dependencies' => pmMcpDependencyHelper::forTask($related_task_id),
        );
    }
}
