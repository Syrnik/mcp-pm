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
        // Stage 0 scaffold: no tools yet. Tools are added here by
        // stages 1-6 (#78.2 – #78.7), e.g.:
        //   $registry->addTool(new pmMcpListProjectsTool());
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
                // Stage 0 scaffold: rights are added alongside their tools by
                // stages 1-6. Groups (group / group_title) will be:
                //   pm.read     - Read
                //   pm.tasks    - Tasks
                //   pm.projects - Projects
                //   pm.wiki     - Wiki
            ),
        );
    }
}
