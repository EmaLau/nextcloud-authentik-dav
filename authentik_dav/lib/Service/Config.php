<?php

declare(strict_types=1);

namespace OCA\AuthentikDav\Service;

use OCP\IAppConfig;

/**
 * The app settings, read and written in one place (stored in Nextcloud's app config).
 * They can be changed from the admin settings page or with "occ config:app:set authentik_dav <key>".
 */
class Config {
	public const APP = 'authentik_dav';
	public const DEFAULT_FLOW_SLUG = 'nextcloud-webdav-check';
	public const DEFAULT_CACHE_TTL = 60;
	public const MAX_CACHE_TTL = 3600;
	public const FLAGS = ['allow_http', 'allow_local_address', 'allow_admins', 'forward_client_ip'];

	/** @var array<string, string>|null values from the settings form, used instead of the stored ones */
	private ?array $override = null;

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * A copy that reads the given (unsaved) form values instead of the stored ones,
	 * so "Test connection" can try the settings before they are saved.
	 *
	 * @param array<string, mixed> $in
	 */
	public function withValues(array $in): self {
		$copy = clone $this;
		$copy->override = [
			'base_url' => is_scalar($in['base_url'] ?? null) ? (string)$in['base_url'] : '',
			'flow_slug' => is_scalar($in['flow_slug'] ?? null) ? (string)$in['flow_slug'] : '',
		];
		foreach (self::FLAGS as $flag) {
			$copy->override[$flag] = filter_var($in[$flag] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'yes' : 'no';
		}
		return $copy;
	}

	private function str(string $key, string $default): string {
		if ($this->override !== null && array_key_exists($key, $this->override)) {
			return $this->override[$key];
		}
		return $this->appConfig->getValueString(self::APP, $key, $default);
	}

	public function baseUrl(): string {
		return rtrim(trim($this->str('base_url', '')), '/');
	}

	public function flowSlug(): string {
		$slug = trim($this->str('flow_slug', self::DEFAULT_FLOW_SLUG));
		return $slug === '' ? self::DEFAULT_FLOW_SLUG : $slug;
	}

	public function flag(string $key): bool {
		return $this->str($key, 'no') === 'yes';
	}

	public function cacheTtl(): int {
		$ttl = $this->appConfig->getValueInt(self::APP, 'cache_ttl', self::DEFAULT_CACHE_TTL);
		return max(0, min(self::MAX_CACHE_TTL, $ttl));
	}

	/**
	 * Nextcloud groups whose members may use the Authentik password; empty = every account.
	 * Stored as a JSON list; a comma-separated list (handy with occ) is accepted too.
	 *
	 * @return list<string>
	 */
	public function allowedGroups(): array {
		$raw = trim($this->appConfig->getValueString(self::APP, 'allowed_groups', ''));
		if ($raw === '') {
			return [];
		}
		$list = json_decode($raw, true);
		if (!is_array($list)) {
			$list = explode(',', $raw);
		}
		$groups = [];
		foreach ($list as $gid) {
			if (is_string($gid) && trim($gid) !== '') {
				$groups[] = trim($gid);
			}
		}
		return array_values(array_unique($groups));
	}

	/** Identifies the settings a successful check depended on (part of the cache key). */
	public function fingerprint(): string {
		return hash('sha256', implode("\n", [
			$this->baseUrl(),
			$this->flowSlug(),
			$this->flag('allow_http') ? 'http' : '',
			$this->flag('allow_local_address') ? 'local' : '',
		]));
	}

	/**
	 * Checks that base_url can be used. Returns null when it can, otherwise the reason.
	 */
	public function baseUrlProblem(): ?string {
		$base = $this->baseUrl();
		if ($base === '') {
			return 'base_url is not configured';
		}
		$scheme = strtolower((string)parse_url($base, PHP_URL_SCHEME));
		$host = (string)parse_url($base, PHP_URL_HOST);
		if (($scheme !== 'https' && $scheme !== 'http') || $host === '') {
			return 'base_url must start with https://';
		}
		if ($scheme === 'http' && !$this->flag('allow_http')) {
			return 'base_url uses plain HTTP; use https or set allow_http=yes';
		}
		return null;
	}

	/** @return array{base_url: string, flow_slug: string, cache_ttl: int, allowed_groups: list<string>, allow_http: bool, allow_local_address: bool, allow_admins: bool, forward_client_ip: bool} */
	public function all(): array {
		return [
			'base_url' => $this->baseUrl(),
			'flow_slug' => $this->flowSlug(),
			'cache_ttl' => $this->cacheTtl(),
			'allowed_groups' => $this->allowedGroups(),
			'allow_http' => $this->flag('allow_http'),
			'allow_local_address' => $this->flag('allow_local_address'),
			'allow_admins' => $this->flag('allow_admins'),
			'forward_client_ip' => $this->flag('forward_client_ip'),
		];
	}

	/**
	 * Validates and stores the settings sent by the admin page.
	 *
	 * @param array<string, mixed> $in
	 * @return list<string> validation errors; nothing is stored when the list is not empty
	 */
	public function save(array $in): array {
		$errors = [];
		$base = rtrim(trim((string)($in['base_url'] ?? '')), '/');
		$scheme = strtolower((string)parse_url($base, PHP_URL_SCHEME));
		if ($base !== '' && (!in_array($scheme, ['https', 'http'], true) || (string)parse_url($base, PHP_URL_HOST) === '')) {
			$errors[] = 'base_url';
		}
		$slug = trim((string)($in['flow_slug'] ?? ''));
		if ($slug === '') {
			$slug = self::DEFAULT_FLOW_SLUG;
		}
		if (!preg_match('/^[a-z0-9_-]{1,50}$/', $slug)) {
			$errors[] = 'flow_slug';
		}
		$ttl = $in['cache_ttl'] ?? self::DEFAULT_CACHE_TTL;
		if (!is_scalar($ttl) || !preg_match('/^\d{1,4}$/', trim((string)$ttl)) || (int)$ttl > self::MAX_CACHE_TTL) {
			$errors[] = 'cache_ttl';
		}
		$groups = $in['allowed_groups'] ?? [];
		if (!is_array($groups)) {
			$errors[] = 'allowed_groups';
			$groups = [];
		}
		$groups = array_values(array_unique(array_filter(array_map(
			fn ($g) => is_string($g) ? trim($g) : '',
			$groups,
		), fn ($g) => $g !== '')));
		if ($errors !== []) {
			return $errors;
		}

		$this->appConfig->setValueString(self::APP, 'base_url', $base);
		$this->appConfig->setValueString(self::APP, 'flow_slug', $slug);
		$this->appConfig->setValueInt(self::APP, 'cache_ttl', (int)$ttl);
		$this->appConfig->setValueString(self::APP, 'allowed_groups', $groups === [] ? '' : (string)json_encode($groups));
		foreach (self::FLAGS as $flag) {
			$on = filter_var($in[$flag] ?? false, FILTER_VALIDATE_BOOLEAN);
			$this->appConfig->setValueString(self::APP, $flag, $on ? 'yes' : 'no');
		}
		return [];
	}
}
