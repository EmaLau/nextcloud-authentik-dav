<?php
declare(strict_types=1);

namespace OCA\AuthentikDav;

use GuzzleHttp\Cookie\CookieJar;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use OCP\User\Backend\ABackend;
use OCP\User\Backend\ICheckPasswordBackend;
use OCP\User\Backend\IGetDisplayNameBackend;
use Psr\Log\LoggerInterface;

/**
 * Password-only backend: accepts the Authentik password of an EXISTING Nextcloud account.
 * It owns no users (userExists() is always false); accounts stay in the Database backend.
 */
class Backend extends ABackend implements ICheckPasswordBackend, IGetDisplayNameBackend {
	private const APP = 'authentik_dav';

	public function __construct(
		private IClientService $clientService,
		private ICacheFactory $cacheFactory,
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
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
		$user = Server::get(IUserManager::class)->get($loginName);
		if ($user === null || !$user->isEnabled()) {
			return false;
		}
		$uid = $user->getUID();
		if ($this->groupManager->isAdmin($uid) && $this->appConfig->getValueString(self::APP, 'allow_admins', 'no') !== 'yes') {
			return false;
		}

		$cache = $this->cacheFactory->createDistributed(self::APP);
		$key = 'ok_' . hash('sha256', strtolower($loginName));
		$cached = $cache->get($key);
		if (is_string($cached) && password_verify($password, $cached)) {
			return $uid;
		}

		if (!$this->authentikAccepts($loginName, $password)) {
			return false;
		}
		$ttl = max(0, $this->appConfig->getValueInt(self::APP, 'cache_ttl', 300));
		if ($ttl > 0) {
			$cache->set($key, password_hash($password, PASSWORD_DEFAULT), $ttl);
		}
		$this->logger->info('Authentik password accepted for ' . $uid, ['app' => self::APP]);
		return $uid;
	}

	private function authentikAccepts(string $loginName, string $password): bool {
		$base = rtrim($this->appConfig->getValueString(self::APP, 'base_url', ''), '/');
		$slug = $this->appConfig->getValueString(self::APP, 'flow_slug', 'nextcloud-webdav-check');
		if ($base === '') {
			$this->logger->warning('authentik_dav: base_url not configured', ['app' => self::APP]);
			return false;
		}
		$url = $base . '/api/v3/flows/executor/' . rawurlencode($slug) . '/?query=';
		$client = $this->clientService->newClient();
		$opts = [
			'cookies' => new CookieJar(),
			'timeout' => 15,
			'http_errors' => false,
			'headers' => ['Accept' => 'application/json'],
			'nextcloud' => ['allow_local_address' => true],
		];
		try {
			$step = fn ($resp) => json_decode((string)$resp->getBody(), true) ?: [];
			$r = $step($client->get($url, $opts));
			if (($r['component'] ?? '') !== 'ak-stage-identification') {
				$this->logger->warning('authentik_dav: unexpected first stage ' . ($r['component'] ?? 'none'), ['app' => self::APP]);
				return false;
			}
			$r = $step($client->post($url, $opts + ['json' => ['component' => 'ak-stage-identification', 'uid_field' => $loginName]]));
			if (($r['component'] ?? '') !== 'ak-stage-password') {
				return false;
			}
			$r = $step($client->post($url, $opts + ['json' => ['component' => 'ak-stage-password', 'password' => $password]]));
			// Success = flow finished. An MFA challenge or a repeated password stage = failure.
			return ($r['component'] ?? '') === 'xak-flow-redirect';
		} catch (\Throwable $e) {
			$this->logger->warning('authentik_dav: ' . $e->getMessage(), ['app' => self::APP]);
			return false;
		}
	}

	public function getDisplayName($uid): string {
		foreach (Server::get(IUserManager::class)->getBackends() as $backend) {
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
