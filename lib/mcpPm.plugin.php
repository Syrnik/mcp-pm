<?php

/**
 * "pm" MCP plugin: exposes the Project Management app (wa-apps/pm) to LLM
 * agents over the MCP JSON-RPC protocol. Each tool has a one-to-one right
 * declared in registerRights() (single source of truth).
 *
 * Tool surface is built up across the implementation stages tracked in the
 * "MCP for PM" project (#78.1 – #78.8). Stage 0 (this commit) ships only the
 * scaffold: plugin bootstrap, the shared tool base class and packaging. The
 * concrete tools are added by the later stages.
 */
class mcpPmPlugin extends mcpPlugin
{
    public function __construct(array $info = array())
    {
        // Eager-load every plugin class before the framework asks us to
        // register tools. The MCP app builds its registry from the
        // mcp_tool_registry_v1 event and does NOT warm up the class index
        // beforehand, so autoloading of tool/helper classes cannot be
        // relied upon inside registerTools(). Requiring them here guarantees
        // the classes exist by the time addTool() is called.
        $dir = __DIR__;
        foreach (glob($dir . '/classes/*.php') as $f) {
            require_once $f;
        }
        foreach (glob($dir . '/tools/*.php') as $f) {
            require_once $f;
        }
        // Boot the pm app system so its models and domain classes
        // (pmTask, pmHelper, pmProjectModel, ...) autoload correctly.
        if (wa()->appExists('pm')) {
            wa('pm');
        }
        parent::__construct($info);
    }

    /**
     * Contribute tools to the shared MCP registry.
     *
     * @param mcpToolRegistry $registry
     */
    public function registerTools($registry)
    {
        // ===== Stage 1 — Read: projects and reference data (7 tools) =====
        $registry->addTool(new pmMcpListProjectsTool());
        $registry->addTool(new pmMcpGetProjectTool());
        $registry->addTool(new pmMcpListStatusesTool());
        $registry->addTool(new pmMcpGetWorkflowTool());
        $registry->addTool(new pmMcpListProjectUsersTool());
        $registry->addTool(new pmMcpListTagsTool());
        $registry->addTool(new pmMcpListMilestonesTool());

        // ===== Stage 2 — Read: tasks (3 tools) =====
        $registry->addTool(new pmMcpListTasksTool());
        $registry->addTool(new pmMcpGetTaskTool());
        $registry->addTool(new pmMcpListTaskCommentsTool());

        // ===== Stage 3 — Write: tasks (8 tools) =====
        $registry->addTool(new pmMcpCreateTaskTool());
        $registry->addTool(new pmMcpUpdateTaskTool());
        $registry->addTool(new pmMcpMoveTaskTool());
        $registry->addTool(new pmMcpAssignTaskTool());
        $registry->addTool(new pmMcpAddTaskCommentTool());
        $registry->addTool(new pmMcpManageChecklistTool());
        $registry->addTool(new pmMcpManageWatchersTool());
        $registry->addTool(new pmMcpDeleteTaskTool());

        // ===== Stage 4 — Write: projects (4 tools) =====
        $registry->addTool(new pmMcpCreateProjectTool());
        $registry->addTool(new pmMcpUpdateProjectTool());
        $registry->addTool(new pmMcpAddProjectUserTool());
        $registry->addTool(new pmMcpRemoveProjectUserTool());
    }

