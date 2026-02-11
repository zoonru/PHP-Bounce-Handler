<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Detector;

use Zoon\BounceHandler\Data\BouncePatterns;

final class AutoResponseDetector {
	/**
	 * @param array<string, string|list<string>|array<string, string>> $headHash
	 * @return array{isAutoResponse: bool, autoResponse: string}
	 */
	public static function detect(array $headHash): array {
		foreach (
			[
				'Auto-submitted',
				'X-autorespond',
				'X-autoreply',
				'X-autoresponse',
				'X-auto-reply-from',
				'X-ms-exchange-inbox-rules-loop',
				'X-ms-exchange-generated-message-source',
			] as $a
		) {
			if (array_key_exists($a, $headHash) && is_string($headHash[$a])) {
				return [
					'isAutoResponse' => true,
					'autoResponse' => "{$a}: {$headHash[$a]}",
				];
			}
		}

		if (
			array_key_exists('X-auto-response-suppress', $headHash)
			&& is_string($headHash['X-auto-response-suppress'])
			&& preg_match('/(?:^|,\\s*)(OOF|AutoReply|All)(?:\\s*,|$)/i', $headHash['X-auto-response-suppress']) === 1
		) {
			return [
				'isAutoResponse' => true,
				'autoResponse' => 'X-auto-response-suppress: ' . $headHash['X-auto-response-suppress'],
			];
		}

		foreach (['Precedence', 'X-precedence'] as $a) {
			if (
				array_key_exists($a, $headHash)
				&& is_string($headHash[$a])
				&& preg_match('/^(auto|junk)/i', $headHash[$a]) === 1
			) {
				return [
					'isAutoResponse' => true,
					'autoResponse' => "{$a}: {$headHash[$a]}",
				];
			}
		}

		$subj = '';
		if (array_key_exists('Subject', $headHash) && is_string($headHash['Subject'])) {
			$subj = $headHash['Subject'];
		}

		foreach (BouncePatterns::AUTO_RESPOND_LIST as $a) {
			if (preg_match("/{$a}/ui", $subj) === 1) {
				return [
					'isAutoResponse' => true,
					'autoResponse' => $subj,
				];
			}
		}

		return ['isAutoResponse' => false, 'autoResponse' => ''];
	}
}
