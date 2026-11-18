<?php

declare(strict_types=1);

namespace OCA\AuthentikDav\Service;

use GuzzleHttp\Cookie\CookieJar;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;

/**
 * Talks to the Authentik flow executor API.
 *
 * Every run starts with a fresh, throw-away cookie jar: whatever the flow sets is discarded,
 * so even a session created by mistake is never reused.
 */
class AuthentikClient {
	private const CHECK_TIMEOUT = 15;
	private const PROBE_TIMEOUT = 5;

	public function __construct(
		private IClientService $clientService,
		private Config $config,
	) {
	}

	/**
	 * Runs the check flow with a username and a password.
	 *
	 * @param string $clientIp address of the client that sent the password, passed on to Authentik
	 *                         in X-Forwarded-For when forward_client_ip=yes
	 * @return string|null null when the password is accepted, otherwise the reason it was not
	 */
	public function check(string $loginName, string $password, string $clientIp = ''): ?string {
		$problem = $this->config->baseUrlProblem();
		if ($problem !== null) {
			return $problem;
		}
		$url = $this->executorUrl();
		$client = $this->clientService->newClient();
		$opts = $this->options(self::CHECK_TIMEOUT);
		if ($clientIp !== '' && $this->config->flag('forward_client_ip')) {
			// Authentik uses it only if Nextcloud's address is in its trusted proxies
			// (AUTHENTIK_LISTEN__TRUSTED_PROXY_CIDRS); otherwise it is ignored.
			$opts['headers']['X-Forwarded-For'] = $clientIp;
		}
		try {
			$r = $this->decode($client->get($url, $opts));
			if (($r['component'] ?? '') !== 'ak-stage-identification') {
				return 'unexpected first stage of the Authentik flow: ' . $this->componentName($r);
			}
			$r = $this->decode($client->post($url, $opts + ['json' => ['component' => 'ak-stage-identification', 'uid_field' => $loginName]]));
			if (($r['component'] ?? '') === 'ak-stage-access-denied') {
				// A deny stage after the identification: the optional limit on failed attempts or the
				// optional Authentik group of the blueprint. Authentik's message says which.
				$why = is_string($r['error_message'] ?? null) ? trim($r['error_message']) : '';
				return 'Authentik denied access to this user' . ($why !== '' ? ': ' . $why : '');
			}
			if (($r['component'] ?? '') !== 'ak-stage-password') {
				return 'Authentik did not accept the username';
			}
			$r = $this->decode($client->post($url, $opts + ['json' => ['component' => 'ak-stage-password', 'password' => $password]]));
			$component = $r['component'] ?? '';
			// Success = flow finished. An MFA challenge or a repeated password stage = failure.
			if ($component === 'xak-flow-redirect') {
				return null;
			}
			if ($component === 'ak-stage-password') {
				return 'wrong password';
			}
			if ($component === 'ak-stage-authenticator-validate') {
				return 'the user has MFA in Authentik (use a Nextcloud app password)';
			}
			return 'unexpected stage after the password: ' . $this->componentName($r);
		} catch (\Throwable $e) {
			return 'Authentik unreachable: ' . $e->getMessage();
		}
	}

	/**
	 * Checks the connection without sending any username or password: opens the flow and
	 * looks at its first stage. Used by the "Test connection" button and the setup check.
	 *
	 * @return array{ok: bool, code: string, detail: string}
	 *                                                       code: ok | not_configured | bad_url | unreachable | flow_not_found | wrong_first_stage | identification_settings
	 */
	public function probe(): array {
		$problem = $this->config->baseUrlProblem();
		if ($problem !== null) {
			$code = $this->config->baseUrl() === '' ? 'not_configured' : 'bad_url';
			return ['ok' => false, 'code' => $code, 'detail' => $problem];
		}
		try {
			$response = $this->clientService->newClient()->get($this->executorUrl(), $this->options(self::PROBE_TIMEOUT));
		} catch (\Throwable $e) {
			return ['ok' => false, 'code' => 'unreachable', 'detail' => $e->getMessage()];
		}
		$status = $response->getStatusCode();
		$r = $this->decode($response);
		if ($status === 404) {
			return ['ok' => false, 'code' => 'flow_not_found', 'detail' => 'flow "' . $this->config->flowSlug() . '" not found'];
		}
		if (($r['component'] ?? '') !== 'ak-stage-identification') {
			return ['ok' => false, 'code' => 'wrong_first_stage', 'detail' => 'HTTP ' . $status . ', first stage: ' . $this->componentName($r)];
		}
		// The identification stage must match the username only (not the email: a Nextcloud login
		// equal to another person's email would check that person's password) and have no password field.
		$fields = is_array($r['user_fields'] ?? null) ? array_values($r['user_fields']) : [];
		if (!empty($r['password_fields']) || $fields !== ['username']) {
			return ['ok' => false, 'code' => 'identification_settings', 'detail' => 'user fields: ' . implode(', ', array_map('strval', $fields)) . (!empty($r['password_fields']) ? ', with a password field' : '')];
		}
		return ['ok' => true, 'code' => 'ok', 'detail' => 'user fields: ' . implode(', ', array_map('strval', $fields))];
	}

	private function executorUrl(): string {
		return $this->config->baseUrl() . '/api/v3/flows/executor/' . rawurlencode($this->config->flowSlug()) . '/?query=';
	}

	/** @return array<string, mixed> */
	private function options(int $timeout): array {
		return [
			'cookies' => new CookieJar(),
			'timeout' => $timeout,
			'http_errors' => false,
			'headers' => ['Accept' => 'application/json'],
			'nextcloud' => ['allow_local_address' => $this->config->flag('allow_local_address')],
		];
	}

	/** @return array<string, mixed> */
	private function decode(IResponse $response): array {
		$data = json_decode((string)$response->getBody(), true);
		return is_array($data) ? $data : [];
	}

	/** @param array<string, mixed> $r */
	private function componentName(array $r): string {
		$c = $r['component'] ?? '';
		return is_string($c) && $c !== '' ? $c : 'none';
	}
}
