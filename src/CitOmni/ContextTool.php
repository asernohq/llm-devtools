<?php
declare(strict_types=1);
/*
 * This file is part of Aserno LLM DevTools.
 * Small, pragmatic tools for LLM-assisted coding workflows.
 *
 * For more information, visit https://github.com/asernohq/llm-devtools
 *
 * Copyright (c) 2026-present Aserno ApS
 * SPDX-License-Identifier: MIT
 *
 * For full copyright and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace Aserno\LlmDevTools\CitOmni;

/**
 * Extract compact CitOmni source context for LLM prompts.
 *
 * Behavior:
 * - Scans local CitOmni package folders.
 * - Resolves services, routes, and commands from Registry.php.
 * - Selects files by package, layer, class, service id, route, command, or search text.
 * - Prints Markdown with fenced PHP blocks for direct copy/paste into LLM chats.
 * - Supports context views optimized for token economy.
 *
 * Notes:
 * - This tool is read-only.
 * - It does not boot CitOmni or execute package code.
 * - Registry.php is used as lookup input, but is only included in output with --with-registry.
 */
final class ContextTool {

	private const DEFAULT_ROOT = 'C:\\dev\\www\\citomni';
	
	private const USAGE_LOG_PATH = 'C:\\dev\\var\\logs\\context-tool-usage.jsonl';

	private const LAYERS = [
		'Boot' => ['src/Boot/Registry.php'],
		'Command' => ['src/Command/*.php'],
		'Controller' => ['src/Controller/*.php'],
		'Enum' => ['src/Enum/*.php'],
		'Exception' => ['src/Exception/*.php'],
		'Operation' => ['src/Operation/*.php'],
		'Policy' => ['src/Policy/*.php'],
		'Repository' => ['src/Repository/*.php'],
		'Service' => ['src/Service/*.php'],
		'State' => ['src/State/*.php'],
		'Support' => ['src/Support/*.php'],
		'Util' => ['src/Util/*.php'],
	];

	private const BUNDLES = [
		'http-boot' => [
			['package' => 'http-skeleton', 'path' => 'public/index.php', 'min_view' => 'logic'],

			['package' => 'http', 'path' => 'src/Kernel.php'],

			['package' => 'kernel', 'path' => 'src/App.php'],
			['package' => 'kernel', 'path' => 'src/Mode.php'],
			['package' => 'kernel', 'path' => 'src/Arr.php'],
			['package' => 'kernel', 'path' => 'src/Cfg.php'],
			['package' => 'kernel', 'path' => 'src/Runtime.php'],
			['package' => 'kernel', 'path' => 'src/Service/BaseService.php'],

			['package' => 'http', 'path' => 'src/Boot/Registry.php'],
			['package' => 'http', 'path' => 'src/Service/ErrorHandler.php'],
			['package' => 'http', 'path' => 'src/Service/Maintenance.php'],
			['package' => 'http', 'path' => 'src/Service/Router.php'],

			['package' => 'kernel', 'path' => 'src/Controller/BaseController.php', 'optional' => true],
		],
		'cli-boot' => [
			['package' => 'http-skeleton', 'path' => 'bin/citomni', 'min_view' => 'logic'],

			['package' => 'cli', 'path' => 'src/Kernel.php'],

			['package' => 'kernel', 'path' => 'src/App.php'],
			['package' => 'kernel', 'path' => 'src/Mode.php'],
			['package' => 'kernel', 'path' => 'src/Arr.php'],
			['package' => 'kernel', 'path' => 'src/Cfg.php'],
			['package' => 'kernel', 'path' => 'src/Runtime.php'],
			['package' => 'kernel', 'path' => 'src/Service/BaseService.php'],

			['package' => 'cli', 'path' => 'src/Boot/Registry.php'],
			['package' => 'cli', 'path' => 'src/Service/ErrorHandler.php'],
			['package' => 'cli', 'path' => 'src/Service/Runner.php'],

			['package' => 'kernel', 'path' => 'src/Command/BaseCommand.php'],
			['package' => 'kernel', 'path' => 'src/Command/ArgvParser.php'],
			['package' => 'kernel', 'path' => 'src/Command/HelpFormatter.php'],
		],
	];

	/** @var array<string,string|bool> */
	private array $options = [];

	/** @var array<string,array<string,string>> */
	private array $fileMeta = [];
	
	/** @var int */
	private int $startedAtNs = 0;

	/**
	 * Run the context extractor.
	 *
	 * @param array<int,string> $argv CLI arguments.
	 * @return int Process exit code.
	 */
	public function run(array $argv): int {
		$this->startedAtNs = \hrtime(true);
		$this->options = $this->parseOptions($argv);

		$root = '';
		$files = [];
		$exitCode = 1;
		$status = 'unknown';

		try {
			if ($this->hasFlag('help')) {
				$this->printHelp();

				$exitCode = 0;
				$status = 'help';

				return $exitCode;
			}

			$root = $this->normalizePath((string)($this->options['root'] ?? self::DEFAULT_ROOT));

			if (!\is_dir($root)) {
				\fwrite(STDERR, "Root path not found: {$root}\n");

				$status = 'root_not_found';

				return $exitCode;
			}

			$bundle = (string)($this->options['bundle'] ?? '');

			if ($bundle !== '') {
				$files = $this->selectBundleFiles($root, $bundle);
			} else {
				$packages = $this->resolvePackages($root);

				if ($packages === []) {
					\fwrite(STDERR, "No matching packages found.\n");

					$status = 'no_matching_packages';

					return $exitCode;
				}

				$classIndex = $this->buildClassIndex($packages);
				$files = $this->selectFiles($packages, $classIndex);
			}

			if ($files === []) {
				\fwrite(STDERR, "No matching files found.\n");

				$status = 'no_matching_files';

				return $exitCode;
			}

			if (!isset($this->options['bundle'])) {
				\sort($files, \SORT_STRING);
			}

			$markdown = $this->renderMarkdown($root, $files);
			$out = (string)($this->options['out'] ?? '');

			if ($out !== '') {
				$target = $this->normalizePath($out);
				$dir = \dirname($target);

				if (!\is_dir($dir)) {
					\fwrite(STDERR, "Output directory not found: {$dir}\n");

					$status = 'output_directory_not_found';

					return $exitCode;
				}

				\file_put_contents($target, $markdown);
				\fwrite(STDOUT, "Wrote context to {$target}\n");

				$exitCode = 0;
				$status = 'wrote_file';

				return $exitCode;
			}

			\fwrite(STDOUT, $markdown);

			$exitCode = 0;
			$status = 'wrote_stdout';

			return $exitCode;
		} finally {
			$this->writeUsageLog($root, $files, $exitCode, $status);
		}
	}

