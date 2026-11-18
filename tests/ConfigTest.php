<?php
declare(strict_types=1);

namespace OCA\AuthentikDav\Tests;

use OCA\AuthentikDav\Service\Config;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/** Reading, validating and saving the settings (what the admin page sends). */
final class ConfigTest extends TestCase {
	/** @var array<string, string|int> */
	private array $stored = [];

	private function config(): Config {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn ($app, $key, $default = '') => (string)($this->stored[$key] ?? $default));
		$appConfig->method('getValueInt')->willReturnCallback(fn ($app, $key, $default = 0) => (int)($this->stored[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(function ($app, $key, $value) {
			$this->assertSame('authentik_dav', $app);
			$this->stored[$key] = $value;
			return true;
		});
		$appConfig->method('setValueInt')->willReturnCallback(function ($app, $key, $value) {
			$this->stored[$key] = $value;
			return true;
		});
		return new Config($appConfig);
	}

	/**
	 * @param array<string, mixed> $changes
	 * @return array<string, mixed>
	 */
	private function form(array $changes = []): array {
		return $changes + [
			'base_url' => 'https://auth.example.com/',
			'flow_slug' => 'nextcloud-webdav-check',
			'cache_ttl' => '60',
			'allowed_groups' => [],
			'allow_http' => false,
			'allow_local_address' => true,
			'allow_admins' => 'false',
		];
	}

	public function testDefaults(): void {
		$this->assertSame([
			'base_url' => '',
			'flow_slug' => 'nextcloud-webdav-check',
			'cache_ttl' => 60,
			'allowed_groups' => [],
			'allow_http' => false,
			'allow_local_address' => false,
			'allow_admins' => false,
		], $this->config()->all());
	}

	public function testValidFormIsSavedAndNormalised(): void {
		$config = $this->config();
		$this->assertSame([], $config->save($this->form(['allowed_groups' => ['friends', ' friends ', '', 'webdav']])));
		$this->assertSame('https://auth.example.com', $this->stored['base_url']);
		$this->assertSame('["friends","webdav"]', $this->stored['allowed_groups']);
		$this->assertSame('yes', $this->stored['allow_local_address']);
		$this->assertSame('no', $this->stored['allow_http']);
		$this->assertSame('no', $this->stored['allow_admins']);
		$this->assertSame(60, $this->stored['cache_ttl']);
		$this->assertSame(['friends', 'webdav'], $config->allowedGroups());
	}

	public function testEmptySlugFallsBackToTheDefault(): void {
		$this->assertSame([], $this->config()->save($this->form(['flow_slug' => ' '])));
		$this->assertSame('nextcloud-webdav-check', $this->stored['flow_slug']);
	}

	public function testInvalidValuesAreRejectedAndNothingIsStored(): void {
		$errors = $this->config()->save($this->form([
			'base_url' => 'ftp://auth.example.com',
			'flow_slug' => '../admin',
			'cache_ttl' => '9999',
			'allowed_groups' => 'friends',
		]));
		$this->assertSame(['base_url', 'flow_slug', 'cache_ttl', 'allowed_groups'], $errors);
		$this->assertSame([], $this->stored);
	}

	public function testCacheTtlMustBeAWholeNumber(): void {
		foreach (['-1', '1.5', 'abc', ''] as $ttl) {
			$this->assertSame(['cache_ttl'], $this->config()->save($this->form(['cache_ttl' => $ttl])), $ttl);
		}
		$this->assertSame([], $this->config()->save($this->form(['cache_ttl' => '0'])));
	}

	public function testEmptyBaseUrlIsAllowedToSwitchTheAppOff(): void {
		$config = $this->config();
		$this->assertSame([], $config->save($this->form(['base_url' => ''])));
		$this->assertSame('base_url is not configured', $config->baseUrlProblem());
	}

	public function testBaseUrlProblems(): void {
		$this->stored = ['base_url' => 'http://auth.example.com'];
		$this->assertStringContainsString('plain HTTP', (string)$this->config()->baseUrlProblem());
		$this->stored['allow_http'] = 'yes';
		$this->assertNull($this->config()->baseUrlProblem());
		$this->stored['base_url'] = 'https://';
		$this->assertSame('base_url must start with https://', $this->config()->baseUrlProblem());
	}

	public function testUnsavedFormValuesOverrideTheStoredOnes(): void {
		$this->stored = ['base_url' => 'https://stored.example.com', 'allow_http' => 'no'];
		$config = $this->config()->withValues(['base_url' => 'http://form.example.com', 'allow_http' => true]);
		$this->assertSame('http://form.example.com', $config->baseUrl());
		$this->assertTrue($config->flag('allow_http'));
		$this->assertSame('nextcloud-webdav-check', $config->flowSlug());
		$this->assertSame('https://stored.example.com', $this->config()->baseUrl(), 'the stored value is unchanged');
	}

	public function testCacheTtlIsClamped(): void {
		$this->stored = ['cache_ttl' => 999999];
		$this->assertSame(3600, $this->config()->cacheTtl());
		$this->stored = ['cache_ttl' => -5];
		$this->assertSame(0, $this->config()->cacheTtl());
	}
}
