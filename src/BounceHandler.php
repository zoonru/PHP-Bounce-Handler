<?php

declare(strict_types=1);

namespace Zoon\BounceHandler;

use Zoon\BounceHandler\Detector\AutoResponseDetector;
use Zoon\BounceHandler\Detector\BounceDetector;
use Zoon\BounceHandler\Detector\FblDetector;
use Zoon\BounceHandler\Enum\BounceAction;
use Zoon\BounceHandler\Enum\BounceReason;
use Zoon\BounceHandler\Enum\EmailType;
use Zoon\BounceHandler\Extractor\EmailAddressExtractor;
use Zoon\BounceHandler\Parser\DsnParser;
use Zoon\BounceHandler\Parser\HeaderParser;
use Zoon\BounceHandler\Parser\MimeParser;
use Zoon\BounceHandler\Resolver\StatusCodeResolver;
use Zoon\BounceHandler\Result\BounceResult;
use Zoon\BounceHandler\Result\DiagnosticCode;

final class BounceHandler {
	/**
	 * @return list<BounceResult>
	 */
	public function parse(string $rawEmail): array {
		$email = MimeParser::normalizeLineEndings($rawEmail);
		[$head, $body] = MimeParser::splitHeadAndBody($email);
		$headHash = HeaderParser::parse($head);

		$boundary = '';
		if (
			array_key_exists('Content-type', $headHash)
			&& is_array($headHash['Content-type'])
			&& array_key_exists('boundary', $headHash['Content-type'])
		) {
			$boundary = $headHash['Content-type']['boundary'];
		}

		$mimeSections = MimeParser::parseSections($body, $boundary);

		$originalLetterBody = '';
		/** @var array<string, string|list<string>|array<string, string>> $originalLetterHeader */
		$originalLetterHeader = [];

		$originalLetter = $this->recoverOriginalLetter($mimeSections, $body, $email);
		if ($originalLetter !== '') {
			[$originalHead, $originalLetterBody] = MimeParser::splitHeadAndBody($originalLetter);
			$originalLetterHeader = HeaderParser::parse($originalHead);
		}

		/** @var list<string> $bodyHash */
		$bodyHash = explode("\r\n", $body);
		$firstBodyHash = $mimeSections['firstBodyPart'] !== ''
			? HeaderParser::parse($mimeSections['firstBodyPart'])
			: [];

		$isBounce = BounceDetector::isBounce($headHash);
		$fblResult = FblDetector::detect($headHash, $firstBodyHash, $mimeSections);
		$isFbl = $fblResult['isFbl'];
		$isHotmail = $fblResult['isHotmail'];
		$fblRecipient = $fblResult['recipient'];

		$isAutoResponse = !$isBounce && !$isFbl && AutoResponseDetector::detect($headHash)['isAutoResponse'];
		$autoResponseText = '';
		if ($isAutoResponse) {
			$autoResponseText = AutoResponseDetector::detect($headHash)['autoResponse'];
		}

		// Handle original letter in senderscore FBL case
		if ($isFbl && $originalLetter !== '' && count($originalLetterHeader) > 0) {
			$olBoundary = '';
			if (
				array_key_exists('Content-type', $originalLetterHeader)
				&& is_array($originalLetterHeader['Content-type'])
				&& array_key_exists('boundary', $originalLetterHeader['Content-type'])
			) {
				$olBoundary = $originalLetterHeader['Content-type']['boundary'];
			}

			if ($olBoundary !== '') {
				$olMimeSections = MimeParser::parseSections($originalLetterBody, $olBoundary);
				$olMpbp = $olMimeSections['machineParsableBodyPart'] !== ''
					? HeaderParser::parse($olMimeSections['machineParsableBodyPart'])
					: [];

				if (
					array_key_exists('Content-type', $olMpbp)
					&& is_array($olMpbp['Content-type'])
					&& array_key_exists('type', $olMpbp['Content-type'])
					&& $olMpbp['Content-type']['type'] === 'message/feedback-report'
					&& $olMimeSections['returnedMessageBodyPart'] !== ''
				) {
					[, $originalLetter] = MimeParser::splitHeadAndBody(
						$olMimeSections['returnedMessageBodyPart'],
					);
					[$originalHead] = MimeParser::splitHeadAndBody($originalLetter);
					$originalLetterHeader = HeaderParser::parse($originalHead);
				}
			}
		}

		/** @var list<array{action: string, deliverystatus: string, recipient: string, autoresponse?: string}> $output */
		$output = [];

		if ($isFbl) {
			$output[] = $this->processFbl(
				$isHotmail,
				$fblRecipient,
				$headHash,
				$firstBodyHash,
				$mimeSections,
			);
		} elseif ($isAutoResponse) {
			$output = $this->processAutoResponse($headHash, $body, $autoResponseText);
		} elseif ($this->isRfc1892MultipartReport($headHash, $boundary)) {
			$output = $this->processRfc1892($mimeSections, $bodyHash);
		} elseif (array_key_exists('X-failed-recipients', $headHash) && is_string($headHash['X-failed-recipients'])) {
			$output = $this->processXFailedRecipients($headHash['X-failed-recipients'], $bodyHash);
		} elseif ($boundary !== '' && $isBounce) {
			$output = $this->processGenericBounceWithMime($mimeSections['firstBodyPart'], $bodyHash);
		} elseif ($isBounce) {
			$output = $this->processLastDitchBounce($body, $bodyHash);
		}

		if (count($output) === 0) {
			$output[] = ['action' => '', 'deliverystatus' => '', 'recipient' => ''];
		}

		$diagnosticCode = $this->extractDiagnosticCode($bodyHash);

		$emailType = $this->determineEmailType($isBounce, $isFbl, $isAutoResponse);
		$messageId = '';
		if (array_key_exists('Message-id', $originalLetterHeader) && is_string($originalLetterHeader['Message-id'])) {
			$messageId = $originalLetterHeader['Message-id'];
		}
		if ($messageId === '' && array_key_exists('References', $headHash) && is_string($headHash['References'])) {
			if (preg_match('/<[^>]+>/', $headHash['References'], $refMatch) === 1) {
				$messageId = $refMatch[0];
			}
		}
		if ($messageId === '' && array_key_exists('In-reply-to', $headHash) && is_string($headHash['In-reply-to'])) {
			if (preg_match('/<[^>]+>/', $headHash['In-reply-to'], $replyMatch) === 1) {
				$messageId = $replyMatch[0];
			}
		}

		$subject = '';
		if (array_key_exists('Subject', $originalLetterHeader) && is_string($originalLetterHeader['Subject'])) {
			$subject = $originalLetterHeader['Subject'];
		}

		$feedbackReport = null;
		if ($isFbl) {
			$feedbackReport = FblDetector::parseFblReport(
				$isHotmail,
				$fblRecipient,
				$headHash,
				$firstBodyHash,
				$mimeSections,
			);
		}

		$results = [];
		foreach ($output as $item) {
			$recipient = trim($item['recipient']);
			$deliveryStatus = $item['deliverystatus'];
			$actionStr = $item['action'];

			if ($recipient === '' && $actionStr === '' && $deliveryStatus === '') {
				continue;
			}

			$action = BounceAction::tryFrom($actionStr);
			if ($action === null) {
				$action = $isAutoResponse ? BounceAction::AutoResponse : BounceAction::Failed;
			}

			$reason = StatusCodeResolver::getReason($deliveryStatus);
			if (
				$reason === BounceReason::Filtered
				&& $emailType !== EmailType::Fbl
			) {
				$reason = BounceReason::UserUnknown;
			}

			$results[] = new BounceResult(
				emailType: $emailType ?? EmailType::Bounce,
				action: $action,
				deliveryStatus: $deliveryStatus,
				recipient: $recipient,
				reason: $reason,
				messageId: $messageId,
				subject: $subject,
				diagnosticCode: $diagnosticCode,
				feedbackReport: $feedbackReport,
				autoResponse: array_key_exists('autoresponse', $item) ? $item['autoresponse'] : '',
			);
		}

		return $results;
	}

