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
