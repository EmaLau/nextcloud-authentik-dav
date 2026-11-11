# Authentik password login for Nextcloud WebDAV

<p align="center"><img src="docs/cover.svg" width="400" alt=""></p>

> **Work in progress — private repository.** It runs on one homelab; a security review is needed before publishing. See [To do before publishing](#to-do-before-publishing).

When Nextcloud users log in through **Authentik** (OpenID Connect), the web login works, but WebDAV clients (Finder, Windows Explorer, rclone, file managers) cannot do the OIDC dance: they only send a username and a password. Users then need a separate Nextcloud app password, or a second local password.

This small Nextcloud app lets WebDAV and other password-based clients use the **Authentik password** instead. Nextcloud asks Authentik whether the password is right, through a dedicated Authentik flow that creates no session.

## How it works

- It is a **password-only user backend**: it owns no users. Accounts stay in Nextcloud's database backend, and the app only answers "is this the right password for this existing account?".
- For each check it runs a dedicated Authentik flow through the flow executor API: identification stage → password stage. The password is accepted only if the flow finishes.
- If the user has MFA configured in Authentik, the flow asks for the second factor, and the check **fails**: those users keep using Nextcloud app passwords for WebDAV.
- Nextcloud administrators are excluded by default.
- A correct password is cached (as a `password_hash`) for a few minutes, so a WebDAV client does not hit Authentik at every request. A password change in Authentik takes effect at most after the cache time.

## Requirements

- Nextcloud 30–35.
- Authentik, reachable **from the Nextcloud server**.
- An existing Nextcloud account for every user (for example created in advance, or provisioned by `user_oidc` at the first web login).

## Installation

### 1. The Authentik flow

Create a flow (for example `nextcloud-webdav-check`, designation *Authentication*) with:

1. an **Identification** stage (username field);
2. a **Password** stage;
3. optionally an **Authenticator validation** stage, so that users with MFA are refused instead of logged in with a password only.

Do **not** add a *User login* stage: the flow must not create a session.

### 2. The Nextcloud app

```bash
cp -r authentik_dav /var/www/html/custom_apps/
chown -R www-data:www-data /var/www/html/custom_apps/authentik_dav
occ app:enable authentik_dav
occ config:app:set authentik_dav base_url --value=https://authentik.example.com
occ config:app:set authentik_dav flow_slug --value=nextcloud-webdav-check
```

With Docker: `docker exec -u 33 <nextcloud-container> php occ ...`.

### 3. Try it

```bash
curl -u alice:'her-authentik-password' -X PROPFIND \
  https://nextcloud.example.com/remote.php/dav/files/alice/ -H 'Depth: 0'
```

## Settings

| Key | Default | Description |
|---|---|---|
| `base_url` | — (required) | Authentik base URL, as seen from the Nextcloud server |
| `flow_slug` | `nextcloud-webdav-check` | Slug of the check flow |
| `cache_ttl` | `300` | Seconds a correct password stays cached (`0` disables the cache) |
| `allow_admins` | `no` | `yes` to accept Authentik passwords for Nextcloud admins too |

Disable the app: `occ app:disable authentik_dav`.

## To do before publishing

Security review:

- [ ] Rate limiting: check how Nextcloud's brute-force protection applies to this backend, and whether Authentik's own limits are enough.
- [ ] Cache: consider memory-only caching and a shorter default TTL; document the trade-off.
- [ ] `allow_local_address` is always on: make it a setting.
- [ ] Refuse plain-HTTP `base_url` unless explicitly allowed.
- [ ] Log failed attempts with enough context for fail2ban-style tooling.

Packaging:

- [ ] `info.xml`: real author, repository and bug tracker URLs; test and declare Nextcloud 36.
- [ ] Automated tests (PHPUnit) with a mocked Authentik.
- [ ] Screenshots of the Authentik flow.

## License

[GNU Affero General Public License v3.0](LICENSE), as declared in `appinfo/info.xml`.
