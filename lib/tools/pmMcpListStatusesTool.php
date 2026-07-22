<?php

/**
 * pm_list_statuses — global task statuses, and optionally the statuses and
 * transition matrix of a specific workflow.
 */
class pmMcpListStatusesTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_list_statuses'; }
    public function getRight()       { return 'pm_list_statuses'; }
    public function getDescription() { return _wp('List global task statuses. Pass workflow_id to also get that workflow\'s statuses (with local names) and transition matrix.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'workflow_id' => array(
                    'type'        => 'string',
                    'maxLength'   => 100,
                    'description' => 'Optional workflow id/slug to describe in addition to the global statuses.',
                ),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $statuses = pmMcpWorkflowHelper::globalStatuses();

            $payload = array(
                'statuses' => $statuses,
                'count'    => count($statuses),
            );

            $workflow_id = $this->argString($arguments, 'workflow_id');
            if ($workflow_id !== '') {
                $workflow = pmMcpWorkflowHelper::describeWorkflow($workflow_id);
                if ($workflow === null) {
                    return $this->softFail('not_found', _wp('Workflow not found.'), array('workflow_id' => $workflow_id));
                }
                $payload['workflow'] = $workflow;
            }

            return $this->ok($payload);
        });
    }
}