	/**
	 * Parse command line options.
	 *
	 * @param array<int,string> $argv CLI arguments.
	 * @return array<string,string|bool> Parsed options.
	 */
	private function parseOptions(array $argv): array {
		$options = [];

		foreach (\array_slice($argv, 1) as $arg) {
			if ($arg === '--help' || $arg === '-h') {
				$options['help'] = true;
				continue;
			}

			if (!\str_starts_with($arg, '--')) {
				$options['package'] = $arg;
				continue;
			}

			$arg = \substr($arg, 2);
			$pos = \strpos($arg, '=');

			if ($pos === false) {
				$options[$arg] = true;
				continue;
			}

			$key = \substr($arg, 0, $pos);
			$value = \substr($arg, $pos + 1);

			$options[$key] = $value;
		}

		return $options;
	}

	/**
	 * Check whether a boolean flag is enabled.
	 *
	 * @param string $name Option name.
	 * @return bool True when enabled.
	 */
	private function hasFlag(string $name): bool {
		return ($this->options[$name] ?? false) === true;
	}

	/**
	 * Resolve package directories to scan.
	 *
	 * @param string $root CitOmni packages root.
	 * @return array<string,string> Package name to absolute path.
	 */
	private function resolvePackages(string $root): array {
		$package = (string)($this->options['package'] ?? '');

		if ($package !== '') {
			$package = \trim($package, "\\/ \t\n\r\0\x0B");
			$path = $root . DIRECTORY_SEPARATOR . $package;

			return \is_dir($path) ? [$package => $path] : [];
		}

		$packages = [];

		foreach ((array)\glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $path) {
			$name = \basename($path);

			if ($name === 'tools') {
				continue;
			}

			if (\is_dir($path . DIRECTORY_SEPARATOR . 'src')) {
				$packages[$name] = $path;
			}
		}

		return $packages;
	}

	/**
	 * Build a simple FQCN to file index.
	 *
	 * @param array<string,string> $packages Package paths.
	 * @return array<string,string> FQCN to file path.
	 */
	private function buildClassIndex(array $packages): array {
		$index = [];

		foreach ($packages as $packagePath) {
			foreach ($this->findAllPhpFiles($packagePath) as $file) {
				$fqcn = $this->readFqcn($file);

				if ($fqcn !== '') {
					$index[$fqcn] = $file;
				}
			}
		}

		return $index;
	}

	/**
	 * Select files based on CLI options.
	 *
	 * @param array<string,string> $packages Package paths.
	 * @param array<string,string> $classIndex FQCN to file path.
	 * @return array<int,string> Selected file paths.
	 */
	private function selectFiles(array $packages, array $classIndex): array {
		$selected = [];
		$hasTargetSelector = $this->hasTargetSelector();

		foreach ($packages as $packagePath) {
			$registry = $packagePath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Boot' . DIRECTORY_SEPARATOR . 'Registry.php';

			if (\is_file($registry) && $this->hasFlag('with-registry')) {
				$selected[$registry] = $registry;
			}

			if ($hasTargetSelector) {
				foreach ($this->resolveRegistryTargets($registry, $classIndex) as $file) {
					$selected[$file] = $file;
				}

				continue;
			}

			foreach ($this->findLayerFiles($packagePath) as $file) {
				if (!$this->hasFlag('with-registry') && \str_ends_with(\str_replace('\\', '/', $file), '/src/Boot/Registry.php')) {
					continue;
				}

				$selected[$file] = $file;
			}
		}

		$selected = $this->filterByClass($selected);
		$selected = $this->filterByContains($selected);

		$maxFiles = (int)($this->options['max-files'] ?? 80);

		if ($maxFiles > 0 && \count($selected) > $maxFiles) {
			$selected = \array_slice($selected, 0, $maxFiles, true);
		}

		return \array_values($selected);
	}

	/**
	 * Determine whether a Registry-based target selector is used.
	 *
	 * @return bool True when service, route, or command is requested.
	 */
	private function hasTargetSelector(): bool {
		return isset($this->options['service']) || isset($this->options['route']) || isset($this->options['command']);
	}

	/**
	 * Select files from a named bundle.
	 *
	 * @param string $root CitOmni packages root.
	 * @param string $bundle Bundle name.
	 * @return array<int,string> Selected file paths.
	 */
	private function selectBundleFiles(string $root, string $bundle): array {
		$bundle = \strtolower(\trim($bundle));

		if (!isset(self::BUNDLES[$bundle])) {
			\fwrite(STDERR, "Unknown bundle: {$bundle}\n");
			\fwrite(STDERR, "Known bundles: " . \implode(', ', \array_keys(self::BUNDLES)) . "\n");
			return [];
		}

		$files = [];

		foreach (self::BUNDLES[$bundle] as $entry) {
			$file = $this->resolveBundleEntryPath($root, $entry);
			$optional = (bool)($entry['optional'] ?? false);

			if ($file === '') {
				continue;
			}

			if (!\is_file($file)) {
				if (!$optional) {
					\fwrite(STDERR, "Bundle file not found: {$file}\n");
				}

				continue;
			}

			$files[] = $file;

			if (isset($entry['view']) && \is_string($entry['view']) && $entry['view'] !== '') {
				$this->fileMeta[$file]['view'] = \strtolower($entry['view']);
			}

			if (isset($entry['min_view']) && \is_string($entry['min_view']) && $entry['min_view'] !== '') {
				$this->fileMeta[$file]['min_view'] = \strtolower($entry['min_view']);
			}
		}

		return $files;
	}

	/**
	 * Resolve a bundle entry to an absolute file path.
	 *
	 * @param string $root CitOmni packages root.
	 * @param array<string,mixed> $entry Bundle entry.
	 * @return string Absolute file path.
	 */
	private function resolveBundleEntryPath(string $root, array $entry): string {
		$path = (string)($entry['path'] ?? '');

		if ($path === '') {
			return '';
		}

		if ($this->isAbsolutePath($path)) {
			return $this->normalizePath($path);
		}

		$package = (string)($entry['package'] ?? '');

		if ($package !== '') {
			return $this->normalizePath($root . DIRECTORY_SEPARATOR . $package . DIRECTORY_SEPARATOR . $path);
		}

		return $this->normalizePath($root . DIRECTORY_SEPARATOR . $path);
	}

	/**
	 * Check whether a path is absolute on Windows or POSIX.
	 *
	 * @param string $path Input path.
	 * @return bool True when absolute.
	 */
	private function isAbsolutePath(string $path): bool {
		return \preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || \str_starts_with($path, '/') || \str_starts_with($path, '\\\\');
	}

