<?php

/**
 * pm_update_task — partial update of task fields via pmTask::save() (which
 * enforces per-field permissions — edit / assign / set_dates — validates
 * references, logs changes and notifies). Status changes go through
 * pm_move_task, not here. Returns the updated task card.
 *
 * Nullable references (assignee_contact_id, milestone_id, parent_id) accept 0
 * to clear the field (unassign / no milestone / detach) and are written as
 * SQL NULL. sprint_id is the odd one out since pm 0.33.5: pm_task.sprint_id
 * is NOT NULL DEFAULT 0, so 0 (backlog) is written as the plain integer, not
 * as null — see the cast below.
 *
 * `tags` is the odd one out: pmTask::save() knows nothing about tags, so they
 * are written separately, gated on task.edit and replacing the whole set — an
 * empty array clears it. pm_add_tags / pm_remove_tags change one tag without
 * touching the others.
 */
class pmMcpUpdateTaskTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_update_task'; }
    public function getRight()       { return 'pm_update_task'; }
    public function getDescription() { return _wp('Update task fields (subject, description, priority, type, assignee, dates, milestone, sprint, parent, estimate, progress, custom fields, tags). Pass 0 for assignee/milestone/sprint/parent to clear them; the tags field replaces the whole tag set, an empty array clears it. Use pm_move_task to change status. Returns the updated task card.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('task_id'),
            'properties' => array(
                'task_id'             => self::taskRefSchema('The task to update.'),
                'subject'             => array('type' => 'string', 'minLength' => 1, 'description' => 'New subject.'),
                'description'         => array('type' => 'string', 'description' => 'New description.'),
                'priority'            => array('type' => 'string', 'description' => 'New priority slug.'),
                'type_slug'           => array('type' => 'string', 'description' => 'New task type slug.'),
                'assignee_contact_id' => array('type' => 'integer', 'minimum' => 0, 'description' => 'New assignee (0 to unassign).'),
                'milestone_id'        => array('type' => 'integer', 'minimum' => 0, 'description' => 'New milestone (0 to clear).'),
                'sprint_id'           => array('type' => 'integer', 'minimum' => 0, 'description' => 'New sprint (0 for backlog).'),
                'parent_id'           => self::taskRefSchema('New parent task, or 0 to detach.'),
                'start_date'          => array('type' => 'string', 'description' => 'Start date (YYYY-MM-DD, empty to clear).'),
                'due_date'            => array('type' => 'string', 'description' => 'Due date (YYYY-MM-DD, empty to clear).'),
                'deadline'            => array('type' => 'string', 'description' => 'Deadline (YYYY-MM-DD, empty to clear).'),
                'estimated_hours'     => array('type' => 'number', 'minimum' => 0, 'description' => 'Estimated hours.'),
                'progress'            => array('type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'description' => 'Progress percent (0-100).'),
                'custom_fields'       => array('type' => 'object', 'description' => 'Map of custom field id => value.'),
                'tags'                => array(
                    'type'        => 'array',
                    'items'       => array('type' => 'string', 'minLength' => 1, 'maxLength' => pmMcpTagHelper::NAME_MAX_LENGTH),
                    'description' => 'Replaces the task\'s whole tag set with these names: tags not listed are detached, an empty array clears every tag. To add or drop individual tags without touching the rest, use pm_add_tags / pm_remove_tags. Requires task.edit.',
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

            $data = array();

            foreach (array('subject', 'description', 'priority', 'type_slug', 'start_date', 'due_date', 'deadline') as $f) {
                if (array_key_exists($f, $arguments)) {
                    $data[$f] = (string) $arguments[$f];
                }
            }
            // Nullable foreign keys: 0 clears (save() writes NULL for null values).
            foreach (array('assignee_contact_id', 'milestone_id') as $f) {
                if (array_key_exists($f, $arguments) && $arguments[$f] !== '') {
                    $v = (int) $arguments[$f];
                    $data[$f] = $v > 0 ? $v : null;
                }
            }
            // sprint_id: NOT NULL DEFAULT 0 since pm 0.33.5 — 0 is the
            // backlog and must reach save() as a plain int, never null
            // (PMCP-518; see pm_create_task for the failure mode).
            if (array_key_exists('sprint_id', $arguments) && $arguments['sprint_id'] !== '') {
                $data['sprint_id'] = max(0, (int) $arguments['sprint_id']);
            }
            // The parent may be quoted as a full number, so it cannot go
            // through the plain (int) cast above — that would read "AUTH-31"
            // as 0 and silently detach the task instead of re-parenting it.
            if (array_key_exists('parent_id', $arguments) && $arguments['parent_id'] !== '') {
                $parent_ref = $this->argRef($arguments, 'parent_id');
                $data['parent_id'] = ($parent_ref === '' || $parent_ref === '0')
                    ? null
                    : (int) pmMcpTaskHelper::loadAccessibleTask($parent_ref)['id'];
            }
            if (array_key_exists('progress', $arguments) && $arguments['progress'] !== '') {
                $data['progress'] = (int) $arguments['progress'];
            }
            if (array_key_exists('estimated_hours', $arguments) && $arguments['estimated_hours'] !== '') {
                $data['estimated_hours'] = (float) $arguments['estimated_hours'];
            }
            if (!empty($arguments['custom_fields']) && is_array($arguments['custom_fields'])) {
                $data['_custom_fields'] = $arguments['custom_fields'];
            }

            // Tags live outside pmTask::save() — they are their own table and
            // their own permission check. An update may carry nothing but tags.
            $has_tags = array_key_exists('tags', $arguments);
            if (!$data && !$has_tags) {
                return $this->softFail('invalid_param', _wp('No fields to update.'));
            }

            // Same one-shot reference report as pm_create_task: save() rejects
            // a foreign milestone / sprint / assignee one per call, naming no
            // alternative.
            $ref_problem = pmMcpTaskHelper::checkProjectRefs((int) $row['project_id'], $data);
            if ($ref_problem !== null) {
                return $this->softFail('invalid_param', $ref_problem['message'], $ref_problem['extra']);
            }

            // Resolve the tag names before saving the other fields: an unknown
            // name then costs the caller nothing, instead of leaving the field
            // changes applied and the tags not.
            $tag_names = array();
            $create_missing_tags = $this->argBool($arguments, 'create_missing_tags');
            if ($has_tags) {
                if (!$entity->canEdit($this->getUserId())) {
                    return $this->softFail('access_denied', _wp('You do not have permission to edit this task\'s tags.'));
                }
                $tag_names = pmMcpTagHelper::normalizeNames($arguments['tags']);
                if ($tag_names && !$create_missing_tags) {
                    $missing = pmMcpTagHelper::missingNames((int) $row['project_id'], $tag_names);
                    if ($missing) {
                        $failure = pmMcpTagHelper::missingTagsFailure((int) $row['project_id'], $missing);
                        return $this->softFail('invalid_param', $failure['message'], $failure['extra']);
                    }
                }
            }

            if ($data) {
                $entity->save($data, $this->getUserId());
            }

            if ($has_tags) {
                // Replace, not merge: every other field of this tool overwrites
                // what was there, and an empty array is the documented way to
                // clear the set.
                $tags = pmMcpTagHelper::resolveOrCreate((int) $row['project_id'], $tag_names, $create_missing_tags);
                pmMcpTagHelper::applyToTask($row, $tags, 'replace', $this->getUserId());
            }

            return $this->ok(array(
                'task_id' => $task_id,
                'task'    => pmMcpTaskHelper::cardById($task_id),
            ));
        });
    }
}
