<?php

/**
 * pm_update_project — partial update of a project's properties and, optionally,
 * its attached workflows. Only the supplied fields are changed. Requires the
 * caller to be a project administrator.
 */
class pmMcpUpdateProjectTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_update_project'; }
    public function getRight()       { return 'pm_update_project'; }
    public function getDescription() { return _wp('Update a project\'s properties (name, description, status, workflows, ...). Only the supplied fields change. Requires project administrator rights. Returns the updated project card.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('project_id'),
            'properties' => array(
                'project_id'       => array('type' => 'integer', 'minimum' => 1, 'description' => 'Project id.'),
                'name'             => array('type' => 'string', 'minLength' => 1, 'description' => 'Project name.'),
                'description'      => array('type' => 'string', 'description' => 'Project description (empty string clears it).'),
                'status'           => array('type' => 'string', 'enum' => array('planned', 'active', 'completed'), 'description' => 'Project status.'),
                'owner_contact_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'New owner contact id.'),
                'workflow_ids'     => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Replacement set of workflow slugs (must be non-empty).'),
                'color'            => array('type' => 'string', 'description' => 'Hex color, e.g. #1a9afe.'),
                'icon'             => array('type' => 'string', 'description' => 'Icon class, e.g. fas fa-folder.'),
                'start_date'       => array('type' => 'string', 'description' => 'Start date (YYYY-MM-DD, empty string clears it).'),
                'end_date'         => array('type' => 'string', 'description' => 'End date (YYYY-MM-DD, empty string clears it).'),
                'prefix'           => array('type' => 'string', 'description' => 'Task number prefix.'),
                'number_mode'      => array('type' => 'string', 'enum' => array('prefix', 'postfix'), 'description' => 'Where the prefix goes relative to the number.'),
                'number_separator' => array('type' => 'string', 'description' => 'Separator between prefix and number.'),
                'parent_id'        => array('type' => 'integer', 'minimum' => 0, 'description' => 'Parent project id (0 detaches from any parent).'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $project_id = $this->argInt($arguments, 'project_id');
            pmMcpProjectHelper::loadAccessibleProject($project_id);
            pmMcpProjectHelper::requireProjectAdmin($project_id);

            $data = array();

            if (array_key_exists('name', $arguments)) {
                $name = strip_tags($this->argString($arguments, 'name'));
                if ($name === '') {
                    return $this->softFail('invalid_param', _wp('Project name cannot be empty.'));
                }
                $data['name'] = $name;
            }

            // Nullable text/date fields: an explicitly supplied empty string clears them.
            foreach (array('description', 'start_date', 'end_date') as $f) {
                if (array_key_exists($f, $arguments)) {
                    $v = $this->argString($arguments, $f);
                    $data[$f] = $v !== '' ? $v : null;
                }
            }

            // Plain string fields: only overwritten when a non-empty value is given.
            foreach (array('status', 'color', 'icon', 'prefix', 'number_mode', 'number_separator') as $f) {
                if (array_key_exists($f, $arguments)) {
                    $v = $this->argString($arguments, $f);
                    if ($v !== '') {
                        $data[$f] = $v;
                    }
                }
            }

            if (array_key_exists('owner_contact_id', $arguments)) {
                $owner_contact_id = $this->argInt($arguments, 'owner_contact_id');
                if ($owner_contact_id > 0) {
                    if (!(new waContact($owner_contact_id))->exists()) {
                        return $this->softFail('not_found', _wp('Owner contact not found.'));
                    }
                    $data['owner_contact_id'] = $owner_contact_id;
                    // Make sure the new owner is a project member with admin rights.
                    (new pmProjectUserModel())->add($project_id, $owner_contact_id, 'admin');
                }
            }

            if (array_key_exists('parent_id', $arguments)) {
                $parent_id = $this->argInt($arguments, 'parent_id');
                $data['parent_id'] = $parent_id > 0 ? $parent_id : null;
            }

            // Workflows are a separate M:N table.
            $workflow_ids = null;
            if (array_key_exists('workflow_ids', $arguments)) {
                if (!is_array($arguments['workflow_ids']) || !$arguments['workflow_ids']) {
                    return $this->softFail('invalid_param', _wp('workflow_ids must be a non-empty list.'));
                }
                $all_workflows = pmWorkflow::getWorkflows();
                $workflow_ids = array();
                foreach ($arguments['workflow_ids'] as $wf_id) {
                    $wf_id = trim((string) $wf_id);
                    if (!isset($all_workflows[$wf_id])) {
                        return $this->softFail(
                            'invalid_param',
                            sprintf(_wp('Unknown workflow "%s".'), $wf_id),
                            array('available_workflows' => array_keys($all_workflows))
                        );
                    }
                    $workflow_ids[$wf_id] = $wf_id;
                }
                $workflow_ids = array_values($workflow_ids);
            }

            if (!$data && $workflow_ids === null) {
                return $this->softFail('invalid_param', _wp('Nothing to update: supply at least one field.'));
            }

            $model = new pmProjectModel();
            if ($data) {
                $data['update_datetime'] = date('Y-m-d H:i:s');
                $model->updateById($project_id, $data);
            }
            if ($workflow_ids !== null) {
                $model->saveWorkflows($project_id, $workflow_ids);
            }

            return $this->ok(pmMcpProjectHelper::fullCard($project_id));
        });
    }
}
