<?php

/**
 * pm_add_project_user — add a participant to a project with a role, or change
 * the role of an existing participant. Requires the caller to be a project
 * administrator. Role must be one of the configured project roles
 * (admin/manager/member/viewer by default).
 */
class pmMcpAddProjectUserTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_add_project_user'; }
    public function getRight()       { return 'pm_add_project_user'; }
    public function getDescription() { return _wp('Add a participant to a project with a role, or update the role of an existing participant. Requires project administrator rights. Returns the updated participant list.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('project_id', 'contact_id'),
            'properties' => array(
                'project_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Project id.'),
                'contact_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Contact id to add.'),
                'role'       => array('type' => 'string', 'description' => 'Project role slug (admin, manager, member, viewer). Defaults to member.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $project_id = $this->argInt($arguments, 'project_id');
            pmMcpProjectHelper::loadAccessibleProject($project_id);
            pmMcpProjectHelper::requireProjectAdmin($project_id);

            $contact_id = $this->argInt($arguments, 'contact_id');
            if ($contact_id <= 0) {
                return $this->softFail('invalid_param', _wp('contact_id is required.'));
            }
            if (!(new waContact($contact_id))->exists()) {
                return $this->softFail('not_found', _wp('Contact not found.'));
            }

            $role = $this->argString($arguments, 'role', 'member');
            if ($role === '') {
                $role = 'member';
            }
            $roles = (new pmRoleModel())->getAllRoles();
            if (!isset($roles[$role])) {
                return $this->softFail(
                    'invalid_param',
                    sprintf(_wp('Unknown role "%s".'), $role),
                    array('available_roles' => array_keys($roles))
                );
            }

            $user_model = new pmProjectUserModel();
            $existing = $user_model->getByField(array('project_id' => $project_id, 'contact_id' => $contact_id));
            if ($existing) {
                $action = 'updated';
                if ($existing['role'] !== $role) {
                    $user_model->updateByField(
                        array('project_id' => $project_id, 'contact_id' => $contact_id),
                        array('role' => $role)
                    );
                }
            } else {
                $action = 'added';
                $user_model->add($project_id, $contact_id, $role);
            }

            return $this->ok(array(
                'project_id'   => $project_id,
                'contact_id'   => $contact_id,
                'role'         => $role,
                'action'       => $action,
                'participants' => pmMcpProjectHelper::formatParticipants($project_id),
            ));
        });
    }
}
