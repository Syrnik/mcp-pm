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
    // pm_task.sprint_id became NOT NULL DEFAULT 0 in 0.33.5 (migration
    // 1786112356: "0 = no sprint, NULL no longer used") — pm_create_task and
    // pm_update_task rely on that column shape and write 0, never null, for
    // the backlog (PMCP-518). The cross-project sprint API the sprint tools
    // rely on — pmSprint::canEdit(array $project_ids) (any-of over the
    // projects) and the 4-argument
    // pmSprint::save($data, $project_ids, $workflow_items, $fill_status_ids) —
    // first appeared in 0.26.0, but the requirement is pinned to 0.50.0, the
    // version this plugin is developed and tested against. On an older pm
    // either the sprint write tools would fatal immediately, or a task
    // created without a sprint would land on a nullable column and go
    // invisible to pm's own IS NULL-based backlog queries, so the
    // requirement is strict rather than advisory.
    'app.pm'  => array(
        'version' => '>=0.50.0',
        'strict'  => true,
    ),
    'php'     => array(
        'version' => '>=7.4',
        'strict'  => true
    ),
);
