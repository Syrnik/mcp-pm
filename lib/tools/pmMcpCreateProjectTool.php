<?php

/**
 * pm_create_project — create a project. The caller must have access to the pm
 * app. Mirrors pmProjectsSaveController: inserts the project, links its
 * workflow(s) and adds the owner as a project admin. Returns the new project's
 * full card.
 */
class pmMcpCreateProjectTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_create_project'; }
    public function getRight()       { return 'pm_create_project'; }
    public function getDescription() { return _wp('Create a project. Requires access to the Project Management app. The owner (defaults to the current user) is added as a project administrator. Returns the created project card.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('name'),
            'properties' => array(
                'name'             => array('type' => 'string', 'minLength' => 1, 'description' => 'Project name.'),
                'description'      => array('type' => 'string', 'description' => 'Project description.'),
                'owner_contact_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Owner contact id. Defaults to the current user. The owner becomes a project admin.'),
                'workflow_ids'     => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Workflow slugs to attach. Defaults to the first configured workflow.'),
                'status'           => array('type' => 'string', 'enum' => array('planned', 'active', 'completed'), 'description' => 'Project status. Defaults to planned.'),
                'color'            => array('type' => 'string', 'description' => 'Hex color, e.g. #1a9afe.'),
                'icon'             => array('type' => 'string', 'description' => 'Icon class, e.g. fas fa-folder.'),
                'start_date'       => array('type' => 'string', 'description' => 'Start date (YYYY-MM-DD).'),
                'end_date'         => array('type' => 'string', 'description' => 'End date (YYYY-MM-DD).'),
                'prefix'           => array('type' => 'string', 'description' => 'Task number prefix.'),
                'number_mode'      => array('type' => 'string', 'enum' => array('prefix', 'postfix'), 'description' => 'Where the prefix goes relative to the number.'),
                'number_separator' => array('type' => 'string', 'description' => 'Separator between prefix and number.'),
                'parent_id'        => array('type' => 'integer', 'minimum' => 1, 'description' => 'Parent project id, for a sub-project.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            pmMcpProjectHelper::requireAppAccess();

            $name = strip_tags($this->argString($arguments, 'name'));
            if ($name === '') {
                return $this->softFail('invalid_param', _wp('Project name is required.'));
            }

            $owner_contact_id = $this->argInt($arguments, 'owner_contact_id');
            if ($owner_contact_id <= 0) {
                $owner_contact_id = $this->getUserId();
            } elseif (!(new waContact($owner_contact_id))->exists()) {
                return $this->softFail('not_found', _wp('Owner contact not found.'));
            }

            // Resolve workflows: explicit list (validated) or the first configured one.
            $all_workflows = pmWorkflow::getWorkflows();
            if (isset($arguments['workflow_ids']) && is_array($arguments['workflow_ids']) && $arguments['workflow_ids']) {
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
            } else {
                $workflow_ids = $all_workflows ? array((string) array_key_first($all_workflows)) : array();
            }

            $config = pmHelper::getConfig('config.php') ?: array();
            $content_format = $config['content_format'] ?? 'md';

            $data = array(
                'name'            => $name,
                'description'     => ($d = $this->argString($arguments, 'description')) !== '' ? $d : null,
                'owner_contact_id' => $owner_contact_id,
                'content_format'  => $content_format,
                'create_datetime' => date('Y-m-d H:i:s'),
            );
            foreach (array('status', 'color', 'icon', 'start_date', 'end_date', 'prefix', 'number_mode', 'number_separator') as $f) {
                $v = $this->argString($arguments, $f);
                if ($v !== '') {
                    $data[$f] = $v;
                }
            }
            $parent_id = $this->argInt($arguments, 'parent_id');
            if ($parent_id > 0) {
                $data['parent_id'] = $parent_id;
            }

            $model = new pmProjectModel();
            $project_id = $model->insert($data);
            pmMcpTaskHelper::resetNumberConfig();
            $model->saveWorkflows($project_id, $workflow_ids);
            (new pmProjectUserModel())->add($project_id, $owner_contact_id, 'admin');

            return $this->ok(array('project_id' => (int) $project_id) + pmMcpProjectHelper::fullCard($project_id));
        });
    }
}
