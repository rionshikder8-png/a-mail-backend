# A-Mail Backend

**A-Mail by Adverune: REST API and administration console.**
Plain PHP 8.1+ and MySQL 8. No frameworks, no Node.js. Companion to [`a-mail-app`](https://github.com/rionshikder8-png/a-mail-app).

> **Scope note.** The brief asked for an official website as well; the owner later removed it. This repository therefore contains the **API** (`/v1/*`) and the **admin console** (`/admin/*`) only. The `website/` folder, `robots.txt`, `sitemap.xml` and `templates/website/` from the original structure are intentionally absent.

## Honest status

| Area | State |
|---|---|
| REST API for every screen in the Flutter app | Implemented, covered by automated tests in CI |
| Accounts, refresh-token rotation with theft detection, OTP, 2FA, devices | Implemented and tested |
| Mail between `@adverune.store` accounts | Implemented, really delivered inside the system |
| Outbound to Gmail/Outlook/Yahoo | Real SMTP client with TLS, AUTH, queue, retries, DKIM signing. **Needs an SMTP relay or your own MTA configured; until then mail is queued and the API says so (`queued_no_relay`)** |
| Inbound from the internet | Real MIME parser, spam filter, bounce handling. **Needs MX records and a pipe, HTTP hook or IMAP mailbox pointing at it** |
| Push notifications | In-app notification history and device registration work. Delivery through FCM needs a service-account file |
| Payments | Plans and subscriptions are data only. No payment provider is integrated |
| A real mail server (Postfix/Dovecot) | **Not included.** It is infrastructure, not PHP. See "Mail infrastructure" |

Nothing in this project claims that internet email works until you have configured DNS and a relay and run the delivery test at the end of this file.

## Requirements

* PHP 8.1+ with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `curl` (`imap` optional)
* MySQL 8.0+ or MariaDB 10.6+
* Apache with `mod_rewrite` (or any server that can apply the rewrites in `.htaccess`)
* HTTPS (enforced in production)
* Cron

## Install

```bash
git clone https://github.com/rionshikder8-png/a-mail-backend.git && cd a-mail-backend
cp .env.example .env
# edit .env: DB_*, APP_URL, and generate secrets:
#   APP_KEY=$(openssl rand -hex 32)      TOKEN_PEPPER=$(openssl rand -hex 32)
mysql -e "CREATE DATABASE amail CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php database/install.php                       # schema + reference data (safe to re-run)
php database/create-admin.php you@example.com "Your Name" super_admin   # asks for a password (12+ chars)
chmod -R u+rwX storage
```

Point the web server's document root at the repository root and make sure `.htaccess` is honoured. Only `index.php`, `api/`, `admin/`, `assets/` and `mail/inbound.php` are reachable from the web; `config/`, `includes/`, `services/`, `cron/`, `database/`, `storage/`, `tests/` and `.env` are denied by `.htaccess`. For stronger isolation, put the document root on a `public/` copy or add the same denials in your vhost.

Verify: `curl https://api.adverune.store/v1/health`, then open `/admin/` and sign in.

## Cron

```cron
* * * * *    php /var/www/a-mail-backend/cron/process-outgoing.php
* * * * *    php /var/www/a-mail-backend/cron/scheduled-emails.php
* * * * *    php /var/www/a-mail-backend/cron/process-incoming.php      # only if you poll IMAP
*/10 * * * * php /var/www/a-mail-backend/cron/process-bounces.php
*/15 * * * * php /var/www/a-mail-backend/cron/system-health.php
17 * * * *   php /var/www/a-mail-backend/cron/cleanup-tokens.php
23 3 * * *   php /var/www/a-mail-backend/cron/cleanup-trash.php
40 3 * * *   php /var/www/a-mail-backend/cron/storage-check.php
```

All cron scripts refuse to run over HTTP.

## Mail infrastructure (what you must provide)

A-Mail's PHP code accepts and sends mail through standard interfaces. It does not replace a mail server.

**Outbound.** Set `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURITY` (`tls` or `ssl`), `SMTP_USER`, `SMTP_PASS` to a relay (your own Postfix, or a provider). `cron/process-outgoing.php` then delivers the queue with exponential back-off (5 min, 15 min, 1 h, 3 h, 12 h, 24 h) and records every attempt in Admin > Delivery logs.

**Inbound**, choose one:
1. *Pipe (recommended with Postfix).* Route the domain's mail to `php /path/mail/inbound.php` with a transport or virtual alias.
2. *HTTP hook.* Have your relay POST the raw message to `/mail/inbound.php` with header `X-Inbound-Secret: <INBOUND_SECRET>`.
3. *IMAP poll.* Set `IMAP_*` to a catch-all mailbox and run `cron/process-incoming.php` (needs `ext-imap`).

**DNS for `adverune.store`** (Admin > Domains shows live pass/fail for each):

| Type | Host | Value |
|---|---|---|
| MX | `@` | `10 mail.adverune.store.` |
| A | `mail` | your server IP |
| TXT (SPF) | `@` | `v=spf1 mx ip4:YOUR_IP -all` |
| TXT (DKIM) | `amail._domainkey` | `v=DKIM1; k=rsa; p=PUBLIC_KEY` |
| TXT (DMARC) | `_dmarc` | `v=DMARC1; p=quarantine; rua=mailto:dmarc@adverune.store` |
| PTR | your IP | `mail.adverune.store` (set at your hosting provider) |

Generate the DKIM key outside the web root:

```bash
openssl genrsa -out /etc/amail/dkim.private 2048
openssl rsa -in /etc/amail/dkim.private -pubout -outform der | openssl base64 -A   # paste as p=
```
Then set `DKIM_PRIVATE_KEY_PATH=/etc/amail/dkim.private` and `DKIM_SELECTOR=amail`.

**Prove it works** before telling anyone it does: register two accounts, mail yourself at an external address and back, and check the headers show `spf=pass dkim=pass dmarc=pass`.

Many cloud providers block outbound port 25 and new IPs start with poor reputation. A transactional relay for outbound is the pragmatic route.

## Connecting the Flutter app

Build the app with `--dart-define=A_MAIL_API_URL=https://api.adverune.store` (or set it in Settings > Account). The app calls `{base}/v1/<group>/<action>`; `.htaccess` maps that to `api/<group>/<action>.php`.

## API reference

Base: `https://api.adverune.store/v1`. JSON in, JSON out. Authenticated calls send `Authorization: Bearer <access_token>`.

Envelope: `{"success":true,"data":...,"error":null,"meta":{...}}` or `{"success":false,"data":null,"error":{"code","message","fields?"}}`. Lists carry `meta: {page, per_page, total, has_more}`; pass `?page=` and `?per_page=` (max 100).

Status codes: 200/201 ok, 400 bad request, 401 not signed in or bad credentials, 403 forbidden, 404 not found (also used for other users' data), 409 conflict, 422 validation (`error.fields`), 423 locked, 429 rate limited (`Retry-After`), 503 maintenance.

| Endpoint | Method | Auth | Purpose |
|---|---|---|---|
| `/auth/username-available?username=` | GET | no | `{available, reason?}` |
| `/auth/register` | POST | no | `username, password, display_name, recovery_email?, device_name?, platform?` returns tokens + user |
| `/auth/login` | POST | no | `username` (name or full address), `password`. Returns tokens, or `{two_factor_required, challenge_id}` |
| `/auth/two-factor` | POST | mixed | Complete login with `challenge_id, code`; or, signed in, `{enable: bool}` |
| `/auth/refresh-token` | POST | no | `refresh_token` returns a new pair. Reusing an old token revokes the whole family |
| `/auth/logout` | POST | yes | Ends the current session |
| `/auth/forgot-password` | POST | no | `identifier`. Same reply whether or not the account exists |
| `/auth/verify-otp` | POST | no | `identifier, code, purpose` (checks without consuming) |
| `/auth/reset-password` | POST | no | `identifier, code, new_password`. Signs out all devices |
| `/auth/verify-email` | POST | yes | `code`, or `resend: true` |
| `/users/profile` | GET | yes | Current user |
| `/users/update-profile` | POST | yes | `display_name, recovery_email, recovery_phone` |
| `/users/change-password` | POST | yes | `current_password, new_password` |
| `/users/account-settings` | GET/POST | yes | Signature, language, theme and similar |
| `/users/delete-account` | POST | yes | `password`. Permanently deletes the account and its mail |
| `/emails/inbox` `sent` `drafts` | GET | yes | Lists. `inbox` also takes `snoozed=1`, `label_id` |
| `/folders/starred` `important` `all-mail` `spam` `trash` `scheduled` | GET | yes | Lists |
| `/emails/view?id=` or `?thread_id=` | GET | yes | One message (marks read) or a whole conversation |
| `/emails/send` | POST | yes | `to[] cc[] bcc[] subject body_text body_html attachment_ids[]`. Returns `status`: `delivered`, `queued`, `queued_no_relay` or `undeliverable` |
| `/emails/reply` `forward` | POST | yes | As send, plus `in_reply_to` |
| `/emails/save-draft` | POST | yes | Creates or updates (`draft_id`) |
| `/emails/schedule` | POST | yes | As send, plus `scheduled_at` (ISO 8601, future) |
| `/emails/search` | GET | yes | `q, from, to, subject, has_attachment, after, before, label_id, folder` |
| `/emails/archive` `delete` `restore` | POST | yes | `ids[]` |
| `/emails/star` `mark-read` | POST | yes | `ids[], value` |
| `/emails/snooze` | POST | yes | `ids[], until` (null clears) |
| `/labels/list` `create` `update` `delete` `assign` | GET/POST | yes | Per-user labels |
| `/contacts/list` `create` `update` `delete` | GET/POST | yes | Per-user contacts |
| `/attachments/upload` | POST | yes | multipart `file`. Dangerous types blocked |
| `/attachments/download?id=` | GET | yes | Owner only; always sent as an attachment |
| `/attachments/delete` | POST | yes | `id` |
| `/notifications/list` `mark-read` `register-device` `preferences` | GET/POST | yes | History, push token, prefs |
| `/devices/list` `revoke` `login-history` | GET/POST | yes | Signed-in devices |
| `/storage/usage` `quota` | GET | yes | Bytes used and limit |

## Security design

* PDO prepared statements everywhere; user input is never concatenated into SQL.
* Passwords: Argon2id (bcrypt fallback), rehashed on login. Constant-time login path against account enumeration.
* Access tokens: short-lived (15 min), HMAC-signed. Refresh tokens: random, stored only as hashes, rotated on every use, with reuse detection that revokes the family.
* OTPs: hashed, single-use, expiring, attempt-limited.
* Rate limiting in MySQL on login, registration, OTP, reset, send and username checks. Account lockout after repeated failures.
* HTML mail is sanitised on the server; remote content and scripts are stripped. Attachments: extension and content checks, random storage names outside the web root, forced download.
* Admin: separate session cookie (`SameSite=Strict`, `HttpOnly`, `Secure`), CSRF on every form, idle and absolute timeouts, role-based capabilities, optional IP allow-list, every action in the activity log.
* **Privacy:** no admin role can read message subjects or bodies. Logs hold routing metadata only.
* Secrets live in `.env` or real environment variables, never in code or the database.
* Admin accounts can only be created from the command line.

## Admin console

`/admin/` has 22 pages: dashboard, users, user details, mailboxes, domains (live DNS checks), mail logs, delivery logs (with retry), spam (blocked senders), abuse reports, storage, plans, subscriptions, notifications (broadcasts), support tickets, reports (CSV export), security center, system health (measured, not assumed), settings, profile, sign-in and sign-out. Roles: `super_admin`, `admin`, `support`, `moderator`.

## Tests

```bash
php database/install.php            # against a scratch database
php tests/unit_test.php             # pure functions, no services needed
php -S 127.0.0.1:8080 tests/router.php &
php tests/api_test.php http://127.0.0.1:8080
ADMIN_PASSWORD='...' php database/create-admin.php ci@example.com "CI" super_admin
php tests/admin_smoke.php http://127.0.0.1:8080 ci@example.com '...'
```

GitHub Actions (`.github/workflows/php-ci.yml`) runs syntax checks on every file, a secrets and structure check, and the three suites above on PHP 8.1 and 8.3 with MySQL 8.

## Backups

`storage/backups/` is for dumps: `mysqldump --single-transaction amail | gzip > storage/backups/amail-$(date +%F).sql.gz`, plus a copy of `storage/attachments/`. Keep copies off the server.

## Known limits

* No payment provider, no built-in mail server, no FCM delivery until configured.
* Search uses `LIKE`. Fine for personal mailboxes; add FULLTEXT or an external index before very large scale.
* IMAP/POP access for third-party clients (Thunderbird, Apple Mail) is not provided; mailboxes are reached through the A-Mail app and API.
