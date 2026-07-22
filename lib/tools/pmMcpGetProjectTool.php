<?php

/**
 * pm_get_project — full project card: properties, participants (with roles),
 * workflows (statuses + transitions) and milestones.
 */
class pmMcpGetProjectTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_get_project'; }
    public function getRight()       { return 'pm_get_project'; }
    public function getDescription() { return _wp('Get a project card: properties, participants with roles, workflows and milestones. Requires project membership.'); }

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
            $project = pmMcpProjectHelper::loadAccessibleProject($project_id);

            return $this->ok(array(
                'project'      => pmMcpProjectHelper::formatProject($project, true),
                'participants' => pmMcpProjectHelper::formatParticipants($project_id),
                'workflows'    => pmMcpWorkflowHelper::describeProjectWorkflows($project),
                'milestones'   => pmMcpProjectHelper::formatMilestones($project_id),
            ));
        });
    }
}
