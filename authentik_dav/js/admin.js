/* Admin settings page of authentik_dav. Plain JavaScript, no build step. */
(function () {
	'use strict'
	const APP = 'authentik_dav'
	// Messages go into textContent, so Nextcloud must not HTML-escape the values.
	const t = (text, vars) => (window.t ? window.t(APP, text, vars, undefined, { escape: false }) : text)

	const messages = {
		ok: () => t('Connection works: the check flow starts with a username field.'),
		not_configured: () => t('Enter the Authentik address first.'),
		bad_url: (d) => t('The address cannot be used: {detail}', { detail: d }),
		unreachable: (d) => t('Authentik cannot be reached: {detail}', { detail: d }),
		flow_not_found: (d) => t('The check flow does not exist in Authentik: {detail}', { detail: d }),
		wrong_first_stage: (d) => t('The check flow does not start with an identification stage ({detail}).', { detail: d }),
		identification_settings: (d) => t('The identification stage should match the username only (not the email) and have no password field ({detail}).', { detail: d }),
	}
	const fieldNames = {
		base_url: () => t('Authentik address'),
		flow_slug: () => t('Check flow slug'),
		cache_ttl: () => t('Cache a correct password for (seconds)'),
		allowed_groups: () => t('Allowed groups'),
	}

	function init() {
		const form = document.getElementById('authentik-dav-form')
		if (!form) {
			return
		}
		const status = document.getElementById('authentik-dav-status')
		const buttons = form.querySelectorAll('button')

		const show = (text, kind) => {
			status.textContent = text
			status.className = 'authentik-dav-status-' + kind
		}
		const values = () => ({
			base_url: form.base_url.value.trim(),
			flow_slug: form.flow_slug.value.trim(),
			cache_ttl: form.cache_ttl.value.trim(),
			allowed_groups: Array.from(form.allowed_groups.selectedOptions).map((o) => o.value),
			allow_http: form.allow_http.checked,
			allow_local_address: form.allow_local_address.checked,
			allow_admins: form.allow_admins.checked,
			forward_client_ip: form.forward_client_ip.checked,
		})
		const call = async (method, path) => {
			buttons.forEach((b) => { b.disabled = true })
			try {
				const response = await fetch(window.OC.generateUrl('/apps/' + APP + path), {
					method,
					headers: { 'Content-Type': 'application/json', requesttoken: window.OC.requestToken },
					body: JSON.stringify(values()),
				})
				let data = {}
				try { data = await response.json() } catch (e) { /* empty or non-JSON answer */ }
				return { ok: response.ok, data }
			} finally {
				buttons.forEach((b) => { b.disabled = false })
			}
		}

		form.addEventListener('submit', async (event) => {
			event.preventDefault()
			show(t('Saving…'), 'busy')
			try {
				const { ok, data } = await call('PUT', '/settings')
				if (ok) {
					show(t('Saved'), 'ok')
				} else if (Array.isArray(data.errors)) {
					const names = data.errors.map((e) => (fieldNames[e] ? fieldNames[e]() : e)).join(', ')
					show(t('Not saved, check: {fields}', { fields: names }), 'error')
				} else {
					show(t('Not saved: the server answered with an error.'), 'error')
				}
			} catch (e) {
				show(t('Not saved: the server answered with an error.'), 'error')
			}
		})

		document.getElementById('authentik-dav-test').addEventListener('click', async () => {
			show(t('Testing…'), 'busy')
			try {
				const { ok, data } = await call('POST', '/settings/test')
				if (!ok || !data.code) {
					show(t('The test failed: the server answered with an error.'), 'error')
					return
				}
				const message = messages[data.code] ? messages[data.code](data.detail || '') : (data.code + ': ' + (data.detail || ''))
				show(message, data.ok ? 'ok' : 'error')
			} catch (e) {
				show(t('The test failed: the server answered with an error.'), 'error')
			}
		})
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init)
	} else {
		init()
	}
})()
