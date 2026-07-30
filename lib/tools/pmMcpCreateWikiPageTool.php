<?php

/**
 * pm_create_wiki_page — create a wiki section or article. Requires the
 * wiki.edit permission. Mirrors pmWikiActions::saveAction (a section never
 * stores content; sort is appended within the parent).
 */
class pmMcpCreateWikiPageTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_create_wiki_page'; }
    public function getRight()       { return 'pm_create_wiki_page'; }
    public function getDescription() { return _wp('Create a wiki section or article in a project. Requires the wiki.edit permission. A "section" is a folder and holds no content. Returns the created page.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('project_id', 'title'),
            'properties' => array(
                'project_id'   => array('type' => 'integer', 'minimum' => 1, 'description' => 'Project id.'),
                'title'        => array('type' => 'string', 'minLength' => 1, 'description' => 'Page title.'),
                'type'         => array('type' => 'string', 'enum' => array('article', 'section'), 'description' => 'Page type. Defaults to article. A section holds no content.'),
                'content'      => array('type' => 'string', 'description' => 'Page body (ignored for a section). Project content format, usually Markdown.'),
                'parent_id'    => array('type' => 'integer', 'minimum' => 0, 'description' => 'Parent page id (must belong to the same project). Omit or 0 for a top-level page.'),
                'published'    => array('type' => 'boolean', 'description' => 'Whether the page is published. Defaults to false (draft, visible only to the author and admins/managers).'),
                'is_public'    => array('type' => 'boolean', 'description' => 'Whether a published page is visible to everyone. Defaults to false.'),
                'access_roles' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Role slugs allowed to see a published, non-public page (e.g. ["member","viewer"]).'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $project_id = $this->argInt($arguments, 'project_id');
            pmMcpWikiHelper::loadProject($project_id);
            pmMcpWikiHelper::requireEdit($project_id);

            $title = $this->argString($arguments, 'title');
            if ($title === '') {
                return $this->softFail('invalid_param', _wp('Page title is required.'));
            }

            $type = $this->argString($arguments, 'type', 'article');
            if (!in_array($type, array('section', 'article'), true)) {
                $type = 'article';
            }

            $model = new pmWikiPageModel();

            $parent_id = $this->argInt($arguments, 'parent_id');
            if ($parent_id > 0) {
                $parent = $model->getById($parent_id);
                if (!$parent || (int) $parent['project_id'] !== $project_id) {
                    return $this->softFail('invalid_param', _wp('Parent page not found in this project.'));
                }
            } else {
                $parent_id = null;
            }

            $access_roles = pmMcpWikiHelper::buildAccessRoles($arguments['access_roles'] ?? null);

            $data = array(
                'project_id'        => $project_id,
                'type'              => $type,
                'title'             => $title,
                'content'           => $type === 'section' ? '' : $this->argString($arguments, 'content'),
                'parent_id'         => $parent_id,
                'published'         => $this->argBool($arguments, 'published') ? 1 : 0,
                'is_public'         => $this->argBool($arguments, 'is_public') ? 1 : 0,
                'access_roles'      => $access_roles,
                'sort'              => $model->getMaxSort($project_id, $parent_id) + 1,
                'create_contact_id' => $this->getUserId(),
                'create_datetime'   => date('Y-m-d H:i:s'),
                'update_datetime'   => date('Y-m-d H:i:s'),
            );

            $page_id = $model->insert($data);

            return $this->ok(array(
                'page_id' => (int) $page_id,
                'page'    => pmMcpWikiHelper::formatPage($model->getById($page_id), true),
            ));
        });
    }
}
