<?php
/**
 * Smoke test: the phpunit <-> bootstrap wiring works, the framework, the mcp
 * and pm apps booted, the plugin's classes autoload, and the whole tool surface
 * registers cleanly with a matching right for every tool.
 *
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class pmMcpSmokeTest extends TestCase
{
    private const EXPECTED_TOOL_COUNT = 37;

    private function plugin(): mcpPmPlugin
    {
        return new mcpPmPlugin(array('id' => 'pm', 'app_id' => 'mcp'));
    }

    /** @return mcpTool[] */
    private function tools(): array
    {
        $registry = new mcpToolRegistry();
        $this->plugin()->registerTools($registry);
        return $registry->getTools();
    }

    public function testFrameworkAndAppsBooted(): void
    {
        $this->assertTrue(class_exists('mcpPlugin'), 'mcp app base class must autoload');
        $this->assertTrue(class_exists('mcpTool'), 'mcp tool base class must autoload');
        $this->assertTrue(class_exists('mcpToolRegistry'), 'mcp registry must autoload');
        $this->assertTrue(class_exists('waModel'), 'framework class must autoload');
        $this->assertTrue(class_exists('pmTask'), 'pm domain class must autoload');
        $this->assertTrue(class_exists('pmHelper'), 'pm helper must autoload');
    }

    public function testPluginClassesAutoload(): void
    {
        foreach (array(
            'mcpPmPlugin',
            'pmMcpToolBase',
            'pmMcpProjectHelper',
            'pmMcpTaskHelper',
            'pmMcpTagHelper',
            'pmMcpDependencyHelper',
            'pmMcpWorkflowHelper',
            'pmMcpWikiHelper',
            'pmMcpSprintHelper',
            'pmMcpExternalHelper',
        ) as $class) {
            $this->assertTrue(class_exists($class), "$class must autoload");
        }
    }

    public function testAllToolsRegister(): void
    {
        $tools = $this->tools();
        $this->assertCount(self::EXPECTED_TOOL_COUNT, $tools);
    }

    public function testEveryToolIsWellFormed(): void
    {
        $names = array();
        foreach ($this->tools() as $tool) {
            $this->assertInstanceOf(pmMcpToolBase::class, $tool);

            $name = $tool->getName();
            $this->assertNotSame('', $name, 'tool name must be non-empty');
            $this->assertMatchesRegularExpression('/^pm_[a-z_]+$/', $name, "tool name '$name' must be snake_case");
            $names[] = $name;

            // Right equals name (helpdesk convention).
            $this->assertSame($name, $tool->getRight(), "tool '$name' right must equal its name");

            $this->assertNotSame('', trim((string) $tool->getDescription()), "tool '$name' must describe itself");

            $schema = $tool->getInputSchema();
            $this->assertSame('object', $schema['type'] ?? null, "tool '$name' schema must be an object");
            $this->assertArrayHasKey('properties', $schema, "tool '$name' schema must declare properties");
        }

        $this->assertSame(array_unique($names), $names, 'tool names must be unique');
    }

    public function testEveryToolHasARight(): void
    {
        $groups = array();
        $this->plugin()->registerRights($groups);
        $this->assertArrayHasKey('pm', $groups);

        $right_names = array();
        $right_groups = array();
        foreach ($groups['pm']['rights'] as $right) {
            $right_names[] = $right['name'];
            $right_groups[$right['group']] = true;
        }

        foreach ($this->tools() as $tool) {
            $this->assertContains($tool->getName(), $right_names, "tool '{$tool->getName()}' must have a registered right");
        }

        // The five documented right groups are present.
        foreach (array('pm.read', 'pm.tasks', 'pm.projects', 'pm.wiki', 'pm.sprints') as $group) {
            $this->assertArrayHasKey($group, $right_groups, "right group '$group' must exist");
        }
    }
}
