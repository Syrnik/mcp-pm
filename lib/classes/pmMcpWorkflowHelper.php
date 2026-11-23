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
     * The task types a workflow accepts: the types of its type group, or —
     * when the group is unset or unknown — every configured type, the same
     * fallback pm_create_task has always used. Raw pmTypeConfig entries.
     *
     * @param array|null $wf  pmWorkflow::getWorkflow() result.
     * @return array[]
     */
    public static function typeOptions(?array $wf)
    {
        $type_group = $wf['type_group'] ?? null;
        $group = $type_group ? pmTypeConfig::getGroup($type_group) : null;
        return array_values($group['types'] ?? pmTypeConfig::getAllTypes());
    }

    /**
     * Serialise type entries to the shape pm's REST pm.workflow.get uses
     * ({slug, name, color, icon}). Shared by the workflow `types` field and
     * the `available_types` list of pm_create_task errors.
     *
     * @param array[] $type_options
     * @return array[]
     */
    public static function formatTypes(array $type_options)
    {
        $out = array();
        foreach ($type_options as $t) {
            $out[] = array(
                'slug'  => $t['slug'],
                'name'  => $t['name'] ?? $t['slug'],
                'color' => $t['color'] ?? null,
                'icon'  => $t['icon'] ?? null,
            );
        }
        return $out;
    }

    /**
     * The project's default task type for a workflow (set in the project
     * settings, pm_project_workflows.default_type_slug, pm >= 0.75.0) — or
     * null when none is set or the stored slug no longer belongs to the
     * workflow's type options (a stale setting left behind by a type-config
     * change; pm's own task form would not offer it either).
     *
     * @param int $project_id
     * @param string $workflow_id
     * @param array[]|null $type_options  typeOptions() of the workflow (computed when null).
     * @param array|null $defaults        getWorkflowsWithTypes() of the project (loaded when null).
     * @return string|null
     */
    public static function defaultTypeSlug($project_id, $workflow_id, ?array $type_options = null, ?array $defaults = null)
    {
        if ($defaults === null) {
            $defaults = (new pmProjectWorkflowModel())->getWorkflowsWithTypes((int) $project_id);
        }
        $slug = (string) ($defaults[$workflow_id] ?? '');
        if ($slug === '') {
            return null;
        }
        if ($type_options === null) {
            $type_options = self::typeOptions(pmWorkflow::getWorkflow($workflow_id));
        }
        return in_array($slug, array_column($type_options, 'slug'), true) ? $slug : null;
    }

    /**
     * Describe one workflow by id: its statuses (with workflow-local names),
     * transition matrix and accepted task types. Returns null when the
     * workflow is unknown.
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
            'types'       => self::formatTypes(self::typeOptions($wf)),
        );
    }

    /**
     * Describe every workflow attached to a project. On top of
     * describeWorkflow(), each entry carries the project's
     * default_type_slug for that workflow (null when unset).
     *
     * @param array $project  The pm_project row.
     * @return array[]
     */
    public static function describeProjectWorkflows(array $project)
    {
        $all_statuses = (new pmStatusModel())->getAllStatuses();
        $defaults = (new pmProjectWorkflowModel())->getWorkflowsWithTypes((int) $project['id']);
        $out = array();
        foreach (pmWorkflow::parseWorkflowIds($project) as $wf_id) {
            $described = self::describeWorkflow($wf_id, $all_statuses);
            if ($described !== null) {
                $described['default_type_slug'] = self::defaultTypeSlug(
                    $project['id'],
                    $wf_id,
                    self::typeOptions(pmWorkflow::getWorkflow($wf_id)),
                    $defaults
                );
                $out[] = $described;
            }
        }
        return $out;
    }
}
