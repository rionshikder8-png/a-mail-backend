# A-Mail Backend

**A-Mail by Adverune: REST API and administration console.**
Plain PHP 8.1+ and MySQL 8. No frameworks, no Node.js. Companion to [`a-mail-app`](https://github.com/rionshikder8-png/a-mail-app).

> **Scope note.** The brief asked for an official website as well; the owner later removed it. This repository therefore contains the **API** (`/v1/*`), the **admin console** (`/admin/*`) and a one-time **setup wizard** (`/setup/`, for hosts without SSH) only. The `website/` folder, `robots.txt`, `sitemap.xml` and `templates/website/` from the original structure are intentionally absent.

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

## Deploy on cPanel shared hosting (Dorahost)

Everything below uses only the cPanel web interface. No SSH needed.

### 1. Create the subdomain
cPanel > **Domains** (or Subdomains) > create `api.adverune.store`. Note its **Document Root** (for example `/home/CPUSER/api.adverune.store`). Then cPanel > **SSL/TLS Status** > run **AutoSSL** so the subdomain has a certificate.

### 2. Choose PHP 8.1 or higher
cPanel > **Select PHP Version** > pick `8.1` (or `8.2`, `8.3`) for `api.adverune.store`. Under **Extensions** make sure these are ticked: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `json`. (`imap` is optional.)

### 3. Create the database
cPanel > **MySQL Databases**:
1. Create a database, for example `amail` (the real name will be `CPUSER_amail`).
2. Create a user with a strong password.
3. Add the user to the database with **ALL PRIVILEGES**.

### 4. Upload the files
cPanel > **File Manager** > open the subdomain's document root > **Upload** `a-mail-backend.zip` > right-click > **Extract**. Move the files so `index.php` sits directly in the document root.

### 5. Create the `.env`
In File Manager click **Settings** > tick **Show Hidden Files**. Copy `.env.example` to `.env` and edit it:
* `DB_NAME`, `DB_USER`, `DB_PASS` from step 3 (`DB_HOST=localhost`)
* `APP_KEY`, `TOKEN_PEPPER`, `SETUP_TOKEN`: three different random strings of 40+ characters
* `SMTP_USER` / `SMTP_PASS`: a mailbox you create in cPanel > **Email Accounts**, for example `system@adverune.store`
* `SMTP_HOST`: shown in Email Accounts > **Connect Devices**. Usually `mail.adverune.store`. Use port `465` with `SMTP_SECURITY=ssl`.

Set permissions of `.env` to `600`.

### 6. Run the setup wizard
Open `https://api.adverune.store/setup/?token=YOUR_SETUP_TOKEN`. It shows what your server supports, installs the database, creates your first administrator, and tests the SMTP login. When everything is green click **Finish and lock**, then delete the `setup/` folder and remove `SETUP_TOKEN` from `.env`.

### 7. Cron jobs
cPanel > **Cron Jobs**. Use the PHP path shown for your PHP version (commonly `/usr/local/bin/php` or `/opt/cpanel/ea-php81/root/usr/bin/php`) and your home path:

| Schedule | Command |
|---|---|
| Every minute | `/usr/local/bin/php /home/CPUSER/api.adverune.store/cron/process-outgoing.php` |
| Every minute | `/usr/local/bin/php /home/CPUSER/api.adverune.store/cron/scheduled-emails.php` |
| Every 10 minutes | `/usr/local/bin/php /home/CPUSER/api.adverune.store/cron/process-bounces.php` |
| Every 15 minutes | `/usr/local/bin/php /home/CPUSER/api.adverune.store/cron/system-health.php` |
| Hourly | `/usr/local/bin/php /home/CPUSER/api.adverune.store/cron/cleanup-tokens.php` |
| Daily 03:23 | `/usr/local/bin/php /home/CPUSER/api.adverune.store/cron/cleanup-trash.php` |
| Daily 03:40 | `/usr/local/bin/php /home/CPUSER/api.adverune.store/cron/storage-check.php` |

If your host limits cron to every 5 minutes, use `*/5`. Outgoing mail then leaves up to 5 minutes after sending.

### 8. Receive mail (pipe to the app)
cPanel's mail server already accepts mail for `adverune.store`. Hand it to A-Mail:
1. In File Manager set `mail/pipe.php` permissions to **755**.
2. cPanel > **Email** > **Default Address** > choose `adverune.store` > **Pipe to a Program** and enter the path relative to your home folder, e.g. `api.adverune.store/mail/pipe.php`.
3. Mail for any address then flows into A-Mail. Addresses with no A-Mail account are rejected back to the sender.

Important: A-Mail users get their mail through A-Mail only. Do **not** also create cPanel mailboxes with the same names as A-Mail users, because a real cPanel mailbox takes priority over the catch-all and the mail would never reach the app.

If your host does not allow piping, use the fallback: create one cPanel mailbox `catchall@adverune.store`, make it the Default Address, set `IMAP_*` in `.env`, enable the `imap` PHP extension, and add the cron `process-incoming.php` every minute.

### 9. DNS and mail authentication
Because DNS for `adverune.store` is at Dorahost/cPanel, the mail records usually exist already. In cPanel open **Email Deliverability** and click **Repair/Manage** until `adverune.store` shows valid **SPF** and **DKIM**. Add a DMARC record in **Zone Editor**: TXT, name `_dmarc`, value `v=DMARC1; p=none; rua=mailto:system@adverune.store` (move to `p=quarantine` once mail is flowing). Make sure the `api` A record points to your server.

### 10. Prove it works
1. `https://api.adverune.store/v1/health` shows `{"success":true,...}`.
2. Sign in at `/admin/`.
3. In the Flutter app: Settings > Account > **Test connection**.
4. Register two A-Mail accounts and send between them.
5. Send to a Gmail address and reply from Gmail. In Gmail choose **Show original** and confirm SPF, DKIM and DMARC say PASS.

Until step 5 succeeds, do not tell users that external email works.

### Shared-hosting limits to know about
* Mail goes out through your host's mail server, so the host's sending limits apply (often 100 to 500 messages per hour per domain). Ask Dorahost for the exact figure.
* The server only lets a logged-in mailbox send as itself or other addresses on the same domain. If Dorahost rejects sending as `alice@adverune.store`, ask them to allow it, or add a relay service.
* Shared IPs can have a weaker reputation. If mail to Gmail lands in spam, an SMTP relay service (Brevo, Mailgun, Amazon SES, Resend) fixes it: only change the `SMTP_*` values.
* Outbound port 25 is usually blocked. That is why A-Mail sends through your host's SMTP server.

### Alternative: VPS with SSH
```bash
cp .env.example .env && nano .env
php database/install.php
php database/create-admin.php you@example.com "Your Name" super_admin
```

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
