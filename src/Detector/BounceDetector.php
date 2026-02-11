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

			foreach (BouncePatterns::BOUNCE_LIST as $bouncetext => $bouncecode) {
				if (preg_match("/{$bouncetext}/i", $line, $matches) === 1) {
					if (array_key_exists(1, $matches) && $bouncecode === 'x') {
						return $matches[1];
					}

					return $bouncecode;
				}
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
