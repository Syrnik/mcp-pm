<?php

/**
 * pm_get_sprint — a sprint card: its project(s), auto-fill source statuses and
 * automation settings. Access requires membership in the sprint's project.
 */
class pmMcpGetSprintTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_get_sprint'; }
    public function getRight()       { return 'pm_get_sprint'; }
    public function getDescription() { return _wp('Get a sprint card: project, auto-fill statuses and automation settings (auto-create-next, auto-close, move-unfinished, auto-fill). Requires membership in the sprint\'s project.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('sprint_id'),
            'properties' => array(
                'sprint_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Sprint id.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $sprint_id = $this->argInt($arguments, 'sprint_id');
            $sprint = (new pmSprintModel())->getById($sprint_id);
            if (!$sprint) {
                return $this->softFail('not_found', _wp('Sprint not found.'));
            }

            // A sprint belongs to a project; the user must be able to access it.
            // accessibleProjectIds() === null means app-admin (no restriction).
            $accessible = pmMcpProjectHelper::accessibleProjectIds();
            $project_ids = array_map('intval', $sprint['project_ids'] ?? array());
            if ($accessible !== null) {
                $shared = array_intersect($project_ids, $accessible);
                if (!$shared) {
                    return $this->softFail('access_denied', _wp('You are not a member of this sprint\'s project.'));
                }
            }

            return $this->ok(array(
                'sprint' => pmMcpSprintHelper::formatSprintCard($sprint),
            ));
        });
    }
}
