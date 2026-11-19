# Architecture decisions

Record of important choices so agents do not re-litigate settled design.  
Add new entries at the top when the user approves a change.

Format: **Status** — accepted | superseded | pending stakeholder.

---

## ADR-015 — Stage C deployment preparation

**Status:** accepted

**Context:** Need a production-ready ops path without enabling live clinic SMS or claiming HIPAA.

**Decision:**

- Ship [`.env.production.example`](../.env.production.example) with `APP_ENV=production`, `ADMIN_SEED_ENABLED=false`, SMS mock/off.
- Force `Secure` session cookies when `APP_ENV=production`; document TLS + `SMS_WEBHOOK_URL`.
- First admin via `scripts/create_first_super_admin.php` (empty `Admin` only) or SQL — never production demo seed.
- Document cron (America/New_York + flock), backups (MariaDB + `.env` + message templates), and SMS-off-until-legal in [`DEPLOY.md`](DEPLOY.md).

---

## ADR-014 — Stage B UI copy vs SMS behavior

**Status:** accepted

**Context:** Patient/admin UI still described link-tap confirms and “does not send an SMS” while the backend sends reminders.

**Decision:** Keep the existing layout/design. Align copy only:

- Patient: texts are optional; confirm via YES/NO reply; confirmation page reflects consent + whether a booking SMS was accepted.
- Admin: reminder actions say they send a text; success/muted copy stress provider accept ≠ delivery; desk badge label “Reminder accepted”.
- Messages page documents which templates cron/desk actually use (`follow_up`).

---

## ADR-013 — SMS env guard, cron lock, storage exposure

**Status:** accepted

**Context:** Finish Stage A technical durability without a Notification table.

**Decision:**

- **Env guard:** Live Twilio send requires `sms_live_ready()` **and** (`APP_ENV=production` **or** `SMS_LIVE_BREAK_GLASS=true`). Otherwise return `code=env_guard` (no silent mock success when live was requested). Local trials must set break-glass explicitly and turn it off afterward.
- **Cron:** `scripts/send_appointment_reminders.php` uses America/New_York for the target date; non-blocking flock on `storage/sms_reminder_cron.lock` skips overlap. Failed sends stay `ConfirmationStatus=Not Sent` for the next run; successes become `Pending`.
- **Storage:** Document root must stay `public/`. `storage/.htaccess` denies Apache access if the tree is exposed. Logs/mock metadata keep last-4 phone digits only — never full numbers or message bodies.

**Stage A technical work is complete.** Clinic/legal approvals (consent copy, BAA/HIPAA project/A2P, body content) remain separate stakeholder items.

---

## ADR-012 — SMS webhook signature hardening

**Status:** accepted

**Context:** Unsigned webhook accepts were too loose for anything beyond local mock. Request `Host` / URI must not define the signature base URL.

**Decision:**

- Unsigned POSTs only when `APP_ENV=local` **and** (`SMS_ENABLED` is off **or** `SMS_MODE=mock`). Missing/non-local `APP_ENV` fails closed.
- When `SMS_ENABLED` + `SMS_MODE=live`, always require valid `X-Twilio-Signature`.
- Validate HMAC over `SMS_WEBHOOK_URL` (exact public URL from env) + sorted POST params + `TWILIO_AUTH_TOKEN`. Never rebuild the URL from the incoming request.
- Non-local: configured URL must be `https://`. Missing token, URL, or signature → 403.
- STOP/START/HELP and consent rules unchanged. No Notification table.

**Ops:** Set the same HTTPS URL in Twilio Console (Phone Numbers → Active number → Messaging → “A message comes in” / status callback) and in `SMS_WEBHOOK_URL`.

---

## ADR-011 — SMS dedupe via Appointment fields

**Status:** accepted

**Context:** File `storage/sms_dedupe.json` was interim and fragile. Prefer no Notification table and no new columns.

**Decision:**

- Dedupe from Appointment fields only (`AppointmentStatus`, `ConfirmationStatus`, `NotificationStatus`).
- `NotificationStatus` / `NotificationDate` remain **last outbound attempt** (`Sent` = provider accept/mock; not delivery).
- On cancel (status transition) and reschedule, clear `NotificationStatus` / `NotificationDate` so the next lifecycle SMS can send once; `Sent` then blocks duplicates. Already-cancelled no-ops leave fields alone.
- Rules when `!$force`: booking/reschedule skip if `Sent`; cancel skip if `Cancelled` + `Sent`; reminder skip if `Pending` + `Sent`. `Failed` is always retryable.
- Ordinary booking/cancel/reschedule/cron use `force=false`. Admin desk reminder may use `force=true` for intentional re-send.
- Reminder cron: `Scheduled` + `ConfirmationStatus = Not Sent` (includes booking/reschedule already-`Sent`).
- Retired `sms_dedupe.json`. Still no Notification table / no new columns.

