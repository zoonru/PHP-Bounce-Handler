<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Result;

use Zoon\BounceHandler\Enum\BounceAction;
use Zoon\BounceHandler\Enum\BounceReason;
use Zoon\BounceHandler\Enum\EmailType;

final readonly class BounceResult {
	public function __construct(
		public EmailType $emailType,
		public BounceAction $action,
		public string $deliveryStatus,
		public string $recipient,
		public BounceReason $reason,
		public string $messageId,
		public string $subject,
		public ?DiagnosticCode $diagnosticCode,
		public ?FeedbackReport $feedbackReport,
		public string $autoResponse,
	) {
	}
}
