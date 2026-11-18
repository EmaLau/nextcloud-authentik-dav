<?php
declare(strict_types=1);

namespace OCA\AuthentikDav;

use GuzzleHttp\Cookie\CookieJar;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\User\Backend\ABackend;
use OCP\User\Backend\ICheckPasswordBackend;
use OCP\User\Backend\IGetDisplayNameBackend;
use Psr\Log\LoggerInterface;

/**
 * Password-only backend: accepts the Authentik password of an EXISTING Nextcloud account.
 * It owns no users (userExists() is always false); accounts stay in the Database backend.
 *
 * Brute-force protection is not done here: every client login goes through
 * OC\User\Session::logClientIn(), which throttles failed attempts per IP address
 * for all user backends.
 */
class Backend extends ABackend implements ICheckPasswordBackend, IGetDisplayNameBackend {
	private const APP = 'authentik_dav';
	private const DEFAULT_CACHE_TTL = 60;

	public function __construct(
		private IClientService $clientService,
		private ICacheFactory $cacheFactory,
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private IRequest $request,
		private LoggerInterface $logger,
	) {
	}

	public function getBackendName(): string {
		return 'Authentik';
	}

	public function checkPassword(string $loginName, string $password) {
		if ($loginName === '' || $password === '' || strlen($password) > 1024) {
			return false;
		}
		$user = $this->userManager->get($loginName);
		if ($user === null || !$user->isEnabled()) {
			// Not our business: the account does not exist here or is disabled.
			return false;
		}
		$uid = $user->getUID();
		if ($this->groupManager->isAdmin($uid) && !$this->flag('allow_admins')) {
			$this->logFailure($uid, 'administrators are excluded (allow_admins=no)');
			return false;
		}

		// Local (per-server, in-memory) cache only: a hash derived from the password
		// never leaves this server, not even to Redis or Memcached.
		$cache = $this->cacheFactory->createLocal(self::APP);
		$key = 'ok_' . hash('sha256', strtolower($loginName));
		$cached = $cache->get($key);
		if (is_string($cached) && password_verify($password, $cached)) {
			return $uid;
		}

		$failure = $this->authentikRejects($loginName, $password);
		if ($failure !== null) {
			$this->logFailure($uid, $failure);
			return false;
		}
		$ttl = max(0, $this->appConfig->getValueInt(self::APP, 'cache_ttl', self::DEFAULT_CACHE_TTL));
		if ($ttl > 0) {
			$cache->set($key, password_hash($password, PASSWORD_DEFAULT), $ttl);
		}
		$this->logger->info('authentik_dav: Authentik password accepted for ' . $uid, ['app' => self::APP]);
		return $uid;
	}

	/**
	 * Runs the Authentik check flow.
	 *
	 * @return string|null null when the password is accepted, otherwise the reason it was not
	 */
	private function authentikRejects(string $loginName, string $password): ?string {
		$base = rtrim($this->appConfig->getValueString(self::APP, 'base_url', ''), '/');
		$slug = $this->appConfig->getValueString(self::APP, 'flow_slug', 'nextcloud-webdav-check');
		if ($base === '') {
			return 'base_url is not configured';
		}
		$scheme = strtolower((string)parse_url($base, PHP_URL_SCHEME));
		if ($scheme === 'http' && !$this->flag('allow_http')) {
			return 'base_url uses plain HTTP; use https or set allow_http=yes';
		}
		if ($scheme !== 'https' && $scheme !== 'http') {
			return 'base_url must start with https://';
		}

		$url = $base . '/api/v3/flows/executor/' . rawurlencode($slug) . '/?query=';
		$client = $this->clientService->newClient();
		$opts = [
			// A fresh, throw-away cookie jar: whatever the flow sets is discarded.
			'cookies' => new CookieJar(),
			'timeout' => 15,
			'http_errors' => false,
			'headers' => ['Accept' => 'application/json'],
			'nextcloud' => ['allow_local_address' => $this->flag('allow_local_address')],
		];
		try {
			$step = fn ($resp) => json_decode((string)$resp->getBody(), true) ?: [];
			$r = $step($client->get($url, $opts));
			if (($r['component'] ?? '') !== 'ak-stage-identification') {
				return 'unexpected first stage of the Authentik flow: ' . ($r['component'] ?? 'none');
			}
			$r = $step($client->post($url, $opts + ['json' => ['component' => 'ak-stage-identification', 'uid_field' => $loginName]]));
			if (($r['component'] ?? '') !== 'ak-stage-password') {
				return 'Authentik did not accept the username';
			}
			$r = $step($client->post($url, $opts + ['json' => ['component' => 'ak-stage-password', 'password' => $password]]));
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
			return 'unexpected stage after the password: ' . ($component === '' ? 'none' : $component);
		} catch (\Throwable $e) {
			return 'Authentik unreachable: ' . $e->getMessage();
		}
	}

	/**
	 * One line per failed check, without the password. Format (inside the Nextcloud log message):
	 *   authentik_dav: login failed for user 'alice' from '192.0.2.10': wrong password
	 */
	private function logFailure(string $uid, string $reason): void {
		$this->logger->warning(sprintf(
			"authentik_dav: login failed for user '%s' from '%s': %s",
			$uid,
			$this->request->getRemoteAddress(),
			$reason,
		), ['app' => self::APP]);
	}

	private function flag(string $key): bool {
		return $this->appConfig->getValueString(self::APP, $key, 'no') === 'yes';
	}

	public function getDisplayName($uid): string {
		foreach ($this->userManager->getBackends() as $backend) {
			if ($backend instanceof \OC\User\Database) {
				return $backend->getDisplayName($uid);
			}
		}
		return (string)$uid;
	}

	public function deleteUser($uid) {
		return false;
	}

	public function getUsers($search = '', $limit = null, $offset = null) {
		return [];
	}

	public function userExists($uid) {
		return false;
	}

	public function getDisplayNames($search = '', $limit = null, $offset = null) {
		return [];
	}

	public function hasUserListings() {
		return false;
	}
}
