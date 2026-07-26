<?php
/**
 * Unit tests for the DB-independent building blocks: schema validation, the
 * argument coercers on the tool base, and the pure helper logic (wiki access
 * roles and per-page visibility).
 *
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Concrete probe exposing the protected coercers of pmMcpToolBase.
 */
class pmMcpToolProbe extends pmMcpToolBase
{
    public function getName()        { return 'pm_probe'; }
    public function getRight()       { return 'pm_probe'; }
    public function getDescription() { return 'probe'; }
    public function getInputSchema() { return array('type' => 'object', 'properties' => array()); }
    public function execute(array $arguments, waSystem $system) { return $this->ok(array()); }

    public function boolArg(array $a, $k, $d = false) { return $this->argBool($a, $k, $d); }
    public function intArg(array $a, $k, $d = 0)       { return $this->argInt($a, $k, $d); }
    public function strArg(array $a, $k, $d = '')      { return $this->argString($a, $k, $d); }
}

class pmMcpSchemaTest extends TestCase
{
    private function probe(): pmMcpToolProbe
    {
        return new pmMcpToolProbe();
    }

    // ---- argBool ----

    public function testArgBoolTrueForms(): void
    {
        $p = $this->probe();
        $this->assertTrue($p->boolArg(array('x' => true), 'x'));
        $this->assertTrue($p->boolArg(array('x' => 'true'), 'x'));
        $this->assertTrue($p->boolArg(array('x' => '1'), 'x'));
        $this->assertTrue($p->boolArg(array('x' => 1), 'x'));
    }

    public function testArgBoolFalseAndDefault(): void
    {
        $p = $this->probe();
        $this->assertFalse($p->boolArg(array('x' => false), 'x'));
        $this->assertFalse($p->boolArg(array('x' => '0'), 'x'));
        $this->assertFalse($p->boolArg(array('x' => 'false'), 'x'));
        $this->assertFalse($p->boolArg(array(), 'missing'));
        $this->assertTrue($p->boolArg(array(), 'missing', true), 'absent key falls back to default');
        $this->assertTrue($p->boolArg(array('x' => ''), 'x', true), 'empty string falls back to default');
    }

    // ---- argInt / argString ----

    public function testIntAndStringCoercion(): void
    {
        $p = $this->probe();
        $this->assertSame(5, $p->intArg(array('n' => '5'), 'n'));
        $this->assertSame(0, $p->intArg(array(), 'n'));
        $this->assertSame(7, $p->intArg(array(), 'n', 7));
        $this->assertSame('hi', $p->strArg(array('s' => '  hi  '), 's'));
        $this->assertSame('', $p->strArg(array(), 's'));
    }

    // ---- flatValidation ----

    public function testFlatValidationFlattensNestedMessages(): void
    {
        $flat = pmMcpToolBase::flatValidation(array(
            'plain error',
            array('name' => 'is required'),
            array('tags' => array('too many', 'bad tag')),
        ));
        $this->assertStringContainsString('plain error', $flat);
        $this->assertStringContainsString('name: is required', $flat);
        $this->assertStringContainsString('tags: too many', $flat);
        $this->assertStringContainsString('tags: bad tag', $flat);
    }

    // ---- schema validate() ----

    public function testValidateReportsMissingRequired(): void
    {
        $errors = (new pmMcpGetTaskTool())->validate(array());
        $this->assertNotEmpty($errors, 'missing task_id must be reported');
    }

    public function testValidateAcceptsValidArguments(): void
    {
        $errors = (new pmMcpGetTaskTool())->validate(array('task_id' => 5));
        $this->assertSame(array(), $errors);
    }

    public function testValidateRejectsWrongEnum(): void
    {
        // pm_create_project has status enum planned/active/completed.
        $errors = (new pmMcpCreateProjectTool())->validate(array('name' => 'X', 'status' => 'bogus'));
        $this->assertNotEmpty($errors, 'invalid status enum must be reported');
    }

    public function testValidateCoercesNumericTaskReference(): void
    {
        // task_id is declared as a string so "AUTH-32" validates; an integer id
        // from an older client must still be accepted.
        $this->assertSame(array(), (new pmMcpGetTaskTool())->validate(array('task_id' => 32)));
        $this->assertSame(array(), (new pmMcpGetTaskTool())->validate(array('task_id' => 'AUTH-32')));
    }

    // ---- task references ----

    public function testParseTaskRefReadsBareIds(): void
    {
        // Shapes that resolve without consulting any project prefix.
        $this->assertSame(32, pmMcpTaskHelper::parseTaskRef(32)['id']);
        $this->assertSame(32, pmMcpTaskHelper::parseTaskRef('32')['id']);
        $this->assertSame(32, pmMcpTaskHelper::parseTaskRef(' 32 ')['id']);
        $this->assertSame(32, pmMcpTaskHelper::parseTaskRef('#32')['id']);
        $this->assertSame('', pmMcpTaskHelper::parseTaskRef('#32')['prefix'], 'a bare id carries no prefix');
    }

    public function testParseTaskRefRejectsGarbage(): void
    {
        $this->expectException(waAPIException::class);
        pmMcpTaskHelper::parseTaskRef('---');
    }

    // ---- dependencies: relation <-> stored row mapping ----

    public function testDependsOnIsStoredOnTheDependentTask(): void
    {
        $row = pmMcpDependencyHelper::rowFor('depends_on', 10, 20, 'FS');
        $this->assertSame(array('task_id' => 10, 'depends_on_task_id' => 20, 'type' => 'FS'), $row);
    }

