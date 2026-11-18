<?php
declare(strict_types=1);
/** @var \OCP\IL10N $l */
/** @var array{settings: array{base_url: string, flow_slug: string, cache_ttl: int, allowed_groups: list<string>, allow_http: bool, allow_local_address: bool, allow_admins: bool}, groups: array<string, string>, default_slug: string, max_ttl: int} $_ */
$s = $_['settings'];
$checked = fn (bool $on): string => $on ? ' checked' : '';
?>
<div id="authentik-dav-settings" class="section">
	<h2><?php p($l->t('Authentik password login')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('WebDAV and other password-based clients can log in with the Authentik password. Nextcloud checks it through a dedicated Authentik flow that creates no session.')); ?>
		<a href="https://github.com/EmaLau/nextcloud-authentik-dav#installation" target="_blank" rel="noreferrer noopener"><?php p($l->t('Setup guide and Authentik blueprint')); ?> ↗</a>
	</p>

	<form id="authentik-dav-form" novalidate>
		<div class="authentik-dav-field">
			<label for="authentik-dav-base-url"><?php p($l->t('Authentik address')); ?></label>
			<input type="url" id="authentik-dav-base-url" name="base_url" value="<?php p($s['base_url']); ?>" placeholder="https://authentik.example.com" autocomplete="off" spellcheck="false">
			<p class="authentik-dav-hint"><?php p($l->t('Must be reachable from this Nextcloud server.')); ?></p>
		</div>

		<div class="authentik-dav-field">
			<label for="authentik-dav-flow-slug"><?php p($l->t('Check flow slug')); ?></label>
			<input type="text" id="authentik-dav-flow-slug" name="flow_slug" value="<?php p($s['flow_slug']); ?>" placeholder="<?php p($_['default_slug']); ?>" pattern="[a-z0-9_\-]{1,50}" autocomplete="off" spellcheck="false">
		</div>

		<div class="authentik-dav-field">
			<label for="authentik-dav-groups"><?php p($l->t('Allowed groups')); ?></label>
			<select id="authentik-dav-groups" name="allowed_groups" multiple size="<?php p((string)min(8, max(3, count($_['groups'])))); ?>">
				<?php foreach ($_['groups'] as $gid => $name): ?>
				<option value="<?php p($gid); ?>"<?php print_unescaped(in_array($gid, $s['allowed_groups'], true) ? ' selected' : ''); ?>><?php p($name); ?></option>
				<?php endforeach; ?>
			</select>
			<p class="authentik-dav-hint"><?php p($l->t('Only members of these groups can use the Authentik password. Select none to allow every account. Ctrl or ⌘ + click to select more than one.')); ?></p>
		</div>

		<div class="authentik-dav-field">
			<label for="authentik-dav-cache-ttl"><?php p($l->t('Cache a correct password for (seconds)')); ?></label>
			<input type="number" id="authentik-dav-cache-ttl" name="cache_ttl" value="<?php p((string)$s['cache_ttl']); ?>" min="0" max="<?php p((string)$_['max_ttl']); ?>" step="1">
			<p class="authentik-dav-hint"><?php p($l->t('Local memory cache only. 0 checks every request against Authentik. A changed password takes effect at most after this time.')); ?></p>
		</div>

		<fieldset class="authentik-dav-field">
			<legend><?php p($l->t('Exceptions')); ?></legend>
			<p>
				<input type="checkbox" class="checkbox" id="authentik-dav-allow-http" name="allow_http"<?php print_unescaped($checked($s['allow_http'])); ?>>
				<label for="authentik-dav-allow-http"><?php p($l->t('Allow plain HTTP (only for Authentik on the same host or a trusted network)')); ?></label>
			</p>
			<p>
				<input type="checkbox" class="checkbox" id="authentik-dav-allow-local" name="allow_local_address"<?php print_unescaped($checked($s['allow_local_address'])); ?>>
				<label for="authentik-dav-allow-local"><?php p($l->t('Allow a private address (LAN, VPN, Docker network)')); ?></label>
			</p>
			<p>
				<input type="checkbox" class="checkbox" id="authentik-dav-allow-admins" name="allow_admins"<?php print_unescaped($checked($s['allow_admins'])); ?>>
				<label for="authentik-dav-allow-admins"><?php p($l->t('Also accept the Authentik password for Nextcloud administrators')); ?></label>
			</p>
		</fieldset>

		<div class="authentik-dav-actions">
			<button type="submit" class="button primary" id="authentik-dav-save"><?php p($l->t('Save')); ?></button>
			<button type="button" class="button" id="authentik-dav-test"><?php p($l->t('Test connection')); ?></button>
			<span id="authentik-dav-status" role="status" aria-live="polite"></span>
		</div>
		<p class="authentik-dav-hint"><?php p($l->t('The test opens the check flow without sending any username or password.')); ?></p>
	</form>
</div>
