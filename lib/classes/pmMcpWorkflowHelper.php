<?php

/**
 * Shared helpers for describing pm workflows and statuses.
 *
 * Workflows are defined in the app config (lib/config/data/workflow.php) and
 * accessed through pmWorkflow. Statuses are global rows (pm_status), each
 * workflow references a subset of them and may override their display name.
 */
class pmMcpWorkflowHelper
{
    /**
     * All global statuses as a flat list.
     *
     * @return array[]
     */
    public static function globalStatuses()
    {
        $statuses = (new pmStatusModel())->getAllStatuses();
        $out = array();
        foreach ($statuses as $s) {
            $out[] = self::formatStatus($s);
        }
        return $out;
    }

    /**
     * Serialise a single pm_status row, optionally with a workflow-local name.
     */
    public static function formatStatus(array $s, $local_name = null)
    {
        return array(
            'id'            => (int) $s['id'],
            'name'          => $local_name !== null && $local_name !== '' ? $local_name : $s['name'],
            'global_name'   => $s['name'],
            'color'         => $s['color'] ?? null,
            'icon'          => $s['icon'] ?? null,
            'is_closed'     => (bool) $s['is_closed'],
            'category_slug' => $s['category_slug'] ?? null,
        );
    }

    /**
     * Describe one workflow by id: its statuses (with workflow-local names)
     * and transition matrix. Returns null when the workflow is unknown.
     *
     * @param string $workflow_id
     * @param array|null $all_statuses  pm_status rows keyed by id (loaded once when null).
     * @return array|null
     */
    public static function describeWorkflow($workflow_id, ?array $all_statuses = null)
    {
        $wf = pmWorkflow::getWorkflow($workflow_id);
        if (!$wf) {
            return null;
        }
        if ($all_statuses === null) {
            $all_statuses = (new pmStatusModel())->getAllStatuses();
        }

        $statuses = array();
        foreach ($wf['statuses'] ?? array() as $sid) {
            $s = $all_statuses[(int) $sid] ?? null;
            if ($s) {
                $local = $wf['status_names'][$sid] ?? null;
                $statuses[] = self::formatStatus($s, $local);
            }
        }

        return array(
            'id'          => $workflow_id,
            'type_group'  => $wf['type_group'] ?? null,
            'statuses'    => $statuses,
            'transitions' => $wf['transitions'] ?? array(),
        );
    }

    /**
     * Describe every workflow attached to a project.
     *
     * @param array $project  The pm_project row.
     * @return array[]
     */
    public static function describeProjectWorkflows(array $project)
    {
        $all_statuses = (new pmStatusModel())->getAllStatuses();
        $out = array();
        foreach (pmWorkflow::parseWorkflowIds($project) as $wf_id) {
            $described = self::describeWorkflow($wf_id, $all_statuses);
            if ($described !== null) {
                $out[] = $described;
            }
        }
        return $out;
    }
}
