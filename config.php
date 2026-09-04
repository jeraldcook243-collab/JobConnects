<?php
/**
 * ============================================================
 * JobConnct - Configuration File
 * ============================================================
 * Edit the database credentials below to match your InfinityFree
 * MySQL database settings (found in cPanel).
 * ============================================================
 */

// -------------------- DATABASE SETTINGS --------------------
// LOCAL XAMPP (current):  host=localhost, user=root, pass=(empty)
// To deploy on InfinityFree, replace these with the cPanel values,
// e.g.  host=sqlXXX.infinityfree.com, db=if0_12345678_jobconnct,
//       user=if0_12345678, pass=your_db_password
define('DB_HOST', 'localhost');
define('DB_NAME', 'jobconnct');
define('DB_USER', 'root');
define('DB_PASS', '');

// Charset for database connections (safest choice)
define('DB_CHARSET', 'utf8mb4');

// -------------------- APP SETTINGS --------------------
// Set base URL. For InfinityFree use the domain assigned to the account.
// Example: https://jobconnct.epizy.com
define('BASE_URL', 'http://localhost'); // change to your domain, e.g., 'https://yourdomain.epizy.com'

// Upload directory for resumes (protected - no direct access)
define('UPLOAD_DIR', __DIR__ . '/uploads/');

// Company logo directory (public - served directly by the web server)
define('LOGO_DIR', __DIR__ . '/logos/');

// Max upload file size in bytes (2 MB)
define('MAX_UPLOAD_SIZE', 2 * 1024 * 1024);

// Allowed file extensions for resume upload
define('ALLOWED_EXTENSIONS', ['pdf', 'doc', 'docx']);

// Job expiry in days (used by cleanup function)
define('JOB_EXPIRY_DAYS', 30);

// -------------------- SMTP SETTINGS (forgot password emails) --------------------
// NOTE: InfinityFree free hosting blocks outbound SMTP - this works on XAMPP
// and normal hosts, but on InfinityFree the connection may time out (the app
// shows a friendly error and the reset still works if you run it elsewhere).
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'jeraldcook243@gmail.com');
define('SMTP_PASS', 'htixxwhxhgvdqtal');
define('SMTP_FROM', 'no-reply@jobconnects.dpdns.org');

// -------------------- DO NOT EDIT BELOW --------------------
session_start();
date_default_timezone_set('UTC');

// Set error reporting (turn off in production)
error_reporting(E_ALL);
ini_set('display_errors', '0');

/**
 * Returns a PDO database connection (singleton).
 * Uses PDO prepared statements for security.
 */
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
        }
    }
    return $pdo;
}
