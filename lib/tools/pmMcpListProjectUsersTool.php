<?php

/**
 * pm_list_project_users — participants of a project with their roles.
 */
class pmMcpListProjectUsersTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_list_project_users'; }
    public function getRight()       { return 'pm_list_project_users'; }
    public function getDescription() { return _wp('List the participants of a project with their roles. Requires project membership.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('project_id'),
            'properties' => array(
                'project_id' => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Project id.',
                ),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $project_id = $this->argInt($arguments, 'project_id');
            pmMcpProjectHelper::loadAccessibleProject($project_id);

            $users = pmMcpProjectHelper::formatParticipants($project_id);

            return $this->ok(array(
                'project_id' => $project_id,
                'users'      => $users,
                'count'      => count($users),
            ));
        });
    }
}
