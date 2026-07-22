<?php
/**
 * Standalone App Plugin Compress Script
 *
 * Checks and packages a Webasyst app plugin (wa-apps/<app>/plugins/<plugin_id>)
 * into {plugin_id}.tar.gz.
 * No Webasyst framework required — works standalone in CI/CD.
 *
 * Usage:
 *   php compress-app-plugin.php <app_id>/plugins/<plugin_id> [options]
 *
 *   -style  true|false|no-vendors   Code checks (default: false — disabled)
 *   -skip   compress|test|all|none  Operations to skip (default: none)
 *   -php    /path/to/php            Custom PHP binary for syntax check
 *   -apps-dir /path/to/wa-apps/     apps directory (default: ./wa-apps/ beside script)
 *   -plugin-path /absolute/path/    Direct plugin path — overrides apps-dir+app_id+plugins+plugin_id
 *
 * Examples:
 *   php compress-app-plugin.php shop/plugins/sdekint
 *   php compress-app-plugin.php shop/plugins/sdekint -skip compress
 *   php compress-app-plugin.php shop/plugins/sdekint -plugin-path /workspace/sdekint
 */

class AppPluginCompressor
{
    /** @var string */
    private $appId;

    /** @var string */
    private $pluginId;

    /** @var string */
    private $pluginPath;

    /** @var string[] */
    private $files = [];

    /** @var array */
    private $params = [];

    private static $defaults = [
        'style' => 'false',
        'skip'  => 'none',
    ];

    public function __construct(array $argv)
    {
        $this->parseArgs($argv);
    }

    // -------------------------------------------------------------------------
    // Main flow
    // -------------------------------------------------------------------------

    public function run(): void
    {
        $this->initPath();

        $skip  = $this->params['skip'] ?? 'none';
        $style = array_map('trim', explode(',', $this->params['style'] ?? 'false'));

        $ok = true;

        if (!in_array($skip, ['test', 'all'], true)) {
            $this->out('');
            $ok = $this->test($style);
            $this->out($ok ? 'Config check OK' : 'Config check FAILED');

            if (!in_array('false', $style, true)) {
                $codeOk = $this->checkCode();
                $this->out($codeOk ? 'Code check OK' : 'Code check FAILED');
                $ok = $ok && $codeOk;
            } else {
                $this->out('Code style check disabled');
            }
        } else {
            $this->out('Config check skipped');
        }

        if ($ok && !in_array($skip, ['compress', 'all'], true)) {
            $this->out('');
            $this->compress();
        } elseif ($ok) {
            $this->out('Test completed');
        } else {
            exit(1);
        }
    }

    // -------------------------------------------------------------------------
    // Argument parsing
    // -------------------------------------------------------------------------

    private function parseArgs(array $argv): void
    {
        if (count($argv) < 2 || in_array($argv[1], ['--help', '-h', 'help'], true)) {
            $this->printHelp();
            exit(0);
        }

        $slug = $argv[1];
        $idPattern = '[a-z][a-z0-9_]+';
        if (!preg_match("@^({$idPattern})/plugins/({$idPattern})$@", $slug, $m)) {
            throw new InvalidArgumentException(
                "Invalid slug '{$slug}'. Expected format: <app_id>/plugins/<plugin_id> " .
                "(e.g. shop/plugins/sdekint)"
            );
        }

        $this->appId    = $m[1];
        $this->pluginId = $m[2];

        $params = self::$defaults;
        $i = 2;
        while ($i < count($argv)) {
            $key = ltrim($argv[$i], '-');
            $i++;
            if ($i < count($argv) && substr($argv[$i], 0, 1) !== '-') {
                $params[$key] = $argv[$i];
                $i++;
            } else {
                $params[$key] = true;
            }
        }
        $this->params = $params;
    }

    // -------------------------------------------------------------------------
    // Path initialization and file collection
    // -------------------------------------------------------------------------

    private function initPath(): void
    {
        if (!empty($this->params['plugin-path'])) {
            $this->pluginPath = rtrim($this->params['plugin-path'], '/\\');
        } else {
            $scriptDir = dirname(realpath(__FILE__));
            $appsDir   = isset($this->params['apps-dir'])
                ? rtrim($this->params['apps-dir'], '/\\')
                : $scriptDir . '/wa-apps';
            $this->pluginPath = $appsDir . '/' . $this->appId . '/plugins/' . $this->pluginId;
        }

        if (!is_dir($this->pluginPath)) {
            throw new RuntimeException("Plugin directory not found: {$this->pluginPath}");
        }

        $this->outf('Check & compress plugin: %s/plugins/%s', $this->appId, $this->pluginId);
        $this->outf('PHP version: %s', PHP_VERSION);
        $this->outf('Plugin path: %s', $this->pluginPath);

        $this->files = $this->listFilesRecursive($this->pluginPath);
        sort($this->files);

        $blacklist = [];
        $whitelist = [];

        // Merge rules from .gitignore
        $gitignore = $this->pluginPath . '/.gitignore';
        if (file_exists($gitignore)) {
            $rules     = $this->parseGitignore($gitignore);
            $blacklist = array_merge($rules['blacklist'], $blacklist);
            $whitelist = array_merge($rules['whitelist'], $whitelist);
        }

        // Merge rules from lib/config/exclude.php
        $excludeFile = $this->pluginPath . '/lib/config/exclude.php';
        if (file_exists($excludeFile)) {
            $exclude = include($excludeFile);
            if (is_array($exclude)) {
                foreach (self::makePatterns($exclude) as $pattern) {
                    $blacklist[$pattern] = 'disabled at exclude.php';
                }
            }
        }

        $skipped = $this->filter($this->files, $blacklist, $whitelist);

        if ($skipped) {
            $this->out(str_repeat('-', 80));
            $this->outf('IGNORE %d FILE(S)', count($skipped));
            $this->out(str_repeat('-', 80));
            $n = 0;
            foreach ($skipped as $file => $description) {
                $this->outf('%3d | %-40s | %s', ++$n, $file, $description);
            }
            $this->out(str_repeat('-', 80));
        }
    }

    // -------------------------------------------------------------------------
    // File listing
    // -------------------------------------------------------------------------

