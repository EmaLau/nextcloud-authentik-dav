# Authentik password login for Nextcloud WebDAV

<p align="center"><img src="docs/cover.svg" width="400" alt=""></p>

> **Work in progress — private repository.** It runs on one homelab; a security review is needed before publishing. See [the roadmap](ROADMAP.md).

When Nextcloud users log in through **Authentik** (OpenID Connect), the web login works, but WebDAV clients (Finder, Windows Explorer, rclone, file managers) cannot do the OIDC dance: they only send a username and a password. Users then need a separate Nextcloud app password, or a second local password.

This small Nextcloud app lets WebDAV and other password-based clients use the **Authentik password** instead. Nextcloud asks Authentik whether the password is right, through a dedicated Authentik flow that creates no session.

## How it works

- It is a **password-only user backend**: it owns no users. Accounts stay in Nextcloud's database backend, and the app only answers "is this the right password for this existing account?".
- For each check it runs a dedicated Authentik flow through the flow executor API: identification stage → password stage. The password is accepted only if the flow finishes.
- If the user has MFA configured in Authentik, the flow asks for the second factor, and the check **fails**: those users keep using Nextcloud app passwords for WebDAV.
- If the user has two-factor authentication enabled **in Nextcloud**, Nextcloud itself refuses every password login from clients, whatever the backend: those users need Nextcloud app passwords too.
- Nextcloud administrators are excluded by default.
- A correct password is cached (as a `password_hash`) for 60 seconds by default, so a WebDAV client does not hit Authentik at every request. The cache is **local to the server** (APCu): nothing derived from the password is stored in Redis or Memcached. Without a local memory cache configured, every request is checked against Authentik. A password change in Authentik takes effect at most after the cache time.

## Security notes

- **Brute force**: failed attempts are throttled by Nextcloud's own brute-force protection. Every client login goes through `OC\User\Session::logClientIn()`, which slows down and then blocks an IP address after repeated failures, for all user backends.
- **Failed attempts are logged** at warning level, with user, client address and reason, never the password:
  `authentik_dav: login failed for user 'alice' from '192.0.2.10': wrong password`.
  Nextcloud also logs its own `Login failed: 'alice' (Remote IP: '192.0.2.10')` line, which fail2ban filters for Nextcloud already match.
- **Plain HTTP is refused**: `base_url` must use `https://`, unless `allow_http=yes` is set (only for Authentik on the same host or a trusted network).
- **Local addresses are refused** by Nextcloud's HTTP client unless `allow_local_address=yes`: set it only when Authentik runs on a private address.
- The check flow must only check the password. Each check uses a fresh cookie jar that is thrown away, so even a session created by mistake is never reused.

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
# only if Authentik is on a private address (LAN, Docker network):
occ config:app:set authentik_dav allow_local_address --value=yes
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
| `cache_ttl` | `60` | Seconds a correct password stays cached in the local memory cache (`0` disables the cache) |
| `allow_admins` | `no` | `yes` to accept Authentik passwords for Nextcloud admins too |
| `allow_local_address` | `no` | `yes` when Authentik is on a private address (LAN, Docker network) |
| `allow_http` | `no` | `yes` to allow a plain-HTTP `base_url` (not recommended) |

Disable the app: `occ app:disable authentik_dav`.

## Roadmap

What is missing before the first public release, and the features planned after it, are in [ROADMAP.md](ROADMAP.md).

## License

[GNU Affero General Public License v3.0](LICENSE), as declared in `appinfo/info.xml`.
