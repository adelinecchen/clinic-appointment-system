# Architecture decisions

Record of important choices so agents do not re-litigate settled design.  
Add new entries at the top when the user approves a change.

Format: **Status** — accepted | superseded | pending stakeholder.

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

**Status:** accepted (review); consent migration landed in ADR-010; other remediations still open

**Context:** Mock SMS works; production needs durable consent and safer ops.

**Decision:** Do not add a Notification table. Prefer minimal remediations: Patient consent/opt-out columns, Appointment-based or small-column dedupe, webhook/env hardening, cron lock. Live SMS stays off until legal/Twilio setup.

**Consequences:** `sms_dedupe.json` remains interim; consent moved off `sms_preferences.json`.

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
