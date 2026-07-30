<?php

/**
 * System requirements for the Project Management (pm) MCP plugin.
 *
 * @see https://developers.webasyst.ru/docs/cookbook/system-requirements/
 */
return array(
    // 1.2.0 introduced the skill registry (mcp_skill_registry_v1 /
    // mcpSkillRegistry) the plugin's registerSkills() hooks into. On an older
    // mcp the skills would be declared but never served, so the requirement is
    // strict rather than advisory.
    'app.mcp' => array(
        'version' => '>=1.2.0',
        'strict'  => true,
    ),
    'app.pm'  => array(
        'version' => '>=0.25.0',
        'strict'  => true,
    ),
    'php'     => array(
        'version' => '>=7.4',
        'strict'  => true
    ),
);
