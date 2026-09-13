<?php

/**
 * pm_find_tasks_by_external — reverse lookup: which pm tasks are linked to a
 * given record in another integrated app (e.g. "which tasks were opened for
 * helpdesk request 412"). Mirrors pm's own
 * pmTaskActions::helpdeskLinkedTasksAction() / externalFindByTaskAction(),
 * which back the "Linked tasks" panel pm injects into the helpdesk request
 * page (and the equivalent crm/shop panels).
 *
 * Unlike helpdeskLinkedTasksAction(), which returns every linked task to
 * anyone with the plain pm.backend right, this filters to the projects the
 * caller can access — the same membership check every other read tool in
 * this plugin applies.
 *
 * `external.element_name` (the linked record's own title, fetched by raw SQL
 * — see pmMcpExternalHelper) is withheld unless at least one linked task is
 * actually visible to the caller (`count > 0`). Without that gate, a caller
 * with no membership in any project holding the link could still learn a
 * helpdesk request's summary or a crm deal's name through this tool, purely
 * because they knew (or guessed) its id — a side channel pm's own
 * `_doEnrich`/`helpdeskLinkedTasksAction` also has, but that this plugin does
 * not need to repeat given it already tracks per-task visibility here.
 */
class pmMcpFindTasksByExternalTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_find_tasks_by_external'; }
    public function getRight()       { return 'pm_find_tasks_by_external'; }
    public function getDescription() { return _wp('Find pm tasks linked to a record in another integrated app (helpdesk request, crm deal, shop order). Returns only tasks in projects you can access; hidden_count reports how many more exist in projects you cannot see, and stale_link_count how many link rows point at a task that no longer exists. The linked record\'s own name is only included once you can see at least one linked task.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('external_id'),
            'properties' => array(
                'app_id'      => array(
                    'type'        => 'string',
                    'enum'        => pmMcpExternalHelper::apps(),
                    'default'     => 'helpdesk',
                    'description' => 'The other app the record lives in. Defaults to helpdesk.',
                ),
                'external_id' => array(
                    'type'        => 'string',
                    'minLength'   => 1,
                    'description' => 'Id of the record in that app.',
                ),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $app_id = $this->argString($arguments, 'app_id', 'helpdesk');
            if (!in_array($app_id, pmMcpExternalHelper::apps(), true)) {
                return $this->softFail('invalid_param', sprintf(
                    _wp('Unknown app_id "%s". Use one of: %s.'),
                    $app_id,
                    implode(', ', pmMcpExternalHelper::apps())
                ));
            }

            $external_id = $this->argRef($arguments, 'external_id');
            if ($external_id === '') {
                return $this->softFail('invalid_param', _wp('external_id is required.'));
            }
            $normalized_id = pmMcpExternalHelper::normalizeExternalId($external_id);
            $lookup_id = $normalized_id !== '' ? $normalized_id : $external_id;

            $links = (new pmTaskExternalModel())->findByExternal($app_id, $lookup_id);
            // Legacy rows may still carry an un-normalised id; try the raw
            // string too when the normalised lookup found nothing.
            if (!$links && $normalized_id !== '' && $normalized_id !== $external_id) {
                $links = (new pmTaskExternalModel())->findByExternal($app_id, $external_id);
            }

            $task_ids = array_values(array_unique(array_map(function ($l) {
                return (int) $l['task_id'];
            }, $links)));

            $tasks = array();
            $hidden_count = 0;
            $stale_link_count = 0;
            if ($task_ids) {
                $rows = (new pmTaskModel())->select('*')->where('id IN (i:ids)', array('ids' => $task_ids))->fetchAll('id');
                foreach ($rows as $row) {
                    if (pmMcpProjectHelper::canAccess((int) $row['project_id'])) {
                        $tasks[] = pmMcpTaskHelper::formatTaskRow($row);
                    } else {
                        $hidden_count++;
                    }
                }
                // A link row whose task no longer exists (e.g. deleted through
                // pmTaskModel directly rather than pmTask::delete(), which is
                // the only path that cascades pm_task_external — see
                // pmTask.class.php:736) yields no row at all here. Counting it
                // as neither visible nor hidden would make a request with
                // stale links only read as "never linked" (count: 0,
                // hidden_count: 0), indistinguishable from the truth.
                $stale_link_count = count($task_ids) - count($rows);
            }

            $count = count($tasks);
            $external = pmMcpExternalHelper::describeExternal($app_id, $lookup_id);
            if ($count === 0) {
                // See the class docblock: the record's own name is withheld
                // unless the caller can see at least one task actually linked
                // to it, so knowing (or guessing) an id alone is not enough to
                // read another app's record through this tool.
                $external['element_name'] = null;
            }

            return $this->ok(array(
                'external'          => $external,
                'tasks'             => $tasks,
                'count'             => $count,
                'hidden_count'      => $hidden_count,
                'stale_link_count'  => $stale_link_count,
            ));
        });
    }
}
