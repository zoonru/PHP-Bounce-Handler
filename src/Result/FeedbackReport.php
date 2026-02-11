<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Result;

final readonly class FeedbackReport {
	public function __construct(
		public string $sourceIp,
		public string $originalMailFrom,
		public string $originalRcptTo,
		public string $feedbackType,
		public string $userAgent,
		public string $receivedDate,
	) {
	}
}
