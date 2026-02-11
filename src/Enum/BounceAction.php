<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Enum;

enum BounceAction: string {
	case Failed = 'failed';
	case Transient = 'transient';
	case Success = 'success';
	case AutoResponse = 'autoresponse';
}
