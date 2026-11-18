<?php
declare(strict_types=1);

namespace OCA\AuthentikDav\Settings;

use OCA\AuthentikDav\Service\Config;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\Settings\ISettings;
use OCP\Util;

/** The settings form, in Administration settings → Security. */
class Admin implements ISettings {
	public function __construct(
		private Config $config,
		private IGroupManager $groupManager,
	) {
	}

	/** @return TemplateResponse<Http::STATUS_OK, array{}> */
	public function getForm(): TemplateResponse {
		$groups = [];
		foreach ($this->groupManager->search('') as $group) {
			$groups[$group->getGID()] = $group->getDisplayName();
		}
		asort($groups, SORT_NATURAL | SORT_FLAG_CASE);
		Util::addScript(Config::APP, 'admin');
		Util::addStyle(Config::APP, 'admin');
		return new TemplateResponse(Config::APP, 'admin', [
			'settings' => $this->config->all(),
			'groups' => $groups,
			'default_slug' => Config::DEFAULT_FLOW_SLUG,
			'max_ttl' => Config::MAX_CACHE_TTL,
		], '');
	}

	public function getSection(): string {
		return 'security';
	}

	public function getPriority(): int {
		return 60;
	}
}
