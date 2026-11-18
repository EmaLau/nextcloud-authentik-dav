<?php
declare(strict_types=1);

namespace OCA\AuthentikDav\SetupCheck;

use OCA\AuthentikDav\Service\AuthentikClient;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Shown in Administration settings → Overview: warns when Authentik cannot be reached
 * or the check flow does not look right. It never sends a username or a password.
 */
class AuthentikConnection implements ISetupCheck {
	public function __construct(
		private AuthentikClient $authentik,
		private IL10N $l,
	) {
	}

	public function getName(): string {
		return $this->l->t('Authentik password login');
	}

	public function getCategory(): string {
		return 'security';
	}

	public function run(): SetupResult {
		$r = $this->authentik->probe();
		return match ($r['code']) {
			'ok' => SetupResult::success($this->l->t('Authentik answers and the check flow starts with a username field.')),
			'not_configured' => SetupResult::warning($this->l->t('The Authentik address is not set: Authentik passwords are not accepted. Set it in Administration settings → Security.')),
			'bad_url' => SetupResult::error($this->l->t('The Authentik address cannot be used: %s', [$r['detail']])),
			'unreachable' => SetupResult::error($this->l->t('Authentik cannot be reached: %s', [$r['detail']])),
			'flow_not_found' => SetupResult::error($this->l->t('The check flow does not exist in Authentik: %s', [$r['detail']])),
			'identification_settings' => SetupResult::warning($this->l->t('The identification stage of the check flow should match the username only (not the email) and have no password field (%s).', [$r['detail']])),
			default => SetupResult::error($this->l->t('The check flow does not start with an identification stage (%s).', [$r['detail']])),
		};
	}
}
