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
    // first appeared in 0.26.0. On an older pm either the sprint write tools
    // would fatal immediately, or a task created without a sprint would land
    // on a nullable column and go invisible to pm's own IS NULL-based backlog
    // queries, so the requirement is strict rather than advisory.
    //
    // The per-project default task type of a workflow
    // (pm_project_workflows.default_type_slug, used by pm_get_workflow,
    // pm_get_project and pm_create_task — PMCP-693) and "all projects"
    // sprints (no pm_sprint_project rows, PMCP-694) appeared in 0.75.0; the
    // per-operation sprint rights the sprint tools mirror, including
    // pmSprint::canEditAll(), in 1.0.0
    // (#593/#595) — the release this plugin is developed and tested against.
    'app.pm'  => array(
        'version' => '>=1.0.0',
        'strict'  => true,
    ),
    'php'     => array(
        'version' => '>=7.4',
        'strict'  => true
    ),
);
