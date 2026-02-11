<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Tests;

use PHPUnit\Framework\TestCase;
use Zoon\BounceHandler\BounceHandler;
use Zoon\BounceHandler\Resolver\StatusCodeResolver;

final class LegacyDriversTest extends TestCase {
	public function testCmdlineDriverEquivalentTotalsForAllFixtures(): void {
		$handler = new BounceHandler();

		/** @var array<string, int> $totals */
		$totals = [];
		foreach ($this->getSortedFixtureFiles() as $file) {
			$results = $handler->parse($this->readFixture($file));
			$type = count($results) === 0 ? 'unknown' : $results[0]->emailType->value;
			$totals[$type] = ($totals[$type] ?? 0) + 1;
		}

		$fixtureCount = count($this->getSortedFixtureFiles());
		self::assertSame($fixtureCount, array_sum($totals));
		self::assertGreaterThan(0, $totals['bounce'] ?? 0);

		foreach (array_keys($totals) as $type) {
			self::assertContains($type, ['bounce', 'fbl', 'autoresponse', 'unknown']);
		}
	}

	public function testCmdlineDriverEquivalentOutputFieldsArePresent(): void {
		$handler = new BounceHandler();

		foreach ($this->getSortedFixtureFiles() as $file) {
			$results = $handler->parse($this->readFixture($file));
			if (count($results) === 0) {
				continue;
			}

			$result = $results[0];
			if ($result->emailType->value === 'bounce') {
				self::assertNotSame('', $result->action->value, "Missing action for {$file}");
				self::assertNotSame('', $result->deliveryStatus, "Missing status for {$file}");
				self::assertNotSame('', $result->recipient, "Missing recipient for {$file}");
			}

			if ($result->emailType->value === 'fbl') {
				self::assertNotNull($result->feedbackReport, "Missing feedback report for {$file}");
			}
		}
	}

	public function testBrowserTestAllEquivalentPassAndWrongCounts(): void {
		$handler = new BounceHandler();
		$passed = 0;
		$wrong = 0;

		foreach ($this->getSortedFixtureFiles() as $file) {
			$results = $handler->parse($this->readFixture($file));
			if (count($results) > 0 && $results[0]->action->value !== '' && $results[0]->recipient !== '') {
				$passed++;
			} else {
				$wrong++;
			}
		}

		self::assertSame(count($this->getSortedFixtureFiles()), $passed + $wrong);
		self::assertGreaterThan(0, $passed);
	}

	public function testBrowserStatusDescriptionEquivalentIsAvailable(): void {
		$handler = new BounceHandler();

		foreach ($this->getSortedFixtureFiles() as $file) {
			$results = $handler->parse($this->readFixture($file));
			if (count($results) === 0 || $results[0]->deliveryStatus === '') {
				continue;
			}

			$statusDescription = StatusCodeResolver::getStatusMessages($results[0]->deliveryStatus);
			self::assertNotSame('', $statusDescription, "Missing status description for {$file}");
		}
	}

	/**
	 * @return list<string>
	 */
	private function getSortedFixtureFiles(): array {
		$files = [];
		$handle = opendir(__DIR__ . '/../eml');
		self::assertNotFalse($handle, 'Failed to open eml directory');

		while (($file = readdir($handle)) !== false) {
			if ($file === '.' || $file === '..') {
				continue;
			}

			$files[] = $file;
		}

		closedir($handle);
		sort($files, SORT_STRING);

		return $files;
	}

	private function readFixture(string $filename): string {
		$contents = file_get_contents(__DIR__ . '/../eml/' . $filename);
		self::assertNotFalse($contents, "Fixture not found: {$filename}");

		return $contents;
	}
}
