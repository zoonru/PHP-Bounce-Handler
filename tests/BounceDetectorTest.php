<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
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

			yield $pattern => [$pattern, $sample];
		}
	}

	#[DataProvider('bouncePatternProvider')]
	public function testPatternSampleIsMatchedTheSameWayAsNaiveScan(string $pattern, string $sample): void {
		// Без этой проверки образец мог бы совпадать с более ранней записью BOUNCE_LIST,
		// а собственная ветка паттерна не исполнялась бы ни разу.
		self::assertSame(1, preg_match("/{$pattern}/i", $sample), "Образец не совпадает со своим паттерном `{$pattern}`");

		$expected = self::naiveMatch($sample);

		self::assertNotNull($expected, "Образец строки для паттерна `{$pattern}` не распознаётся эталонным перебором");
		self::assertSame($expected, BounceDetector::matchBouncePattern($sample));
	}

	/**
	 * Приоритет задаётся порядком записей в BOUNCE_LIST, а не позицией совпадения в строке.
	 *
	 * Замена перебора на одну альтернативу с определением ветки прошла бы весь остальной
	 * набор тестов и молча сменила бы классификацию именно на таких строках.
	 *
	 * @return list<array{string, string, string}>
	 */
	public static function listOrderProvider(): array {
		return [
			['The mailbox is full. Status: 5.2.2', '5.2.2', 'mailbox is full'],
			['Access denied: no such user', '5.1.1', 'Access denied'],
		];
	}

	#[DataProvider('listOrderProvider')]
	public function testEarlierListEntryWinsOverLeftmostMatch(string $line, string $expected, string $leftmost): void {
		self::assertSame($expected, BounceDetector::matchBouncePattern($line));

		preg_match(BouncePatterns::getCombinedBounceRegex(), $line, $m);
		self::assertSame($leftmost, $m[0], 'Строка перестала различать порядок списка и leftmost-совпадение');
	}

	/**
	 * Предфильтр корректен только пока ни один паттерн не зависит от нумерации групп.
	 *
	 * В объединённой альтернативе номера групп сквозные, поэтому `\1` в поздней ветке
	 * сошлётся на группу чужой ветки и совпадение будет молча потеряно; две одноимённые
	 * группы вообще не дадут шаблону скомпилироваться.
	 */
	public function testNoBounceListPatternBreaksTheCombinedRegex(): void {
		$offenders = [];
		foreach (array_keys(BouncePatterns::BOUNCE_LIST) as $pattern) {
			if (preg_match('/\\\\[1-9]/', $pattern) === 1) {
				$offenders[$pattern] = 'обратная ссылка';
			}
			if (preg_match('/\(\?P?[<\']/', $pattern) === 1) {
				$offenders[$pattern] = 'именованная группа';
			}
		}

		self::assertSame([], $offenders);
		self::assertSame(0, preg_match(BouncePatterns::getCombinedBounceRegex(), ''));
		self::assertSame(PREG_NO_ERROR, preg_last_error(), preg_last_error_msg());
	}

	/**
	 * Гейт по JIT легко сделать всегда-ложным (например, сравнив bool-константу с int) — и тогда
	 * предфильтр молча выключится целиком, не изменив ни одного результата. Поведенческие тесты
	 * такого не видят, поэтому проверяем решение напрямую.
	 */
	public function testPrefilterIsActuallyEnabledWhenJitIsAvailable(): void {
		if (PCRE_JIT_SUPPORT !== true || ini_get('pcre.jit') === '0') {
			self::markTestSkipped('PCRE JIT недоступен — предфильтр отключён намеренно');
		}

		$isUsable = new ReflectionMethod(BounceDetector::class, 'isPrefilterUsable');

		self::assertTrue($isUsable->invoke(null), 'Предфильтр выключен, хотя JIT доступен');
	}

	/**
	 * Строки длиннее PREFILTER_MAX_LINE_LENGTH идут мимо предфильтра — прямым перебором.
	 */
	public function testLongLineIsMatchedTheSameWayAsNaiveScan(): void {
		$filler = str_repeat('<td class="wrapper-cell"><span>Lorem ipsum dolor sit amet</span></td>', 20);
		self::assertGreaterThan(256, strlen($filler));

		self::assertNull(BounceDetector::matchBouncePattern($filler));

		$matching = $filler . ' mailbox is full';
		self::assertSame(self::naiveMatch($matching), BounceDetector::matchBouncePattern($matching));
		self::assertSame('4.2.2', BounceDetector::matchBouncePattern($matching));
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
