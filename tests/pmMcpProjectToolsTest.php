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

    /**
     * PMCP-693: workflows carry the accepted task types (REST
     * pm.workflow.get shape) and the project's default_type_slug.
     */
    public function testGetWorkflowExposesTypesAndDefault(): void
    {
        $wf_id = $this->workflowId();
        $r = $this->callTool(new pmMcpGetWorkflowTool(), array('project_id' => $this->project_id));
        $this->assertTrue($r['ok'], json_encode($r));
        $wf = $r['workflows'][0];
        $this->assertSame($wf_id, $wf['id']);
        $this->assertSame($this->workflowTypeSlugs(), array_column($wf['types'], 'slug'));
        $this->assertSame(array('slug', 'name', 'color', 'icon'), array_keys($wf['types'][0]));
        $this->assertArrayHasKey('default_type_slug', $wf);
        $this->assertNull($wf['default_type_slug']);

        $default = $this->workflowTypeSlugs()[1];
        $this->setDefaultType($wf_id, $default);
        $r = $this->callTool(new pmMcpGetProjectTool(), array('project_id' => $this->project_id));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame($default, $r['workflows'][0]['default_type_slug']);

        // A stale default (type outside the workflow's group) reads as null.
        $this->setDefaultType($wf_id, 'org_task');
        $r = $this->callTool(new pmMcpGetWorkflowTool(), array('project_id' => $this->project_id));
        $this->assertNull($r['workflows'][0]['default_type_slug']);
    }

    /** pm_list_statuses(workflow_id) has types, but no project → no default. */
    public function testListStatusesWorkflowHasTypesWithoutDefault(): void
    {
        $r = $this->callTool(new pmMcpListStatusesTool(), array('workflow_id' => $this->workflowId()));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame($this->workflowTypeSlugs(), array_column($r['workflow']['types'], 'slug'));
        $this->assertArrayNotHasKey('default_type_slug', $r['workflow']);
    }

    /**
     * pm_update_project with workflow_ids used to re-save the workflow set as
     * a plain list, and pm's model re-inserts it with default_type_slug =
     * null — silently wiping the defaults set in the project settings.
     */
    public function testUpdateProjectWorkflowsKeepsDefaultType(): void
    {
        $wf_id = $this->workflowId();
        $default = $this->workflowTypeSlugs()[1];
        $this->setDefaultType($wf_id, $default);

        $r = $this->callTool(new pmMcpUpdateProjectTool(), array(
            'project_id'   => $this->project_id,
            'workflow_ids' => array($wf_id),
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame($default, $r['workflows'][0]['default_type_slug']);
        $stored = (new pmProjectWorkflowModel())->getWorkflowsWithTypes($this->project_id);
        $this->assertSame($default, $stored[$wf_id]);
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