	/**
	 * Resolve files referenced by selected Registry keys.
	 *
	 * @param string $registry Registry file path.
	 * @param array<string,string> $classIndex FQCN to file path.
	 * @return array<int,string> Matching file paths.
	 */
	private function resolveRegistryTargets(string $registry, array $classIndex): array {
		if (!\is_file($registry)) {
			return [];
		}

		$code = (string)\file_get_contents($registry);
		$aliases = $this->extractUseAliases($code);
		$targets = [];

		$selectors = [
			'service' => ['MAP_HTTP', 'MAP_CLI'],
			'route' => ['ROUTES_HTTP'],
			'command' => ['COMMANDS_CLI'],
		];

		foreach ($selectors as $option => $constants) {
			$key = (string)($this->options[$option] ?? '');

			if ($key === '') {
				continue;
			}

			foreach ($constants as $constant) {
				$arraySnippet = $this->findConstantArraySnippet($code, $constant);

				if ($arraySnippet === '') {
					continue;
				}

				$itemSnippet = $this->findArrayItemSnippet($arraySnippet, $key);

				if ($itemSnippet === '') {
					continue;
				}

				foreach ($this->extractClassRefs($itemSnippet, $aliases) as $fqcn) {
					if (isset($classIndex[$fqcn])) {
						$targets[$classIndex[$fqcn]] = $classIndex[$fqcn];
					}
				}
			}
		}

		return \array_values($targets);
	}

	/**
	 * Find the array assigned to a Registry constant.
	 *
	 * @param string $code PHP source code.
	 * @param string $constant Registry constant name.
	 * @return string Constant array snippet, or empty string.
	 */
	private function findConstantArraySnippet(string $code, string $constant): string {
		$pattern = '/\bpublic\s+const\s+' . \preg_quote($constant, '/') . '\s*=\s*\[/';

		if (\preg_match($pattern, $code, $match, PREG_OFFSET_CAPTURE) !== 1) {
			return '';
		}

		$startPos = \strpos($code, '[', (int)$match[0][1]);

		if ($startPos === false) {
			return '';
		}

		$endPos = $this->findBracketEnd($code, $startPos);

		if ($endPos === null) {
			return '';
		}

		return \substr($code, $startPos, $endPos - $startPos + 1);
	}

	/**
	 * Find PHP files for requested layers.
	 *
	 * @param string $packagePath Package path.
	 * @return array<int,string> File paths.
	 */
	private function findLayerFiles(string $packagePath): array {
		$layers = $this->resolveLayers();
		$files = [];

		foreach ($layers as $layer) {
			foreach (self::LAYERS[$layer] as $pattern) {
				$pathPattern = $packagePath . DIRECTORY_SEPARATOR . \str_replace('/', DIRECTORY_SEPARATOR, $pattern);

				foreach ((array)\glob($pathPattern) as $file) {
					if (\is_file($file)) {
						$files[$file] = $file;
					}
				}
			}
		}

		return \array_values($files);
	}

	/**
	 * Resolve requested layers.
	 *
	 * @return array<int,string> Layer names.
	 */
	private function resolveLayers(): array {
		$raw = (string)($this->options['layer'] ?? '');

		if ($raw === '') {
			return \array_keys(self::LAYERS);
		}

		$wanted = [];
		$lookup = [];

		foreach (\array_keys(self::LAYERS) as $layer) {
			$lookup[\strtolower($layer)] = $layer;
		}

		foreach (\explode(',', $raw) as $part) {
			$key = \strtolower(\trim($part));

			if ($key !== '' && isset($lookup[$key])) {
				$wanted[] = $lookup[$key];
			}
		}

		return $wanted;
	}

	/**
	 * Find all PHP files under known CitOmni layer folders.
	 *
	 * @param string $packagePath Package path.
	 * @return array<int,string> File paths.
	 */
	private function findAllPhpFiles(string $packagePath): array {
		$files = [];

		foreach (self::LAYERS as $patterns) {
			foreach ($patterns as $pattern) {
				$pathPattern = $packagePath . DIRECTORY_SEPARATOR . \str_replace('/', DIRECTORY_SEPARATOR, $pattern);

				foreach ((array)\glob($pathPattern) as $file) {
					if (\is_file($file)) {
						$files[$file] = $file;
					}
				}
			}
		}

		return \array_values($files);
	}

	/**
	 * Filter selected files by class name or FQCN.
	 *
	 * @param array<string,string> $selected Selected files.
	 * @return array<string,string> Filtered files.
	 */
	private function filterByClass(array $selected): array {
		$class = (string)($this->options['class'] ?? '');

		if ($class === '') {
			return $selected;
		}

		$class = \ltrim($class, '\\');
		$filtered = [];

		foreach ($selected as $file) {
			$fqcn = \ltrim($this->readFqcn($file), '\\');
			$short = \basename(\str_replace('\\', '/', $fqcn));

			if (\strcasecmp($fqcn, $class) === 0 || \strcasecmp($short, $class) === 0) {
				$filtered[$file] = $file;
			}
		}

		return $filtered;
	}

	/**
	 * Filter selected files by search text.
	 *
	 * @param array<string,string> $selected Selected files.
	 * @return array<string,string> Filtered files.
	 */
	private function filterByContains(array $selected): array {
		$needle = (string)($this->options['contains'] ?? '');

		if ($needle === '') {
			return $selected;
		}

		$filtered = [];

		foreach ($selected as $file) {
			$code = (string)\file_get_contents($file);

			if (\stripos($file, $needle) !== false || \stripos($code, $needle) !== false) {
				$filtered[$file] = $file;
			}
		}

		return $filtered;
	}

	/**
	 * Render selected files as Markdown.
	 *
	 * @param string $root Root path.
	 * @param array<int,string> $files File paths.
	 * @return string Markdown output.
	 */
	private function renderMarkdown(string $root, array $files): string {
		$view = $this->resolveView();
		$visibility = $this->resolveVisibility($view);
		$docMode = $this->resolveDocMode($view);
		$lines = [];

		$lines[] = '# CitOmni LLM context';
		$lines[] = '';
		$lines[] = '- Generated: ' . \date('c');
		$lines[] = '- Root: `' . $root . '`';

		if (isset($this->options['bundle'])) {
			$lines[] = '- Bundle: `' . (string)$this->options['bundle'] . '`';
		}

		$lines[] = '- View: `' . $view . '`';
		$lines[] = '- Visibility: `' . $visibility . '`';
		$lines[] = '- Doc: `' . $docMode . '`';
		$lines[] = '- Files: `' . (string)\count($files) . '`';
		$lines[] = '';

		foreach ($files as $file) {
			$relative = $this->relativePath($root, $file);
			$fileView = $this->resolveFileView($file, $view);
			$fileVisibility = $this->resolveVisibility($fileView);
			$fileDocMode = $this->resolveDocMode($fileView);
			$code = (string)\file_get_contents($file);
			$code = $this->renderCodeView($code, $fileView, $fileVisibility, $fileDocMode);

			$lines[] = '## ' . $relative;

			if ($fileView !== $view) {
				$lines[] = '';

				if (isset($this->fileMeta[$file]['view'])) {
					$lines[] = '_File view override: `' . $fileView . '`_';
				} else {
					$lines[] = '_File minimum view applied: `' . $fileView . '`_';
				}
			}

			$lines[] = '';
			$lines[] = '```php';
			$lines[] = \rtrim($code);
			$lines[] = '```';
			$lines[] = '';
		}

		return \implode("\n", $lines);
	}

