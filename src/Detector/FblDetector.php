<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Detector;

use Zoon\BounceHandler\Extractor\EmailAddressExtractor;
use Zoon\BounceHandler\Parser\HeaderParser;
use Zoon\BounceHandler\Parser\MimeParser;
use Zoon\BounceHandler\Result\FeedbackReport;

final class FblDetector {
	/**
	 * @param array<string, string|list<string>|array<string, string>> $headHash
	 * @param array<string, string|list<string>|array<string, string>> $firstBodyHash
	 * @param array{firstBodyPart: string, machineParsableBodyPart: string, returnedMessageBodyPart: string} $mimeSections
	 * @return array{isFbl: bool, isHotmail: bool, recipient: string}
	 */
	public static function detect(array $headHash, array $firstBodyHash, array $mimeSections): array {
		if (
			array_key_exists('Content-type', $headHash)
			&& is_array($headHash['Content-type'])
			&& array_key_exists('report-type', $headHash['Content-type'])
			&& preg_match('/feedback-report/', $headHash['Content-type']['report-type']) === 1
		) {
			return ['isFbl' => true, 'isHotmail' => false, 'recipient' => ''];
		}

		if (
			array_key_exists('X-loop', $headHash)
			&& is_string($headHash['X-loop'])
			&& preg_match('/(scomp|fbl|feedback|abuse)/i', $headHash['X-loop']) === 1
		) {
			return ['isFbl' => true, 'isHotmail' => false, 'recipient' => ''];
		}

		if (
			(
				array_key_exists('Feedback-id', $headHash)
				&& is_string($headHash['Feedback-id'])
				&& $headHash['Feedback-id'] !== ''
			)
			|| (
				array_key_exists('X-feedback-id', $headHash)
				&& is_string($headHash['X-feedback-id'])
				&& $headHash['X-feedback-id'] !== ''
			)
			|| (
				array_key_exists('Feedback-id', $firstBodyHash)
				&& is_string($firstBodyHash['Feedback-id'])
				&& $firstBodyHash['Feedback-id'] !== ''
			)
			|| (
				array_key_exists('X-feedback-id', $firstBodyHash)
				&& is_string($firstBodyHash['X-feedback-id'])
				&& $firstBodyHash['X-feedback-id'] !== ''
			)
		) {
			return ['isFbl' => true, 'isHotmail' => false, 'recipient' => ''];
		}

		if (self::hasFeedbackReportPart($mimeSections)) {
			return ['isFbl' => true, 'isHotmail' => false, 'recipient' => ''];
		}

		if (array_key_exists('X-hmxmroriginalrecipient', $headHash) && is_string($headHash['X-hmxmroriginalrecipient'])) {
			return ['isFbl' => true, 'isHotmail' => true, 'recipient' => $headHash['X-hmxmroriginalrecipient']];
		}

		if (array_key_exists('X-hmxmroriginalrecipient', $firstBodyHash) && is_string($firstBodyHash['X-hmxmroriginalrecipient'])) {
			return ['isFbl' => true, 'isHotmail' => true, 'recipient' => $firstBodyHash['X-hmxmroriginalrecipient']];
		}

		return ['isFbl' => false, 'isHotmail' => false, 'recipient' => ''];
	}

	/**
	 * @param array{firstBodyPart: string, machineParsableBodyPart: string, returnedMessageBodyPart: string} $mimeSections
	 */
	private static function hasFeedbackReportPart(array $mimeSections): bool {
		foreach (
			[
				$mimeSections['firstBodyPart'],
				$mimeSections['machineParsableBodyPart'],
				$mimeSections['returnedMessageBodyPart'],
			] as $part
		) {
			if ($part === '') {
				continue;
			}

			$header = HeaderParser::parse($part);
			if (
				array_key_exists('Content-type', $header)
				&& is_array($header['Content-type'])
				&& array_key_exists('type', $header['Content-type'])
				&& $header['Content-type']['type'] === 'message/feedback-report'
			) {
				return true;
			}
		}

		return false;
	}

	private static function normalizeAddressField(string $value): string {
		$value = trim($value);
		if (preg_match('/^[a-z0-9_-]+;\s*/i', $value) === 1) {
			$value = preg_replace('/^[a-z0-9_-]+;\s*/i', '', $value) ?? $value;
		}

		return EmailAddressExtractor::stripAngleBrackets($value);
	}

