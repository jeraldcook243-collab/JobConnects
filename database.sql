-- ============================================================
-- JobConnct - Database Schema & Seed Data
-- ============================================================
-- HOW TO INSTALL ON INFINITYFREE:
--   1. Create the database in cPanel -> MySQL Databases
--      (e.g. if0_12345678_jobconnct) and remember the DB user/password.
--   2. Open phpMyAdmin, select your database.
--   3. Click the "Import" tab and upload THIS file.
--   4. Open config.php and set DB_HOST, DB_NAME, DB_USER, DB_PASS.
--
-- NOTE: no CREATE DATABASE here on purpose - on InfinityFree your
--       MySQL user only has rights on the database created in cPanel.
-- ============================================================

SET NAMES utf8mb4;

-- -------------------- USERS --------------------
CREATE TABLE IF NOT EXISTS users (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role                ENUM('admin','employer','jobseeker') NOT NULL DEFAULT 'jobseeker',
    name                VARCHAR(100)  NOT NULL,
    email               VARCHAR(150)  NOT NULL,
    password            VARCHAR(255)  NOT NULL,
    reset_token         VARCHAR(64)   DEFAULT NULL,
    reset_token_expires DATETIME      DEFAULT NULL,
    company_name        VARCHAR(150)  DEFAULT NULL,
    company_logo        VARCHAR(255)  DEFAULT NULL,
    company_description TEXT          DEFAULT NULL,
    is_suspended        TINYINT(1)    NOT NULL DEFAULT 0,
    created_at          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------- CATEGORIES --------------------
CREATE TABLE IF NOT EXISTS categories (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(100) NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------- JOBS --------------------
CREATE TABLE IF NOT EXISTS jobs (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    employer_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED DEFAULT NULL,
    title       VARCHAR(200) NOT NULL,
    description TEXT         NOT NULL,
    location    VARCHAR(150) NOT NULL,
    salary      VARCHAR(100) DEFAULT NULL,
    job_type    ENUM('full-time','part-time','contract','internship','remote') NOT NULL DEFAULT 'full-time',
    status      ENUM('pending','approved','rejected','filled','expired') NOT NULL DEFAULT 'pending',
    is_featured TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_jobs_status_created (status, created_at),
    KEY idx_jobs_employer (employer_id),
    KEY idx_jobs_category (category_id),
    CONSTRAINT fk_jobs_employer FOREIGN KEY (employer_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_jobs_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------- APPLICATIONS --------------------
CREATE TABLE IF NOT EXISTS applications (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id        INT UNSIGNED NOT NULL,
    jobseeker_id  INT UNSIGNED NOT NULL,
    resume_file   VARCHAR(255) NOT NULL,
    resume_name   VARCHAR(255) DEFAULT NULL,
    cover_message TEXT         DEFAULT NULL,
    status        ENUM('pending','shortlisted','rejected','hired') NOT NULL DEFAULT 'pending',
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_applications_job_user (job_id, jobseeker_id),
    KEY idx_applications_jobseeker (jobseeker_id),
    CONSTRAINT fk_applications_job        FOREIGN KEY (job_id)       REFERENCES jobs (id) ON DELETE CASCADE,
    CONSTRAINT fk_applications_jobseeker  FOREIGN KEY (jobseeker_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED DATA
-- ============================================================

-- Categories
INSERT INTO categories (name) VALUES
    ('Information Technology'),
    ('Marketing'),
    ('Sales'),
    ('Finance & Accounting'),
    ('Human Resources'),
    ('Healthcare'),
    ('Engineering'),
    ('Education'),
    ('Customer Service'),
    ('Design & Creative');

-- Users
-- Passwords:
--   admin@jobconnct.com     / Admin123!
--   employer@jobconnct.com  / Employer123!
--   jobseeker@jobconnct.com / Jobseeker123!
INSERT INTO users (id, role, name, email, password, company_name, company_description) VALUES
(1, 'admin',     'Site Administrator', 'admin@jobconnct.com',     '$2y$10$WtAHXqCd5MOnDBMfbgm3Ku53VY56jFMzYdSMvRMxKfJpf7w2nq1s6', NULL, NULL),
(2, 'employer',  'Alice Johnson',      'employer@jobconnct.com',  '$2y$10$Q9PAuMTCYBjILAar7xyzQ.bgDTPCmbaQEn86l/.gxP4wQaCoaFInq', 'TechNova Solutions', 'TechNova Solutions is a fast-growing software company building web and mobile products for clients worldwide.'),
(3, 'jobseeker', 'Bob Smith',          'jobseeker@jobconnct.com', '$2y$10$ofGW33rV503a3nHYIFuBZ.lw984Rf1Jy46oCfZUCyLLYRgnJ6I86i', NULL, NULL);

-- Sample jobs (all approved so the site looks alive immediately)
INSERT INTO jobs (employer_id, category_id, title, description, location, salary, job_type, status, is_featured, created_at) VALUES
(2, 1, 'Senior PHP Developer',
 'We are looking for an experienced PHP developer to join our web team.\n\nResponsibilities:\n- Build and maintain web applications in PHP/MySQL\n- Write clean, secure and well-documented code\n- Work closely with front-end developers and designers\n\nRequirements:\n- 3+ years of PHP experience\n- Solid knowledge of PDO, MySQL and HTML/CSS\n- Experience with version control (Git)\n\nWe offer a competitive salary, remote-friendly work and a great team.',
 'London, UK', '$60,000 - $80,000', 'full-time', 'approved', 1, NOW() - INTERVAL 2 DAY),

(2, 7, 'Mechanical Engineer',
 'Join our engineering department and help design the next generation of industrial equipment.\n\nResponsibilities include CAD modelling, prototyping and working with the production team.\n\nRequirements: degree in Mechanical Engineering, 2+ years experience, strong problem-solving skills.',
 'Manchester, UK', '$45,000 - $55,000', 'full-time', 'approved', 1, NOW() - INTERVAL 5 DAY),

(2, 6, 'Registered Nurse',
 'Our clinic is hiring a registered nurse to provide excellent patient care.\n\nShift work available, flexible hours, supportive environment. Nursing degree and valid registration required.',
 'Birmingham, UK', NULL, 'full-time', 'approved', 0, NOW() - INTERVAL 8 DAY),

(2, 2, 'Digital Marketing Specialist',
 'We need a creative marketer to run our online campaigns.\n\nYou will manage social media, SEO and email campaigns. Experience with analytics tools is a plus.',
 'London, UK', '$35,000 - $45,000', 'full-time', 'approved', 0, NOW() - INTERVAL 12 DAY),

(2, 1, 'Frontend Developer (Internship)',
 'Great opportunity for students or recent graduates to learn on the job. You will assist our frontend team with HTML, CSS and JavaScript tasks.\n\nNo experience required - passion for web development is a must.',
 'Remote', NULL, 'internship', 'approved', 0, NOW() - INTERVAL 15 DAY),

(2, 4, 'Accountant',
 'We are looking for a detail-oriented accountant to manage day-to-day bookkeeping, payroll and financial reporting.\n\nCPA or equivalent qualification preferred.',
 'Leeds, UK', '$40,000 - $50,000', 'full-time', 'approved', 0, NOW() - INTERVAL 20 DAY),

-- One job that is 40 days old - the admin "Cleanup" button will delete it
(2, 3, 'Old Sales Role (Cleanup Demo)',
 'This job is older than 30 days and will be removed by the admin cleanup button.',
 'Sheffield, UK', NULL, 'full-time', 'approved', 0, NOW() - INTERVAL 40 DAY);
