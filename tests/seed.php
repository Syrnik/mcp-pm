<?php
/**
 * Idempotent PM fixture seed for the test installation.
 *
 * Creates a known "MCP Test Project" with a workflow, participants, a milestone,
 * tags and a spread of tasks (varied statuses, priorities, assignee/unassigned,
 * a subtask, checklist, comment, tags and a time entry). Re-running tears the
 * project down first and recreates it, so the data is always in a known state —
 * no more hand-rolled temporary rows for each check.
 *
 * Usage (from anywhere):
 *   php wa-apps/mcp/plugins/pm/tests/seed.php
 *
 * The acting user is contact 1 (the install admin). `tests/` is excluded from
 * the release bundle, so this never ships.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

// tests → pm → plugins → mcp → wa-apps → htdocs  (depth 5 up to htdocs root)
$root = dirname(__FILE__, 6);
require $root . '/wa-config/SystemConfig.class.php';
waSystem::getInstance(null, new SystemConfig());
wa('pm');

$ACTOR = 1;                         // install admin contact id
wa()->setUser(new waUser($ACTOR));

const PROJECT_NAME = 'MCP Test Project';
const WORKFLOW     = 'management';  // statuses 1..6

/** Delete a project and every row that references it. */
function teardown_project(int $pid): void
{
    $db = new waModel();
    $task_ids = array_keys((new pmTaskModel())->getByField('project_id', $pid, 'id'));
    if ($task_ids) {
        $in = implode(',', array_map('intval', $task_ids));
        foreach (['pm_field_data', 'pm_task_times', 'pm_checklist', 'pm_comment',
                  'pm_task_participant', 'pm_task_tag', 'pm_task_dependency',
                  'pm_task_external'] as $t) {
            $db->query("DELETE FROM {$t} WHERE task_id IN ({$in})");
        }
        $db->query("DELETE FROM pm_task WHERE id IN ({$in})");
    }
    foreach (['pm_milestone', 'pm_tag', 'pm_project_workflows', 'pm_project_user',
              'pm_sprint_project', 'pm_activity_log'] as $t) {
        $db->query("DELETE FROM {$t} WHERE project_id = i:p", ['p' => $pid]);
    }
    $db->query("DELETE FROM pm_project WHERE id = i:p", ['p' => $pid]);
}

// ---- 1. Clean slate ---------------------------------------------------------
$project_model = new pmProjectModel();
foreach ($project_model->getByField('name', PROJECT_NAME, true) as $p) {
    teardown_project((int) $p['id']);
    echo "torn down existing project #{$p['id']}\n";
}

// ---- 2. Project + workflow + members ---------------------------------------
$now = date('Y-m-d H:i:s');
$pid = (int) $project_model->insert([
    'name'             => PROJECT_NAME,
    'description'      => 'Seeded fixture project for exercising the pm MCP tools.',
    'status'           => 'active',
    'owner_contact_id' => $ACTOR,
    'prefix'           => 'MCP',
    'create_datetime'  => $now,
    'update_datetime'  => $now,
]);

$db = new waModel();
$db->query("INSERT INTO pm_project_workflows (project_id, workflow_id) VALUES (i:p, s:w)", ['p' => $pid, 'w' => WORKFLOW]);

$pu = new pmProjectUserModel();
$pu->insert(['project_id' => $pid, 'contact_id' => $ACTOR, 'role' => 'admin']);
$has2 = (int) $db->query("SELECT COUNT(*) FROM wa_contact WHERE id = 2")->fetchField() > 0;
if ($has2) {
    $pu->insert(['project_id' => $pid, 'contact_id' => 2, 'role' => 'member']);
}

// ---- 3. Milestone + tags ----------------------------------------------------
$milestone_id = (int) (new pmMilestoneModel())->insert([
    'project_id' => $pid,
    'name'       => 'v1.0',
    'status'     => 'active',
    'sort'       => 1,
]);

