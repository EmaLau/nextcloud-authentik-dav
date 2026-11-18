# Authentik password login for Nextcloud — documentation

🇬🇧 **English** · 🇮🇹 [Italiano](documentazione.md)

This is the complete guide to `authentik_dav`, the Nextcloud app that lets WebDAV and other password-based clients log in with the **Authentik password**. For a short overview see the [README](../README.md).

## Contents

- [The problem it solves](#the-problem-it-solves)
- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
  - [1. The Authentik flow](#1-the-authentik-flow)
  - [2. The Nextcloud app](#2-the-nextcloud-app)
  - [3. Configuration and first test](#3-configuration-and-first-test)
- [Settings](#settings)
- [Using it from clients](#using-it-from-clients)
- [Security](#security)
- [Connection test and setup check](#connection-test-and-setup-check)
- [Log messages and troubleshooting](#log-messages-and-troubleshooting)
- [Updating and removing](#updating-and-removing)
- [Internals](#internals)
- [Development](#development)
- [FAQ](#faq)

## The problem it solves

When Nextcloud users log in through **Authentik** with OpenID Connect (for example with the `user_oidc` app), the web login works: the browser is sent to Authentik, the user logs in there, and comes back. WebDAV clients cannot do that: the Finder, Windows Explorer, rclone, Cyberduck, file managers on Linux and phones only send a **username and a password**. Without this app, every user needs a separate Nextcloud app password, or a second local password to remember.

With this app, those clients accept the **same password the user types on the Authentik login page**. Nextcloud asks Authentik whether it is right, and Authentik stays the only place where passwords are kept and changed.

## How it works

1. A client sends `alice` and a password to Nextcloud (for example a WebDAV `PROPFIND`).
2. Nextcloud asks every user backend whether the password is right. `authentik_dav` is a **password-only backend**: it owns no users and only answers for accounts that already exist in Nextcloud.
3. The app checks a few things locally, before talking to Authentik: the account exists and is enabled, it is not an administrator (unless allowed), it belongs to an allowed group (if groups are set), and a correct password for it is not already in the short local cache.
4. It runs the **check flow** in Authentik through the flow executor API, with a fresh, empty cookie jar:
   - opens the flow and expects an **identification** stage;
   - sends the username and expects a **password** stage;
   - sends the password.
5. The answer decides:
   - the flow **finished** (`xak-flow-redirect`): the password is right, the login succeeds;
   - the password stage comes back: **wrong password**;
   - Authentik asks for a **second factor**: the user has MFA, the login is refused;
   - anything else, or Authentik not answering: refused.
6. On success, a hash of the password (`password_hash`) is kept in the local memory cache for 60 seconds, so a client that sends many requests does not ask Authentik every time.

The flow has no *User login* stage, so it never creates an Authentik session, and the cookie jar is thrown away after each check.

## Requirements

- **Nextcloud 30 to 36**, PHP 8.1 or later. The code is tested against the public API of Nextcloud 30, 31, 35 and the development branch, and end to end on real Nextcloud 31 and 35.
- **Authentik**, reachable **from the Nextcloud server** (not only from the users' browsers). Tested with Authentik 2026.8.
- An **existing Nextcloud account** for every user, with the same username as in Authentik. Accounts can be created in advance or by `user_oidc` at the first web login.
- Recommended: a **local memory cache** in Nextcloud (`memcache.local`, usually APCu). Without it, every request is checked against Authentik.

## Installation

### 1. The Authentik flow

The repository includes an Authentik **blueprint**, [`authentik/nextcloud-webdav-check.yaml`](../authentik/nextcloud-webdav-check.yaml). It creates the check flow and three stages used only by this flow:

| Order | Stage | Settings |
|---|---|---|
| 10 | `nextcloud-webdav-check-identification` | user fields: **username only** (not the email); never shows the matched user; pretends unknown users exist |
| 20 | `nextcloud-webdav-check-password` | backend: **the built-in user database only** (no app-password tokens, no LDAP, no Kerberos); 5 attempts |
| 30 | `nextcloud-webdav-check-mfa-validation` | users **without** MFA pass, users **with** MFA are refused; MFA is never "remembered" (threshold `seconds=0`) |

Why stages of its own: if the flow used the stages of the default login flow, a change to the web login would change WebDAV too. For example "remember MFA for 12 hours" on a shared validation stage would let users with MFA in through WebDAV with the password alone. Why username only: if the identification stage also accepted the email, a Nextcloud username equal to another person's email address would check *that* person's password.

Apply the blueprint once, in one of these ways:

- **Flows and Stages → Flows → Import**, then choose the file;
- **Customization → Blueprints → Create**, paste the file content, save and press **Apply**;
- from the command line: copy the file to `/blueprints/custom/` in the **worker** container and run
  `ak apply_blueprint /blueprints/custom/nextcloud-webdav-check.yaml`.

Applying it again resets the flow and its three stages to these settings, so it is also a quick repair if someone changed them by hand. To use another slug, change `flow_slug` in the `context` section of the file: the stage names follow it.

<details>
<summary>Building the flow by hand instead</summary>

1. Create three stages: an *Identification stage* (user fields: Username only; *Show matched user* off), a *Password stage* (backend: *User database + standard password* only) and an *Authenticator Validation stage* (*Not configured action*: Skip; *Last validation threshold*: `seconds=0`).
2. Create a flow with designation *Authentication* and slug `nextcloud-webdav-check`.
3. Bind the three stages to the flow, in this order. Do **not** add a *User login* stage.
</details>

### 2. The Nextcloud app

From a [release](https://github.com/EmaLau/nextcloud-authentik-dav/releases) (recommended):

```bash
cd /var/www/html/custom_apps
curl -fsSL https://github.com/EmaLau/nextcloud-authentik-dav/releases/download/v1.1.0/authentik_dav-1.1.0.tar.gz | tar -xz
chown -R www-data:www-data authentik_dav
occ app:enable authentik_dav
```

From the repository: copy the `authentik_dav/` folder into `custom_apps/` in the same way.

With Docker, run `occ` inside the container as the web server user: `docker exec -u 33 <nextcloud-container> php occ ...`.

### 3. Configuration and first test

1. Open **Administration settings → Security → Authentik password login**.
2. Enter the **Authentik address** as seen from the Nextcloud server, for example `https://authentik.example.com`.
3. Leave the **check flow slug** at `nextcloud-webdav-check`, unless you changed it in the blueprint.
4. Press **Test connection**. It opens the check flow **without sending any username or password** and tells you whether Authentik answers and the flow starts as expected.
5. Press **Save**.
6. Try a real login with a user **without MFA**:

```bash
curl -u alice:'her-authentik-password' -X PROPFIND -H 'Depth: 0' \
  https://nextcloud.example.com/remote.php/dav/files/alice/
```

`207 Multi-Status` means it works; `401` means the password was refused, and the reason is in the Nextcloud log (see [Log messages](#log-messages-and-troubleshooting)).

## Settings

Everything can be changed on the settings page, or with `occ config:app:set authentik_dav <key> --value=<value>`.

| Key | Default | What it does |
|---|---|---|
| `base_url` | empty | Authentik address, as seen from the Nextcloud server. **Empty switches the app off**: no Authentik password is accepted |
| `flow_slug` | `nextcloud-webdav-check` | Slug of the check flow |
| `allowed_groups` | empty | Nextcloud groups whose members may use the Authentik password. Empty = every account. JSON list (`["friends","webdav"]`) or comma-separated (`friends,webdav`) |
| `cache_ttl` | `60` | Seconds a correct password stays in the local memory cache, from 0 to 3600. `0` asks Authentik at every request |
| `allow_admins` | `no` | `yes` to accept the Authentik password for Nextcloud administrators too |
| `allow_local_address` | `no` | `yes` when Authentik is on a private address (LAN, VPN, Docker network) |
| `allow_http` | `no` | `yes` to allow a plain `http://` address (only for Authentik on the same host or a trusted network) |

Notes:

- Nextcloud keeps app settings in a memory cache for about 3 seconds: a change made with `occ` can take a few seconds to apply.
- Changing `base_url`, `flow_slug`, `allow_http` or `allow_local_address` invalidates every cached password at once.
- A password changed in Authentik keeps working at most for `cache_ttl` seconds.

## Using it from clients

| | |
|---|---|
| **Address** | `https://nextcloud.example.com/remote.php/dav/files/<username>/` |
| **Username** | the Authentik username (the Nextcloud account name), not the display name or the email |
| **Password** | the Authentik password |

- **macOS Finder**: *Go → Connect to Server*, enter the address with `https://`.
- **Windows Explorer**: *Map network drive*, address `https://nextcloud.example.com/remote.php/dav/files/<username>/`.
- **rclone**: `rclone config`, type `webdav`, vendor `nextcloud`.
- **Linux file managers**: `davs://nextcloud.example.com/remote.php/dav/files/<username>/`.

Users who **cannot** use the Authentik password, and should create a Nextcloud **app password** instead (*Personal settings → Security → Devices & sessions*):

- users with **MFA in Authentik**: a password alone is not enough, by design;
- users with **two-factor authentication in Nextcloud**: Nextcloud refuses every password login from clients, whatever the backend;
- Nextcloud **administrators**, unless `allow_admins=yes`;
- users outside the **allowed groups**, if groups are set.

The official Nextcloud desktop and mobile apps use the browser login (Login Flow v2) and do not need this app.

## Security

**What is sent where.** The username and password go from the client to Nextcloud (as with any password login) and from Nextcloud to Authentik, through the check flow. Nothing else leaves the server. Use `https://` for both, or keep the traffic on the same host.

**Brute force.** Every password login from clients goes through Nextcloud's `OC\User\Session::logClientIn()`, which slows down and then blocks an IP address after repeated failures, for every user backend. Authentik also counts failed attempts on the password stage. The app adds no throttling of its own.

**The cache.** Only a `password_hash` of a correct password is kept, in the **local** memory cache (APCu) of the server, never in Redis or Memcached. The key depends on the username and on the Authentik settings. With `cache_ttl=0` nothing is read or written.

**What is checked before contacting Authentik.** Account existing and enabled, administrators, allowed groups, address scheme. A refused user never makes Nextcloud send a password to Authentik.

**Plain HTTP and private addresses** are refused by default: `allow_http` and `allow_local_address` must be switched on explicitly. Nextcloud's HTTP client refuses private addresses unless told otherwise, which protects against an address pointing somewhere unexpected.

**No session.** The check flow has no User login stage and every check starts with an empty cookie jar that is thrown away: even a session created by mistake would never be reused.

**Logs.** Every refused attempt is logged at *warning* level with user, client address and reason, **never the password** (see below). The end-to-end tests check that no password reaches the Nextcloud log.

**fail2ban.** Nextcloud also writes its own `Login failed: 'alice' (Remote IP: '192.0.2.10')` line, which the usual fail2ban filters for Nextcloud already match. To match the app's line instead:

```ini
[Definition]
failregex = authentik_dav: login failed for user '[^']*' from '<HOST>'
```

Reporting a vulnerability: see [SECURITY.md](../SECURITY.md).

## Connection test and setup check

The **Test connection** button and the check in **Administration settings → Overview** run the same test: they open the check flow and look at its first stage, without sending any username or password.

| Result | Meaning | What to do |
|---|---|---|
| Connection works | Authentik answers, the flow starts with an identification stage asking for the username only | nothing |
| Address not set | `base_url` is empty: the app accepts no Authentik password | enter the address |
| Address cannot be used | not `http(s)://`, or `http://` without `allow_http` | use `https://`, or allow HTTP for a local Authentik |
| Authentik cannot be reached | DNS, network, firewall, certificate, or a private address without `allow_local_address` | check the address *from the Nextcloud server*, e.g. `curl` inside the container |
| Flow does not exist | Authentik answered 404 for the slug | apply the blueprint, or fix the slug |
| Does not start with an identification stage | the flow starts with another stage | use the blueprint flow |
| Identification stage should match the username only | the stage also accepts the email, or has a password field (for example the default login flow) | use the blueprint flow, or set *user fields* to Username only |

## Log messages and troubleshooting

The app writes one line per refused attempt (log level *warning*), in this form:

```
authentik_dav: login failed for user 'alice' from '192.0.2.10': <reason>
```

| Reason | Cause | Fix |
|---|---|---|
| `wrong password` | Authentik refused the password | — |
| `the user has MFA in Authentik (use a Nextcloud app password)` | the user has a second factor in Authentik | the user creates a Nextcloud app password |
| `administrators are excluded (allow_admins=no)` | the account is a Nextcloud admin | use an app password, or set `allow_admins=yes` |
| `not in an allowed group (allowed_groups)` | `allowed_groups` is set and the user is in none of them | add the user to a group, or change the setting |
| `Authentik did not accept the username` | the identification stage refused the name | check that the Authentik username equals the Nextcloud account name |
| `base_url is not configured` | the address is empty | set it |
| `base_url uses plain HTTP; use https or set allow_http=yes` | `http://` address | use `https://`, or `allow_http=yes` |
| `base_url must start with https://` | the address has no scheme or another scheme | fix the address |
| `Authentik unreachable: …` | network error or timeout (15 s) | see *Authentik cannot be reached* above |
| `unexpected first stage of the Authentik flow: …` | wrong flow, or the slug points to another flow | use the blueprint flow |
| `unexpected stage after the password: …` | the flow has more stages after the password (consent, user login…) | use the blueprint flow |

Other situations:

- **`429 Too Many Requests` from WebDAV**: Nextcloud's brute-force protection blocked the address after too many failures. Wait, or run `occ security:bruteforce:reset <ip>`.
- **Accepted on the web, refused in the Finder**: check the username (not the email or display name) and whether the user has MFA or Nextcloud 2FA.
- **A changed password still works for a minute**: that is the cache; lower `cache_ttl` if needed.
- **Nothing in the log**: the account does not exist in Nextcloud or is disabled. The app ignores those on purpose.

## Updating and removing

**Updating**: replace the `custom_apps/authentik_dav` folder with the new version, then run `occ upgrade`. Settings are kept. See [CHANGELOG.md](../CHANGELOG.md) for what changed.

**Disabling**: `occ app:disable authentik_dav`. Clients go back to Nextcloud app passwords.

**Removing**: `occ app:remove authentik_dav`, then delete the check flow and its three stages in Authentik if no longer needed.

## Internals

```
authentik_dav/
  appinfo/info.xml, routes.php
  lib/AppInfo/Application.php        registers the backend and the setup check
  lib/Backend.php                    the password-only user backend
  lib/Service/Config.php             reading, validating and saving the settings
  lib/Service/AuthentikClient.php    flow executor: password check and connection test
  lib/Controller/SettingsController  save and test (administrators only, CSRF-checked)
  lib/Settings/Admin.php             the settings form in Administration settings → Security
  lib/SetupCheck/AuthentikConnection the check in Administration settings → Overview
  templates/, js/, css/, l10n/       settings page (plain JavaScript, no build step), translations
authentik/                           the Authentik blueprint
dev/                                 Docker environment and end-to-end tests
tests/                               PHPUnit tests
scripts/release.sh                   release archive and App Store signature
```

The flow executor API (`/api/v3/flows/executor/<slug>/?query=`) is the same one the Authentik login page uses: `GET` returns the current stage as JSON, `POST` answers it. The app makes one `GET` and two `POST`s per check, with a 15-second timeout; the connection test makes one `GET` with a 5-second timeout.

## Development

**Unit tests** use Nextcloud's public API stubs (`nextcloud/ocp`) and a simulated Authentik:

```bash
composer install
composer test      # PHPUnit
composer lint      # php -l on every file
composer analyse   # PHPStan, level 6
```

Without PHP: `docker run --rm -v "$PWD:/app" -w /app composer:2 sh -c 'composer install && composer test'`.

**End-to-end tests** run a real Authentik and a real Nextcloud in Docker:

```bash
cp dev/.env.example dev/.env          # replace every value with a random string
docker compose -f dev/compose.yaml --env-file dev/.env up -d --wait
dev/setup.sh                          # copies the app, applies the blueprint, creates test users
dev/test.sh                           # real WebDAV logins and setup checks
docker compose -f dev/compose.yaml --env-file dev/.env down -v   # delete everything
```

`dev/setup.sh` creates `alice` (no MFA, in the allowed group), `bob` (MFA) and `carol` (outside the allowed group), with the Authentik password from `dev/.env` and a different random password in Nextcloud, so a successful login can only come from the app. Run it again after changing the app code. Nextcloud is at http://localhost:8088, Authentik at http://localhost:9088. The environment switches off Nextcloud's brute-force protection, because the tests fail logins on purpose.

**CI** (GitHub Actions): lint, PHPUnit and PHPStan against the API of Nextcloud 30, 31, 35 and master (PHP 8.1–8.4), validation of `info.xml` against the App Store schema, and the end-to-end tests with Nextcloud 31 and 35.

**Releases**: raise `<version>` in `appinfo/info.xml`, add the changes to `CHANGELOG.md`, then run `scripts/release.sh`. With `NC_CONTAINER=<a Nextcloud container>` it also signs the code with the app certificate. The private key lives in `~/.nextcloud/certificates/`, never in the repository.

## FAQ

**Does it create Nextcloud accounts?** No. Accounts must exist already (created by hand, by `user_oidc`, by a script). The app only checks passwords.

**Can I use it without OpenID Connect?** Yes: any Nextcloud account whose name matches an Authentik username can use the Authentik password.

**Why are users with MFA refused?** Accepting only the password of an account protected by a second factor would weaken it. Those users create a Nextcloud app password, which can be revoked device by device.

**Does it work with Keycloak or other providers?** Not yet: it uses Authentik's flow executor API. Other providers are listed under *Maybe later* in the [roadmap](../ROADMAP.md).

**Is it on the Nextcloud App Store?** Not yet: the code-signing certificate has been requested. Until then, install it from the GitHub releases.
