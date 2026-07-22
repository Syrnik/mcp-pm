<?php

/**
 * pm_list_tags — tags defined in a project.
 */
class pmMcpListTagsTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_list_tags'; }
    public function getRight()       { return 'pm_list_tags'; }
    public function getDescription() { return _wp('List the tags defined in a project. Requires project membership.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('project_id'),
            'properties' => array(
                'project_id' => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Project id.',
                ),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $project_id = $this->argInt($arguments, 'project_id');
            pmMcpProjectHelper::loadAccessibleProject($project_id);

            $tags = array();
            foreach ((new pmTagModel())->getByProject($project_id) as $t) {
                $tags[] = array(
                    'id'    => (int) $t['id'],
                    'name'  => $t['name'],
                    'color' => $t['color'] ?? null,
                );
            }

            return $this->ok(array(
                'project_id' => $project_id,
                'tags'       => $tags,
                'count'      => count($tags),
            ));
        });
    }
}