	/**
	 * Resolve the requested source view.
	 *
	 * @return string View name.
	 */
	private function resolveView(): string {
		$view = (string)($this->options['view'] ?? '');

		if ($view === '' && isset($this->options['strip'])) {
			$view = $this->mapLegacyStripToView((string)$this->options['strip']);
		}

		if ($view === '') {
			return 'contract';
		}

		return $this->normalizeView($view);
	}

	/**
	 * Resolve the effective view for a specific file.
	 *
	 * Behavior:
	 * - A hard per-file "view" override wins.
	 * - A per-file "min_view" can raise the global view, but never lower it.
	 *
	 * @param string $file Absolute file path.
	 * @param string $globalView Requested global view.
	 * @return string Effective view name.
	 */
	private function resolveFileView(string $file, string $globalView): string {
		$hardView = $this->fileMeta[$file]['view'] ?? '';

		if ($hardView !== '') {
			return $this->normalizeView($hardView);
		}

		$minView = $this->fileMeta[$file]['min_view'] ?? '';

		if ($minView === '') {
			return $globalView;
		}

		$minView = $this->normalizeView($minView);

		return $this->viewRank($globalView) >= $this->viewRank($minView) ? $globalView : $minView;
	}

	/**
	 * Normalize a view name.
	 *
	 * @param string $view View name.
	 * @return string Normalized view name.
	 */
	private function normalizeView(string $view): string {
		$view = \strtolower(\trim($view));

		return \in_array($view, ['api', 'contract', 'logic', 'source', 'full'], true) ? $view : 'contract';
	}

	/**
	 * Return the information rank for a view.
	 *
	 * @param string $view View name.
	 * @return int Rank, higher means more source information.
	 */
	private function viewRank(string $view): int {
		return match ($view) {
			'api' => 10,
			'contract' => 20,
			'logic' => 30,
			'source' => 40,
			'full' => 50,
			default => 20,
		};
	}

	/**
	 * Map old strip levels to new view levels.
	 *
	 * @param string $strip Legacy strip level.
	 * @return string View name.
	 */
	private function mapLegacyStripToView(string $strip): string {
		return match (\strtolower($strip)) {
			'none' => 'full',
			'doc' => 'source',
			'compact' => 'logic',
			'outline' => 'api',
			default => 'contract',
		};
	}

	/**
	 * Resolve member visibility filter.
	 *
	 * @param string $view View name.
	 * @return string Visibility setting.
	 */
	private function resolveVisibility(string $view): string {
		$visibility = \strtolower((string)($this->options['visibility'] ?? 'auto'));

		if ($visibility === 'auto' || $visibility === '') {
			return match ($view) {
				'logic', 'source', 'full' => 'all',
				default => 'public,protected',
			};
		}

		return \in_array($visibility, ['public', 'public,protected', 'all'], true) ? $visibility : 'public,protected';
	}

	/**
	 * Resolve PHPDoc rendering mode.
	 *
	 * @param string $view View name.
	 * @return string Documentation mode.
	 */
	private function resolveDocMode(string $view): string {
		$docMode = (string)($this->options['doc'] ?? '');

		if ($docMode !== '') {
			return $this->normalizeDocMode($docMode);
		}

		return match ($view) {
			'api', 'logic' => 'none',
			'contract' => 'summary',
			'source', 'full' => 'full',
			default => 'summary',
		};
	}

	/**
	 * Normalize a documentation mode.
	 *
	 * @param string $docMode Documentation mode.
	 * @return string Normalized documentation mode.
	 */
	private function normalizeDocMode(string $docMode): string {
		$docMode = \strtolower(\trim($docMode));

		return \in_array($docMode, ['none', 'tags', 'summary', 'full'], true) ? $docMode : 'summary';
	}

	/**
	 * Render source code according to the requested view.
	 *
	 * @param string $code PHP source code.
	 * @param string $view View name.
	 * @param string $visibility Visibility setting.
	 * @param string $docMode Documentation mode.
	 * @return string Rendered source code.
	 */
	private function renderCodeView(string $code, string $view, string $visibility, string $docMode): string {
		return match ($view) {
			'full' => $code,
			'source' => $this->createSourceView($code),
			'logic' => $this->createLogicView($code),
			'api' => $this->createApiView($code, $visibility),
			default => $this->createContractView($code, $visibility, $docMode),
		};
	}

	/**
	 * Create an API-focused view.
	 *
	 * @param string $code PHP source code.
	 * @param string $visibility Visibility setting.
	 * @return string API skeleton.
	 */
	private function createApiView(string $code, string $visibility): string {
		return $this->createSkeletonView($code, $visibility, 'none');
	}

	/**
	 * Create a contract-focused view.
	 *
	 * @param string $code PHP source code.
	 * @param string $visibility Visibility setting.
	 * @param string $docMode Documentation mode.
	 * @return string Contract skeleton with PHPDoc.
	 */
	private function createContractView(string $code, string $visibility, string $docMode): string {
		return $this->createSkeletonView($code, $visibility, $docMode);
	}

	/**
	 * Create a source-focused view.
	 *
	 * @param string $code PHP source code.
	 * @return string Source code without the common CitOmni file header.
	 */
	private function createSourceView(string $code): string {
		$code = $this->removeFileHeader($code);
		$code = (string)\preg_replace("/\n{3,}/", "\n\n", $code);

		return \trim($code) . "\n";
	}

	/**
	 * Create a logic-focused view.
	 *
	 * @param string $code PHP source code.
	 * @return string Source code without comments or documentation.
	 */
	private function createLogicView(string $code): string {
		$code = $this->removeFileHeader($code);
		$code = $this->removeComments($code);
		$code = $this->normalizeLogicWhitespace($code);

		return \trim($code) . "\n";
	}

