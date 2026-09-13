<?php

/**
 * pm_create_task — create a task in a project via pmTask::create() (which
 * enforces task.create, validates every referenced entity, logs the activity
 * and fires notifications). Returns the new task's full card.
 */
class pmMcpCreateTaskTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_create_task'; }
    public function getRight()       { return 'pm_create_task'; }
    public function getDescription() { return _wp('Create a task in a project. Requires project membership with the task.create permission. workflow_id is required unless the project has exactly one workflow; type_slug is required unless the workflow\'s type group has exactly one type (pm rejects every task with no type); assignee, milestone and sprint are optional — omit them (or pass 0) to leave them empty. Tags can be set in the same call with the tags field, and links to helpdesk/crm/shop records with external_links. Returns the created task card.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('project_id', 'subject'),
            'properties' => array(
                'project_id'          => array('type' => 'integer', 'minimum' => 1, 'description' => 'Target project id.'),
                'subject'             => array('type' => 'string', 'minLength' => 1, 'description' => 'Task subject.'),
                'description'         => array('type' => 'string', 'description' => 'Task description (project content format, usually Markdown).'),
                'workflow_id'         => array('type' => 'string', 'description' => 'Workflow slug. Optional when the project has a single workflow.'),
                'status_id'           => array('type' => 'integer', 'minimum' => 1, 'description' => 'Initial status id. Defaults to the first status when omitted.'),
                'priority'            => array('type' => 'string', 'description' => 'Priority slug (e.g. low, normal, high). Defaults to normal.'),
                'type_slug'           => array('type' => 'string', 'description' => 'Task type slug, from the workflow\'s type group. pm requires a type on every task; omit this only when the group has exactly one type (it is then auto-selected). An omitted-but-ambiguous or a wrong type both fail with invalid_param and list available_types.'),
                'assignee_contact_id' => array('type' => 'integer', 'minimum' => 0, 'description' => 'Assignee contact id (must be a project participant). Optional: omit or 0 leaves the task unassigned.'),
                'milestone_id'        => array('type' => 'integer', 'minimum' => 0, 'description' => 'Milestone id (must belong to the project). Optional: omit or 0 for no milestone.'),
                'sprint_id'           => array('type' => 'integer', 'minimum' => 0, 'description' => 'Sprint id (must belong to the project). Optional: omit or 0 puts the task in the backlog.'),
                'parent_id'           => self::taskRefSchema('Parent task for a subtask (same project). Optional: omit or 0 for a top-level task.'),
                'start_date'          => array('type' => 'string', 'description' => 'Start date (YYYY-MM-DD).'),
                'due_date'            => array('type' => 'string', 'description' => 'Due date (YYYY-MM-DD).'),
                'deadline'            => array('type' => 'string', 'description' => 'Hard deadline (YYYY-MM-DD).'),
                'estimated_hours'     => array('type' => 'number', 'minimum' => 0, 'description' => 'Estimated hours.'),
                'progress'            => array('type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'description' => 'Progress percent (0-100).'),
                'custom_fields'       => array('type' => 'object', 'description' => 'Map of custom field id => value.'),
                'tags'                => array(
                    'type'        => 'array',
                    'items'       => array('type' => 'string', 'minLength' => 1, 'maxLength' => pmMcpTagHelper::NAME_MAX_LENGTH),
                    'description' => 'Tag names to put on the new task. Matching against the project\'s tags is case-insensitive; a name the project does not have is refused unless create_missing_tags is true.',
                ),
                'create_missing_tags' => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Create tags the project does not have yet. Default false: an unknown name is refused with the project\'s available_tags instead, so a typo does not become a new tag.',
                ),
                'external_links'      => array(
                    'type'        => 'array',
                    'items'       => array(
                        'type'       => 'object',
                        'required'   => array('app_id', 'external_id'),
                        'properties' => array(
                            'app_id'      => array('type' => 'string', 'enum' => pmMcpExternalHelper::apps()),
                            'external_id' => array('type' => 'string', 'minLength' => 1),
                        ),
                    ),
                    'description' => 'Links to records in other integrated apps (e.g. the helpdesk request this task was opened from). Each entry\'s record must exist; an unknown id is refused before the task is created.',
                ),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $project_id = $this->argInt($arguments, 'project_id');
            pmMcpProjectHelper::loadAccessibleProject($project_id);
            pmMcpTaskHelper::requireProjectRole($project_id);

            $subject = $this->argString($arguments, 'subject');
            if ($subject === '') {
                return $this->softFail('invalid_param', _wp('Task subject is required.'));
            }

            // Resolve the workflow: required, but auto-selected when the project
            // has exactly one.
            $workflow_id = $this->argString($arguments, 'workflow_id');
            if ($workflow_id === '') {
                $project_wfs = (new pmProjectModel())->getWorkflows($project_id);
                if (count($project_wfs) === 1) {
                    $workflow_id = reset($project_wfs);
                } else {
                    return $this->softFail(
                        'invalid_param',
                        _wp('workflow_id is required (the project has multiple workflows).'),
                        array('available_workflows' => array_values($project_wfs))
                    );
                }
            }

            // Resolve the type: pmTask::validate() rejects every task with no
            // type_slug, or one that does not belong to the workflow's type
            // group, unconditionally (pm's "Types only from workflow, type is
            // required on save" change), but this tool used to treat the
            // field as an unchecked optional. Auto-select it the same way
            // workflow_id is auto-selected above — only when the workflow's
            // type group leaves exactly one candidate — and validate an
            // explicit value against that same list, instead of letting
            // pmTask::create() throw a bare "Task type is required." /
            // "Invalid task type." / "Task type does not belong to the
            // workflow group." (PMCP-555).
            $wf = pmWorkflow::getWorkflow($workflow_id);
            $type_group = $wf['type_group'] ?? null;
            $group = $type_group ? pmTypeConfig::getGroup($type_group) : null;
            $type_options = array_values($group['types'] ?? pmTypeConfig::getAllTypes());

            $type_slug = $this->argString($arguments, 'type_slug');
            if ($type_slug === '') {
                if (count($type_options) === 1) {
                    $type_slug = $type_options[0]['slug'];
                } else {
                    return $this->softFail(
                        'invalid_param',
                        _wp('type_slug is required (more than one task type is available); see available_types.'),
                        array('available_types' => self::formatTypeOptions($type_options))
                    );
                }
            } elseif (!in_array($type_slug, array_column($type_options, 'slug'), true)) {
                return $this->softFail(
                    'invalid_param',
                    sprintf(_wp('type_slug "%s" is not valid for this workflow; see available_types.'), $type_slug),
                    array('available_types' => self::formatTypeOptions($type_options))
                );
            }

            $data = array(
                'project_id'          => $project_id,
                'subject'             => $subject,
                'description'         => $this->argString($arguments, 'description'),
                'workflow_id'         => $workflow_id,
                'priority'            => $this->argString($arguments, 'priority', 'normal'),
                'status_id'           => $this->argInt($arguments, 'status_id'),
                'progress'            => $this->argInt($arguments, 'progress'),
                'type_slug'           => $type_slug,
            );
            foreach (array('start_date', 'due_date', 'deadline') as $f) {
                $v = $this->argString($arguments, $f);
                if ($v !== '') {
                    $data[$f] = $v;
                }
            }
            foreach (array('assignee_contact_id', 'milestone_id') as $f) {
                $v = $this->argInt($arguments, $f);
                $data[$f] = $v > 0 ? $v : null;
            }
            // sprint_id is NOT NULL DEFAULT 0 since pm 0.33.5 (migration
            // 1786112356: "sprint_id 0 = no sprint, NULL no longer used") — 0
            // is the backlog, not the two genuinely nullable FKs above. A PHP
            // null here would hit pm_task's NOT NULL INT UNSIGNED column;
            // waModel::castValue() has no branch for the unparenthesised
            // "int unsigned" type DESCRIBE reports, falls through to the
            // default case and writes '' — 1366 in strict mode (PMCP-518).
            $data['sprint_id'] = max(0, $this->argInt($arguments, 'sprint_id'));
            // The parent may be quoted as a full number; resolving it here also
            // rejects a parent the caller cannot see before pmTask::create()
            // does its own same-project check.
            $parent_ref = $this->argRef($arguments, 'parent_id');
            $data['parent_id'] = ($parent_ref === '' || $parent_ref === '0')
                ? null
                : (int) pmMcpTaskHelper::loadAccessibleTask($parent_ref)['id'];
            if (isset($arguments['estimated_hours']) && $arguments['estimated_hours'] !== '') {
                $data['estimated_hours'] = (float) $arguments['estimated_hours'];
            }
            if (!empty($arguments['custom_fields']) && is_array($arguments['custom_fields'])) {
                $data['_custom_fields'] = $arguments['custom_fields'];
            }

            // Report every reference that does not fit the project in one
            // answer, with the project's own options — pmTask::create() would
            // otherwise reject them one per call and name no alternative.
            $ref_problem = pmMcpTaskHelper::checkProjectRefs($project_id, $data);
            if ($ref_problem !== null) {
                return $this->softFail('invalid_param', $ref_problem['message'], $ref_problem['extra']);
            }

            // Tags are not part of pmTask::create()'s data — they are linked
            // afterwards. Resolve the names before creating anything, so a
            // rejected tag does not leave a task behind that the caller did not
            // get told about, and so no tag is created for a task that then
            // fails validation.
            $tag_names = array();
            $create_missing_tags = $this->argBool($arguments, 'create_missing_tags');
            if (array_key_exists('tags', $arguments)) {
                $tag_names = pmMcpTagHelper::normalizeNames($arguments['tags']);
                if ($tag_names && !$create_missing_tags) {
                    $missing = pmMcpTagHelper::missingNames($project_id, $tag_names);
                    if ($missing) {
                        $failure = pmMcpTagHelper::missingTagsFailure($project_id, $missing);
                        return $this->softFail('invalid_param', $failure['message'], $failure['extra']);
                    }
                }
            }

            // External links (helpdesk request, crm deal, shop order): resolved
            // and validated before create() runs, same reasoning as tags above
            // — a rejected link must not leave a task behind that the caller
            // was not told about.
            $external_links = array();
            if (!empty($arguments['external_links']) && is_array($arguments['external_links'])) {
                foreach ($arguments['external_links'] as $i => $entry) {
                    if (!is_array($entry)) {
                        return $this->softFail('invalid_param', sprintf(_wp('external_links[%d] must be an object with app_id and external_id.'), $i));
                    }
                    $link_app_id = is_scalar($entry['app_id'] ?? null) ? (string) $entry['app_id'] : '';
                    $link_external_id = pmMcpExternalHelper::normalizeExternalId($entry['external_id'] ?? null);

                    if (!in_array($link_app_id, pmMcpExternalHelper::apps(), true)) {
                        return $this->softFail('invalid_param', sprintf(
                            _wp('external_links[%1$d].app_id "%2$s" is not one of: %3$s.'),
                            $i, $entry['app_id'] ?? '', implode(', ', pmMcpExternalHelper::apps())
                        ));
                    }
                    if ($link_external_id === '') {
                        return $this->softFail('invalid_param', sprintf(_wp('external_links[%d].external_id must be a positive integer id.'), $i));
                    }
                    pmMcpExternalHelper::assertLinkable($link_app_id);
                    if (!pmMcpExternalHelper::externalExists($link_app_id, $link_external_id)) {
                        return $this->softFail('not_found', sprintf(
                            _wp('external_links[%1$d]: no %2$s record with id %3$s was found; the task was not created.'),
                            $i, $link_app_id, $link_external_id
                        ));
                    }
                    $external_links[] = array('app_id' => $link_app_id, 'external_id' => $link_external_id);
                }
            }

            $task_id = pmTask::create($data, $this->getUserId());

            if ($tag_names) {
                $tags = pmMcpTagHelper::resolveOrCreate($project_id, $tag_names, $create_missing_tags);
                pmMcpTagHelper::applyToTask(
                    array('id' => $task_id, 'project_id' => $project_id),
                    $tags,
                    'add',
                    $this->getUserId()
                );
            }

            if ($external_links) {
                // pmTaskExternalModel::add() directly, not
                // pmTask::addExternalLink() — the latter requires canEdit()
                // (task.edit), while this tool is gated on task.create. A role
                // with create-but-not-edit would create the task and then hit
                // a 403 on the very links it asked for in the same call: the
                // "task with silently lost links" state this pre-validation
                // exists to avoid. Whoever just created the task has an
                // obvious claim to also link it — mirrors pm's own bulk save
                // path (pmTask.actions.php::saveAction()'s external_links
                // branch), which does the same.
                $ext_model = new pmTaskExternalModel();
                foreach ($external_links as $link) {
                    $ext_model->add((int) $task_id, $link['app_id'], $link['external_id']);
                }
            }

            return $this->ok(array(
                'task_id' => (int) $task_id,
                'task'    => pmMcpTaskHelper::cardById($task_id),
            ));
        });
    }

    /** Reduce pmTypeConfig type entries to the slug/name pair callers act on. */
    private static function formatTypeOptions(array $type_options)
    {
        return array_map(static function ($t) {
            return array('slug' => $t['slug'], 'name' => $t['name']);
        }, $type_options);
    }
}
