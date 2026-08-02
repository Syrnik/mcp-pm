<?php
/**
 * Integration tests for task tagging: the `tags` field of pm_create_task and
 * pm_update_task, and the pm_add_tags / pm_remove_tags pair.
 *
 * Covers what the schemas cannot state on their own — that an unknown name is
 * refused unless creation is asked for, that update replaces the whole set
 * while add/remove do not, that matching ignores case, and that a freshly
 * written tag is immediately visible to pm_get_task and pm_list_tasks.
 *
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

class pmMcpTagToolsTest extends pmMcpIntegrationTestCase
{
    private function makeTask(string $subject = 'ZZ Tagged', array $extra = array()): array
    {
        $r = $this->callTool(new pmMcpCreateTaskTool(), array_merge(array(
            'project_id' => $this->project_id,
            'subject'    => $subject,
        ), $extra));
        $this->assertTrue($r['ok'], json_encode($r));
        return $r;
    }

    /** Create a tag in the test project directly, bypassing the tools. */
    private function makeTag(string $name): int
    {
        return (int) (new pmTagModel())->add($this->project_id, $name, '#123456');
    }

    /** Tag names on a task, as pm_get_task reports them. */
    private function tagNames($task_id): array
    {
        $r = $this->callTool(new pmMcpGetTaskTool(), array('task_id' => $task_id));
        $this->assertTrue($r['ok'], json_encode($r));
        return array_column($r['task']['tags'], 'name');
    }

    public function testCreateTaskRefusesUnknownTagAndCreatesNothing(): void
    {
        $r = $this->callTool(new pmMcpCreateTaskTool(), array(
            'project_id' => $this->project_id,
            'subject'    => 'ZZ Should not exist',
            'tags'       => array('ZZ nonexistent'),
        ));

        $this->assertFalse($r['ok'], 'an unknown tag name must be refused by default');
        $this->assertSame('invalid_param', $r['error_code']);
        $this->assertSame(array('ZZ nonexistent'), $r['missing_tags']);
        $this->assertSame(array(), $r['available_tags'], 'a project with no tags says so with an empty list');
        $this->assertStringContainsString('create_missing_tags', $r['error_message']);

        // The rejection happens before pmTask::create(), so no half-made task
        // and no stray tag are left behind.
        $this->assertSame(array(), (new pmTaskModel())->getByField('project_id', $this->project_id, true));
        $this->assertSame(array(), (new pmTagModel())->getByProject($this->project_id));
    }

    public function testCreateTaskWithExistingTag(): void
    {
        $this->makeTag('backend');

        $created = $this->makeTask('ZZ With tag', array('tags' => array('backend')));
        $this->assertSame(array('backend'), array_column($created['task']['tags'], 'name'));
    }

    public function testCreateTaskCreatesMissingTagWhenAllowed(): void
    {
        $created = $this->makeTask('ZZ Creates tag', array(
            'tags'                => array('Webasyst Framework'),
            'create_missing_tags' => true,
        ));

        $this->assertSame(array('Webasyst Framework'), array_column($created['task']['tags'], 'name'));

        // The tag is a real project tag afterwards, not just a link.
        $listed = $this->callTool(new pmMcpListTagsTool(), array('project_id' => $this->project_id));
        $this->assertTrue($listed['ok'], json_encode($listed));
        $this->assertSame(array('Webasyst Framework'), array_column($listed['tags'], 'name'));
        $this->assertSame(pmMcpTagHelper::DEFAULT_COLOR, $listed['tags'][0]['color']);
    }

    public function testAddTagsKeepsExistingOnesAndMatchesCaseInsensitively(): void
    {
        $this->makeTag('bug');
        $this->makeTag('urgent');
        $created = $this->makeTask('ZZ Add tags', array('tags' => array('bug')));

        $r = $this->callTool(new pmMcpAddTagsTool(), array(
            'task_id' => $created['task_id'],
            'tags'    => array('URGENT'),
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(array('urgent'), $r['added'], 'the response names the tag as pm stores it');

        $names = array_column($r['tags'], 'name');
        sort($names);
        $this->assertSame(array('bug', 'urgent'), $names, 'adding must not drop the tag already there');
    }

    public function testAddTagsIsIdempotent(): void
    {
        $this->makeTag('bug');
        $created = $this->makeTask('ZZ Idempotent', array('tags' => array('bug')));

        $r = $this->callTool(new pmMcpAddTagsTool(), array(
            'task_id' => $created['task_id'],
            'tags'    => array('bug'),
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(array(), $r['added'], 'a tag already on the task is not added again');
        $this->assertSame(array('bug'), array_column($r['tags'], 'name'));

        $entries = array_filter(
            (new pmActivityLogModel())->getByTask((int) $created['task_id']),
            static function ($e) {
                return $e['action'] === 'tag_added';
            }
        );
        $this->assertCount(1, $entries, 'the repeat must not log a second tag_added');
    }

    public function testAddTagsRefusesUnknownNameWithTheProjectsOwnTags(): void
    {
        $this->makeTag('bug');
        $created = $this->makeTask('ZZ Refuses');

        $r = $this->callTool(new pmMcpAddTagsTool(), array(
            'task_id' => $created['task_id'],
            'tags'    => array('buggg'),
        ));
        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertSame('invalid_param', $r['error_code']);
        $this->assertSame(array('buggg'), $r['missing_tags']);
        $this->assertSame(array('bug'), array_column($r['available_tags'], 'name'), 'the error carries the real tags');
        $this->assertSame(array(), $this->tagNames($created['task_id']), 'nothing is attached on a refusal');
    }

    public function testAddTagsCreatesMissingTagWhenAllowedAndLogsIt(): void
    {
        $created = $this->makeTask('ZZ Creates on add');

        $r = $this->callTool(new pmMcpAddTagsTool(), array(
            'task_id'             => $created['task_id'],
            'tags'                => array('docs'),
            'create_missing_tags' => true,
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(array('docs'), $r['added']);

        $entries = array_values(array_filter(
            (new pmActivityLogModel())->getByTask((int) $created['task_id']),
            static function ($e) {
                return $e['action'] === 'tag_added';
            }
        ));
        $this->assertCount(1, $entries);
        $params = json_decode((string) $entries[0]['params'], true);
        $this->assertSame('docs', $params['tag_name'], 'the history entry carries the tag name the UI renders');
    }

    public function testUpdateTaskReplacesTheWholeTagSet(): void
    {
        $this->makeTag('bug');
        $this->makeTag('urgent');
        $created = $this->makeTask('ZZ Replace', array('tags' => array('bug')));

        $r = $this->callTool(new pmMcpUpdateTaskTool(), array(
            'task_id' => $created['task_id'],
            'tags'    => array('urgent'),
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(array('urgent'), array_column($r['task']['tags'], 'name'), 'tags not listed are detached');
    }

    public function testUpdateTaskClearsTagsWithAnEmptyArray(): void
    {
        $this->makeTag('bug');
        $created = $this->makeTask('ZZ Clear', array('tags' => array('bug')));

        $r = $this->callTool(new pmMcpUpdateTaskTool(), array(
            'task_id' => $created['task_id'],
            'tags'    => array(),
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(array(), $r['task']['tags']);

        // Clearing unlinks; the tag itself stays in the project.
        $this->assertSame(array('bug'), array_column((new pmTagModel())->getByProject($this->project_id), 'name'));
    }

    public function testUpdateTaskAcceptsTagsAsTheOnlyField(): void
    {
        $this->makeTag('bug');
        $created = $this->makeTask('ZZ Tags only');

        $r = $this->callTool(new pmMcpUpdateTaskTool(), array(
            'task_id' => $created['task_id'],
            'tags'    => array('bug'),
        ));
        $this->assertTrue($r['ok'], 'a tags-only update is not "no fields to update": ' . json_encode($r));
        $this->assertSame(array('bug'), array_column($r['task']['tags'], 'name'));
        $this->assertSame('ZZ Tags only', $r['task']['subject'], 'nothing else changed');
    }

    public function testUpdateTaskRefusesUnknownTagBeforeSavingTheOtherFields(): void
    {
        $created = $this->makeTask('ZZ Untouched');

        $r = $this->callTool(new pmMcpUpdateTaskTool(), array(
            'task_id' => $created['task_id'],
            'subject' => 'ZZ Renamed',
            'tags'    => array('ZZ nonexistent'),
        ));
        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertSame('invalid_param', $r['error_code']);

        $get = $this->callTool(new pmMcpGetTaskTool(), array('task_id' => $created['task_id']));
        $this->assertSame('ZZ Untouched', $get['task']['subject'], 'the rejected tag must not leave the rename applied');
    }

    public function testRemoveTagsDetachesWithoutDeletingTheTag(): void
    {
        $this->makeTag('bug');
        $this->makeTag('urgent');
        $created = $this->makeTask('ZZ Remove', array('tags' => array('bug', 'urgent')));

        $r = $this->callTool(new pmMcpRemoveTagsTool(), array(
            'task_id' => $created['task_id'],
            'tags'    => array('BUG'),
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(array('bug'), $r['removed']);
        $this->assertSame(array('urgent'), array_column($r['tags'], 'name'), 'the other tag stays on the task');

        $project_tags = array_column((new pmTagModel())->getByProject($this->project_id), 'name');
        sort($project_tags);
        $this->assertSame(array('bug', 'urgent'), $project_tags, 'the tag itself survives in the project');
    }

    public function testRemoveTagsReportsWhatWasNotThere(): void
    {
        $this->makeTag('bug');
        $created = $this->makeTask('ZZ Not there', array('tags' => array('bug')));

        $r = $this->callTool(new pmMcpRemoveTagsTool(), array(
            'task_id' => $created['task_id'],
            'tags'    => array('bug', 'never-existed'),
        ));
        $this->assertTrue($r['ok'], 'removing a tag the task lacks is not an error: ' . json_encode($r));
        $this->assertSame(array('bug'), $r['removed']);
        $this->assertSame(array('never-existed'), $r['not_on_task']);
        $this->assertSame(array(), $r['tags']);
    }

    public function testFreshlyTaggedTaskIsFoundByTagFilter(): void
    {
        $tag_id = $this->makeTag('findable');
        $created = $this->makeTask('ZZ Findable');

        $r = $this->callTool(new pmMcpAddTagsTool(), array(
            'task_id' => $created['task_id'],
            'tags'    => array('findable'),
        ));
        $this->assertTrue($r['ok'], json_encode($r));

        $list = $this->callTool(new pmMcpListTasksTool(), array(
            'project_id' => $this->project_id,
            'tag_id'     => $tag_id,
        ));
        $this->assertTrue($list['ok'], json_encode($list));
        $this->assertContains((int) $created['task_id'], array_column($list['tasks'], 'id'));
    }

    public function testTagNamesAreTrimmedAndDeduplicated(): void
    {
        $created = $this->makeTask('ZZ Dedup');

        $r = $this->callTool(new pmMcpAddTagsTool(), array(
            'task_id'             => $created['task_id'],
            'tags'                => array('  spaced  ', 'spaced', 'SPACED', ''),
            'create_missing_tags' => true,
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(array('spaced'), $r['added'], 'one tag, whatever the spelling and spacing');
        $this->assertCount(1, (new pmTagModel())->getByProject($this->project_id));
    }

    public function testTooLongTagNameIsRefused(): void
    {
        $created = $this->makeTask('ZZ Too long');

        $r = $this->callTool(new pmMcpAddTagsTool(), array(
            'task_id'             => $created['task_id'],
            'tags'                => array(str_repeat('a', pmMcpTagHelper::NAME_MAX_LENGTH + 1)),
            'create_missing_tags' => true,
        ));
        $this->assertFalse($r['ok'], 'a name longer than the column must not be silently truncated');
        $this->assertSame('invalid_param', $r['error_code']);
        $this->assertSame(array(), (new pmTagModel())->getByProject($this->project_id));
    }
}
