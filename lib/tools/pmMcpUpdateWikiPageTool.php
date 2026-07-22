<?php

/**
 * pm_update_wiki_page — partial update of a wiki page: title, content, type,
 * publication flags, access roles and re-parenting (move). Requires the
 * wiki.edit permission. Only supplied fields change.
 */
class pmMcpUpdateWikiPageTool extends pmMcpToolBase
{
    public function getName()        { return 'pm_update_wiki_page'; }
    public function getRight()       { return 'pm_update_wiki_page'; }
    public function getDescription() { return _wp('Update a wiki page: title, content, type, publication, access roles, or move it under another parent (parent_id=0 makes it top-level). Requires the wiki.edit permission. Returns the updated page.'); }

    public function getInputSchema()
    {
        return array(
            'type'       => 'object',
            'required'   => array('page_id'),
            'properties' => array(
                'page_id'      => array('type' => 'integer', 'minimum' => 1, 'description' => 'Wiki page id.'),
                'title'        => array('type' => 'string', 'minLength' => 1, 'description' => 'New title.'),
                'content'      => array('type' => 'string', 'description' => 'New body (ignored when the page is/becomes a section).'),
                'type'         => array('type' => 'string', 'enum' => array('article', 'section'), 'description' => 'Change the page type. Switching to section clears its content.'),
                'parent_id'    => array('type' => 'integer', 'minimum' => 0, 'description' => 'Move under this parent (same project, not itself or a descendant). 0 makes it top-level.'),
                'published'    => array('type' => 'boolean', 'description' => 'Publication flag.'),
                'is_public'    => array('type' => 'boolean', 'description' => 'Public-visibility flag for a published page.'),
                'access_roles' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Replace the role slugs allowed to see a published, non-public page. Empty list clears the restriction.'),
            ),
        );
    }

    public function execute(array $arguments, waSystem $system)
    {
        return $this->safeExecute(function () use ($arguments) {
            $page_id = $this->argInt($arguments, 'page_id');
            $model = new pmWikiPageModel();
            $page = $model->getById($page_id);
            if (!$page) {
                return $this->softFail('not_found', _wp('Wiki page not found.'));
            }

            $project_id = (int) $page['project_id'];
            pmMcpWikiHelper::requireEdit($project_id);

            $data = array();

            if (array_key_exists('title', $arguments)) {
                $title = $this->argString($arguments, 'title');
                if ($title === '') {
                    return $this->softFail('invalid_param', _wp('Page title cannot be empty.'));
                }
                $data['title'] = $title;
            }

            // Resolve the effective type, then honour the "sections hold no
            // content" rule for both an explicit type switch and a content edit.
            $final_type = $page['type'];
            if (array_key_exists('type', $arguments)) {
                $type = $this->argString($arguments, 'type');
                if (in_array($type, array('section', 'article'), true)) {
                    $final_type = $type;
                    $data['type'] = $type;
                }
            }
            if ($final_type === 'section') {
                // A section never stores content: clear it when switching to a
                // section, and ignore any content passed for a section.
                $switched_to_section = (($data['type'] ?? null) === 'section');
                if ($switched_to_section || array_key_exists('content', $arguments)) {
                    $data['content'] = '';
                }
            } elseif (array_key_exists('content', $arguments)) {
                $data['content'] = $this->argString($arguments, 'content');
            }

            if (array_key_exists('published', $arguments)) {
                $data['published'] = $this->argBool($arguments, 'published') ? 1 : 0;
            }
            if (array_key_exists('is_public', $arguments)) {
                $data['is_public'] = $this->argBool($arguments, 'is_public') ? 1 : 0;
            }
            if (array_key_exists('access_roles', $arguments)) {
                $data['access_roles'] = pmMcpWikiHelper::buildAccessRoles($arguments['access_roles']);
            }

            // Move (re-parent). Only recompute sort when the parent changes.
            if (array_key_exists('parent_id', $arguments)) {
                $new_parent = $this->argInt($arguments, 'parent_id');
                $new_parent = $new_parent > 0 ? $new_parent : null;
                $old_parent = !empty($page['parent_id']) ? (int) $page['parent_id'] : null;

                if ($new_parent !== $old_parent) {
                    if ($new_parent !== null) {
                        if ($new_parent === $page_id) {
                            return $this->softFail('invalid_param', _wp('A page cannot be moved into itself.'));
                        }
                        if (!pmMcpWikiHelper::isSafeParent($model, $page_id, $new_parent, $project_id)) {
                            return $this->softFail('invalid_param', _wp('Invalid parent page (not in this project, or it is a descendant of this page).'));
                        }
                    }
                    $data['parent_id'] = $new_parent;
                    $data['sort'] = $model->getMaxSort($project_id, $new_parent) + 1;
                }
            }

            if (!$data) {
                return $this->softFail('invalid_param', _wp('Nothing to update: supply at least one field.'));
            }

            $data['update_datetime'] = date('Y-m-d H:i:s');
            $model->updateById($page_id, $data);

            return $this->ok(array(
                'page' => pmMcpWikiHelper::formatPage($model->getById($page_id), true),
            ));
        });
    }
}
