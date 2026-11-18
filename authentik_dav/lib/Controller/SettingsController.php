<?php
declare(strict_types=1);

namespace OCA\AuthentikDav\Controller;

use OCA\AuthentikDav\Service\AuthentikClient;
use OCA\AuthentikDav\Service\Config;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\IRequest;

/**
 * Admin settings page actions. No #[NoAdminRequired]: only Nextcloud administrators can call
 * them, and Nextcloud checks the CSRF token of every request.
 */
class SettingsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private Config $config,
		private IClientService $clientService,
	) {
		parent::__construct($appName, $request);
	}

	/** @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array<string, mixed>, array{}> */
	public function save(): JSONResponse {
		$errors = $this->config->save($this->formValues());
		if ($errors !== []) {
			return new JSONResponse(['errors' => $errors], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse(['settings' => $this->config->all()]);
	}

	/**
	 * Tests the values currently in the form, without saving them and without sending any password.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 */
	public function test(): JSONResponse {
		$client = new AuthentikClient($this->clientService, $this->config->withValues($this->formValues()));
		return new JSONResponse($client->probe());
	}

	/** @return array<string, mixed> */
	private function formValues(): array {
		$keys = array_merge(['base_url', 'flow_slug', 'cache_ttl', 'allowed_groups'], Config::FLAGS);
		$values = [];
		foreach ($keys as $key) {
			$value = $this->request->getParam($key);
			if ($value !== null) {
				$values[$key] = $value;
			}
		}
		return $values;
	}
}
