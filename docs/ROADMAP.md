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
- [ ] Flip local `.env` to `SMS_ENABLED=true` + `SMS_MODE=live` only while testing
- [ ] Run `php scripts/sms_trial_send.php` and confirm phone receives text
- [ ] Flip back to `SMS_ENABLED=false` / `SMS_MODE=mock` when done testing

---

## Next — Stage A: SMS durability (pre-live)

Goal: make consent and send safety production-credible **without** a Notification table. Keep live SMS off except intentional local trials.

- [x] Migrate consent / opt-out from `storage/sms_preferences.json` → MariaDB on `Patient` (`SmsConsentStatus` / `UpdatedAt` / `Source`; phone-level STOP; DDL is source of truth)
- [ ] Replace or reduce reliance on `storage/sms_dedupe.json` (prefer Appointment field rules and/or tiny `LastSmsType`-style column if needed)
- [ ] Harden webhook: no unsigned accepts outside true local; document public HTTPS URL for Twilio signatures
- [ ] Env guard: refuse live send unless production (or explicit break-glass flag) in addition to `SMS_ENABLED` / `SMS_MODE`
- [ ] Cron: document ET; add simple overlap lock; clarify failure/retry behavior
- [ ] Ensure `storage/` not web-reachable; review log redaction
- [ ] Regression: extend M9 + any new migration tests; fictional phones only

**Stakeholder / legal (do not invent policy):**

- [~] Approve consent copy and STOP/START re-opt-in rules
- [~] Twilio BAA + HIPAA project + A2P 10DLC + From number
- [~] Approve what may appear in SMS body (minimize clinical detail)

---

## Next — Stage B: Final frontend integration

Goal: polish existing UI against the live backend; no redesign-from-scratch unless asked.

- [ ] Patient: booking consent UX, confirmation/status copy vs actual SMS behavior
- [ ] Admin: Appointments / Patients / Messages wording (accept ≠ delivered); Staff flows
- [ ] Responsive / spacing passes (Patients, Messages, etc.)
- [ ] Manual walkthrough of happy paths + errors (fictional data)

---

## Next — Stage C: Deployment preparation

- [ ] Production `.env` pattern: no demo seed, strong admin password, `APP_ENV` not `local`
- [ ] HTTPS, session Secure cookies, webhook URL
- [ ] Cron for `scripts/send_appointment_reminders.php`
- [ ] First `super_admin` create procedure (documented in README)
- [ ] Backup story includes MariaDB (and any remaining files that must survive)
- [ ] Keep `SMS_ENABLED=false` until Stage A stakeholder items cleared

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
