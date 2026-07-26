<?php

/**
 * pm_list_tasks — list tasks across the projects the current user can access,
 * with the full pm filter set and offset/limit pagination.
 *
 * Filtering is delegated to pmTaskModel::getByStatus() (the same query the pm
 * backend board uses), which returns tasks grouped by status; this tool
 * flattens the groups into one ordered, paginated list.
 */
class pmMcpListTasksTool extends pmMcpToolBase
{
    const DEFAULT_LIMIT = 50;
    const MAX_LIMIT     = 200;

    public function getName()        { return 'pm_list_tasks'; }
    public function getRight()       { return 'pm_list_tasks'; }
    public function getDescription() { return _wp('List tasks the current user can access, filtered by project, status, assignee, sprint (-1 = backlog), milestone, type, priority, tag or a subject search. Paginated.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'project_id' => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Restrict to one project. Omit to span every project the user can access.',
                ),
                'status_id' => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Filter by task status id.',
                ),
                'assignee_contact_id' => array(
                    'type'        => 'integer',
                    'description' => 'Filter by assignee contact id. Use -1 for unassigned tasks.',
                ),
                'sprint_id' => array(
                    'type'        => 'integer',
                    'description' => 'Filter by sprint id. Use -1 for the backlog (no sprint, open statuses).',
                ),
                'milestone_id' => array(
                    'type'        => 'integer',
                    'description' => 'Filter by milestone id. Use -1 for tasks with no milestone.',
                ),
                'type_slug' => array(
                    'type'        => 'string',
                    'description' => 'Filter by task type slug.',
                ),
                'priority' => array(
                    'type'        => 'string',
                    'description' => 'Filter by priority (e.g. low, normal, high).',
                ),
                'tag_id' => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Filter by tag id.',
                ),
                'search' => array(
                    'type'        => 'string',
                    'description' => 'Substring match against the task subject. A task id ("32") or full task number ("AUTH-32") also returns that exact task.',
                ),
                'limit' => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                    'description' => 'Max tasks to return (default ' . self::DEFAULT_LIMIT . ', max ' . self::MAX_LIMIT . ').',
                ),
                'offset' => array(
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'description' => 'Number of tasks to skip (default 0).',
                ),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $project_ids = pmMcpTaskHelper::resolveProjectIds($this->argInt($arguments, 'project_id'));
            if (!$project_ids) {
                return $this->ok(array('tasks' => array(), 'count' => 0, 'total' => 0, 'offset' => 0, 'limit' => self::DEFAULT_LIMIT));
            }

            // Build the pmTaskModel filter set. Empty/zero values are skipped by
            // getByStatus() itself; -1 sentinels for assignee/sprint/milestone
            // are honoured there.
            $filters = array();
            if (isset($arguments['assignee_contact_id']) && $arguments['assignee_contact_id'] !== '') {
                $filters['assignee_contact_id'] = (int) $arguments['assignee_contact_id'];
            }
            if (isset($arguments['sprint_id']) && $arguments['sprint_id'] !== '') {
                $filters['sprint_id'] = (int) $arguments['sprint_id'];
            }
            if (isset($arguments['milestone_id']) && $arguments['milestone_id'] !== '') {
                $filters['milestone_id'] = (int) $arguments['milestone_id'];
            }
            $type_slug = $this->argString($arguments, 'type_slug');
            if ($type_slug !== '') {
                $filters['type_slug'] = $type_slug;
            }
            $priority = $this->argString($arguments, 'priority');
            if ($priority !== '') {
                $filters['priority'] = $priority;
            }
            $tag_id = $this->argInt($arguments, 'tag_id');
            if ($tag_id > 0) {
                $filters['tag_id'] = $tag_id;
            }
            $search = $this->argString($arguments, 'search');
            if ($search !== '') {
                $filters['search'] = $search;
            }

            $by_status = (new pmTaskModel())->getByStatus($project_ids, $filters);

            // Flatten the status groups, applying the status_id filter (which
            // getByStatus does not handle) here.
            $status_id = $this->argInt($arguments, 'status_id');
            $flat = array();
            foreach ($by_status as $sid => $group) {
                if ($status_id > 0 && (int) $sid !== $status_id) {
                    continue;
                }
                foreach ($group as $t) {
                    $flat[] = $t;
                }
            }

            // A search term that reads as a task reference ("32", "AUTH-32")
            // returns that task too: the subject match alone would miss it, and
            // this is how a user quotes a task they already know.
            if ($search !== '') {
                $referenced = pmMcpTaskHelper::findAccessibleTask($search);
                if ($referenced && in_array((int) $referenced['project_id'], $project_ids, true)) {
                    $known = false;
                    foreach ($flat as $t) {
                        if ((int) $t['id'] === (int) $referenced['id']) {
                            $known = true;
                            break;
                        }
                    }
                    if (!$known && ($status_id <= 0 || (int) $referenced['status_id'] === $status_id)) {
                        array_unshift($flat, $referenced);
                    }
                }
            }

            // Global order: sort, then id — matching the pm board ordering.
            usort($flat, function ($a, $b) {
                $sa = (int) $a['sort'];
                $sb = (int) $b['sort'];
                if ($sa !== $sb) {
                    return $sa <=> $sb;
                }
                return (int) $a['id'] <=> (int) $b['id'];
            });

            $total  = count($flat);
            $limit  = $this->argInt($arguments, 'limit', self::DEFAULT_LIMIT);
            $limit  = max(1, min(self::MAX_LIMIT, $limit));
            $offset = max(0, $this->argInt($arguments, 'offset', 0));

            $page = array_slice($flat, $offset, $limit);
            $tasks = array();
            foreach ($page as $t) {
                $tasks[] = pmMcpTaskHelper::formatTaskRow($t);
            }

            return $this->ok(array(
                'tasks'  => $tasks,
                'count'  => count($tasks),
                'total'  => $total,
                'offset' => $offset,
                'limit'  => $limit,
            ));
        });
    }
}