    /**
     * @return string[]
     */
    private function listFilesRecursive(string $dir): array
    {
        $files   = [];
        $baseLen = strlen($dir) + 1;
        $iter    = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $item) {
            if ($item->isFile()) {
                $files[] = str_replace('\\', '/', substr($item->getPathname(), $baseLen));
            }
        }
        return $files;
    }

    // -------------------------------------------------------------------------
    // File filtering
    // -------------------------------------------------------------------------

    /**
     * Remove blacklisted files (unless whitelisted) and return the skipped list.
     *
     * @param string[] $files
     * @param array    $blacklist  pattern => description
     * @param array    $whitelist  pattern => description
     * @return array   file => reason
     */
    private function filter(array &$files, array $blacklist, array $whitelist): array
    {
        // Standard blacklist — mirrors the original webasystCompress rules
        $blacklist = array_merge($blacklist, [
            '@^lib/updates/dev/.+@'                                => 'developer stage updates',
            '@^lib/config/exclude\.php@'                           => 'exclude files list',
            '@\.styl$@'                                            => 'CSS preprocessor files',
            '@\.(bak|old|user|te?mp|www)(\.(php|css|js|html))?$@' => 'temp file',
            '@(locale)\/.+\.(te?mp)(\.(po|mo))?$@'                => 'temp files in locale directory',
            '@(/|^)(\.DS_Store|\.desktop\.ini|thumbs\.db)$@i'      => 'system file',
            '@\b\.(svn|git|hg_archival\.txt)\b@'                   => 'CVS file',
            '@(/|^)\.git.*@'                                       => 'GIT file',
            '@(/|^)\.[^/]+/@'                                      => 'directory with leading dot',
            '@(/|^)\.(project|buildpath)@'                         => 'IDE file',
            '@\.(zip|rar|gz)$@'                                    => 'archive',
            '@\.log$@'                                             => 'log file',
            '@\.md5$@'                                             => 'checksum file',
            '@\.(exe|dll|sys)$@'                                   => 'executable file',
            '@(/|^)[^\.]*todo$@i'                                  => 'TODO file',
            '@(/|^)[^\.]+$@'                                       => 'unknown type file',
            '@(/|^)[^0-9a-z_\-\.]+$@'                              => 'invalid filename characters',
            '@\.fw_@'                                              => 'internal files',
        ]);

        $skipped = [];
        foreach ($files as $id => $file) {
            foreach ($blacklist as $pattern => $description) {
                if (!preg_match($pattern, $file)) {
                    continue;
                }
                $whitelisted = false;
                foreach ($whitelist as $wPattern => $wDesc) {
                    if (preg_match($wPattern, $file)) {
                        $whitelisted = true;
                        break;
                    }
                }
                if (!$whitelisted) {
                    $skipped[$file] = $description;
                    unset($files[$id]);
                }
                break;
            }
        }
        $files = array_values($files);
        return $skipped;
    }

    // -------------------------------------------------------------------------
    // Gitignore / exclude pattern helpers
    // -------------------------------------------------------------------------

    private function parseGitignore(string $file): array
    {
        $blacklist = [];
        $whitelist = [];
        foreach (file($file) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if ($line[0] === '!') {
                $sub = substr($line, 1);
                $whitelist[self::getGitPattern($sub)] = 'Gitignore rule !' . $sub;
            } else {
                $blacklist[self::getGitPattern($line)] = 'Gitignore rule ' . $line;
            }
        }
        return compact('blacklist', 'whitelist');
    }

    private static function getGitPattern(string $pattern): string
    {
        $pattern = preg_replace('@^/\*\*/@', '', $pattern);
        $pattern = preg_replace('@^/@', '^', $pattern);
        $pattern = preg_replace('@/\*\*/@', '([^/]+/){0,}', $pattern);
        $pattern = preg_replace('@\*@', '.*', $pattern);
        return "@{$pattern}@";
    }

    /**
     * Convert simple glob-style strings (from exclude.php) to regex patterns.
     *
     * @param string[] $patterns
     * @return string[]
     */
    private static function makePatterns(array $patterns, string $basePath = null): array
    {
        $metaChars = ['+', '.', '(', ')', '[', ']', '{', '}', '<', '>', '^', '$', '@'];
        foreach ($metaChars as &$char) {
            $char = "\\{$char}";
        }
        unset($char);

        $commandChars = ['?', '*'];
        foreach ($commandChars as &$char) {
            $char = "\\{$char}";
        }
        unset($char);

        $cleanupPattern = '@(' . implode('|', $metaChars) . ')@';
        $commandPattern = '@(' . implode('|', $commandChars) . ')@';

        if ($basePath) {
            $basePath = preg_replace('@([/\\\\]+)@', '/', $basePath . '/');
            $basePath = preg_replace($cleanupPattern, '\\\\$1', $basePath);
        }

        foreach ($patterns as &$pattern) {
            $pattern = preg_replace($cleanupPattern, '\\\\$1', $pattern);
            $pattern = preg_replace($commandPattern, '.$1', $pattern);
            $pattern = "@^{$basePath}({$pattern})@m";
        }
        unset($pattern);

        return $patterns;
    }

    // -------------------------------------------------------------------------
    // Config loading and token analysis
    // -------------------------------------------------------------------------

    /**
     * Safely include lib/config/{name}.php and return its value.
     *
     * @return array|null|false  array on success, null if file absent, false if invalid
     */
    private function loadConfig(string $name)
    {
        $path = $this->pluginPath . '/lib/config/' . $name . '.php';
        if (!file_exists($path)) {
            return null;
        }
        $config = include($path);
        return is_array($config) ? $config : false;
    }

    /**
     * Token-analyze a config file for forbidden constructs (no functions, classes, etc.).
     */
    private function checkConfig(string $name): bool
    {
        $path    = $this->pluginPath . '/lib/config/' . $name . '.php';
        $relName = '/lib/config/' . $name . '.php';

        if (!file_exists($path)) {
            return true;
        }

        $blacklistedTokens = $this->getConfigTokensBlacklist();
        $tokens = token_get_all(file_get_contents($path));
        $result = true;
        $skip   = [];

        foreach ($tokens as $id => $token) {
            if (isset($skip[$id]) || !is_array($token)) {
                continue;
            }

            if (in_array($token[0], $blacklistedTokens)) {
                if ($result) {
                    $this->outf("\nERROR encountered in config file %s:", $relName);
                }
                $result = false;
                $this->outf("\tUnexpected '%s' (%s) on line %d", $token[1], token_name($token[0]), $token[2]);

            } elseif ($token[0] === T_OPEN_TAG && $token[1] === '<?') {
                if ($result) {
                    $this->outf("\nERROR encountered in config file %s:", $relName);
                }
                $result = false;
                $this->outf("\tPHP short open tag not allowed on line %d", $token[2]);

            } elseif ($token[0] === T_STRING && !in_array($token[1], ['true', 'false', 'null'], true)) {
                $next  = $tokens[$id + 1] ?? null;
                $next2 = $tokens[$id + 2] ?? null;
                if (is_array($next) && $next[0] === T_DOUBLE_COLON) {
                    if (is_array($next2) && $next2[0] === T_STRING) {
                        $constant = "{$token[1]}::{$next2[1]}";
                        if (!defined($constant)) {
                            if ($result) {
                                $this->outf("\nERROR encountered in config file %s:", $relName);
                            }
                            $result = false;
                            $this->outf("\tUndefined constant '%s' on line %d", $constant, $token[2]);
                        }
                        $skip[$id + 1] = true;
                        $skip[$id + 2] = true;
                        continue;
                    }
                }
                if ($result) {
                    $this->outf("\nERROR encountered in config file %s:", $relName);
                }
                $result = false;
                $this->outf("\tUnexpected '%s' (%s) on line %d", $token[1], token_name($token[0]), $token[2]);
            }
        }

        if (!$result) {
            $this->out('');
        }

        return $result;
    }

    private function getConfigTokensBlacklist(): array
    {
        $list = [
            T_FUNC_C, T_FUNCTION, T_INTERFACE, T_CLASS, T_CLASS_C,
            T_CONST, T_DOUBLE_COLON, T_OPEN_TAG_WITH_ECHO, T_EXIT,
            T_NEW, T_PRINT, T_ECHO, T_DECLARE, T_GLOBAL, T_HALT_COMPILER,
        ];
        foreach (['T_NAMESPACE', 'T_TRAIT', 'T_TRAIT_C', 'T_USE', 'T_OPEN_SHORT_ARRAY', 'T_YIELD'] as $c) {
            if (defined($c)) {
                $list[] = constant($c);
            }
        }
        return $list;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * @param string[] $style
     */
    private function test(array $style): bool
    {
        $result = true;
        $result = $this->testConfig() && $result;
        $result = $this->testRequirements() && $result;
        $result = $this->testPhp($style) && $result;
        $result = $this->testDb() && $result;
        $result = $this->testInstall() && $result;
        return $result;
    }

    private function testConfig(): bool
    {
        $this->out('Start test plugin config.');
        $valid = true;

        $available = [
            'name', 'description', 'version', 'vendor', 'img', 'icon', 'logo',
            'frontend', 'license', 'critical', 'locale',
            'handlers', 'rights', 'custom_settings',
        ];

        if ($this->appId === 'shop') {
            $available = array_merge($available, [
                'shop_settings', 'importexport', 'export_profile', 'printforms', 'emailprintform',
            ]);
        }

        if (!$this->checkConfig('plugin')) {
            $this->out('ERROR: Invalid plugin config. Config full test skipped.');
            return false;
        }

        $config = $this->loadConfig('plugin');
        if ($config === null) {
            $this->out('ERROR: lib/config/plugin.php not found');
            return false;
        }
        if ($config === false) {
            $this->out('ERROR: lib/config/plugin.php does not return an array');
            return false;
        }

        // Required fields
        foreach (['name', 'version', 'vendor'] as $required) {
            if (empty($config[$required])) {
                $this->outf('ERROR: required field "%s" missing or empty in plugin.php', $required);
                $valid = false;
            }
        }

        $unknown = array_diff(array_keys($config), $available);
        if ($unknown) {
            $this->outf("Unknown config options: %s", implode(', ', $unknown));
        }

        $hasImages = false;
        foreach (['icon', 'img', 'logo'] as $field) {
            if (!empty($config[$field])) {
                foreach ((array)$config[$field] as $imgFile) {
                    $imgFile = '/' . ltrim($imgFile, '/');
                    if (!file_exists($this->pluginPath . $imgFile)) {
                        $this->outf('WARNING: not found %s file %s', $field, $imgFile);
                        $valid = false;
                    } else {
                        $hasImages = true;
                    }
                }
            }
        }
        if (!$hasImages) {
            $this->out('WARNING: not found any of icon, img, logo in plugin config');
            $valid = false;
        }

        $valid = $this->testRouting($config) && $valid;

        if ($valid) {
            $this->out("\tOK");
        }
        return $valid;
    }

    private function testRouting(array $config): bool
    {
        $routing = $this->loadConfig('routing');

        if (!empty($routing)) {
            if (empty($config['frontend'])) {
                $this->out('NOTICE: routing.php exists but frontend is not declared in plugin.php');
            }
        } else {
            if (!empty($config['frontend']) && empty($config['handlers']['routing'])) {
                $this->out('ERROR: frontend is declared but routing.php is absent and handlers[routing] is not set');
                return false;
            }
        }

        return true;
    }

    private function testRequirements(): bool
    {
        $this->out('Start checking system requirements');

        $requirements = $this->loadConfig('requirements');
        if ($requirements === null) {
            $this->out("\tPASSED (no requirements.php)");
            return true;
        }
        if ($requirements === false) {
            $this->out('ERROR: Invalid requirements.php');
            return false;
        }
        if (!$this->checkConfig('requirements')) {
            return false;
        }

        foreach ($requirements as $key => $info) {
            if (preg_match('@^php\.(.+)$@', $key, $m) && !extension_loaded($m[1])) {
                $this->outf('NOTICE: extension "%s" not loaded on this machine (strict check skipped)', $m[1]);
            }
        }

        $this->out("\tPASSED");
        return true;
    }

    private function testDb(): bool
    {
        $result = true;

        $this->out('Start test plugin database section.');

        // Namespace can be overridden by 'prefix' in plugin.php
        $config    = $this->loadConfig('plugin');
        $namespace = (!empty($config['prefix']) && is_string($config['prefix']))
            ? $config['prefix']
            : $this->appId . '_' . $this->pluginId;

        $db = $this->loadConfig('db');

        if ($db) {
            $pattern = "@^{$namespace}(_.+)?$@";
            foreach ($db as $table => $info) {
                if (!preg_match($pattern, $table)) {
                    $result = false;
                    $this->outf('Invalid table name: "%s"', $table);
                }
            }
            if (!$result) {
                $this->outf('Valid table names: "%1$s" or "%1$s_*"', $namespace);
            } else {
                $this->out("\tPassed");
            }
        } elseif ($db === false) {
            $this->out('ERROR: Invalid db.php');
            $result = false;
        } else {
            $this->out("\tPassed (no database)");
        }

        return $result;
    }

    private function testInstall(): bool
    {
        $this->out('Start test plugin install/uninstall section.');
        $install   = in_array('lib/config/install.php', $this->files);
        $uninstall = in_array('lib/config/uninstall.php', $this->files);

        if (($install && !$uninstall) || (!$install && $uninstall)) {
            $this->out('NOTICE: only one of install.php & uninstall.php is present');
        } else {
            $this->out("\tPassed");
        }
        return true;
    }

    /**
     * @param string[] $style
     */
    private function testPhp(array $style): bool
    {
        $result = true;

        if (!$this->procOpenAvailable()) {
            $this->out("WARNING: PHP syntax check SKIPPED (proc_open not available)");
            return true;
        }

        $phpBins = $this->getPhpBinaries();
        if (empty($phpBins)) {
            $this->out("PHP binary not found, syntax check SKIPPED");
            return true;
        }

        foreach ($phpBins as $phpBin => $versionStr) {
            $this->out("\nStart checking PHP syntax");
            $matches = [];
            $version = preg_match('@PHP\s+(\d+(\.\d+)+)(\s|$)@', $versionStr, $matches)
                ? $matches[1]
                : $versionStr;
            $this->outf("PHP Version:\t%s\nBinary path:\t%s", $version, $phpBin);

            $versionResult = true;
            $errors        = ['ignored' => 0, 'strict' => 0];

            foreach ($this->files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
                    continue;
                }

                $strict = !in_array('no-vendors', $style, true)
                    || !preg_match('@^(lib/|js/)?vendors?/@', $file);

                $command  = sprintf('%s -l -f "%s/%s"', $phpBin, $this->pluginPath, $file);
                $exitCode = $this->execCommand($command, $stdout, $stderr);

                if ($exitCode !== 0) {
                    $lines = array_filter(array_merge($stdout, preg_split("@[\r\n]+@", $stderr)), 'trim');
                    $lines = array_filter($lines, static function ($l) {
                        return strpos($l, 'No syntax errors') === false;
                    });
                    if ($lines) {
                        $format = $strict ? "\nERROR at [%s]:" : "\nIgnored ERROR at vendor [%s]:";
                        $this->outf($format, $file);
                        foreach ($lines as $line) {
                            $this->outf("\t%s", trim(str_replace($this->pluginPath . '/' . $file, 'file', $line)));
                        }
                    }
                    if ($strict) {
                        $versionResult = false;
                        $errors['strict']++;
                    } else {
                        $errors['ignored']++;
                    }
                }
            }

            foreach ($errors as $type => $count) {
                if ($count) {
                    $this->outf("Found %d %s error(s)", $count, $type);
                }
            }
            $this->outf("PHP %s file syntax check\t%s", $version, $versionResult ? 'PASSED' : 'FAILED');
            $result = $result && $versionResult;
        }

        if (count($phpBins) > 1) {
            $this->outf("PHP file syntax check totally %s", $result ? 'PASSED' : 'FAILED');
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Code token analysis
    // -------------------------------------------------------------------------

    private function checkCode(): bool
    {
        $result = true;

        $varBlacklist = [
            '@^\$_(POST|GET|REQUEST|COOKIE|SERVER)$@' => 'Use waRequest or waStorage instead',
        ];
        $funcBlacklist = [
            '@^mysqli?_@'           => 'Use waModel instead',
            '@^eregi?(_replace)?$@' => 'Deprecated — use preg functions',
            '@^spliti?$@'           => 'Deprecated — use explode',
        ];

        // Expected class name prefix: {appId}{Ucfirst(pluginId)} e.g. shopSdekint
        $classPrefix  = $this->appId . ucfirst($this->pluginId);
        $classPattern = sprintf('@^%s\w*$@', $classPrefix);

        $classes   = [];
        $functions = [];

        foreach ($this->files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            $path   = $this->pluginPath . '/' . $file;
            $tokens = token_get_all(file_get_contents($path));

            foreach ($tokens as $id => $token) {
                if (!is_array($token)) {
                    continue;
                }

                switch ($token[0]) {
                    case T_CLASS:
                        $next_id = $id;
                        do {
                            $next = $tokens[++$next_id] ?? [];
                        } while (is_array($next) && $next[0] !== T_STRING && $next_id < $id + 10);
                        if (is_array($next) && isset($next[1])) {
                            $classes[$next[1]][] = $file;
                        }
                        break;

                    case T_VARIABLE:
                    case T_STRING_VARNAME:
                        foreach ($varBlacklist as $pattern => $hint) {
                            if (preg_match($pattern, $token[1])) {
                                $this->outf(
                                    "Not allowed variable %s at %s:%d\n\t%s",
                                    $token[1], $file, $token[2], $hint
                                );
                                $result = false;
                            }
                        }
                        break;

                    case T_STRING:
                        if (function_exists($token[1])) {
                            $functions[$token[1]][] = $file;
                        }
                        break;

                    case T_EVAL:
                    case T_EXIT:
                        $this->outf("Not allowed token '%s' at %s:%d", $token[1], $file, $token[2]);
                        $result = false;
                        break;

                    case T_OPEN_TAG:
                        if ($token[1] === '<?') {
                            $this->outf("PHP short open tag not allowed at %s:%d", $file, $token[2]);
                            $result = false;
                        }
                        break;

                    case T_CLOSE_TAG:
                        $this->outf("PHP closing tag not recommended at %s:%d", $file, $token[2]);
                        break;
                }
            }
        }

        // Class name prefix check
        foreach ($classes as $class => $classFiles) {
            if (!preg_match($classPattern, $class)) {
                $this->outf(
                    "Invalid class name '%s' at %s (expected prefix: %s)",
                    $class, implode(', ', array_unique($classFiles)), $classPrefix
                );
                $result = false;
            }
        }

        // Forbidden functions
        foreach ($functions as $func => $funcFiles) {
            foreach ($funcBlacklist as $pattern => $hint) {
                if (preg_match($pattern, $func)) {
                    $this->outf(
                        "Function '%s' not allowed at %s\n\tHint: %s",
                        $func, implode(', ', array_unique($funcFiles)), $hint
                    );
                    $result = false;
                }
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // PHP binary helpers
    // -------------------------------------------------------------------------

    private function procOpenAvailable(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = preg_split('@[,\s]+@', (string)ini_get('disable_functions'));
        return !in_array('proc_open', $disabled, true);
    }

    private function getPhpBinaries(): array
    {
        $paths  = [];
        $phpBin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
        $ver    = $this->getPhpVersion($phpBin);
        if ($ver === false && $phpBin !== 'php') {
            $phpBin = 'php';
            $ver    = $this->getPhpVersion('php');
        }
        if ($ver !== false) {
            $paths[$phpBin] = $ver;
        }

        if (!empty($this->params['php'])) {
            foreach (preg_split('@[\s;,]+@', $this->params['php']) as $bin) {
                $v = $this->getPhpVersion(trim($bin));
                if ($v !== false) {
                    $paths[trim($bin)] = $v;
                }
            }
        }
        return $paths;
    }

    /**
     * @return string|false
     */
    private function getPhpVersion(string $bin)
    {
        $exitCode = $this->execCommand(sprintf('%s -v', $bin), $stdout, $stderr);
        if ($exitCode === 0) {
            return implode("\n\t", array_filter($stdout, 'trim'));
        }
        return false;
    }

    private function execCommand(string $command, &$stdout, &$stderr): int
    {
        $stdout = [];
        $stderr = '';
        $spec   = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes  = [];

        $process = proc_open($command, $spec, $pipes);
        if (!is_resource($process)) {
            return -1;
        }

        $out    = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $exitCode = proc_close($process);
        $stdout   = preg_split("@[\r\n]+@", $out);
        return $exitCode;
    }

    // -------------------------------------------------------------------------
    // Compression
    // -------------------------------------------------------------------------

    private function compress(): void
    {
        $archivePath = $this->pluginPath . '/' . $this->pluginId . '.tar.gz';

        if (file_exists($archivePath)) {
            $mtime = max(filemtime($archivePath), filectime($archivePath));
            $this->outf('Removing previous archive from %s', date('Y-m-d H:i:s', $mtime));
            if (!unlink($archivePath)) {
                throw new RuntimeException("Cannot remove existing archive: {$archivePath}");
            }
        }

        // Write .files.md5
        $md5Path = $this->pluginPath . '/.files.md5';
        $this->md5Files($this->files, $md5Path);

        $time = microtime(true);

        // Archive_Tar uses GNU LongLink for paths >99 chars — compatible with all tar parsers.
        $archiveFiles = [[$this->pluginId . '/.files.md5', $md5Path]];
        foreach ($this->files as $file) {
            $archiveFiles[] = [$this->pluginId . '/' . $file, $this->pluginPath . '/' . $file];
        }
        $tar = new Archive_Tar($archivePath, 'gz');
        if (!$tar->create($archiveFiles)) {
            throw new RuntimeException("Archive_Tar failed to create: {$archivePath}");
        }
        unset($tar);

        if (file_exists($md5Path)) {
            unlink($md5Path);
        }

        $size = filesize($archivePath);
        $this->outf("\ntime\t%d ms\nsize\t%0.2f KByte", (int)((microtime(true) - $time) * 1000), $size / 1024);
        $this->outf("Archive: %s", $archivePath);
    }

    private function md5Files(array $files, string $outPath): int
    {
        $fp = fopen($outPath, 'w');
        if (!$fp) {
            throw new RuntimeException('Cannot create checksum file: ' . $outPath);
        }

        $count     = 0;
        $totalSize = 0;
        $time      = microtime(true);

        foreach ($files as $file) {
            $fullPath = $this->pluginPath . '/' . $file;
            if (file_exists($fullPath)) {
                $md5   = md5_file($fullPath);
                $size  = filesize($fullPath);
                $totalSize += $size;
                fprintf($fp, "%s *%s\n", $md5, $file);
                $this->outf("%5d\t%-10s\t%32s\t%s", ++$count, $size, $md5, $file);
            } else {
                $this->outf("%5d\t%-10s\t%32s\t%s", $count, '0', 'missed', $file);
            }
        }

        fclose($fp);
        $this->outf(
            "md5: %d ms\t%d files, %0.2f KBytes",
            (int)((microtime(true) - $time) * 1000),
            $count,
            $totalSize / 1024
        );

        return $count;
    }

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    public function out(string $msg = ''): void
    {
        echo $msg . "\n";
    }

    public function outf(string $fmt, ...$args): void
    {
        $this->out(vsprintf($fmt, $args));
    }

    // -------------------------------------------------------------------------
    // Help
    // -------------------------------------------------------------------------

    private function printHelp(): void
    {
        echo <<<'HELP'
Standalone App Plugin Compress Script — packages a Webasyst app plugin into {plugin_id}.tar.gz
No Webasyst framework required. Works standalone in CI/CD.

Usage:
  php compress-app-plugin.php <app_id>/plugins/<plugin_id> [options]

Arguments:
  app_id/plugins/plugin_id    App and plugin IDs, e.g. shop/plugins/sdekint

Options:
  -style  VALUE       Code check mode:
                        true        Check all PHP/JS/CSS code
                        false       Disabled (default)
                        no-vendors  Skip lib/vendors/ and js/vendors/
  -skip   VALUE       Operations to skip:
                        compress    Test only, no archive
                        test        Archive only, no checks
                        all         Skip everything (dry run listing)
                        none        Run everything (default)
  -php    PATH        Custom PHP binary for syntax check (e.g. /usr/bin/php8.2)
  -apps-dir DIR       Path to wa-apps/ directory (default: ./wa-apps/ beside script)
  -plugin-path DIR    Direct path to the plugin directory — overrides apps-dir + slug
                      Useful in CI/CD where the plugin repo is checked out directly.

Examples:
  # Standard local run
  php compress-app-plugin.php shop/plugins/sdekint

  # Test only (no archive)
  php compress-app-plugin.php shop/plugins/sdekint -skip compress

  # Archive only (skip tests)
  php compress-app-plugin.php shop/plugins/sdekint -skip test

  # CI/CD: plugin checked out at /workspace/sdekint/
  php compress-app-plugin.php shop/plugins/sdekint -plugin-path /workspace/sdekint

  # CI/CD: parent dir contains wa-apps/ tree
  php compress-app-plugin.php shop/plugins/sdekint -apps-dir /workspace/wa-apps

  # Dry run: list files that would be included
  php compress-app-plugin.php shop/plugins/sdekint -skip all

HELP;
    }
}

// =============================================================================
// Minimal PEAR stub — only what Archive_Tar needs
// =============================================================================
if (!class_exists('PEAR')) {
    class PEAR
    {
        public function __construct() {}
        public function __destruct() {}
        public static function loadExtension($ext)
        {
            if (!extension_loaded($ext) && function_exists('dl')) {
                @dl($ext . '.' . PHP_SHLIB_SUFFIX);
            }
        }
        public function raiseError($msg)
        {
            throw new RuntimeException($msg);
        }
    }
}

// =============================================================================
// Archive_Tar (PEAR) — embedded, no external dependencies
// Copyright (c) 1997-2008 Vincent Blavet <vincent@phpconcept.net>
// License: New BSD License
// =============================================================================
if (!function_exists('gzclose') && function_exists('gzclose64')) {
    function gzclose() { return call_user_func_array('gzclose64', func_get_args()); }
}
if (!function_exists('gzeof') && function_exists('gzeof64')) {
    function gzeof() { return call_user_func_array('gzeof64', func_get_args()); }
}
if (!function_exists('gzread') && function_exists('gzread64')) {
    function gzread() { return call_user_func_array('gzread64', func_get_args()); }
}
if (!function_exists('gzopen') && function_exists('gzopen64')) {
    function gzopen() { return call_user_func_array('gzopen64', func_get_args()); }
}
if (!function_exists('gzseek') && function_exists('gzseek64')) {
    function gzseek() { return call_user_func_array('gzseek64', func_get_args()); }
}
if (!function_exists('gztell') && function_exists('gztell64')) {
    function gztell() { return call_user_func_array('gztell64', func_get_args()); }
}
if (!function_exists('gzwrite') && function_exists('gzwrite64')) {
    function gzwrite() { return call_user_func_array('gzwrite64', func_get_args()); }
}
if (!function_exists('gzputs') && function_exists('gzputs64')) {
    function gzputs() { return call_user_func_array('gzputs64', func_get_args()); }
}

if (!defined('ARCHIVE_TAR_ATT_SEPARATOR')) {
    define('ARCHIVE_TAR_ATT_SEPARATOR', 90001);
    define('ARCHIVE_TAR_END_BLOCK', pack("a512", ''));
}

class Archive_Tar extends PEAR
{
    var $_tarname='';
    var $_compress=false;
    var $_compress_type='none';
    var $_separator=' ';
    var $_file=0;
    var $_temp_tarname='';
    var $_ignore_regexp='';

    function __construct($p_tarname, $p_compress = null)
    {
        parent::__construct();
        $this->_compress = false;
        $this->_compress_type = 'none';
        if (($p_compress === null) || ($p_compress == '')) {
            if (@file_exists($p_tarname)) {
                if ($fp = @fopen($p_tarname, "rb")) {
                    $data = fread($fp, 2);
                    fclose($fp);
                    if ($data == "\37\213") {
                        $this->_compress = true;
                        $this->_compress_type = 'gz';
                    } elseif ($data == "BZ") {
                        $this->_compress = true;
                        $this->_compress_type = 'bz2';
                    }
                }
            } else {
                if (substr($p_tarname, -2) == 'gz') {
                    $this->_compress = true;
                    $this->_compress_type = 'gz';
                } elseif ((substr($p_tarname, -3) == 'bz2') || (substr($p_tarname, -2) == 'bz')) {
                    $this->_compress = true;
                    $this->_compress_type = 'bz2';
                }
            }
        } else {
            if (($p_compress === true) || ($p_compress == 'gz')) {
                $this->_compress = true;
                $this->_compress_type = 'gz';
            } else if ($p_compress == 'bz2') {
                $this->_compress = true;
                $this->_compress_type = 'bz2';
            } else {
                $this->_error("Unsupported compression type '$p_compress'\n");
                return false;
            }
        }
        $this->_tarname = $p_tarname;
        if ($this->_compress) {
            $extname = ($this->_compress_type == 'gz') ? 'zlib' : 'bz2';
            if (!extension_loaded($extname)) {
                PEAR::loadExtension($extname);
            }
            if (!extension_loaded($extname)) {
                $this->_error("Extension '$extname' not found.");
                return false;
            }
        }
    }

    function __destruct()
    {
        $this->_close();
        if ($this->_temp_tarname != '')
            @unlink($this->_temp_tarname);
        parent::__destruct();
    }

    function create($p_filelist)
    {
        return $this->createModify($p_filelist, '', '');
    }

    function add($p_filelist)
    {
        return $this->addModify($p_filelist, '', '');
    }

    function createModify($p_filelist, $p_add_dir, $p_remove_dir='')
    {
        $v_result = true;
        if (!$this->_openWrite())
            return false;
        if ($p_filelist != '') {
            if (is_array($p_filelist))
                $v_list = $p_filelist;
            elseif (is_string($p_filelist))
                $v_list = explode($this->_separator, $p_filelist);
            else {
                $this->_cleanFile();
                $this->_error('Invalid file list');
                return false;
            }
            $v_result = $this->_addList($v_list, $p_add_dir, $p_remove_dir);
        }
        if ($v_result) {
            $this->_writeFooter();
            $this->_close();
        } else
            $this->_cleanFile();
        return $v_result;
    }

    function addModify($p_filelist, $p_add_dir, $p_remove_dir='')
    {
        $v_result = true;
        if (!$this->_isArchive())
            $v_result = $this->createModify($p_filelist, $p_add_dir, $p_remove_dir);
        else {
            if (is_array($p_filelist))
                $v_list = $p_filelist;
            elseif (is_string($p_filelist))
                $v_list = explode($this->_separator, $p_filelist);
            else {
                $this->_error('Invalid file list');
                return false;
            }
            $v_result = $this->_append($v_list, $p_add_dir, $p_remove_dir);
        }
        return $v_result;
    }

    function addString($p_filename, $p_string)
    {
        $v_result = true;
        if (!$this->_isArchive()) {
            if (!$this->_openWrite()) return false;
            $this->_close();
        }
        if (!$this->_openAppend()) return false;
        $v_result = $this->_addString($p_filename, $p_string);
        $this->_writeFooter();
        $this->_close();
        return $v_result;
    }

    function _error($p_message)
    {
        $this->raiseError($p_message);
    }

    function _warning($p_message)
    {
        $this->raiseError($p_message);
    }

    function _isArchive($p_filename=NULL)
    {
        if ($p_filename == NULL) $p_filename = $this->_tarname;
        clearstatcache();
        return @is_file($p_filename) && !@is_link($p_filename);
    }

    function _openWrite()
    {
        if ($this->_compress_type == 'gz')
            $this->_file = @gzopen($this->_tarname, "wb9");
        else if ($this->_compress_type == 'bz2')
            $this->_file = @bzopen($this->_tarname, "w");
        else if ($this->_compress_type == 'none')
            $this->_file = @fopen($this->_tarname, "wb");
        else
            $this->_error('Unknown compression type ('.$this->_compress_type.')');
        if ($this->_file == 0) {
            $this->_error('Unable to open in write mode \''.$this->_tarname.'\'');
            return false;
        }
        return true;
    }

    function _openRead()
    {
        if (strtolower(substr($this->_tarname, 0, 7)) == 'http://') {
            if ($this->_temp_tarname == '') {
                $this->_temp_tarname = uniqid('tar').'.tmp';
                if (!$v_file_from = @fopen($this->_tarname, 'rb')) {
                    $this->_error('Unable to open \''.$this->_tarname.'\'');
                    $this->_temp_tarname = '';
                    return false;
                }
                if (!$v_file_to = @fopen($this->_temp_tarname, 'wb')) {
                    $this->_error('Unable to open \''.$this->_temp_tarname.'\'');
                    $this->_temp_tarname = '';
                    return false;
                }
                while ($v_data = @fread($v_file_from, 1024))
                    @fwrite($v_file_to, $v_data);
                @fclose($v_file_from);
                @fclose($v_file_to);
            }
            $v_filename = $this->_temp_tarname;
        } else {
            $v_filename = $this->_tarname;
        }
        if ($this->_compress_type == 'gz')
            $this->_file = @gzopen($v_filename, "rb");
        else if ($this->_compress_type == 'bz2')
            $this->_file = @bzopen($v_filename, "r");
        else if ($this->_compress_type == 'none')
            $this->_file = @fopen($v_filename, "rb");
        else
            $this->_error('Unknown compression type ('.$this->_compress_type.')');
        if ($this->_file == 0) {
            $this->_error('Unable to open in read mode \''.$v_filename.'\'');
            return false;
        }
        return true;
    }

    function _close()
    {
        if (is_resource($this->_file)) {
            if ($this->_compress_type == 'gz')       @gzclose($this->_file);
            else if ($this->_compress_type == 'bz2') @bzclose($this->_file);
            else if ($this->_compress_type == 'none') @fclose($this->_file);
            $this->_file = 0;
        }
        if ($this->_temp_tarname != '') {
            @unlink($this->_temp_tarname);
            $this->_temp_tarname = '';
        }
        return true;
    }

    function _cleanFile()
    {
        $this->_close();
        if ($this->_temp_tarname != '') {
            @unlink($this->_temp_tarname);
            $this->_temp_tarname = '';
        } else {
            @unlink($this->_tarname);
        }
        $this->_tarname = '';
        return true;
    }

    function _writeBlock($p_binary_data, $p_len=null)
    {
        if (is_resource($this->_file)) {
            if ($p_len === null) {
                if ($this->_compress_type == 'gz')       @gzputs($this->_file, $p_binary_data);
                else if ($this->_compress_type == 'bz2') @bzwrite($this->_file, $p_binary_data);
                else if ($this->_compress_type == 'none') @fputs($this->_file, $p_binary_data);
            } else {
                if ($this->_compress_type == 'gz')       @gzputs($this->_file, $p_binary_data, $p_len);
                else if ($this->_compress_type == 'bz2') @bzwrite($this->_file, $p_binary_data, $p_len);
                else if ($this->_compress_type == 'none') @fputs($this->_file, $p_binary_data, $p_len);
            }
        }
        return true;
    }

    function _readBlock()
    {
        $v_block = null;
        if (is_resource($this->_file)) {
            if ($this->_compress_type == 'gz')       $v_block = @gzread($this->_file, 512);
            else if ($this->_compress_type == 'bz2') $v_block = @bzread($this->_file, 512);
            else if ($this->_compress_type == 'none') $v_block = @fread($this->_file, 512);
        }
        return $v_block;
    }

    function _jumpBlock($p_len=null)
    {
        if (is_resource($this->_file)) {
            if ($p_len === null) $p_len = 1;
            if ($this->_compress_type == 'gz')
                @gzseek($this->_file, gztell($this->_file)+($p_len*512));
            else if ($this->_compress_type == 'bz2')
                for ($i=0; $i<$p_len; $i++) $this->_readBlock();
            else if ($this->_compress_type == 'none')
                @fseek($this->_file, $p_len*512, SEEK_CUR);
        }
        return true;
    }

    function _writeFooter()
    {
        if (is_resource($this->_file)) {
            $this->_writeBlock(pack('a1024', ''));
        }
        return true;
    }

    function _addList($p_list, $p_add_dir, $p_remove_dir)
    {
        $v_result = true;
        $v_header = array();
        $p_add_dir    = $this->_translateWinPath($p_add_dir);
        $p_remove_dir = $this->_translateWinPath($p_remove_dir, false);
        if (!$this->_file) {
            $this->_error('Invalid file descriptor');
            return false;
        }
        if (sizeof($p_list) == 0) return true;
        foreach ($p_list as $v_filename) {
            if (!$v_result) break;
            $p_as_filename = null;
            if (is_array($v_filename)) {
                @list($p_as_filename, $v_filename) = $v_filename;
            }
            if ($v_filename == $this->_tarname) continue;
            if ($v_filename == '') continue;
            if ($this->_ignore_regexp && preg_match($this->_ignore_regexp, '/'.$v_filename)) {
                $this->_warning("File '$v_filename' ignored");
                continue;
            }
            if (!file_exists($v_filename)) {
                $this->_warning("File '$v_filename' does not exist");
                continue;
            }
            if (!$this->_addFile($v_filename, $v_header, $p_add_dir, $p_remove_dir, $p_as_filename))
                return false;
            if (@is_dir($v_filename) && !@is_link($v_filename)) {
                if (!($p_hdir = opendir($v_filename))) {
                    $this->_warning("Directory '$v_filename' can not be read");
                    continue;
                }
                while (false !== ($p_hitem = readdir($p_hdir))) {
                    if (($p_hitem != '.') && ($p_hitem != '..')) {
                        $p_temp_list[0] = ($v_filename != ".") ? $v_filename.'/'.$p_hitem : $p_hitem;
                        $v_result = $this->_addList($p_temp_list, $p_add_dir, $p_remove_dir);
                    }
                }
                unset($p_temp_list, $p_hdir, $p_hitem);
            }
        }
        return $v_result;
    }

    function _addFile($p_filename, &$p_header, $p_add_dir, $p_remove_dir, $p_as_filename = null)
    {
        if (!$this->_file) { $this->_error('Invalid file descriptor'); return false; }
        if ($p_filename == '') { $this->_error('Invalid file name'); return false; }
        $p_filename = $this->_translateWinPath($p_filename, false);
        $v_stored_filename = $p_as_filename ? $this->_translateWinPath($p_as_filename, false) : $p_filename;
        if (strcmp($p_filename, $p_remove_dir) == 0) return true;
        if ($p_remove_dir != '') {
            if (substr($p_remove_dir, -1) != '/') $p_remove_dir .= '/';
            if (substr($p_filename, 0, strlen($p_remove_dir)) == $p_remove_dir)
                $v_stored_filename = substr($p_filename, strlen($p_remove_dir));
        }
        $v_stored_filename = $this->_translateWinPath($v_stored_filename);
        if ($p_add_dir != '') {
            $v_stored_filename = (substr($p_add_dir, -1) == '/')
                ? $p_add_dir.$v_stored_filename
                : $p_add_dir.'/'.$v_stored_filename;
        }
        $v_stored_filename = $this->_pathReduction($v_stored_filename);
        if ($this->_isArchive($p_filename)) {
            if (($v_file = @fopen($p_filename, "rb")) == 0) {
                $this->_warning("Unable to open file '".$p_filename."' in binary read mode");
                return true;
            }
            if (!$this->_writeHeader($p_filename, $v_stored_filename)) return false;
            while (($v_buffer = fread($v_file, 512)) != '') {
                $this->_writeBlock(pack("a512", "$v_buffer"));
            }
            fclose($v_file);
        } else {
            if (!$this->_writeHeader($p_filename, $v_stored_filename)) return false;
        }
        return true;
    }

    function _addString($p_filename, $p_string)
    {
        if (!$this->_file) { $this->_error('Invalid file descriptor'); return false; }
        if ($p_filename == '') { $this->_error('Invalid file name'); return false; }
        $p_filename = $this->_translateWinPath($p_filename, false);
        if (!$this->_writeHeaderBlock($p_filename, strlen($p_string), time(), 384, "", 0, 0))
            return false;
        $i = 0;
        while (($v_buffer = substr($p_string, (($i++)*512), 512)) != '') {
            $this->_writeBlock(pack("a512", $v_buffer));
        }
        return true;
    }

    function _writeHeader($p_filename, $p_stored_filename)
    {
        if ($p_stored_filename == '') $p_stored_filename = $p_filename;
        $v_reduce_filename = $this->_pathReduction($p_stored_filename);
        if (strlen($v_reduce_filename) > 99) {
            if (!$this->_writeLongHeader($v_reduce_filename)) return false;
        }
        $v_info  = lstat($p_filename);
        $v_uid   = sprintf("%07s", DecOct($v_info[4]));
        $v_gid   = sprintf("%07s", DecOct($v_info[5]));
        $v_perms = sprintf("%07s", DecOct($v_info['mode'] & 000777));
        $v_mtime = sprintf("%011s", DecOct($v_info['mtime']));
        $v_linkname = '';
        if (@is_link($p_filename)) {
            $v_typeflag = '2';
            $v_linkname = readlink($p_filename);
            $v_size = sprintf("%011s", DecOct(0));
        } elseif (@is_dir($p_filename)) {
            $v_typeflag = "5";
            $v_size = sprintf("%011s", DecOct(0));
        } else {
            $v_typeflag = '0';
            clearstatcache();
            $v_size = sprintf("%011s", DecOct($v_info['size']));
        }
        $v_magic = 'ustar ';
        $v_version = ' ';
        if (function_exists('posix_getpwuid')) {
            $userinfo  = posix_getpwuid($v_info[4]);
            $groupinfo = posix_getgrgid($v_info[5]);
            $v_uname = $userinfo['name'];
            $v_gname = $groupinfo['name'];
        } else {
            $v_uname = '';
            $v_gname = '';
        }
        $v_binary_data_first = pack("a100a8a8a8a12a12",
            $v_reduce_filename, $v_perms, $v_uid, $v_gid, $v_size, $v_mtime);
        $v_binary_data_last = pack("a1a100a6a2a32a32a8a8a155a12",
            $v_typeflag, $v_linkname, $v_magic, $v_version, $v_uname, $v_gname, '', '', '', '');
        $v_checksum = 0;
        for ($i=0; $i<148; $i++) $v_checksum += ord(substr($v_binary_data_first,$i,1));
        for ($i=148; $i<156; $i++) $v_checksum += ord(' ');
        for ($i=156, $j=0; $i<512; $i++, $j++) $v_checksum += ord(substr($v_binary_data_last,$j,1));
        $this->_writeBlock($v_binary_data_first, 148);
        $this->_writeBlock(pack("a8", sprintf("%06s ", DecOct($v_checksum))), 8);
        $this->_writeBlock($v_binary_data_last, 356);
        return true;
    }

    function _writeHeaderBlock($p_filename, $p_size, $p_mtime=0, $p_perms=0,
                               $p_type='', $p_uid=0, $p_gid=0)
    {
        $p_filename = $this->_pathReduction($p_filename);
        if (strlen($p_filename) > 99) {
            if (!$this->_writeLongHeader($p_filename)) return false;
        }
        $v_size  = ($p_type == "5") ? sprintf("%011s", DecOct(0)) : sprintf("%011s", DecOct($p_size));
        $v_uid   = sprintf("%07s", DecOct($p_uid));
        $v_gid   = sprintf("%07s", DecOct($p_gid));
        $v_perms = sprintf("%07s", DecOct($p_perms & 000777));
        $v_mtime = sprintf("%11s", DecOct($p_mtime));
        if (function_exists('posix_getpwuid')) {
            $v_uname = posix_getpwuid($p_uid)['name'] ?? '';
            $v_gname = posix_getgrgid($p_gid)['name'] ?? '';
        } else {
            $v_uname = '';
            $v_gname = '';
        }
        $v_binary_data_first = pack("a100a8a8a8a12A12",
            $p_filename, $v_perms, $v_uid, $v_gid, $v_size, $v_mtime);
        $v_binary_data_last = pack("a1a100a6a2a32a32a8a8a155a12",
            $p_type, '', 'ustar ', ' ', $v_uname, $v_gname, '', '', '', '');
        $v_checksum = 0;
        for ($i=0; $i<148; $i++) $v_checksum += ord(substr($v_binary_data_first,$i,1));
        for ($i=148; $i<156; $i++) $v_checksum += ord(' ');
        for ($i=156, $j=0; $i<512; $i++, $j++) $v_checksum += ord(substr($v_binary_data_last,$j,1));
        $this->_writeBlock($v_binary_data_first, 148);
        $this->_writeBlock(pack("a8", sprintf("%06s ", DecOct($v_checksum))), 8);
        $this->_writeBlock($v_binary_data_last, 356);
        return true;
    }

    function _writeLongHeader($p_filename)
    {
        $v_size = sprintf("%11s ", DecOct(strlen($p_filename)));
        $v_binary_data_first = pack("a100a8a8a8a12a12", '././@LongLink', 0, 0, 0, $v_size, 0);
        $v_binary_data_last  = pack("a1a100a6a2a32a32a8a8a155a12", 'L', '', '', '', '', '', '', '', '', '');
        $v_checksum = 0;
        for ($i=0; $i<148; $i++) $v_checksum += ord(substr($v_binary_data_first,$i,1));
        for ($i=148; $i<156; $i++) $v_checksum += ord(' ');
        for ($i=156, $j=0; $i<512; $i++, $j++) $v_checksum += ord(substr($v_binary_data_last,$j,1));
        $this->_writeBlock($v_binary_data_first, 148);
        $this->_writeBlock(pack("a8", sprintf("%06s ", DecOct($v_checksum))), 8);
        $this->_writeBlock($v_binary_data_last, 356);
        $i = 0;
        while (($v_buffer = substr($p_filename, (($i++)*512), 512)) != '') {
            $this->_writeBlock(pack("a512", "$v_buffer"));
        }
        return true;
    }

    function _append($p_filelist, $p_add_dir='', $p_remove_dir='')
    {
        if (!$this->_openAppend()) return false;
        if ($this->_addList($p_filelist, $p_add_dir, $p_remove_dir))
            $this->_writeFooter();
        $this->_close();
        return true;
    }

    function _openAppend()
    {
        if (filesize($this->_tarname) == 0) return $this->_openWrite();
        if ($this->_compress) {
            $this->_close();
            if (!@rename($this->_tarname, $this->_tarname.".tmp")) {
                $this->_error('Error while renaming to tmp');
                return false;
            }
            if ($this->_compress_type == 'gz')
                $v_temp_tar = @gzopen($this->_tarname.".tmp", "rb");
            elseif ($this->_compress_type == 'bz2')
                $v_temp_tar = @bzopen($this->_tarname.".tmp", "r");
            if ($v_temp_tar == 0) {
                $this->_error('Unable to open tmp file');
                @rename($this->_tarname.".tmp", $this->_tarname);
                return false;
            }
            if (!$this->_openWrite()) {
                @rename($this->_tarname.".tmp", $this->_tarname);
                return false;
            }
            if ($this->_compress_type == 'gz') {
                while (!@gzeof($v_temp_tar)) {
                    $v_buffer = @gzread($v_temp_tar, 512);
                    if ($v_buffer == ARCHIVE_TAR_END_BLOCK) continue;
                    $this->_writeBlock(pack("a512", $v_buffer));
                }
                @gzclose($v_temp_tar);
            } elseif ($this->_compress_type == 'bz2') {
                while (strlen($v_buffer = @bzread($v_temp_tar, 512)) > 0) {
                    if ($v_buffer == ARCHIVE_TAR_END_BLOCK) continue;
                    $this->_writeBlock(pack("a512", $v_buffer));
                }
                @bzclose($v_temp_tar);
            }
            if (!@unlink($this->_tarname.".tmp"))
                $this->_error('Error deleting tmp file');
        } else {
            if (!$this->_openReadWrite()) return false;
            clearstatcache();
            $v_size = filesize($this->_tarname);
            fseek($this->_file, $v_size - 1024);
            if (fread($this->_file, 512) == ARCHIVE_TAR_END_BLOCK)
                fseek($this->_file, $v_size - 1024);
            elseif (fread($this->_file, 512) == ARCHIVE_TAR_END_BLOCK)
                fseek($this->_file, $v_size - 512);
        }
        return true;
    }

    function _openReadWrite()
    {
        if ($this->_compress_type == 'gz')
            $this->_file = @gzopen($this->_tarname, "r+b");
        else if ($this->_compress_type == 'bz2') {
            $this->_error('Unable to open bz2 in read/write mode');
            return false;
        } else if ($this->_compress_type == 'none')
            $this->_file = @fopen($this->_tarname, "r+b");
        if ($this->_file == 0) {
            $this->_error('Unable to open in read/write mode \''.$this->_tarname.'\'');
            return false;
        }
        return true;
    }

    function _dirCheck($p_dir)
    {
        clearstatcache();
        if ((@is_dir($p_dir)) || ($p_dir == '')) return true;
        $p_parent_dir = dirname($p_dir);
        if (($p_parent_dir != $p_dir) && ($p_parent_dir != '') && (!$this->_dirCheck($p_parent_dir)))
            return false;
        if (!@mkdir($p_dir, 0777)) {
            $this->_error("Unable to create directory '$p_dir'");
            return false;
        }
        return true;
    }

    function _pathReduction($p_dir)
    {
        $v_result = '';
        if ($p_dir != '') {
            $v_list = explode('/', $p_dir);
            for ($i=sizeof($v_list)-1; $i>=0; $i--) {
                if ($v_list[$i] == ".") {
                    // ignore
                } else if ($v_list[$i] == "..") {
                    $i--;
                } else if ($v_list[$i] == '' && $i != (sizeof($v_list)-1) && $i != 0) {
                    // ignore double slashes
                } else {
                    $v_result = $v_list[$i].($i != (sizeof($v_list)-1) ? '/'.$v_result : '');
                }
            }
        }
        return strtr($v_result, '\\', '/');
    }

    function _translateWinPath($p_path, $p_remove_disk_letter=true)
    {
        if (defined('OS_WINDOWS') && OS_WINDOWS) {
            if ($p_remove_disk_letter && (($v_position = strpos($p_path, ':')) != false))
                $p_path = substr($p_path, $v_position+1);
            if ((strpos($p_path, '\\') > 0) || (substr($p_path, 0, 1) == '\\'))
                $p_path = strtr($p_path, '\\', '/');
        }
        return $p_path;
    }
}

// Entry point
try {
    (new AppPluginCompressor($argv))->run();
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n\n");
    exit(1);
}
