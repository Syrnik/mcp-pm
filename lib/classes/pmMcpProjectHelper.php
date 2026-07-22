<?php

/**
 * Shared helpers for pm MCP read tools: project-access filtering (by
 * membership) and record serialisation.
 *
 * Access model mirrors the pm backend: a pm app-admin sees every project;
 * everyone else sees only the projects they are a member of
 * (pm_project_user). See e.g. pmActivity.actions.php / pmSprints.actions.php.
 */
class pmMcpProjectHelper
{
    /**
     * Project ids the current user may access.
     *
     * @return int[]|null  null means "no restriction" (app-admin: all projects);
     *                     otherwise the explicit list of member project ids.
     */
    public static function accessibleProjectIds()
    {
        if (wa()->getUser()->isAdmin('pm')) {
            return null;
        }
        $ids = (new pmProjectUserModel())->getProjectIdsByContact(wa()->getUser()->getId());
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Whether the current user may access the given project.
     */
    public static function canAccess($project_id)
    {
        $ids = self::accessibleProjectIds();
        return $ids === null || in_array((int) $project_id, $ids, true);
    }

    /**
     * Load a project by id, enforcing existence and membership.
     *
     * @throws waAPIException    not_found when the project does not exist.
     * @throws waRightsException access_denied when the user is not a member.
     * @return array             The pm_project row.
     */
    public static function loadAccessibleProject($project_id)
    {
        $project = (new pmProjectModel())->getById((int) $project_id);
        if (!$project) {
            throw new waAPIException('not_found', _wp('Project not found.'), 404);
        }
        if (!self::canAccess($project_id)) {
            throw new waRightsException(_wp('You are not a member of this project.'));
        }
        return $project;
    }

    /**
     * Serialise a pm_project row for tool output.
     *
     * @param array $p     The pm_project row.
     * @param bool  $full  Include the extended card fields (description,
     *                     numbering, workflow ids, timestamps).
     * @return array
     */
    public static function formatProject(array $p, $full = false)
    {
        $out = array(
            'id'               => (int) $p['id'],
            'name'             => $p['name'],
            'status'           => $p['status'],
            'color'            => $p['color'],
            'icon'             => $p['icon'],
            'start_date'       => $p['start_date'] ?? null,
            'end_date'         => $p['end_date'] ?? null,
            'owner_contact_id' => (int) $p['owner_contact_id'],
        );
        if ($full) {
            $out['parent_id']        = !empty($p['parent_id']) ? (int) $p['parent_id'] : null;
            $out['description']      = $p['description'] ?? '';
            $out['prefix']           = $p['prefix'] ?? null;
            $out['number_mode']      = $p['number_mode'] ?? null;
            $out['number_separator'] = $p['number_separator'] ?? null;
            $out['content_format']   = $p['content_format'] ?? null;
            $out['workflow_ids']     = pmWorkflow::parseWorkflowIds($p);
            $out['create_datetime']  = $p['create_datetime'] ?? null;
            $out['update_datetime']  = $p['update_datetime'] ?? null;
        }
        return $out;
    }

    /**
     * Serialise project participants (pm_project_user joined with contacts),
     * decorating each with the human-readable role name.
     *
     * @param int $project_id
     * @return array[]
     */
    public static function formatParticipants($project_id)
    {
        $users = (new pmProjectUserModel())->getByProject((int) $project_id);
        $role_names = (new pmRoleModel())->getAllKeyed();

        $out = array();
        foreach ($users as $u) {
            $out[] = array(
                'contact_id' => (int) $u['contact_id'],
                'name'       => $u['name'] ?? '',
                'firstname'  => $u['firstname'] ?? '',
                'lastname'   => $u['lastname'] ?? '',
                'photo'      => $u['photo'] ?? null,
                'role'       => $u['role'],
                'role_name'  => $role_names[$u['role']] ?? $u['role'],
            );
        }
        return $out;
    }

    /**
     * Serialise project milestones.
     *
     * @param int $project_id
     * @return array[]
     */
    public static function formatMilestones($project_id)
    {
        $milestones = (new pmMilestoneModel())->getByProject((int) $project_id);
        $out = array();
        foreach ($milestones as $m) {
            $out[] = array(
                'id'          => (int) $m['id'],
                'name'        => $m['name'],
                'description' => $m['description'] ?? '',
                'start_date'  => $m['start_date'] ?? null,
                'end_date'    => $m['end_date'] ?? null,
                'status'      => $m['status'],
                'sort'        => (int) $m['sort'],
            );
        }
        return $out;
    }

    /**
     * The full project card returned by the write tools after a mutation:
     * properties, participants, workflows and milestones (same shape as
     * pm_get_project). Reloads the row so it reflects the persisted state.
     *
     * @param int $project_id
     * @return array
     */
    public static function fullCard($project_id)
    {
        $project = (new pmProjectModel())->getById((int) $project_id);
        return array(
            'project'      => self::formatProject($project, true),
            'participants' => self::formatParticipants($project_id),
            'workflows'    => pmMcpWorkflowHelper::describeProjectWorkflows($project),
            'milestones'   => self::formatMilestones($project_id),
        );
    }

    /**
     * Require the current user to have access to the Project Management app.
     * pm has no granular app-level rights config — any backend user with pm
     * access has it fully — so this mirrors pmHelper::hasPermission()'s admin
     * bypass and otherwise checks the plain 'backend' access right. Needed for
     * pm_create_project, where there is no project membership to check yet.
     *
     * @throws waRightsException when the user cannot use the pm app.
     */
    public static function requireAppAccess()
    {
        $user = wa()->getUser();
        if ($user->isAdmin() || $user->getRights('pm', 'backend')) {
            return;
        }
        throw new waRightsException(_wp('You do not have access to the Project Management app.'));
    }

    /**
     * Require the current user to be an administrator of the project (project
     * role "admin") or an app admin. Managing a project's settings and
     * membership is an admin-level action. Assumes existence/membership were
     * already checked with loadAccessibleProject().
     *
     * @param int $project_id
     * @throws waRightsException when the user is not a project admin.
     * @return string  The role slug ("admin").
     */
    public static function requireProjectAdmin($project_id)
    {
        if (wa()->getUser()->isAdmin()) {
            return 'admin';
        }
        $role = pmHelper::getContactRole(wa()->getUser()->getId(), (int) $project_id);
        if ($role !== 'admin') {
            throw new waRightsException(_wp('Only a project administrator can perform this action.'));
        }
        return $role;
    }
}
