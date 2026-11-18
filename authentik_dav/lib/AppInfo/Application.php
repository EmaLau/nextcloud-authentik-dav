<?php
declare(strict_types=1);

namespace OCA\AuthentikDav\AppInfo;

use OCA\AuthentikDav\Backend;
use OCA\AuthentikDav\SetupCheck\AuthentikConnection;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUserManager;

class Application extends App implements IBootstrap {
	public const APP_ID = 'authentik_dav';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Administration settings → Overview: warns when Authentik or the check flow is not right.
		$context->registerSetupCheck(AuthentikConnection::class);
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(function (IUserManager $userManager, Backend $backend): void {
			$userManager->registerBackend($backend);
		});
	}
}
