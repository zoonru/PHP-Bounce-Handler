<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zoon\BounceHandler\Data\BouncePatterns;
use Zoon\BounceHandler\Detector\BounceDetector;

final class BounceDetectorTest extends TestCase {
	/**
	 * Образцы строк для паттернов, которые нельзя получить простым снятием экранирования.
	 *
	 * @var array<non-empty-string, non-empty-string>
	 */
	private const array PATTERN_SAMPLES = [
		'[45]\d\d[- ]#?([45]\.\d\.\d{1,2})' => '550 5.1.1 user unknown',
		'Diagnostic[- ][Cc]ode: smtp; ?\d\d\ ([45]\.\d\.\d{1,2})' => 'Diagnostic-Code: smtp; 55 4.4.7 timeout',
		'Status: ([45]\.\d\.\d{1,2})' => 'Status: 4.7.1',
		'over ?quota' => 'over quota',
		'host ?name is unknown' => 'host name is unknown',
		'blocke?d? for spam' => 'blocked for spam',
	];

	/**
	 * Эталонная реализация: перебор всех паттернов по порядку, без предфильтра.
	 */
	private static function naiveMatch(string $line): ?string {
		foreach (BouncePatterns::BOUNCE_LIST as $bouncetext => $bouncecode) {
			if (preg_match("/{$bouncetext}/i", $line, $matches) === 1) {
				if (array_key_exists(1, $matches) && $bouncecode === 'x') {
					return $matches[1];
				}

				return $bouncecode;
			}
		}

		return null;
	}

	/**
	 * @return iterable<string, array{non-empty-string, non-empty-string}>
	 */
	public static function bouncePatternProvider(): iterable {
		foreach (array_keys(BouncePatterns::BOUNCE_LIST) as $pattern) {
			$sample = self::PATTERN_SAMPLES[$pattern] ?? str_replace(['\.', '\/', '\ '], ['.', '/', ' '], $pattern);
			self::assertNotSame('', $sample);

			yield $pattern => [$pattern, $sample];
		}
	}

	#[DataProvider('bouncePatternProvider')]
	public function testPatternSampleIsMatchedTheSameWayAsNaiveScan(string $pattern, string $sample): void {
		$expected = self::naiveMatch($sample);

		self::assertNotNull($expected, "Образец строки для паттерна `{$pattern}` не распознаётся эталонным перебором");
		self::assertSame($expected, BounceDetector::matchBouncePattern($sample));
	}

	public function testLineWithoutAnyBounceTextIsNotMatched(): void {
		self::assertNull(BounceDetector::matchBouncePattern('Received: from mx.example.org by relay.example.net'));
	}

	public function testMatchesNaiveScanOnEveryFixtureLine(): void {
		$mismatches = [];
		foreach (self::fixtureLines() as $line) {
			$expected = self::naiveMatch($line);
			$actual = BounceDetector::matchBouncePattern($line);
			if ($expected !== $actual) {
				$mismatches[$line] = ['эталон' => $expected, 'получено' => $actual];
			}
		}

		self::assertSame([], $mismatches);
	}

	/**
	 * @return list<string>
	 */
	private static function fixtureLines(): array {
		$lines = [];
		$files = glob(__DIR__ . '/../eml/*');
		self::assertIsArray($files);
		self::assertNotSame([], $files);

		foreach ($files as $file) {
			if (!is_file($file)) {
				continue;
			}

			$content = file_get_contents($file);
			self::assertIsString($content);

			foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
				$line = trim($line);
				if ($line !== '') {
					$lines[$line] = true;
				}
			}
		}

		// Ключи массива, состоящие из цифр, PHP приводит к int — возвращаем строки явно.
		return array_map(strval(...), array_keys($lines));
	}
}
