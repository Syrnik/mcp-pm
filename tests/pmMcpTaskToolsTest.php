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
