<?php

/**
 * Shared helpers for the pm task read tools (Stage 2): resolving the set of
 * projects a listing may span, loading a task with a project-membership check,
 * and serialising task rows and the full task card.
 *
 * Access model mirrors pmMcpProjectHelper: an app-admin sees every project;
 * everyone else only the projects they are a member of. A task is visible iff
 * its project is.
 */
class pmMcpTaskHelper
{
    /**
     * Concrete list of project ids a task listing may span.
     *
     * @param int $project_id  A specific project (>0), or 0 for "all the user
     *                         can access".
     * @return int[]           Possibly empty (user is a member of nothing).
     * @throws waAPIException    not_found when a specific project is missing.
     * @throws waRightsException access_denied when the user is not a member.
     */
    public static function resolveProjectIds($project_id)
    {
        $project_id = (int) $project_id;
        if ($project_id > 0) {
            pmMcpProjectHelper::loadAccessibleProject($project_id);
            return array($project_id);
        }

        $accessible = pmMcpProjectHelper::accessibleProjectIds();
        if ($accessible === null) {
            // App-admin: every project.
            $ids = array_keys((new pmProjectModel())->select('id')->fetchAll('id'));
            return array_map('intval', $ids);
        }
        return $accessible;
    }

    /**
     * Load a task by id, enforcing existence and project membership.
     *
     * @throws waAPIException    not_found when the task does not exist.
     * @throws waRightsException access_denied when the project is not accessible.
     * @return array             The pm_task row.
     */
    public static function loadAccessibleTask($task_id)
    {
        $task = (new pmTaskModel())->getById((int) $task_id);
        if (!$task) {
            throw new waAPIException('not_found', _wp('Task not found.'), 404);
        }
        if (!pmMcpProjectHelper::canAccess((int) $task['project_id'])) {
            throw new waRightsException(_wp('You are not a member of this task\'s project.'));
        }
        return $task;
    }

    /**
     * Serialise a pm_task row for list output. Mirrors pmTaskListMethod's shape,
     * plus subtasks_count when getByStatus() supplied it.
     *
     * @param array $t
     * @return array
     */
    public static function formatTaskRow(array $t)
    {
        $row = array(
            'id'                  => (int) $t['id'],
            'project_id'          => (int) $t['project_id'],
            'parent_id'           => !empty($t['parent_id']) ? (int) $t['parent_id'] : null,
            'subject'             => $t['subject'],
            'status_id'           => (int) $t['status_id'],
            'workflow_id'         => $t['workflow_id'] ?? null,
            'priority'            => $t['priority'] ?? 'normal',
            'type_slug'           => $t['type_slug'] ?? null,
            'assignee_contact_id' => !empty($t['assignee_contact_id']) ? (int) $t['assignee_contact_id'] : null,
            'milestone_id'        => !empty($t['milestone_id']) ? (int) $t['milestone_id'] : null,
            'sprint_id'           => !empty($t['sprint_id']) ? (int) $t['sprint_id'] : null,
            'start_date'          => $t['start_date'] ?? null,
            'due_date'            => $t['due_date'] ?? null,
            'estimated_hours'     => isset($t['estimated_hours']) && $t['estimated_hours'] !== null ? (float) $t['estimated_hours'] : null,
            'spent_hours'         => (float) ($t['spent_hours'] ?? 0),
            'progress'            => (int) ($t['progress'] ?? 0),
            'sort'                => (int) $t['sort'],
            'create_contact_id'   => (int) $t['create_contact_id'],
            'create_datetime'     => $t['create_datetime'] ?? null,
            'update_datetime'     => $t['update_datetime'] ?? null,
            'completed_datetime'  => $t['completed_datetime'] ?? null,
        );
        if (array_key_exists('subtasks_count', $t)) {
            $row['subtasks_count'] = (int) $t['subtasks_count'];
        }
        return $row;
    }

