<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Resolver;

use Zoon\BounceHandler\Data\StatusCodes;
use Zoon\BounceHandler\Enum\BounceAction;
use Zoon\BounceHandler\Enum\BounceReason;

final class StatusCodeResolver {
	/**
	 * @return array{code: string, text: string}
	 */
	public static function format(string $code): array {
		if (preg_match('/([245]\.[01234567]\.\d{1,2})\s*(.*)/', $code, $matches) === 1) {
			return [
				'code' => $matches[1],
				'text' => $matches[2],
			];
		}

		if (preg_match('/([245])([01234567])(\d{1,2})\s*(.*)/', $code, $matches) === 1) {
			return [
				'code' => $matches[1] . '.' . $matches[2] . '.' . $matches[3],
				'text' => $matches[4],
			];
		}

		return ['code' => '', 'text' => ''];
	}

	public static function getAction(string $code): ?BounceAction {
		if ($code === '') {
			return null;
		}

		$ret = self::format($code);
		$firstChar = $ret['code'] !== '' ? $ret['code'][0] : '';

		return match ($firstChar) {
			'2' => BounceAction::Success,
			'4' => BounceAction::Transient,
			'5' => BounceAction::Failed,
			default => null,
		};
	}

	/**
	 * Returns action string for compatibility with old output format.
	 */
	public static function getActionString(string $code): string {
		$action = self::getAction($code);

		return $action !== null ? $action->value : '';
	}

	public static function getReason(string $code): BounceReason {
		if ($code === '5.7.1') {
			return BounceReason::Filtered;
		}

		if (in_array($code, ['4.2.0', '4.2.2', '4.3.2'], true)) {
			return BounceReason::NotAccept;
		}

		return BounceReason::UserUnknown;
	}

	public static function getStatusMessages(string $code): string {
		$ret = self::format($code);
		if ($ret['code'] === '') {
			return '';
		}

		$arr = explode('.', $ret['code']);
		$classKey = $arr[0];
		$subclassKey = $arr[1] . '.' . $arr[2];

		$classTitle = '';
		$classDescr = '';
		/** @var array<string, array{title: string, descr: string}> $classes */
		$classes = StatusCodes::CLASSES;
		if (array_key_exists($classKey, $classes)) {
			$classTitle = $classes[$classKey]['title'];
			$classDescr = $classes[$classKey]['descr'];
		}

		$subTitle = '';
		$subDescr = '';
		/** @var array<string, array{title: string, descr: string}> $subclasses */
		$subclasses = StatusCodes::SUBCLASSES;
		if (array_key_exists($subclassKey, $subclasses)) {
			$subTitle = $subclasses[$subclassKey]['title'];
			$subDescr = $subclasses[$subclassKey]['descr'];
		}

		return "<P><B>{$classTitle}</B> - {$classDescr}  <B>{$subTitle}</B> - {$subDescr}</P>";
	}

	public static function decodeDiagnostic(string $dcode): string {
		if (preg_match('/(\d\.\d\.\d)\s/', $dcode, $array) === 1) {
			return $array[1];
		}

		if (preg_match('/(\d\d\d)\s/', $dcode, $array) === 1) {
			return $array[1];
		}

		return '';
	}
}
