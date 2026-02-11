<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Tests;

use PHPUnit\Framework\TestCase;
use Zoon\BounceHandler\BounceHandler;
use Zoon\BounceHandler\Enum\BounceAction;
use Zoon\BounceHandler\Enum\BounceReason;
use Zoon\BounceHandler\Enum\EmailType;

final class BounceHandlerTest extends TestCase {
	public function testParsesClassicBounceFromFixture(): void {
		$handler = new BounceHandler();
		$results = $handler->parse($this->readFixture('1.eml'));

		self::assertCount(1, $results);
		self::assertSame(EmailType::Bounce, $results[0]->emailType);
		self::assertSame(BounceAction::Failed, $results[0]->action);
		self::assertSame('5.1.1', $results[0]->deliveryStatus);
		self::assertSame('somat@ciudad.com.ar', $results[0]->recipient);
		self::assertSame(BounceReason::UserUnknown, $results[0]->reason);
	}

	public function testParsesTransientBounceFromFixture(): void {
		$handler = new BounceHandler();
		$results = $handler->parse($this->readFixture('46.eml'));

		self::assertCount(1, $results);
		self::assertSame(EmailType::Bounce, $results[0]->emailType);
		self::assertSame(BounceAction::Transient, $results[0]->action);
		self::assertSame('4.2.0', $results[0]->deliveryStatus);
		self::assertSame('geoffrey@gels.biz', $results[0]->recipient);
		self::assertSame(BounceReason::NotAccept, $results[0]->reason);
	}

	public function testParsesFblFromFixture(): void {
		$handler = new BounceHandler();
		$results = $handler->parse($this->readFixture('arf3.txt'));

		self::assertCount(1, $results);
		self::assertSame(EmailType::Fbl, $results[0]->emailType);
		self::assertSame(BounceAction::Failed, $results[0]->action);
		self::assertSame('5.7.1', $results[0]->deliveryStatus);
		self::assertSame('user@example.com', $results[0]->recipient);
		self::assertSame(BounceReason::Filtered, $results[0]->reason);
		self::assertNotNull($results[0]->feedbackReport);
		self::assertSame('192.0.2.1', $results[0]->feedbackReport?->sourceIp);
		self::assertSame('somespammer@example.net', $results[0]->feedbackReport?->originalMailFrom);
		self::assertSame('SomeGenerator/1.0', $results[0]->feedbackReport?->userAgent);
	}

	public function testParsesLegacy511FixtureAsBounce(): void {
		$handler = new BounceHandler();
		$results = $handler->parse($this->readFixture('5.1.1.eml'));

		self::assertCount(1, $results);
		self::assertSame(EmailType::Bounce, $results[0]->emailType);
		self::assertSame(BounceAction::Failed, $results[0]->action);
		self::assertSame('5.1.1', $results[0]->deliveryStatus);
		self::assertSame('someuser@somedomain.com', $results[0]->recipient);
		self::assertSame(BounceReason::UserUnknown, $results[0]->reason);
	}

	public function testParsesMailDeliveryFailedFixtureAsUserUnknownBounce(): void {
		$handler = new BounceHandler();
		$results = $handler->parse($this->readFixture('fixture_bounce_001.eml'));

		self::assertCount(1, $results);
		self::assertSame(EmailType::Bounce, $results[0]->emailType);
		self::assertSame(BounceAction::Failed, $results[0]->action);
		self::assertSame('5.7.1', $results[0]->deliveryStatus);
		self::assertSame('recipient.one@example.test', $results[0]->recipient);
		self::assertSame(BounceReason::UserUnknown, $results[0]->reason);
	}

	public function testDetectsAutoResponseFromHeaders(): void {
		$rawEmail = implode("\r\n", [
			'From: sender@example.com',
			'To: recipient@example.com',
			'Subject: Out of office',
			'Auto-submitted: auto-replied',
			'',
			'I am currently away.',
		]);

		$handler = new BounceHandler();
		$results = $handler->parse($rawEmail);

		self::assertCount(1, $results);
		self::assertSame(EmailType::AutoResponse, $results[0]->emailType);
		self::assertSame(BounceAction::AutoResponse, $results[0]->action);
		self::assertSame('Auto-submitted: auto-replied', $results[0]->autoResponse);
		self::assertSame('', $results[0]->deliveryStatus);
		self::assertSame('', $results[0]->recipient);
	}

	public function testDetectsAutoResponseFromCommonProviderHeaders(): void {
		$headerCases = [
			['X-autoreply', 'yes'],
			['X-autoresponse', 'auto'],
			['X-auto-reply-from', 'mailbox@example.com'],
			['X-ms-exchange-inbox-rules-loop', 'user@example.com'],
			['X-ms-exchange-generated-message-source', 'Mailbox Rules Agent'],
			['X-auto-response-suppress', 'OOF'],
			['X-auto-response-suppress', 'RN, NRN, AutoReply'],
		];

		foreach ($headerCases as [$name, $value]) {
			$rawEmail = implode("\r\n", [
				'From: sender@example.com',
				'To: recipient@example.com',
				"Subject: Regular message {$name}",
				"{$name}: {$value}",
				'',
				'Body',
			]);

			$handler = new BounceHandler();
			$results = $handler->parse($rawEmail);

			self::assertCount(1, $results, "Expected autoresponse for {$name}");
			self::assertSame(EmailType::AutoResponse, $results[0]->emailType, "Expected autoresponse for {$name}");
			self::assertSame(BounceAction::AutoResponse, $results[0]->action, "Expected autoresponse action for {$name}");
		}
	}