	/**
	 * Normalize whitespace after comment removal.
	 *
	 * @param string $code PHP source code.
	 * @return string Normalized source code.
	 */
	private function normalizeLogicWhitespace(string $code): string {
		$code = \str_replace(["\r\n", "\r"], "\n", $code);

		// Remove trailing horizontal whitespace before line breaks.
		$code = (string)\preg_replace('/[ \t]+\n/', "\n", $code);

		// Turn whitespace-only lines into empty lines.
		$code = (string)\preg_replace('/^\h+$/m', '', $code);

		// Collapse all blank-line runs for token economy.
		$code = (string)\preg_replace("/\n{2,}/", "\n", $code);

		return $code;
	}

	/**
	 * Create a skeleton source view.
	 *
	 * @param string $code PHP source code.
	 * @param string $visibility Visibility setting.
	 * @param string $docMode Documentation mode.
	 * @return string Skeleton source.
	 */
	private function createSkeletonView(string $code, string $visibility, string $docMode): string {
		$code = $this->removeFileHeader($code);
		$lines = \preg_split('/\R/', $code) ?: [];
		$out = [];
		$pendingDoc = [];
		$inDoc = false;
		$openedClass = false;
		$skipMethodBody = false;
		$methodBraceDepth = 0;
		$count = \count($lines);

		for ($i = 0; $i < $count; $i++) {
			$line = $lines[$i];
			$trim = \trim($line);

			if ($skipMethodBody) {
				$methodBraceDepth += \substr_count($line, '{');
				$methodBraceDepth -= \substr_count($line, '}');

				if ($methodBraceDepth <= 0) {
					$skipMethodBody = false;
					$methodBraceDepth = 0;
				}

				continue;
			}

			if ($inDoc) {
				$pendingDoc[] = $line;

				if (\str_contains($trim, '*/')) {
					$inDoc = false;
				}

				continue;
			}

			if (\str_starts_with($trim, '/**')) {
				$pendingDoc = [$line];

				if (!\str_contains($trim, '*/')) {
					$inDoc = true;
				}

				continue;
			}

			if ($trim === '' || \str_starts_with($trim, '//') || \str_starts_with($trim, '/*')) {
				continue;
			}

			if ($this->isNamespaceOrUseLine($trim)) {
				$out[] = $trim;
				$pendingDoc = [];
				continue;
			}

			if ($this->isClassLikeLine($trim)) {
				$this->appendPendingDoc($out, $pendingDoc, $docMode);
				$out[] = $trim;
				$openedClass = true;
				continue;
			}

			if ($this->isEnumCaseLine($trim)) {
				$out[] = "\t" . $trim;
				$pendingDoc = [];
				continue;
			}

			if ($this->isMemberDeclarationStart($trim)) {
				$declaration = $trim;

				while (!$this->isDeclarationComplete($declaration) && $i + 1 < $count) {
					$i++;
					$declaration .= ' ' . \trim($lines[$i]);
				}

				$isConstant = $this->isConstantDeclaration($declaration);
				$memberVisibility = $this->extractMemberVisibility($declaration);

				if (!$isConstant && !$this->visibilityAllows($memberVisibility, $visibility)) {
					$pendingDoc = [];

					if (\str_contains($declaration, '{')) {
						$methodBraceDepth = \substr_count($declaration, '{') - \substr_count($declaration, '}');

						if ($methodBraceDepth > 0) {
							$skipMethodBody = true;
						}
					}

					continue;
				}

				$this->appendPendingDoc($out, $pendingDoc, $docMode);

				if ($this->isFunctionDeclaration($declaration)) {
					$out[] = "\t" . $this->normalizeMethodSignature($declaration);

					$methodBraceDepth = \substr_count($declaration, '{') - \substr_count($declaration, '}');

					if ($methodBraceDepth > 0) {
						$skipMethodBody = true;
					}

					continue;
				}

				$out[] = "\t" . $this->normalizeMemberDeclaration($declaration);
				continue;
			}

			$pendingDoc = [];
		}

		if ($openedClass) {
			$out[] = '}';
		}

		$result = \implode("\n", $out);
		$result = (string)\preg_replace("/\n{3,}/", "\n\n", $result);

		return \trim($result) . "\n";
	}

	/**
	 * Append a pending PHPDoc block according to the selected documentation mode.
	 *
	 * @param array<int,string> $out Output lines.
	 * @param array<int,string> $pendingDoc Pending PHPDoc lines.
	 * @param string $docMode Documentation mode.
	 * @return void
	 */
	private function appendPendingDoc(array &$out, array &$pendingDoc, string $docMode): void {
		$rendered = $this->renderDocBlock($pendingDoc, $docMode);

		foreach ($rendered as $line) {
			$out[] = $line;
		}

		$pendingDoc = [];
	}

	/**
	 * Render a PHPDoc block in the requested documentation mode.
	 *
	 * @param array<int,string> $docLines Original PHPDoc lines.
	 * @param string $docMode Documentation mode.
	 * @return array<int,string> Rendered PHPDoc lines.
	 */
	private function renderDocBlock(array $docLines, string $docMode): array {
		if ($docLines === [] || $docMode === 'none') {
			return [];
		}

		if ($docMode === 'full') {
			return $docLines;
		}

		$summary = $this->extractDocSummary($docLines);
		$tags = $this->extractDocTags($docLines);

		if ($docMode === 'tags') {
			return $this->buildDocBlock($tags);
		}

		if ($docMode === 'summary') {
			$lines = [];

			if ($summary !== '') {
				$lines[] = $summary;
			}

			foreach ($tags as $tag) {
				$lines[] = $tag;
			}

			return $this->buildDocBlock($lines);
		}

		return [];
	}

	/**
	 * Extract the first useful summary line from a PHPDoc block.
	 *
	 * @param array<int,string> $docLines Original PHPDoc lines.
	 * @return string Summary line without PHPDoc framing.
	 */
	private function extractDocSummary(array $docLines): string {
		foreach ($docLines as $line) {
			$text = $this->cleanDocLine($line);

			if ($text === '' || \str_starts_with($text, '@')) {
				continue;
			}

			return $text;
		}

		return '';
	}

	/**
	 * Extract PHPDoc tag lines and simple continuation lines.
	 *
	 * @param array<int,string> $docLines Original PHPDoc lines.
	 * @return array<int,string> Tag lines without PHPDoc framing.
	 */
	private function extractDocTags(array $docLines): array {
		$tags = [];
		$capture = false;

		foreach ($docLines as $line) {
			$text = $this->cleanDocLine($line);

			if ($text === '') {
				$capture = false;
				continue;
			}

			if (\preg_match('/^@(param|return|throws?|var|template(?:-[a-z]+)?|extends|implements|method|property(?:-read|-write)?)\b/', $text) === 1) {
				$tags[] = $text;
				$capture = true;
				continue;
			}

			if ($capture && !\str_starts_with($text, '@')) {
				$tags[] = $text;
				continue;
			}

			$capture = false;
		}

		return $tags;
	}

