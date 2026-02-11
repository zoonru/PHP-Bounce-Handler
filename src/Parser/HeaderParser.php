<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Parser;

final class HeaderParser {
	/**
	 * @param string|list<string> $headers
	 * @return array<string, string|list<string>|array<string, string>>
	 */
	public static function parse(string|array $headers): array {
		if (!is_array($headers)) {
			$headers = explode("\r\n", $headers);
		}

		$hash = self::parseKeyValue($headers);

		if (array_key_exists('Content-type', $hash) && is_string($hash['Content-type'])) {
			$hash['Content-type'] = self::parseContentType($hash['Content-type']);
		}

		return $hash;
	}

	/**
	 * @param string|list<string> $content
	 * @return array<string, string|list<string>>
	 */
	public static function parseKeyValue(string|array $content): array {
		/** @var array<string, string|list<string>> $hash */
		$hash = [];
		if (!is_array($content)) {
			$content = explode("\r\n", $content);
		}

		$entity = null;
		foreach ($content as $line) {
			if ($line === '') {
				continue;
			}

			if (preg_match('/^([^\s.]*):\s*(.*)\s*/', $line, $array) === 1) {
				$entity = ucfirst(strtolower($array[1]));
				$value = $array[2];

				if (strpos($value, '=?') !== false) {
					$decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
					if ($decoded !== false) {
						$value = $decoded;
					}
				}

				if (!array_key_exists($entity, $hash) || $hash[$entity] === '') {
					$hash[$entity] = trim($value);
				} elseif (array_key_exists('Received', $hash)) {
					if ($value !== '' && is_string($hash[$entity]) && $value !== $hash[$entity]) {
						$hash[$entity] .= '|' . trim($value);
					}
				}
			} else {
				if (preg_match('/^\s+(.+)\s*/', $line, $array) === 1 && $entity !== null) {
					$continuation = $array[1];
					if (strpos($continuation, '=?') !== false) {
						$decoded = iconv_mime_decode($continuation, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
						if ($decoded !== false) {
							$continuation = $decoded;
						}
					}

					if (array_key_exists($entity, $hash) && is_string($hash[$entity])) {
						$hash[$entity] .= ' ' . $continuation;
					}
				} else {
					$entity = null;
				}
			}
		}

		if (array_key_exists('Received', $hash) && is_string($hash['Received'])) {
			$hash['Received'] = explode('|', $hash['Received']);
		}

		return $hash;
	}

	/**
	 * @return array<string, string>
	 */
	private static function parseContentType(string $contentType): array {
		$multipartReport = explode(';', $contentType);
		$result = [];
		$result['type'] = strtolower($multipartReport[0]);

		foreach ($multipartReport as $mr) {
			if (preg_match('/([^=.]*?)=(.*)/i', $mr, $matches) === 1) {
				$result[strtolower(trim($matches[1]))] = str_replace('"', '', $matches[2]);
			}
		}

		return $result;
	}
}
