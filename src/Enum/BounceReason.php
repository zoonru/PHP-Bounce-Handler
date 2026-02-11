<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Enum;

enum BounceReason: string {
	case UserUnknown = 'userunknown';
	case NotAccept = 'notaccept';
	case Filtered = 'filtered';
}
