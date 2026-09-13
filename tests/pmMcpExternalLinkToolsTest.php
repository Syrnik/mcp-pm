<?php
/**
 * Integration tests for pm_manage_external_links, pm_find_tasks_by_external
 * and the external_links surface on pm_get_task / pm_create_task.
 *
 * pm_task_external is generic across helpdesk/crm/shop (see
 * pmMcpExternalHelper's docblock); shop is not installed in this environment
 * (wa-config/apps.php has no "shop" entry), so its branch is exercised only
 * through the app-not-installed path (assertLinkable's not_found), never
 * against a real shop_order row.
 *
 * helpdesk_request and crm_deal have no enforced foreign keys on their
 * reference columns (workflow_id, source_id, funnel_id, stage_id, ...) — only
 * plain indexes — so a minimal row inserted directly through the model is a
 * valid target without needing a real workflow/funnel configured.
 *
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

class pmMcpExternalLinkToolsTest extends pmMcpIntegrationTestCase
{
    /** @var int[] helpdesk_request ids created by a test, cleaned up in tearDown */
    private $helpdesk_request_ids = array();

    /** @var int[] crm_deal ids created by a test, cleaned up in tearDown */
    private $crm_deal_ids = array();

    /**
     * helpdeskRequestModel/crmDealModel are only used here, to seed test rows
     * directly — pmMcpExternalHelper itself never instantiates them, it reads
     * their tables through pmTaskModel::query() precisely because those app
     * classes are not guaranteed to autoload without booting the app (see
     * pmMcpExternalHelper's class docblock on why it avoids wa($app, 1)).
     * wa($app) without the set_current flag only registers the app's classes
     * with the framework autoloader; it does not switch the current app away
     * from pm (tests/init.php relies on the same fact for 'mcp').
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (wa()->appExists('helpdesk')) {
            wa('helpdesk');
        }
        if (wa()->appExists('crm')) {
            wa('crm');
        }
    }

    protected function tearDown(): void
    {
        // pmMcpIntegrationTestCase::dropProject() deletes a test's tasks
        // through pmTaskModel::deleteById() directly, not pmTask::delete() —
        // the only path that cascades pm_task_external (pmTask.class.php:736).
        // Left alone, every test that links a task would leak a row here,
        // and a leaked row pointing at an id this tearDown is about to delete
        // from helpdesk_request/crm_deal would become exactly the stale-link
        // case testFindTasksByExternalCountsAStaleLinkSeparately tests for —
        // except unintentionally, and shared across every other test in the
        // suite. Purge by (app_id, external_id) rather than by task_id, since
        // it works whether or not the task itself still exists.
        $ext_model = new pmTaskExternalModel();
        foreach ($this->helpdesk_request_ids as $id) {
            $ext_model->deleteByField(array('app_id' => 'helpdesk', 'external_id' => (string) $id));
        }
        foreach ($this->crm_deal_ids as $id) {
            $ext_model->deleteByField(array('app_id' => 'crm', 'external_id' => (string) $id));
        }

        if ($this->helpdesk_request_ids && wa()->appExists('helpdesk')) {
            (new helpdeskRequestModel())->deleteByField('id', $this->helpdesk_request_ids);
        }
        if ($this->crm_deal_ids && wa()->appExists('crm')) {
            (new crmDealModel())->deleteByField('id', $this->crm_deal_ids);
        }
        $this->helpdesk_request_ids = array();
        $this->crm_deal_ids = array();
        parent::tearDown();
    }

    private function makeTask(string $subject = 'ZZ External', array $extra = array()): array
    {
        $r = $this->callTool(new pmMcpCreateTaskTool(), array_merge(array(
            'project_id' => $this->project_id,
            'subject'    => $subject,
            'type_slug'  => $this->defaultTypeSlug(),
        ), $extra));
        $this->assertTrue($r['ok'], json_encode($r));
        return $r;
    }

    /** Minimal, directly-inserted helpdesk_request row. Returns its id. */
    private function makeHelpdeskRequest(string $summary = 'ZZ Test request'): int
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) (new helpdeskRequestModel())->insert(array(
            'rating'              => 0,
            'workflow_id'         => 1,
            'updated'             => $now,
            'creator_contact_id'  => 1,
            'source_id'           => 1,
            'creator_type'        => 'contact',
            'created'             => $now,
            'state_id'            => 'new',
            'client_contact_id'   => 1,
            'assigned_contact_id' => 0,
            'summary'             => $summary,
            'text'                => 'ZZ seeded by pmMcpExternalLinkToolsTest',
            'last_log_id'         => 0,
        ));
        $this->helpdesk_request_ids[] = $id;
        return $id;
    }

    /** Minimal, directly-inserted crm_deal row. Returns its id. */
    private function makeCrmDeal(string $name = 'ZZ Test deal'): int
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) (new crmDealModel())->insert(array(
            'creator_contact_id' => 1,
            'create_datetime'    => $now,
            'update_datetime'    => $now,
            'funnel_id'          => 1,
            'stage_id'           => 1,
            'status_id'          => 'OPEN',
            'name'               => $name,
            'contact_id'         => 1,
        ));
        $this->crm_deal_ids[] = $id;
        return $id;
    }

    // ---- pm_manage_external_links: add ----

    public function testAddLinksTaskToHelpdeskRequest(): void
    {
        $request_id = $this->makeHelpdeskRequest('ZZ Request for add');
        $created = $this->makeTask('ZZ Add link');

        $r = $this->callTool(new pmMcpManageExternalLinksTool(), array(
            'task_id'     => $created['task_id'],
            'action'      => 'add',
            'app_id'      => 'helpdesk',
            'external_id' => (string) $request_id,
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertFalse($r['already_exists']);
        $this->assertCount(1, $r['external_links']);
        $link = $r['external_links'][0];
        $this->assertSame('helpdesk', $link['app_id']);
        $this->assertSame((string) $request_id, $link['external_id']);
        $this->assertTrue($link['exists']);
        $this->assertTrue($link['app_installed']);
        $this->assertSame('ZZ Request for add', $link['element_name']);
    }

    public function testAddIsIdempotent(): void
    {
        $request_id = $this->makeHelpdeskRequest();
        $created = $this->makeTask('ZZ Idempotent link');

        $args = array(
            'task_id'     => $created['task_id'],
            'action'      => 'add',
            'app_id'      => 'helpdesk',
            'external_id' => (string) $request_id,
        );
        $first = $this->callTool(new pmMcpManageExternalLinksTool(), $args);
        $this->assertTrue($first['ok'], json_encode($first));
        $this->assertFalse($first['already_exists']);

        $second = $this->callTool(new pmMcpManageExternalLinksTool(), $args);
        $this->assertTrue($second['ok'], json_encode($second));
        $this->assertTrue($second['already_exists'], 'a repeat add must not error, and must say it did nothing new');
        $this->assertCount(1, $second['external_links'], 'no duplicate row');
    }

    public function testAddRefusesNonexistentRequest(): void
    {
        $created = $this->makeTask('ZZ Bad link');

        $r = $this->callTool(new pmMcpManageExternalLinksTool(), array(
            'task_id'     => $created['task_id'],
            'action'      => 'add',
            'app_id'      => 'helpdesk',
            'external_id' => '999999999',
        ));
        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertSame('not_found', $r['error_code']);
        $this->assertSame(array(), (new pmTaskExternalModel())->getByTask((int) $created['task_id']));
    }

    public function testAddRefusesUninstalledApp(): void
    {
        $created = $this->makeTask('ZZ Shop link');

        $r = $this->callTool(new pmMcpManageExternalLinksTool(), array(
            'task_id'     => $created['task_id'],
            'action'      => 'add',
            'app_id'      => 'shop',
            'external_id' => '1',
        ));
        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertSame('not_found', $r['error_code'], 'shop is not installed in this environment');
    }

    public function testAddNormalizesLeadingZeros(): void
    {
        $request_id = $this->makeHelpdeskRequest();
        $created = $this->makeTask('ZZ Normalize');

        $r = $this->callTool(new pmMcpManageExternalLinksTool(), array(
            'task_id'     => $created['task_id'],
            'action'      => 'add',
            'app_id'      => 'helpdesk',
            'external_id' => '0' . $request_id,
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame((string) $request_id, $r['external_links'][0]['external_id'], 'stored id is normalised, not the padded form');
    }

    // ---- pm_manage_external_links: remove ----

    public function testRemoveDetachesTheLink(): void
    {
        $request_id = $this->makeHelpdeskRequest();
        $created = $this->makeTask('ZZ Remove link');
        $this->callTool(new pmMcpManageExternalLinksTool(), array(
            'task_id' => $created['task_id'], 'action' => 'add', 'app_id' => 'helpdesk', 'external_id' => (string) $request_id,
        ));

        $r = $this->callTool(new pmMcpManageExternalLinksTool(), array(
            'task_id'     => $created['task_id'],
            'action'      => 'remove',
            'app_id'      => 'helpdesk',
            'external_id' => (string) $request_id,
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertTrue($r['removed']);
        $this->assertSame(array(), $r['external_links']);
    }

    public function testRemoveNonexistentLinkIsNotFound(): void
    {
        $created = $this->makeTask('ZZ Remove missing');

        $r = $this->callTool(new pmMcpManageExternalLinksTool(), array(
            'task_id'     => $created['task_id'],
            'action'      => 'remove',
            'app_id'      => 'helpdesk',
            'external_id' => '123456',
        ));
        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertSame('not_found', $r['error_code']);
    }

    public function testRemoveFindsALegacyUnnormalisedRow(): void
    {
        $request_id = $this->makeHelpdeskRequest();
        $created = $this->makeTask('ZZ Legacy row');
        $task_id = (int) $created['task_id'];
        $padded = '0' . $request_id;

        // Write the row directly with a leading zero, bypassing the tool's own
        // normalisation — simulating a link the pm UI (or an older version of
        // this plugin) wrote before normalizeExternalId() existed. describeExternal()
        // echoes external_id as stored, so a card reading this link back would
        // show exactly $padded, not the canonical $request_id.
        (new pmTaskExternalModel())->add($task_id, 'helpdesk', $padded);

        // Remove with the padded id, matching what the card actually displays —
        // normalizeExternalId() strips the padding to $request_id, which does not
        // match the stored row; the tool must fall back to the caller's raw
        // string ($padded) rather than reporting not_found.
        $r = $this->callTool(new pmMcpManageExternalLinksTool(), array(
            'task_id'     => $task_id,
            'action'      => 'remove',
            'app_id'      => 'helpdesk',
            'external_id' => $padded,
        ));
        $this->assertTrue($r['ok'], 'a link visible on the card must be removable through the same tool: ' . json_encode($r));
        $this->assertTrue($r['removed']);
    }

    public function testAddRequiresTaskEditPermission(): void
    {
        $other = $this->otherContactId();
        if ($other === null) {
            $this->markTestSkipped('install has no second contact to test permission denial with');
        }

        $request_id = $this->makeHelpdeskRequest();
        $created = $this->makeTask('ZZ No permission');

        wa()->setUser(new waUser($other));
        try {
            $r = $this->callTool(new pmMcpManageExternalLinksTool(), array(
                'task_id'     => $created['task_id'],
                'action'      => 'add',
                'app_id'      => 'helpdesk',
                'external_id' => (string) $request_id,
            ));
        } finally {
            wa()->setUser(new waUser(1));
        }
        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertSame('access_denied', $r['error_code']);
    }

    // ---- pm_get_task: external_links on the card ----

    public function testGetTaskCardIncludesExternalLinks(): void
    {
        $request_id = $this->makeHelpdeskRequest('ZZ On the card');
        $created = $this->makeTask('ZZ Card link');
        $this->callTool(new pmMcpManageExternalLinksTool(), array(
            'task_id' => $created['task_id'], 'action' => 'add', 'app_id' => 'helpdesk', 'external_id' => (string) $request_id,
        ));

        $r = $this->callTool(new pmMcpGetTaskTool(), array('task_id' => $created['task_id']));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertArrayHasKey('external_links', $r['task']);
        $this->assertCount(1, $r['task']['external_links']);
        $this->assertSame('ZZ On the card', $r['task']['external_links'][0]['element_name']);
    }

    public function testGetTaskCardShowsADanglingLinkRatherThanHidingIt(): void
    {
        $request_id = $this->makeHelpdeskRequest('ZZ Will be deleted');
        $created = $this->makeTask('ZZ Dangling');
        $this->callTool(new pmMcpManageExternalLinksTool(), array(
            'task_id' => $created['task_id'], 'action' => 'add', 'app_id' => 'helpdesk', 'external_id' => (string) $request_id,
        ));

        // Delete the helpdesk request out from under the link, the way pm's
        // lack of a reverse cascade allows (pmTaskExternal.model.php has no
        // deleteByExternal()).
        (new helpdeskRequestModel())->deleteById($request_id);
        $this->helpdesk_request_ids = array_values(array_diff($this->helpdesk_request_ids, array($request_id)));

        $r = $this->callTool(new pmMcpGetTaskTool(), array('task_id' => $created['task_id']));
        $this->assertTrue($r['ok'], json_encode($r));
        $link = $r['task']['external_links'][0];
        $this->assertFalse($link['exists'], 'the row is gone, and the tool must say so');
        $this->assertNull($link['element_name']);
        $this->assertSame((string) $request_id, $link['external_id'], 'the link itself stays visible and removable');
    }

    // ---- pm_find_tasks_by_external ----

    public function testFindTasksByExternalReturnsLinkedTasks(): void
    {
        $request_id = $this->makeHelpdeskRequest('ZZ Findable request');
        $created = $this->makeTask('ZZ Findable task');
        $this->callTool(new pmMcpManageExternalLinksTool(), array(
            'task_id' => $created['task_id'], 'action' => 'add', 'app_id' => 'helpdesk', 'external_id' => (string) $request_id,
        ));

        $r = $this->callTool(new pmMcpFindTasksByExternalTool(), array(
            'app_id'      => 'helpdesk',
            'external_id' => (string) $request_id,
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(1, $r['count']);
        $this->assertSame(0, $r['hidden_count']);
        $this->assertSame(0, $r['stale_link_count']);
        $this->assertContains((int) $created['task_id'], array_column($r['tasks'], 'id'));
        $this->assertTrue($r['external']['exists']);
        $this->assertSame('ZZ Findable request', $r['external']['element_name'], 'the caller can see the linked task, so the record name is included');
    }

    public function testFindTasksByExternalCountsAStaleLinkSeparately(): void
    {
        $request_id = $this->makeHelpdeskRequest('ZZ Stale link');
        $created = $this->makeTask('ZZ Will be deleted raw');
        $task_id = (int) $created['task_id'];
        $this->callTool(new pmMcpManageExternalLinksTool(), array(
            'task_id' => $task_id, 'action' => 'add', 'app_id' => 'helpdesk', 'external_id' => (string) $request_id,
        ));

        // Delete the task directly through the model, bypassing pmTask::delete()
        // — the only path that cascades pm_task_external (pmTask.class.php:736).
        // This is exactly what pmMcpIntegrationTestCase::dropProject() itself
        // does when a test's own project is torn down, so it is not a
        // contrived case.
        (new pmTaskModel())->deleteById($task_id);

        $r = $this->callTool(new pmMcpFindTasksByExternalTool(), array(
            'app_id'      => 'helpdesk',
            'external_id' => (string) $request_id,
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(0, $r['count']);
        $this->assertSame(0, $r['hidden_count'], 'the task is gone, not merely inaccessible');
        $this->assertSame(1, $r['stale_link_count'], 'a link row survives with nothing left for it to point at — must not read as "never linked"');
    }

    public function testFindTasksByExternalHidesInaccessibleProjects(): void
    {
        $request_id = $this->makeHelpdeskRequest('ZZ Cross project');
        $created = $this->makeTask('ZZ In accessible project');
        $this->callTool(new pmMcpManageExternalLinksTool(), array(
            'task_id' => $created['task_id'], 'action' => 'add', 'app_id' => 'helpdesk', 'external_id' => (string) $request_id,
        ));

        $other = $this->otherContactId();
        if ($other === null) {
            $this->markTestSkipped('install has no second contact to test project visibility with');
        }
        // A second task, in a project the admin is a member of but that we then
        // read as a non-member/non-admin contact — the case hidden_count exists
        // to report. We approximate this by reading as a contact that is a
        // helpdesk/pm user but not a project member, if isAdmin('pm') is false
        // for them; skip if that assumption does not hold on this install.
        wa()->setUser(new waUser($other));
        $is_admin = wa()->getUser()->isAdmin('pm');
        wa()->setUser(new waUser(1));
        if ($is_admin) {
            $this->markTestSkipped('the only other contact on this install is a pm app-admin, which always sees every project');
        }

        wa()->setUser(new waUser($other));
        try {
            $r = $this->callTool(new pmMcpFindTasksByExternalTool(), array(
                'app_id'      => 'helpdesk',
                'external_id' => (string) $request_id,
            ));
        } finally {
            wa()->setUser(new waUser(1));
        }
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(0, $r['count'], 'the non-member must not see the task');
        $this->assertSame(1, $r['hidden_count'], 'but the tool says a link exists it cannot show');
        $this->assertNull(
            $r['external']['element_name'],
            'the request summary must not leak to a caller who cannot see any task actually linked to it'
        );
    }

    public function testFindTasksByExternalOnUnlinkedRecordReturnsEmpty(): void
    {
        $request_id = $this->makeHelpdeskRequest('ZZ Never linked');

        $r = $this->callTool(new pmMcpFindTasksByExternalTool(), array(
            'app_id'      => 'helpdesk',
            'external_id' => (string) $request_id,
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(0, $r['count']);
        $this->assertSame(array(), $r['tasks']);
        $this->assertTrue($r['external']['exists'], 'the record itself is real, it just has no linked tasks');
        $this->assertNull($r['external']['element_name'], 'no visible link means no name either, same rule as the hidden-project case');
    }

    // ---- pm_create_task: external_links ----

    public function testCreateTaskWithExternalLinks(): void
    {
        $request_id = $this->makeHelpdeskRequest('ZZ Seeded from helpdesk');

        $created = $this->makeTask('ZZ Created with link', array(
            'external_links' => array(
                array('app_id' => 'helpdesk', 'external_id' => (string) $request_id),
            ),
        ));
        $this->assertCount(1, $created['task']['external_links']);
        $this->assertSame('helpdesk', $created['task']['external_links'][0]['app_id']);
        $this->assertSame((string) $request_id, $created['task']['external_links'][0]['external_id']);
    }

    public function testCreateTaskRefusesUnknownLinkAndCreatesNothing(): void
    {
        $r = $this->callTool(new pmMcpCreateTaskTool(), array(
            'project_id'     => $this->project_id,
            'subject'        => 'ZZ Should not exist',
            'type_slug'      => $this->defaultTypeSlug(),
            'external_links' => array(
                array('app_id' => 'helpdesk', 'external_id' => '999999999'),
            ),
        ));
        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertSame('not_found', $r['error_code']);
        $this->assertSame(array(), (new pmTaskModel())->getByField('project_id', $this->project_id, true), 'a rejected link must not leave a task behind');
    }

    public function testCreateTaskWithCrmDealLink(): void
    {
        $deal_id = $this->makeCrmDeal('ZZ Deal for task');

        $created = $this->makeTask('ZZ With crm link', array(
            'external_links' => array(
                array('app_id' => 'crm', 'external_id' => (string) $deal_id),
            ),
        ));
        $this->assertCount(1, $created['task']['external_links']);
        $link = $created['task']['external_links'][0];
        $this->assertSame('crm', $link['app_id']);
        $this->assertSame('ZZ Deal for task', $link['element_name']);
    }
}
