<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Tests;

use PHPUnit\Framework\TestCase;
use Zoon\BounceHandler\BounceHandler;

final class MessageIdExtractionTest extends TestCase {
	private BounceHandler $handler;

	protected function setUp(): void {
		$this->handler = new BounceHandler();
	}

	public function testMessageIdFromStandard3PartMime(): void {
		$eml = $this->buildEml([
			'Return-Path' => '<>',
			'From' => 'mailer-daemon@example.com',
			'Subject' => 'Mail delivery failed',
			'Content-Type' => 'multipart/report; report-type=delivery-status; boundary="BOUND3PART"',
		], implode("\r\n", [
			'--BOUND3PART',
			'Content-Type: text/plain',
			'',
			'The message could not be delivered.',
			'user@example.com: mailbox not found',
			'',
			'--BOUND3PART',
			'Content-Type: message/delivery-status',
			'',
			'Final-Recipient: rfc822;user@example.com',
			'Action: failed',
			'Status: 5.1.1',
			'',
			'--BOUND3PART',
			'Content-Type: message/rfc822',
			'',
			'Message-ID: <original-3part@example.com>',
			'From: sender@example.com',
			'To: user@example.com',
			'Subject: Original Subject',
			'',
			'Original body text',
			'--BOUND3PART--',
		]));

		$results = $this->handler->parse($eml);

		self::assertCount(1, $results);
		self::assertSame('<original-3part@example.com>', $results[0]->messageId);
		self::assertSame('Original Subject', $results[0]->subject);
	}

	public function testMessageIdFrom2PartMimeFallback(): void {
		$eml = $this->buildEml([
			'Return-Path' => '<>',
			'From' => 'mailer-daemon@example.com',
			'Subject' => 'Mail delivery failed',
			'Content-Type' => 'multipart/mixed; boundary="BOUND2PART"',
		], implode("\r\n", [
			'--BOUND2PART',
			'',
			'Su mensaje no pudo ser entregado.',
			'user@example.com: mailbox not found',
			'',
			'--- Mensaje original adjunto.',
			'',
			'--BOUND2PART',
			'Content-Type: message/rfc822',
			'',
			'Message-ID: <original-2part@example.com>',
			'From: sender@example.com',
			'To: user@example.com',
			'Subject: Two Part Subject',
			'',
			'Original body text',
			'--BOUND2PART--',
		]));

		$results = $this->handler->parse($eml);

		self::assertCount(1, $results);
		self::assertSame('<original-2part@example.com>', $results[0]->messageId);
		self::assertSame('Two Part Subject', $results[0]->subject);
	}

	public function testMessageIdFromReferencesHeader(): void {
		$eml = $this->buildEml([
			'Return-Path' => '<>',
			'From' => 'mailer-daemon@example.com',
			'Subject' => 'Mail delivery failed',
			'References' => '<ref-fallback@example.com>',
			'X-Failed-Recipients' => 'user@example.com',
		], implode("\r\n", [
			'This message was created automatically by mail delivery software.',
			'',
			'A message that you sent could not be delivered.',
			'',
			'  user@example.com',
			'    mailbox is full',
		]));

		$results = $this->handler->parse($eml);

		self::assertCount(1, $results);
		self::assertSame('<ref-fallback@example.com>', $results[0]->messageId);
	}

	public function testMessageIdFromInReplyToHeader(): void {
		$eml = $this->buildEml([
			'Return-Path' => '<>',
			'From' => 'mailer-daemon@example.com',
			'Subject' => 'Mail delivery failed',
			'In-Reply-To' => '<inreply-fallback@example.com>',
			'X-Failed-Recipients' => 'user@example.com',
		], implode("\r\n", [
			'This message was created automatically by mail delivery software.',
			'',
			'A message that you sent could not be delivered.',
			'',
			'  user@example.com',
			'    mailbox is full',
		]));

		$results = $this->handler->parse($eml);

		self::assertCount(1, $results);
		self::assertSame('<inreply-fallback@example.com>', $results[0]->messageId);
	}

