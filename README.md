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
  admin/staff.php            Super admin accounts and invites
  admin/invite.php           Invitee creates their password
  admin/patients.php         Sample patient list
  admin/availability.php     Clinic calendar and 15-minute holds
  admin/waitlist.php         Sample waitlist
  admin/notifications.php    Editable patient texts
  api/health.php             JSON health check (PHP up, optional PDO ping)
  assets/css/                Patient and staff styles
  assets/js/booking.js       Adds family-member fields on the form
src/                         Not meant to be requested by a browser
  bootstrap.php              Loads .env and shared helpers
  db.php                     PDO connection to clinic_db
  preview_data.php           Fictional clinic content and form checks
  patient_layout.php         Patient page header and footer
  admin_layout.php           Staff sidebar layout
  .htaccess                  Denies direct web access to this folder
database/                    SQL schema used in MySQL Workbench
  databaseDDL.sql            Tables, keys, and constraints for clinic_db
```

`src/preview_data.php` is sample content for the screens. It is not a database design. The clinic name defaults to Global Health and Education clinic. The sample patients are Sarah Smith, Mark Smith, and George Marcus. Set `APP_NAME` in `.env` to show a different display name.

## Requirements

- PHP 8.0 or newer with the `pdo_mysql` extension (included with a current XAMPP install)
- MySQL or MariaDB through XAMPP, with the `clinic_db` schema already created
- Git

No Composer packages or frontend framework are used.

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

## Test the preview screens

Patient site:

1. Open the homepage. Choose **First Visit** or **Follow-up visit**. Use the calendar to pick an open day, then a 15-minute time. Enter a name and phone number.
2. Submit **Book my visit**. The next page shows the visit and says a text is on the way. Scroll to **Check your visit** on the homepage, or tap that link in the header, to look it up, pick a new time, or cancel.
3. Submit the form with an empty name. The form should stay put and list what to fix. A blocked time cannot be selected.
4. Choose **Español** in the header. The booking screen should switch to Spanish.

Staff desk:

1. Open the staff desk. Super admin is **staff** / **clinic-desk**. A regular admin is **desk** / **clinic-desk**. Only a super admin sees **Staff**, where they can email an invite, create an account, change roles, or delete accounts.
2. The sample day shows Booked 6, Confirmed 2, Not confirmed 3, and Cancelled 1. Sarah Smith is at 9:00 and Mark Smith has the next 15 minutes, 9:15. George Marcus and Luis Ortega have no reply. Amina Cole’s reminder was sent. Carlos Ruiz cancelled.
3. In Follow up, the unconfirmed names are checked. Send text marks those reminders as sent on this sample day.
4. On Carlos Ruiz’s row, choose Fill from waitlist and give that 15-minute block to Elena Park or James Okonkwo. The cancelled count drops, and that person leaves the waitlist.
5. Use the sidebar for Patients, Availability, Waitlist, and Messages. On Messages, edit a text and save. The Follow-up box on Appointments uses that last template.
6. On Availability, use the calendar to open a day for booking, or close one. On an open day, block a 15-minute time. The patient site only lists days the clinic opened. 10:30 AM starts blocked. **Log out** returns to the login page.
7. Narrow the browser window. The patient page should stack, and the staff navigation should sit above the content.

The health check should still return JSON. After `.env` is filled in and MySQL is running, `database` should be `connected`.

## Local configuration

```bash
cp .env.example .env
```

`.env.example` already uses the local XAMPP defaults below. Edit `.env` if your MariaDB user or password is different. The variables are:

| Variable | Purpose |
| --- | --- |
| `APP_ENV` | `local` while developing |
| `APP_NAME` | Name shown on the placeholder pages |
| `ADMIN_USERNAME` | Staff login name. Local demo: `staff` |
| `ADMIN_PASSWORD` | Staff login password. Local demo: `clinic-desk` |
| `DB_HOST` | Database host, usually `127.0.0.1` for XAMPP |
| `DB_PORT` | Database port, usually `3306` |
| `DB_NAME` | Database name. Local schema: `clinic_db` |
| `DB_USER` | Database user. XAMPP default: `root` |
| `DB_PASSWORD` | Database password. XAMPP default is often empty |

`.env` is listed in `.gitignore`. Commit `.env.example` only.

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
