# JobConnct — Job Agency Website

A complete job agency website built with **plain PHP** (no Composer, no frameworks) that runs on **InfinityFree** free hosting — and locally on **XAMPP**.

- **Front controller + router:** `index.php?page=...`
- **Database:** MySQL (PDO prepared statements everywhere)
- **Frontend:** Bootstrap 5.3 (CDN) + Bootstrap Icons
- **PHP requirement:** 7.4+ (tested on 8.2)

---

## Table of Contents

1. [Features](#features)
2. [Requirements](#requirements)
3. [Local Setup (XAMPP)](#local-setup-xampp)
4. [InfinityFree Deployment](#infinityfree-deployment)
5. [Complete Route Map (all pages)](#complete-route-map-all-pages)
   - [Public pages](#public-pages)
   - [Authenticated pages](#authenticated-pages)
   - [Employer pages (backend)](#employer-pages-backend)
   - [Job Seeker pages (backend)](#job-seeker-pages-backend)
   - [Admin panel pages](#admin-panel-pages)
   - [POST actions (form handlers)](#post-actions-form-handlers)
6. [Seed Accounts](#seed-accounts)
7. [Project Structure](#project-structure)
8. [Security](#security)
9. [No-Cron Workaround](#no-cron-workaround)
10. [Limitations & Notes](#limitations--notes)

---

## Features

| Area | Capabilities |
|---|---|
| **Auth** | 3 roles (admin / employer / jobseeker), `password_hash()`, sessions, **auto-login after registration** (no email verification) |
| **Employer** | Post / edit / delete jobs, view applications per job, update application status (shortlist / reject / hire), company profile (name, description, logo) |
| **Job Seeker** | Search & filter jobs (keyword, category, location, type), apply with resume upload (PDF/DOC/DOCX, max 2 MB), track application status |
| **Admin** | Manage users (suspend / reactivate / delete), manage categories (add / edit / delete), approve / reject jobs, mark jobs filled / expired, feature jobs on the homepage, manual "cleanup old jobs" button (replaces cron) |
| **Public** | Homepage with featured jobs, paginated job listing, single job detail page, registration / login, responsive Bootstrap 5 |

---

## Requirements

- PHP **7.4+** (PDO MySQL, `pdo_mysql` extension enabled)
- MySQL / MariaDB (InnoDB)
- Apache with `mod_rewrite` optional (the app uses query-string routing, `.htaccess` only adds security rules)
- No Composer, no cron, no email sending — all work happens on-demand via HTTP

---

## Local Setup (XAMPP)

1. Copy the project folder into `C:\xampp\htdocs\` (so the app lives at `C:\xampp\htdocs\JobConnect`).
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Create the database and import the schema:
   ```bash
   # from a terminal (adjust the mysql path if needed)
   C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE jobconnct CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   C:\xampp\mysql\bin\mysql.exe -u root jobconnct < database.sql
   ```
   (or do it in phpMyAdmin: create DB `jobconnct`, then *Import* → `database.sql`)
4. **config.php** already points at `localhost` / `root` / (empty password). If your MySQL root has a password, edit these lines:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'jobconnct');
   define('DB_USER', 'root');
   define('DB_PASS', '');          // <-- your MySQL password here
   ```
5. Open **http://localhost/JobConnect/** in your browser.

---

## InfinityFree Deployment

1. **Create the database** — cPanel → *MySQL Databases* → create a database (e.g. `if0_12345678_jobconnct`) and its user. Copy **host** (e.g. `sqlXXX.infinityfree.com`), **name**, **user**, **password**.
2. **Import the SQL** — cPanel → *phpMyAdmin* → select your database → *Import* → upload `database.sql` → *Go*. (The file deliberately has **no** `CREATE DATABASE` — on InfinityFree your user only has rights on the DB created in cPanel.)
3. **Upload files** — cPanel → *File Manager* → `htdocs` → upload the project contents. **Delete the `tests/` folder before uploading** (it is a local-only dev script, blocked by its own `.htaccess` anyway).
4. **Edit `config.php`** — set the 4 database constants to your cPanel values:
   ```php
   define('DB_HOST', 'sqlXXX.infinityfree.com');
   define('DB_NAME', 'if0_12345678_jobconnct');
   define('DB_USER', 'if0_12345678');
   define('DB_PASS', 'your_db_password');
   ```
5. **Permissions** — folders `755`, files `644`. `uploads/` and `logos/` must be writable by PHP (`755` is fine on InfinityFree; they are also auto-created by the app if missing).
6. **SMTP (forgot-password emails)** — the values are in `config.php` under `SMTP_*`. ⚠️ **InfinityFree free hosting blocks outbound SMTP**, so password-reset emails will *not* be delivered from InfinityFree itself; the feature works on XAMPP and normal hosts. If you host elsewhere, set:
   ```php
   define('SMTP_HOST', 'smtp.gmail.com');
   define('SMTP_PORT', 587);
   define('SMTP_USER', 'your_gmail@gmail.com');   // Gmail app password, not your login password
   define('SMTP_PASS', 'your_16_char_app_password');
   define('SMTP_FROM', 'no-reply@yourdomain.com');
   ```
7. **Go live** — visit your domain. Log in as the admin, then **change the seed passwords** (see below) before announcing the site.

> **Upgrade note (existing installations):** if your database was created before the forgot-password feature, run this once in phpMyAdmin:
> ```sql
> ALTER TABLE users
>   ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL AFTER password,
>   ADD COLUMN reset_token_expires DATETIME DEFAULT NULL AFTER reset_token;
> ```
> Fresh installs get these columns automatically from `database.sql`.

---

## Complete Route Map (all pages)

The app is a single front controller: **`index.php`**, routed by the `page` parameter.
Base URL (local): `http://localhost/JobConnect/index.php` — or simply `http://localhost/JobConnect/?page=...`

### Public pages

| Route | Description |
|---|---|
| `?page=home` | Homepage: hero search, **featured jobs**, recent jobs |
| `?page=jobs` | Job listing with pagination. Optional filters: `&q=keyword`, `&cat=ID`, `&loc=city`, `&type=full-time\|part-time\|contract\|internship\|remote`, `&p=page#` |
| `?page=job&id=X` | Single job detail page (only visible if approved, or to the owner/admin) |
| `?page=register` | Create account (choose **Job Seeker** or **Employer**) — auto-login after submit |
| `?page=login` | Log in |
| `?page=forgot-password` | Request a password-reset link by email (works for **every** role, including admin) |
| `?page=reset-password&token=...` | Set a new password using the emailed link (valid 30 minutes) |
| `?page=logout` | Log out (destroys session) |
| *(anything else)* | 404 page |

### Authenticated pages

| Route | Description |
|---|---|
| `?page=dashboard` | Role-based landing: redirects/logic for employer, jobseeker or admin dashboards |
| `?page=download&app=X` | **Secure resume download.** Serves the resume file of application `X` only to: the applicant, the employer who owns the job, or an admin. Direct access to `/uploads/` is blocked by `.htaccess`. |

### Employer pages (backend)

*Requires an employer account.*

| Route | Description |
|---|---|
| `?page=post-job` | Form to post a new job (goes to **pending** for admin approval) |
| `?page=edit-job&id=X` | Edit one of your jobs (returns to **pending** for re-approval) |
| `?page=applications&id=X` | View all applications for one of your jobs, download resumes, change statuses |
| `?page=company-profile` | Edit company name, description and upload a logo |

### Job Seeker pages (backend)

*Requires a jobseeker account.*

| Route | Description |
|---|---|
| `?page=apply&id=X` | Apply to job `X` — upload resume (PDF/DOC/DOCX, ≤ 2 MB) + optional cover message |
| `?page=my-applications` | List all your applications with their current status (pending / shortlisted / rejected / hired) |

### Admin panel pages

*Requires an admin account.*

| Route | Description |
|---|---|
| `?page=admin-users` | Manage all users — suspend, reactivate or delete (you cannot modify yourself) |
| `?page=admin-categories` | Add, edit, delete job categories. Editing via `?page=admin-categories&edit=ID` |
| `?page=admin-jobs` | Moderate all jobs. Status filter via `?page=admin-jobs&status=pending\|approved\|rejected\|filled\|expired` |
| `?page=cleanup` | **POST only.** Manual "cleanup old jobs" — deletes jobs older than 30 days plus their resume files (see [No-Cron Workaround](#no-cron-workaround)) |

### POST actions (form handlers)

All forms must include a CSRF token; every POST without a valid token is rejected (redirect + error flash).

| URL | Fields | Effect |
|---|---|---|
| `?page=register` | `name, email, password, role(jobseeker\|employer), company_name(if employer)` | Create account + auto-login |
| `?page=login` | `email, password` | Start session |
| `?page=post-job` | `title, category_id, location, job_type, salary, description` | Create job (status: pending) |
| `?page=edit-job&id=X` | same as post-job | Update job (status: pending) |
| `?page=delete-job` | `job_id` | Delete job + its applications + resume files |
| `?page=update-application` | `application_id, status(pending\|shortlisted\|rejected\|hired)` | Update application status |
| `?page=apply&id=X` | `resume (file), cover_message` | Submit application (unique per job+user) |
| `?page=company-profile` | `company_name, company_description, company_logo (file)` | Update company profile |
| `?page=admin-users` | `user_id, action(suspend\|unsuspend\|delete)` | User management |
| `?page=admin-categories` | `action(add\|edit\|delete), id(edit/delete), name(add/edit)` | Category management |
| `?page=admin-jobs` | `job_id, action(approve\|reject\|filled\|expired\|feature\|unfeature)` | Job moderation |
| `?page=cleanup` | *(none)* | Delete jobs older than 30 days |

---

## Seed Accounts

| Role | Email | Password |
|---|---|---|
| Admin | `admin@jobconnct.com` | `Admin123!` |
| Employer | `employer@jobconnct.com` | `Employer123!` |
| Job Seeker | `jobseeker@jobconnct.com` | `Jobseeker123!` |

> ⚠️ **Change these passwords (or delete the seed users) after your first login — both locally and in production.**

The database also ships with 10 job categories and 7 sample jobs (one job is deliberately dated 40 days old so you can test the admin **Cleanup** button immediately).

---

## Project Structure

```
JobConnect/
├── index.php               # Front controller + router (all requests)
├── config.php              # DB credentials, constants, getDB() PDO singleton
├── functions.php           # Helpers: e(), CSRF, flash, auth, uploads
├── database.sql            # Schema + seed data (import in phpMyAdmin)
├── .htaccess               # Blocks config.php/functions.php, disables listing
├── includes/
│   ├── header.php          # Navbar + flash messages
│   ├── footer.php
│   └── .htaccess           # Deny direct access
├── views/                  # One file per page (see route map)
│   ├── home.php  jobs.php  job-details.php  register.php  login.php  404.php
│   ├── dashboard-employer.php  job-form.php  applications.php  company-profile.php
│   ├── dashboard-jobseeker.php apply.php  my-applications.php
│   └── dashboard-admin.php admin-users.php admin-categories.php admin-jobs.php
├── uploads/                # Resumes (denied by .htaccess, served via ?page=download)
│   └── .htaccess           # Deny ALL direct access
├── logos/                  # Company logos (public, script execution blocked)
│   └── .htaccess
└── tests/                  # Local-only smoke test (delete before deploying)
    ├── smoke-test.sh
    └── .htaccess
```

---

## Security

- **PDO prepared statements** for every query — no string-built SQL.
- **`htmlspecialchars()`** on all output via the `e()` helper (and `nl2br()` for descriptions).
- **CSRF tokens** on every POST form (`csrf_field()` + `verify_csrf()`).
- **Upload validation** — extension whitelist, `finfo` MIME check (when available), 2 MB size limit, random file names.
- **Role guards** — `require_role('admin'|'employer'|'jobseeker')` on every protected page.
- **Suspended users** are blocked at login *and* their session is killed on the next request.
- **`.htaccess` rules** deny direct access to `includes/`, `uploads/`, `config.php`, `functions.php`; `logos/` blocks script execution.
- **Resumes are never publicly reachable** — only served through the permission-checked download route.
- Session-based flash messages; passwords stored with `password_hash()` / checked with `password_verify()`.

---

## No-Cron Workaround

InfinityFree does not allow cron jobs, so old-job cleanup is **manual and on-demand**:

1. Log in as **admin**.
2. Admin Dashboard → *Database Cleanup* card → **Run Cleanup Now**.
3. The POST to `?page=cleanup` deletes every job whose `created_at` is older than **`JOB_EXPIRY_DAYS` (30)** in `config.php`, along with their applications and stored resume files, then reports how many were removed.

No email is sent anywhere in the app (per hosting constraints); notifications happen via on-site flash messages.

---

## Limitations & Notes

- **`BASE_URL`** in `config.php` is informational — the app uses relative URLs, so it works in subfolders and on any domain without changes.
- Resumes are stored on disk; the DB only keeps the file name + the original name for download display.
- Bootstrap and Bootstrap Icons are loaded from **jsDelivr CDN** (requires internet on the client side, which is fine for visitors).
- The `tests/smoke-test.sh` script is for local development only (it calls local MySQL directly) — it is blocked from the web by `.htaccess` and should be deleted before uploading to production.
