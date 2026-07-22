<?php
/**
 * Integration tests for the Stage 4 project-write tools against the live DB.
 *
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

class pmMcpProjectToolsTest extends pmMcpIntegrationTestCase
{
    public function testCreateProjectAddsOwnerAsAdmin(): void
    {
        $r = $this->callTool(new pmMcpCreateProjectTool(), array(
            'name'        => 'ZZ Created ' . uniqid(),
            'description' => 'made by test',
            'status'      => 'active',
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->extra_project_ids[] = $r['project_id'];

        $this->assertSame('active', $r['project']['status']);
        $this->assertSame(1, $r['project']['owner_contact_id']);
        $roles = array_column($r['participants'], 'role', 'contact_id');
        $this->assertSame('admin', $roles[1] ?? null, 'owner must be a project admin');
        $this->assertNotEmpty($r['workflows'], 'a workflow must be attached by default');
    }

    public function testUpdateProjectPartialFields(): void
    {
        $r = $this->callTool(new pmMcpUpdateProjectTool(), array(
            'project_id' => $this->project_id,
            'name'       => 'ZZ Renamed',
            'status'     => 'completed',
            'color'      => '#ff0000',
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('ZZ Renamed', $r['project']['name']);
        $this->assertSame('completed', $r['project']['status']);
        $this->assertSame('#ff0000', $r['project']['color']);
    }

    public function testUpdateProjectRejectsUnknownWorkflow(): void
    {
        $r = $this->callTool(new pmMcpUpdateProjectTool(), array(
            'project_id'   => $this->project_id,
            'workflow_ids' => array('does_not_exist'),
        ));
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_param', $r['error_code']);
        $this->assertArrayHasKey('available_workflows', $r);
    }

    public function testUpdateProjectRejectsEmptyPayload(): void
    {
        $r = $this->callTool(new pmMcpUpdateProjectTool(), array('project_id' => $this->project_id));
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_param', $r['error_code']);
    }

    public function testAddProjectUserIsUpsert(): void
    {
        $other = $this->otherContactId();
        if ($other === null) {
            $this->markTestSkipped('installation has no second contact');
        }

        $add = new pmMcpAddProjectUserTool();

        $r = $this->callTool($add, array('project_id' => $this->project_id, 'contact_id' => $other, 'role' => 'member'));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('added', $r['action']);
        $this->assertSame('member', $r['role']);

        $r = $this->callTool($add, array('project_id' => $this->project_id, 'contact_id' => $other, 'role' => 'manager'));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('updated', $r['action']);
        $this->assertSame('manager', $r['role']);
    }

    public function testAddProjectUserRejectsUnknownRole(): void
    {
        $other = $this->otherContactId();
        if ($other === null) {
            $this->markTestSkipped('installation has no second contact');
        }
        $r = $this->callTool(new pmMcpAddProjectUserTool(), array(
            'project_id' => $this->project_id,
            'contact_id' => $other,
            'role'       => 'wizard',
        ));
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_param', $r['error_code']);
    }

    public function testRemoveProjectUserRequiresConfirm(): void
    {
        $other = $this->otherContactId();
        if ($other === null) {
            $this->markTestSkipped('installation has no second contact');
        }
        $this->callTool(new pmMcpAddProjectUserTool(), array('project_id' => $this->project_id, 'contact_id' => $other, 'role' => 'member'));

        $r = $this->callTool(new pmMcpRemoveProjectUserTool(), array('project_id' => $this->project_id, 'contact_id' => $other));
        $this->assertFalse($r['ok']);
        $this->assertSame('confirm_required', $r['error_code']);
    }

    public function testCannotRemoveOwner(): void
    {
        $r = $this->callTool(new pmMcpRemoveProjectUserTool(), array(
            'project_id' => $this->project_id,
            'contact_id' => 1,
            'confirm'    => true,
        ));
        $this->assertFalse($r['ok']);
        $this->assertSame('conflict', $r['error_code']);
    }

    public function testRemoveProjectUserConfirmed(): void
    {
        $other = $this->otherContactId();
        if ($other === null) {
            $this->markTestSkipped('installation has no second contact');
        }
        $this->callTool(new pmMcpAddProjectUserTool(), array('project_id' => $this->project_id, 'contact_id' => $other, 'role' => 'member'));

        $r = $this->callTool(new pmMcpRemoveProjectUserTool(), array(
            'project_id' => $this->project_id,
            'contact_id' => $other,
            'confirm'    => true,
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertTrue($r['removed']);
        $remaining = array_column($r['participants'], 'contact_id');
        $this->assertNotContains($other, $remaining);
    }
}