	public function testDetectsAutoResponseFromLocalizedSubjects(): void {
		$subjects = [
			'Automatic reply: out of office',
			'Automatic response',
			'OOO: traveling',
			'Abwesenheitsnotiz',
			'Réponse automatique',
			'Risposta automatica',
			'Fuori ufficio',
			'Respuesta automática',
			'Fuera de la oficina',
			'Автоответ',
			'Ответ автоматически',
			'I will be out of office until Monday',
			'This is an automated response',
		];

		foreach ($subjects as $subject) {
			$rawEmail = implode("\r\n", [
				'From: sender@example.com',
				'To: recipient@example.com',
				"Subject: {$subject}",
				'',
				'Body',
			]);

			$handler = new BounceHandler();
			$results = $handler->parse($rawEmail);

			self::assertCount(1, $results, "Expected autoresponse for subject: {$subject}");
			self::assertSame(EmailType::AutoResponse, $results[0]->emailType, "Expected autoresponse for subject: {$subject}");
			self::assertSame(BounceAction::AutoResponse, $results[0]->action, "Expected autoresponse action for subject: {$subject}");
		}
	}

	public function testDetectsFblFromFeedbackIdHeader(): void {
		$rawEmail = implode("\r\n", [
			'From: feedback-bot@example.test',
			'To: postmaster@example.test',
			'Subject: Complaint report',
			'Content-Type: multipart/report; report-type=delivery-status; boundary=fb1',
			'Feedback-ID: campaign:mailer:example',
			'',
			'--fb1',
			'Content-Type: text/plain',
			'',
			'FBL notice',
			'--fb1',
			'Content-Type: message/feedback-report',
			'',
			'Feedback-type: abuse',
			'Original-rcpt-to: rfc822;recipient.feedback@example.test',
			'User-agent: ExampleFBL/1.0',
			'--fb1',
			'Content-Type: message/rfc822',
			'',
			'To: recipient.feedback@example.test',
			'From: sender@example.test',
			'',
			'Original message',
			'--fb1--',
		]);

		$handler = new BounceHandler();
		$results = $handler->parse($rawEmail);

		self::assertCount(1, $results);
		self::assertSame(EmailType::Fbl, $results[0]->emailType);
		self::assertSame(BounceAction::Failed, $results[0]->action);
		self::assertSame('5.7.1', $results[0]->deliveryStatus);
		self::assertSame('recipient.feedback@example.test', $results[0]->recipient);
		self::assertSame(BounceReason::Filtered, $results[0]->reason);
	}

	public function testDetectsFblFromExtendedXLoopPattern(): void {
		$rawEmail = implode("\r\n", [
			'From: noreply@example.test',
			'To: postmaster@example.test',
			'Subject: Abuse report',
			'X-Loop: abuse-feedback.example.test',
			'Content-Type: multipart/report; report-type=delivery-status; boundary=fb2',
			'',
			'--fb2',
			'Content-Type: text/plain',
			'',
			'FBL notice',
			'--fb2',
			'Content-Type: message/feedback-report',
			'',
			'Feedback-type: abuse',
			'Original-rcpt-to: rfc822;recipient.xloop@example.test',
			'User-agent: ExampleFBL/1.0',
			'--fb2',
			'Content-Type: message/rfc822',
			'',
			'To: recipient.xloop@example.test',
			'From: sender@example.test',
			'',
			'Original message',
			'--fb2--',
		]);

		$handler = new BounceHandler();
		$results = $handler->parse($rawEmail);

		self::assertCount(1, $results);
		self::assertSame(EmailType::Fbl, $results[0]->emailType);
		self::assertSame('recipient.xloop@example.test', $results[0]->recipient);
		self::assertSame(BounceReason::Filtered, $results[0]->reason);
	}

	public function testDetectsFblFromNestedFeedbackReportPartWithoutTopReportType(): void {
		$rawEmail = implode("\r\n", [
			'From: reports@example.test',
			'To: postmaster@example.test',
			'Subject: Abuse feedback',
			'Content-Type: multipart/mixed; boundary=fb3',
			'',
			'--fb3',
			'Content-Type: text/plain',
			'',
			'Complaint notification',
			'--fb3',
			'Content-Type: message/feedback-report',
			'',
			'Feedback-type: abuse',
			'Original-rcpt-to: rfc822;recipient.nested@example.test',
			'User-agent: ExampleFBL/2.0',
			'--fb3',
			'Content-Type: message/rfc822',
			'',
			'To: recipient.nested@example.test',
			'From: sender@example.test',
			'',
			'Original message',
			'--fb3--',
		]);

		$handler = new BounceHandler();
		$results = $handler->parse($rawEmail);

		self::assertCount(1, $results);
		self::assertSame(EmailType::Fbl, $results[0]->emailType);
		self::assertSame(BounceAction::Failed, $results[0]->action);
		self::assertSame('5.7.1', $results[0]->deliveryStatus);
		self::assertSame('recipient.nested@example.test', $results[0]->recipient);
		self::assertSame(BounceReason::Filtered, $results[0]->reason);
	}

	private function readFixture(string $filename): string {
		$path = __DIR__ . '/../eml/' . $filename;
		$contents = file_get_contents($path);

		self::assertNotFalse($contents, "Fixture not found: {$filename}");

		return $contents;
	}
}
