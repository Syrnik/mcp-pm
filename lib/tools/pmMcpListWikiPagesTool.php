<?php

/**
 * pm_list_wiki_pages — the wiki page tree of a project, filtered by the
 * caller's per-page visibility (pmWikiActions::canUserSeePage). Page content is
 * omitted here; use pm_get_wiki_page to read a page's body.
 */
class pmMcpListWikiPagesTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_list_wiki_pages'; }
    public function getRight()       { return 'pm_list_wiki_pages'; }
    public function getDescription() { return _wp('List the wiki page tree of a project (metadata only, no content), filtered by the caller\'s visibility. Requires the wiki.view or wiki.edit permission.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('project_id'),
            'properties' => array(
                'project_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Project id.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $project_id = $this->argInt($arguments, 'project_id');
            $project = pmMcpWikiHelper::loadProject($project_id);
            $ctx = pmMcpWikiHelper::requireView($project_id);

            $contact_id = $this->getUserId();
            $tree = (new pmWikiPageModel())->getTree($project_id);

            $pages = array();
            foreach ($tree['pages'] as $id => $page) {
                if (pmMcpWikiHelper::canUserSeePage($page, $contact_id, $ctx['role'], $ctx['is_admin_manager'])) {
                    $pages[] = pmMcpWikiHelper::formatPage($page);
                }
            }

            // Rebuild the parent => child-ids map over the visible set only.
            // Key "0" holds the top-level pages (parent_id NULL). Cast to an
            // object so JSON always encodes it as a map, never as an array when
            // the parent ids happen to be sequential from zero.
            $visible_ids = array();
            foreach ($pages as $p) {
                $visible_ids[$p['id']] = true;
            }
            $children = array();
            foreach ($tree['children'] as $parent_id => $child_ids) {
                $kept = array_values(array_filter($child_ids, function ($cid) use ($visible_ids) {
                    return isset($visible_ids[$cid]);
                }));
                if ($kept) {
                    $children[(string) (int) $parent_id] = array_map('intval', $kept);
                }
            }

            return $this->ok(array(
                'project_id'     => $project_id,
                'content_format' => $project['content_format'] ?? 'md',
                'can_edit'       => pmHelper::hasPermission($ctx['role'], 'wiki.edit'),
                'pages'          => $pages,
                'children'       => (object) $children,
                'count'          => count($pages),
            ));
        });
    }
}
