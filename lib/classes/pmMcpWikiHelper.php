<?php

/**
 * Shared helpers for the pm wiki MCP tools (Stage 5): access gating, per-page
 * visibility and serialisation.
 *
 * Wiki access is a two-level check that mirrors pmWikiActions exactly:
 *   1. The user needs the wiki.view (read) or wiki.edit (write) permission for
 *      the project — pmHelper::hasPermission() with the user's project role.
 *      A non-member gets a null role and, unless an app admin, is denied.
 *   2. Each individual page is then filtered through canUserSeePage(): an
 *      unpublished page is visible only to its author and to project
 *      admins/managers; a published page restricted by access_roles is visible
 *      only to those roles (and admins/managers).
 */
class pmMcpWikiHelper
{
    /**
     * Load a project row, enforcing existence. Wiki visibility is governed by
     * the wiki.view/edit permission rather than plain membership, so this does
     * not call canAccess() — requireView()/requireEdit() do the gating.
     *
     * @throws waAPIException not_found when the project is missing.
     * @return array
     */
    public static function loadProject($project_id)
    {
        $project = (new pmProjectModel())->getById((int) $project_id);
        if (!$project) {
            throw new waAPIException('not_found', _wp('Project not found.'), 404);
        }
        return $project;
    }

    /**
     * The current user's project role, decorated with the admin/manager flag
     * used by the visibility rules.
     *
     * @return array{role: ?string, is_admin_manager: bool}
     */
    public static function roleContext($project_id)
    {
        $role = pmHelper::getContactRole(wa()->getUser()->getId(), (int) $project_id);
        return array(
            'role'             => $role,
            'is_admin_manager' => in_array($role, array('admin', 'manager'), true),
        );
    }

    /**
     * Require the wiki.view permission (read side). Users with only wiki.edit
     * are allowed too, matching pmWikiActions::dataAction.
     *
     * @throws waRightsException
     * @return array  roleContext()
     */
    public static function requireView($project_id)
    {
        $ctx = self::roleContext($project_id);
        $can_view = pmHelper::hasPermission($ctx['role'], 'wiki.view');
        $can_edit = pmHelper::hasPermission($ctx['role'], 'wiki.edit');
        if (!$can_view && !$can_edit) {
            throw new waRightsException(_wp('You do not have permission to view this project\'s wiki.'));
        }
        return $ctx;
    }

    /**
     * Require the wiki.edit permission (write side).
     *
     * @throws waRightsException
     * @return array  roleContext()
     */
    public static function requireEdit($project_id)
    {
        $ctx = self::roleContext($project_id);
        if (!pmHelper::hasPermission($ctx['role'], 'wiki.edit')) {
            throw new waRightsException(_wp('You do not have permission to edit this project\'s wiki.'));
        }
        return $ctx;
    }

    /**
     * Whether the current user may see a specific page. Faithful port of
     * pmWikiActions::canUserSeePage.
     */
    public static function canUserSeePage(array $page, $contact_id, $role, $is_admin_manager)
    {
        if (!empty($page['published'])) {
            if (empty($page['is_public']) && !empty($page['access_roles'])) {
                $allowed = array_map('trim', explode(',', $page['access_roles']));
                return $is_admin_manager || in_array($role, $allowed, true);
            }
            return true;
        }
        return $is_admin_manager || (int) $page['create_contact_id'] === (int) $contact_id;
    }

    /**
     * Serialise a pm_wiki_page row. Content is included only for the
     * single-page read; the tree listing omits it to stay light.
     */
    public static function formatPage(array $p, $include_content = false)
    {
        $out = array(
            'id'                => (int) $p['id'],
            'project_id'        => (int) $p['project_id'],
            'parent_id'         => !empty($p['parent_id']) ? (int) $p['parent_id'] : null,
            'type'              => $p['type'],
            'title'             => $p['title'],
            'published'         => (bool) $p['published'],
            'is_public'         => (bool) $p['is_public'],
            'access_roles'      => self::parseAccessRoles($p['access_roles'] ?? ''),
            'sort'              => (int) $p['sort'],
            'create_contact_id' => (int) $p['create_contact_id'],
            'create_datetime'   => $p['create_datetime'] ?? null,
            'update_datetime'   => $p['update_datetime'] ?? null,
        );
        if ($include_content) {
            $out['content'] = $p['content'] ?? '';
        }
        return $out;
    }

    /**
     * Normalise the stored CSV access_roles field into a list of role slugs.
     *
     * @return string[]
     */
    public static function parseAccessRoles($csv)
    {
        $csv = trim((string) $csv);
        if ($csv === '') {
            return array();
        }
        return array_values(array_filter(array_map('trim', explode(',', $csv)), 'strlen'));
    }

    /**
     * Turn an access_roles argument (list of slugs or a CSV string) into the
     * stored CSV form, validating each slug against the configured roles.
     *
     * @param mixed $value
     * @return string  CSV string ("" when none).
     * @throws waAPIException invalid_param on an unknown role slug.
     */
    public static function buildAccessRoles($value)
    {
        if ($value === null || $value === '') {
            return '';
        }
        $slugs = is_array($value)
            ? $value
            : explode(',', (string) $value);

        $known = (new pmRoleModel())->getAllRoles();
        $clean = array();
        foreach ($slugs as $slug) {
            $slug = trim((string) $slug);
            if ($slug === '') {
                continue;
            }
            if (!isset($known[$slug])) {
                throw new waAPIException(
                    'invalid_param',
                    sprintf(_wp('Unknown role "%s" in access_roles.'), $slug),
                    400
                );
            }
            $clean[$slug] = $slug;
        }
        return implode(',', array_keys($clean));
    }

    /**
     * Guard against creating a cycle when re-parenting a page: the new parent
     * must not be the page itself or any of its descendants.
     *
     * @return bool  True when $candidate_parent_id is a safe parent for $page_id.
     */
    public static function isSafeParent(pmWikiPageModel $model, $page_id, $candidate_parent_id, $project_id)
    {
        $page_id = (int) $page_id;
        $cursor = (int) $candidate_parent_id;
        $guard = 0;
        while ($cursor > 0) {
            if ($cursor === $page_id) {
                return false; // candidate is the page or one of its descendants
            }
            $row = $model->getById($cursor);
            if (!$row || (int) $row['project_id'] !== (int) $project_id) {
                return false;
            }
            $cursor = !empty($row['parent_id']) ? (int) $row['parent_id'] : 0;
            if (++$guard > 1000) {
                return false; // corrupt tree safety valve
            }
        }
        return true;
    }
}
