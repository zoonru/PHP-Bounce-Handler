<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Tests;

use PHPUnit\Framework\TestCase;
use Zoon\BounceHandler\Enum\BounceAction;
use Zoon\BounceHandler\Enum\BounceReason;
use Zoon\BounceHandler\Resolver\StatusCodeResolver;

final class StatusCodeResolverTest extends TestCase {
	public function testFormatSupportsDottedAndCompactCodes(): void {
		self::assertSame(
			['code' => '5.1.1', 'text' => 'mailbox unavailable'],
			StatusCodeResolver::format('5.1.1 mailbox unavailable'),
		);

		self::assertSame(
			['code' => '5.1.1', 'text' => 'mailbox unavailable'],
			StatusCodeResolver::format('511 mailbox unavailable'),
		);
	}

	public function testGetActionReturnsExpectedEnum(): void {
		self::assertSame(BounceAction::Success, StatusCodeResolver::getAction('2.1.5 delivered'));
		self::assertSame(BounceAction::Transient, StatusCodeResolver::getAction('4.2.2 mailbox full'));
		self::assertSame(BounceAction::Failed, StatusCodeResolver::getAction('5.1.1 user unknown'));
		self::assertNull(StatusCodeResolver::getAction('invalid code'));
	}

	public function testGetReasonReturnsExpectedReason(): void {
		self::assertSame(BounceReason::Filtered, StatusCodeResolver::getReason('5.7.1'));
		self::assertSame(BounceReason::NotAccept, StatusCodeResolver::getReason('4.2.0'));
		self::assertSame(BounceReason::UserUnknown, StatusCodeResolver::getReason('5.1.1'));
	}

	public function testDecodeDiagnosticExtractsRfcAndSmtpCodes(): void {
		self::assertSame('5.1.1', StatusCodeResolver::decodeDiagnostic('smtp; 5.1.1 User unknown'));
		self::assertSame('550', StatusCodeResolver::decodeDiagnostic('smtp; 550 User unknown'));
		self::assertSame('', StatusCodeResolver::decodeDiagnostic('No diagnostic code'));
	}
}
