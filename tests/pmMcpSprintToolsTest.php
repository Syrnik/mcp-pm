<?php
/**
 * Integration tests for the Stage 6 sprint read tools against the live DB.
 *
 * Sprints are per-project; the tests attach sprints to the throwaway project
 * through pmSprintModel (the same M:N mechanism the pm UI uses).
 *
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

class pmMcpSprintToolsTest extends pmMcpIntegrationTestCase
{
    private function addSprint(array $data): int
    {
        $model = new pmSprintModel();
        $id = (int) $model->insert($data);
        $model->saveProjects($id, array($this->project_id));
        return $id;
    }

    public function testListOrdersActivePlannedCompleted(): void
    {
        $this->addSprint(array('name' => 'A', 'status' => 'completed', 'start_date' => '2026-06-01', 'sort' => 2));
        $this->addSprint(array('name' => 'B', 'status' => 'active', 'start_date' => '2026-07-01', 'sort' => 0));
        $this->addSprint(array('name' => 'C', 'status' => 'planned', 'start_date' => '2026-07-15', 'sort' => 1));

        $r = $this->callTool(new pmMcpListSprintsTool(), array('project_id' => $this->project_id));
        $this->assertTrue($r['ok'], json_encode($r));
        $statuses = array_column($r['sprints'], 'status');
        $this->assertSame(array('active', 'planned', 'completed'), $statuses);
        foreach ($r['sprints'] as $s) {
            $this->assertSame($this->project_id, $s['project_id'], 'sprint reports its owning project');
        }
    }

    public function testListStatusFilter(): void
    {
        $this->addSprint(array('name' => 'A', 'status' => 'active', 'sort' => 0));
        $this->addSprint(array('name' => 'B', 'status' => 'planned', 'sort' => 1));

        $r = $this->callTool(new pmMcpListSprintsTool(), array('project_id' => $this->project_id, 'status' => 'active'));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(1, $r['count']);
        $this->assertSame('active', $r['sprints'][0]['status']);
    }

    public function testGetSprintCardExpandsProjectsAndFillStatuses(): void
    {
        $sid = $this->addSprint(array('name' => 'Card', 'status' => 'active', 'auto_fill' => 1, 'duration' => 2));
        (new pmSprintModel())->saveFillStatuses($sid, array(1, 2));

        $r = $this->callTool(new pmMcpGetSprintTool(), array('sprint_id' => $sid));
        $this->assertTrue($r['ok'], json_encode($r));
        $card = $r['sprint'];
        $this->assertSame($this->project_id, $card['project_id']);
        $this->assertNotEmpty($card['projects'], 'linked projects expanded');
        $this->assertCount(2, $card['fill_statuses'], 'auto-fill statuses expanded');
        $this->assertArrayHasKey('name', $card['fill_statuses'][0]);
        $this->assertTrue($card['automation']['auto_fill']);
    }

    public function testGetMissingSprint(): void
    {
        $r = $this->callTool(new pmMcpGetSprintTool(), array('sprint_id' => 999999999));
        $this->assertFalse($r['ok']);
        $this->assertSame('not_found', $r['error_code']);
    }

    public function testNonMemberIsDenied(): void
    {
        $other = $this->otherContactId();
        if ($other === null) {
            $this->markTestSkipped('installation has no second contact');
        }
        // A foreign project the "other" (non-admin) contact is not a member of.
        $foreign_pid = $this->makeProject('ZZ Foreign ' . uniqid());
        $this->extra_project_ids[] = $foreign_pid;
        $model = new pmSprintModel();
        $sid = (int) $model->insert(array('name' => 'F', 'status' => 'planned', 'sort' => 0));
        $model->saveProjects($sid, array($foreign_pid));

        // Act as the non-admin, non-member contact.
        wa()->setUser(new waUser($other));
        try {
            $get = $this->callTool(new pmMcpGetSprintTool(), array('sprint_id' => $sid));
            $this->assertFalse($get['ok']);
            $this->assertSame('access_denied', $get['error_code']);

            $list = $this->callTool(new pmMcpListSprintsTool(), array('project_id' => $foreign_pid));
            $this->assertFalse($list['ok']);
            $this->assertSame('access_denied', $list['error_code']);
        } finally {
            // Restore admin so tearDown can clean up.
            wa()->setUser(new waUser(1));
        }
    }
}