    public function testBlocksIsStoredOnTheOtherTask(): void
    {
        // "10 blocks 20" is the same record as "20 depends on 10", so the row
        // belongs to 20 — that inversion is what makes the relation mutual.
        $row = pmMcpDependencyHelper::rowFor('blocks', 10, 20, 'SS');
        $this->assertSame(array('task_id' => 20, 'depends_on_task_id' => 10, 'type' => 'SS'), $row);
    }

    public function testSymmetricRelationsIgnoreTheSchedulingType(): void
    {
        $this->assertSame(
            array('task_id' => 10, 'depends_on_task_id' => 20, 'type' => 'RELATES_TO'),
            pmMcpDependencyHelper::rowFor('relates_to', 10, 20, 'FF')
        );
        $this->assertSame(
            'DUPLICATES',
            pmMcpDependencyHelper::rowFor('duplicates', 10, 20)['type']
        );
    }

    public function testInverseRelation(): void
    {
        $this->assertSame('blocks', pmMcpDependencyHelper::inverse('depends_on'));
        $this->assertSame('depends_on', pmMcpDependencyHelper::inverse('blocks'));
        $this->assertSame('relates_to', pmMcpDependencyHelper::inverse('relates_to'), 'symmetric relations invert to themselves');
        $this->assertSame('duplicates', pmMcpDependencyHelper::inverse('duplicates'));
    }

    public function testRelationFromRowDependsOnTheSideYouReadFrom(): void
    {
        $row = array('id' => 1, 'task_id' => 10, 'depends_on_task_id' => 20, 'type' => 'FS');
        $this->assertSame('depends_on', pmMcpDependencyHelper::relationFromRow($row, 10));
        $this->assertSame('blocks', pmMcpDependencyHelper::relationFromRow($row, 20));
        $this->assertSame(20, pmMcpDependencyHelper::counterpart($row, 10));
        $this->assertSame(10, pmMcpDependencyHelper::counterpart($row, 20));

        $related = array('id' => 2, 'task_id' => 10, 'depends_on_task_id' => 20, 'type' => 'RELATES_TO');
        $this->assertSame('relates_to', pmMcpDependencyHelper::relationFromRow($related, 10));
        $this->assertSame('relates_to', pmMcpDependencyHelper::relationFromRow($related, 20));
    }

    public function testNormalizeType(): void
    {
        $this->assertSame('FS', pmMcpDependencyHelper::normalizeType(''), 'FS is the default');
        $this->assertSame('SF', pmMcpDependencyHelper::normalizeType(' sf '));
    }

    public function testNormalizeTypeRejectsSymmetricAndGarbage(): void
    {
        $this->expectException(waAPIException::class);
        // Symmetric types are chosen through `relation`, not `type`.
        pmMcpDependencyHelper::normalizeType('RELATES_TO');
    }

    // ---- wiki: parseAccessRoles ----

    public function testParseAccessRoles(): void
    {
        $this->assertSame(array(), pmMcpWikiHelper::parseAccessRoles(''));
        $this->assertSame(array('member', 'viewer'), pmMcpWikiHelper::parseAccessRoles(' member , viewer '));
    }

    // ---- wiki: buildAccessRoles (uses roles config, no DB rows) ----

    public function testBuildAccessRolesAcceptsKnownRoles(): void
    {
        $csv = pmMcpWikiHelper::buildAccessRoles(array('member', 'member', 'viewer'));
        $this->assertSame('member,viewer', $csv, 'duplicates collapsed, order preserved');
        $this->assertSame('', pmMcpWikiHelper::buildAccessRoles(null));
        $this->assertSame('', pmMcpWikiHelper::buildAccessRoles(array()));
    }

    public function testBuildAccessRolesRejectsUnknownRole(): void
    {
        $this->expectException(waAPIException::class);
        pmMcpWikiHelper::buildAccessRoles(array('wizard'));
    }

    // ---- wiki: canUserSeePage matrix ----

    public function testCanSeePublishedPublicPage(): void
    {
        $page = array('published' => 1, 'is_public' => 1, 'access_roles' => '', 'create_contact_id' => 99);
        $this->assertTrue(pmMcpWikiHelper::canUserSeePage($page, 5, 'member', false));
    }

    public function testRestrictedPublishedPageHonoursRoles(): void
    {
        $page = array('published' => 1, 'is_public' => 0, 'access_roles' => 'manager,member', 'create_contact_id' => 99);
        $this->assertTrue(pmMcpWikiHelper::canUserSeePage($page, 5, 'member', false), 'listed role sees it');
        $this->assertFalse(pmMcpWikiHelper::canUserSeePage($page, 5, 'viewer', false), 'unlisted role does not');
        $this->assertTrue(pmMcpWikiHelper::canUserSeePage($page, 5, 'viewer', true), 'admin/manager always sees it');
    }

    public function testUnpublishedPageVisibleToAuthorAndManagers(): void
    {
        $page = array('published' => 0, 'is_public' => 0, 'access_roles' => '', 'create_contact_id' => 5);
        $this->assertTrue(pmMcpWikiHelper::canUserSeePage($page, 5, 'member', false), 'author sees own draft');
        $this->assertFalse(pmMcpWikiHelper::canUserSeePage($page, 8, 'member', false), 'other member does not');
        $this->assertTrue(pmMcpWikiHelper::canUserSeePage($page, 8, 'manager', true), 'admin/manager sees drafts');
    }
}
