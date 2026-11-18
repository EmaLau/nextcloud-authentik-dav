<?php
declare(strict_types=1);

namespace OCA\AuthentikDav;

use OCA\AuthentikDav\Service\AuthentikClient;
use OCA\AuthentikDav\Service\Config;
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
	private const APP = Config::APP;

	public function __construct(
		private AuthentikClient $authentik,
		private Config $config,
		private ICacheFactory $cacheFactory,
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
		if ($this->groupManager->isAdmin($uid) && !$this->config->flag('allow_admins')) {
			$this->logFailure($uid, 'administrators are excluded (allow_admins=no)');
			return false;
		}
		$allowed = $this->config->allowedGroups();
		if ($allowed !== [] && !$this->inAnyGroup($uid, $allowed)) {
			$this->logFailure($uid, 'not in an allowed group (allowed_groups)');
			return false;
		}

		// Local (per-server, in-memory) cache only: a hash derived from the password
		// never leaves this server, not even to Redis or Memcached.
		// The key also depends on the Authentik settings, so changing the address, the flow or an
		// exception immediately invalidates every cached password. With cache_ttl=0 nothing is read.
		$ttl = $this->config->cacheTtl();
		$cache = $this->cacheFactory->createLocal(self::APP);
		$key = 'ok_' . hash('sha256', strtolower($loginName) . "\n" . $this->config->fingerprint());
		$cached = $ttl > 0 ? $cache->get($key) : null;
		if (is_string($cached) && password_verify($password, $cached)) {
			return $uid;
		}

		$failure = $this->authentik->check($loginName, $password);
		if ($failure !== null) {
			$this->logFailure($uid, $failure);
			return false;
		}
		if ($ttl > 0) {
			$cache->set($key, password_hash($password, PASSWORD_DEFAULT), $ttl);
		}
		$this->logger->info('authentik_dav: Authentik password accepted for ' . $uid, ['app' => self::APP]);
		return $uid;
	}

	/** @param list<string> $groups */
	private function inAnyGroup(string $uid, array $groups): bool {
		foreach ($groups as $gid) {
			if ($this->groupManager->isInGroup($uid, $gid)) {
				return true;
			}
		}
		return false;
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

	public function getDisplayName($uid): string {
		// The account belongs to another backend (usually the database): ask it through the public API.
		return $this->userManager->get((string)$uid)?->getDisplayName() ?? (string)$uid;
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

	/** @return array<string, string> */
	public function getDisplayNames($search = '', $limit = null, $offset = null) {
		return [];
	}

	public function hasUserListings() {
		return false;
	}
}
