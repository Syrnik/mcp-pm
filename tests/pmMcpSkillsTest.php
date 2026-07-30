<?php
/**
 * The plugin's skill registration (mcp_skill_registry_v1) is well-formed: the
 * handler is wired in plugin.php, every declared skill points at a real
 * markdown file inside skills/, and no file in skills/ is left unregistered.
 *
 * DB-independent — the registration is pure configuration.
 *
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class pmMcpSkillsTest extends TestCase
{
    private const PLUGIN_DIR = __DIR__ . '/..';

    private function plugin(): mcpPmPlugin
    {
        return new mcpPmPlugin(array('id' => 'pm', 'app_id' => 'mcp'));
    }

    /** @return array[] the entries of $groups['pm']['skills'] */
    private function skills(): array
    {
        $groups = array();
        $this->plugin()->registerSkills($groups);

        $this->assertArrayHasKey('pm', $groups, 'skills must be registered under the "pm" key');
        $this->assertNotSame('', trim((string) ($groups['pm']['name'] ?? '')), 'the group needs a display name');
        $this->assertIsArray($groups['pm']['skills'] ?? null);
        $this->assertNotEmpty($groups['pm']['skills'], 'the plugin must declare at least one skill');

        return $groups['pm']['skills'];
    }

    public function testHandlerIsWiredInPluginConfig(): void
    {
        $config = include self::PLUGIN_DIR . '/lib/config/plugin.php';

        $this->assertSame(
            'registerSkills',
            $config['handlers']['mcp_skill_registry_v1'] ?? null,
            'plugin.php must bind mcp_skill_registry_v1 to registerSkills'
        );
    }

    /**
     * The registry landed in mcp 1.2.0. Declaring skills against an older mcp
     * would advertise documentation the core cannot serve.
     */
    public function testRequirementsDemandAnMcpWithTheSkillRegistry(): void
    {
        $requirements = include self::PLUGIN_DIR . '/lib/config/requirements.php';

        $this->assertArrayHasKey('app.mcp', $requirements);
        $this->assertTrue((bool) ($requirements['app.mcp']['strict'] ?? false), 'the mcp requirement must stay strict');

        $version = (string) ($requirements['app.mcp']['version'] ?? '');
        $this->assertMatchesRegularExpression('/^>=\s*(\d+\.\d+\.\d+)$/', $version);
        $this->assertGreaterThanOrEqual(
            0,
            version_compare(ltrim($version, '>= '), '1.2.0'),
            'the skill registry requires app.mcp >= 1.2.0'
        );
    }

    public function testEverySkillIsWellFormed(): void
    {
        $ids = array();

        foreach ($this->skills() as $skill) {
            $this->assertIsArray($skill);

            $id = (string) ($skill['id'] ?? '');
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id, "skill id '$id' must be kebab-case");
            $ids[] = $id;

            $this->assertNotSame('', trim((string) ($skill['name'] ?? '')), "skill '$id' must have a name");
            $this->assertNotSame('', trim((string) ($skill['description'] ?? '')), "skill '$id' must describe itself");
            $this->assertSame('text/markdown', $skill['mime'] ?? null, "skill '$id' must be served as markdown");
        }

        $this->assertSame(array_unique($ids), $ids, 'skill ids must be unique');
    }

    /**
     * mcpSkillRegistry resolves the declared path against the plugin directory
     * and refuses anything outside skills/ or containing "..", dropping the
     * skill silently. Catch such a path here instead of in production.
     *
     * The prefix the registry demands is 'skills' . DIRECTORY_SEPARATOR, so a
     * hard-coded "skills/" would pass on Linux and silently drop every skill on
     * Windows — assert the separator the registry actually compares against.
     */
    public function testEverySkillPathIsInsideSkillsAndExists(): void
    {
        foreach ($this->skills() as $skill) {
            $id = (string) $skill['id'];
            $path = (string) ($skill['path'] ?? '');

            $this->assertStringStartsWith(
                'skills' . DIRECTORY_SEPARATOR,
                $path,
                "skill '$id' must live under skills/ and use DIRECTORY_SEPARATOR, which is what mcpSkillRegistry compares against"
            );
            $this->assertStringNotContainsString('..', $path, "skill '$id' path must not traverse upwards");
            $this->assertStringEndsWith('.md', $path, "skill '$id' must point at a markdown file");

            $absolute = self::PLUGIN_DIR . '/' . $path;
            $this->assertFileExists($absolute, "skill '$id' declares a file that does not exist");
            $this->assertGreaterThan(0, (int) filesize($absolute), "skill '$id' file must not be empty");
        }
    }

    /**
     * The end-to-end guard: mcpSkillRegistry drops a rejected path without
     * throwing, so the only way to notice is to run the core's own check. This
     * reproduces it against the real class when the mcp app is present, and
     * fails on the exact platform where a hard-coded separator would break.
     */
    public function testEverySkillSurvivesTheRegistryPathCheck(): void
    {
        if (!class_exists('mcpSkillRegistry')) {
            $this->markTestSkipped('the mcp app is not available in this bootstrap');
        }

        $resolve = (new ReflectionClass('mcpSkillRegistry'))->getMethod('resolvePluginRelative');
        $resolve->setAccessible(true);
        $registry = new mcpSkillRegistry();

        foreach ($this->skills() as $skill) {
            $id = (string) $skill['id'];
            $resolved = (string) $resolve->invoke($registry, 'pm', (string) $skill['path']);

            $this->assertNotSame(
                '',
                $resolved,
                "skill '$id' was rejected by mcpSkillRegistry and would never reach resources/list"
            );
            $this->assertFileExists($resolved, "skill '$id' resolved to a path that does not exist");
        }
    }

    /**
     * A markdown file nobody registered is a file nobody reads: the registry
     * only serves what registerSkills() declares.
     */
    public function testNoSkillFileIsLeftUnregistered(): void
    {
        $declared = array();
        foreach ($this->skills() as $skill) {
            $declared[] = basename((string) $skill['path']);
        }

        $on_disk = array_map('basename', glob(self::PLUGIN_DIR . '/skills/*.md') ?: array());

        sort($declared);
        sort($on_disk);
        $this->assertSame($on_disk, $declared, 'every skills/*.md file must be registered, and vice versa');
    }

    /**
     * The skills ship: they are the plugin's documentation surface, so they
     * must not be swept up by the packaging excludes the way README/AGENTS are.
     */
    public function testSkillsAreNotExcludedFromTheReleaseBundle(): void
    {
        $exclude = include self::PLUGIN_DIR . '/lib/config/exclude.php';

        foreach ($exclude as $pattern) {
            $this->assertStringNotContainsString('skills', (string) $pattern, 'skills/ must stay in the release bundle');
        }
    }
}
