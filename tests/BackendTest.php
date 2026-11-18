<?php
declare(strict_types=1);

namespace OCA\AuthentikDav\Tests;

use OCA\AuthentikDav\Backend;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Unit tests with a simulated Authentik: every HTTP call to the flow executor is answered
 * by a scripted list of responses, so no network and no real Nextcloud are needed.
 */
final class BackendTest extends TestCase {
	private const PASSWORD = 'correct horse battery staple';

	/** @var array<string, string|int> */
	private array $config;
	/** Scripted Authentik answers: a decoded JSON array, or a \Throwable to throw. */
	private array $answers;
	/** Every HTTP call made: [method, url, options]. */
	private array $calls;
	/** In-memory local cache: key => [value, ttl]. */
	private array $cacheData;
	private array $users;
	private array $admins;
	private bool $distributedCacheUsed;
	private MemoryLogger $logger;

	protected function setUp(): void {
		$this->config = ['base_url' => 'https://auth.example.com'];
		$this->answers = [];
		$this->calls = [];
		$this->cacheData = [];
		$this->users = ['alice' => true];
		$this->admins = [];
		$this->distributedCacheUsed = false;
		$this->logger = new MemoryLogger();
	}

	// --- Authentik answers ---------------------------------------------------------------

	private function flowAccepts(): void {
		$this->answers = [
			['component' => 'ak-stage-identification'],
			['component' => 'ak-stage-password'],
			['component' => 'xak-flow-redirect', 'to' => '/'],
		];
	}

	private function flowAfterPassword(array $answer): void {
		$this->answers = [
			['component' => 'ak-stage-identification'],
			['component' => 'ak-stage-password'],
			$answer,
		];
	}

	// --- Backend with mocked Nextcloud services --------------------------------------------

	private function backend(): Backend {
		$response = fn (array $json): IResponse => new class($json) implements IResponse {
			public function __construct(private array $json) {
			}
			public function getBody() {
				return json_encode($this->json);
			}
			public function getStatusCode(): int {
				return 200;
			}
			public function getHeader(string $key): string {
				return '';
			}
			public function getHeaders(): array {
				return [];
			}
		};
		$answer = function (string $method, string $url, array $options) use ($response): IResponse {
			$this->calls[] = [$method, $url, $options];
			$next = array_shift($this->answers);
			if ($next === null) {
				$this->fail("Unexpected $method request to $url");
			}
			if ($next instanceof \Throwable) {
				throw $next;
			}
			return $response($next);
		};

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(fn ($url, $options = []) => $answer('GET', $url, $options));
		$client->method('post')->willReturnCallback(fn ($url, $options = []) => $answer('POST', $url, $options));
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn ($key) => $this->cacheData[$key][0] ?? null);
		$cache->method('set')->willReturnCallback(function ($key, $value, $ttl = 0) {
			$this->cacheData[$key] = [$value, $ttl];
			return true;
		});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createLocal')->willReturn($cache);
		$cacheFactory->method('createDistributed')->willReturnCallback(function () use ($cache) {
			$this->distributedCacheUsed = true;
			return $cache;
		});

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn ($app, $key, $default = '') => (string)($this->config[$key] ?? $default)
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn ($app, $key, $default = 0) => (int)($this->config[$key] ?? $default)
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(fn ($uid) => in_array($uid, $this->admins, true));

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(function ($uid) {
			if (!array_key_exists($uid, $this->users)) {
				return null;
			}
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('isEnabled')->willReturn($this->users[$uid]);
			return $user;
		});

		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('192.0.2.10');

