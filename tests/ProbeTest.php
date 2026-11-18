<?php

declare(strict_types=1);

namespace OCA\AuthentikDav\Tests;

use OCA\AuthentikDav\Service\AuthentikClient;
use OCA\AuthentikDav\Service\Config;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/** The connection test behind the "Test connection" button and the setup check. */
final class ProbeTest extends TestCase {
	/** @var array<string, string> */
	private array $stored = ['base_url' => 'https://auth.example.com'];
	/** @var list<array{0: string, 1: array<string, mixed>}> */
	private array $calls = [];

	/**
	 * @param array<string, mixed>|\Throwable $answer
	 * @return array{ok: bool, code: string, detail: string}
	 */
	private function probe(array|\Throwable $answer, int $status = 200): array {
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('post');
		$client->method('get')->willReturnCallback(function ($url, $options = []) use ($answer, $status) {
			$this->calls[] = [$url, $options];
			if ($answer instanceof \Throwable) {
				throw $answer;
			}
			return new class($answer, $status) implements IResponse {
				/** @param array<string, mixed> $json */
				public function __construct(
					private array $json,
					private int $status,
				) {
				}
				public function getBody(): string {
					return (string)json_encode($this->json);
				}
				public function getStatusCode(): int {
					return $this->status;
				}
				public function getHeader(string $key): string {
					return '';
				}
				/** @return array<string, string[]> */
				public function getHeaders(): array {
					return [];
				}
			};
		});
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn ($app, $key, $default = '') => $this->stored[$key] ?? $default);
		return (new AuthentikClient($service, new Config($appConfig)))->probe();
	}

	public function testGoodFlow(): void {
		$r = $this->probe(['component' => 'ak-stage-identification', 'user_fields' => ['username'], 'password_fields' => false]);
		$this->assertTrue($r['ok']);
		$this->assertSame('ok', $r['code']);
		$this->assertSame(5, $this->calls[0][1]['timeout'], 'the probe uses a short timeout');
	}

	public function testNotConfiguredDoesNoRequest(): void {
		$this->stored = [];
		$this->assertSame('not_configured', $this->probe([])['code']);
		$this->assertSame([], $this->calls);
	}

	public function testPlainHttpIsReportedWithoutARequest(): void {
		$this->stored = ['base_url' => 'http://auth.example.com'];
		$this->assertSame('bad_url', $this->probe([])['code']);
		$this->assertSame([], $this->calls);
	}

	public function testUnreachable(): void {
		$r = $this->probe(new \RuntimeException('cURL error 6: Could not resolve host'));
		$this->assertSame('unreachable', $r['code']);
		$this->assertStringContainsString('Could not resolve host', $r['detail']);
	}

	public function testMissingFlow(): void {
		$this->assertSame('flow_not_found', $this->probe(['detail' => 'Not found.'], 404)['code']);
	}

	public function testFlowThatDoesNotStartWithIdentification(): void {
		$this->assertSame('wrong_first_stage', $this->probe(['component' => 'ak-stage-password'])['code']);
	}

	public function testIdentificationWithEmailOnlyOrPasswordField(): void {
		$this->assertSame('identification_settings', $this->probe(['component' => 'ak-stage-identification', 'user_fields' => ['email']])['code']);
		$this->assertSame('identification_settings', $this->probe(['component' => 'ak-stage-identification', 'user_fields' => ['email', 'username']])['code'], 'email must not be accepted');
		$this->assertSame('identification_settings', $this->probe(['component' => 'ak-stage-identification', 'user_fields' => ['username'], 'password_fields' => true])['code']);
	}

	public function testLocalAddressSettingIsPassedOn(): void {
		$this->stored['allow_local_address'] = 'yes';
		$this->probe(['component' => 'ak-stage-identification', 'user_fields' => ['username']]);
		$this->assertSame(['allow_local_address' => true], $this->calls[0][1]['nextcloud']);
	}
}
