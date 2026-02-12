<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Tests;

use PHPUnit\Framework\TestCase;
use Zoon\BounceHandler\Parser\HeaderParser;

final class HeaderParserTest extends TestCase {
	public function testParseKeyValueDoesNotEmitWarningsOnInvalidMimeEncodedHeader(): void {
		$headers = [
			'Subject: =?UTF-8?Q?Broken_=ZZ?=',
			' =?UTF-8?Q?continuation_=XX?=',
		];

		set_error_handler(static function (int $severity, string $message): bool {
			throw new \ErrorException($message, 0, $severity);
		});

		try {
			$parsed = HeaderParser::parseKeyValue($headers);
		} finally {
			restore_error_handler();
		}

		self::assertArrayHasKey('Subject', $parsed);
		self::assertIsString($parsed['Subject']);
		self::assertNotSame('', $parsed['Subject']);
	}
}
