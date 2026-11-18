<?php

declare(strict_types=1);

namespace OCA\AuthentikDav\Controller;

use OCA\AuthentikDav\Service\Config;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/** The personal setting of the logged-in user (CSRF-checked by Nextcloud). */
class PersonalController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $ncConfig,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/** @return JSONResponse<Http::STATUS_OK, array{enabled: bool}, array{}>|JSONResponse<Http::STATUS_UNAUTHORIZED, array{}, array{}> */
	#[NoAdminRequired]
	public function save(bool $enabled): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}
		$this->ncConfig->setUserValue($user->getUID(), Config::APP, 'disabled', $enabled ? 'no' : 'yes');
		return new JSONResponse(['enabled' => $enabled]);
	}
}
