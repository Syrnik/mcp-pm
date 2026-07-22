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
            ),
        );
    }
}