	/**
	 * @param array<string, string|list<string>|array<string, string>> $headHash
	 * @param array<string, string|list<string>|array<string, string>> $firstBodyHash
	 * @param array{firstBodyPart: string, machineParsableBodyPart: string, returnedMessageBodyPart: string} $mimeSections
	 */
	public static function parseFblReport(
		bool $isHotmail,
		string $recipient,
		array $headHash,
		array $firstBodyHash,
		array $mimeSections,
	): FeedbackReport {
		if ($isHotmail) {
			$sourceIp = '';
			$originalMailFrom = '';
			$receivedDate = '';

			if (array_key_exists('Date', $firstBodyHash) && is_string($firstBodyHash['Date'])) {
				$receivedDate = $firstBodyHash['Date'];
			}

			if (
				array_key_exists('Subject', $headHash)
				&& is_string($headHash['Subject'])
				&& preg_match('/complaint about message from ([0-9.]+)/', $headHash['Subject'], $matches) === 1
			) {
				$sourceIp = $matches[1];
			}

			if (array_key_exists('X-sid-pra', $firstBodyHash) && is_string($firstBodyHash['X-sid-pra'])) {
				$originalMailFrom = $firstBodyHash['X-sid-pra'];
			}

			return new FeedbackReport(
				sourceIp: $sourceIp,
				originalMailFrom: self::normalizeAddressField($originalMailFrom),
				originalRcptTo: self::normalizeAddressField($recipient),
				feedbackType: 'abuse',
				userAgent: 'Hotmail FBL',
				receivedDate: $receivedDate,
			);
		}

		$fblHash = HeaderParser::parseKeyValue($mimeSections['machineParsableBodyPart']);
		$returnedHash = HeaderParser::parseKeyValue($mimeSections['returnedMessageBodyPart']);

		$originalMailFrom = '';
		if (array_key_exists('Return-path', $returnedHash) && is_string($returnedHash['Return-path']) && $returnedHash['Return-path'] !== '') {
			$originalMailFrom = $returnedHash['Return-path'];
		} elseif (
			(!array_key_exists('Original-mail-from', $fblHash) || $fblHash['Original-mail-from'] === '')
			&& array_key_exists('From', $returnedHash)
			&& is_string($returnedHash['From'])
		) {
			$originalMailFrom = $returnedHash['From'];
		} elseif (array_key_exists('Original-mail-from', $fblHash) && is_string($fblHash['Original-mail-from'])) {
			$originalMailFrom = $fblHash['Original-mail-from'];
		}

		$originalRcptTo = '';
		if (
			array_key_exists('Original-rcpt-to', $fblHash)
			&& is_string($fblHash['Original-rcpt-to'])
			&& $fblHash['Original-rcpt-to'] !== ''
		) {
			$originalRcptTo = $fblHash['Original-rcpt-to'];
		} elseif (array_key_exists('Removal-recipient', $fblHash) && is_string($fblHash['Removal-recipient'])) {
			$originalRcptTo = $fblHash['Removal-recipient'];
		} elseif (array_key_exists('To', $returnedHash) && is_string($returnedHash['To'])) {
			$originalRcptTo = $returnedHash['To'];
		}

		if (
			preg_match('/Undisclosed|redacted/i', $originalRcptTo) === 1
			&& array_key_exists('Removal-recipient', $fblHash)
			&& is_string($fblHash['Removal-recipient'])
		) {
			$originalRcptTo = $fblHash['Removal-recipient'];
		}

		$sourceIp = '';
		if (array_key_exists('Source-ip', $fblHash) && is_string($fblHash['Source-ip']) && $fblHash['Source-ip'] !== '') {
			$sourceIp = $fblHash['Source-ip'];
		} elseif (array_key_exists('X-originating-ip', $returnedHash) && is_string($returnedHash['X-originating-ip'])) {
			$sourceIp = EmailAddressExtractor::stripAngleBrackets($returnedHash['X-originating-ip']);
		}

		$receivedDate = '';
		if (array_key_exists('Received-date', $fblHash) && is_string($fblHash['Received-date']) && $fblHash['Received-date'] !== '') {
			$receivedDate = $fblHash['Received-date'];
		} elseif (array_key_exists('Arrival-date', $fblHash) && is_string($fblHash['Arrival-date'])) {
			$receivedDate = $fblHash['Arrival-date'];
		}

		$feedbackType = '';
		if (array_key_exists('Feedback-type', $fblHash) && is_string($fblHash['Feedback-type'])) {
			$feedbackType = $fblHash['Feedback-type'];
		}

		$userAgent = '';
		if (array_key_exists('User-agent', $fblHash) && is_string($fblHash['User-agent'])) {
			$userAgent = $fblHash['User-agent'];
		}

		return new FeedbackReport(
			sourceIp: $sourceIp,
			originalMailFrom: self::normalizeAddressField($originalMailFrom),
			originalRcptTo: self::normalizeAddressField($originalRcptTo),
			feedbackType: $feedbackType,
			userAgent: $userAgent,
			receivedDate: $receivedDate,
		);
	}
}
