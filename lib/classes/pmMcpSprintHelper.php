<?php

/**
 * Shared helpers for the pm sprint read tools (Stage 6).
 *
 * NOTE on the data model: sprints are per-project. The pm schema still carries
 * an M:N `pm_sprint_project` link table (and a matching pmSprintProjectModel)
 * from an earlier cross-project design that was abandoned; pmSprintModel keeps
 * populating a `project_ids` array through it, so we read through that
 * mechanism but treat a sprint as belonging to a single project (its first,
 * and in practice only, linked project). We expose both `project_id` (the
 * owning project) and the raw `project_ids` for transparency.
 */
class pmMcpSprintHelper
{
    /**
     * Serialise a pm_sprint row (as returned by pmSprintModel, i.e. already
     * decorated with project_ids and fill_status_ids).
     */
    public static function formatSprintRow(array $s)
    {
        $project_ids = array_map('intval', $s['project_ids'] ?? array());
        return array(
            'id'                 => (int) $s['id'],
            'name'               => $s['name'],
            'goal'               => $s['goal'] ?? '',
            'status'             => $s['status'],
            'start_date'         => $s['start_date'] ?? null,
            'end_date'           => $s['end_date'] ?? null,
            'duration_weeks'     => (int) ($s['duration'] ?? 1),
            'sort'               => (int) ($s['sort'] ?? 0),
            'previous_sprint_id' => !empty($s['previous_sprint_id']) ? (int) $s['previous_sprint_id'] : null,
            'project_id'         => $project_ids ? $project_ids[0] : null,
            'project_ids'        => $project_ids,
            'fill_status_ids'    => array_map('intval', $s['fill_status_ids'] ?? array()),
            'automation'         => array(
                'auto_create_next' => (bool) ($s['auto_create_next'] ?? 0),
                'auto_close'       => (bool) ($s['auto_close'] ?? 0),
                'move_unfinished'  => (bool) ($s['move_unfinished'] ?? 0),
                'auto_fill'        => (bool) ($s['auto_fill'] ?? 0),
            ),
        );
    }

    /**
     * Full sprint card: the row plus expanded projects and auto-fill statuses.
     *
     * @param array      $s            pm_sprint row (decorated).
     * @param array|null $all_statuses pm_status rows keyed by id (loaded when null).
     */
    public static function formatSprintCard(array $s, ?array $all_statuses = null)
    {
        $card = self::formatSprintRow($s);

        // Expand linked projects (id, name, color, icon).
        $project_model = new pmProjectModel();
        $projects = array();
        foreach ($card['project_ids'] as $pid) {
            $p = $project_model->getById($pid);
            if ($p) {
                $projects[] = array(
                    'id'    => (int) $p['id'],
                    'name'  => $p['name'],
                    'color' => $p['color'] ?? null,
                    'icon'  => $p['icon'] ?? null,
                );
            }
        }
        $card['projects'] = $projects;

        // Expand the auto-fill source statuses (id + name).
        if ($all_statuses === null) {
            $all_statuses = (new pmStatusModel())->getAllStatuses();
        }
        $fill = array();
        foreach ($card['fill_status_ids'] as $sid) {
            if (isset($all_statuses[$sid])) {
                $fill[] = pmMcpWorkflowHelper::formatStatus($all_statuses[$sid]);
            }
        }
        $card['fill_statuses'] = $fill;

        return $card;
    }

    /**
     * Sort sprints the way the pm UI does: active, then planned, then completed;
     * within a group by start_date (completed newest-first, others oldest-first).
     */
    public static function sortSprints(array &$rows)
    {
        $order = array('active' => 0, 'planned' => 1, 'completed' => 2);
        usort($rows, function ($a, $b) use ($order) {
            $sa = $order[$a['status']] ?? 99;
            $sb = $order[$b['status']] ?? 99;
            if ($sa !== $sb) {
                return $sa - $sb;
            }
            if ($a['status'] === 'completed') {
                return strcmp($b['start_date'] ?? '', $a['start_date'] ?? '');
            }
            return strcmp($a['start_date'] ?? '', $b['start_date'] ?? '');
        });
    }
}
