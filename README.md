# Clinic Appointment System

Privacy-conscious appointment scheduling for a community health clinic. Patients request visits on a public website. Clinic staff use a separate desk to review the schedule. The screens below are a preview with fictional sample data. Nothing is saved in a database, and no messages are sent.

The browser never talks to MySQL directly. Pages and API scripts in `public/` call PHP in `src/`, and that PHP code will be the only layer that opens a database connection.

## Layout

```
public/                      Web root
  index.php                  Patient homepage
  book.php                   Visit request form
  confirmation.php           Preview receipt after a valid form submit
  admin/index.php            Staff dashboard
  admin/patients.php         Sample patient list
  admin/availability.php     Sample hours and 15-minute slots
  admin/waitlist.php         Sample waitlist
  admin/notifications.php    Draft message text
  api/health.php             JSON health check
  assets/css/                Patient and staff styles
  assets/js/booking.js       Adds family-member fields on the form
src/                         Not meant to be requested by a browser
  bootstrap.php              Loads .env and shared helpers
  preview_data.php           Fictional clinic content and form checks
  patient_layout.php         Patient page header and footer
  admin_layout.php           Staff sidebar layout
  .htaccess                  Denies direct web access to this folder
```

`src/preview_data.php` is sample content for the screens. It is not a database design. The clinic name defaults to Global Health and Education clinic. The sample patients are Sarah Smith, Mark Smith, and George Marcus. Set `APP_NAME` in `.env` to show a different display name.

## Requirements

- PHP 8.0 or newer (included with a current XAMPP install)
- MySQL or MariaDB later, through XAMPP. It is not required to run the health check.
- Git

No Composer packages or frontend framework are used.

## Run it with XAMPP

1. Install [XAMPP](https://www.apachefriends.org/) and start **Apache** from the XAMPP control panel. MySQL can stay stopped until a database is added.
2. Put this project where Apache can see it. On macOS that is often a symlink:

   ```bash
   ln -s /Users/adelinechen/capstone/Capstone /Applications/XAMPP/htdocs/capstone
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
- [http://localhost:8000/api/health.php](http://localhost:8000/api/health.php)

Stop the server with Ctrl+C. Use one server at a time if both try to use the same port.

## Test the health check

With either server running:

```bash
curl -i http://localhost:8000/api/health.php
```

For the XAMPP URL, use `http://localhost/capstone/public/api/health.php` instead.

Expected body:

```json
{"status":"ok","service":"clinic-appointments","database":"not_configured"}
```

`database` stays `not_configured` until `.env` has `DB_HOST`, `DB_NAME`, and `DB_USER`. The endpoint does not open a database connection.

## Test the preview screens

Patient site:

1. Open the homepage. Choose a date and an open 15-minute time, then enter a name and phone number.
2. Choose **Add another person**. Each added person takes the next 15 minutes. If the next block is taken, the form asks you to book the rest as another visit. Submit **Book my visit**.
3. The next page shows the visit and says a text is on the way. Use **Change this visit** to look it up, pick a new time, or cancel.
4. Submit the form with an empty name. The form should stay put and list what to fix. The full time cannot be selected.
5. Choose **Español** in the header. The booking screen should switch to Spanish.

Staff desk:

1. Open the staff desk. The login page asks for a username and password. For this local demo, use **staff** and **clinic-desk**. Change those in `.env` with `ADMIN_USERNAME` and `ADMIN_PASSWORD` before sharing the computer.
2. The sample day shows Booked 6, Confirmed 2, Not confirmed 3, and Cancelled 1. Sarah Smith is at 9:00 and Mark Smith has the next 15 minutes, 9:15. George Marcus and Luis Ortega have no reply. Amina Cole’s reminder was sent. Carlos Ruiz cancelled.
3. In Follow up, the unconfirmed names are checked. Send text marks those reminders as sent on this sample day.
4. On Carlos Ruiz’s row, choose Fill from waitlist and give that 15-minute block to Elena Park or James Okonkwo. The cancelled count drops, and that person leaves the waitlist.
5. Use the sidebar for Patients, Availability, Waitlist, and Messages. The message drafts stay unsent.
6. On Availability, use the calendar to open a day for booking, or close one. On an open day, block a 15-minute time. The patient site only lists days the clinic opened. 10:30 AM starts blocked. **Log out** returns to the login page.
7. Narrow the browser window. The patient page should stack, and the staff navigation should sit above the content.

The health check should still return the JSON body above.

## Local configuration

```bash
cp .env.example .env
```

Edit `.env` when you create the database. The variables are:

| Variable | Purpose |
| --- | --- |
| `APP_ENV` | `local` while developing |
| `APP_NAME` | Name shown on the placeholder pages |
| `ADMIN_USERNAME` | Staff login name. Local demo: `staff` |
| `ADMIN_PASSWORD` | Staff login password. Local demo: `clinic-desk` |
| `DB_HOST` | Database host, usually `127.0.0.1` for XAMPP |
| `DB_PORT` | Database port, usually `3306` |
| `DB_NAME` | Database name, chosen when the schema is designed |
| `DB_USER` | Database user |
| `DB_PASSWORD` | Database password |

`.env` is listed in `.gitignore`. Commit `.env.example` only.