	/**
	 * @param array{firstBodyPart: string, machineParsableBodyPart: string, returnedMessageBodyPart: string} $mimeSections
	 */
	private function recoverOriginalLetter(
		array $mimeSections,
		string $body,
		string $email,
	): string {
		if ($mimeSections['returnedMessageBodyPart'] !== '') {
			[, $letter] = MimeParser::splitHeadAndBody($mimeSections['returnedMessageBodyPart']);
			if ($letter !== '') {
				return $letter;
			}
		}

		// Fallback for 2-part MIME bounces where original message is in part 2 instead of part 3
		if ($mimeSections['machineParsableBodyPart'] !== '') {
			[$mpbpHead] = MimeParser::splitHeadAndBody($mimeSections['machineParsableBodyPart']);
			if (stripos($mpbpHead, 'message/rfc822') !== false) {
				[, $letter] = MimeParser::splitHeadAndBody($mimeSections['machineParsableBodyPart']);
				if ($letter !== '') {
					return $letter;
				}
			}
		}

		$yourCopyMarker = '------ This is a copy of your message, including all the headers. ------';
		if (strpos($body, $yourCopyMarker) !== false) {
			$parts = preg_split(
				'/\s{4}------ This is a copy of your message, including all the headers\. ------[\s\S]*?\s{4}/',
				$body,
				2,
			);
			if ($parts !== false && array_key_exists(1, $parts)) {
				return $parts[1];
			}
		}

		$theCopyMarker = '------ This is a copy of the message, including all the headers. ------';
		if (strpos($body, $theCopyMarker) !== false) {
			$parts = preg_split(
				'/\s{4}------ This is a copy of the message, including all the headers\. ------[\s\S]*?\s{4}/',
				$body,
				2,
			);
			if ($parts !== false && array_key_exists(1, $parts)) {
				return $parts[1];
			}
		}

		$letters = preg_split('/\nReturn-path:[^\n]*\n/i', $email, 3, PREG_SPLIT_NO_EMPTY);
		if ($letters !== false && array_key_exists(2, $letters)) {
			return $letters[2];
		}

		return '';
	}