    /**
     * Serialise the full task card: base fields plus tags, subtasks, milestone,
     * participants, checklist, dependencies, custom fields, time entries and the
     * workflow transitions available to the current user.
     *
     * @param array $task  A pm_task row.
     * @return array
     */
    public static function formatTaskCard(array $task)
    {
        $id = (int) $task['id'];

        $card = self::formatTaskRow($task);
        $card['description'] = $task['description'] ?? '';

        // Tags.
        $card['tags'] = array();
        $task_tags = (new pmTaskModel())->query(
            "SELECT tt.tag_id, t.name, t.color
             FROM pm_task_tag tt
             JOIN pm_tag t ON tt.tag_id = t.id
             WHERE tt.task_id = i:id",
            array('id' => $id)
        )->fetchAll();
        foreach ($task_tags as $tt) {
            $card['tags'][] = array(
                'id'    => (int) $tt['tag_id'],
                'name'  => $tt['name'],
                'color' => $tt['color'] ?? null,
            );
        }

        // Milestone (expanded).
        $card['milestone'] = null;
        if (!empty($task['milestone_id'])) {
            $m = (new pmMilestoneModel())->getById((int) $task['milestone_id']);
            if ($m) {
                $card['milestone'] = array(
                    'id'         => (int) $m['id'],
                    'name'       => $m['name'],
                    'status'     => $m['status'],
                    'start_date' => $m['start_date'] ?? null,
                    'end_date'   => $m['end_date'] ?? null,
                );
            }
        }

        // Subtasks.
        $card['subtasks'] = array();
        foreach ((new pmTaskModel())->getSubtasks($id) as $st) {
            $card['subtasks'][] = array(
                'id'                  => (int) $st['id'],
                'subject'             => $st['subject'],
                'status_id'           => (int) $st['status_id'],
                'assignee_contact_id' => !empty($st['assignee_contact_id']) ? (int) $st['assignee_contact_id'] : null,
                'progress'            => (int) $st['progress'],
                'sort'                => (int) $st['sort'],
            );
        }

        // Participants (assignees + watchers).
        $card['participants'] = (new pmTaskParticipantModel())->getByTask($id);

        // Checklist.
        $checklist = (new pmChecklistModel())->getByTask($id);
        foreach ($checklist as &$ci) {
            $ci['id']           = (int) $ci['id'];
            $ci['task_id']      = (int) $ci['task_id'];
            $ci['is_completed'] = (int) $ci['is_completed'];
            $ci['sort']         = (float) $ci['sort'];
        }
        unset($ci);
        $card['checklist'] = $checklist;

        // Dependencies (depends_on / blocks / related).
        $card['dependencies'] = (new pmTaskDependencyModel())->getByTask($id);

        // Custom fields: field_id => value map.
        $card['custom_fields'] = (array) (new pmFieldDataModel())->getByTask($id);

        // Time entries and the total logged.
        $entries = array();
        $total = 0.0;
        foreach ((new pmTaskTimesModel())->getByTask($id) as $e) {
            $hours = (float) $e['hours'];
            $total += $hours;
            $entries[] = array(
                'id'              => (int) $e['id'],
                'contact_id'      => (int) $e['contact_id'],
                'contact_name'    => trim(($e['firstname'] ?? '') . ' ' . ($e['lastname'] ?? '')),
                'hours'           => $hours,
                'description'     => $e['description'] ?? '',
                'create_datetime' => $e['create_datetime'] ?? null,
            );
        }
        $card['time_entries'] = $entries;
        $card['logged_hours'] = $total;

        // Workflow transitions available to the current user from here.
        $workflow_id = $task['workflow_id'] ?? null;
        $card['workflow_id'] = $workflow_id;
        $card['allowed_statuses'] = array();
        if ($workflow_id) {
            $user_role = pmHelper::getContactRole(wa()->getUser()->getId(), (int) $task['project_id']);
            $card['allowed_statuses'] = pmWorkflow::getAllowedTransitions(
                (int) $task['status_id'],
                $workflow_id,
                $user_role
            );
        }

        return $card;
    }
}
