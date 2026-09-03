<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Detector;

use Zoon\BounceHandler\Data\BouncePatterns;
use Zoon\BounceHandler\Extractor\EmailAddressExtractor;

final class BounceDetector {
	/**
	 * @param array<string, string|list<string>|array<string, string>> $headHash
	 */
	public static function isBounce(array $headHash): bool {
		foreach (BouncePatterns::BOUNCE_SUBJECTS as $s) {
			if (
				array_key_exists('Subject', $headHash)
				&& is_string($headHash['Subject'])
				&& preg_match("/^{$s}/ui", $headHash['Subject']) === 1
			) {
				return true;
			}
		}

		if (
			array_key_exists('From', $headHash)
			&& is_string($headHash['From'])
			&& preg_match('/^(postmaster|mailer-daemon)\@?/i', $headHash['From']) === 1
		) {
			return true;
		}

		return false;
	}

	/**
	 * Longest line, in bytes, that the combined prefilter is worth running on.
	 *
	 * The two strategies have opposite cost shapes. The per-pattern scan pays ~200 preg_match
	 * calls per line, but each is a literal search PCRE can start with a memchr, so it costs
	 * almost nothing per byte. The combined alternation is a single call, but its start-code-unit
	 * bitmap covers most letters, so nearly every offset retries ~200 branches and the cost grows
	 * with the length of the line. Measured on PHP 8.4 / PCRE 10.42 the two meet near 350 bytes,
	 * and past that the prefilter loses without bound: on one unwrapped 110 KB HTML line the scan
	 * takes 0.9 ms and the prefilter 6.0 ms.
	 *
	 * Real message bodies sit far below the crossover - over the 37868 lines of the eml/ corpus
	 * p99 is 104 bytes and the longest line is 972 - so the prefilter still covers nearly every
	 * line, while unwrapped 8bit HTML in a returned original message no longer regresses.
	 */
	private const int PREFILTER_MAX_LINE_LENGTH = 256;

	private static ?bool $prefilterUsable = null;

	/**
	 * Returns the status code of the first BOUNCE_LIST pattern matching the line, or null when none does.
	 */
	public static function matchBouncePattern(string $line): ?string {
		// Cheap prefilter: one combined pattern instead of ~200 preg_match calls per line.
		// Exactly 0 means no alternation branch matched, so the detailed scan cannot match either.
		// On a PCRE error (false) we deliberately fall through to the scan rather than lose a match.
		if (
			strlen($line) <= self::PREFILTER_MAX_LINE_LENGTH
			&& self::isPrefilterUsable()
			&& preg_match(BouncePatterns::getCombinedBounceRegex(), $line) === 0
		) {
			return null;
		}

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
	 * The prefilter only pays off while PCRE can JIT-compile it. Interpreted, the combined
	 * alternation is several times slower than the per-pattern scan at any line length, so on a
	 * build without JIT support - or with pcre.jit turned off - the scan is used unconditionally.
	 */
	private static function isPrefilterUsable(): bool {
		if (self::$prefilterUsable === null) {
			self::$prefilterUsable = PCRE_JIT_SUPPORT === true && ini_get('pcre.jit') !== '0';
		}

		return self::$prefilterUsable;
	}

	/**
	 * @param list<string> $bodyHash
	 */
	public static function getStatusCodeFromText(string $recipient, int $startIndex, array $bodyHash): string {
		for ($i = $startIndex, $iMax = count($bodyHash); $i < $iMax; $i++) {
			$line = trim($bodyHash[$i]);

			if ($line === '') {
				continue;
			}

			if (stripos($line, 'Message-ID') !== false) {
				continue;
			}

			if (stristr($line, '------ This is a copy of the message') !== false) {
				break;
			}

			if (stristr($line, 'Mensaje original adjunto') !== false) {
				break;
			}

			if (
				count(EmailAddressExtractor::findAll($line)) >= 1
				&& stristr($line, $recipient) === false
				&& strstr($line, 'FROM:<') === false
			) {
				continue;
			}

			$bounceCode = self::matchBouncePattern($line);
			if ($bounceCode !== null) {
				return $bounceCode;
			}

			if (preg_match('/\W([245]\.[01234567]\.\d{1,2})\W/', $line, $matches) === 1) {
				return $matches[1];
			}

			if (
				preg_match('/\]?: ([45][01257][012345]) /', $line, $matches) === 1
				|| preg_match('/^([45][01257][012345]) (?:.*?)(?:denied|inactive|deactivated|rejected|disabled|unknown|no such|not (?:our|activated|a valid))+/i', $line, $matches) === 1
			) {
				$mycode = $matches[1];

				return match ($mycode) {
					'550', '551', '553', '554' => '5.1.1',
					'452', '552' => '4.2.2',
					'450', '421' => '4.3.2',
					default => '5.5.0',
				};
			}
		}

		return '5.5.0';
	}
}