	/**
	 * @param array<string, string|list<string>|array<string, string>> $headHash
	 */
	private function isRfc1892MultipartReport(array $headHash, string $boundary): bool {
		return array_key_exists('Content-type', $headHash)
			&& is_array($headHash['Content-type'])
			&& array_key_exists('type', $headHash['Content-type'])
			&& $headHash['Content-type']['type'] === 'multipart/report'
			&& array_key_exists('report-type', $headHash['Content-type'])
			&& $headHash['Content-type']['report-type'] === 'delivery-status'
			&& $boundary !== ''
		;
	}

	/**
	 * @param array<string, string|list<string>|array<string, string>> $headHash
	 * @param array<string, string|list<string>|array<string, string>> $firstBodyHash
	 * @param array{firstBodyPart: string, machineParsableBodyPart: string, returnedMessageBodyPart: string} $mimeSections
	 * @return array{action: string, deliverystatus: string, recipient: string}
	 */
	private function processFbl(
		bool $isHotmail,
		string $fblRecipient,
		array $headHash,
		array $firstBodyHash,
		array $mimeSections,
	): array {
		$report = FblDetector::parseFblReport(
			$isHotmail,
			$fblRecipient,
			$headHash,
			$firstBodyHash,
			$mimeSections,
		);

		return ['action' => 'failed', 'deliverystatus' => '5.7.1', 'recipient' => $report->originalRcptTo];
	}

	/**
	 * @param array<string, string|list<string>|array<string, string>> $headHash
	 * @return list<array{action: string, deliverystatus: string, recipient: string, autoresponse: string}>
	 */
	private function processAutoResponse(array $headHash, string $body, string $autoResponseText): array {
		$recipient = '';
		if (array_key_exists('Return-path', $headHash) && is_string($headHash['Return-path'])) {
			$recipient = EmailAddressExtractor::stripAngleBrackets($headHash['Return-path']);
		}

		if ($recipient === '') {
			$found = EmailAddressExtractor::findAll($body);
			if (count($found) > 0) {
				$recipient = trim($found[0]);
			}
		}

		return [[
			'action' => 'autoresponse',
			'deliverystatus' => '',
			'recipient' => $recipient,
			'autoresponse' => $autoResponseText,
		]];
	}

