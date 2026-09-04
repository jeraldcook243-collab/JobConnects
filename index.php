<?php
/**
 * ============================================================
 * JobConnct - Front Controller (simple router)
 * ============================================================
 * All requests go through this file. Routing is done with the
 * "page" query parameter: ?page=home, ?page=job&id=X, ...
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$page        = $_GET['page'] ?? 'home';
$currentUser = is_logged_in() ? current_user() : null;

/**
 * Render the 404 page and stop.
 */
function not_found() {
    http_response_code(404);
    include __DIR__ . '/views/404.php';
    exit;
}

switch ($page) {

    /* =====================================================
     * PUBLIC - HOME
     * ===================================================== */
    case 'home':
        $stmt = getDB()->query(
            "SELECT j.*, u.company_name, c.name AS category_name
               FROM jobs j
               JOIN users u       ON u.id = j.employer_id
               LEFT JOIN categories c ON c.id = j.category_id
              WHERE j.status = 'approved'
              ORDER BY j.is_featured DESC, j.created_at DESC
              LIMIT 9"
        );
        $featuredJobs = $stmt->fetchAll();
        $pageTitle    = 'JobConnct - Find Your Next Job';
        $viewFile     = __DIR__ . '/views/home.php';
        break;

    /* =====================================================
     * PUBLIC - JOB LISTING (search, filters, pagination)
     * ===================================================== */
    case 'jobs':
        $q        = trim($_GET['q'] ?? '');
        $cat      = (int)($_GET['cat'] ?? 0);
        $loc      = trim($_GET['loc'] ?? '');
        $type     = $_GET['type'] ?? '';
        $p        = max(1, (int)($_GET['p'] ?? 1));
        $perPage  = 6;

        $where  = ["j.status = 'approved'"];
        $params = [];

        if ($q !== '') {
            $where[] = '(j.title LIKE ? OR j.description LIKE ? OR j.location LIKE ? OR u.company_name LIKE ?)';
            $like    = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($cat > 0) {
            $where[] = 'j.category_id = ?';
            $params[] = $cat;
        }
        if ($loc !== '') {
            $where[] = 'j.location LIKE ?';
            $params[] = '%' . $loc . '%';
        }
        $allowedTypes = ['full-time', 'part-time', 'contract', 'internship', 'remote'];
        if (in_array($type, $allowedTypes, true)) {
            $where[] = 'j.job_type = ?';
            $params[] = $type;
        }
        $whereSql = implode(' AND ', $where);

        $stmt = getDB()->prepare(
            "SELECT COUNT(*) FROM jobs j JOIN users u ON u.id = j.employer_id WHERE $whereSql"
        );
        $stmt->execute($params);
        $total      = (int)$stmt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $perPage));
        $p          = min($p, $totalPages);
        $offset     = ($p - 1) * $perPage;

        $stmt = getDB()->prepare(
            "SELECT j.*, u.company_name, c.name AS category_name
               FROM jobs j
               JOIN users u       ON u.id = j.employer_id
               LEFT JOIN categories c ON c.id = j.category_id
              WHERE $whereSql
              ORDER BY j.created_at DESC
              LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);
        $jobs      = $stmt->fetchAll();
        $categories = get_categories();
        $pageTitle = 'Browse Jobs';
        $viewFile  = __DIR__ . '/views/jobs.php';
        break;

    /* =====================================================
     * PUBLIC - JOB DETAILS
     * ===================================================== */
    case 'job':
        $jobId = (int)($_GET['id'] ?? 0);
        $stmt  = getDB()->prepare(
            "SELECT j.*, u.company_name, u.company_logo, u.company_description,
                    c.name AS category_name
               FROM jobs j
               JOIN users u       ON u.id = j.employer_id
               LEFT JOIN categories c ON c.id = j.category_id
              WHERE j.id = ?"
        );
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();
        if (!$job) {
            not_found();
        }

        $canView = $job['status'] === 'approved'
            || ($currentUser && ($currentUser['role'] === 'admin' || (int)$currentUser['id'] === (int)$job['employer_id']));
        if (!$canView) {
            not_found();
        }

        $application    = null;
        $alreadyApplied = false;
        if ($currentUser && $currentUser['role'] === 'jobseeker') {
            $stmt = getDB()->prepare('SELECT * FROM applications WHERE job_id = ? AND jobseeker_id = ?');
            $stmt->execute([$job['id'], $currentUser['id']]);
            $application    = $stmt->fetch();
            $alreadyApplied = (bool)$application;
        }

        $stmt = getDB()->prepare('SELECT COUNT(*) FROM applications WHERE job_id = ?');
        $stmt->execute([$job['id']]);
        $applicantCount = (int)$stmt->fetchColumn();

        $pageTitle = $job['title'] . ' - JobConnct';
        $viewFile  = __DIR__ . '/views/job-details.php';
        break;

    /* =====================================================
     * AUTH - REGISTER (auto-login, no email verification)
     * ===================================================== */
    case 'register':
        if ($currentUser) {
            redirect('?page=dashboard');
        }
        $errors = [];
        $old    = ['name' => '', 'email' => '', 'role' => 'jobseeker', 'company_name' => ''];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf('?page=register');
            $old['name']          = trim($_POST['name'] ?? '');
            $old['email']         = trim($_POST['email'] ?? '');
            $old['role']          = $_POST['role'] ?? 'jobseeker';
            $old['company_name']  = trim($_POST['company_name'] ?? '');
            $password             = $_POST['password'] ?? '';

            if ($old['name'] === '' || str_len($old['name']) > 100) {
                $errors[] = 'Name is required (max 100 characters).';
            }
            if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || str_len($old['email']) > 150) {
                $errors[] = 'A valid email address is required.';
            }
            if (strlen($password) < 6) {
                $errors[] = 'Password must be at least 6 characters.';
            }
            if (!in_array($old['role'], ['employer', 'jobseeker'], true)) {
                $old['role'] = 'jobseeker';
            }
            if ($old['role'] === 'employer' && $old['company_name'] === '') {
                $errors[] = 'Company name is required for employer accounts.';
            }

            if (!$errors) {
                $stmt = getDB()->prepare('SELECT id FROM users WHERE email = ?');
                $stmt->execute([$old['email']]);
                if ($stmt->fetch()) {
                    $errors[] = 'An account with this email already exists.';
                } else {
                    $stmt = getDB()->prepare(
                        'INSERT INTO users (role, name, email, password, company_name) VALUES (?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $old['role'],
                        $old['name'],
                        $old['email'],
                        password_hash($password, PASSWORD_DEFAULT),
                        $old['company_name'] !== '' ? $old['company_name'] : null,
                    ]);
                    $_SESSION['user_id'] = (int)getDB()->lastInsertId();
                    set_flash('success', 'Welcome to JobConnct! Your account has been created.');
                    redirect('?page=dashboard');
                }
            }
            if ($errors) {
                set_flash('danger', implode(' ', $errors));
            }
        }
        $pageTitle = 'Create an Account';
        $viewFile  = __DIR__ . '/views/register.php';
        break;

    /* =====================================================
     * AUTH - LOGIN
     * ===================================================== */
    case 'login':
        if ($currentUser) {
            redirect('?page=dashboard');
        }
        $errors  = [];
        $oldEmail = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf('?page=login');
            $email    = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $oldEmail = $email;
            if ($email === '' || $password === '') {
                $errors[] = 'Email and password are required.';
            } else {
                $stmt = getDB()->prepare('SELECT * FROM users WHERE email = ?');
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                if ($user && password_verify($password, $user['password'])) {
                    if ((int)$user['is_suspended'] === 1) {
                        $errors[] = 'This account has been suspended. Please contact the administrator.';
                    } else {
                        $_SESSION['user_id'] = (int)$user['id'];
                        set_flash('success', 'Welcome back, ' . $user['name'] . '!');
                        redirect('?page=dashboard');
                    }
                } else {
                    $errors[] = 'Invalid email or password.';
                }
            }
            if ($errors) {
                set_flash('danger', implode(' ', $errors));
            }
        }
        $pageTitle = 'Log In';
        $viewFile  = __DIR__ . '/views/login.php';
        break;

    /* =====================================================
     * AUTH - FORGOT PASSWORD (works for every role, incl. admin)
     * ===================================================== */
    case 'forgot-password':
        if ($currentUser) {
            redirect('?page=dashboard');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf('?page=forgot-password');
            $email = trim($_POST['email'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || str_len($email) > 150) {
                set_flash('danger', 'Please enter a valid email address.');
            } else {
                $stmt = getDB()->prepare('SELECT id, email FROM users WHERE email = ?');
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                // Always show the same message (prevents account enumeration).
                $sent = true;
                if ($user) {
                    $token = bin2hex(random_bytes(32));
                    // Expiry uses MySQL's own clock so the later
                    // "reset_token_expires > NOW()" check stays consistent.
                    $stmt = getDB()->prepare(
                        'UPDATE users SET reset_token = ?, reset_token_expires = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = ?'
                    );
                    $stmt->execute([$token, $user['id']]);

                    $link = app_url('index.php?page=reset-password&token=' . $token);
                    $body = '<p>Hello,</p>'
                          . '<p>We received a request to reset the password for your <strong>JobConnct</strong> account.</p>'
                          . '<p>Click the link below to choose a new password. This link is valid for <strong>30 minutes</strong>:</p>'
                          . '<p><a href="' . $link . '">' . $link . '</a></p>'
                          . '<p>If you did not request this, you can safely ignore this email.</p>'
                          . '<p>&mdash; JobConnct</p>';
                    $sent = send_email($user['email'], 'JobConnct - Password Reset', $body);
                }
                if ($sent) {
                    set_flash('success', 'If an account exists for that email, a reset link has been sent.');
                } else {
                    set_flash('danger', 'The reset email could not be sent right now (outbound SMTP may be blocked on this host). Please try again later or contact the administrator.');
                }
            }
        }
        $pageTitle = 'Forgot Password';
        $viewFile  = __DIR__ . '/views/forgot-password.php';
        break;

    /* =====================================================
     * AUTH - RESET PASSWORD (token from email link)
     * ===================================================== */
    case 'reset-password':
        if ($currentUser) {
            redirect('?page=dashboard');
        }
        $token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));

        $stmt = getDB()->prepare(
            'SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > NOW()'
        );
        $stmt->execute([$token]);
        $resetUser = $stmt->fetch();

        if (!$resetUser) {
            set_flash('danger', 'This reset link is invalid or has expired. Please request a new one.');
            redirect('?page=forgot-password');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf('?page=forgot-password');
            $password = $_POST['password'] ?? '';
            $confirm  = $_POST['password_confirm'] ?? '';
            $errors   = [];
            if (strlen($password) < 6) {
                $errors[] = 'Password must be at least 6 characters.';
            }
            if ($password !== $confirm) {
                $errors[] = 'Passwords do not match.';
            }
            if (!$errors) {
                $stmt = getDB()->prepare(
                    'UPDATE users SET password = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?'
                );
                $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $resetUser['id']]);
                set_flash('success', 'Your password has been updated. You can now log in.');
                redirect('?page=login');
            }
            set_flash('danger', implode(' ', $errors));
        }
        $pageTitle = 'Reset Password';
        $viewFile  = __DIR__ . '/views/reset-password.php';
        break;

    /* =====================================================
     * AUTH - LOGOUT
     * ===================================================== */
    case 'logout':
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        redirect('?page=home');
        break;

    /* =====================================================
     * ROLE-BASED DASHBOARD
     * ===================================================== */
    case 'dashboard':
        require_login();
        $user = current_user();

        if ($user['role'] === 'employer') {
            $stmt = getDB()->prepare('SELECT COUNT(*) FROM jobs WHERE employer_id = ?');
            $stmt->execute([$user['id']]);
            $stats['jobs'] = (int)$stmt->fetchColumn();

            $stmt = getDB()->prepare(
                'SELECT COUNT(*) FROM applications a JOIN jobs j ON j.id = a.job_id WHERE j.employer_id = ?'
            );
            $stmt->execute([$user['id']]);
            $stats['applications'] = (int)$stmt->fetchColumn();

            $stmt = getDB()->prepare(
                "SELECT COUNT(*) FROM applications a JOIN jobs j ON j.id = a.job_id
                  WHERE j.employer_id = ? AND a.status = 'pending'"
            );
            $stmt->execute([$user['id']]);
            $stats['pending_apps'] = (int)$stmt->fetchColumn();

            $stmt = getDB()->prepare(
                "SELECT j.*, c.name AS category_name,
                        (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id) AS app_count
                   FROM jobs j
                   LEFT JOIN categories c ON c.id = j.category_id
                  WHERE j.employer_id = ?
                  ORDER BY j.created_at DESC"
            );
            $stmt->execute([$user['id']]);
            $jobs = $stmt->fetchAll();

            $viewFile = __DIR__ . '/views/dashboard-employer.php';
        } elseif ($user['role'] === 'jobseeker') {
            $stmt = getDB()->prepare('SELECT COUNT(*) FROM applications WHERE jobseeker_id = ?');
            $stmt->execute([$user['id']]);
            $stats['applications'] = (int)$stmt->fetchColumn();

            $stmt = getDB()->prepare(
                "SELECT COUNT(*) FROM applications WHERE jobseeker_id = ? AND status = 'hired'"
            );
            $stmt->execute([$user['id']]);
            $stats['hired'] = (int)$stmt->fetchColumn();

            $stmt = getDB()->prepare(
                "SELECT a.*, j.title AS job_title, j.status AS job_status, u.company_name
                   FROM applications a
                   JOIN jobs j ON j.id = a.job_id
                   JOIN users u ON u.id = j.employer_id
                  WHERE a.jobseeker_id = ?
                  ORDER BY a.created_at DESC
                  LIMIT 5"
            );
            $stmt->execute([$user['id']]);
            $applications = $stmt->fetchAll();

            $viewFile = __DIR__ . '/views/dashboard-jobseeker.php';
        } else {
            $stats = [];
            $labels = ['users', 'employers', 'jobseekers', 'jobs', 'jobs_pending', 'applications'];
            $queries = [
                'users'         => 'SELECT COUNT(*) FROM users',
                'employers'     => "SELECT COUNT(*) FROM users WHERE role = 'employer'",
                'jobseekers'    => "SELECT COUNT(*) FROM users WHERE role = 'jobseeker'",
                'jobs'          => 'SELECT COUNT(*) FROM jobs',
                'jobs_pending'  => "SELECT COUNT(*) FROM jobs WHERE status = 'pending'",
                'applications'  => 'SELECT COUNT(*) FROM applications',
            ];
            foreach ($labels as $label) {
                $stats[$label] = (int)getDB()->query($queries[$label])->fetchColumn();
            }

            $stmt = getDB()->query(
                "SELECT j.*, u.company_name FROM jobs j
                   JOIN users u ON u.id = j.employer_id
                  WHERE j.status = 'pending'
                  ORDER BY j.created_at DESC LIMIT 5"
            );
            $pendingJobs = $stmt->fetchAll();

            $viewFile = __DIR__ . '/views/dashboard-admin.php';
        }
        $pageTitle = 'Dashboard';
        break;

    /* =====================================================
     * EMPLOYER - POST A JOB
     * ===================================================== */
    case 'post-job':
        require_role('employer');
        $user       = current_user();
        $categories = get_categories();
        $errors     = [];
        $job        = ['title' => '', 'category_id' => 0, 'location' => '', 'job_type' => 'full-time', 'salary' => '', 'description' => ''];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf('?page=post-job');
            $job['title']       = trim($_POST['title'] ?? '');
            $job['category_id'] = (int)($_POST['category_id'] ?? 0);
            $job['location']    = trim($_POST['location'] ?? '');
            $job['job_type']    = $_POST['job_type'] ?? 'full-time';
            $job['salary']      = trim($_POST['salary'] ?? '');
            $job['description'] = trim($_POST['description'] ?? '');

            if ($job['title'] === '' || str_len($job['title']) > 200) {
                $errors[] = 'Job title is required (max 200 characters).';
            }
            if (!category_exists($job['category_id'])) {
                $errors[] = 'Please select a valid category.';
            }
            if ($job['location'] === '' || str_len($job['location']) > 150) {
                $errors[] = 'Location is required (max 150 characters).';
            }
            $allowedTypes = ['full-time', 'part-time', 'contract', 'internship', 'remote'];
            if (!in_array($job['job_type'], $allowedTypes, true)) {
                $errors[] = 'Invalid job type selected.';
            }
            if (str_len($job['salary']) > 100) {
                $errors[] = 'Salary field is too long (max 100 characters).';
            }
            if ($job['description'] === '' || str_len($job['description']) > 20000) {
                $errors[] = 'Job description is required (max 20000 characters).';
            }

            if (!$errors) {
                $stmt = getDB()->prepare(
                    "INSERT INTO jobs (employer_id, category_id, title, description, location, salary, job_type, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
                );
                $stmt->execute([
                    $user['id'],
                    $job['category_id'],
                    $job['title'],
                    $job['description'],
                    $job['location'],
                    $job['salary'] !== '' ? $job['salary'] : null,
                    $job['job_type'],
                ]);
                set_flash('success', 'Job posted! It will appear on the site once an admin approves it.');
                redirect('?page=dashboard');
            }
            if ($errors) {
                set_flash('danger', implode(' ', $errors));
            }
        }
        $pageTitle = 'Post a Job';
        $viewFile  = __DIR__ . '/views/job-form.php';
        break;

    /* =====================================================
     * EMPLOYER - EDIT A JOB
     * ===================================================== */
    case 'edit-job':
        require_role('employer');
        $user       = current_user();
        $jobId      = (int)($_GET['id'] ?? 0);
        $categories = get_categories();
        $errors     = [];

        $stmt = getDB()->prepare('SELECT * FROM jobs WHERE id = ? AND employer_id = ?');
        $stmt->execute([$jobId, $user['id']]);
        $job = $stmt->fetch();
        if (!$job) {
            not_found();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf('?page=edit-job&id=' . $jobId);
            $job['title']       = trim($_POST['title'] ?? '');
            $job['category_id'] = (int)($_POST['category_id'] ?? 0);
            $job['location']    = trim($_POST['location'] ?? '');
            $job['job_type']    = $_POST['job_type'] ?? 'full-time';
            $job['salary']      = trim($_POST['salary'] ?? '');
            $job['description'] = trim($_POST['description'] ?? '');

            if ($job['title'] === '' || str_len($job['title']) > 200) {
                $errors[] = 'Job title is required (max 200 characters).';
            }
            if (!category_exists($job['category_id'])) {
                $errors[] = 'Please select a valid category.';
            }
            if ($job['location'] === '' || str_len($job['location']) > 150) {
                $errors[] = 'Location is required (max 150 characters).';
            }
            $allowedTypes = ['full-time', 'part-time', 'contract', 'internship', 'remote'];
            if (!in_array($job['job_type'], $allowedTypes, true)) {
                $errors[] = 'Invalid job type selected.';
            }
            if (str_len($job['salary']) > 100) {
                $errors[] = 'Salary field is too long (max 100 characters).';
            }
            if ($job['description'] === '' || str_len($job['description']) > 20000) {
                $errors[] = 'Job description is required (max 20000 characters).';
            }

            if (!$errors) {
                // Edited jobs go back to "pending" for a quick admin re-approval.
                $stmt = getDB()->prepare(
                    "UPDATE jobs
                        SET title = ?, category_id = ?, description = ?, location = ?,
                            salary = ?, job_type = ?, status = 'pending'
                      WHERE id = ? AND employer_id = ?"
                );
                $stmt->execute([
                    $job['title'],
                    $job['category_id'],
                    $job['description'],
                    $job['location'],
                    $job['salary'] !== '' ? $job['salary'] : null,
                    $job['job_type'],
                    $jobId,
                    $user['id'],
                ]);
                set_flash('success', 'Job updated. It will be re-approved by an admin shortly.');
                redirect('?page=dashboard');
            }
            if ($errors) {
                set_flash('danger', implode(' ', $errors));
            }
        }
        $editing  = true;
        $pageTitle = 'Edit Job';
        $viewFile = __DIR__ . '/views/job-form.php';
        break;

    /* =====================================================
     * EMPLOYER - DELETE A JOB
     * ===================================================== */
    case 'delete-job':
        require_role('employer');
        $user = current_user();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('?page=dashboard');
        }
        verify_csrf('?page=dashboard');
        $jobId = (int)($_POST['job_id'] ?? 0);

        $stmt = getDB()->prepare('SELECT * FROM jobs WHERE id = ? AND employer_id = ?');
        $stmt->execute([$jobId, $user['id']]);
        if (!$stmt->fetch()) {
            set_flash('danger', 'Job not found.');
            redirect('?page=dashboard');
        }

        // Remove stored resume files of the applications that cascade-delete.
        $fileStmt = getDB()->prepare('SELECT resume_file FROM applications WHERE job_id = ?');
        $fileStmt->execute([$jobId]);
        while ($f = $fileStmt->fetch()) {
            delete_stored_file(UPLOAD_DIR, $f['resume_file']);
        }

        $stmt = getDB()->prepare('DELETE FROM jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        set_flash('success', 'Job and its applications were deleted.');
        redirect('?page=dashboard');
        break;

    /* =====================================================
     * EMPLOYER - VIEW APPLICATIONS FOR A JOB
     * ===================================================== */
    case 'applications':
        require_role('employer');
        $user  = current_user();
        $jobId = (int)($_GET['id'] ?? 0);

        $stmt = getDB()->prepare('SELECT * FROM jobs WHERE id = ? AND employer_id = ?');
        $stmt->execute([$jobId, $user['id']]);
        $job = $stmt->fetch();
        if (!$job) {
            not_found();
        }

        $stmt = getDB()->prepare(
            'SELECT a.*, u.name, u.email
               FROM applications a
               JOIN users u ON u.id = a.jobseeker_id
              WHERE a.job_id = ?
              ORDER BY a.created_at DESC'
        );
        $stmt->execute([$jobId]);
        $applications = $stmt->fetchAll();

        $pageTitle = 'Applications - ' . $job['title'];
        $viewFile  = __DIR__ . '/views/applications.php';
        break;

    /* =====================================================
     * EMPLOYER - UPDATE APPLICATION STATUS
     * ===================================================== */
    case 'update-application':
        require_role('employer');
        $user = current_user();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('?page=dashboard');
        }
        verify_csrf('?page=dashboard');

        $appId    = (int)($_POST['application_id'] ?? 0);
        $status   = $_POST['status'] ?? '';
        $allowed  = ['pending', 'shortlisted', 'rejected', 'hired'];
        if (!in_array($status, $allowed, true)) {
            set_flash('danger', 'Invalid application status.');
            redirect('?page=dashboard');
        }

        $stmt = getDB()->prepare(
            'SELECT a.id, a.job_id FROM applications a JOIN jobs j ON j.id = a.job_id
              WHERE a.id = ? AND j.employer_id = ?'
        );
        $stmt->execute([$appId, $user['id']]);
        $app = $stmt->fetch();
        if (!$app) {
            set_flash('danger', 'Application not found.');
            redirect('?page=dashboard');
        }

        $stmt = getDB()->prepare('UPDATE applications SET status = ? WHERE id = ?');
        $stmt->execute([$status, $appId]);
        set_flash('success', 'Application status updated.');
        redirect('?page=applications&id=' . (int)$app['job_id']);
        break;

    /* =====================================================
     * JOBSEEKER - APPLY (with resume upload)
     * ===================================================== */
    case 'apply':
        require_role('jobseeker');
        $user  = current_user();
        $jobId = (int)($_GET['id'] ?? 0);

        $stmt = getDB()->prepare(
            "SELECT j.*, u.company_name
               FROM jobs j JOIN users u ON u.id = j.employer_id
              WHERE j.id = ? AND j.status = 'approved'"
        );
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();
        if (!$job) {
            not_found();
        }

        $stmt = getDB()->prepare('SELECT id FROM applications WHERE job_id = ? AND jobseeker_id = ?');
        $stmt->execute([$jobId, $user['id']]);
        if ($stmt->fetch()) {
            set_flash('warning', 'You have already applied for this job.');
            redirect('?page=my-applications');
        }

        $errors = [];
        $cover  = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf('?page=apply&id=' . $jobId);
            $cover = trim($_POST['cover_message'] ?? '');
            if (str_len($cover) > 2000) {
                $errors[] = 'Cover message is too long (max 2000 characters).';
            }
            $upload = handle_resume_upload($_FILES['resume'] ?? []);
            if (!empty($upload['error'])) {
                $errors[] = $upload['error'];
            }

            if (!$errors) {
                $stmt = getDB()->prepare(
                    'INSERT INTO applications (job_id, jobseeker_id, resume_file, resume_name, cover_message)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $jobId,
                    $user['id'],
                    $upload['file'],
                    basename($_FILES['resume']['name']),
                    $cover !== '' ? $cover : null,
                ]);
                set_flash('success', 'Your application has been submitted! You can track its status from your dashboard.');
                redirect('?page=my-applications');
            }
            if ($errors) {
                set_flash('danger', implode(' ', $errors));
            }
        }
        $pageTitle = 'Apply for ' . $job['title'];
        $viewFile  = __DIR__ . '/views/apply.php';
        break;

    /* =====================================================
     * JOBSEEKER - MY APPLICATIONS
     * ===================================================== */
    case 'my-applications':
        require_role('jobseeker');
        $user = current_user();

        $stmt = getDB()->prepare(
            "SELECT a.*, j.title AS job_title, j.status AS job_status, u.company_name
               FROM applications a
               JOIN jobs j ON j.id = a.job_id
               JOIN users u ON u.id = j.employer_id
              WHERE a.jobseeker_id = ?
              ORDER BY a.created_at DESC"
        );
        $stmt->execute([$user['id']]);
        $applications = $stmt->fetchAll();

        $pageTitle = 'My Applications';
        $viewFile  = __DIR__ . '/views/my-applications.php';
        break;

    /* =====================================================
     * EMPLOYER - COMPANY PROFILE
     * ===================================================== */
    case 'company-profile':
        require_role('employer');
        $user   = current_user();
        $errors = [];
        $old    = [
            'company_name'        => $user['company_name'] ?? '',
            'company_description' => $user['company_description'] ?? '',
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf('?page=company-profile');
            $old['company_name']        = trim($_POST['company_name'] ?? '');
            $old['company_description'] = trim($_POST['company_description'] ?? '');

            if ($old['company_name'] === '' || str_len($old['company_name']) > 150) {
                $errors[] = 'Company name is required (max 150 characters).';
            }
            if (str_len($old['company_description']) > 5000) {
                $errors[] = 'Company description is too long (max 5000 characters).';
            }

            $newLogo = null;
            if (!empty($_FILES['company_logo']['name'])) {
                $upload = handle_logo_upload($_FILES['company_logo']);
                if (!empty($upload['error'])) {
                    $errors[] = $upload['error'];
                } else {
                    $newLogo = $upload['file'];
                }
            }

            if (!$errors) {
                if ($newLogo !== null) {
                    if (!empty($user['company_logo'])) {
                        delete_stored_file(LOGO_DIR, $user['company_logo']);
                    }
                    $stmt = getDB()->prepare(
                        'UPDATE users SET company_name = ?, company_description = ?, company_logo = ? WHERE id = ?'
                    );
                    $stmt->execute([$old['company_name'], $old['company_description'], $newLogo, $user['id']]);
                } else {
                    $stmt = getDB()->prepare(
                        'UPDATE users SET company_name = ?, company_description = ? WHERE id = ?'
                    );
                    $stmt->execute([$old['company_name'], $old['company_description'], $user['id']]);
                }
                set_flash('success', 'Company profile updated.');
                redirect('?page=company-profile');
            }
            if ($errors) {
                set_flash('danger', implode(' ', $errors));
            }
        }
        $pageTitle = 'Company Profile';
        $viewFile  = __DIR__ . '/views/company-profile.php';
        break;

    /* =====================================================
     * ADMIN - MANAGE USERS
     * ===================================================== */
    case 'admin-users':
        require_role('admin');
        $admin = current_user();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf('?page=admin-users');
            $target = (int)($_POST['user_id'] ?? 0);
            $action = $_POST['action'] ?? '';
            if ($target === (int)$admin['id']) {
                set_flash('danger', 'You cannot modify your own account.');
                redirect('?page=admin-users');
            }
            $stmt = getDB()->prepare('SELECT id FROM users WHERE id = ?');
            $stmt->execute([$target]);
            if (!$stmt->fetch()) {
                set_flash('danger', 'User not found.');
                redirect('?page=admin-users');
            }
            if ($action === 'suspend') {
                getDB()->prepare('UPDATE users SET is_suspended = 1 WHERE id = ?')->execute([$target]);
                set_flash('success', 'User suspended.');
            } elseif ($action === 'unsuspend') {
                getDB()->prepare('UPDATE users SET is_suspended = 0 WHERE id = ?')->execute([$target]);
                set_flash('success', 'User reactivated.');
            } elseif ($action === 'delete') {
                getDB()->prepare('DELETE FROM users WHERE id = ?')->execute([$target]);
                set_flash('success', 'User deleted (their jobs and applications were removed too).');
            } else {
                set_flash('danger', 'Unknown action.');
            }
            redirect('?page=admin-users');
        }

        $users = getDB()->query(
            "SELECT u.*,
                    (SELECT COUNT(*) FROM jobs         WHERE employer_id   = u.id) AS job_count,
                    (SELECT COUNT(*) FROM applications WHERE jobseeker_id  = u.id) AS app_count
               FROM users u
              ORDER BY u.created_at DESC"
        )->fetchAll();

        $pageTitle = 'Manage Users';
        $viewFile  = __DIR__ . '/views/admin-users.php';
        break;

    /* =====================================================
     * ADMIN - MANAGE CATEGORIES
     * ===================================================== */
    case 'admin-categories':
        require_role('admin');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf('?page=admin-categories');
            $action = $_POST['action'] ?? '';
            $name   = trim($_POST['name'] ?? '');
            if (in_array($action, ['add', 'edit'], true) && ($name === '' || str_len($name) > 100)) {
                set_flash('danger', 'Category name is required (max 100 characters).');
                redirect('?page=admin-categories');
            }

            if ($action === 'add') {
                $stmt = getDB()->prepare('SELECT id FROM categories WHERE name = ?');
                $stmt->execute([$name]);
                if ($stmt->fetch()) {
                    set_flash('danger', 'That category already exists.');
                } else {
                    getDB()->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$name]);
                    set_flash('success', 'Category added.');
                }
            } elseif ($action === 'edit') {
                $id   = (int)($_POST['id'] ?? 0);
                $stmt = getDB()->prepare('SELECT id FROM categories WHERE name = ? AND id <> ?');
                $stmt->execute([$name, $id]);
                if ($stmt->fetch()) {
                    set_flash('danger', 'That category name is already used.');
                } else {
                    getDB()->prepare('UPDATE categories SET name = ? WHERE id = ?')->execute([$name, $id]);
                    set_flash('success', 'Category updated.');
                }
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                getDB()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
                set_flash('success', 'Category deleted (existing jobs keep working without a category).');
            } else {
                set_flash('danger', 'Unknown action.');
            }
            redirect('?page=admin-categories');
        }

        $editCategory = null;
        if (isset($_GET['edit'])) {
            $stmt = getDB()->prepare('SELECT * FROM categories WHERE id = ?');
            $stmt->execute([(int)$_GET['edit']]);
            $editCategory = $stmt->fetch();
        }

        $categories = getDB()->query(
            "SELECT c.*, (SELECT COUNT(*) FROM jobs WHERE category_id = c.id) AS job_count
               FROM categories c ORDER BY c.name"
        )->fetchAll();

        $pageTitle = 'Manage Categories';
        $viewFile  = __DIR__ . '/views/admin-categories.php';
        break;

    /* =====================================================
     * ADMIN - MANAGE JOBS (approve / reject / filled / expired / featured)
     * ===================================================== */
    case 'admin-jobs':
        require_role('admin');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf('?page=admin-jobs');
            $jobId  = (int)($_POST['job_id'] ?? 0);
            $action = $_POST['action'] ?? '';
            $allowedActions = ['approve', 'reject', 'filled', 'expired', 'feature', 'unfeature'];
            if (in_array($action, $allowedActions, true)) {
                $stmt = getDB()->prepare('SELECT id FROM jobs WHERE id = ?');
                $stmt->execute([$jobId]);
                if (!$stmt->fetch()) {
                    set_flash('danger', 'Job not found.');
                    redirect('?page=admin-jobs');
                }
                $updates = [
                    'approve'   => "UPDATE jobs SET status = 'approved'  WHERE id = ?",
                    'reject'    => "UPDATE jobs SET status = 'rejected'  WHERE id = ?",
                    'filled'    => "UPDATE jobs SET status = 'filled'    WHERE id = ?",
                    'expired'   => "UPDATE jobs SET status = 'expired'   WHERE id = ?",
                    'feature'   => 'UPDATE jobs SET is_featured = 1 WHERE id = ?',
                    'unfeature' => 'UPDATE jobs SET is_featured = 0 WHERE id = ?',
                ];
                getDB()->prepare($updates[$action])->execute([$jobId]);
                set_flash('success', 'Job updated.');
            } else {
                set_flash('danger', 'Unknown action.');
            }
            redirect('?page=admin-jobs');
        }

        $statusFilter = $_GET['status'] ?? '';
        $where  = [];
        $params = [];
        $allowedStatuses = ['pending', 'approved', 'rejected', 'filled', 'expired'];
        if (in_array($statusFilter, $allowedStatuses, true)) {
            $where[] = 'j.status = ?';
            $params[] = $statusFilter;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = getDB()->prepare(
            "SELECT j.*, u.company_name, c.name AS category_name
               FROM jobs j
               JOIN users u       ON u.id = j.employer_id
               LEFT JOIN categories c ON c.id = j.category_id
              $whereSql
              ORDER BY j.created_at DESC"
        );
        $stmt->execute($params);
        $jobs = $stmt->fetchAll();

        $pageTitle = 'Manage Jobs';
        $viewFile  = __DIR__ . '/views/admin-jobs.php';
        break;

    /* =====================================================
     * ADMIN - MANUAL CLEANUP (replaces cron: deletes jobs older than 30 days)
     * ===================================================== */
    case 'cleanup':
        require_role('admin');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('?page=admin-jobs');
        }
        verify_csrf('?page=admin-jobs');

        $cutoff = date('Y-m-d H:i:s', time() - JOB_EXPIRY_DAYS * 86400);

        $stmt = getDB()->prepare('SELECT id FROM jobs WHERE created_at < ?');
        $stmt->execute([$cutoff]);
        $jobIds = [];
        while ($row = $stmt->fetch()) {
            $jobIds[] = (int)$row['id'];
        }

        $deletedFiles = 0;
        if ($jobIds) {
            $in        = implode(',', array_fill(0, count($jobIds), '?'));
            $fileStmt  = getDB()->prepare("SELECT resume_file FROM applications WHERE job_id IN ($in)");
            $fileStmt->execute($jobIds);
            while ($f = $fileStmt->fetch()) {
                if (delete_stored_file(UPLOAD_DIR, $f['resume_file'])) {
                    $deletedFiles++;
                }
            }
            getDB()->prepare("DELETE FROM jobs WHERE id IN ($in)")->execute($jobIds);
        }

        set_flash(
            'success',
            'Cleanup complete: ' . count($jobIds) . ' job(s) older than ' . JOB_EXPIRY_DAYS .
            ' days were deleted (including ' . $deletedFiles . ' resume file(s)).'
        );
        redirect('?page=admin-jobs');
        break;

    /* =====================================================
     * SECURE RESUME DOWNLOAD (uploads dir is blocked by .htaccess)
     * ===================================================== */
    case 'download':
        require_login();
        $user  = current_user();
        $appId = (int)($_GET['app'] ?? 0);

        $stmt = getDB()->prepare(
            'SELECT a.*, j.employer_id FROM applications a JOIN jobs j ON j.id = a.job_id WHERE a.id = ?'
        );
        $stmt->execute([$appId]);
        $app = $stmt->fetch();
        if (!$app) {
            not_found();
        }

        $allowed = $user['role'] === 'admin'
            || (int)$user['id'] === (int)$app['jobseeker_id']
            || (int)$user['id'] === (int)$app['employer_id'];
        if (!$allowed) {
            set_flash('danger', 'You are not allowed to download this file.');
            redirect('?page=home');
        }

        $path = UPLOAD_DIR . basename($app['resume_file']);
        if (!is_file($path)) {
            set_flash('danger', 'Resume file not found.');
            redirect('?page=home');
        }

        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = $ext === 'pdf' ? 'application/pdf'
              : ($ext === 'doc' ? 'application/msword'
              : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $dlName = str_replace('"', '', basename($app['resume_name'] ?? 'resume.' . $ext));
        if ($dlName === '' || $dlName === '.') {
            $dlName = 'resume.' . $ext;
        }

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $dlName . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;

    /* =====================================================
     * 404
     * ===================================================== */
    default:
        not_found();
}

/* -------------------- LAYOUT -------------------- */
include __DIR__ . '/includes/header.php';
include $viewFile;
include __DIR__ . '/includes/footer.php';