---

## ADR-010 — Patient SMS consent columns (phone-level)

**Status:** accepted (technical); wording / re-opt-in policy still pending clinic/legal

**Context:** File-backed prefs were not durable or backup-friendly. Shared phones must share STOP.

**Decision:**

- Add to `Patient`: `SmsConsentStatus` ENUM(`unknown`,`opted_in`,`opted_out`), `SmsConsentUpdatedAt`, `SmsConsentSource` (`booking_form` | `sms_stop` | `sms_start`, plus `test` / `file_import`).
- Application updates **all** Patient rows matching the same 10-digit phone on any change.
- New Patient rows inherit existing phone-level status from siblings.
- No admin UI to edit consent.
- Still no Notification table.

**Pending stakeholders:** exact consent checkbox copy; whether booking checkbox or START may reverse STOP; HELP text.

**Schema:** columns live in `database/databaseDDL.sql`. Pre-prod local workflow is reset-from-DDL, not ongoing ALTER migrations.

---

## ADR-009 — SMS production readiness (Phase 3C review)

**Status:** accepted (review); technical remediations completed in ADR-010–013; clinic/legal still open

**Context:** Mock SMS works; production needs durable consent and safer ops.

**Decision:** Do not add a Notification table. Prefer minimal remediations: Patient consent/opt-out columns, Appointment-field dedupe, webhook/env hardening, cron lock. Live SMS stays off until legal/Twilio setup.

**Consequences:** Consent on `Patient`; field dedupe; webhook signatures; env guard; cron lock; storage deny. Stakeholder/legal approvals still required before clinic live SMS.

---

## ADR-008 — SMS without Notification table (Phase 3B)

**Status:** accepted

**Context:** Need booking/reminder/cancel/reschedule texts without redesigning appointments.

**Decision:**

- Helper `src/sms.php` (Twilio; mock default).
- Reuse `NotificationStatus` / `NotificationDate` for last outbound **attempt**.
- `Sent` = provider accepted (or mock), **not** carrier delivery.
- Reminder success sets `ConfirmationStatus = Pending`.
- Desk `reminder_sent` only when Pending + Sent.
- Consent: optional checkbox; prefs file by phone; STOP via webhook.

**Provider:** Twilio Programmable Messaging, with BAA + HIPAA project + A2P 10DLC required before live—not assumed compliant merely because a BAA exists.

---

## ADR-007 — Admin authentication on MariaDB (Phase 3A)

**Status:** accepted

**Context:** Dual DB email + JSON username auth was inconsistent; Staff roles lived only in JSON.

**Decision:**

- Email + password against `Admin` only.
- Add `Admin.Role`: `admin` | `super_admin` (PHP-validated).
- Staff UI CRUD on `Admin`; retire username login and invite accept flow.
- Seed only when `APP_ENV=local` and `ADMIN_SEED_ENABLED=true`.
- Session: regenerate on login; destroy on logout; HttpOnly / SameSite / Secure when HTTPS.

**Schema:** `Admin.Role` is in `database/databaseDDL.sql`.

---

## ADR-006 — Schedule status vs booking

**Status:** accepted

**Context:** Confusion when booked slots still show `Open` in Schedule.

**Decision:** `Schedule.Status` means clinic is **offering** the slot (Open) or **blocking** it (Closed). Occupancy = non-cancelled Appointment. Closing does not delete Schedule rows (FK + keep booked visits). Admin double-book keeps the same Open schedule row.

---

## ADR-005 — Patient identity and phones

**Status:** accepted

**Decision:** One `Patient` row per person; `PhoneNumber` required but **not** unique (family may share a phone). Booking creates a new patient row rather than merging by phone alone.

---

## ADR-004 — Status columns are separate

**Status:** accepted

**Decision:** Do not collapse into one status field.

- `AppointmentStatus`: Scheduled | Cancelled | Completed  
- `ConfirmationStatus`: Not Sent | Pending | Confirmed | Declined  
- `NotificationStatus`: Not Sent | Sent | Failed (NULL ≈ Not Sent)

Desk UI composites via `clinic_desk_status_key()` in `src/clinic_maps.php`.

---

## ADR-003 — Intentional admin double-book

**Status:** accepted

**Decision:** Patient path rejects occupied slots. Admin may place a waitlist hold on an **unconfirmed** active visit (not Confirmed/Declined/Cancelled/Completed).

---

## ADR-002 — No SMS until workflows exist; then mock-first

**Status:** accepted (evolved by ADR-008)

**Decision:** Desk “mark reminder” originally updated DB only. Phase 3B wires real send helper with mock-first; UI must not claim delivery.

---

## ADR-001 — Frontend preserved; backend via helpers

**Status:** accepted

**Decision:** Existing patient/admin screens stay; PHP in `src/` owns DB and rules. Avoid Composer frameworks and frontend rewrites unless the user asks for UI work.
