# Clinic Appointment System

Privacy-conscious appointment scheduling for a community health clinic. Patients request visits on a public website. Clinic staff use a separate desk to review the schedule. The screens below are a preview with fictional sample data. Patient booking and the staff desk still use that preview content. They do not read or write clinic tables yet, and no messages are sent.

The browser never talks to MySQL directly. Pages and API scripts in `public/` call PHP in `src/`. `src/db.php` is the only file that opens a PDO connection, using values from `.env`.

## Layout

```
public/                      Web root
  index.php                  Patient booking + check status
  book.php                   Redirects to the homepage
  confirmation.php           Preview receipt after a valid form submit
  admin/index.php            Staff dashboard
  admin/login.php            Staff login
  admin/staff.php            Super admin account management (MariaDB Admin)
  admin/invite.php           Retired; redirects to login
  admin/patients.php         Patient list
  admin/availability.php     Clinic calendar and 15-minute holds
  admin/waitlist.php         Waitlist
  admin/notifications.php    Editable patient texts
  api/health.php             JSON health check (PHP up, optional PDO ping)
  assets/css/                Patient and staff styles
  assets/js/booking.js       Adds family-member fields on the form
src/                         Not meant to be requested by a browser
  bootstrap.php              Loads .env and shared helpers
  db.php                     PDO connection to clinic_db
  admin_auth.php             Admin email login and account CRUD
  preview_data.php           Clinic display helpers and message templates
  patient_layout.php         Patient page header and footer
  admin_layout.php           Staff sidebar layout
  .htaccess                  Denies direct web access to this folder
database/                    SQL schema used in MySQL Workbench
  databaseDDL.sql            Tables, keys, and constraints for clinic_db
  migrations/                Versioned ALTER scripts for existing databases
```

Staff sign in with email against the MariaDB `Admin` table. Set `APP_NAME` in `.env` for the display name.

## Requirements

- PHP 8.0 or newer with the `pdo_mysql` extension (included with a current XAMPP install)
- MySQL or MariaDB through XAMPP, with the `clinic_db` schema already created
- Git

## Run it with XAMPP

