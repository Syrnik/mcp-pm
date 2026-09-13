<?php
/**
 * Integration tests for pm_manage_dependencies: a relation is stored once, both
 * tasks read it (with inverted wording), repeats are idempotent and a competing
 * relation is refused.
 *
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

class pmMcpDependencyToolTest extends pmMcpIntegrationTestCase
{
    private function makeTask(string $subject): int
    {
        $r = $this->callTool(new pmMcpCreateTaskTool(), array(
            'project_id' => $this->project_id,
            'subject'    => $subject,
            'type_slug'  => $this->defaultTypeSlug(),
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        return (int) $r['task_id'];
    }

    private function manage(array $args): array
    {
        return $this->callTool(new pmMcpManageDependenciesTool(), $args);
    }

    private function card(int $task_id): array
    {
        $r = $this->callTool(new pmMcpGetTaskTool(), array('task_id' => $task_id));
        $this->assertTrue($r['ok'], json_encode($r));
        return $r['task'];
    }

    /** Counterpart task ids listed in one section of a task card. */
    private function section(int $task_id, string $section): array
    {
        $entries = $this->card($task_id)['dependencies'][$section] ?? array();
        return array_map('intval', array_column($entries, 'related_task_id'));
    }

    private function rowCount(int $a, int $b): int
    {
        return count(pmMcpDependencyHelper::findBetween($a, $b));
    }

    public function testDependsOnIsVisibleFromBothSides(): void
    {
        $a = $this->makeTask('ZZ Dep A');
        $b = $this->makeTask('ZZ Dep B');

        $r = $this->manage(array(
            'task_id'         => $a,
            'action'          => 'add',
            'related_task_id' => $b,
            'relation'        => 'depends_on',
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('depends_on', $r['relation']['relation']);
        $this->assertSame('blocks', $r['relation']['inverse_relation']);
        $this->assertSame('FS', $r['relation']['type']);
        $this->assertSame($a, $r['relation']['stored_as']['task_id'], 'the dependent task carries the row');
        $this->assertSame($b, $r['relation']['stored_as']['depends_on_task_id']);

        // Both sides of the response already show the relation.
        $this->assertSame(array($b), array_map('intval', array_column($r['dependencies']['depends_on'], 'related_task_id')));
        $this->assertSame(array($a), array_map('intval', array_column($r['related_dependencies']['blocks'], 'related_task_id')));

        // And so do both task cards, from one stored row.
        $this->assertSame(array($b), $this->section($a, 'depends_on'));
        $this->assertSame(array($a), $this->section($b, 'blocks'));
        $this->assertSame(1, $this->rowCount($a, $b), 'a relation is stored exactly once');
    }

    public function testBlocksIsStoredOnTheBlockedTask(): void
    {
        $a = $this->makeTask('ZZ Blocker');
        $b = $this->makeTask('ZZ Blocked');

        $r = $this->manage(array(
            'task_id'         => $a,
            'action'          => 'add',
            'related_task_id' => $b,
            'relation'        => 'blocks',
            'type'            => 'SS',
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame($b, $r['relation']['stored_as']['task_id'], 'the blocked task carries the row');
        $this->assertSame('SS', $r['relation']['type']);

        $this->assertSame(array($b), $this->section($a, 'blocks'));
        $this->assertSame(array($a), $this->section($b, 'depends_on'));
    }

    public function testSymmetricRelationReadsTheSameFromBothEnds(): void
    {
        $a = $this->makeTask('ZZ Rel A');
        $b = $this->makeTask('ZZ Rel B');

        $r = $this->manage(array(
            'task_id'         => $a,
            'action'          => 'add',
            'related_task_id' => $b,
            'relation'        => 'relates_to',
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('RELATES_TO', $r['relation']['type']);
        $this->assertSame('relates_to', $r['relation']['inverse_relation']);

        $this->assertSame(array($b), $this->section($a, 'related'));
        $this->assertSame(array($a), $this->section($b, 'related'));

        // Asking for the same relation from the other end changes nothing.
        $again = $this->manage(array(
            'task_id'         => $b,
            'action'          => 'add',
            'related_task_id' => $a,
            'relation'        => 'relates_to',
        ));
        $this->assertTrue($again['ok'], json_encode($again));
        $this->assertTrue($again['already_exists']);
        $this->assertSame(1, $this->rowCount($a, $b));
    }

    public function testRepeatedAddIsIdempotent(): void
    {
        $a = $this->makeTask('ZZ Idem A');
        $b = $this->makeTask('ZZ Idem B');
        $args = array('task_id' => $a, 'action' => 'add', 'related_task_id' => $b, 'relation' => 'depends_on');

        $first = $this->manage($args);
        $this->assertTrue($first['ok'], json_encode($first));
        $this->assertArrayNotHasKey('already_exists', $first);

        $second = $this->manage($args);
        $this->assertTrue($second['ok'], json_encode($second));
        $this->assertTrue($second['already_exists']);
        $this->assertSame($first['relation']['dependency_id'], $second['relation']['dependency_id']);
        $this->assertSame(1, $this->rowCount($a, $b));
    }

    public function testCompetingRelationIsRefused(): void
    {
        $a = $this->makeTask('ZZ Conflict A');
        $b = $this->makeTask('ZZ Conflict B');

        $this->assertTrue($this->manage(array(
            'task_id' => $a, 'action' => 'add', 'related_task_id' => $b, 'relation' => 'depends_on',
        ))['ok']);

        // A counter-dependency would make the pair block itself.
        $reverse = $this->manage(array(
            'task_id' => $a, 'action' => 'add', 'related_task_id' => $b, 'relation' => 'blocks',
        ));
        $this->assertFalse($reverse['ok'], json_encode($reverse));
        $this->assertSame('conflict', $reverse['error_code']);
        $this->assertSame('depends_on', $reverse['existing_relation']['relation']);

        // So would a different type, or a different kind of relation.
        foreach (array(
            array('relation' => 'depends_on', 'type' => 'FF'),
            array('relation' => 'relates_to'),
        ) as $variant) {
            $r = $this->manage(array_merge(
                array('task_id' => $a, 'action' => 'add', 'related_task_id' => $b),
                $variant
            ));
            $this->assertFalse($r['ok'], json_encode($variant) . ': ' . json_encode($r));
            $this->assertSame('conflict', $r['error_code']);
        }

        $this->assertSame(1, $this->rowCount($a, $b));
    }

    public function testSelfRelationIsRejected(): void
    {
        $a = $this->makeTask('ZZ Self');
        $r = $this->manage(array(
            'task_id' => $a, 'action' => 'add', 'related_task_id' => $a, 'relation' => 'relates_to',
        ));
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_param', $r['error_code']);
    }

    public function testAddRequiresRelationAndRelatedTask(): void
    {
        $a = $this->makeTask('ZZ Missing args A');
        $b = $this->makeTask('ZZ Missing args B');

        $r = $this->manage(array('task_id' => $a, 'action' => 'add', 'related_task_id' => $b));
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_param', $r['error_code']);

        $r = $this->manage(array('task_id' => $a, 'action' => 'add', 'relation' => 'relates_to'));
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_param', $r['error_code']);
    }

    public function testRemoveByRelatedTaskClearsBothCards(): void
    {
        $a = $this->makeTask('ZZ Unlink A');
        $b = $this->makeTask('ZZ Unlink B');
        $this->assertTrue($this->manage(array(
            'task_id' => $a, 'action' => 'add', 'related_task_id' => $b, 'relation' => 'blocks',
        ))['ok']);

        $r = $this->manage(array('task_id' => $a, 'action' => 'remove', 'related_task_id' => $b));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertTrue($r['removed']);
        $this->assertSame('blocks', $r['removed_relation']['relation']);

        $this->assertSame(array(), $this->section($a, 'blocks'));
        $this->assertSame(array(), $this->section($b, 'depends_on'));
        $this->assertSame(0, $this->rowCount($a, $b));
    }

    public function testRemoveByDependencyIdAndForeignIdRejected(): void
    {
        $a = $this->makeTask('ZZ ById A');
        $b = $this->makeTask('ZZ ById B');
        $c = $this->makeTask('ZZ ById C');

        $ab = $this->manage(array(
            'task_id' => $a, 'action' => 'add', 'related_task_id' => $b, 'relation' => 'depends_on',
        ));
        $this->assertTrue($ab['ok'], json_encode($ab));
        $dependency_id = (int) $ab['relation']['dependency_id'];

        // c is not part of that relation, so it cannot delete it.
        $foreign = $this->manage(array(
            'task_id' => $c, 'action' => 'remove', 'dependency_id' => $dependency_id,
        ));
        $this->assertFalse($foreign['ok'], json_encode($foreign));
        $this->assertSame('not_found', $foreign['error_code']);
        $this->assertSame(1, $this->rowCount($a, $b));

        // From either end of the relation it works — here the blocking side.
        $r = $this->manage(array('task_id' => $b, 'action' => 'remove', 'dependency_id' => $dependency_id));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(0, $this->rowCount($a, $b));
    }

    public function testRemoveUnrelatedTasksReportsNotFound(): void
    {
        $a = $this->makeTask('ZZ Unrelated A');
        $b = $this->makeTask('ZZ Unrelated B');

        $r = $this->manage(array('task_id' => $a, 'action' => 'remove', 'related_task_id' => $b));
        $this->assertFalse($r['ok']);
        $this->assertSame('not_found', $r['error_code']);
    }

    public function testAcceptsFullTaskNumbers(): void
    {
        (new pmProjectModel())->updateById($this->project_id, array(
            'prefix'           => 'ZZDEP',
            'number_mode'      => 'prefix',
            'number_separator' => '-',
        ));
        pmMcpTaskHelper::resetNumberConfig();

        $a = $this->makeTask('ZZ Numbered A');
        $b = $this->makeTask('ZZ Numbered B');

        $r = $this->manage(array(
            'task_id'         => 'ZZDEP-' . $a,
            'action'          => 'add',
            'related_task_id' => 'zzdep' . $b,
            'relation'        => 'depends_on',
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('ZZDEP-' . $a, $r['relation']['task_full_number']);
        $this->assertSame('ZZDEP-' . $b, $r['relation']['related_full_number']);
        $this->assertSame('ZZDEP-' . $b, $this->card($a)['dependencies']['depends_on'][0]['related_full_number']);
    }

    public function testRelationsAcrossProjectsAreAllowed(): void
    {
        $a = $this->makeTask('ZZ Cross A');

        $other_project = $this->makeProject('ZZ MCP Cross ' . uniqid());
        $this->extra_project_ids[] = $other_project;
        $created = $this->callTool(new pmMcpCreateTaskTool(), array(
            'project_id' => $other_project,
            'subject'    => 'ZZ Cross B',
            'type_slug'  => $this->defaultTypeSlug($other_project),
        ));
        $this->assertTrue($created['ok'], json_encode($created));
        $b = (int) $created['task_id'];

        $r = $this->manage(array(
            'task_id' => $a, 'action' => 'add', 'related_task_id' => $b, 'relation' => 'relates_to',
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame($other_project, (int) $this->card($a)['dependencies']['related'][0]['related_project_id']);
    }
}
