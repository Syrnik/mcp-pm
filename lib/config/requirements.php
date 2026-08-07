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
    // The cross-project sprint API the sprint tools rely on — pmSprint::canEdit
    // (array $project_ids) (any-of over the projects) and the 4-argument
    // pmSprint::save($data, $project_ids, $workflow_items, $fill_status_ids) —
    // first appeared in 0.26.0, but the requirement is pinned to 0.33.1, the
    // version the sprint tools were developed and tested against. On an older
    // pm the sprint write tools would fatal immediately, so the requirement is
    // strict rather than advisory.
    'app.pm'  => array(
        'version' => '>=0.33.1',
        'strict'  => true,
    ),
    'php'     => array(
        'version' => '>=7.4',
        'strict'  => true
    ),
);
