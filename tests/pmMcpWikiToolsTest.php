<?php
/**
 * Integration tests for the Stage 5 wiki tools against the live DB.
 *
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

class pmMcpWikiToolsTest extends pmMcpIntegrationTestCase
{
    private function createPage(array $args): array
    {
        return $this->callTool(new pmMcpCreateWikiPageTool(), array('project_id' => $this->project_id) + $args);
    }

    public function testCreateSectionHoldsNoContent(): void
    {
        $r = $this->createPage(array('title' => 'Docs', 'type' => 'section', 'content' => 'should vanish'));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('section', $r['page']['type']);
        $this->assertSame('', $r['page']['content'], 'a section never stores content');
    }

    public function testCreateArticleWithAccessRoles(): void
    {
        $r = $this->createPage(array(
            'title'        => 'Intro',
            'type'         => 'article',
            'content'      => '# Hello',
            'published'    => true,
            'is_public'    => false,
            'access_roles' => array('member'),
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('# Hello', $r['page']['content']);
        $this->assertTrue($r['page']['published']);
        $this->assertSame(array('member'), $r['page']['access_roles']);
    }

    public function testListReturnsTreeWithChildrenMap(): void
    {
        $section = $this->createPage(array('title' => 'Sect', 'type' => 'section'));
        $sid = $section['page_id'];
        $this->createPage(array('title' => 'Child', 'type' => 'article', 'parent_id' => $sid, 'published' => true, 'is_public' => true));

        $r = $this->callTool(new pmMcpListWikiPagesTool(), array('project_id' => $this->project_id));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertGreaterThanOrEqual(2, $r['count']);
        $this->assertTrue($r['can_edit']);
        // children is an object map keyed by parent id.
        $children = (array) $r['children'];
        $this->assertArrayHasKey((string) $sid, $children, 'the section must list its child');
    }

    public function testGetPageReturnsContent(): void
    {
        $created = $this->createPage(array('title' => 'Body', 'content' => 'text here', 'published' => true, 'is_public' => true));
        $r = $this->callTool(new pmMcpGetWikiPageTool(), array('page_id' => $created['page_id']));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('text here', $r['page']['content']);
    }

    public function testGetMissingPage(): void
    {
        $r = $this->callTool(new pmMcpGetWikiPageTool(), array('page_id' => 999999999));
        $this->assertFalse($r['ok']);
        $this->assertSame('not_found', $r['error_code']);
    }

    public function testUpdateRenameAndUnpublish(): void
    {
        $created = $this->createPage(array('title' => 'Old', 'content' => 'a', 'published' => true, 'is_public' => true));
        $r = $this->callTool(new pmMcpUpdateWikiPageTool(), array(
            'page_id'   => $created['page_id'],
            'title'     => 'New',
            'content'   => 'b',
            'published' => false,
        ));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('New', $r['page']['title']);
        $this->assertSame('b', $r['page']['content']);
        $this->assertFalse($r['page']['published']);
    }

    public function testUpdateMoveToTopLevel(): void
    {
        $section = $this->createPage(array('title' => 'S', 'type' => 'section'));
        $child = $this->createPage(array('title' => 'C', 'parent_id' => $section['page_id']));
        $r = $this->callTool(new pmMcpUpdateWikiPageTool(), array('page_id' => $child['page_id'], 'parent_id' => 0));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertNull($r['page']['parent_id']);
    }

    public function testCannotMovePageIntoItself(): void
    {
        $page = $this->createPage(array('title' => 'Self'));
        $r = $this->callTool(new pmMcpUpdateWikiPageTool(), array('page_id' => $page['page_id'], 'parent_id' => $page['page_id']));
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_param', $r['error_code']);
    }

    public function testSwitchArticleToSectionClearsContent(): void
    {
        $page = $this->createPage(array('title' => 'Art', 'content' => 'body', 'type' => 'article'));
        $r = $this->callTool(new pmMcpUpdateWikiPageTool(), array('page_id' => $page['page_id'], 'type' => 'section'));
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('section', $r['page']['type']);
        $this->assertSame('', $r['page']['content']);
    }
}
