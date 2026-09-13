<?php

/**
 * pm_get_sprint — a sprint card: its project(s), per-project workflow
 * selection, auto-fill source statuses and automation settings. A sprint can
 * span several projects; access requires membership in at least one of them.
 * Projects you are not a member of are stripped from project_ids/workflows —
 * hidden_project_count says how many were hidden.
 */
class pmMcpGetSprintTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_get_sprint'; }
    public function getRight()       { return 'pm_get_sprint'; }
    public function getDescription() { return _wp('Get a sprint card: projects, per-project workflow selection, auto-fill statuses and automation settings (auto-create-next, auto-close, move-unfinished, auto-fill). Requires membership in at least one of the sprint\'s projects; projects you cannot access are hidden from the card (see hidden_project_count).'); }

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

            // A sprint belongs to one or more projects; the user must be able
            // to access at least one. accessibleProjectIds() === null means
            // app-admin (no restriction).
            $accessible = pmMcpProjectHelper::accessibleProjectIds();
            $project_ids = array_map('intval', $sprint['project_ids'] ?? array());
            if ($accessible !== null && !array_intersect($project_ids, $accessible)) {
                return $this->softFail('access_denied', _wp('You are not a member of any of this sprint\'s projects.'));
            }

            $sprint = pmMcpSprintHelper::narrowToAccessible($sprint, $accessible);
            $card = pmMcpSprintHelper::formatSprintCard($sprint);
            $card['can_edit'] = pmSprint::canEdit($this->getUserId(), $project_ids);

            return $this->ok(array('sprint' => $card));
        });
    }
}
