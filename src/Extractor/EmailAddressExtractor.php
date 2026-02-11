<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Extractor;

final class EmailAddressExtractor {
	/**
	 * @return list<non-empty-string>
	 */
	public static function findAll(string $text): array {
		if (preg_match('/\b([A-Z0-9._%-]+@[A-Z0-9.-]+\.[A-Z]{2,4})\b/i', $text, $matches) === 1) {
			/** @var non-empty-string $email */
			$email = $matches[1];

			return [$email];
		}

		return [];
	}

	public static function stripAngleBrackets(string $str): string {
		if (preg_match('/[<\[](.*)[>\]]/', $str, $matches) === 1) {
			return trim($matches[1]);
		}

		return trim($str);
	}

	public static function extractAddress(string $str): string {
		$result = '';
		$parts = preg_split('/[ "\'\<\>:\(\)\[\]]/', $str);
		if ($parts === false) {
			return '';
		}

		foreach ($parts as $part) {
			if (strpos($part, '@') !== false) {
				$result = $part;
			}
		}

		return $result;
	}

	/**
	 * @param array<string, array{addr?: string, type?: string}|string> $perRcpt
	 */
	public static function findRecipient(array $perRcpt): string {
		$recipient = '';
		if (
			array_key_exists('Original-recipient', $perRcpt)
			&& is_array($perRcpt['Original-recipient'])
			&& array_key_exists('addr', $perRcpt['Original-recipient'])
			&& $perRcpt['Original-recipient']['addr'] !== ''
		) {
			$recipient = $perRcpt['Original-recipient']['addr'];
		} elseif (
			array_key_exists('Final-recipient', $perRcpt)
			&& is_array($perRcpt['Final-recipient'])
			&& array_key_exists('addr', $perRcpt['Final-recipient'])
			&& $perRcpt['Final-recipient']['addr'] !== ''
		) {
			$recipient = $perRcpt['Final-recipient']['addr'];
		}

		return self::stripAngleBrackets($recipient);
	}
}
