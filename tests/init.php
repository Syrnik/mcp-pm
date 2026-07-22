<?php
/**
 * PHPUnit bootstrap for the "pm" MCP plugin.
 *
 * Boots the Webasyst framework, the "pm" app (its domain classes pmTask,
 * pmHelper, pm*Model) and the "mcp" app (the plugin base classes mcpPlugin,
 * mcpTool, mcpToolRegistry, mcpSchemaValidator). The framework autoloader then
 * resolves the plugin's own classes (pmMcp*) by name.
 *
 * pm is booted FIRST on purpose: the first app booted becomes the current app,
 * and it must stay pm so that pm-relative config lookups
 * (pmHelper::getConfig -> pmWorkflow::getWorkflows / pmRoleModel, which cache
 * statically) resolve against the pm app rather than mcp. Booting mcp afterwards
 * does not steal the current-app pointer, and — unlike wa('pm', 1) or
 * waSystem::setActive('pm') — avoids re-initialising the system / reloading the
 * locale, which under the PHPUnit CLI would clobber argv or fatal.
 *
 * phpunit.phar is taken globally from the OSPanel PHP module; the plugin ships
 * neither phpunit nor composer.
 *
 * Depth 6: tests -> pm -> plugins -> mcp -> wa-apps -> htdocs.
 *
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require_once dirname(__FILE__, 6) . '/wa-config/SystemConfig.class.php';
waSystem::getInstance(null, new SystemConfig());
wa('pm');   // first app booted -> becomes and stays the current app
wa('mcp');  // loads the mcp plugin base classes; current app stays pm

// Eager-load the plugin's own classes and tools. We do NOT instantiate
// mcpPmPlugin here: its constructor calls wa('pm') and would flip the current
// app to mcp. Requiring the files directly loads pmMcpToolBase, the helpers and
// every tool (some test files subclass pmMcpToolBase at include time) without
// touching the current-app pointer.
$plugin_dir = dirname(__DIR__) . '/lib';
foreach (glob($plugin_dir . '/classes/*.php') as $f) {
    require_once $f;
}
foreach (glob($plugin_dir . '/tools/*.php') as $f) {
    require_once $f;
}
require_once $plugin_dir . '/mcpPm.plugin.php';

// Prime the workflow cache while pm is current, so a mis-timed read can never
// poison the static cache with an empty set.
pmWorkflow::getWorkflows();

// The abstract integration base does not end in *Test.php, so PHPUnit will not
// autoload it; the concrete integration tests extend it. Pull it in here.
require_once __DIR__ . '/pmMcpIntegrationTestCase.php';