$tag_model = new pmTagModel();
$tag_bug     = (int) $tag_model->add($pid, 'bug', '#e74c3c');
$tag_feature = (int) $tag_model->add($pid, 'feature', '#2ecc71');

// ---- 4. Tasks (via the domain layer, like real usage) ----------------------
function mk(int $pid, array $extra): int
{
    $data = array_merge([
        'project_id'  => $pid,
        'workflow_id' => WORKFLOW,
        'priority'    => 'normal',
        'description' => '',
    ], $extra);
    return pmTask::create($data, 1);
}

$t_ci = mk($pid, [
    'subject'             => 'Настроить CI пайплайн',
    'description'         => 'GitHub Actions: lint + phpunit на каждый push.',
    'status_id'           => 3,   // In Progress
    'priority'            => 'high',
    'assignee_contact_id' => 1,
    'milestone_id'        => $milestone_id,
]);
$t_docs = mk($pid, ['subject' => 'Написать документацию по API', 'status_id' => 1]);
$t_bug  = mk($pid, [
    'subject'             => 'Починить баг авторизации',
    'status_id'           => 4,   // Review
    'priority'            => 'critical',
    'assignee_contact_id' => 1,
]);
$t_land = mk($pid, ['subject' => 'Дизайн лендинга', 'status_id' => 1]);
$t_rel  = mk($pid, [
    'subject'      => 'Релиз v1.0',
    'status_id'    => 5,   // Done
    'priority'     => 'low',
    'milestone_id' => $milestone_id,
]);
$t_sso  = mk($pid, ['subject' => 'Исследовать варианты SSO', 'status_id' => 2]); // Research

// Subtask under "Дизайн лендинга"
$t_sub = mk($pid, ['subject' => 'Подобрать цветовую палитру', 'status_id' => 1, 'parent_id' => $t_land]);

// ---- 5. Decorate the CI task: checklist, comment, tag, time ----------------
$cl = new pmChecklistModel();
$ci1 = $cl->add($t_ci, 'Добавить workflow-файл');
$cl->add($t_ci, 'Настроить кэш зависимостей');
$cl->toggle($ci1, 1); // first item done

(new pmCommentModel())->insert([
    'task_id'         => $t_ci,
    'contact_id'      => 1,
    'text'            => 'Черновик пайплайна готов, осталось кэширование.',
    'is_internal'     => 0,
    'create_datetime' => $now,
]);

(new pmTaskTimesModel())->addEntry($t_ci, 2.5, 'Начальная настройка', 1);

// Tags
(new pmTaskTagModel())->add($t_ci, $tag_feature);
(new pmTaskTagModel())->add($t_bug, $tag_bug);

// ---- 6. Summary -------------------------------------------------------------
$task_ids = [$t_ci, $t_docs, $t_bug, $t_land, $t_rel, $t_sso, $t_sub];
echo "\nSeeded '" . PROJECT_NAME . "'\n";
echo "  project_id   = {$pid} (prefix MCP, workflow " . WORKFLOW . ")\n";
echo "  milestone_id = {$milestone_id} (v1.0)\n";
echo "  tags         = bug#{$tag_bug}, feature#{$tag_feature}\n";
echo "  members      = 1 (admin)" . ($has2 ? ", 2 (member)" : "") . "\n";
echo "  tasks        = " . count($task_ids) . ": " . implode(', ', $task_ids) . "\n";
echo "    #{$t_ci} Настроить CI пайплайн   [In Progress, high, assignee 1, +checklist/comment/time/tag]\n";
echo "    #{$t_docs} Документация по API     [Backlog]\n";
echo "    #{$t_bug} Баг авторизации         [Review, critical, assignee 1, tag bug]\n";
echo "    #{$t_land} Дизайн лендинга        [Backlog] └ #{$t_sub} Подобрать палитру (subtask)\n";
echo "    #{$t_rel} Релиз v1.0              [Done, low, milestone v1.0]\n";
echo "    #{$t_sso} Исследовать SSO         [Research]\n";
echo "\nDONE\n";
