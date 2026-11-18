# Changelog

## Unreleased

### Added

- Full documentation in English and Italian (`docs/documentation.md`, `docs/documentazione.md`) and Italian README.
- `CONTRIBUTING.md`, `SECURITY.md`, issue and pull request templates.
- App icon (`img/app.svg`, `img/app-dark.svg`), screenshots and documentation links in `info.xml` for the App Store.
- Release archives include `CHANGELOG.md`, which the App Store shows.
- Optional **limit on failed attempts per username** in the Authentik blueprint (`rate_limit: true`), based on Authentik's reputation scores, and a clear log reason when Authentik denies a user. Off by default: see the trade-off in the documentation.
- Documentation: Nextcloud behind a reverse proxy, response times and account existence.
- Weekly CI run with the latest Authentik and Nextcloud, Dependabot for GitHub Actions and development tools.
- Release archives are also published as `authentik_dav.tar.gz`, so `releases/latest/download/authentik_dav.tar.gz` always points to the latest version.

## 1.1.0 — 2026-11-18

### Added

- **Authentik blueprint** (`authentik/nextcloud-webdav-check.yaml`): creates the check flow and three stages used only by it (identification by username only, built-in password database only, MFA check with threshold 0). Applying it again resets them.
- **Admin settings page** in *Administration settings → Security*, instead of `occ config:app:set`, with a **Test connection** button that opens the check flow without sending any username or password. Italian translation.
- **Allowed groups** (`allowed_groups`): only members of chosen Nextcloud groups can use the Authentik password. Checked before contacting Authentik.
- **Setup check** in *Administration settings → Overview*: warns when Authentik cannot be reached, the flow does not exist, or its identification stage accepts the email or shows a password field.
- **End-to-end tests** with a real Authentik and a real Nextcloud (`dev/`), run by GitHub Actions with Nextcloud 31 and 35.
- `scripts/release.sh` to build signed release archives for the App Store.

### Changed

- Cached passwords are tied to the Authentik settings: changing the address, the flow slug or an exception invalidates them at once, and `cache_ttl=0` no longer reads entries cached before.

## 1.0.0 — 2026-11-18

First public release, after a security review of the code and of the Authentik flow: brute force handled by Nextcloud, local memory cache only (60 s), private addresses and plain HTTP refused unless allowed, failed attempts logged without the password, PHPUnit tests and CI on Nextcloud 30–36.
