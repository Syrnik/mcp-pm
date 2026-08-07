<?php
/**
 * Base class for the pm MCP integration tests: they exercise the tools against
 * the live database, so each test runs as the install admin (contact 1) inside
 * a throwaway project that is torn down afterwards.
 *
 * Abstract, so PHPUnit does not treat it as a test case of its own.
 *
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

abstract class pmMcpIntegrationTestCase extends TestCase
{
    /** @var int */
    protected $project_id = 0;

    /** @var int[] extra project ids created by a test, cleaned up in tearDown */
    protected $extra_project_ids = array();

    protected function setUp(): void
    {
        parent::setUp();
        wa()->setUser(new waUser(1));
        $this->project_id = $this->makeProject('ZZ MCP Test ' . uniqid());
    }

    protected function tearDown(): void
    {
        foreach (array_merge(array($this->project_id), $this->extra_project_ids) as $pid) {
            $this->dropProject((int) $pid);
        }
        $this->project_id = 0;
        $this->extra_project_ids = array();
        parent::tearDown();
    }

    /**
     * Create a project owned by, and with, the current admin, linked to the
     * first configured workflow. Returns its id.
     */
    protected function makeProject($name, $owner_contact_id = 1)
    {
        $model = new pmProjectModel();
        $id = (int) $model->insert(array(
            'name'             => $name,
            'owner_contact_id' => $owner_contact_id,
            'content_format'   => 'md',
            'create_datetime'  => date('Y-m-d H:i:s'),
        ));
        $workflows = pmWorkflow::getWorkflows();
        $model->saveWorkflows($id, $workflows ? array((string) array_key_first($workflows)) : array());
        (new pmProjectUserModel())->add($id, $owner_contact_id, 'admin');
        return $id;
    }

    /** Remove a project and everything the tests attach to it. */
    protected function dropProject($pid)
    {
        if ($pid <= 0) {
            return;
        }
        $task_model = new pmTaskModel();
        $dependency_model = new pmTaskDependencyModel();
        $task_tag_model = new pmTaskTagModel();
        foreach ($task_model->getByField('project_id', $pid, true) as $t) {
            // Relations and tag links live in their own tables and survive a raw
            // task delete, so clear them before the task goes.
            $dependency_model->deleteByField('task_id', $t['id']);
            $dependency_model->deleteByField('depends_on_task_id', $t['id']);
            $task_tag_model->deleteByTask($t['id']);
            $task_model->deleteById($t['id']);
        }
        (new pmTagModel())->deleteByField('project_id', $pid);
        (new pmWikiPageModel())->deleteByField('project_id', $pid);
        foreach ((new pmSprintModel())->getByProject($pid) as $s) {
            (new pmSprintProjectModel())->deleteByField('sprint_id', $s['id']);
            (new pmSprintFillStatusModel())->deleteByField('sprint_id', $s['id']);
            (new pmSprintWorkflowModel())->deleteByField('sprint_id', $s['id']);
            (new pmSprintModel())->deleteById($s['id']);
        }
        (new pmMilestoneModel())->deleteByField('project_id', $pid);
        (new pmActivityLogModel())->deleteByField('project_id', $pid);
        (new pmProjectUserModel())->deleteByField('project_id', $pid);
        (new pmProjectWorkflowModel())->deleteByField('project_id', $pid);
        (new pmProjectModel())->deleteById($pid);
    }

    /** The workflow slug attached to the test project. */
    protected function workflowId()
    {
        $wfs = (new pmProjectModel())->getWorkflows($this->project_id);
        return $wfs ? reset($wfs) : null;
    }

    /**
     * Create a sprint directly through the model and attach it to the given
     * projects (plus, optionally, per-project workflow selections and
     * auto-fill statuses) the same way pmSprint::save() would. Torn down by
     * dropProject() through whichever project cleans up first.
     *
     * @param array $data            pm_sprint columns (name/status/start_date/...).
     * @param int[] $project_ids     Projects to link. Defaults to $this->project_id.
     * @param array $workflow_items  Flat list of ['project_id'=>int,'workflow_id'=>string].
     * @param int[] $fill_status_ids
     * @return int Sprint id.
     */
    protected function makeSprint(array $data, array $project_ids = array(), array $workflow_items = array(), array $fill_status_ids = array())
    {
        if (!$project_ids) {
            $project_ids = array($this->project_id);
        }
        $model = new pmSprintModel();
        $id = (int) $model->insert($data);
        $model->saveProjects($id, $project_ids);
        if ($workflow_items) {
            $model->saveWorkflows($id, $workflow_items);
        }
        if ($fill_status_ids) {
            $model->saveFillStatuses($id, $fill_status_ids);
        }
        return $id;
    }

    /** Invoke a tool and return its decoded array response. */
    protected function callTool(mcpTool $tool, array $args)
    {
        return $tool->execute($args, wa());
    }

    /** A second contact id (not the admin), or null when the install has none. */
    protected function otherContactId()
    {
        $id = (new waModel())->query("SELECT id FROM wa_contact WHERE id <> 1 ORDER BY id LIMIT 1")->fetchField('id');
        return $id ? (int) $id : null;
    }
}
