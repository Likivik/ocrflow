<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Tests\Unit\AppInfo;

use OCP\AppFramework\Controller;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Route and command ↔ class drift.
 *
 * A route whose `controller#method` no longer exists answers 404 at
 * deploy time, and an occ command class that is not registered is simply
 * silent — the operator types `occ ocrflow:requeue` and gets "command not
 * found". Both are wiring facts, invisible to any unit test that mocks
 * the layer above them, so this guard checks the wiring itself:
 *
 *  - every `controller#method` in appinfo/routes.php names a real,
 *    public method of a real controller class;
 *  - every <command> in appinfo/info.xml is an existing class that
 *    satisfies the console command contract;
 *  - the reverse for commands: every class in lib/Command/ is declared in
 *    info.xml, so a command cannot exist silently.
 *
 * Settings sections and background jobs declared in info.xml are checked
 * the same way — they are wiring too.
 */
final class WiringTest extends TestCase
{
	public function testEveryRouteResolvesToAPublicControllerMethod(): void
	{
		$routes = include $this->repoRoot() . '/appinfo/routes.php';
		$this->assertIsArray($routes);
		$targets = [];
		foreach ($routes as $section) {
			foreach ($section as $route) {
				$targets[] = $route['name'];
			}
		}
		$this->assertNotSame([], $targets, 'appinfo/routes.php declares no routes at all');

		$offenders = [];
		foreach ($targets as $target) {
			[$controller, $method] = explode('#', $target);
			$class = 'OCA\\OcrFlow\\Controller\\' . ucfirst($controller) . 'Controller';
			if (!class_exists($class)) {
				$offenders[] = sprintf('appinfo/routes.php — route "%s" targets %s, which does not exist — fix the route or restore the class', $target, $class);
				continue;
			}
			if (!is_subclass_of($class, Controller::class)) {
				$offenders[] = sprintf('appinfo/routes.php — route "%s" targets %s, which is not an AppFramework controller', $target, $class);
				continue;
			}
			if (!method_exists($class, $method)) {
				$offenders[] = sprintf('appinfo/routes.php — route "%s" targets %s::%s(), which does not exist — fix the route or restore the method', $target, $class, $method);
				continue;
			}
			$reflection = new \ReflectionMethod($class, $method);
			if (!$reflection->isPublic()) {
				$offenders[] = sprintf('appinfo/routes.php — route "%s" targets %s::%s(), which is not public — the router cannot dispatch it', $target, $class, $method);
			}
		}
		$this->assertSame([], $offenders,
			"These routes cannot be dispatched:\n  " . implode("\n  ", $offenders));
	}

	public function testEveryDeclaredCommandExistsAndIsAConsoleCommand(): void
	{
		$offenders = [];
		foreach ($this->infoXmlClasses('command') as $class) {
			$offenders = array_merge($offenders, $this->consoleCommandOffence('appinfo/info.xml <commands>', $class));
		}
		$this->assertSame([], $offenders,
			"These occ commands are declared but not usable:\n  " . implode("\n  ", $offenders));
	}

	public function testEveryCommandClassIsDeclaredInInfoXml(): void
	{
		$declared = array_flip($this->infoXmlClasses('command'));
		$offenders = [];
		foreach (glob($this->repoRoot() . '/lib/Command/*.php') ?: [] as $file) {
			$class = 'OCA\\OcrFlow\\Command\\' . basename($file, '.php');
			if (!isset($declared[$class])) {
				$offenders[] = sprintf('lib/Command/%s.php — %s is not in appinfo/info.xml <commands> — occ cannot see it; declare it or delete the class', basename($file), $class);
			}
		}
		$this->assertSame([], $offenders,
			"These commands exist but occ will never run them:\n  " . implode("\n  ", $offenders));
	}

	public function testInfoXmlClassesExist(): void
	{
		$offenders = [];
		foreach (['job' => 'background-jobs', 'admin' => 'settings/admin', 'admin-section' => 'settings/admin-section',
			'personal' => 'settings/personal', 'personal-section' => 'settings/personal-section'] as $tag => $label) {
			foreach ($this->infoXmlClasses($tag) as $class) {
				if (!class_exists($class)) {
					$offenders[] = sprintf('appinfo/info.xml <%s> — %s does not exist — fix the declaration or restore the class', $label, $class);
				}
			}
		}
		$this->assertSame([], $offenders,
			"info.xml wires classes that do not exist:\n  " . implode("\n  ", $offenders));
	}

	// -------------------------------------------------------------------

	/**
	 * @param "command"|"job"|"admin"|"admin-section"|"personal"|"personal-section" $tag
	 * @return list<string> FQCNs
	 */
	private function infoXmlClasses(string $tag): array
	{
		$info = simplexml_load_file($this->repoRoot() . '/appinfo/info.xml');
		$this->assertNotFalse($info, 'appinfo/info.xml must parse');
		$classes = [];
		foreach ($info->xpath('//' . $tag) ?: [] as $element) {
			$classes[] = (string) $element;
		}
		return array_values(array_unique($classes));
	}

	/**
	 * @return list<string>
	 */
	private function consoleCommandOffence(string $declaredIn, string $class): array
	{
		if (!class_exists($class)) {
			return [sprintf('%s — %s does not exist — fix the declaration or restore the class', $declaredIn, $class)];
		}
		if (!is_subclass_of($class, Command::class)) {
			return [sprintf('%s — %s does not extend Symfony\\Component\\Console\\Command\\Command — occ cannot run it', $declaredIn, $class)];
		}
		return [];
	}

	private function repoRoot(): string
	{
		return dirname(__DIR__, 3); // tests/Unit/AppInfo -> repo root
	}
}
