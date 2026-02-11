<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Result;

final readonly class DiagnosticCode {
	public function __construct(
		public int $code,
		public string $text,
	) {
	}
}
