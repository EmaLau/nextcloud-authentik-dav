<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'settings#save', 'url' => '/settings', 'verb' => 'PUT'],
		['name' => 'settings#test', 'url' => '/settings/test', 'verb' => 'POST'],
		['name' => 'personal#save', 'url' => '/personal', 'verb' => 'PUT'],
	],
];
