# Contributing

Thanks for your interest in this app! Bug reports, ideas and pull requests are all welcome.
Issues and pull requests can be written in **English or Italian**.

## Reporting a bug

Open an [issue](https://github.com/EmaLau/nextcloud-authentik-dav/issues/new/choose) with the **Bug report** form and include:

- the app version (`occ app:list | grep authentik_dav`), the Nextcloud and Authentik versions;
- what the client did (for example a WebDAV login from the Finder) and what happened (`401`, `429`, error page);
- the `authentik_dav: login failed …` line from the Nextcloud log, and the result of **Test connection** on the settings page.

**Never paste passwords, tokens or the full Nextcloud configuration.** Replace user names and addresses if they are private.

Security problems should not be reported in a public issue: see [SECURITY.md](SECURITY.md).

## Proposing a feature

Use the **Feature request** form and say which problem it solves. Ideas already planned are in [ROADMAP.md](ROADMAP.md).

## Development setup

```bash
git clone https://github.com/EmaLau/nextcloud-authentik-dav.git
cd nextcloud-authentik-dav
composer install
composer test && composer lint && composer analyse
```

Without PHP on the machine: `docker run --rm -v "$PWD:/app" -w /app composer:2 sh -c 'composer install && composer test'`.

For real logins, run the end-to-end environment (Docker):

```bash
cp dev/.env.example dev/.env          # replace every value with a random string
docker compose -f dev/compose.yaml --env-file dev/.env up -d --wait
dev/setup.sh && dev/test.sh
```

- **The app** (`authentik_dav/`) is plain PHP and JavaScript, with no build step and no runtime dependencies.
- **The password check** is in `lib/Service/AuthentikClient.php`, the local checks (admins, groups, cache) in `lib/Backend.php`.
- **A new setting** goes in `lib/Service/Config.php` (reading, validation, saving), in the form (`templates/admin.php`, `js/admin.js`) and in the documentation.
- **The Authentik flow** is the blueprint in `authentik/`; `dev/setup.sh` applies it in the test environment.

## Pull requests

- Keep each pull request focused on one change, and add or update tests (`tests/` and, for behaviour visible from a client, `dev/test.sh`).
- Run `composer test`, `composer lint` and `composer analyse` before opening it: GitHub Actions runs them on every pull request, together with the end-to-end tests.
- Never log a password or anything derived from it, and never send it anywhere but to the configured Authentik.
- Update the documentation (`README.md`, `docs/documentation.md`) and, when you can, the Italian versions (`README.it.md`, `docs/documentazione.md`, `authentik_dav/l10n/it.*`).
- Add a line to the *Unreleased* section of [CHANGELOG.md](CHANGELOG.md).

By contributing you agree that your contribution is licensed under the [AGPL-3.0](LICENSE).
