<?php

/**
 * pm_list_projects — list projects the current user may access, optionally
 * filtered by status.
 */
class pmMcpListProjectsTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_list_projects'; }
    public function getRight()       { return 'pm_list_projects'; }
    public function getDescription() { return _wp('List projects the current user has access to, optionally filtered by status (planned, active, completed).'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'status' => array(
                    'type'        => 'string',
                    'enum'        => array('planned', 'active', 'completed'),
                    'description' => 'Optional status filter.',
                ),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $status = $this->argString($arguments, 'status');
            $accessible = pmMcpProjectHelper::accessibleProjectIds();

            $projects = (new pmProjectModel())->order('sort ASC, id ASC')->fetchAll();

            $result = array();
            foreach ($projects as $p) {
                if ($accessible !== null && !in_array((int) $p['id'], $accessible, true)) {
                    continue;
                }
                if ($status !== '' && $p['status'] !== $status) {
                    continue;
                }
                $result[] = pmMcpProjectHelper::formatProject($p);
            }

            return $this->ok(array(
                'projects' => $result,
                'count'    => count($result),
            ));
        });
    }
}
