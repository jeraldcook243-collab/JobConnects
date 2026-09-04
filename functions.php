<?php
/**
 * ============================================================
 * JobConnct - Helper Functions
 * ============================================================
 * All helper functions used by the front controller and views.
 * Plain PHP only - no Composer dependencies.
 * ============================================================
 */

// -------------------- OUTPUT HELPERS --------------------

/**
 * Escape output with htmlspecialchars (always escape ALL output).
 */
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Safe UTF-8 string length (falls back to byte length if mbstring is off).
 */
function str_len($string) {
    return function_exists('mb_strlen') ? mb_strlen($string, 'UTF-8') : strlen($string);
}

/**
 * Safe UTF-8 substring (falls back to substr if mbstring is off).
 */
function str_sub($string, $start, $length = null) {
    if (function_exists('mb_substr')) {
        return $length === null ? mb_substr($string, $start, null, 'UTF-8') : mb_substr($string, $start, $length, 'UTF-8');
    }
    return $length === null ? substr($string, $start) : substr($string, $start, $length);
}

/**
 * Redirect to an internal (relative) URL and stop execution.
 */
function redirect($path) {
    header('Location: ' . $path);
    exit;
}

/**
 * Short plain-text excerpt of job descriptions.
 */
function excerpt($text, $len = 140) {
    $text = trim(strip_tags($text));
    if (str_len($text) <= $len) {
        return $text;
    }
    return rtrim(str_sub($text, 0, $len)) . '…';
}

/**
 * Human friendly "posted x ago" text.
 */
function time_ago($datetime) {
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 60)       return 'just now';
    if ($diff < 3600)     return floor($diff / 60) . ' min ago';
    if ($diff < 86400)    return floor($diff / 3600) . ' hr ago';
    if ($diff < 2592000)  return floor($diff / 86400) . ' day' . (floor($diff / 86400) > 1 ? 's' : '') . ' ago';
    return date('M j, Y', $ts);
}

/**
 * Nicely formatted job type label ("full-time" -> "Full Time").
 */
function job_type_label($type) {
    return ucwords(str_replace('-', ' ', $type));
}

/**
 * Badge styling class for job status.
 */
function job_status_badge($status) {
    $map = [
        'pending'  => 'bg-warning text-dark',
        'approved' => 'bg-success',
        'rejected' => 'bg-danger',
        'filled'   => 'bg-secondary',
        'expired'  => 'bg-dark',
    ];
    return $map[$status] ?? 'bg-secondary';
}

/**
 * Badge styling class for application status.
 */
function application_status_badge($status) {
    $map = [
        'pending'     => 'bg-warning text-dark',
        'shortlisted' => 'bg-info text-dark',
        'rejected'    => 'bg-danger',
        'hired'       => 'bg-success',
    ];
    return $map[$status] ?? 'bg-secondary';
}

// -------------------- CSRF PROTECTION --------------------

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Hidden input field - include inside every <form method="post">.
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Verify the CSRF token on a POST request.
 * Redirects back to $fallback (or the referring page) on failure.
 */
function verify_csrf($fallback = '?page=home') {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        set_flash('danger', 'Invalid security token. Please try again.');
        redirect($fallback);
    }
}

// -------------------- FLASH MESSAGES --------------------

function set_flash($type, $message) {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flash() {
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

// -------------------- AUTHENTICATION --------------------

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

/**
 * Returns the logged-in user row (fresh from DB) or null.
 * Destroys the session if the account no longer exists or was suspended.
 */
function current_user() {
    static $user = false;
    if ($user === false && is_logged_in()) {
        $stmt = getDB()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user || (int)$user['is_suspended'] === 1) {
            unset($_SESSION['user_id']);
            $user = null;
        }
    }
    return $user ?: null;
}

function require_login() {
    if (!is_logged_in() || !current_user()) {
        set_flash('danger', 'Please log in to continue.');
        redirect('?page=login');
    }
}

function require_role($role) {
    require_login();
    if (current_user()['role'] !== $role) {
        set_flash('danger', 'You do not have permission to access that page.');
        redirect('?page=home');
    }
}

// -------------------- DATA HELPERS --------------------

function get_categories() {
    $stmt = getDB()->query('SELECT * FROM categories ORDER BY name');
    return $stmt->fetchAll();
}

function category_exists($id) {
    $stmt = getDB()->prepare('SELECT id FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    return (bool)$stmt->fetch();
}

// -------------------- FILE UPLOADS --------------------

/**
 * Validate + store an uploaded resume (PDF/DOC/DOCX, max 2 MB).
 * Returns ['ok' => true, 'file' => stored_name] or ['error' => message].
 */
function handle_resume_upload($file) {
    if (!isset($file) || !is_array($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['error' => 'Please choose a resume file (PDF, DOC or DOCX, max 2 MB).'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'File upload failed (error code ' . (int)$file['error'] . '). Please try again.'];
    }
    if ((int)$file['size'] > MAX_UPLOAD_SIZE) {
        return ['error' => 'File is too large. Maximum size is 2 MB.'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
        return ['error' => 'Invalid file type. Only PDF, DOC and DOCX files are allowed.'];
    }

    // Validate real file content (when available on the host).
    if (function_exists('finfo_open')) {
        $finfo   = finfo_open(FILEINFO_MIME_TYPE);
        $mime    = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowed = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        if (!in_array($mime, $allowed, true)) {
            return ['error' => 'Invalid file content. Only PDF, DOC and DOCX documents are allowed.'];
        }
    }

    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0755, true);
    }
    $storedName = 'resume_' . date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $storedName)) {
        return ['error' => 'Could not save the uploaded file. Please try again.'];
    }
    return ['ok' => true, 'file' => $storedName];
}

