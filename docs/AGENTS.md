# Agent guide — Clinic Appointment System

This file is the working context for Cursor agents (and humans pairing with them).  
Human-facing setup and run instructions stay in the repo-root `README.md`.

**Documentation index:** [`README.md`](README.md) in this folder.  
**Also read:** [`DECISIONS.md`](DECISIONS.md) (why), [`ROADMAP.md`](ROADMAP.md) (what next).

---

## What this project is

Privacy-conscious appointment scheduling for a **small volunteer women’s health clinic**.

- Patients book, look up, cancel, and reschedule on a public PHP site.
- Staff use a separate admin desk (email login, MariaDB `Admin` + roles).
- Stack: **PHP 8.x**, **MariaDB 10.4**, **PDO**, no Composer app framework, XAMPP locally.
- SMS is integrated in **mock mode** by default (Twilio-ready; live off until clinic/legal setup).

**Do not claim HIPAA compliance or production readiness** unless the user explicitly asks for a compliance audit with evidence.

---

## Hard constraints (do not violate)

- Do **not** start, stop, restart, or manage the XAMPP MariaDB/MySQL service.
- Do **not** use real patient information; fictional data only; clean up test rows.
- Do **not** enable live SMS (`SMS_ENABLED=true` / `SMS_MODE=live`) unless the user explicitly requests it after clinic approval.
- Do **not** expose API keys, passwords, or `.env` contents in commits or reports.
- Do **not** create a `Notification` table unless the user re-opens that decision.
- Prefer **minimal schema changes**; update `database/databaseDDL.sql` as the source of truth.
- While solo / pre-prod: **no new migration SQL** — reset the local DB from DDL when the schema changes. Add `database/migrations/` only later if a shared or production database must be upgraded in place.
- Do **not** redesign the frontend for its own sake; preserve existing UI language and CSS.
- Do **not** rebuild staff/invite JSON workflows (retired) or implement unrelated features.
- Patient booking **rejects** occupied slots; admin **may** intentional double-book from waitlist.

---

## Architecture (current)

```
public/                 Web root (patient + admin + api)
  index.php             Book + status
  confirmation.php      Post-booking receipt
  admin/*.php           Staff desk (login-gated)
  api/health.php        Health JSON
  api/sms_webhook.php   Twilio STOP/START + status ack (signature rules: ADR-012)
src/                    PHP helpers (not web-served; .htaccess deny)
  db.php                Sole PDO entry
  admin_auth.php        Email auth + Admin CRUD + roles
  appointment_*.php     Booking / cancel / reschedule
  sms*.php              Provider + consent + workflows
database/
  databaseDDL.sql       Canonical schema (edit this; reset local DB from it)
scripts/                Cron / CLI (e.g. reminders)
storage/                Gitignored local state (tests, SMS prefs, templates)
docs/                   Project documentation for agents and maintainers
```

**Data model (core):**

| Table | Role |
|-------|------|
| `Patient` | Name + phone (phone **not** unique; one row per person) |
| `Schedule` | Date+time slots; `Status` Open/Closed = clinic offering, **not** “booked” |
| `Appointment` | FK to Schedule + Patient; separate `AppointmentStatus`, `ConfirmationStatus`, `NotificationStatus`, `NotificationDate` |
| `Waitlist` | Waiting patients for fill / hold |
| `Admin` | Email login, `PasswordHash`, `Role` (`admin` \| `super_admin`) |

**Occupancy:** derived from non-cancelled `Appointment` rows. Closing a slot sets `Schedule.Status = Closed` (does not delete the row—FKs + booked visits).

**Auth:** MariaDB email only. `super_admin` manages Staff accounts. Session cookie `clinic_admin` with CSRF on POSTs.

**SMS fields (no Notification table):**

