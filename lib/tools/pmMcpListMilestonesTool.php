<?php

/**
 * pm_list_milestones — milestones of a project.
 */
class pmMcpListMilestonesTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_list_milestones'; }
    public function getRight()       { return 'pm_list_milestones'; }
    public function getDescription() { return _wp('List the milestones of a project. Requires project membership.'); }

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

            $milestones = pmMcpProjectHelper::formatMilestones($project_id);

            return $this->ok(array(
                'project_id' => $project_id,
                'milestones' => $milestones,
                'count'      => count($milestones),
            ));
        });
    }
}
