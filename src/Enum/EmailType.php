<?php

declare(strict_types=1);

namespace Zoon\BounceHandler\Enum;

enum EmailType: string {
	case Bounce = 'bounce';
	case Fbl = 'fbl';
	case AutoResponse = 'autoresponse';
}