1. Install [XAMPP](https://www.apachefriends.org/) and start **Apache** and **MySQL** from the XAMPP control panel. MySQL is required for the health check to report `connected`.
2. Put this project where Apache can see it. On macOS that is often a symlink:

   ```bash
   ln -s /Users/.../XAMPP/htdocs/capstone
   ```

   On Windows, copy or link the project into `C:\xampp\htdocs\capstone`.
3. Open:

   - Patient homepage: [http://localhost/capstone/public/](http://localhost/capstone/public/)
   - Booking form: [http://localhost/capstone/public/book.php](http://localhost/capstone/public/book.php)
   - Staff dashboard: [http://localhost/capstone/public/admin/](http://localhost/capstone/public/admin/)
   - Health check: [http://localhost/capstone/public/api/health.php](http://localhost/capstone/public/api/health.php)

Apache is serving the whole `public/` folder, so the URL includes `/public`. A later Apache virtual host can set the document root to `public/` and drop that segment from the URL. Do not point the document root at the repository root: that would expose `src/` and `.env`. `src/.htaccess` blocks direct access if Apache still reaches that folder.

## Run it without changing XAMPP

From the repository root, PHP's built-in server can serve `public/` for a quick check:

```bash
php -S localhost:8000 -t public
```

If `php` is not on your PATH, XAMPP's binary works the same way:

```bash
/Applications/XAMPP/xamppfiles/bin/php -S localhost:8000 -t public
```

Leave that terminal open, then visit:

- [http://localhost:8000/](http://localhost:8000/) patient homepage
- [http://localhost:8000/book.php](http://localhost:8000/book.php) visit request
- [http://localhost:8000/confirmation.php](http://localhost:8000/confirmation.php) receipt, after a valid submit
- [http://localhost:8000/admin/](http://localhost:8000/admin/) staff dashboard
- [http://localhost:8000/admin/patients.php](http://localhost:8000/admin/patients.php)
- [http://localhost:8000/admin/availability.php](http://localhost:8000/admin/availability.php)
- [http://localhost:8000/admin/waitlist.php](http://localhost:8000/admin/waitlist.php)
- [http://localhost:8000/admin/notifications.php](http://localhost:8000/admin/notifications.php)
- [http://localhost:8000/admin/staff.php](http://localhost:8000/admin/staff.php) super admin only
- [http://localhost:8000/api/health.php](http://localhost:8000/api/health.php)

Stop the server with Ctrl+C. Use one server at a time if both try to use the same port.

## Test the health check

With either server running:

```bash
curl -i http://localhost:8000/api/health.php
```

For the XAMPP URL, use `http://localhost/capstone/public/api/health.php` instead.

The endpoint always confirms that PHP is running. It never returns database credentials, SQL errors, or patient rows. `database` is one of:

| Value | Meaning |
| --- | --- |
| `connected` | `.env` is set and PDO reached `clinic_db` with `SELECT 1` |
| `not_configured` | `DB_HOST`, `DB_NAME`, or `DB_USER` is empty |
| `unavailable` | credentials are present but the connection failed (MySQL stopped, wrong name, or wrong user) |

Expected body when MySQL is running and `.env` matches your local `clinic_db`:

```json
{"status":"ok","service":"clinic-appointments","database":"connected"}
```

## Local configuration

```bash
cp .env.example .env
```

`.env.example` already uses the local XAMPP defaults below. Edit `.env` if your MariaDB user or password is different. The variables are:

| Variable | Purpose |
| --- | --- |
| `APP_ENV` | `local` while developing; use something else outside local demo |
| `APP_NAME` | Name shown on the pages |
| `ADMIN_SESSION_LIFETIME` | Staff session lifetime in seconds (default 28800) |
| `ADMIN_SEED_ENABLED` | `true` only for local empty-table demo seeding; keep `false` otherwise |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` / `ADMIN_NAME` / `ADMIN_PHONE` / `ADMIN_ROLE` | Used only when local seeding is explicitly enabled |
| `DB_HOST` | Database host, usually `127.0.0.1` for XAMPP |
| `DB_PORT` | Database port, usually `3306` |
| `DB_NAME` | Database name. Local schema: `clinic_db` |
| `DB_USER` | Database user. XAMPP default: `root` |
| `DB_PASSWORD` | Database password. XAMPP default is often empty |

`.env` is listed in `.gitignore`. Commit `.env.example` only.

### First production super_admin

Do **not** enable `ADMIN_SEED_ENABLED` outside local development. Create the first account with a one-time SQL insert (replace the password hash by generating it locally):

```bash
/Applications/XAMPP/xamppfiles/bin/php -r 'echo password_hash("choose-a-strong-password", PASSWORD_DEFAULT), PHP_EOL;'
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

Apply schema updates on existing databases with:

```bash
/Applications/XAMPP/xamppfiles/bin/mysql -h 127.0.0.1 -u root < database/migrations/001_admin_role.sql
```

### SMS (Twilio)

Outbound texts use `src/sms.php` in **mock** mode by default (`SMS_ENABLED=false`, `SMS_MODE=mock`). Live sending requires Twilio credentials, A2P 10DLC registration, a signed BAA with a HIPAA-eligible account configuration, and clinic-approved consent copy. See `.env.example`. Daily reminders can be scheduled with `scripts/send_appointment_reminders.php`.

## Verify the database connection

1. Copy `.env.example` to `.env` if you have not already.
2. Start **MySQL** in XAMPP. Confirm `clinic_db` exists in phpMyAdmin or MySQL Workbench.
3. From the repository root, ping PDO without exposing a web page:

   ```bash
   /Applications/XAMPP/xamppfiles/bin/php -r 'require "src/db.php"; echo db_health(), PHP_EOL;'
   ```

   On Windows, use `C:\xampp\php\php.exe` instead of the macOS path.
4. Open the health check URL from the sections above, or:

   ```bash
   curl http://localhost:8000/api/health.php
   ```

A printed `connected` (CLI) or `"database":"connected"` (health check) means PHP reached MariaDB. `unavailable` usually means MySQL is stopped or `.env` does not match the local user. `not_configured` means `.env` is missing `DB_HOST`, `DB_NAME`, or `DB_USER`.

This milestone does not create patient or admin API endpoints, send SMS, or deploy the app. The project is not a HIPAA compliance claim.
