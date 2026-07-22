<?php

/**
 * pm_get_wiki_page — a single wiki page including its content. Enforces both
 * the wiki.view permission and the per-page visibility rule.
 */
class pmMcpGetWikiPageTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_get_wiki_page'; }
    public function getRight()       { return 'pm_get_wiki_page'; }
    public function getDescription() { return _wp('Read a wiki page with its content. Requires the wiki.view or wiki.edit permission and that the page is visible to the caller.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('page_id'),
            'properties' => array(
                'page_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'Wiki page id.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $page_id = $this->argInt($arguments, 'page_id');
            $page = (new pmWikiPageModel())->getById($page_id);
            if (!$page) {
                return $this->softFail('not_found', _wp('Wiki page not found.'));
            }

            $ctx = pmMcpWikiHelper::requireView($page['project_id']);
            if (!pmMcpWikiHelper::canUserSeePage($page, $this->getUserId(), $ctx['role'], $ctx['is_admin_manager'])) {
                return $this->softFail('access_denied', _wp('You are not allowed to view this wiki page.'));
            }

            return $this->ok(array(
                'page' => pmMcpWikiHelper::formatPage($page, true),
            ));
        });
    }
}
