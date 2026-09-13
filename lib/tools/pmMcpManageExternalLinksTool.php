<?php

/**
 * pm_manage_external_links — link a task to a record in another integrated
 * app (helpdesk request, crm deal, shop order) or unlink it.
 *
 * pm keeps this as one generic table (pm_task_external) shared by all three
 * apps rather than a helpdesk-specific one — see pmMcpExternalHelper's
 * docblock — so this tool covers all three rather than just helpdesk.
 *
 * Goes through pmTask::addExternalLink()/removeExternalLink()
 * (pmTask.class.php), the only path in pm that checks task.edit before
 * writing; pm's own backend controllers (externalAddAction/
 * externalRemoveAction) write pmTaskExternalModel directly and skip the
 * check entirely.
 *
 * No activity-log entry. addExternalLink()/removeExternalLink() do not call
 * pmTask::log(), unlike sibling mutations (checklist items, tags), and
 * externalAddAction doesn't log it from the controller side either — so pm
 * itself never records this action anywhere in a task's history. Unlike
 * pm_manage_dependencies (which replicates a log entry the pm controller
 * genuinely writes), inventing an activity-log key here would be a key pm's
 * own activity feed has no renderer for. Left un-logged on purpose.
 */
class pmMcpManageExternalLinksTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_manage_external_links'; }
    public function getRight()       { return 'pm_manage_external_links'; }
    public function getDescription() { return _wp('Link a task to a record in another integrated app (helpdesk request, crm deal, shop order) or remove that link. Requires the task.edit permission. Returns the task\'s external links after the change.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('task_id', 'action', 'app_id', 'external_id'),
            'properties' => array(
                'task_id'     => self::taskRefSchema('The task whose external links to manage.'),
                'action'      => array('type' => 'string', 'enum' => array('add', 'remove'), 'description' => 'Link or unlink.'),
                'app_id'      => array(
                    'type'        => 'string',
                    'enum'        => pmMcpExternalHelper::apps(),
                    'description' => 'The other app the record lives in.',
                ),
                'external_id' => array(
                    'type'        => 'string',
                    'minLength'   => 1,
                    'description' => 'Id of the record in that app (the helpdesk request id, crm deal id, or shop order id).',
                ),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $action = $this->argString($arguments, 'action');
            $app_id = $this->argString($arguments, 'app_id');
            $raw_external_id = $this->argRef($arguments, 'external_id');

            $entity = pmMcpTaskHelper::loadTaskEntity($this->argRef($arguments, 'task_id'), $row);
            $task_id = (int) $row['id'];

            if (!$entity->canEdit($this->getUserId())) {
                return $this->softFail('access_denied', _wp('You do not have permission to edit this task\'s external links.'));
            }

            $normalized_id = pmMcpExternalHelper::normalizeExternalId($raw_external_id);
            if ($normalized_id === '') {
                return $this->softFail('invalid_param', _wp('external_id must be a positive integer id.'));
            }

            return $action === 'add'
                ? $this->add($app_id, $normalized_id, $task_id, $entity, $raw_external_id)
                : $this->remove($app_id, $normalized_id, $task_id, $entity, $raw_external_id);
        });
    }

    protected function add($app_id, $external_id, $task_id, pmTask $entity, $raw_external_id)
    {
        pmMcpExternalHelper::assertLinkable($app_id);

        $model = new pmTaskExternalModel();
        if ($model->getByField(array('task_id' => $task_id, 'app_id' => $app_id, 'external_id' => $external_id))) {
            return $this->ok(array(
                'task_id'        => $task_id,
                'action'         => 'add',
                'already_exists' => true,
                'external_links' => pmMcpExternalHelper::forTask($task_id),
            ));
        }

        if (!pmMcpExternalHelper::externalExists($app_id, $external_id)) {
            return $this->softFail(
                'not_found',
                sprintf(_wp('No %1$s record with id %2$s was found; the link was not created.'), $app_id, $external_id)
            );
        }

        $entity->addExternalLink($app_id, $external_id, $this->getUserId());

        return $this->ok(array(
            'task_id'        => $task_id,
            'action'         => 'add',
            'already_exists' => false,
            'external_links' => pmMcpExternalHelper::forTask($task_id),
        ));
    }

    protected function remove($app_id, $external_id, $task_id, pmTask $entity, $raw_external_id)
    {
        $model = new pmTaskExternalModel();

        // Normalisation is a rule this tool introduces, not a property of rows
        // already in the table: a link written by the pm UI, or by an older
        // version of this plugin, may still carry an un-normalised id
        // ("0123"). Try the normalised id first, then the caller's raw string,
        // so a link the task card is showing is never unreachable through this
        // same tool.
        $existing = $model->getByField(array('task_id' => $task_id, 'app_id' => $app_id, 'external_id' => $external_id));
        $lookup_id = $external_id;
        if (!$existing && $raw_external_id !== $external_id) {
            $existing = $model->getByField(array('task_id' => $task_id, 'app_id' => $app_id, 'external_id' => $raw_external_id));
            if ($existing) {
                $lookup_id = $raw_external_id;
            }
        }

        if (!$existing) {
            return $this->softFail('not_found', _wp('That link does not exist on this task.'));
        }

        $entity->removeExternalLink($app_id, $lookup_id, $this->getUserId());

        return $this->ok(array(
            'task_id'        => $task_id,
            'action'         => 'remove',
            'removed'        => true,
            'external_links' => pmMcpExternalHelper::forTask($task_id),
        ));
    }
}