- `NotificationStatus` / `NotificationDate` = **last send attempt** (`Sent` = provider accepted or mock OK — **not** carrier delivery).
- Reminder success also sets `ConfirmationStatus = Pending`.
- Desk badge `reminder_sent` only when Pending + Sent.
- Consent/opt-out: `Patient.SmsConsentStatus` / `SmsConsentUpdatedAt` / `SmsConsentSource` (phone-level across shared numbers).
- Dedupe: Appointment fields only (booking/reschedule skip on `Sent`; cancel skip on `Cancelled`+`Sent`; reminder skip on `Pending`+`Sent`). Cancel/reschedule DB clears `Notification*` so the next lifecycle SMS can send once. No `sms_dedupe.json`.

**Intentionally still file/preview-backed:** clinic display name, message templates (`storage/messages.json`). `storage/` is outside the web root; `storage/.htaccess` denies Apache if the tree is exposed.

---

## Key decisions (summary)

See [`DECISIONS.md`](DECISIONS.md) for full notes. Short version:

1. Schedule Open/Closed ≠ booked; occupancy from Appointment.
2. Admin auth is DB email + `Role`; JSON username/invites retired.
3. No Notification table; reuse Appointment notification columns.
4. SMS via Twilio helper; mock by default; BAA/HIPAA project/A2P required before live.
5. Phone ≠ SMS consent; optional checkbox; STOP wins.
6. Admin may double-book; patients may not.

---

## Completed phases (backend-focused)

| Phase | Outcome |
|-------|---------|
| M1–M4 | PDO, schedule, booking, cancel/reschedule |
| Patient UI ↔ DB | Calendar, book, status |
| Admin Phase 2 | Availability, desk, waitlist, patients |
| Final backend review | Integration fixes, M3–M7 green |
| Phase 3A | DB admin auth + roles; Staff CRUD on `Admin` |
| Phase 3B | SMS helper, workflows, mock tests (M9) |
| Phase 3C / Stage A | Consent → MariaDB; field dedupe; webhook signatures; env guard; cron lock; storage deny (ADR-010–013) |

**Tests (CLI, fictional data):**  
`storage/m3_booking_test.php` … `m12_deploy_prep_test.php`  
Run with XAMPP PHP, e.g. `/Applications/XAMPP/xamppfiles/bin/php storage/m12_deploy_prep_test.php`

---

## Next stages

Authoritative checklist: **[`ROADMAP.md`](ROADMAP.md)**.

At a high level, expected order after this point:

1. **Stage D — Capstone testing** (scripted + manual), still fictional data; live SMS only if stakeholders approve.
2. **Stakeholder/legal** (consent copy, BAA/HIPAA project/A2P, SMS body policy) — blocks clinic live SMS; Stage A–C engineering done.
3. Ops reference: [`DEPLOY.md`](DEPLOY.md).

---

## How agents should work here

1. Read this file + [`ROADMAP.md`](ROADMAP.md) before large changes.
2. Prefer fixing/verifying existing helpers over new abstractions.
3. After backend changes: run relevant `storage/m*_*.php` tests; clean fictional rows.
4. Schema: update `database/databaseDDL.sql`, then recreate/reset local `clinic_db` from that file when needed.
5. Report honestly what was tested vs only code-reviewed.
6. Keep secrets in `.env` (gitignored); update `.env.example` with empty placeholders only.

---

## Quick file map

| Concern | Start here |
|---------|------------|
| PDO / health | `src/db.php`, `public/api/health.php` |
| Patient book | `src/patient_booking.php`, `public/index.php` |
| Cancel / reschedule | `src/appointment_queries.php`, `src/patient_status.php` |
| Admin desk | `src/admin_desk.php`, `public/admin/index.php` |
| Auth / staff | `src/admin_auth.php`, `public/admin/staff.php` |
| SMS | `src/sms.php`, `src/sms_consent.php`, `src/sms_notifications.php` |
| Status maps | `src/clinic_maps.php` |
| Schema | `database/databaseDDL.sql` |