	public function testReferencesPreferredOverInReplyTo(): void {
		$eml = $this->buildEml([
			'Return-Path' => '<>',
			'From' => 'mailer-daemon@example.com',
			'Subject' => 'Mail delivery failed',
			'References' => '<from-references@example.com>',
			'In-Reply-To' => '<from-inreply@example.com>',
			'X-Failed-Recipients' => 'user@example.com',
		], implode("\r\n", [
			'This message was created automatically by mail delivery software.',
			'',
			'  user@example.com',
			'    mailbox is full',
		]));

		$results = $this->handler->parse($eml);

		self::assertCount(1, $results);
		self::assertSame('<from-references@example.com>', $results[0]->messageId);
	}

	public function testOriginalLetterMessageIdPreferredOverReferences(): void {
		$eml = $this->buildEml([
			'Return-Path' => '<>',
			'From' => 'mailer-daemon@example.com',
			'Subject' => 'Mail delivery failed',
			'References' => '<should-not-use@example.com>',
			'Content-Type' => 'multipart/report; report-type=delivery-status; boundary="BOUNDPRIO"',
		], implode("\r\n", [
			'--BOUNDPRIO',
			'Content-Type: text/plain',
			'',
			'user@example.com: mailbox not found',
			'',
			'--BOUNDPRIO',
			'Content-Type: message/delivery-status',
			'',
			'Final-Recipient: rfc822;user@example.com',
			'Action: failed',
			'Status: 5.1.1',
			'',
			'--BOUNDPRIO',
			'Content-Type: message/rfc822',
			'',
			'Message-ID: <from-original@example.com>',
			'From: sender@example.com',
			'To: user@example.com',
			'Subject: Test',
			'',
			'Body',
			'--BOUNDPRIO--',
		]));

		$results = $this->handler->parse($eml);

		self::assertCount(1, $results);
		self::assertSame('<from-original@example.com>', $results[0]->messageId);
	}

	public function testEmptyMessageIdWhenNoSourceAvailable(): void {
		$eml = $this->buildEml([
			'Return-Path' => '<>',
			'From' => 'mailer-daemon@example.com',
			'Subject' => 'Mail delivery failed',
			'X-Failed-Recipients' => 'user@example.com',
		], implode("\r\n", [
			'This message was created automatically by mail delivery software.',
			'',
			'A message that you sent could not be delivered.',
			'',
			'  user@example.com',
			'    mailbox is full',
		]));

		$results = $this->handler->parse($eml);

		self::assertCount(1, $results);
		self::assertSame('', $results[0]->messageId);
	}

	public function testReferencesWithMultipleMessageIds(): void {
		$eml = $this->buildEml([
			'Return-Path' => '<>',
			'From' => 'mailer-daemon@example.com',
			'Subject' => 'Mail delivery failed',
			'References' => '<first@example.com> <second@example.com>',
			'X-Failed-Recipients' => 'user@example.com',
		], implode("\r\n", [
			'This message was created automatically by mail delivery software.',
			'',
			'  user@example.com',
			'    mailbox is full',
		]));

		$results = $this->handler->parse($eml);

		self::assertCount(1, $results);
		self::assertSame('<first@example.com>', $results[0]->messageId);
	}

	public function testRealEmlFile1HasMessageId(): void {
		$emlPath = __DIR__ . '/../eml/1.eml';
		if (!file_exists($emlPath)) {
			self::markTestSkipped('eml/1.eml not found');
		}

		$results = $this->handler->parse(file_get_contents($emlPath));

		self::assertGreaterThan(0, count($results));
		self::assertSame(
			'<11885fd3ea338f04de950704ee6b30f5@ar1.outmailing.com>',
			$results[0]->messageId,
		);
	}

	/**
	 * @param array<string, string> $headers
	 */
	private function buildEml(array $headers, string $body): string {
		$lines = [];
		foreach ($headers as $name => $value) {
			$lines[] = "{$name}: {$value}";
		}

		return implode("\r\n", $lines) . "\r\n\r\n" . $body;
	}
}