		return new Backend($clientService, $cacheFactory, $appConfig, $groupManager, $userManager, $request, $this->logger);
	}

	private function assertPasswordNeverLogged(): void {
		foreach ($this->logger->records as [, $message]) {
			$this->assertStringNotContainsString(self::PASSWORD, $message);
		}
	}

	// --- Tests -------------------------------------------------------------------------------

	public function testRightPasswordIsAccepted(): void {
		$this->flowAccepts();
		$this->assertSame('alice', $this->backend()->checkPassword('alice', self::PASSWORD));

		$this->assertCount(3, $this->calls);
		[$method, $url, $options] = $this->calls[0];
		$this->assertSame('GET', $method);
		$this->assertSame('https://auth.example.com/api/v3/flows/executor/nextcloud-webdav-check/?query=', $url);
		$this->assertSame(['component' => 'ak-stage-identification', 'uid_field' => 'alice'], $this->calls[1][2]['json']);
		$this->assertSame(['component' => 'ak-stage-password', 'password' => self::PASSWORD], $this->calls[2][2]['json']);
		$this->assertPasswordNeverLogged();
	}

	public function testAllStepsShareOneFreshCookieJar(): void {
		$this->flowAccepts();
		$this->backend()->checkPassword('alice', self::PASSWORD);
		$jar = $this->calls[0][2]['cookies'];
		$this->assertSame($jar, $this->calls[1][2]['cookies']);
		$this->assertSame($jar, $this->calls[2][2]['cookies']);

		$this->flowAccepts();
		$this->cacheData = [];
		$this->backend()->checkPassword('alice', self::PASSWORD);
		$this->assertNotSame($jar, $this->calls[3][2]['cookies'], 'each check must start with an empty cookie jar');
	}

	public function testWrongPasswordIsRefusedAndLogged(): void {
		$this->flowAfterPassword(['component' => 'ak-stage-password', 'response_errors' => ['password' => ['Invalid password']]]);
		$this->assertFalse($this->backend()->checkPassword('alice', self::PASSWORD));

		$this->assertTrue($this->logger->has('warning', "login failed for user 'alice' from '192.0.2.10': wrong password"));
		$this->assertSame([], $this->cacheData);
		$this->assertPasswordNeverLogged();
	}

	public function testMfaChallengeIsRefused(): void {
		$this->flowAfterPassword(['component' => 'ak-stage-authenticator-validate']);
		$this->assertFalse($this->backend()->checkPassword('alice', self::PASSWORD));
		$this->assertTrue($this->logger->has('warning', 'the user has MFA in Authentik'));
	}

	public function testUnexpectedFinalStageIsRefused(): void {
		$this->flowAfterPassword(['component' => 'ak-stage-consent']);
		$this->assertFalse($this->backend()->checkPassword('alice', self::PASSWORD));
		$this->assertTrue($this->logger->has('warning', 'unexpected stage after the password: ak-stage-consent'));
	}

	public function testUnknownUsernameInAuthentikIsRefused(): void {
		$this->answers = [
			['component' => 'ak-stage-identification'],
			['component' => 'ak-stage-identification', 'response_errors' => ['uid_field' => ['Failed to authenticate.']]],
		];
		$this->assertFalse($this->backend()->checkPassword('alice', self::PASSWORD));
		$this->assertCount(2, $this->calls, 'the password must not be sent when the username was not accepted');
		$this->assertTrue($this->logger->has('warning', 'Authentik did not accept the username'));
	}

	public function testWrongFlowIsRefused(): void {
		$this->answers = [['component' => 'ak-stage-password']];
		$this->assertFalse($this->backend()->checkPassword('alice', self::PASSWORD));
		$this->assertCount(1, $this->calls);
		$this->assertTrue($this->logger->has('warning', 'unexpected first stage of the Authentik flow: ak-stage-password'));
	}

	public function testAuthentikDownIsRefused(): void {
		$this->answers = [new \RuntimeException('cURL error 7: Failed to connect to auth.example.com')];
		$this->assertFalse($this->backend()->checkPassword('alice', self::PASSWORD));
		$this->assertTrue($this->logger->has('warning', 'Authentik unreachable: cURL error 7'));
	}

	public function testTimeoutIsRefused(): void {
		$this->answers = [
			['component' => 'ak-stage-identification'],
			['component' => 'ak-stage-password'],
			new \RuntimeException('cURL error 28: Operation timed out after 15001 milliseconds'),
		];
		$this->assertFalse($this->backend()->checkPassword('alice', self::PASSWORD));
		$this->assertSame(15, $this->calls[0][2]['timeout']);
		$this->assertTrue($this->logger->has('warning', 'Authentik unreachable: cURL error 28'));
		$this->assertPasswordNeverLogged();
	}

	public function testNonJsonAnswerIsRefused(): void {
		$this->answers = [[]];
		$this->assertFalse($this->backend()->checkPassword('alice', self::PASSWORD));
		$this->assertTrue($this->logger->has('warning', 'unexpected first stage of the Authentik flow: none'));
	}

	public function testCacheHitSkipsAuthentik(): void {
		$this->flowAccepts();
		$this->assertSame('alice', $this->backend()->checkPassword('alice', self::PASSWORD));
		$this->assertCount(3, $this->calls);

		$this->assertSame('alice', $this->backend()->checkPassword('alice', self::PASSWORD));
		$this->assertCount(3, $this->calls, 'a cached password must not reach Authentik again');
	}

	public function testCacheIsLocalHashedAndShort(): void {
		$this->flowAccepts();
		$this->backend()->checkPassword('alice', self::PASSWORD);

		$this->assertFalse($this->distributedCacheUsed, 'the distributed cache (Redis) must never be used');
		$this->assertCount(1, $this->cacheData);
		[$stored, $ttl] = array_values($this->cacheData)[0];
		$this->assertSame(60, $ttl);
		$this->assertNotSame(self::PASSWORD, $stored);
		$this->assertTrue(password_verify(self::PASSWORD, $stored));
		$this->assertStringNotContainsString('alice', (string)array_key_first($this->cacheData));
	}

	public function testCachedHashDoesNotAcceptAnotherPassword(): void {
		$this->flowAccepts();
		$this->backend()->checkPassword('alice', self::PASSWORD);

		$this->flowAfterPassword(['component' => 'ak-stage-password']);
		$this->assertFalse($this->backend()->checkPassword('alice', 'another password'));
		$this->assertCount(6, $this->calls, 'a different password must be checked against Authentik');
	}

	public function testCacheTtlZeroDisablesTheCache(): void {
		$this->config['cache_ttl'] = 0;
		$this->flowAccepts();
		$this->backend()->checkPassword('alice', self::PASSWORD);
		$this->assertSame([], $this->cacheData);
	}

	public function testAdminIsRefusedByDefault(): void {
		$this->admins = ['alice'];
		$this->assertFalse($this->backend()->checkPassword('alice', self::PASSWORD));
		$this->assertSame([], $this->calls);
		$this->assertTrue($this->logger->has('warning', 'administrators are excluded'));
	}

	public function testAdminIsAcceptedWhenAllowed(): void {
		$this->admins = ['alice'];
		$this->config['allow_admins'] = 'yes';
		$this->flowAccepts();
		$this->assertSame('alice', $this->backend()->checkPassword('alice', self::PASSWORD));
	}

	public function testUnknownOrDisabledNextcloudUserIsIgnored(): void {
		$this->users = ['bob' => false];
		$this->assertFalse($this->backend()->checkPassword('alice', self::PASSWORD));
		$this->assertFalse($this->backend()->checkPassword('bob', self::PASSWORD));
		$this->assertSame([], $this->calls);
		$this->assertSame([], $this->logger->records, 'accounts this backend does not handle are not logged');
	}

	public function testEmptyOrHugeInputIsRefused(): void {
		$this->assertFalse($this->backend()->checkPassword('', self::PASSWORD));
		$this->assertFalse($this->backend()->checkPassword('alice', ''));
		$this->assertFalse($this->backend()->checkPassword('alice', str_repeat('x', 1025)));
		$this->assertSame([], $this->calls);
	}

	public function testMissingBaseUrlIsRefused(): void {
		$this->config = [];
		$this->assertFalse($this->backend()->checkPassword('alice', self::PASSWORD));
		$this->assertSame([], $this->calls);
		$this->assertTrue($this->logger->has('warning', 'base_url is not configured'));
	}

	public function testPlainHttpIsRefusedByDefault(): void {
		$this->config['base_url'] = 'http://auth.example.com';
		$this->assertFalse($this->backend()->checkPassword('alice', self::PASSWORD));
		$this->assertSame([], $this->calls, 'the password must not be sent over plain HTTP');
		$this->assertTrue($this->logger->has('warning', 'base_url uses plain HTTP'));
	}

	public function testPlainHttpIsAllowedWhenConfigured(): void {
		$this->config['base_url'] = 'http://auth.example.com';
		$this->config['allow_http'] = 'yes';
		$this->flowAccepts();
		$this->assertSame('alice', $this->backend()->checkPassword('alice', self::PASSWORD));
	}

	public function testOtherSchemesAreRefused(): void {
		foreach (['ftp://auth.example.com', 'auth.example.com', 'file:///etc/passwd'] as $url) {
			$this->config['base_url'] = $url;
			$this->config['allow_http'] = 'yes';
			$this->assertFalse($this->backend()->checkPassword('alice', self::PASSWORD), $url);
		}
		$this->assertSame([], $this->calls);
	}

	public function testLocalAddressesAreRefusedByDefault(): void {
		$this->flowAccepts();
		$this->backend()->checkPassword('alice', self::PASSWORD);
		$this->assertSame(['allow_local_address' => false], $this->calls[0][2]['nextcloud']);
	}

	public function testLocalAddressesAreAllowedWhenConfigured(): void {
		$this->config['allow_local_address'] = 'yes';
		$this->flowAccepts();
		$this->backend()->checkPassword('alice', self::PASSWORD);
		$this->assertSame(['allow_local_address' => true], $this->calls[0][2]['nextcloud']);
	}

	public function testCustomFlowSlugIsEncoded(): void {
		$this->config['flow_slug'] = 'my flow/../x';
		$this->config['base_url'] = 'https://auth.example.com/';
		$this->flowAccepts();
		$this->backend()->checkPassword('alice', self::PASSWORD);
		$this->assertSame('https://auth.example.com/api/v3/flows/executor/my%20flow%2F..%2Fx/?query=', $this->calls[0][1]);
	}

	public function testBackendOwnsNoUsers(): void {
		$backend = $this->backend();
		$this->assertFalse($backend->userExists('alice'));
		$this->assertSame([], $backend->getUsers());
		$this->assertFalse($backend->hasUserListings());
		$this->assertFalse($backend->deleteUser('alice'));
	}
}

/** Collects log records so the tests can inspect them. */
final class MemoryLogger extends AbstractLogger {
	/** @var list<array{0: string, 1: string}> */
	public array $records = [];

	public function log($level, string|\Stringable $message, array $context = []): void {
		$this->records[] = [(string)$level, (string)$message];
	}

	public function has(string $level, string $needle): bool {
		foreach ($this->records as [$l, $message]) {
			if ($l === $level && str_contains($message, $needle)) {
				return true;
			}
		}
		return false;
	}
}