    /**
     * Declare the rights that gate each tool. The right name equals the tool
     * name (helpdesk convention): a token scope lists tool names directly.
     *
     * @param array $groups  Passed by reference; add a 'pm' entry.
     */
    public function registerRights(&$groups)
    {
        $groups['pm'] = array(
            'name'   => _wp('Project Management'),
            'rights' => array(

                // ===== Read group (7 rights) =====
                array(
                    'name'        => 'pm_list_projects',
                    'group'       => 'pm.read',
                    'group_title' => _wp('Read'),
                    'title'       => _wp('List projects'),
                    'description' => _wp('List projects the user has access to, optionally filtered by status.'),
                ),
                array(
                    'name'        => 'pm_get_project',
                    'group'       => 'pm.read',
                    'group_title' => _wp('Read'),
                    'title'       => _wp('Get project'),
                    'description' => _wp('Read a project card: properties, participants with roles, workflows and milestones.'),
                ),
                array(
                    'name'        => 'pm_list_statuses',
                    'group'       => 'pm.read',
                    'group_title' => _wp('Read'),
                    'title'       => _wp('List statuses'),
                    'description' => _wp('List global task statuses and, optionally, a specific workflow\'s statuses and transitions.'),
                ),
                array(
                    'name'        => 'pm_get_workflow',
                    'group'       => 'pm.read',
                    'group_title' => _wp('Read'),
                    'title'       => _wp('Get workflow'),
                    'description' => _wp('Read the workflows of a project: statuses, local names and transition matrix.'),
                ),
                array(
                    'name'        => 'pm_list_project_users',
                    'group'       => 'pm.read',
                    'group_title' => _wp('Read'),
                    'title'       => _wp('List project users'),
                    'description' => _wp('List the participants of a project with their roles.'),
                ),
                array(
                    'name'        => 'pm_list_tags',
                    'group'       => 'pm.read',
                    'group_title' => _wp('Read'),
                    'title'       => _wp('List tags'),
                    'description' => _wp('List the tags defined in a project.'),
                ),
                array(
                    'name'        => 'pm_list_milestones',
                    'group'       => 'pm.read',
                    'group_title' => _wp('Read'),
                    'title'       => _wp('List milestones'),
                    'description' => _wp('List the milestones of a project.'),
                ),

                // ===== Read group — tasks (3 rights) =====
                array(
                    'name'        => 'pm_list_tasks',
                    'group'       => 'pm.read',
                    'group_title' => _wp('Read'),
                    'title'       => _wp('List tasks'),
                    'description' => _wp('List tasks the user can access, filtered by project, status, assignee, sprint, milestone, type, priority, tag or search.'),
                ),
                array(
                    'name'        => 'pm_get_task',
                    'group'       => 'pm.read',
                    'group_title' => _wp('Read'),
                    'title'       => _wp('Get task'),
                    'description' => _wp('Read a full task card: tags, subtasks, participants, checklist, dependencies, custom fields, logged time and allowed transitions.'),
                ),
                array(
                    'name'        => 'pm_list_task_comments',
                    'group'       => 'pm.read',
                    'group_title' => _wp('Read'),
                    'title'       => _wp('List task comments'),
                    'description' => _wp('List the comments of a task with author name and the internal flag.'),
                ),

                // ===== Tasks group — write (8 rights) =====
                array(
                    'name'        => 'pm_create_task',
                    'group'       => 'pm.tasks',
                    'group_title' => _wp('Tasks'),
                    'title'       => _wp('Create task'),
                    'description' => _wp('Create a task in a project (respects the task.create permission).'),
                ),
                array(
                    'name'        => 'pm_update_task',
                    'group'       => 'pm.tasks',
                    'group_title' => _wp('Tasks'),
                    'title'       => _wp('Update task'),
                    'description' => _wp('Update task fields (respects per-field edit/assign/set_dates permissions).'),
                ),
                array(
                    'name'        => 'pm_move_task',
                    'group'       => 'pm.tasks',
                    'group_title' => _wp('Tasks'),
                    'title'       => _wp('Move task'),
                    'description' => _wp('Change a task status through allowed workflow transitions.'),
                ),
                array(
                    'name'        => 'pm_assign_task',
                    'group'       => 'pm.tasks',
                    'group_title' => _wp('Tasks'),
                    'title'       => _wp('Assign task'),
                    'description' => _wp('Set or clear a task assignee (respects the task.assign permission).'),
                ),
                array(
                    'name'        => 'pm_add_task_comment',
                    'group'       => 'pm.tasks',
                    'group_title' => _wp('Tasks'),
                    'title'       => _wp('Add task comment'),
                    'description' => _wp('Add a comment to a task (public or internal).'),
                ),
                array(
                    'name'        => 'pm_manage_checklist',
                    'group'       => 'pm.tasks',
                    'group_title' => _wp('Tasks'),
                    'title'       => _wp('Manage checklist'),
                    'description' => _wp('Add, rename, complete or delete task checklist items.'),
                ),
                array(
                    'name'        => 'pm_manage_watchers',
                    'group'       => 'pm.tasks',
                    'group_title' => _wp('Tasks'),
                    'title'       => _wp('Manage watchers'),
                    'description' => _wp('Add or remove task watchers.'),
                ),
                array(
                    'name'        => 'pm_delete_task',
                    'group'       => 'pm.tasks',
                    'group_title' => _wp('Tasks'),
                    'title'       => _wp('Delete task'),
                    'description' => _wp('Permanently delete a task and its dependents (requires confirm).'),
                ),

                // ===== Projects group — write (4 rights) =====
                array(
                    'name'        => 'pm_create_project',
                    'group'       => 'pm.projects',
                    'group_title' => _wp('Projects'),
                    'title'       => _wp('Create project'),
                    'description' => _wp('Create a project (requires access to the Project Management app). The owner becomes a project admin.'),
                ),
                array(
                    'name'        => 'pm_update_project',
                    'group'       => 'pm.projects',
                    'group_title' => _wp('Projects'),
                    'title'       => _wp('Update project'),
                    'description' => _wp('Update project properties and attached workflows (requires project admin rights).'),
                ),
                array(
                    'name'        => 'pm_add_project_user',
                    'group'       => 'pm.projects',
                    'group_title' => _wp('Projects'),
                    'title'       => _wp('Add project user'),
                    'description' => _wp('Add a participant with a role or change an existing member\'s role (requires project admin rights).'),
                ),
                array(
                    'name'        => 'pm_remove_project_user',
                    'group'       => 'pm.projects',
                    'group_title' => _wp('Projects'),
                    'title'       => _wp('Remove project user'),
                    'description' => _wp('Remove a participant from a project (requires project admin rights and confirm).'),
                ),
            ),
        );
    }
}
