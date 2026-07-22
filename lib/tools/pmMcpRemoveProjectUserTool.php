<?php

/**
 * pm_remove_project_user — remove a participant from a project. Destructive:
 * requires confirm=true. Requires the caller to be a project administrator.
 * The project owner cannot be removed (reassign ownership first via
 * pm_update_project).
 */
class pmMcpRemoveProjectUserTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_remove_project_user'; }
    public function getRight()       { return 'pm_remove_project_user'; }
    public function getDescription() { return _wp('Remove a participant from a project. Requires project administrator rights and confirm=true. The project owner cannot be removed. Returns the remaining participants.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('project_id', 'contact_id', 'confirm'),
            'properties' => array(
                'project_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Project id.'),
                'contact_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Contact id to remove.'),
                'confirm'    => array('type' => 'boolean', 'description' => 'Must be true to confirm this destructive action.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $error = null;
            if (!$this->assertConfirm($arguments, $error)) {
                return $error;
            }

            $project_id = $this->argInt($arguments, 'project_id');
            $project = pmMcpProjectHelper::loadAccessibleProject($project_id);
            pmMcpProjectHelper::requireProjectAdmin($project_id);

            $contact_id = $this->argInt($arguments, 'contact_id');
            if ($contact_id <= 0) {
                return $this->softFail('invalid_param', _wp('contact_id is required.'));
            }

            if ($contact_id === (int) $project['owner_contact_id']) {
                return $this->softFail(
                    'conflict',
                    _wp('The project owner cannot be removed. Reassign ownership with pm_update_project first.')
                );
            }

            $user_model = new pmProjectUserModel();
            $existing = $user_model->getByField(array('project_id' => $project_id, 'contact_id' => $contact_id));
            if (!$existing) {
                return $this->softFail('not_found', _wp('This contact is not a member of the project.'));
            }

            $user_model->remove($project_id, $contact_id);

            return $this->ok(array(
                'project_id'   => $project_id,
                'contact_id'   => $contact_id,
                'removed'      => true,
                'participants' => pmMcpProjectHelper::formatParticipants($project_id),
            ));
        });
    }
}
