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

        // ===== Stage 3 — Write: tasks (9 tools) =====
        $registry->addTool(new pmMcpCreateTaskTool());
        $registry->addTool(new pmMcpUpdateTaskTool());
        $registry->addTool(new pmMcpMoveTaskTool());
        $registry->addTool(new pmMcpAssignTaskTool());
        $registry->addTool(new pmMcpAddTaskCommentTool());
        $registry->addTool(new pmMcpManageChecklistTool());
        $registry->addTool(new pmMcpManageWatchersTool());
        $registry->addTool(new pmMcpManageDependenciesTool());
        $registry->addTool(new pmMcpDeleteTaskTool());

        // ===== Stage 4 — Write: projects (4 tools) =====
        $registry->addTool(new pmMcpCreateProjectTool());
        $registry->addTool(new pmMcpUpdateProjectTool());
        $registry->addTool(new pmMcpAddProjectUserTool());
        $registry->addTool(new pmMcpRemoveProjectUserTool());

        // ===== Stage 5 — Wiki (4 tools) =====
        $registry->addTool(new pmMcpListWikiPagesTool());
        $registry->addTool(new pmMcpGetWikiPageTool());
        $registry->addTool(new pmMcpCreateWikiPageTool());
        $registry->addTool(new pmMcpUpdateWikiPageTool());

        // ===== Stage 6 — Sprints (2 tools, read-only) =====
        $registry->addTool(new pmMcpListSprintsTool());
        $registry->addTool(new pmMcpGetSprintTool());
    }

    /**
     * Publish the plugin's agent-facing documentation (mcp_skill_registry_v1).
     *
     * The tool schemas say what each argument is; they cannot say that a status
     * change goes through pm_move_task rather than pm_update_task, that a
     * relation between two tasks is one row read from both ends, or that a wiki
     * page created without `published` is a draft nobody else sees. Those are
     * the pitfalls an agent otherwise discovers by getting them wrong, so they
     * live in skills/*.md and are served through resources/list + resources/read
     * (skill://pm/<id>) and GET /mcp/skill/pm/<id>.md.
     *
     * The split follows the plugin's own right groups, so an agent granted only
     * pm.read has no reason to fetch the wiki page.
     *
     * Requires the mcp app >= 1.2.0 (see lib/config/requirements.php), which is
     * where mcpSkillRegistry and this event were introduced.
     *
     * Path separator
     * --------------
     * The paths below are built with DIRECTORY_SEPARATOR rather than the
     * forward slash mcpPlugin's docblock shows. mcpSkillRegistry gates the
     * declared path with
     *
     *     strncmp($rel, 'skills' . DIRECTORY_SEPARATOR, 7) !== 0
     *
     * so on Windows a literal "skills/foo.md" fails the check and the skill is
     * dropped from the registry without a log line — resources/list simply
     * comes back empty. On POSIX DIRECTORY_SEPARATOR is "/", so this spelling
     * is byte-identical to the documented one; on Windows it is the only one
     * that survives. We do not control the mcp app, so the plugin matches the
     * core's check instead of the core's docblock. Revisit if mcp normalises
     * the separator itself.
     *
     * @param array $groups  Passed by reference; add a 'pm' entry.
     */
    public function registerSkills(&$groups)
    {
        $dir = 'skills' . DIRECTORY_SEPARATOR;

        $groups['pm'] = array(
            'name'   => _wp('Project Management'),
            'skills' => array(
                array(
                    'id'          => 'pm-basics',
                    'name'        => _wp('pm: conventions every tool shares'),
                    'description' => _wp('Start here: the ok/error envelope and its error codes, the two permission layers, the AUTH-32 task reference syntax, the "0 clears a reference" rule and a safe working order.'),
                    'path'        => $dir . 'pm-basics.md',
                    'mime'        => 'text/markdown',
                ),
                array(
                    'id'          => 'pm-tasks',
                    'name'        => _wp('pm: tasks, statuses and relations'),
                    'description' => _wp('Finding, creating and changing tasks: list filters and their -1 sentinels, why status and assignee have their own tools, comments, checklist, watchers, task relations and deletion.'),
                    'path'        => $dir . 'pm-tasks.md',
                    'mime'        => 'text/markdown',
                ),
                array(
                    'id'          => 'pm-projects',
                    'name'        => _wp('pm: projects, members, workflows and sprints'),
                    'description' => _wp('The container around tasks: what is writable and what is backend-only, statuses and workflow transitions, project roles and ownership, sprints and the backlog.'),
                    'path'        => $dir . 'pm-projects.md',
                    'mime'        => 'text/markdown',
                ),
                array(
                    'id'          => 'pm-wiki',
                    'name'        => _wp('pm: the project wiki'),
                    'description' => _wp('Sections versus articles, the two access gates and the per-page visibility table, publication flags and access roles, moving pages, and when to use the wiki instead of a task.'),
                    'path'        => $dir . 'pm-wiki.md',
                    'mime'        => 'text/markdown',
                ),
            ),
        );
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

                // ===== Read group — sprints (2 rights) =====
                array(
                    'name'        => 'pm_list_sprints',
                    'group'       => 'pm.read',
                    'group_title' => _wp('Read'),
                    'title'       => _wp('List sprints'),
                    'description' => _wp('List a project\'s sprints with their statuses and automation settings.'),
                ),
                array(
                    'name'        => 'pm_get_sprint',
                    'group'       => 'pm.read',
                    'group_title' => _wp('Read'),
                    'title'       => _wp('Get sprint'),
                    'description' => _wp('Read a sprint card: project, auto-fill statuses and automation settings.'),
                ),

                // ===== Tasks group — write (9 rights) =====
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
                    'name'        => 'pm_manage_dependencies',
                    'group'       => 'pm.tasks',
                    'group_title' => _wp('Tasks'),
                    'title'       => _wp('Manage task relations'),
                    'description' => _wp('Link or unlink two tasks (depends on, blocks, duplicates, relates to).'),
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

                // ===== Wiki group (4 rights) =====
                array(
                    'name'        => 'pm_list_wiki_pages',
                    'group'       => 'pm.wiki',
                    'group_title' => _wp('Wiki'),
                    'title'       => _wp('List wiki pages'),
                    'description' => _wp('List a project\'s wiki page tree, filtered by visibility (requires the wiki.view or wiki.edit permission).'),
                ),
                array(
                    'name'        => 'pm_get_wiki_page',
                    'group'       => 'pm.wiki',
                    'group_title' => _wp('Wiki'),
                    'title'       => _wp('Get wiki page'),
                    'description' => _wp('Read a wiki page with its content (requires the wiki.view permission and page visibility).'),
                ),
                array(
                    'name'        => 'pm_create_wiki_page',
                    'group'       => 'pm.wiki',
                    'group_title' => _wp('Wiki'),
                    'title'       => _wp('Create wiki page'),
                    'description' => _wp('Create a wiki section or article (requires the wiki.edit permission).'),
                ),
                array(
                    'name'        => 'pm_update_wiki_page',
                    'group'       => 'pm.wiki',
                    'group_title' => _wp('Wiki'),
                    'title'       => _wp('Update wiki page'),
                    'description' => _wp('Update or move a wiki page (requires the wiki.edit permission).'),
                ),
            ),
        );
    }
}
