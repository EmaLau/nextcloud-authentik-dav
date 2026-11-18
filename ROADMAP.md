# Roadmap

Where this project is going. Items move from top to bottom; each release gets its own entry in a future `CHANGELOG.md`.

**Status:** 1.0 released on 18 November 2026, after a security review of the code and of the Authentik flow. Runs on one homelab (Nextcloud 35, Authentik 2026.8).

## 1.0 — first public release

### Security review

- [x] **Brute force**: already covered by Nextcloud: `OC\User\Session::logClientIn()` throttles failed client logins per IP for every backend. Documented in the README.
- [x] **Cache**: memory-only cache (no distributed cache for password results) and a shorter default TTL (local APCu cache, 60 s); document the trade-off between Authentik load and how fast a changed password takes effect.
- [x] `allow_local_address` is always on: make it a **setting**, off unless Authentik is on a private address.
- [x] **Refuse a plain-HTTP `base_url`** unless `allow_http` is set explicitly.
- [x] **Log failed attempts** with user, client address and reason, in a format fail2ban-style tools can parse, without ever logging the password.
- [x] Review the Authentik flow itself: it must only check the password, never create a session or grant access to anything else. Checked on a live Authentik: no User login stage, no session after the flow; the flow now uses **stages of its own** (username only, built-in password database only, MFA threshold 0), so changes to the web login do not affect it. Tested end to end with a test user: right password accepted, wrong password refused, user with MFA refused, cache expiring after 60 s.

### Packaging and tests

- [x] `info.xml`: real author, repository and bug tracker URLs, Nextcloud 30–36 declared; CI runs the tests against the API of 30, 31, 35 and 36 (master) and validates `info.xml` against the App Store schema.
- [ ] When Nextcloud 36 is released: run the app on a real 36 instance and switch the CI from `dev-master` to `dev-stable36`.
- [x] **PHPUnit tests** with a mocked Authentik (scripted flow executor answers): right password, wrong password, Authentik down, timeout, cache hit, admin refused when `allow_admins=no`.
- [x] **CI** on GitHub Actions: `php -l`, PHPUnit and PHPStan (level 6) on PHP 8.1–8.4.
- [x] **Screenshots** of the Authentik flow and of a WebDAV client logging in.
- [x] Publish: make the repository public, social preview (`docs/social-preview.png`), link from the GitHub profile README and the Homelab page of emalau.it, tag `v1.0.0`.

## Next — features to add after 1.0

In order of priority.

1. **Authentik blueprint**: a YAML blueprint that creates the check flow and its stages in one step, instead of building it by hand.
2. **Admin settings page** in Nextcloud (instead of `occ config:app:set`), with a "test connection" button.
3. **Group allowlist**: accept Authentik passwords only for members of a chosen Nextcloud group.
4. **Setup check** in the Nextcloud admin overview: warns when Authentik is unreachable or the flow slug is wrong.
5. **Nextcloud App Store** publication (same process as Universal Converter: certificate, signed releases).

## Maybe later

- Support for other identity providers that expose a password-check endpoint (e.g. Keycloak direct grant), behind the same interface.

## Not planned

- Creating or provisioning users: accounts stay in Nextcloud's own backend (or in `user_oidc`); this app only checks passwords of existing accounts.
