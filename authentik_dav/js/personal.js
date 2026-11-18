/* Personal setting of authentik_dav. Plain JavaScript, no build step. */
(function () {
	'use strict'
	const APP = 'authentik_dav'
	const t = (text) => (window.t ? window.t(APP, text, undefined, undefined, { escape: false }) : text)

	function init() {
		const box = document.getElementById('authentik-dav-personal-enabled')
		const status = document.getElementById('authentik-dav-personal-status')
		if (!box) {
			return
		}
		box.addEventListener('change', async () => {
			box.disabled = true
			status.textContent = t('Saving…')
			try {
				const response = await fetch(window.OC.generateUrl('/apps/' + APP + '/personal'), {
					method: 'PUT',
					headers: { 'Content-Type': 'application/json', requesttoken: window.OC.requestToken },
					body: JSON.stringify({ enabled: box.checked }),
				})
				status.textContent = response.ok ? t('Saved') : t('Not saved: the server answered with an error.')
				if (!response.ok) {
					box.checked = !box.checked
				}
			} catch (e) {
				status.textContent = t('Not saved: the server answered with an error.')
				box.checked = !box.checked
			} finally {
				box.disabled = false
			}
		})
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init)
	} else {
		init()
	}
})()
