<?php

/**
 * pm_get_workflow — the workflows attached to a project: statuses (with
 * workflow-local names) and the transition matrix of each.
 */
class pmMcpGetWorkflowTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_get_workflow'; }
    public function getRight()       { return 'pm_get_workflow'; }
    public function getDescription() { return _wp('Get the workflows of a project: each workflow\'s statuses (with local names) and transition matrix. Requires project membership.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('project_id'),
            'properties' => array(
                'project_id' => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Project id whose workflows to describe.',
                ),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $project_id = $this->argInt($arguments, 'project_id');
            $project = pmMcpProjectHelper::loadAccessibleProject($project_id);

            $workflows = pmMcpWorkflowHelper::describeProjectWorkflows($project);

            return $this->ok(array(
                'project_id' => (int) $project['id'],
                'workflows'  => $workflows,
                'count'      => count($workflows),
            ));
        });
    }
}
