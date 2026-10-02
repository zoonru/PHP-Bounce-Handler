<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Result;

/**
 * @psalm-immutable
 */
final readonly class DiagnosticCode {
	/**
	 * @psalm-capabilities read-props
	 */
	public function __construct(
		public int $code,
		public string $text,
	) {
	}
}
