# Accesso con la password di Authentik per Nextcloud — documentazione

🇬🇧 [English](documentation.md) · 🇮🇹 **Italiano**

Questa è la guida completa di `authentik_dav`, l'app per Nextcloud che permette ai client WebDAV e agli altri client con password di entrare con la **password di Authentik**. Per una presentazione breve c'è il [README](../README.it.md).

## Indice

- [Il problema che risolve](#il-problema-che-risolve)
- [Come funziona](#come-funziona)
- [Requisiti](#requisiti)
- [Installazione](#installazione)
  - [1. Il flow su Authentik](#1-il-flow-su-authentik)
  - [2. L'app su Nextcloud](#2-lapp-su-nextcloud)
  - [3. Configurazione e prima prova](#3-configurazione-e-prima-prova)
- [Impostazioni](#impostazioni)
- [Uso dai client](#uso-dai-client)
- [Sicurezza](#sicurezza)
- [Prova di connessione e controllo nella panoramica](#prova-di-connessione-e-controllo-nella-panoramica)
- [Messaggi nel log e problemi comuni](#messaggi-nel-log-e-problemi-comuni)
- [Aggiornare e rimuovere](#aggiornare-e-rimuovere)
- [Come è fatta](#come-è-fatta)
- [Sviluppo](#sviluppo)
- [Domande frequenti](#domande-frequenti)

## Il problema che risolve

Quando gli utenti entrano in Nextcloud tramite **Authentik** con OpenID Connect (per esempio con l'app `user_oidc`), il login dal web funziona: il browser passa da Authentik, l'utente entra lì e torna indietro. I client WebDAV non possono farlo: il Finder, Esplora risorse, rclone, Cyberduck, i file manager di Linux e dei telefoni inviano solo **nome utente e password**. Senza questa app ogni utente ha bisogno di una password per app di Nextcloud, o di una seconda password locale da ricordare.

Con questa app quei client accettano **la stessa password che l'utente scrive nella pagina di login di Authentik**. Nextcloud chiede ad Authentik se è giusta, e Authentik resta l'unico posto dove le password si conservano e si cambiano.

## Come funziona

1. Un client invia `alice` e una password a Nextcloud (per esempio una richiesta WebDAV `PROPFIND`).
2. Nextcloud chiede a ogni backend utenti se la password è giusta. `authentik_dav` è un **backend solo per le password**: non possiede utenti e risponde solo per account che esistono già in Nextcloud.
3. Prima di contattare Authentik l'app controlla alcune cose in locale: che l'account esista e sia attivo, che non sia un amministratore (salvo permesso), che appartenga a un gruppo ammesso (se i gruppi sono impostati) e che una password giusta non sia già nella breve cache locale.
4. Esegue il **flow di controllo** su Authentik tramite l'API del flow executor, con un contenitore di cookie nuovo e vuoto:
   - apre il flow e si aspetta uno stage di **identificazione**;
   - invia il nome utente e si aspetta uno stage di **password**;
   - invia la password.
5. Decide la risposta:
   - il flow è **finito** (`xak-flow-redirect`): la password è giusta, l'accesso riesce;
   - torna lo stage della password: **password sbagliata**;
   - Authentik chiede un **secondo fattore**: l'utente ha l'MFA, l'accesso viene rifiutato;
   - qualsiasi altra cosa, o Authentik che non risponde: rifiutato.
6. Se va bene, un hash della password (`password_hash`) resta nella cache in memoria locale per 60 secondi, così un client che fa molte richieste non interroga Authentik ogni volta.

Il flow non ha lo stage *User login*, quindi non crea mai una sessione su Authentik, e i cookie vengono buttati dopo ogni controllo.

## Requisiti

- **Nextcloud dalla 30 alla 36**, PHP 8.1 o successivo. Il codice è provato con l'API pubblica di Nextcloud 30, 31, 35 e del ramo di sviluppo, e dal vivo su Nextcloud 31 e 35 veri.
- **Authentik**, raggiungibile **dal server Nextcloud** (non solo dai browser degli utenti). Provato con Authentik 2026.8.
- Un **account Nextcloud già esistente** per ogni utente, con lo stesso nome utente che ha su Authentik. Gli account si possono creare prima o farli creare da `user_oidc` al primo accesso dal web.
- Consigliata: una **cache in memoria locale** in Nextcloud (`memcache.local`, di solito APCu). Senza, ogni richiesta viene controllata su Authentik.

## Installazione

### 1. Il flow su Authentik

Il repository contiene un **blueprint** di Authentik, [`authentik/nextcloud-webdav-check.yaml`](../authentik/nextcloud-webdav-check.yaml). Crea il flow di controllo e tre stage usate solo da questo flow:

| Ordine | Stage | Impostazioni |
|---|---|---|
| 10 | `nextcloud-webdav-check-identification` | campi utente: **solo il nome utente** (non l'email); non mostra mai l'utente trovato; finge che gli utenti sconosciuti esistano |
| 20 | `nextcloud-webdav-check-password` | backend: **solo il database utenti di Authentik** (niente token delle password per app, niente LDAP, niente Kerberos); 5 tentativi |
| 30 | `nextcloud-webdav-check-mfa-validation` | gli utenti **senza** MFA passano, quelli **con** MFA vengono rifiutati; l'MFA non viene mai "ricordato" (soglia `seconds=0`) |

Perché stage proprie: se il flow usasse le stage del login predefinito, una modifica al login dal web cambierebbe anche WebDAV. Per esempio "ricorda l'MFA per 12 ore" su uno stage di validazione condiviso farebbe entrare via WebDAV chi ha l'MFA con la sola password. Perché solo il nome utente: se lo stage di identificazione accettasse anche l'email, un nome utente di Nextcloud uguale all'indirizzo email di un'altra persona controllerebbe la password di *quella* persona.

Applica il blueprint una volta, in uno di questi modi:

- **Flows and Stages → Flows → Import**, poi scegli il file;
- **Customization → Blueprints → Create**, incolla il contenuto del file, salva e premi **Apply**;
- da riga di comando: copia il file in `/blueprints/custom/` nel container **worker** ed esegui
  `ak apply_blueprint /blueprints/custom/nextcloud-webdav-check.yaml`.

Riapplicarlo rimette il flow e le tre stage a queste impostazioni, quindi serve anche per sistemare in fretta modifiche fatte a mano. Per usare un altro slug cambia `flow_slug` nella sezione `context` del file: i nomi delle stage lo seguono.

<details>
<summary>Creare il flow a mano</summary>

1. Crea tre stage: un *Identification stage* (campi utente: solo Username; *Show matched user* spento), un *Password stage* (backend: solo *User database + standard password*) e un *Authenticator Validation stage* (*Not configured action*: Skip; *Last validation threshold*: `seconds=0`).
2. Crea un flow con designazione *Authentication* e slug `nextcloud-webdav-check`.
3. Collega le tre stage al flow, in quest'ordine. **Non** aggiungere lo stage *User login*.
</details>

### 2. L'app su Nextcloud

Da una [release](https://github.com/EmaLau/nextcloud-authentik-dav/releases) (consigliato):

```bash
cd /var/www/html/custom_apps
curl -fsSL https://github.com/EmaLau/nextcloud-authentik-dav/releases/download/v1.1.0/authentik_dav-1.1.0.tar.gz | tar -xz
chown -R www-data:www-data authentik_dav
occ app:enable authentik_dav
```

Dal repository: copia allo stesso modo la cartella `authentik_dav/` in `custom_apps/`.

Con Docker, esegui `occ` nel container come utente del server web: `docker exec -u 33 <container-nextcloud> php occ ...`.

### 3. Configurazione e prima prova

1. Apri **Impostazioni di amministrazione → Sicurezza → Accesso con la password di Authentik**.
2. Inserisci l'**indirizzo di Authentik** come lo vede il server Nextcloud, per esempio `https://authentik.example.com`.
3. Lascia lo **slug del flow di controllo** a `nextcloud-webdav-check`, a meno che tu non l'abbia cambiato nel blueprint.
4. Premi **Prova connessione**. Apre il flow di controllo **senza inviare nomi utente o password** e ti dice se Authentik risponde e se il flow inizia come previsto.
5. Premi **Salva**.
6. Prova un accesso vero con un utente **senza MFA**:

```bash
curl -u alice:'la-sua-password-di-authentik' -X PROPFIND -H 'Depth: 0' \
  https://nextcloud.example.com/remote.php/dav/files/alice/
```

`207 Multi-Status` vuol dire che funziona; `401` vuol dire che la password è stata rifiutata, e il motivo è nel log di Nextcloud (vedi [Messaggi nel log](#messaggi-nel-log-e-problemi-comuni)).

## Impostazioni

Tutto si cambia dalla pagina delle impostazioni oppure con `occ config:app:set authentik_dav <chiave> --value=<valore>`.

| Chiave | Predefinito | Cosa fa |
|---|---|---|
| `base_url` | vuoto | Indirizzo di Authentik, come lo vede il server Nextcloud. **Vuoto spegne l'app**: nessuna password di Authentik viene accettata |
| `flow_slug` | `nextcloud-webdav-check` | Slug del flow di controllo |
| `allowed_groups` | vuoto | Gruppi di Nextcloud i cui membri possono usare la password di Authentik. Vuoto = tutti gli account. Lista JSON (`["amici","webdav"]`) o separata da virgole (`amici,webdav`) |
| `cache_ttl` | `60` | Secondi per cui una password giusta resta nella cache in memoria locale, da 0 a 3600. `0` chiede ad Authentik a ogni richiesta |
| `allow_admins` | `no` | `yes` per accettare la password di Authentik anche per gli amministratori di Nextcloud |
| `allow_local_address` | `no` | `yes` quando Authentik ha un indirizzo privato (LAN, VPN, rete Docker) |
| `allow_http` | `no` | `yes` per consentire un indirizzo `http://` non cifrato (solo per Authentik sulla stessa macchina o su una rete fidata) |

Note:

- Nextcloud tiene le impostazioni delle app in una cache in memoria per circa 3 secondi: una modifica fatta con `occ` può metterci qualche secondo a valere.
- Cambiare `base_url`, `flow_slug`, `allow_http` o `allow_local_address` annulla subito tutte le password in cache.
- Una password cambiata su Authentik continua a funzionare al massimo per `cache_ttl` secondi.

## Uso dai client

| | |
|---|---|
| **Indirizzo** | `https://nextcloud.example.com/remote.php/dav/files/<nomeutente>/` |
| **Nome utente** | il nome utente di Authentik (il nome dell'account Nextcloud), non il nome visualizzato né l'email |
| **Password** | la password di Authentik |

- **Finder di macOS**: *Vai → Connessione al server*, inserisci l'indirizzo con `https://`.
- **Esplora risorse di Windows**: *Connetti unità di rete*, indirizzo `https://nextcloud.example.com/remote.php/dav/files/<nomeutente>/`.
- **rclone**: `rclone config`, tipo `webdav`, vendor `nextcloud`.
- **File manager di Linux**: `davs://nextcloud.example.com/remote.php/dav/files/<nomeutente>/`.

Utenti che **non possono** usare la password di Authentik e devono creare invece una **password per app** di Nextcloud (*Impostazioni personali → Sicurezza → Dispositivi e sessioni*):

- chi ha l'**MFA su Authentik**: la sola password non basta, per scelta;
- chi ha l'**autenticazione a due fattori su Nextcloud**: Nextcloud rifiuta ogni accesso con password dai client, con qualsiasi backend;
- gli **amministratori** di Nextcloud, salvo `allow_admins=yes`;
- chi non è nei **gruppi ammessi**, se i gruppi sono impostati.

Le app ufficiali di Nextcloud per computer e telefono usano il login dal browser (Login Flow v2) e non hanno bisogno di questa app.

## Sicurezza

**Cosa viene inviato e a chi.** Nome utente e password vanno dal client a Nextcloud (come per qualsiasi accesso con password) e da Nextcloud ad Authentik, tramite il flow di controllo. Nient'altro esce dal server. Usa `https://` per entrambi, oppure tieni il traffico sulla stessa macchina.

**Forza bruta.** Ogni accesso con password dai client passa da `OC\User\Session::logClientIn()` di Nextcloud, che rallenta e poi blocca un indirizzo IP dopo troppi errori, per tutti i backend utenti. Anche Authentik conta i tentativi falliti sullo stage della password. L'app non aggiunge limiti suoi.

**La cache.** Viene conservato solo un `password_hash` di una password giusta, nella cache in memoria **locale** (APCu) del server, mai su Redis o Memcached. La chiave dipende dal nome utente e dalle impostazioni di Authentik. Con `cache_ttl=0` non si legge né si scrive nulla.

**Cosa si controlla prima di contattare Authentik.** Account esistente e attivo, amministratori, gruppi ammessi, schema dell'indirizzo. Un utente rifiutato non fa mai inviare una password ad Authentik.

**HTTP non cifrato e indirizzi privati** sono rifiutati per default: `allow_http` e `allow_local_address` vanno accesi esplicitamente. Il client HTTP di Nextcloud rifiuta gli indirizzi privati se non gli si dice altrimenti, e questo protegge da un indirizzo che punta dove non dovrebbe.

**Nessuna sessione.** Il flow di controllo non ha lo stage User login e ogni controllo parte con cookie vuoti che poi vengono buttati: anche una sessione creata per errore non verrebbe mai riusata.

**Log.** Ogni tentativo rifiutato finisce nel log a livello *warning* con utente, indirizzo del client e motivo, **mai la password** (vedi sotto). I test completi controllano che nessuna password arrivi nel log di Nextcloud.

**fail2ban.** Nextcloud scrive anche la sua riga `Login failed: 'alice' (Remote IP: '192.0.2.10')`, che i filtri fail2ban per Nextcloud già riconoscono. Per riconoscere invece la riga dell'app:

```ini
[Definition]
failregex = authentik_dav: login failed for user '[^']*' from '<HOST>'
```

Per segnalare una vulnerabilità: vedi [SECURITY.md](../SECURITY.md).

## Prova di connessione e controllo nella panoramica

Il pulsante **Prova connessione** e il controllo in **Impostazioni di amministrazione → Panoramica** fanno la stessa prova: aprono il flow di controllo e guardano il primo stage, senza inviare nomi utente o password.

| Risultato | Significato | Cosa fare |
|---|---|---|
| La connessione funziona | Authentik risponde e il flow inizia con uno stage di identificazione che chiede solo il nome utente | niente |
| Indirizzo non impostato | `base_url` è vuoto: l'app non accetta password di Authentik | inserisci l'indirizzo |
| L'indirizzo non si può usare | non è `http(s)://`, oppure è `http://` senza `allow_http` | usa `https://`, o consenti HTTP per un Authentik locale |
| Authentik non è raggiungibile | DNS, rete, firewall, certificato, o un indirizzo privato senza `allow_local_address` | controlla l'indirizzo *dal server Nextcloud*, per esempio con `curl` nel container |
| Il flow non esiste | Authentik ha risposto 404 per quello slug | applica il blueprint o correggi lo slug |
| Non inizia con uno stage di identificazione | il flow inizia con un altro stage | usa il flow del blueprint |
| Lo stage di identificazione dovrebbe accettare solo il nome utente | lo stage accetta anche l'email o ha il campo password (per esempio il flow del login predefinito) | usa il flow del blueprint, o imposta i campi utente su solo Username |

## Messaggi nel log e problemi comuni

L'app scrive una riga per ogni tentativo rifiutato (livello *warning*), in questa forma:

```
authentik_dav: login failed for user 'alice' from '192.0.2.10': <motivo>
```

| Motivo | Causa | Soluzione |
|---|---|---|
| `wrong password` | Authentik ha rifiutato la password | — |
| `the user has MFA in Authentik (use a Nextcloud app password)` | l'utente ha un secondo fattore su Authentik | l'utente crea una password per app di Nextcloud |
| `administrators are excluded (allow_admins=no)` | l'account è un amministratore di Nextcloud | password per app, oppure `allow_admins=yes` |
| `not in an allowed group (allowed_groups)` | `allowed_groups` è impostato e l'utente non è in nessuno di quei gruppi | aggiungi l'utente a un gruppo o cambia l'impostazione |
| `Authentik did not accept the username` | lo stage di identificazione ha rifiutato il nome | controlla che il nome utente su Authentik sia uguale al nome dell'account Nextcloud |
| `base_url is not configured` | l'indirizzo è vuoto | impostalo |
| `base_url uses plain HTTP; use https or set allow_http=yes` | indirizzo `http://` | usa `https://`, o `allow_http=yes` |
| `base_url must start with https://` | l'indirizzo non ha lo schema o ne ha un altro | correggi l'indirizzo |
| `Authentik unreachable: …` | errore di rete o tempo scaduto (15 s) | vedi *Authentik non è raggiungibile* sopra |
| `unexpected first stage of the Authentik flow: …` | flow sbagliato, o lo slug punta a un altro flow | usa il flow del blueprint |
| `unexpected stage after the password: …` | il flow ha altre stage dopo la password (consenso, user login…) | usa il flow del blueprint |

Altre situazioni:

- **`429 Too Many Requests` da WebDAV**: la protezione anti-forza bruta di Nextcloud ha bloccato l'indirizzo dopo troppi errori. Aspetta, oppure `occ security:bruteforce:reset <ip>`.
- **Funziona dal web ma non dal Finder**: controlla il nome utente (non l'email né il nome visualizzato) e se l'utente ha l'MFA o il 2FA di Nextcloud.
- **Una password cambiata funziona ancora per un minuto**: è la cache; abbassa `cache_ttl` se serve.
- **Niente nel log**: l'account non esiste in Nextcloud o è disattivato. L'app li ignora di proposito.

## Aggiornare e rimuovere

**Aggiornare**: sostituisci la cartella `custom_apps/authentik_dav` con la nuova versione, poi esegui `occ upgrade`. Le impostazioni restano. Le novità sono in [CHANGELOG.md](../CHANGELOG.md).

**Disattivare**: `occ app:disable authentik_dav`. I client tornano alle password per app di Nextcloud.

**Rimuovere**: `occ app:remove authentik_dav`, poi cancella su Authentik il flow di controllo e le sue tre stage se non servono più.

## Come è fatta

```
authentik_dav/
  appinfo/info.xml, routes.php
  lib/AppInfo/Application.php        registra il backend e il controllo nella panoramica
  lib/Backend.php                    il backend utenti solo per le password
  lib/Service/Config.php             lettura, validazione e salvataggio delle impostazioni
  lib/Service/AuthentikClient.php    flow executor: controllo della password e prova di connessione
  lib/Controller/SettingsController  salva e prova (solo amministratori, con controllo CSRF)
  lib/Settings/Admin.php             il modulo in Impostazioni di amministrazione → Sicurezza
  lib/SetupCheck/AuthentikConnection il controllo in Impostazioni di amministrazione → Panoramica
  templates/, js/, css/, l10n/       pagina delle impostazioni (JavaScript semplice, senza build), traduzioni
authentik/                           il blueprint di Authentik
dev/                                 ambiente Docker e test completi
tests/                               test PHPUnit
scripts/release.sh                   archivio di release e firma per lo Store
```

L'API del flow executor (`/api/v3/flows/executor/<slug>/?query=`) è la stessa che usa la pagina di login di Authentik: `GET` restituisce lo stage corrente in JSON, `POST` gli risponde. L'app fa un `GET` e due `POST` per ogni controllo, con 15 secondi di tempo massimo; la prova di connessione fa un solo `GET` con 5 secondi.

## Sviluppo

I **test unitari** usano gli stub dell'API pubblica di Nextcloud (`nextcloud/ocp`) e un Authentik simulato:

```bash
composer install
composer test      # PHPUnit
composer lint      # php -l su ogni file
composer analyse   # PHPStan, livello 6
```

Senza PHP: `docker run --rm -v "$PWD:/app" -w /app composer:2 sh -c 'composer install && composer test'`.

I **test completi** usano un Authentik e un Nextcloud veri in Docker:

```bash
cp dev/.env.example dev/.env          # sostituisci ogni valore con una stringa casuale
docker compose -f dev/compose.yaml --env-file dev/.env up -d --wait
dev/setup.sh                          # copia l'app, applica il blueprint, crea gli utenti di prova
dev/test.sh                           # accessi WebDAV veri e controlli nella panoramica
docker compose -f dev/compose.yaml --env-file dev/.env down -v   # cancella tutto
```

`dev/setup.sh` crea `alice` (senza MFA, nel gruppo ammesso), `bob` (con MFA) e `carol` (fuori dal gruppo ammesso), con la password di Authentik presa da `dev/.env` e una password diversa e casuale su Nextcloud, così un accesso riuscito può venire solo dall'app. Va rilanciato dopo ogni modifica al codice dell'app. Nextcloud è su http://localhost:8088, Authentik su http://localhost:9088. L'ambiente spegne la protezione anti-forza bruta di Nextcloud, perché i test sbagliano gli accessi apposta.

**CI** (GitHub Actions): lint, PHPUnit e PHPStan con l'API di Nextcloud 30, 31, 35 e master (PHP 8.1–8.4), validazione di `info.xml` con lo schema dello Store e test completi con Nextcloud 31 e 35.

**Release**: alza `<version>` in `appinfo/info.xml`, scrivi le novità in `CHANGELOG.md` ed esegui `scripts/release.sh`. Con `NC_CONTAINER=<un container Nextcloud>` firma anche il codice con il certificato dell'app. La chiave privata sta in `~/.nextcloud/certificates/`, mai nel repository.

## Domande frequenti

**Crea gli account di Nextcloud?** No. Gli account devono esistere già (creati a mano, da `user_oidc`, da uno script). L'app controlla solo le password.

**Si può usare senza OpenID Connect?** Sì: qualsiasi account Nextcloud il cui nome coincide con un nome utente di Authentik può usare la password di Authentik.

**Perché gli utenti con MFA vengono rifiutati?** Accettare la sola password di un account protetto da un secondo fattore lo indebolirebbe. Quegli utenti creano una password per app di Nextcloud, che si può revocare dispositivo per dispositivo.

**Funziona con Keycloak o altri provider?** Non ancora: usa l'API del flow executor di Authentik. Gli altri provider sono tra le idee per il futuro nella [roadmap](../ROADMAP.md).

**È sull'App Store di Nextcloud?** Non ancora: il certificato per firmare il codice è stato richiesto. Fino ad allora si installa dalle release su GitHub.
