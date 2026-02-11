<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Parser;

use Zoon\BounceHandler\Extractor\EmailAddressExtractor;
use Zoon\BounceHandler\Resolver\StatusCodeResolver;

final class DsnParser {
	/**
	 * @return array<string, mixed>
	 */
	public static function parse(string $machineParsableBodyPart): array {
		$hash = self::splitDsnFields($machineParsableBodyPart);

		if (array_key_exists('mime_header', $hash) && is_string($hash['mime_header'])) {
			$hash['mime_header'] = HeaderParser::parseKeyValue($hash['mime_header']);
		}

		if (array_key_exists('per_message', $hash) && is_string($hash['per_message'])) {
			/** @var array<string, string|list<string>|array{type: string, addr: string}> $perMessage */
			$perMessage = HeaderParser::parseKeyValue($hash['per_message']);
			foreach (['X-postfix-sender', 'Reporting-mta', 'Received-from-mta'] as $key) {
				if (array_key_exists($key, $perMessage) && is_string($perMessage[$key])) {
					$arr = explode(';', $perMessage[$key]);
					$perMessage[$key] = [
						'type' => array_key_exists(0, $arr) ? trim($arr[0]) : '',
						'addr' => array_key_exists(1, $arr) ? trim($arr[1]) : '',
					];
				}
			}
			$hash['per_message'] = $perMessage;
		} else {
			$hash['per_message'] = [];
		}

		if (array_key_exists('per_recipient', $hash) && is_array($hash['per_recipient'])) {
			foreach ($hash['per_recipient'] as $i => $rcpt) {
				if (!is_string($rcpt)) {
					continue;
				}

				/** @var array<string, string|list<string>|array{type: string, addr: string}> $temp */
				$temp = HeaderParser::parseKeyValue(explode("\r\n", $rcpt));

				if (array_key_exists('Final-recipient', $temp) && is_string($temp['Final-recipient'])) {
					$arr = explode(';', $temp['Final-recipient']);
					$temp['Final-recipient'] = self::formatFinalRecipient($arr);
				}

				foreach (['Original-recipient', 'Diagnostic-code', 'Remote-mta'] as $key) {
					if (array_key_exists($key, $temp) && is_string($temp[$key])) {
						$arr = explode(';', $temp[$key]);
						$temp[$key] = [
							'type' => array_key_exists(0, $arr) ? trim($arr[0]) : '',
							'addr' => array_key_exists(1, $arr) ? trim($arr[1]) : '',
						];
					}
				}

				if (
					array_key_exists('Diagnostic-code', $temp)
					&& is_array($temp['Diagnostic-code'])
					&& array_key_exists('text', $temp['Diagnostic-code'])
				) {
					/** @var string $diagText */
					$diagText = $temp['Diagnostic-code']['text'];
					$ddc = StatusCodeResolver::decodeDiagnostic($diagText);
					$judgement = StatusCodeResolver::getAction($ddc);
					if ($judgement !== null
						&& $judgement->value === 'transient'
						&& array_key_exists('Action', $temp)
						&& is_string($temp['Action'])
						&& stristr($temp['Action'], 'failed') !== false
					) {
						$temp['Action'] = 'transient';
						$temp['Status'] = '4.3.0';
					}
				}

				$hash['per_recipient'][$i] = $temp;
			}
		}

		return $hash;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function splitDsnFields(string $dsnFields): array {
		$parts = explode("\r\n\r\n", $dsnFields);

		$j = 0;
		/** @var array<string, mixed> $hash */
		$hash = [];
		$partsCount = count($parts);

		for ($i = 0; $i < $partsCount; $i++) {
			$parts[$i] = trim($parts[$i]);
			if ($i === 0) {
				$hash['mime_header'] = $parts[0];
			} elseif ($i === 1 && preg_match('/(Final|Original)-Recipient/', $parts[1]) !== 1) {
				$hash['per_message'] = $parts[1];
			} else {
				if ($parts[$i] === '--') {
					continue;
				}

				if (!array_key_exists('per_recipient', $hash) || !is_array($hash['per_recipient'])) {
					$hash['per_recipient'] = [];
				}
				$hash['per_recipient'][$j] = $parts[$i];
				$j++;
			}
		}

		return $hash;
	}

	/**
	 * @param list<string> $arr
	 * @return array{addr: string, type: string}
	 */
	private static function formatFinalRecipient(array $arr): array {
		if (count($arr) > 1) {
			return [
				'type' => trim($arr[0]),
				'addr' => EmailAddressExtractor::stripAngleBrackets($arr[1]),
			];
		}

		return [
			'addr' => array_key_exists(0, $arr) ? EmailAddressExtractor::stripAngleBrackets($arr[0]) : '',
			'type' => 'unknown',
		];
	}
}
