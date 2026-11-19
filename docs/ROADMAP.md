# Roadmap / todo log

Update checkboxes as work lands. Agents: treat this as the **next-stage** source of truth.

Legend: `[x]` done · `[ ]` todo · `[~]` partial / blocked on stakeholders

---

## Done

- [x] Schema + PDO connection + health check
- [x] Patient booking / cancel / reschedule (MariaDB)
- [x] Patient UI wired to DB (calendar, confirmation, status)
- [x] Admin availability, desk, patients, waitlist
- [x] CSRF on mutating forms; admin login gate
- [x] Phase 3A: `Admin.Role`, email-only auth, Staff CRUD on MariaDB
- [x] Phase 3B: SMS helper + workflows (mock); M9 tests
- [x] Phase 3C: production readiness **review** (documented risks)

---

## In progress — Twilio trial wiring

- [x] Project already has curl-based Twilio send in `src/sms.php` (no Composer SDK)
- [x] `scripts/sms_trial_send.php` for a one-off live smoke test
- [ ] Put Account SID + Auth Token in local `.env` (never commit)
- [ ] Verify personal test phone as Verified Caller ID in Twilio Console (trial rule)
- [ ] Flip local `.env` to `SMS_ENABLED=true` + `SMS_MODE=live` + `SMS_LIVE_BREAK_GLASS=true` only while testing
- [ ] Run `php scripts/sms_trial_send.php` and confirm phone receives text
- [ ] Flip back to `SMS_ENABLED=false` / `SMS_MODE=mock` / `SMS_LIVE_BREAK_GLASS=false` when done testing

---

## Done — Stage A: SMS durability (technical)

Goal: make consent and send safety production-credible **without** a Notification table. Keep live SMS off except intentional local trials.

- [x] Migrate consent / opt-out from `storage/sms_preferences.json` → MariaDB on `Patient` (`SmsConsentStatus` / `UpdatedAt` / `Source`; phone-level STOP; DDL is source of truth)
- [x] Replace `storage/sms_dedupe.json` with Appointment-field dedupe (cancel/reschedule clear Notification*; no new column / no Notification table; ADR-011)
- [x] Harden webhook: unsigned only for local mock/disabled; live validates `X-Twilio-Signature` over `SMS_WEBHOOK_URL`; M10 tests; ADR-012
- [x] Env guard: live send only when `APP_ENV=production` or `SMS_LIVE_BREAK_GLASS=true` (plus enabled/live/credentials); M11; ADR-013
- [x] Cron: America/New_York target date; flock overlap lock; Failed stays retryable next run; ADR-013
- [x] `storage/.htaccess` deny + gitignore exception; log/mock redaction review (last4 only); ADR-013
- [x] Regression: M9 field dedupe; M10 webhook; M11 ops guards (fictional phones only)

**Stage A technical checklist is complete.** Stakeholder / legal items below remain open and block real clinic live SMS (do not invent policy):

- [~] Approve consent copy and STOP/START re-opt-in rules
- [~] Twilio BAA + HIPAA project + A2P 10DLC + From number
- [~] Approve what may appear in SMS body (minimize clinical detail)

---

## Done — Stage B: Final frontend integration

Goal: polish existing UI against the live backend; no redesign-from-scratch unless asked.

- [x] Patient: optional-consent copy (EN/ES); YES/NO (not “tap link”); confirmation branches on consent + send result
- [x] Admin: desk/Patients reminder buttons + accept≠delivery wording; Messages template usage clarified; badge “Reminder accepted”
- [x] Responsive/spacing: Patients visit-manage stack under 800px; consent callout; Messages stack gap
- [x] Copy walkthrough against booking / remind / cancel / reschedule SMS behavior (fictional data; mock SMS)

---

## Done — Stage C: Deployment preparation

- [x] Production `.env` pattern: [`.env.production.example`](../.env.production.example); no demo seed; `APP_ENV=production`
- [x] HTTPS / Secure cookies (`APP_ENV=production` forces Secure); webhook URL documented in [`DEPLOY.md`](DEPLOY.md)
- [x] Cron for `scripts/send_appointment_reminders.php` documented (ET + flock)
- [x] First `super_admin`: `scripts/create_first_super_admin.php` + README / DEPLOY
- [x] Backup story: MariaDB + `.env` + `storage/messages.json` in [`DEPLOY.md`](DEPLOY.md)
- [x] Keep `SMS_ENABLED=false` / mock until Stage A stakeholder items cleared (called out in production env + DEPLOY)

---

## Next — Stage D: Capstone testing

- [ ] Re-run M3–M9 (and new tests) on target environment
- [ ] Scripted + manual test plan (patient + admin + auth + SMS mock)
- [ ] Document known limitations (no HIPAA claim; mock vs live SMS)
- [ ] Live SMS pilot only if explicitly approved (still no real PHI in logs)

---

## Out of scope (unless user re-opens)

- Custom messaging server / Notification ledger table
- Full SMS delivery-status DB ledger
- Rebuilding invite/username JSON staff system
- Managing XAMPP MariaDB service from agents
- Claiming HIPAA compliance without a formal assessment

---

## Suggested agent prompt when starting a stage

> Read `docs/AGENTS.md`, `docs/DECISIONS.md`, and the relevant section of `docs/ROADMAP.md`. Implement only Stage X. Do not enable live SMS. Do not manage MariaDB service. Use fictional data and clean up.
