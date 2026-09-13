<?php
/**
 * Integration tests for the Stage 8 sprint write tools (pm_create_sprint,
 * pm_update_sprint, pm_manage_sprint) against the live DB.
 *
 * Status ids used below come from the install's two seeded workflows
 * (lib/config/data/workflow.php): 'dvlpmnt' carries statuses 1,2,3,4,6;
 * 'management' carries 1,2,3,4,5,6 — status 5 exists only in 'management',
 * which is what lets a couple of tests tell the two workflows apart.
 *
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

class pmMcpSprintWriteToolsTest extends pmMcpIntegrationTestCase
{
    /** A second throwaway project, attached to the 'management' workflow only. */
    private function makeManagementProject(): int
    {
        $pid = $this->makeProject('ZZ Mgmt ' . uniqid());
        $this->extra_project_ids[] = $pid;
        (new pmProjectModel())->saveWorkflows($pid, array('management'));
        return $pid;
    }

    private function makeTask(int $project_id, array $overrides = array()): int
    {
        $r = $this->callTool(new pmMcpCreateTaskTool(), array_merge(array(
            'project_id' => $project_id,
            'subject'    => 'ZZ Task ' . uniqid(),
            'type_slug'  => $this->defaultTypeSlug($project_id),
        ), $overrides));
        $this->assertTrue($r['ok'], json_encode($r));
        return (int) $r['task_id'];
    }

    // ── create ──

    public function testCreateSpansTwoProjects(): void
    {
        $pB = $this->makeManagementProject();

        $r = $this->callTool(new pmMcpCreateSprintTool(), array(
            'project_ids'   => array($this->project_id, $pB),
            'name'          => 'Cross',
            'start_date'    => '2026-08-01',
            'duration_weeks' => 2,
            'workflows'     => array(
                array('project_id' => $this->project_id, 'workflow_ids' => array('dvlpmnt')),
                array('project_id' => $pB, 'workflow_ids' => array('management')),
            ),
            'fill_status_ids' => array(1, 5),
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $sprint_id = $r['sprint_id'];
        $this->assertSame('planned', $r['sprint']['status']);

        // Re-read through the domain model, not the tool's own response.
        $stored = (new pmSprintModel())->getById($sprint_id);
        $this->assertEqualsCanonicalizing(array($this->project_id, $pB), $stored['project_ids']);
        $this->assertSame(array('dvlpmnt'), $stored['workflows'][$this->project_id]);
        $this->assertSame(array('management'), $stored['workflows'][$pB]);
        $this->assertEqualsCanonicalizing(array(1, 5), $stored['fill_status_ids']);
        $this->assertSame('2026-08-14', $stored['end_date']);

        $log = (new pmActivityLogModel())->getByField(array('project_id' => $this->project_id, 'action' => 'sprint_created'), true);
        $this->assertNotEmpty($log, 'sprint_created is logged on the first listed project');
    }

    public function testCreateRejectsInaccessibleProject(): void
    {
        $other = $this->otherContactId();
        if ($other === null) {
            $this->markTestSkipped('installation has no second contact');
        }
        wa()->setUser(new waUser($other));
        try {
            $r = $this->callTool(new pmMcpCreateSprintTool(), array(
                'project_ids' => array($this->project_id),
                'name'        => 'Nope',
            ));
            $this->assertFalse($r['ok']);
            $this->assertSame('access_denied', $r['error_code']);
        } finally {
            wa()->setUser(new waUser(1));
        }
    }

    public function testCreateRejectsWorkflowNotOnProject(): void
    {
        $r = $this->callTool(new pmMcpCreateSprintTool(), array(
            'project_ids' => array($this->project_id),
            'name'        => 'Bad workflow',
            'workflows'   => array(array('project_id' => $this->project_id, 'workflow_ids' => array('management'))),
        ));
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_param', $r['error_code']);
        $this->assertArrayHasKey('available_workflows', $r);
    }

    public function testCreateRejectsFillStatusOutsideSelectedWorkflows(): void
    {
        $r = $this->callTool(new pmMcpCreateSprintTool(), array(
            'project_ids'     => array($this->project_id),
            'name'            => 'Bad fill status',
            'workflows'       => array(array('project_id' => $this->project_id, 'workflow_ids' => array('dvlpmnt'))),
            'fill_status_ids' => array(5), // 5 only exists in 'management'
        ));
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_param', $r['error_code']);
        $this->assertArrayHasKey('available_fill_status_ids', $r);
        $this->assertNotContains(5, $r['available_fill_status_ids']);
    }

    public function testCreateRejectsFillStatusWhenProjectHasNoWorkflow(): void
    {
        $pid = $this->makeProject('ZZ No Workflow ' . uniqid());
        $this->extra_project_ids[] = $pid;
        (new pmProjectModel())->saveWorkflows($pid, array());

        $r = $this->callTool(new pmMcpCreateSprintTool(), array(
            'project_ids'     => array($pid),
            'name'            => 'No workflow',
            'fill_status_ids' => array(1),
        ));
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_param', $r['error_code']);
        $this->assertArrayHasKey('available_fill_status_ids', $r);
        $this->assertSame(array(), $r['available_fill_status_ids'], 'an empty set is distinguishable from a status simply not being in it');
    }

    // ── update: the merge-rule regression ──

    public function testUpdateNameAlonePreservesEveryRelation(): void
    {
        $pB = $this->makeManagementProject();
        $sprint_id = $this->makeSprint(
            array('name' => 'Original', 'status' => 'planned', 'start_date' => '2026-08-01', 'duration' => 2, 'end_date' => '2026-08-14'),
            array($this->project_id, $pB),
            array(
                array('project_id' => $this->project_id, 'workflow_id' => 'dvlpmnt'),
                array('project_id' => $pB, 'workflow_id' => 'management'),
            ),
            array(1, 5)
        );

        $r = $this->callTool(new pmMcpUpdateSprintTool(), array('sprint_id' => $sprint_id, 'name' => 'Renamed'));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(array('name'), $r['updated_fields']);
        $this->assertArrayNotHasKey('warnings', $r);

        // The regression check: re-read through the domain model, not the
        // tool's own response, so a bug that only shows up in storage (not in
        // what formatSprintCard() echoes back) is still caught.
        $stored = (new pmSprintModel())->getById($sprint_id);
        $this->assertSame('Renamed', $stored['name']);
        $this->assertEqualsCanonicalizing(array($this->project_id, $pB), $stored['project_ids']);
        $this->assertSame(array('dvlpmnt'), $stored['workflows'][$this->project_id]);
        $this->assertSame(array('management'), $stored['workflows'][$pB]);
        $this->assertEqualsCanonicalizing(array(1, 5), $stored['fill_status_ids']);
        $this->assertSame('2026-08-01', $stored['start_date']);
        $this->assertSame('2026-08-14', $stored['end_date']);
        $this->assertSame(2, (int) $stored['duration']);
    }

    public function testUpdateStartDateAloneRecomputesEndDate(): void
    {
        $sprint_id = $this->makeSprint(array(
            'name' => 'S', 'status' => 'planned', 'start_date' => '2026-08-01', 'duration' => 2, 'end_date' => '2026-08-14',
        ));

        $r = $this->callTool(new pmMcpUpdateSprintTool(), array('sprint_id' => $sprint_id, 'start_date' => '2026-09-01'));
        $this->assertTrue($r['ok'], json_encode($r));

        $stored = (new pmSprintModel())->getById($sprint_id);
        $this->assertSame('2026-09-01', $stored['start_date']);
        $this->assertSame('2026-09-14', $stored['end_date'], 'duration carried over from the stored sprint');
    }

    public function testUpdateClearingStartDateClearsEndDate(): void
    {
        $sprint_id = $this->makeSprint(array(
            'name' => 'S', 'status' => 'planned', 'start_date' => '2026-08-01', 'duration' => 2, 'end_date' => '2026-08-14',
        ));

        $r = $this->callTool(new pmMcpUpdateSprintTool(), array('sprint_id' => $sprint_id, 'start_date' => ''));
        $this->assertTrue($r['ok'], json_encode($r));

        // Exact NULL, not just falsy: waModel writes the column's actual SQL
        // NULL for a PHP null through updateById() (getFieldValue()), distinct
        // from a zero-date or empty string that assertEmpty() would also pass.
        $stored = (new pmSprintModel())->getById($sprint_id);
        $this->assertNull($stored['start_date']);
        $this->assertNull($stored['end_date']);
    }

    public function testUpdateCannotDetachInaccessibleProject(): void
    {
        $other = $this->otherContactId();
        if ($other === null) {
            $this->markTestSkipped('installation has no second contact');
        }
        $pB = $this->makeManagementProject();
        $sprint_id = $this->makeSprint(array('name' => 'Shared', 'status' => 'planned'), array($this->project_id, $pB));

        // "other" is a project-role admin of project A only, so it has
        // sprint.edit there but is not even a member of B.
        (new pmProjectUserModel())->add($this->project_id, $other, 'admin');

        wa()->setUser(new waUser($other));
        try {
            $r = $this->callTool(new pmMcpUpdateSprintTool(), array('sprint_id' => $sprint_id, 'project_ids' => array($this->project_id)));
            $this->assertTrue($r['ok'], json_encode($r));
        } finally {
            wa()->setUser(new waUser(1));
        }

        $stored = (new pmSprintModel())->getById($sprint_id);
        $this->assertEqualsCanonicalizing(array($this->project_id, $pB), $stored['project_ids'], 'a project the caller cannot access is never detached');

        (new pmProjectUserModel())->remove($this->project_id, $other);
    }

    public function testUpdateShrinkWarnsAboutHiddenTasks(): void
    {
        $pB = $this->makeManagementProject();
        $sprint_id = $this->makeSprint(array('name' => 'Shrink', 'status' => 'planned'), array($this->project_id, $pB));
        $task_id = $this->makeTask($pB, array('sprint_id' => $sprint_id, 'status_id' => 1));

        $r = $this->callTool(new pmMcpUpdateSprintTool(), array('sprint_id' => $sprint_id, 'project_ids' => array($this->project_id)));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertArrayHasKey('warnings', $r);
        $codes = array_column($r['warnings'], 'code');
        $this->assertContains('tasks_hidden_from_board', $codes);

        $task = (new pmTaskModel())->getById($task_id);
        $this->assertSame($sprint_id, (int) $task['sprint_id'], 'the task stays attached — only the board visibility changed');
    }

    public function testUpdateRejectsStatus(): void
    {
        $sprint_id = $this->makeSprint(array('name' => 'S', 'status' => 'planned'));

        $r = $this->callTool(new pmMcpUpdateSprintTool(), array('sprint_id' => $sprint_id, 'name' => 'S2', 'status' => 'active'));
        $this->assertTrue($r['ok'], json_encode($r));

        $stored = (new pmSprintModel())->getById($sprint_id);
        $this->assertSame('planned', $stored['status'], 'status is not settable through pm_update_sprint');
    }

    // ── manage: activate / complete / delete ──

    public function testActivateOnlyFromPlanned(): void
    {
        $sprint_id = $this->makeSprint(array('name' => 'Done', 'status' => 'completed'));

        $r = $this->callTool(new pmMcpManageSprintTool(), array('sprint_id' => $sprint_id, 'action' => 'activate'));
        $this->assertFalse($r['ok']);
        $this->assertSame('conflict', $r['error_code']);

        $stored = (new pmSprintModel())->getById($sprint_id);
        $this->assertSame('completed', $stored['status']);
    }

    public function testActivateFillsFromBacklog(): void
    {
        $task_id = $this->makeTask($this->project_id, array('status_id' => 2));
        $sprint_id = $this->makeSprint(
            array('name' => 'Fill', 'status' => 'planned', 'auto_fill' => 1),
            array($this->project_id),
            array(),
            array(2)
        );

        $r = $this->callTool(new pmMcpManageSprintTool(), array('sprint_id' => $sprint_id, 'action' => 'activate'));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertGreaterThanOrEqual(1, $r['filled_task_count']);

        $task = (new pmTaskModel())->getById($task_id);
        $this->assertSame($sprint_id, (int) $task['sprint_id']);
    }

    public function testCompleteRenamesAndReportsBacklogMove(): void
    {
        $sprint_id = $this->makeSprint(array(
            'name' => 'Active', 'status' => 'active', 'move_unfinished' => 1, 'auto_create_next' => 0,
            'start_date' => '2026-08-01', 'duration' => 1, 'end_date' => '2026-08-07',
        ));
        $task_id = $this->makeTask($this->project_id, array('sprint_id' => $sprint_id, 'status_id' => 2));

        $r = $this->callTool(new pmMcpManageSprintTool(), array('sprint_id' => $sprint_id, 'action' => 'complete'));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertTrue($r['completed']);
        $this->assertNull($r['new_sprint']);
        $this->assertNotNull($r['moved_unfinished']);
        $this->assertSame('backlog', $r['moved_unfinished']['target']);
        $this->assertNull($r['moved_unfinished']['target_sprint_id']);
        $this->assertGreaterThanOrEqual(1, $r['moved_unfinished']['task_count']);

        // pm_task.sprint_id is NOT NULL DEFAULT 0 (pm 0.33.5+): a task moved
        // to the backlog lands on 0 in the row, not NULL (PMCP-518).
        $task = (new pmTaskModel())->getById($task_id);
        $this->assertSame(0, (int) $task['sprint_id']);

        $stored = (new pmSprintModel())->getById($sprint_id);
        $this->assertSame('completed', $stored['status']);
        $this->assertNotSame('Active', $stored['name'], 'the completed sprint is renamed with its date range');
    }

    public function testCompleteCreatesNextSprint(): void
    {
        $sprint_id = $this->makeSprint(
            array('name' => 'Next', 'status' => 'active', 'start_date' => '2026-08-01', 'duration' => 1, 'end_date' => '2026-08-07', 'auto_create_next' => 1),
            array($this->project_id),
            array(array('project_id' => $this->project_id, 'workflow_id' => 'dvlpmnt'))
        );

        $r = $this->callTool(new pmMcpManageSprintTool(), array('sprint_id' => $sprint_id, 'action' => 'complete'));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertNotNull($r['new_sprint']);
        $this->assertContains($this->project_id, $r['new_sprint']['project_ids']);
    }

    public function testDeleteRequiresConfirm(): void
    {
        $sprint_id = $this->makeSprint(array('name' => 'ToDelete', 'status' => 'planned'));

        $r = $this->callTool(new pmMcpManageSprintTool(), array('sprint_id' => $sprint_id, 'action' => 'delete'));
        $this->assertFalse($r['ok']);
        $this->assertSame('confirm_required', $r['error_code']);
        $this->assertNotNull((new pmSprintModel())->getById($sprint_id));
    }

    public function testDeleteDetachesTasks(): void
    {
        $sprint_id = $this->makeSprint(array('name' => 'ToDelete', 'status' => 'planned'));
        $task_id = $this->makeTask($this->project_id, array('sprint_id' => $sprint_id));

        $r = $this->callTool(new pmMcpManageSprintTool(), array('sprint_id' => $sprint_id, 'action' => 'delete', 'confirm' => true));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertTrue($r['deleted']);
        $this->assertGreaterThanOrEqual(1, $r['detached_task_count']);

        $this->assertNull((new pmSprintModel())->getById($sprint_id));
        $task = (new pmTaskModel())->getById($task_id);
        $this->assertNotEmpty($task, 'the task itself is not deleted');
        // pm_task.sprint_id is NOT NULL DEFAULT 0 (pm 0.33.5+): a detached
        // task lands on 0 in the row, not NULL (PMCP-518).
        $this->assertSame(0, (int) $task['sprint_id']);
    }
}
