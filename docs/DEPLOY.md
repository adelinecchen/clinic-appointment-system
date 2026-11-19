# Deployment preparation (Stage C)

Ops checklist for putting this app on a host. This is **not** a HIPAA compliance claim. Keep live SMS off until clinic/legal and Twilio production setup are done.

Also see [`.env.production.example`](../.env.production.example) and the human runbook in [`README.md`](../README.md).

---

## 1. Production `.env`

1. Copy [`.env.production.example`](../.env.production.example) to `.env` on the server (never commit `.env`).
2. Set `APP_ENV=production` (not `local`).
3. Keep `ADMIN_SEED_ENABLED=false`. Leave seed password fields empty.
4. Use a dedicated MariaDB user/password (not empty-root XAMPP defaults).
5. Keep SMS mock/off until stakeholders clear Stage A legal items:

```env
SMS_ENABLED=false
SMS_MODE=mock
SMS_LIVE_BREAK_GLASS=false
```

6. When ready for live Twilio later: set credentials, `SMS_WEBHOOK_URL` to the exact public HTTPS webhook, then `SMS_ENABLED` / `SMS_MODE` only after approval. Live sends also require `APP_ENV=production` (or temporary `SMS_LIVE_BREAK_GLASS` for trials only).

---

## 2. HTTPS, Secure cookies, webhook URL

- Point the web server **document root** at `public/` only (not the repo root). That keeps `src/`, `storage/`, `.env`, and `database/` off the public URL. `src/.htaccess` and `storage/.htaccess` are backups if the tree is exposed.
- Terminate TLS at the host or reverse proxy. Prefer redirecting HTTP → HTTPS.
- If PHP sits behind a proxy, forward `X-Forwarded-Proto: https` so non-production Secure detection still works. **When `APP_ENV=production`, session cookies are always `Secure`** (`clinic_admin` cookie via `src/csrf.php`).
- Set `SMS_WEBHOOK_URL` to the exact public HTTPS URL of `public/api/sms_webhook.php`, and configure the same URL in Twilio Console: **Phone Numbers → Active numbers → [number] → Messaging** (“A message comes in” / status callback).

---

## 3. Cron (reminder texts)

Target date uses **America/New_York**. Overlapping runs are skipped via `storage/sms_reminder_cron.lock`.

```bash
0 10 * * * TZ=America/New_York /usr/bin/php /path/to/Capstone/scripts/send_appointment_reminders.php >> /path/to/Capstone/storage/reminder_cron.log 2>&1
```

- Replace `/usr/bin/php` with the host PHP binary (XAMPP example: `/Applications/XAMPP/xamppfiles/bin/php`).
- `SMS_REMINDER_DAYS_AHEAD` defaults to `2`.
- Failed sends stay eligible for the next run; successes set `ConfirmationStatus=Pending`.
- Ensure `storage/` is writable by the cron user (lock + optional log). Do not serve `storage/` on the web.

---

## 4. First `super_admin`

Do **not** turn on `ADMIN_SEED_ENABLED` in production.

**Preferred (CLI):**

```bash
/path/to/php scripts/create_first_super_admin.php \
  "Clinic Lead" lead@example.org 5550100000 'choose-a-strong-password'
```

Runs only when `Admin` is empty. Then sign in at `/admin/login.php` and create other staff from **Staff**.

**Alternate (SQL):**

```bash
/path/to/php -r 'echo password_hash("choose-a-strong-password", PASSWORD_DEFAULT), PHP_EOL;'
```

```sql
INSERT INTO Admin (Name, PhoneNumber, Email, PasswordHash, Role)
VALUES (
  'Clinic Lead',
  '5550100000',
  'lead@example.org',
  '<paste-password-hash-here>',
  'super_admin'
);
```

---

## 5. Backup story

Back up at least:

| Asset | Why |
|-------|-----|
| MariaDB `clinic_db` | Patients, appointments, schedule, waitlist, Admin, SMS consent columns |
| `.env` | Secrets — store offline / in a secrets manager, not in git or public backups |
| `storage/messages.json` | Edited SMS templates (if present) |
| Optional: `storage/availability.json` | Only if still used for preview/local blocks |

Example logical dump (adjust user/host; do not manage the MariaDB service from agents):

```bash
mysqldump -u clinic_app -p clinic_db > clinic_db_$(date +%Y%m%d).sql
```

Restore = load dump into MariaDB, restore `.env` and `storage/messages.json`, confirm document root is `public/`, reinstall cron.

---

## 6. SMS stays off until legal clears

Until consent copy, Twilio BAA / HIPAA-eligible project / A2P 10DLC, and body-content policy are approved:

- Leave `SMS_ENABLED=false` and `SMS_MODE=mock`.
- Do not set `SMS_LIVE_BREAK_GLASS=true` on a shared production host.
- Mock still exercises booking/reminder/cancel/reschedule paths for Stage D testing.

---

## Quick verify after deploy

```bash
/path/to/php -r 'require "src/db.php"; echo db_health(), PHP_EOL;'
curl -i https://your.domain.example/api/health.php
```

Expect `"database":"connected"`. Sign in as the first super_admin, open Appointments, confirm SMS remains mock unless intentionally enabled later.
