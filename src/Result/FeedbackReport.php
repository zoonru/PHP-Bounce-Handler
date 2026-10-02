<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Result;

/**
 * @psalm-immutable
 */
final readonly class FeedbackReport {
	/**
	 * @psalm-capabilities read-props
	 */
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
