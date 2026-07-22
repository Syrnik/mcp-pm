<?php

/**
 * pm_list_sprints — the sprints of a project with their statuses. Sprints are
 * per-project; the backlog (tasks with sprint_id IS NULL) is not a sprint and
 * is reached through pm_list_tasks (sprint_id = -1).
 */
class pmMcpListSprintsTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_list_sprints'; }
    public function getRight()       { return 'pm_list_sprints'; }
    public function getDescription() { return _wp('List a project\'s sprints (active first, then planned, then completed) with automation settings. Requires project membership. Backlog tasks are listed via pm_list_tasks with sprint_id=-1.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('project_id'),
            'properties' => array(
                'project_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Project id.'),
                'status'     => array('type' => 'string', 'enum' => array('planned', 'active', 'completed'), 'description' => 'Optional filter by sprint status.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $project_id = $this->argInt($arguments, 'project_id');
            pmMcpProjectHelper::loadAccessibleProject($project_id);

            $status_filter = $this->argString($arguments, 'status');

            $rows = array();
            foreach ((new pmSprintModel())->getByProject($project_id) as $s) {
                if ($status_filter !== '' && $s['status'] !== $status_filter) {
                    continue;
                }
                $rows[] = pmMcpSprintHelper::formatSprintRow($s);
            }
            pmMcpSprintHelper::sortSprints($rows);

            return $this->ok(array(
                'project_id' => $project_id,
                'sprints'    => $rows,
                'count'      => count($rows),
            ));
        });
    }
}