	/**
	 * Clean one PHPDoc line.
	 *
	 * @param string $line Raw PHPDoc line.
	 * @return string Cleaned content line.
	 */
	private function cleanDocLine(string $line): string {
		$line = \trim($line);
		$line = (string)\preg_replace('/^\/\*\*\s*/', '', $line);
		$line = (string)\preg_replace('/^\*\s?/', '', $line);
		$line = (string)\preg_replace('/\s*\*\/$/', '', $line);

		return \trim($line);
	}

	/**
	 * Build a PHPDoc block from content lines.
	 *
	 * @param array<int,string> $lines PHPDoc content lines.
	 * @return array<int,string> Rendered PHPDoc block.
	 */
	private function buildDocBlock(array $lines): array {
		if ($lines === []) {
			return [];
		}

		$out = ['/**'];

		foreach ($lines as $line) {
			$out[] = ' * ' . $line;
		}

		$out[] = ' */';

		return $out;
	}

	/**
	 * Remove the common CitOmni file header while keeping PHPDoc.
	 *
	 * @param string $code PHP source code.
	 * @return string Source without file header.
	 */
	private function removeFileHeader(string $code): string {
		return (string)\preg_replace(
			'/^<\?php\s+declare\(strict_types=1\);\s*\/\*.*?\*\/\s*/s',
			"<?php\ndeclare(strict_types=1);\n\n",
			$code
		);
	}

	/**
	 * Remove PHP comments and PHPDoc blocks using PHP's tokenizer.
	 *
	 * @param string $code PHP source code.
	 * @return string Source code without comments.
	 */
	private function removeComments(string $code): string {
		$out = '';

		foreach (\token_get_all($code) as $token) {
			if (\is_array($token)) {
				if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
					continue;
				}

				$out .= $token[1];
				continue;
			}

			$out .= $token;
		}

