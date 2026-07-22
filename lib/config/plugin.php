<?php
return array(
    'name'        => /*_wp*/('Project Management for MCP'),
    'description' => /*_wp*/('MCP tools for the Project Management (pm) app: projects, tasks, assignees, sprints, milestones and wiki.'),
    'version'     => '0.1.0',
    'vendor'      => '1023936',
    'icon'        => 'img/icon16.png',
    'img'         => 'img/icon.svg',

    'handlers' => array(
        'mcp_tool_registry_v1' => 'registerTools',
        'mcp_plugin_rights_v1' => 'registerRights',
    ),
);
