PHP Bounce Handler (v8)
=======================

Modern PHP 8.4+ library for parsing bounce emails, FBL reports, and autoresponses.

Package: `zoonru/php-bounce-handler`

Features
--------
- Detects email type: `bounce`, `fbl`, `autoresponse`
- Extracts action and status (`failed`, `transient`, `success`, SMTP/RFC status codes)
- Resolves reason (`userunknown`, `notaccept`, `filtered`)
- Parses FBL fields (source IP, original sender/recipient, agent)
- Detects FBL from common patterns: `report-type=feedback-report`, `message/feedback-report` MIME parts, `Feedback-ID`/`X-Feedback-ID`, provider loop headers
- Includes fixture-driven regression tests (`eml/`)

Requirements
------------
- PHP `>= 8.4`
- Composer

Installation
------------
```bash
composer install
```

Quick Start
-----------
```php
<?php
require_once 'vendor/autoload.php';

use Zoon\BounceHandler\BounceHandler;

$rawEmail = file_get_contents('eml/1.eml');
$handler = new BounceHandler();
$results = $handler->parse($rawEmail);

foreach ($results as $result) {
	echo $result->emailType->value . PHP_EOL;      // bounce|fbl|autoresponse
	echo $result->action->value . PHP_EOL;         // failed|transient|success|autoresponse
	echo $result->deliveryStatus . PHP_EOL;        // e.g. 5.1.1
	echo $result->recipient . PHP_EOL;
	echo $result->reason->value . PHP_EOL;
}
```

Development
-----------
```bash
composer lint    # psalm + phpcs
composer test    # phpunit
composer psalm
composer phpcs
composer phpcbf
```

Project Structure
-----------------
- `src/` - library source code
- `tests/` - PHPUnit test suite
- `eml/` - anonymized sample emails and fixtures (`fixture_*`) for parsing regression checks

Status
------
Current version is a namespaced PHP 8 rewrite with enums, readonly DTOs, static analysis, and PHPUnit coverage.