		return $out;
	}

	/**
	 * Check whether a line is a namespace or use declaration.
	 *
	 * @param string $line Trimmed source line.
	 * @return bool True when relevant.
	 */
	private function isNamespaceOrUseLine(string $line): bool {
		return \str_starts_with($line, '<?php') ||
			\str_starts_with($line, 'declare(') ||
			\str_starts_with($line, 'namespace ') ||
			\str_starts_with($line, 'use ');
	}

	/**
	 * Check whether a line starts a class-like declaration.
	 *
	 * @param string $line Trimmed source line.
	 * @return bool True when class-like.
	 */
	private function isClassLikeLine(string $line): bool {
		return \preg_match('/^(final\s+|abstract\s+)?(class|interface|trait|enum)\s+/', $line) === 1;
	}

	/**
	 * Check whether a line is an enum case declaration.
	 *
	 * @param string $line Trimmed source line.
	 * @return bool True when enum case.
	 */
	private function isEnumCaseLine(string $line): bool {
		return \preg_match('/^case\s+[A-Za-z_][A-Za-z0-9_]*(\s*=\s*[^;]+)?;$/', $line) === 1;
	}

	/**
	 * Check whether a line starts a member declaration.
	 *
	 * @param string $line Trimmed source line.
	 * @return bool True when member declaration.
	 */
	private function isMemberDeclarationStart(string $line): bool {
		if (\preg_match('/^((final|abstract)\s+)?(public|protected|private)\s+(static\s+)?function\s+/', $line) === 1) {
			return true;
		}

		if (\preg_match('/^(public|protected|private)\s+const\s+/', $line) === 1) {
			return true;
		}

		if (\preg_match('/^(public|protected|private)\s+(readonly\s+)?(static\s+)?[?\\\\A-Za-z_][\\\\A-Za-z0-9_|&?\s]*\s+\$[A-Za-z_][A-Za-z0-9_]*/', $line) === 1) {
			return true;
		}

		if (\preg_match('/^(static\s+)?function\s+/', $line) === 1) {
			return true;
		}

		return false;
	}

	/**
	 * Check whether a collected declaration is complete.
	 *
	 * @param string $declaration Member declaration.
	 * @return bool True when complete.
	 */
	private function isDeclarationComplete(string $declaration): bool {
		return \str_contains($declaration, '{') || \str_contains($declaration, ';');
	}

	/**
	 * Check whether a declaration is a class constant.
	 *
	 * @param string $declaration Member declaration.
	 * @return bool True when declaration is a constant.
	 */
	private function isConstantDeclaration(string $declaration): bool {
		return \preg_match('/\bconst\s+[A-Z][A-Z0-9_]*\b/', $declaration) === 1;
	}

	/**
	 * Check whether a declaration is a function or method.
	 *
	 * @param string $declaration Member declaration.
	 * @return bool True when function declaration.
	 */
	private function isFunctionDeclaration(string $declaration): bool {
		return \preg_match('/\bfunction\s+[A-Za-z_][A-Za-z0-9_]*\s*\(/', $declaration) === 1;
	}

	/**
	 * Extract member visibility from a declaration.
	 *
	 * @param string $declaration Member declaration.
	 * @return string Visibility name.
	 */
	private function extractMemberVisibility(string $declaration): string {
		if (\preg_match('/\b(private|protected|public)\b/', $declaration, $match) === 1) {
			return $match[1];
		}

		return 'public';
	}

	/**
	 * Check whether a member visibility is allowed by the current filter.
	 *
	 * @param string $memberVisibility Member visibility.
	 * @param string $visibility Visibility filter.
	 * @return bool True when included.
	 */
	private function visibilityAllows(string $memberVisibility, string $visibility): bool {
		return match ($visibility) {
			'all' => true,
			'public' => $memberVisibility === 'public',
			default => $memberVisibility === 'public' || $memberVisibility === 'protected',
		};
	}

	/**
	 * Normalize a method signature and replace the body.
	 *
	 * @param string $signature Raw method signature.
	 * @return string Normalized method signature.
	 */
	private function normalizeMethodSignature(string $signature): string {
		$signature = (string)\preg_replace('/\s+/', ' ', \trim($signature));
		$pos = \strpos($signature, '{');

		if ($pos !== false) {
			$signature = \substr($signature, 0, $pos);
		}

		$signature = \rtrim($signature, " \t\n\r\0\x0B;");

		return $signature . ' { ... }';
	}

	/**
	 * Normalize a constant or property declaration.
	 *
	 * @param string $declaration Raw member declaration.
	 * @return string Normalized declaration.
	 */
	private function normalizeMemberDeclaration(string $declaration): string {
		$declaration = (string)\preg_replace('/\s+/', ' ', \trim($declaration));

		if (\str_contains($declaration, '[')) {
			$declaration = (string)\preg_replace('/=\s*\[.*$/s', '= [...];', $declaration);
		}

		if (!\str_ends_with($declaration, ';')) {
			$declaration = \rtrim($declaration, " \t\n\r\0\x0B,") . ';';
		}

		return $declaration;
	}

	/**
	 * Read the first declared FQCN from a PHP file.
	 *
	 * @param string $file PHP file path.
	 * @return string FQCN with leading slash, or empty string.
	 */
	private function readFqcn(string $file): string {
		$code = (string)\file_get_contents($file);
		$namespace = '';
		$name = '';

		if (\preg_match('/^\s*namespace\s+([^;]+);/m', $code, $match) === 1) {
			$namespace = \trim($match[1]);
		}

		if (\preg_match('/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $code, $match) === 1) {
			$name = \trim($match[1]);
		}

		if ($namespace === '' || $name === '') {
			return '';
		}

		return '\\' . $namespace . '\\' . $name;
	}

	/**
	 * Find the array item assigned to a specific string key.
	 *
	 * @param string $code PHP source code.
	 * @param string $key Array key to find.
	 * @return string Matching array item snippet.
	 */
	private function findArrayItemSnippet(string $code, string $key): string {
		$needles = [
			"'" . $key . "'",
			'"' . $key . '"',
		];

		foreach ($needles as $needle) {
			$keyPos = \strpos($code, $needle);

			if ($keyPos === false) {
				continue;
			}

			$arrowPos = \strpos($code, '=>', $keyPos + \strlen($needle));

			if ($arrowPos === false) {
				continue;
			}

			$endPos = $this->findArrayItemEnd($code, $arrowPos + 2);

			if ($endPos === null) {
				return \substr($code, $keyPos);
			}

			return \substr($code, $keyPos, $endPos - $keyPos + 1);
		}

		return '';
	}

	/**
	 * Find the matching closing bracket for an array.
	 *
	 * @param string $code PHP source code.
	 * @param int $startPos Position of the opening square bracket.
	 * @return int|null Closing bracket position, or null when not found.
	 */
	private function findBracketEnd(string $code, int $startPos): ?int {
		$length = \strlen($code);
		$depth = 0;
		$quote = '';
		$escaped = false;

		for ($i = $startPos; $i < $length; $i++) {
			$char = $code[$i];

			if ($quote !== '') {
				if ($escaped) {
					$escaped = false;
					continue;
				}

				if ($char === '\\') {
					$escaped = true;
					continue;
				}

				if ($char === $quote) {
					$quote = '';
				}

				continue;
			}

			if ($char === "'" || $char === '"') {
				$quote = $char;
				continue;
			}

			if ($char === '[') {
				$depth++;
				continue;
			}

			if ($char === ']') {
				$depth--;

				if ($depth === 0) {
					return $i;
				}
			}
		}

		return null;
	}

	/**
	 * Find the end position of an array item value.
	 *
	 * @param string $code PHP source code.
	 * @param int $startPos Position immediately after the array arrow.
	 * @return int|null End position, or null when not found.
	 */
	private function findArrayItemEnd(string $code, int $startPos): ?int {
		$length = \strlen($code);
		$depthSquare = 0;
		$depthParen = 0;
		$depthBrace = 0;
		$quote = '';
		$escaped = false;

		for ($i = $startPos; $i < $length; $i++) {
			$char = $code[$i];

			if ($quote !== '') {
				if ($escaped) {
					$escaped = false;
					continue;
				}

				if ($char === '\\') {
					$escaped = true;
					continue;
				}

				if ($char === $quote) {
					$quote = '';
				}

				continue;
			}

			if ($char === "'" || $char === '"') {
				$quote = $char;
				continue;
			}

			if ($char === '[') {
				$depthSquare++;
				continue;
			}

			if ($char === ']') {
				if ($depthSquare === 0 && $depthParen === 0 && $depthBrace === 0) {
					return $i - 1;
				}

				$depthSquare--;
				continue;
			}

			if ($char === '(') {
				$depthParen++;
				continue;
			}

			if ($char === ')') {
				$depthParen--;
				continue;
			}

			if ($char === '{') {
				$depthBrace++;
				continue;
			}

			if ($char === '}') {
				$depthBrace--;
				continue;
			}

			if ($char === ',' && $depthSquare === 0 && $depthParen === 0 && $depthBrace === 0) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * Extract simple class aliases from use declarations.
	 *
	 * @param string $code PHP source code.
	 * @return array<string,string> Short alias to FQCN with leading slash.
	 */
	private function extractUseAliases(string $code): array {
		$aliases = [];

		if (\preg_match_all('/^\s*use\s+([^;]+);/m', $code, $matches) === false) {
			return $aliases;
		}

		foreach ($matches[1] as $raw) {
			$raw = \trim($raw);

			if (\str_contains($raw, ' function ') || \str_contains($raw, ' const ')) {
				continue;
			}

			$parts = \preg_split('/\s+as\s+/i', $raw);
			$fqcn = \trim((string)($parts[0] ?? ''), '\\');

			if ($fqcn === '') {
				continue;
			}

			$alias = isset($parts[1]) ? \trim($parts[1]) : \basename(\str_replace('\\', '/', $fqcn));
			$aliases[$alias] = '\\' . $fqcn;
		}

		return $aliases;
	}

	/**
	 * Extract ::class references from source.
	 *
	 * @param string $code PHP source snippet.
	 * @param array<string,string> $aliases Short alias to FQCN with leading slash.
	 * @return array<int,string> FQCNs with leading slash.
	 */
	private function extractClassRefs(string $code, array $aliases = []): array {
		$refs = [];

		if (\preg_match_all('/\\\\?[A-Z][A-Za-z0-9_]*(?:\\\\[A-Za-z0-9_]+)*::class/', $code, $matches) !== false) {
			foreach ($matches[0] as $raw) {
				$class = \substr($raw, 0, -7);

				if (\str_contains($class, '\\')) {
					$fqcn = '\\' . \ltrim($class, '\\');
					$refs[$fqcn] = $fqcn;
					continue;
				}

				if (isset($aliases[$class])) {
					$refs[$aliases[$class]] = $aliases[$class];
				}
			}
		}

		return \array_values($refs);
	}

	/**
	 * Normalize path separators for the current OS.
	 *
	 * @param string $path Input path.
	 * @return string Normalized path.
	 */
	private function normalizePath(string $path): string {
		return \rtrim(\str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
	}

	/**
	 * Return a path relative to root when possible.
	 *
	 * @param string $root Root path.
	 * @param string $file File path.
	 * @return string Relative path.
	 */
	private function relativePath(string $root, string $file): string {
		$root = \rtrim($this->normalizePath($root), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		$file = $this->normalizePath($file);

		if (\str_starts_with($file, $root)) {
			return \str_replace(DIRECTORY_SEPARATOR, '/', \substr($file, \strlen($root)));
		}

		return \str_replace(DIRECTORY_SEPARATOR, '/', $file);
	}

	/**
	 * Write one usage event as JSON Lines.
	 *
	 * Behavior:
	 * - Never writes to STDOUT.
	 * - Never fails the context extraction when logging fails.
	 * - Avoids logging generated source context.
	 *
	 * @param string $root Resolved packages root.
	 * @param array<int,string> $files Selected files.
	 * @param int $exitCode Process exit code.
	 * @param string $status Short machine-readable status.
	 * @return void
	 */
	private function writeUsageLog(string $root, array $files, int $exitCode, string $status): void {
		$path = $this->resolveUsageLogPath();

		if ($path === '') {
			return;
		}

		$entry = [
			'ts' => \date('c'),
			'status' => $status,
			'exit_code' => $exitCode,
			'duration_ms' => (int)((\hrtime(true) - $this->startedAtNs) / 1000000),
			'root' => $root,
			'selectors' => $this->buildUsageSelectorLog(),
			'view' => $this->resolveView(),
			'visibility' => $this->resolveVisibility($this->resolveView()),
			'doc' => $this->resolveDocMode($this->resolveView()),
			'files' => \count($files),
			'output' => isset($this->options['out']) ? 'file' : 'stdout',
		];

		try {
			$dir = \dirname($path);

			if (!\is_dir($dir)) {
				\mkdir($dir, 0775, true);
			}

			$json = \json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

			if ($json !== false) {
				\file_put_contents($path, $json . "\n", FILE_APPEND | LOCK_EX);
			}
		} catch (\Throwable) {
			// Logging must never break context generation. The dog ate the telemetry.
		}
	}

	/**
	 * Resolve usage log path.
	 *
	 * @return string Absolute log path, or empty string when logging is disabled.
	 */
	private function resolveUsageLogPath(): string {
		if ($this->hasFlag('no-log')) {
			return '';
		}

		$explicit = (string)($this->options['log'] ?? '');

		if ($explicit !== '') {
			return $this->normalizePath($explicit);
		}

		$env = (string)(\getenv('ASERNO_LLM_CONTEXT_USAGE_LOG') ?: '');

		if ($env !== '') {
			return $this->normalizePath($env);
		}

		return $this->normalizePath(self::USAGE_LOG_PATH);
	}

	/**
	 * Build a compact selector log without source content.
	 *
	 * @return array<string,mixed> Selector metadata.
	 */
	private function buildUsageSelectorLog(): array {
		$selectors = [];

		foreach (['bundle', 'package', 'layer', 'service', 'route', 'command', 'class', 'max-files'] as $key) {
			if (isset($this->options[$key])) {
				$selectors[$key] = $this->options[$key];
			}
		}

		if (isset($this->options['contains'])) {
			$value = (string)$this->options['contains'];

			$selectors['contains'] = [
				'present' => true,
				'length' => \strlen($value),
				'sha1' => \sha1($value),
			];
		}

		if ($this->hasFlag('with-registry')) {
			$selectors['with-registry'] = true;
		}

		return $selectors;
	}

	/**
	 * Print usage help.
	 *
	 * @return void
	 */
	private function printHelp(): void {
		echo <<<'TXT'
CitOmni context extractor for LLM prompts

Usage:
  php bin/citomni-context [options]

Target options:
  --root=C:\dev\www\citomni        CitOmni packages root.
  --bundle=http-boot               Use a predefined ordered file bundle.
  --package=http                   Package folder name.
  --layer=Service,Controller       One or more layers.
  --service=router                 Resolve a service id from Registry.php.
  --route=/member/security.html    Resolve a route key from Registry.php.
  --command=greeter:say            Resolve a command key from Registry.php.
  --class=Router                   Filter by short class name or FQCN.
  --contains=needle                Filter files by text.
  --with-registry                  Include Registry.php in the output.
  --max-files=80                   Limit number of files.
  --out=C:\path\context.md         Write output to file instead of STDOUT.
  --log=C:\path\usage.jsonl        Override the default usage log path for this run.
  --no-log                         Disable usage logging for this run.

View options:
  --view=api                       Show namespace, imports, class shape, constants, properties, and method signatures.
  --view=contract                  Show class shape, method signatures, and selected PHPDoc. Default.
  --view=logic                     Show implementation without documentation or comments.
  --view=source                    Show implementation with PHPDoc and comments, but without license header.
  --view=full                      Show exact source file.

Documentation options:
  --doc=none                       Show no PHPDoc in skeleton views.
  --doc=tags                       Show only PHPDoc tags such as @param, @return, and @throws.
  --doc=summary                    Show first summary line plus PHPDoc tags. Default for contract.
  --doc=full                       Show full PHPDoc blocks.

Visibility options:
  --visibility=auto                Use view-specific default. Default.
  --visibility=public              Include public members only.
  --visibility=public,protected    Include public and protected members.
  --visibility=all                 Include public, protected, and private members.

Legacy aliases:
  --strip=none                     Alias for --view=full.
  --strip=doc                      Alias for --view=source.
  --strip=compact                  Alias for --view=logic.
  --strip=outline                  Alias for --view=api.

Examples:
  php bin/citomni-context --package=http --service=router | clip
  php bin/citomni-context --package=http --service=router --view=api | clip
  php bin/citomni-context --package=http --service=router --view=logic | clip
  php bin/citomni-context --package=http --service=router --view=source | clip
  php bin/citomni-context --package=authenticate --route=/member/security.html --view=logic | clip
  php bin/citomni-context --package=infrastructure --service=db --view=contract --visibility=all | clip
  php bin/citomni-context --package=http --layer=Service --view=api --max-files=30 | clip
  php bin/citomni-context --bundle=http-boot --view=source | clip
  php bin/citomni-context --bundle=cli-boot --view=source | clip
  php bin/citomni-context --package=http --service=router --view=contract --doc=tags | clip
  php bin/citomni-context --bundle=http-boot --view=contract --doc=summary | clip
  php bin/citomni-context --bundle=http-boot --view=contract --doc=full | clip

TXT;
	}
}