	/**
	 * @param array{firstBodyPart: string, machineParsableBodyPart: string, returnedMessageBodyPart: string} $mimeSections
	 * @param list<string> $bodyHash
	 * @return list<array{action: string, deliverystatus: string, recipient: string}>
	 */
	private function processRfc1892(array $mimeSections, array $bodyHash): array {
		$rptHash = DsnParser::parse($mimeSections['machineParsableBodyPart']);
		$output = [];

		if (array_key_exists('per_recipient', $rptHash) && is_array($rptHash['per_recipient'])) {
			foreach ($rptHash['per_recipient'] as $perRcpt) {
				if (!is_array($perRcpt)) {
					continue;
				}

				/** @var array<string, array{addr?: string, type?: string}|string> $perRcpt */
				$recipient = EmailAddressExtractor::findRecipient($perRcpt);
				$status = array_key_exists('Status', $perRcpt) && is_string($perRcpt['Status'])
					? $perRcpt['Status']
					: '';
				$mycode = StatusCodeResolver::format($status);
				$action = array_key_exists('Action', $perRcpt) && is_string($perRcpt['Action'])
					? $perRcpt['Action']
					: '';

				$output[] = [
					'recipient' => $recipient,
					'deliverystatus' => $mycode['code'],
					'action' => $action,
				];
			}
		} else {
			$arrFailed = EmailAddressExtractor::findAll($mimeSections['firstBodyPart']);
			foreach ($arrFailed as $addr) {
				$deliveryStatus = BounceDetector::getStatusCodeFromText(trim($addr), 0, $bodyHash);
				$output[] = [
					'recipient' => trim($addr),
					'deliverystatus' => $deliveryStatus,
					'action' => StatusCodeResolver::getActionString($deliveryStatus),
				];
			}
		}

		return $output;
	}

	/**
	 * @param list<string> $bodyHash
	 * @return list<array{action: string, deliverystatus: string, recipient: string}>
	 */
	private function processXFailedRecipients(string $xFailedRecipients, array $bodyHash): array {
		$output = [];
		$arrFailed = explode(',', $xFailedRecipients);
		foreach ($arrFailed as $addr) {
			$addr = trim($addr);
			$deliveryStatus = BounceDetector::getStatusCodeFromText($addr, 0, $bodyHash);
			$output[] = [
				'recipient' => $addr,
				'deliverystatus' => $deliveryStatus,
				'action' => StatusCodeResolver::getActionString($deliveryStatus),
			];
		}

		return $output;
	}

	/**
	 * @param list<string> $bodyHash
	 * @return list<array{action: string, deliverystatus: string, recipient: string}>
	 */
	private function processGenericBounceWithMime(string $firstBodyPart, array $bodyHash): array {
		$output = [];
		$arrFailed = EmailAddressExtractor::findAll($firstBodyPart);
		foreach ($arrFailed as $addr) {
			$addr = trim($addr);
			$deliveryStatus = BounceDetector::getStatusCodeFromText($addr, 0, $bodyHash);
			$output[] = [
				'recipient' => $addr,
				'deliverystatus' => $deliveryStatus,
				'action' => StatusCodeResolver::getActionString($deliveryStatus),
			];
		}

		return $output;
	}

	/**
	 * @param list<string> $bodyHash
	 * @return list<array{action: string, deliverystatus: string, recipient: string}>
	 */
	private function processLastDitchBounce(string $body, array $bodyHash): array {
		$output = [];
		$arrFailed = EmailAddressExtractor::findAll($body);
		foreach ($arrFailed as $addr) {
			$addr = trim($addr);
			$deliveryStatus = BounceDetector::getStatusCodeFromText($addr, 0, $bodyHash);
			$output[] = [
				'recipient' => $addr,
				'deliverystatus' => $deliveryStatus,
				'action' => StatusCodeResolver::getActionString($deliveryStatus),
			];
		}

		return $output;
	}

	/**
	 * @param list<string> $bodyHash
	 */
	private function extractDiagnosticCode(array $bodyHash): ?DiagnosticCode {
		foreach ($bodyHash as $line) {
			if (preg_match('~^Diagnostic\-Code:(.*)$~isuD', $line, $m) === 1
				&& preg_match('~(\d\d\d)(.*)$~isuD', $m[1], $m2) === 1
			) {
				return new DiagnosticCode(
					code: (int) $m2[1],
					text: trim($m2[2]),
				);
			}
		}

		return null;
	}

	private function determineEmailType(bool $isBounce, bool $isFbl, bool $isAutoResponse): ?EmailType {
		if ($isBounce) {
			return EmailType::Bounce;
		}

		if ($isFbl) {
			return EmailType::Fbl;
		}

		if ($isAutoResponse) {
			return EmailType::AutoResponse;
		}

		return null;
	}
}
