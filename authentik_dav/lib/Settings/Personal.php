<?php

declare(strict_types=1);

namespace OCA\AuthentikDav\Settings;

use OCA\AuthentikDav\Backend;
use OCA\AuthentikDav\Service\Config;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use OCP\Util;

/** Personal settings → Security: a user can refuse the Authentik password for their own account. */
class Personal implements ISettings {
	public function __construct(
		private Backend $backend,
		private Config $config,
		private IUserSession $userSession,
	) {
	}

	/** @return TemplateResponse<Http::STATUS_OK, array{}> */
	public function getForm(): TemplateResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		Util::addScript(Config::APP, 'personal');
		return new TemplateResponse(Config::APP, 'personal', [
			'enabled' => !$this->backend->userDisabledIt($uid),
			'configured' => $this->config->baseUrl() !== '',
		], '');
	}

	public function getSection(): string {
		return 'security';
	}

	public function getPriority(): int {
		return 60;
	}
}
