// Browser test of the settings pages (Playwright, Chromium), run by dev/ui-test.sh inside the Docker network.
import { chromium } from 'playwright'

const NC = 'http://nextcloud'
const { NC_ADMIN_PASS, TEST_USER_PASS } = process.env
let fails = 0
const expect = (what, ok, detail = '') => {
	console.log(`${ok ? 'ok   ' : 'FAIL '} ${what}${ok || !detail ? '' : ': ' + detail}`)
	if (!ok) fails++
}

async function login(page, user, password) {
	await page.goto(`${NC}/login`)
	await page.fill('#user', user)
	await page.fill('#password', password)
	await page.press('#password', 'Enter')
	await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 30000 })
}

async function status(page, selector) {
	// Nextcloud's Content Security Policy forbids page.waitForFunction: poll from Node instead.
	const el = page.locator(selector)
	for (let i = 0; i < 100; i++) {
		const text = ((await el.textContent()) || '').trim()
		if (text !== '' && !text.endsWith('…')) {
			return { cls: (await el.getAttribute('class')) || '', text }
		}
		await page.waitForTimeout(200)
	}
	return { cls: '', text: '(no status after 20 s)' }
}

const browser = await chromium.launch()
try {
	// Administration settings → Security
	const admin = await (await browser.newContext({ locale: 'en-US' })).newPage()
	await login(admin, 'admin', NC_ADMIN_PASS)
	await admin.goto(`${NC}/settings/admin/security`)
	await admin.waitForSelector('#authentik-dav-settings', { timeout: 20000 })
	expect('admin page: the section is shown', true)

	await admin.evaluate(() => { document.getElementById('authentik-dav-status').textContent = '' }).catch(() => {})
	await admin.click('#authentik-dav-test')
	let s = await status(admin, '#authentik-dav-status')
	expect('admin page: Test connection succeeds', s.cls.includes('ok'), s.text)

	await admin.fill('#authentik-dav-flow-slug', 'missing-flow')
	await admin.evaluate(() => { document.getElementById('authentik-dav-status').textContent = '' }).catch(() => {})
	await admin.click('#authentik-dav-test')
	s = await status(admin, '#authentik-dav-status')
	expect('admin page: Test connection reports a missing flow (unsaved value)', s.cls.includes('error') && s.text.includes('missing-flow'), s.text)
	expect('admin page: quotes are not HTML-escaped', !s.text.includes('&quot;'), s.text)

	await admin.fill('#authentik-dav-flow-slug', '../admin')
	await admin.fill('#authentik-dav-cache-ttl', '99999')
	await admin.locator('#authentik-dav-status').evaluate((e) => { e.textContent = '' })
	await admin.click('#authentik-dav-save')
	s = await status(admin, '#authentik-dav-status')
	expect('admin page: invalid values are refused', s.cls.includes('error'), s.text)

	await admin.fill('#authentik-dav-flow-slug', 'nextcloud-webdav-check')
	await admin.fill('#authentik-dav-cache-ttl', '45')
	await admin.locator('#authentik-dav-status').evaluate((e) => { e.textContent = '' })
	await admin.click('#authentik-dav-save')
	s = await status(admin, '#authentik-dav-status')
	expect('admin page: valid values are saved', s.cls.includes('ok'), s.text)
	await admin.reload()
	await admin.waitForSelector('#authentik-dav-cache-ttl')
	expect('admin page: the saved value is shown after reload', (await admin.inputValue('#authentik-dav-cache-ttl')) === '45')
	await admin.fill('#authentik-dav-cache-ttl', '60')
	await admin.locator('#authentik-dav-status').evaluate((e) => { e.textContent = '' })
	await admin.click('#authentik-dav-save')
	await status(admin, '#authentik-dav-status')

	// Personal settings → Security, as alice (the web login accepts her Authentik password too)
	const alice = await (await browser.newContext({ locale: 'en-US' })).newPage()
	await login(alice, 'alice', TEST_USER_PASS)
	await alice.goto(`${NC}/settings/user/security`)
	await alice.waitForSelector('#authentik-dav-personal-enabled', { timeout: 20000 })
	expect('personal page: switched on by default', await alice.isChecked('#authentik-dav-personal-enabled'))
	// Nextcloud's checkbox style hides the input and shows the label: click the label, as a person would.
	await alice.click('label[for="authentik-dav-personal-enabled"]')
	s = await status(alice, '#authentik-dav-personal-status')
	expect('personal page: switching off is saved', s.text === 'Saved', s.text)
	await alice.reload()
	await alice.waitForSelector('#authentik-dav-personal-enabled')
	expect('personal page: still off after reload', !(await alice.isChecked('#authentik-dav-personal-enabled')))
	await alice.locator('#authentik-dav-personal-status').evaluate((e) => { e.textContent = '' })
	await alice.click('label[for="authentik-dav-personal-enabled"]')
	s = await status(alice, '#authentik-dav-personal-status')
	expect('personal page: switching on again is saved', s.text === 'Saved', s.text)
} catch (e) {
	expect('browser test', false, e.message.split('\n')[0])
} finally {
	await browser.close()
}
console.log(fails === 0 ? 'All browser checks passed.' : `${fails} browser check(s) failed.`)
process.exit(fails === 0 ? 0 : 1)
