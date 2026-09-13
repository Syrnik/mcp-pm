<?php

/**
 * pm_list_sprints — sprints the current user can see, with their statuses and
 * automation settings. A sprint can span several projects, so it is
 * addressed by project membership rather than owned by a single project: omit
 * project_id to see every sprint you can access (a cross-project sprint is
 * listed once), or pass it to restrict to sprints linked to one project. The
 * backlog (tasks with sprint_id = 0) is not a sprint and is reached
 * through pm_list_tasks (sprint_id = -1).
 */
class pmMcpListSprintsTool extends pmMcpToolBase
{
    const DEFAULT_LIMIT = 50;
    const MAX_LIMIT     = 200;

    public function getName()        { return 'pm_list_sprints'; }
    public function getRight()       { return 'pm_list_sprints'; }
    public function getDescription() { return _wp('List sprints you can access (active first, then planned, then completed) with automation settings. Omit project_id to span every project you can access; a sprint spanning several projects is returned once. Projects you cannot access are stripped from project_ids/workflows on each row — hidden_project_count and access_narrowed flag when that happened. Paginated. Backlog tasks are listed via pm_list_tasks with sprint_id=-1.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'project_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Restrict to sprints linked to one project. Omit to span every project you can access.'),
                'status'     => array('type' => 'string', 'enum' => array('planned', 'active', 'completed'), 'description' => 'Optional filter by sprint status.'),
                'limit'      => array('type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'Max sprints to return (default ' . self::DEFAULT_LIMIT . ', max ' . self::MAX_LIMIT . ').'),
                'offset'     => array('type' => 'integer', 'minimum' => 0, 'description' => 'Number of sprints to skip (default 0).'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $project_id = $this->argInt($arguments, 'project_id');
            $accessible = pmMcpProjectHelper::accessibleProjectIds();

            $limit  = max(1, min(self::MAX_LIMIT, $this->argInt($arguments, 'limit', self::DEFAULT_LIMIT)));
            $offset = max(0, $this->argInt($arguments, 'offset', 0));

            if ($project_id > 0) {
                // Existing behaviour for an explicit project_id: not_found /
                // access_denied when the caller cannot see that project at all.
                pmMcpProjectHelper::loadAccessibleProject($project_id);
                $sprints = pmMcpSprintHelper::loadSprints($project_id, $accessible);
            } elseif ($accessible !== null && !$accessible) {
                // No project_id and no accessible projects: nothing to show,
                // not an error (mirrors pm_list_tasks's empty-project-set path).
                $sprints = array();
            } else {
                $sprints = pmMcpSprintHelper::loadSprints(null, $accessible);
            }

            $status_filter = $this->argString($arguments, 'status');

            $rows = array();
            foreach ($sprints as $s) {
                if ($status_filter !== '' && $s['status'] !== $status_filter) {
                    continue;
                }
                $rows[] = pmMcpSprintHelper::formatSprintRow(pmMcpSprintHelper::narrowToAccessible($s, $accessible));
            }
            pmMcpSprintHelper::sortSprints($rows);
            pmMcpSprintHelper::decorateCanEdit($rows);

            $total = count($rows);
            $page  = array_slice($rows, $offset, $limit);

            $access_narrowed = false;
            foreach ($page as $r) {
                if ($r['hidden_project_count'] > 0) {
                    $access_narrowed = true;
                    break;
                }
            }

            return $this->ok(array(
                'project_id'       => $project_id > 0 ? $project_id : null,
                'sprints'          => $page,
                'count'            => count($page),
                'total'            => $total,
                'offset'           => $offset,
                'limit'            => $limit,
                'has_more'         => $offset + count($page) < $total,
                'access_narrowed'  => $access_narrowed,
            ));
        });
    }
}
