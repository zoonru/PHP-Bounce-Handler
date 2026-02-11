<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Parser;

final class MimeParser {
	/**
	 * @return array{string, string}
	 */
	public static function splitHeadAndBody(string $letter): array {
		$parts = preg_split("/\r\n\r\n/", $letter, 2);
		if ($parts !== false && count($parts) === 2) {
			return [$parts[0], $parts[1]];
		}

		return [$letter, ''];
	}

	/**
	 * @return array{firstBodyPart: string, machineParsableBodyPart: string, returnedMessageBodyPart: string}
	 */
	public static function parseSections(string $body, string $boundary): array {
		if ($boundary === '') {
			return [
				'firstBodyPart' => '',
				'machineParsableBodyPart' => '',
				'returnedMessageBodyPart' => '',
			];
		}

		$parts = explode($boundary, $body);

		return [
			'firstBodyPart' => array_key_exists(1, $parts)
				? self::decodeContentTransferEncoding($parts[1])
				: '',
			'machineParsableBodyPart' => array_key_exists(2, $parts)
				? self::decodeContentTransferEncoding($parts[2])
				: '',
			'returnedMessageBodyPart' => array_key_exists(3, $parts)
				? self::decodeContentTransferEncoding($parts[3])
				: '',
		];
	}

	private static function decodeContentTransferEncoding(string $mimePart): string {
		$encoding = '7bit';
		$decoded = '';

		foreach (explode("\r\n", $mimePart) as $line) {
			if (preg_match('/^Content-Transfer-Encoding:\s*(\S+)/', $line, $match) === 1) {
				$encoding = $match[1];
				$decoded .= $line . "\r\n";
			} else {
				$decoded .= match ($encoding) {
					'quoted-printable' => self::decodeQuotedPrintableLine($line),
					'base64' => self::decodeBase64Line($line),
					default => $line . "\r\n",
				};
			}
		}

		return $decoded;
	}

	private static function decodeBase64Line(string $line): string {
		$decoded = base64_decode($line, true);

		return $decoded !== false ? $decoded : '';
	}

	private static function decodeQuotedPrintableLine(string $line): string {
		if (str_ends_with($line, '=')) {
			return quoted_printable_decode(substr($line, 0, -1));
		}

		return quoted_printable_decode($line . "\r\n");
	}

	public static function normalizeLineEndings(string $email): string {
		$email = str_replace("\r\n", "\n", $email);

		return str_replace("\n", "\r\n", $email);
	}
}
