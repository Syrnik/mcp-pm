<?php
/**
 * Integration tests for a representative slice of the Stage 3 task-write tools
 * and the Stage 2 task-read tools against the live DB.
 *
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

class pmMcpTaskToolsTest extends pmMcpIntegrationTestCase
{
    private function makeTask(string $subject = 'ZZ Task'): array
    {
        $r = $this->callTool(new pmMcpCreateTaskTool(), array(
            'project_id' => $this->project_id,
            'subject'    => $subject,
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        return $r;
    }

    /** Give the throwaway project a task-number prefix and return it. */
    private function setPrefix(string $prefix, string $mode = 'prefix', string $separator = '-'): string
    {
        (new pmProjectModel())->updateById($this->project_id, array(
            'prefix'           => $prefix,
            'number_mode'      => $mode,
            'number_separator' => $separator,
        ));
        pmMcpTaskHelper::resetNumberConfig();
        return $prefix;
    }

    public function testCreateAndGetTask(): void
    {
        $created = $this->makeTask('ZZ Hello');
        $task_id = $created['task_id'];

        $r = $this->callTool(new pmMcpGetTaskTool(), array('task_id' => $task_id));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame($task_id, $r['task']['id']);
        $this->assertSame('ZZ Hello', $r['task']['subject']);
        $this->assertSame($this->project_id, $r['task']['project_id']);
    }

    public function testListTasksFindsCreated(): void
    {
        $created = $this->makeTask();
        $r = $this->callTool(new pmMcpListTasksTool(), array('project_id' => $this->project_id));
        $this->assertTrue($r['ok'], json_encode($r));
        $ids = array_column($r['tasks'], 'id');
        $this->assertContains($created['task_id'], $ids);
    }

    public function testMoveTaskThroughAllowedTransition(): void
    {
        $created = $this->makeTask();
        $allowed = $created['task']['allowed_statuses'] ?? array();
        if (!$allowed) {
            $this->markTestSkipped('workflow exposes no transitions from the initial status');
        }
        $target = (int) (is_array($allowed[0]) ? $allowed[0]['id'] : $allowed[0]);

        $r = $this->callTool(new pmMcpMoveTaskTool(), array('task_id' => $created['task_id'], 'status_id' => $target));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame($target, $r['task']['status_id']);
    }

    public function testMoveTaskLogsStatusChange(): void
    {
        $created = $this->makeTask('ZZ Logged move');
        $allowed = $created['task']['allowed_statuses'] ?? array();
        if (!$allowed) {
            $this->markTestSkipped('workflow exposes no transitions from the initial status');
        }
        $task_id = (int) $created['task_id'];
        $from = (int) $created['task']['status_id'];
        $target = (int) (is_array($allowed[0]) ? $allowed[0]['id'] : $allowed[0]);

        $r = $this->callTool(new pmMcpMoveTaskTool(), array('task_id' => $task_id, 'status_id' => $target));
        $this->assertTrue($r['ok'], json_encode($r));

        $entries = array_values(array_filter(
            (new pmActivityLogModel())->getByTask($task_id),
            static function ($e) {
                return $e['action'] === 'status_changed';
            }
        ));
        $this->assertCount(1, $entries, 'the move must leave exactly one status_changed entry');

        $params = json_decode((string) $entries[0]['params'], true);
        $this->assertSame($from, (int) $params['from_id']);
        $this->assertSame($target, (int) $params['to_id']);
        $this->assertNotSame('', (string) $params['to_name'], 'the entry must carry the target status name');
    }

    public function testAddComment(): void
    {
        $created = $this->makeTask();
        $r = $this->callTool(new pmMcpAddTaskCommentTool(), array(
            'task_id' => $created['task_id'],
            'text'    => 'a test comment',
        ));
        $this->assertTrue($r['ok'], json_encode($r));

        $list = $this->callTool(new pmMcpListTaskCommentsTool(), array('task_id' => $created['task_id']));
        $this->assertTrue($list['ok'], json_encode($list));
        $texts = array_column($list['comments'], 'text');
        $joined = implode("\n", array_map('strval', $texts));
        $this->assertStringContainsString('a test comment', $joined);
    }

    public function testUpdateOwnComment(): void
    {
        $created = $this->makeTask();
        $add = $this->callTool(new pmMcpAddTaskCommentTool(), array(
            'task_id' => $created['task_id'],
            'text'    => 'original text',
        ));
        $this->assertTrue($add['ok'], json_encode($add));
        $comment_id = (int) $add['comment']['id'];

        $r = $this->callTool(new pmMcpUpdateTaskCommentTool(), array(
            'task_id'    => $created['task_id'],
            'comment_id' => $comment_id,
            'text'       => 'edited text',
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('edited text', $r['comment']['text']);
        $this->assertNotNull($r['comment']['update_datetime']);

        $list = $this->callTool(new pmMcpListTaskCommentsTool(), array('task_id' => $created['task_id']));
        $texts = array_column($list['comments'], 'text');
        $this->assertContains('edited text', $texts);
        $this->assertNotContains('original text', $texts);
    }

    public function testCannotUpdateSomeoneElsesComment(): void
    {
        $other = $this->otherContactId();
        if ($other === null) {
            $this->markTestSkipped('installation has no second contact');
        }
        (new pmProjectUserModel())->add($this->project_id, $other, 'member');

        $created = $this->makeTask();
        $add = $this->callTool(new pmMcpAddTaskCommentTool(), array(
            'task_id' => $created['task_id'],
            'text'    => 'admin comment',
        ));
        $this->assertTrue($add['ok'], json_encode($add));
        $comment_id = (int) $add['comment']['id'];

        wa()->setUser(new waUser($other));
        try {
            $r = $this->callTool(new pmMcpUpdateTaskCommentTool(), array(
                'task_id'    => $created['task_id'],
                'comment_id' => $comment_id,
                'text'       => 'hijacked text',
            ));
        } finally {
            wa()->setUser(new waUser(1));
        }
        $this->assertFalse($r['ok']);
        $this->assertSame('access_denied', $r['error_code']);
    }

    public function testUpdateCommentNotFoundOnTask(): void
    {
        $created = $this->makeTask();
        $other_task = $this->makeTask('ZZ Other task');
        $add = $this->callTool(new pmMcpAddTaskCommentTool(), array(
            'task_id' => $other_task['task_id'],
            'text'    => 'on other task',
        ));
        $this->assertTrue($add['ok'], json_encode($add));
        $comment_id = (int) $add['comment']['id'];

        $r = $this->callTool(new pmMcpUpdateTaskCommentTool(), array(
            'task_id'    => $created['task_id'],
            'comment_id' => $comment_id,
            'text'       => 'wrong task',
        ));
        $this->assertFalse($r['ok']);
        $this->assertSame('not_found', $r['error_code']);
    }

    public function testGetTaskByFullNumber(): void
    {
        $prefix = $this->setPrefix('ZZQA');
        $created = $this->makeTask('ZZ Numbered');
        $task_id = (int) $created['task_id'];

        foreach (array($prefix . '-' . $task_id, strtolower($prefix) . $task_id, $prefix . ' ' . $task_id) as $ref) {
            $r = $this->callTool(new pmMcpGetTaskTool(), array('task_id' => $ref));
            $this->assertTrue($r['ok'], $ref . ': ' . json_encode($r));
            $this->assertSame($task_id, $r['task']['id'], 'resolved from ' . $ref);
        }

        $this->assertSame($prefix . '-' . $task_id, $r['task']['full_number']);
    }

    public function testGetTaskByPostfixNumber(): void
    {
        $this->setPrefix('ZZQB', 'postfix', '/');
        $created = $this->makeTask('ZZ Postfixed');
        $task_id = (int) $created['task_id'];

        $r = $this->callTool(new pmMcpGetTaskTool(), array('task_id' => $task_id . '/ZZQB'));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame($task_id, $r['task']['id']);
        $this->assertSame($task_id . '/ZZQB', $r['task']['full_number']);
    }

    public function testWrongPrefixDoesNotResolve(): void
    {
        $this->setPrefix('ZZQA');
        $created = $this->makeTask();

        $r = $this->callTool(new pmMcpGetTaskTool(), array('task_id' => 'ZZQZ-' . $created['task_id']));
        $this->assertFalse($r['ok'], 'a prefix naming another project must not resolve');
        $this->assertSame('not_found', $r['error_code']);
        $this->assertStringContainsString('ZZQA-' . $created['task_id'], $r['error_message'], 'the error names the real number');
    }

    public function testListTasksExposesFullNumberAndFindsByReference(): void
    {
        $prefix = $this->setPrefix('ZZQA');
        $created = $this->makeTask('ZZ Listed');
        $task_id = (int) $created['task_id'];

        $r = $this->callTool(new pmMcpListTasksTool(), array('project_id' => $this->project_id));
        $this->assertTrue($r['ok'], json_encode($r));
        $numbers = array_column($r['tasks'], 'full_number', 'id');
        $this->assertSame($prefix . '-' . $task_id, $numbers[$task_id] ?? null);

        // A full number as the search term returns that task, even though the
        // subject does not contain it.
        $r = $this->callTool(new pmMcpListTasksTool(), array(
            'project_id' => $this->project_id,
            'search'     => $prefix . '-' . $task_id,
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertContains($task_id, array_column($r['tasks'], 'id'));
    }

    public function testListTasksSurvivesUnparseableSearch(): void
    {
        $this->setPrefix('ZZQA');
        $this->makeTask('ZZ Searchable');

        foreach (array('---', 'ZZQZ-999999', 'nothing here') as $search) {
            $r = $this->callTool(new pmMcpListTasksTool(), array(
                'project_id' => $this->project_id,
                'search'     => $search,
            ));
            $this->assertTrue($r['ok'], $search . ': ' . json_encode($r));
        }
    }

    public function testWriteToolsAcceptFullNumber(): void
    {
        $prefix = $this->setPrefix('ZZQA');
        $created = $this->makeTask('ZZ Writable');
        $ref = $prefix . '-' . $created['task_id'];

        $r = $this->callTool(new pmMcpUpdateTaskTool(), array('task_id' => $ref, 'subject' => 'ZZ Renamed'));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('ZZ Renamed', $r['task']['subject']);

        $r = $this->callTool(new pmMcpAddTaskCommentTool(), array('task_id' => $ref, 'text' => 'by number'));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame((int) $created['task_id'], $r['comment']['task_id']);

        // A subtask parented by the full number attaches to the same task.
        $child = $this->callTool(new pmMcpCreateTaskTool(), array(
            'project_id' => $this->project_id,
            'subject'    => 'ZZ Child',
            'parent_id'  => $ref,
        ));
        $this->assertTrue($child['ok'], json_encode($child));
        $this->assertSame((int) $created['task_id'], (int) $child['task']['parent_id']);

        $r = $this->callTool(new pmMcpDeleteTaskTool(), array('task_id' => $ref, 'confirm' => true));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame($ref, $r['full_number']);
    }

    /**
     * A milestone and a sprint of another project are rejected together, in one
     * answer, with this project's own (empty) options — not one per call with a
     * bare "does not belong to this project" (Task #320).
     */
    public function testCreateTaskReportsForeignReferencesAtOnce(): void
    {
        $other_project_id = $this->makeProject('ZZ MCP Other ' . uniqid());
        $this->extra_project_ids[] = $other_project_id;

        $foreign_milestone_id = (int) (new pmMilestoneModel())->insert(array(
            'project_id' => $other_project_id,
            'name'       => 'ZZ Foreign Milestone',
            'status'     => 'active',
            'sort'       => 1,
        ));
        $sprint_model = new pmSprintModel();
        $foreign_sprint_id = (int) $sprint_model->insert(array(
            'name'     => 'ZZ Foreign Sprint',
            'status'   => 'planned',
            'duration' => 1,
            'sort'     => 1,
        ));
        (new pmSprintProjectModel())->insert(array(
            'sprint_id'  => $foreign_sprint_id,
            'project_id' => $other_project_id,
        ));

        $r = $this->callTool(new pmMcpCreateTaskTool(), array(
            'project_id'   => $this->project_id,
            'subject'      => 'ZZ Foreign refs',
            'milestone_id' => $foreign_milestone_id,
            'sprint_id'    => $foreign_sprint_id,
        ));

        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertSame('invalid_param', $r['error_code']);
        // Both mismatches in one answer, not just the first one pm would hit.
        $this->assertStringContainsString('milestone_id', $r['error_message']);
        $this->assertStringContainsString('sprint_id', $r['error_message']);
        // The project has neither, so the options are empty — which is exactly
        // the fact the old bare message hid.
        $this->assertSame(array(), $r['available_milestones']);
        $this->assertSame(array(), $r['available_sprints']);

        // And the same references are refused on update.
        $task = $this->makeTask('ZZ Foreign refs target');
        $u = $this->callTool(new pmMcpUpdateTaskTool(), array(
            'task_id'      => $task['task_id'],
            'milestone_id' => $foreign_milestone_id,
        ));
        $this->assertFalse($u['ok'], json_encode($u));
        $this->assertSame('invalid_param', $u['error_code']);

        $sprint_model->deleteById($foreign_sprint_id);
        (new pmSprintProjectModel())->deleteByField('sprint_id', $foreign_sprint_id);
    }

    /**
     * A milestone that does belong to the project is listed back when the
     * caller names a different one, so the correction needs no extra lookup.
     */
    public function testForeignMilestoneAnswerListsTheProjectsOwn(): void
    {
        $own_milestone_id = (int) (new pmMilestoneModel())->insert(array(
            'project_id' => $this->project_id,
            'name'       => 'ZZ Own Milestone',
            'status'     => 'active',
            'sort'       => 1,
        ));

        $r = $this->callTool(new pmMcpCreateTaskTool(), array(
            'project_id'   => $this->project_id,
            'subject'      => 'ZZ Wrong milestone',
            'milestone_id' => $own_milestone_id + 100000,
        ));
        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertSame(
            array($own_milestone_id),
            array_column($r['available_milestones'], 'id')
        );

        // The right one goes through.
        $ok = $this->callTool(new pmMcpCreateTaskTool(), array(
            'project_id'   => $this->project_id,
            'subject'      => 'ZZ Right milestone',
            'milestone_id' => $own_milestone_id,
        ));
        $this->assertTrue($ok['ok'], json_encode($ok));
        $this->assertSame($own_milestone_id, $ok['task']['milestone_id']);
    }

    /** 0 means "no milestone / no sprint / unassigned", not an invalid id. */
    public function testCreateTaskTreatsZeroReferencesAsEmpty(): void
    {
        $tool = new pmMcpCreateTaskTool();
        $args = array(
            'project_id'          => $this->project_id,
            'subject'             => 'ZZ Zero refs',
            'milestone_id'        => 0,
            'sprint_id'           => 0,
            'assignee_contact_id' => 0,
        );
        $this->assertSame(array(), $tool->validate($args), 'zero must pass schema validation');

        $r = $this->callTool($tool, $args);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertNull($r['task']['milestone_id']);
        $this->assertNull($r['task']['sprint_id']);
        $this->assertNull($r['task']['assignee_contact_id']);
    }

    /**
     * PMCP-518: pm_task.sprint_id became NOT NULL DEFAULT 0 in pm 0.33.5 (0 =
     * backlog, NULL no longer used — migration 1786112356). Before this fix,
     * pm_create_task wrote PHP null for an omitted sprint_id, which
     * waModel::castValue() turned into '' for the NOT NULL int unsigned
     * column, and the INSERT failed with a 1366 db_error — the ordinary
     * "create in the backlog" case could not create a task at all.
     *
     * type_slug is passed explicitly because pm now requires it
     * unconditionally on every pmTask::create()/save() call
     * (pmTask.class.php:235) regardless of what the caller omits — a
     * separate, pre-existing regression outside this test's scope.
     */
    public function testCreateTaskWithoutSprintGoesToBacklog(): void
    {
        $r = $this->callTool(new pmMcpCreateTaskTool(), array(
            'project_id' => $this->project_id,
            'subject'    => 'ZZ No sprint',
            'type_slug'  => 'task',
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertNull($r['task']['sprint_id']);

        // The tool's response maps both 0 and NULL to null (formatTaskRow's
        // !empty() check) — read the raw row to confirm what actually landed
        // in the NOT NULL column, not just what the tool reports back.
        $row = (new pmTaskModel())->getById($r['task_id']);
        $this->assertSame(0, (int) $row['sprint_id']);
    }

    /** Same failure mode, reached through pm_update_task moving a task to the backlog. */
    public function testUpdateTaskSprintZeroMovesToBacklog(): void
    {
        $sprint_model = new pmSprintModel();
        $sprint_id = (int) $sprint_model->insert(array(
            'name'     => 'ZZ Backlog Move Sprint',
            'status'   => 'planned',
            'duration' => 1,
            'sort'     => 1,
        ));
        (new pmSprintProjectModel())->insert(array(
            'sprint_id'  => $sprint_id,
            'project_id' => $this->project_id,
        ));

        $created = $this->callTool(new pmMcpCreateTaskTool(), array(
            'project_id' => $this->project_id,
            'subject'    => 'ZZ In sprint',
            'type_slug'  => 'task',
            'sprint_id'  => $sprint_id,
        ));
        $this->assertTrue($created['ok'], json_encode($created));
        $this->assertSame($sprint_id, $created['task']['sprint_id']);

        $u = $this->callTool(new pmMcpUpdateTaskTool(), array(
            'task_id'   => $created['task_id'],
            'sprint_id' => 0,
        ));
        $this->assertTrue($u['ok'], json_encode($u));
        $this->assertNull($u['task']['sprint_id']);

        $row = (new pmTaskModel())->getById($created['task_id']);
        $this->assertSame(0, (int) $row['sprint_id']);

        $sprint_model->deleteById($sprint_id);
        (new pmSprintProjectModel())->deleteByField('sprint_id', $sprint_id);
    }

    public function testDeleteTaskRequiresConfirm(): void
    {
        $created = $this->makeTask();

        $r = $this->callTool(new pmMcpDeleteTaskTool(), array('task_id' => $created['task_id']));
        $this->assertFalse($r['ok']);
        $this->assertSame('confirm_required', $r['error_code']);

        $r = $this->callTool(new pmMcpDeleteTaskTool(), array('task_id' => $created['task_id'], 'confirm' => true));
        $this->assertTrue($r['ok'], json_encode($r));

        $gone = $this->callTool(new pmMcpGetTaskTool(), array('task_id' => $created['task_id']));
        $this->assertFalse($gone['ok']);
        $this->assertSame('not_found', $gone['error_code']);
    }
}
