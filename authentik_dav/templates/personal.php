<?php
declare(strict_types=1);
/** @var \OCP\IL10N $l */
/** @var array{enabled: bool, configured: bool} $_ */
?>
<div id="authentik-dav-personal" class="section">
	<h2><?php p($l->t('Authentik password in WebDAV clients')); ?></h2>
	<p class="settings-hint"><?php p($l->t('WebDAV clients (Finder, Windows Explorer, rclone…) can log in with your Authentik password. Switch it off if you prefer to use only app passwords, created below in Devices & sessions.')); ?></p>
	<?php if (!$_['configured']): ?>
	<p class="settings-hint"><?php p($l->t('Your administrator has not set up the Authentik address yet, so this has no effect for now.')); ?></p>
	<?php endif; ?>
	<p>
		<input type="checkbox" class="checkbox" id="authentik-dav-personal-enabled"<?php print_unescaped($_['enabled'] ? ' checked' : ''); ?>>
		<label for="authentik-dav-personal-enabled"><?php p($l->t('Allow my Authentik password in WebDAV clients')); ?></label>
		<span id="authentik-dav-personal-status" role="status" aria-live="polite"></span>
	</p>
</div>