/**
 * Validate + store an uploaded company logo (image, max 2 MB).
 * Logos are stored in /logos/ (publicly served) - NOT in the protected uploads dir.
 */
function handle_logo_upload($file) {
    if (!isset($file) || !is_array($file)) {
        return ['error' => 'Logo upload failed. Please try again.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'Logo upload failed (error code ' . (int)$file['error'] . '). Please try again.'];
    }
    if ((int)$file['size'] > MAX_UPLOAD_SIZE) {
        return ['error' => 'Logo image is too large. Maximum size is 2 MB.'];
    }

    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        return ['error' => 'Invalid image type. Use JPG, PNG, GIF or WEBP.'];
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (strpos($mime, 'image/') !== 0) {
            return ['error' => 'The uploaded file is not a valid image.'];
        }
    }

    if (!is_dir(LOGO_DIR)) {
        @mkdir(LOGO_DIR, 0755, true);
    }
    $storedName = 'logo_' . date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], LOGO_DIR . $storedName)) {
        return ['error' => 'Could not save the logo image. Please try again.'];
    }
    return ['ok' => true, 'file' => $storedName];
}

/**
 * Safely delete a stored file (only inside the given protected directory).
 */
function delete_stored_file($dir, $filename) {
    $path = $dir . basename($filename);
    if (is_file($path)) {
        @unlink($path);
        return true;
    }
    return false;
}

// -------------------- EMAIL (plain-PHP SMTP, no libraries) --------------------

/**
 * Build an absolute URL to this app from the current request.
 * Works locally (http://localhost/JobConnect/...) and in production
 * (https://yourdomain/...), including subfolder installs.
 */
function app_url($path = '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $base . '/' . ltrim($path, '/');
}

/**
 * Send an HTML email using a minimal SMTP client (STARTTLS + AUTH LOGIN).
 * Returns true on success, false on any failure.
 */
function send_email($to, $subject, $htmlBody) {
    if (empty(SMTP_HOST)) {
        return false;
    }
    // Header-injection protection: strip line breaks from inputs.
    $to      = preg_replace('/[\r\n]+/', '', trim($to));
    $subject = preg_replace('/[\r\n]+/', '', trim($subject));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client('tcp://' . SMTP_HOST . ':' . SMTP_PORT, $errno, $errstr, 5);
    if (!$socket) {
        return false;
    }
    // Never let any SMTP read/write block the page for more than 5 seconds.
    stream_set_timeout($socket, 5);

    $expect = function ($codes) use ($socket) {
        $line = '';
        while (!feof($socket)) {
            $line .= fgets($socket, 515);
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $meta = stream_get_meta_data($socket);
        return !$meta['timed_out'] && in_array((int)substr($line, 0, 3), (array)$codes, true);
    };

    $ehlo = 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\r\n";

    if (!$expect(220)) { fclose($socket); return false; }          // greeting
    fwrite($socket, $ehlo);
    if (!$expect(250)) { fclose($socket); return false; }          // EHLO
    fwrite($socket, "STARTTLS\r\n");
    if (!$expect(220)) { fclose($socket); return false; }          // STARTTLS
    stream_set_timeout($socket, 5);                                 // TLS handshake is also time-bounded
    if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        fclose($socket);
        return false;
    }
    stream_set_timeout($socket, 5);
    fwrite($socket, $ehlo);
    if (!$expect(250)) { fclose($socket); return false; }          // EHLO (secure)
    fwrite($socket, "AUTH LOGIN\r\n");
    if (!$expect(334)) { fclose($socket); return false; }
    fwrite($socket, base64_encode(SMTP_USER) . "\r\n");
    if (!$expect(334)) { fclose($socket); return false; }
    fwrite($socket, base64_encode(SMTP_PASS) . "\r\n");
    if (!$expect(235)) { fclose($socket); return false; }          // authenticated

    fwrite($socket, 'MAIL FROM:<' . SMTP_FROM . ">\r\n");
    if (!$expect(250)) { fclose($socket); return false; }
    fwrite($socket, 'RCPT TO:<' . $to . ">\r\n");
    if (!$expect(250, 251)) { fclose($socket); return false; }
    fwrite($socket, "DATA\r\n");
    if (!$expect(354)) { fclose($socket); return false; }

    $headers = "From: JobConnct <" . SMTP_FROM . ">\r\n"
             . "To: <" . $to . ">\r\n"
             . "Subject: " . $subject . "\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: 8bit\r\n"
             . "Date: " . date('r') . "\r\n";

    fwrite($socket, $headers . "\r\n" . $htmlBody . "\r\n.\r\n");
    if (!$expect(250)) { fclose($socket); return false; }          // queued
    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return true;
}
