<?php
declare(strict_types=1);

// Nextcloud's coding standard, as used by the official apps.
require_once './vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
	->getFinder()
	->ignoreVCSIgnored(true)
	->notPath('dev')
	->notPath('vendor')
	->in(__DIR__ . '/authentik_dav')
	->in(__DIR__ . '/tests');
return $config;
