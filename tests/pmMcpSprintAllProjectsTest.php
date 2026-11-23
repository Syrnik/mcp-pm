<?php
/**
 * PMCP-694: "all projects" sprints (no pm_sprint_project rows, project_ids =
 * null, covering every active project) across the sprint tools, and the
 * per-operation sprint rights of pm 1.0.0 (#595) the tools mirror.
 *
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

class pmMcpSprintAllProjectsTest extends pmMcpIntegrationTestCase
{
    /**
     * An "all projects" sprint covers only ACTIVE projects
     * (pmProjectModel::getActiveProjects()); test projects start "planned".
     */
    protected function setUp(): void
    {
        parent::setUp();
        (new pmProjectModel())->updateById($this->project_id, array('status' => 'active'));
    }

    /** An "all projects" sprint written straight through the domain class, as pm's UI does. */
    private function makeAllProjectsSprint(string $name = 'ZZ All'): int
    {
        $id = (int) pmSprint::save(array('name' => $name . ' ' . uniqid(), 'status' => 'planned', 'duration' => 1), null, array(), array());
        $this->extra_sprint_ids[] = $id;
        return $id;
    }

    private function otherOrSkip(): int
    {
        $other = $this->otherContactId();
        if ($other === null) {
            $this->markTestSkipped('installation has no second contact');
        }
        $this->assertFalse((new waUser($other))->isAdmin('pm'), 'fixture assumption: the second contact is not a pm admin');
        return $other;
    }

    /** Run $fn as another contact, restoring the admin afterwards. */
    private function asUser(int $contact_id, callable $fn)
    {
        wa()->setUser(new waUser($contact_id));
        try {
            return $fn();
        } finally {
            wa()->setUser(new waUser(1));
        }
    }

    private function extraProject(): int
    {
        $pid = $this->makeProject('ZZ Extra ' . uniqid());
        $this->extra_project_ids[] = $pid;
        (new pmProjectModel())->updateById($pid, array('status' => 'active'));
        return $pid;
    }

    // ── create ──

    public function testAdminCreatesAllProjectsSprint(): void
    {
        $r = $this->callTool(new pmMcpCreateSprintTool(), array('name' => 'ZZ All create', 'all_projects' => true));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->extra_sprint_ids[] = $r['sprint_id'];

        $this->assertTrue($r['sprint']['all_projects']);
        $this->assertContains($this->project_id, $r['sprint']['project_ids'], 'covers every active project, the test one included');
        $this->assertNull((new pmSprintModel())->getById($r['sprint_id'])['project_ids'], 'stored with no pm_sprint_project rows');
    }

    public function testCreateRejectsAllProjectsWithProjectIds(): void
    {
        $r = $this->callTool(new pmMcpCreateSprintTool(), array('name' => 'ZZ Both', 'all_projects' => true, 'project_ids' => array($this->project_id)));
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_param', $r['error_code']);
    }

    public function testCreateRejectsAllProjectsWithWorkflows(): void
    {
        $r = $this->callTool(new pmMcpCreateSprintTool(), array(
            'name'         => 'ZZ Wf',
            'all_projects' => true,
            'workflows'    => array(array('project_id' => $this->project_id, 'workflow_ids' => array($this->workflowId()))),
        ));
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_param', $r['error_code']);
    }

    public function testNonAdminCannotCreateAllProjectsSprint(): void
    {
        $other = $this->otherOrSkip();
        (new pmProjectUserModel())->add($this->project_id, $other, 'admin');
        $r = $this->asUser($other, function () {
            return $this->callTool(new pmMcpCreateSprintTool(), array('name' => 'ZZ Nope', 'all_projects' => true));
        });
        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertSame('access_denied', $r['error_code']);
    }

    /** pm #595 rule 1: sprint.edit is needed in EVERY project of a new sprint. */
    public function testNonAdminCreateNeedsSprintEditInEveryProject(): void
    {
        $other = $this->otherOrSkip();
        $pB = $this->extraProject();
        (new pmProjectUserModel())->add($this->project_id, $other, 'admin');
        (new pmProjectUserModel())->add($pB, $other, 'viewer');

        $r = $this->asUser($other, function () use ($pB) {
            return $this->callTool(new pmMcpCreateSprintTool(), array('name' => 'ZZ Two', 'project_ids' => array($this->project_id, $pB)));
        });
        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertSame('access_denied', $r['error_code']);

        $r = $this->asUser($other, function () {
            return $this->callTool(new pmMcpCreateSprintTool(), array('name' => 'ZZ One', 'project_ids' => array($this->project_id)));
        });
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertFalse($r['sprint']['all_projects']);
    }

    // ── read ──

    /** A project that is not active is not covered, exactly as in pm. */
    public function testAllProjectsSprintSkipsInactiveProjects(): void
    {
        $sid = $this->makeAllProjectsSprint();
        (new pmProjectModel())->updateById($this->project_id, array('status' => 'completed'));
        $r = $this->callTool(new pmMcpListSprintsTool(), array('project_id' => $this->project_id, 'limit' => 200));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertNotContains($sid, array_column($r['sprints'], 'id'));
    }

    public function testListFindsAllProjectsSprintUnderAnyProject(): void
    {
        $sid = $this->makeAllProjectsSprint();
        $pB = $this->extraProject();
        foreach (array($this->project_id, $pB) as $pid) {
            $r = $this->callTool(new pmMcpListSprintsTool(), array('project_id' => $pid, 'limit' => 200));
            $this->assertTrue($r['ok'], json_encode($r));
            $row = array_values(array_filter($r['sprints'], function ($s) use ($sid) {
                return $s['id'] === $sid;
            }));
            $this->assertCount(1, $row, "all-projects sprint must show under project $pid");
            $this->assertTrue($row[0]['all_projects']);
            $this->assertContains($pid, $row[0]['project_ids']);
        }
    }

    public function testMemberOfOneProjectSeesAllProjectsSprintNarrowed(): void
    {
        $other = $this->otherOrSkip();
        $sid = $this->makeAllProjectsSprint();
        $this->extraProject(); // another active project the caller is not in
        (new pmProjectUserModel())->add($this->project_id, $other, 'viewer');

        $r = $this->asUser($other, function () use ($sid) {
            return $this->callTool(new pmMcpGetSprintTool(), array('sprint_id' => $sid));
        });
        $this->assertTrue($r['ok'], json_encode($r));
        $card = $r['sprint'];
        $this->assertTrue($card['all_projects']);
        $this->assertContains($this->project_id, $card['project_ids']);
        $this->assertGreaterThan(0, $card['hidden_project_count']);
        $this->assertFalse($card['can_edit'], 'viewer has no sprint.edit');

        $r = $this->asUser($other, function () {
            return $this->callTool(new pmMcpListSprintsTool(), array('limit' => 200));
        });
        $this->assertContains($sid, array_column($r['sprints'], 'id'), 'listed without project_id too');
    }

    /** Tasks can be put into an all-projects sprint from any project. */
    public function testCreateTaskInAllProjectsSprint(): void
    {
        $sid = $this->makeAllProjectsSprint();
        $r = $this->callTool(new pmMcpCreateTaskTool(), array(
            'project_id' => $this->project_id,
            'subject'    => 'ZZ In all-projects sprint',
            'type_slug'  => $this->defaultTypeSlug(),
            'sprint_id'  => $sid,
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame($sid, $r['task']['sprint_id']);
    }

    // ── update ──

    public function testUpdateFieldsKeepsAllProjectsMode(): void
    {
        $sid = $this->makeAllProjectsSprint();
        $r = $this->callTool(new pmMcpUpdateSprintTool(), array('sprint_id' => $sid, 'name' => 'ZZ Renamed all'));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertTrue($r['sprint']['all_projects']);
        $this->assertNull((new pmSprintModel())->getById($sid)['project_ids']);
    }

    public function testNonAdminWithSprintEditRenamesAllProjectsSprint(): void
    {
        $other = $this->otherOrSkip();
        $sid = $this->makeAllProjectsSprint();
        (new pmProjectUserModel())->add($this->project_id, $other, 'admin');

        $r = $this->asUser($other, function () use ($sid) {
            return $this->callTool(new pmMcpUpdateSprintTool(), array('sprint_id' => $sid, 'goal' => 'kept all'));
        });
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertNull((new pmSprintModel())->getById($sid)['project_ids'], 'mode survives an update by a non-admin');

        // ...but may not change its composition (pm #595 rule 3).
        $r = $this->asUser($other, function () use ($sid) {
            return $this->callTool(new pmMcpUpdateSprintTool(), array('sprint_id' => $sid, 'project_ids' => array($this->project_id)));
        });
        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertSame('access_denied', $r['error_code']);
        $this->assertNull((new pmSprintModel())->getById($sid)['project_ids']);
    }

    public function testAdminSwitchesBetweenModes(): void
    {
        $sid = $this->makeSprint(array('name' => 'ZZ Explicit', 'status' => 'planned', 'duration' => 1));

        $r = $this->callTool(new pmMcpUpdateSprintTool(), array('sprint_id' => $sid, 'all_projects' => true));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertTrue($r['sprint']['all_projects']);
        $this->assertNull((new pmSprintModel())->getById($sid)['project_ids']);
        $this->extra_sprint_ids[] = $sid; // no longer findable through the project

        $r = $this->callTool(new pmMcpUpdateSprintTool(), array('sprint_id' => $sid, 'all_projects' => false, 'project_ids' => array($this->project_id)));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertFalse($r['sprint']['all_projects']);
        $this->assertSame(array($this->project_id), (new pmSprintModel())->getById($sid)['project_ids']);
    }

    public function testUpdateAllProjectsFalseNeedsProjectIds(): void
    {
        $sid = $this->makeAllProjectsSprint();
        $r = $this->callTool(new pmMcpUpdateSprintTool(), array('sprint_id' => $sid, 'all_projects' => false));
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_param', $r['error_code']);
    }

    public function testNonAdminCannotSetAllProjects(): void
    {
        $other = $this->otherOrSkip();
        $sid = $this->makeSprint(array('name' => 'ZZ To all', 'status' => 'planned', 'duration' => 1));
        (new pmProjectUserModel())->add($this->project_id, $other, 'admin');

        $r = $this->asUser($other, function () use ($sid) {
            return $this->callTool(new pmMcpUpdateSprintTool(), array('sprint_id' => $sid, 'all_projects' => true));
        });
        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertSame('access_denied', $r['error_code']);
        $this->assertSame(array($this->project_id), (new pmSprintModel())->getById($sid)['project_ids']);
    }

    /** pm #595 rule 3: adding a project needs sprint.edit in it. */
    public function testNonAdminCannotAddProjectWithoutSprintEdit(): void
    {
        $other = $this->otherOrSkip();
        $pB = $this->extraProject();
        $sid = $this->makeSprint(array('name' => 'ZZ Add', 'status' => 'planned', 'duration' => 1));
        (new pmProjectUserModel())->add($this->project_id, $other, 'admin');
        (new pmProjectUserModel())->add($pB, $other, 'viewer');

        $r = $this->asUser($other, function () use ($sid, $pB) {
            return $this->callTool(new pmMcpUpdateSprintTool(), array('sprint_id' => $sid, 'project_ids' => array($this->project_id, $pB)));
        });
        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertSame('access_denied', $r['error_code']);
        $this->assertSame(array($this->project_id), (new pmSprintModel())->getById($sid)['project_ids']);
    }

    /** pm #595 rule 3: a project you cannot manage keeps its stored workflow selection. */
    public function testWorkflowSelectionOfUnmanagedProjectIsKept(): void
    {
        $other = $this->otherOrSkip();
        $pB = $this->extraProject();
        $wf = $this->workflowId();
        $sid = $this->makeSprint(
            array('name' => 'ZZ Wf lock', 'status' => 'planned', 'duration' => 1),
            array($this->project_id, $pB),
            array(array('project_id' => $pB, 'workflow_id' => $wf))
        );
        (new pmProjectUserModel())->add($this->project_id, $other, 'admin');
        (new pmProjectUserModel())->add($pB, $other, 'viewer');

        $r = $this->asUser($other, function () use ($sid, $pB) {
            return $this->callTool(new pmMcpUpdateSprintTool(), array(
                'sprint_id' => $sid,
                'workflows' => array(array('project_id' => $pB, 'workflow_ids' => array())),
            ));
        });
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('workflows_locked', $r['warnings'][0]['code'] ?? null, json_encode($r));
        $this->assertSame(array($pB), $r['warnings'][0]['project_ids']);
        $this->assertSame(array($wf), (new pmSprintModel())->getById($sid)['workflows'][$pB] ?? null);
    }

    // ── manage ──

    public function testNonAdminActivatesButCannotDeleteAllProjectsSprint(): void
    {
        $other = $this->otherOrSkip();
        $sid = $this->makeAllProjectsSprint();
        (new pmProjectUserModel())->add($this->project_id, $other, 'admin');

        $r = $this->asUser($other, function () use ($sid) {
            return $this->callTool(new pmMcpManageSprintTool(), array('sprint_id' => $sid, 'action' => 'activate'));
        });
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertTrue($r['sprint']['all_projects']);

        $r = $this->asUser($other, function () use ($sid) {
            return $this->callTool(new pmMcpManageSprintTool(), array('sprint_id' => $sid, 'action' => 'delete', 'confirm' => true));
        });
        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertSame('access_denied', $r['error_code']);
        $this->assertNotNull((new pmSprintModel())->getById($sid));
    }

    public function testAdminDeletesAllProjectsSprint(): void
    {
        $sid = $this->makeAllProjectsSprint();
        $r = $this->callTool(new pmMcpManageSprintTool(), array('sprint_id' => $sid, 'action' => 'delete', 'confirm' => true));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertTrue($r['all_projects']);
        $this->assertNull((new pmSprintModel())->getById($sid));
    }
}
