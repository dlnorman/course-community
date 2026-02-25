<?php
/**
 * Course Community — Standalone Auth
 *
 * Handles enrollment via invite codes and passwordless magic-link login.
 * Routed from index.php and (under Apache) directly via .htaccess.
 *
 * Routes:
 *   GET  /join                 — enroll form (email + invite code)
 *   POST /join                 — process enrollment
 *   GET  /login                — magic-link request form
 *   POST /login                — send magic link
 *   GET  /auth/magic?token=x   — redeem magic link
 *   GET  /auth/course-picker   — choose course (multi-course users)
 *   POST /auth/select-course   — create session for chosen course
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// When included by another file (e.g. admin.php) skip routing — just load functions.
if (!defined('AUTH_FUNCTIONS_ONLY')) {

// ── Early request log — confirms auth.php was reached ────────────────────────
// Writes to data/auth.log; falls back to sys_get_temp_dir() if data/ isn't writable.
(function () {
    $line = date('Y-m-d H:i:s') . ' REQUEST '
          . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' '
          . ($_SERVER['REQUEST_URI']    ?? '?') . PHP_EOL;
    $primary  = __DIR__ . '/data/auth.log';
    $fallback = sys_get_temp_dir() . '/cc-auth.log';
    if (@file_put_contents($primary, $line, FILE_APPEND | LOCK_EX) === false) {
        @file_put_contents($fallback, $line, FILE_APPEND | LOCK_EX);
        // If neither works, surface it in the system error log
        error_log('[auth] Could not write to ' . $primary . ' or ' . $fallback
                  . ' — check directory permissions. Request: ' . trim($line));
    }
})();

// Determine the path relative to APP_URL
$_authUri = $_SERVER['REQUEST_URI'] ?? '/';
$_authUri = parse_url($_authUri, PHP_URL_PATH);
$_authUri = rtrim($_authUri, '/') ?: '/';
$_authBasePath = rtrim(parse_url(APP_URL, PHP_URL_PATH) ?? '', '/');
if ($_authBasePath && str_starts_with($_authUri, $_authBasePath)) {
    $_authUri = substr($_authUri, strlen($_authBasePath)) ?: '/';
}

$_authMethod = $_SERVER['REQUEST_METHOD'];

// ── Route dispatch ─────────────────────────────────────────────────────────────
// Supports both clean URLs (via mod_rewrite through index.php) and direct
// ?action= parameters (when accessing auth.php directly without mod_rewrite).
//
//   Clean URL          ↔   Direct URL
//   /join              ↔   auth.php?action=join
//   /login             ↔   auth.php?action=login
//   /auth/magic        ↔   auth.php?action=magic
//   /auth/course-picker↔   auth.php?action=picker
//   /auth/select-course↔   auth.php?action=select (POST)

$_authAction = $_GET['action'] ?? '';

if ($_authUri === '/join'               || $_authAction === 'join')   { $_route = 'join';     }
elseif ($_authUri === '/login'          || $_authAction === 'login')  { $_route = 'login';    }
elseif ($_authUri === '/auth/magic'     || $_authAction === 'magic')  { $_route = 'magic';    }
elseif ($_authUri === '/auth/course-picker' || $_authAction === 'picker') { $_route = 'picker'; }
elseif ($_authUri === '/auth/select-course' || $_authAction === 'select') { $_route = 'select'; }
else                                                                  { $_route = 'notfound'; }

match ($_route) {
    'join'     => $_authMethod === 'POST' ? authHandleJoin()         : authShowJoin(),
    'login'    => $_authMethod === 'POST' ? authHandleLogin()        : authShowLogin(),
    'magic'    => authHandleMagic(),
    'picker'   => authShowCoursePicker(),
    'select'   => authHandleSelectCourse(),
    default    => authPage('Not Found', '<p class="auth-error">Page not found.</p>'),
};

} // end if (!defined('AUTH_FUNCTIONS_ONLY'))

// ── Page wrapper ──────────────────────────────────────────────────────────────

function authPage(string $title, string $bodyHtml): never {
    $appName = APP_NAME;
    $appUrl  = APP_URL;
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} — {$appName}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..700;1,9..144,300..500&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
        --bg:       #F2F0EA;
        --surface:  #FFFFFF;
        --sidebar:  #1C2035;
        --border:   #E3DFD5;
        --text:     #1A1D2E;
        --muted:    #5E6278;
        --accent:   #C84B10;
        --accent-t: #FDF1EC;
        --error-t:  #FDECEA;
        --error:    #C0392B;
        --success-t:#EDF7F2;
        --success:  #2E7D52;
        --r:        10px;
        --font-body: 'Plus Jakarta Sans', system-ui, sans-serif;
        --font-disp: 'Fraunces', Georgia, serif;
    }
    body {
        font-family: var(--font-body);
        background: var(--bg);
        color: var(--text);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px 16px;
    }
    .auth-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--r);
        padding: 40px 40px 36px;
        width: 100%;
        max-width: 420px;
        box-shadow: 0 4px 24px rgba(28,32,53,0.08);
    }
    .auth-logo {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 28px;
        color: var(--sidebar);
        font-size: 13px;
        font-weight: 600;
        letter-spacing: 0.01em;
    }
    .auth-logo .mark {
        font-size: 20px;
        color: var(--accent);
    }
    .auth-title {
        font-family: var(--font-disp);
        font-size: 22px;
        font-weight: 600;
        color: var(--text);
        margin-bottom: 6px;
        line-height: 1.2;
    }
    .auth-subtitle {
        font-size: 13px;
        color: var(--muted);
        margin-bottom: 28px;
        line-height: 1.5;
    }
    .auth-field { margin-bottom: 18px; }
    .auth-field label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: 0.07em;
        margin-bottom: 6px;
    }
    .auth-field input {
        width: 100%;
        padding: 10px 14px;
        border: 1.5px solid var(--border);
        border-radius: 7px;
        font-size: 15px;
        font-family: var(--font-body);
        color: var(--text);
        background: #FAFAF8;
        outline: none;
        transition: border-color 0.15s;
    }
    .auth-field input:focus { border-color: var(--accent); background: #fff; }
    .auth-btn {
        width: 100%;
        padding: 12px;
        background: var(--accent);
        color: #fff;
        border: none;
        border-radius: 7px;
        font-size: 15px;
        font-weight: 600;
        font-family: var(--font-body);
        cursor: pointer;
        margin-top: 4px;
        transition: opacity 0.15s;
    }
    .auth-btn:hover { opacity: 0.88; }
    .auth-error {
        background: var(--error-t);
        border: 1px solid rgba(192,57,43,0.25);
        color: var(--error);
        padding: 10px 14px;
        border-radius: 7px;
        font-size: 13px;
        margin-bottom: 18px;
        line-height: 1.5;
    }
    .auth-success {
        background: var(--success-t);
        border: 1px solid rgba(46,125,82,0.25);
        color: var(--success);
        padding: 10px 14px;
        border-radius: 7px;
        font-size: 13px;
        margin-bottom: 18px;
        line-height: 1.5;
    }
    .auth-link {
        display: block;
        text-align: center;
        font-size: 13px;
        color: var(--muted);
        margin-top: 20px;
    }
    .auth-link a { color: var(--accent); text-decoration: none; font-weight: 500; }
    .auth-link a:hover { text-decoration: underline; }
    .course-list { list-style: none; }
    .course-list li { margin-bottom: 10px; }
    .course-btn {
        display: block;
        width: 100%;
        padding: 14px 16px;
        background: var(--bg);
        border: 1.5px solid var(--border);
        border-radius: 8px;
        text-align: left;
        cursor: pointer;
        font-family: var(--font-body);
        font-size: 14px;
        font-weight: 600;
        color: var(--text);
        transition: border-color 0.15s, background 0.15s;
    }
    .course-btn:hover { border-color: var(--accent); background: var(--accent-t); }
    .course-btn small { display: block; font-size: 12px; font-weight: 400; color: var(--muted); margin-top: 2px; }
    </style>
    </head>
    <body>
    <div class="auth-card">
        <div class="auth-logo">
            <span class="mark">◈</span>
            <span>{$appName}</span>
        </div>
        {$bodyHtml}
    </div>
    </body>
    </html>
    HTML;
    exit;
}

// ── /join — Enrollment form ───────────────────────────────────────────────────

function authShowJoin(string $error = ''): never {
    $errorHtml  = $error ? '<div class="auth-error">' . htmlspecialchars($error) . '</div>' : '';
    $action     = htmlspecialchars($_SERVER['REQUEST_URI']);
    $loginUrl   = APP_URL . '/auth.php?action=login';
    authPage('Join a Course', <<<HTML
    <h1 class="auth-title">Join a Course</h1>
    <p class="auth-subtitle">Enter your email address and the invite code shared by your instructor.</p>
    {$errorHtml}
    <form method="POST" action="{$action}">
        <div class="auth-field">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" required autofocus placeholder="you@example.com">
        </div>
        <div class="auth-field">
            <label for="code">Invite Code</label>
            <input type="text" id="code" name="code" required placeholder="e.g. ABCD1234"
                   maxlength="8" style="text-transform:uppercase;letter-spacing:0.1em;font-size:16px;">
        </div>
        <div class="auth-field">
            <label for="name">Your Name</label>
            <input type="text" id="name" name="name" required placeholder="First Last">
        </div>
        <button type="submit" class="auth-btn">Join Course</button>
    </form>
    <p class="auth-link">Already joined? <a href="{$loginUrl}">Sign in via email</a></p>
    HTML);
}

function authHandleJoin(): never {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $code  = strtoupper(trim($_POST['code']  ?? ''));
    $name  = trim($_POST['name'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        authShowJoin('Please enter a valid email address.');
    }
    if (strlen($code) !== 8) {
        authShowJoin('Invite code must be 8 characters.');
    }
    if (!$name) {
        authShowJoin('Please enter your name.');
    }

    // Look up invite code
    $invite = dbOne(
        "SELECT * FROM course_invite_codes
          WHERE code = ? AND is_active = 1
            AND (expires_at IS NULL OR expires_at > ?)
            AND (max_uses   IS NULL OR use_count < max_uses)",
        [$code, time()]
    );

    if (!$invite) {
        authShowJoin('That invite code is invalid, expired, or has reached its usage limit.');
    }

    $courseId = (int)$invite['course_id'];
    $role     = $invite['role'];

    // Upsert user (issuer='standalone', sub=email)
    $existing = dbOne("SELECT id FROM users WHERE sub = ? AND issuer = 'standalone'", [$email]);
    if ($existing) {
        $userId = (int)$existing['id'];
        // Update name if blank
        dbRun("UPDATE users SET name = CASE WHEN name = '' THEN ? ELSE name END WHERE id = ?", [$name, $userId]);
    } else {
        $parts  = explode(' ', $name, 2);
        $given  = $parts[0];
        $family = $parts[1] ?? '';
        $userId = dbExec(
            "INSERT INTO users (sub, issuer, name, given_name, family_name, email)
             VALUES (?, 'standalone', ?, ?, ?, ?)",
            [$email, $name, $given, $family, $email]
        );
    }

    // Enroll (upsert)
    $enrolled = dbOne('SELECT id, role FROM enrollments WHERE user_id = ? AND course_id = ?', [$userId, $courseId]);
    if (!$enrolled) {
        dbExec(
            'INSERT INTO enrollments (user_id, course_id, role) VALUES (?, ?, ?)',
            [$userId, $courseId, $role]
        );
    }

    // Increment code use count
    dbRun('UPDATE course_invite_codes SET use_count = use_count + 1 WHERE id = ?', [(int)$invite['id']]);

    // Ensure default spaces exist
    ensureDefaultSpaces($courseId);

    // Create 30-day session
    authCreateSession($userId, $courseId, $enrolled['role'] ?? $role);

    header('Location: ' . APP_URL . '/');
    exit;
}

// ── /login — Magic link request ───────────────────────────────────────────────

function authShowLogin(string $error = '', string $success = ''): never {
    $errorHtml   = $error   ? '<div class="auth-error">'   . htmlspecialchars($error)   . '</div>' : '';
    $successHtml = $success ? '<div class="auth-success">' . htmlspecialchars($success) . '</div>' : '';
    $action      = htmlspecialchars($_SERVER['REQUEST_URI']);
    $joinUrl     = APP_URL . '/auth.php?action=join';
    authPage('Sign In', <<<HTML
    <h1 class="auth-title">Sign In</h1>
    <p class="auth-subtitle">Enter your email and we'll send you a sign-in link. No password needed.</p>
    {$errorHtml}{$successHtml}
    <form method="POST" action="{$action}">
        <div class="auth-field">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" required autofocus placeholder="you@example.com">
        </div>
        <button type="submit" class="auth-btn">Send Sign-In Link</button>
    </form>
    <p class="auth-link">New here? Get an <a href="{$joinUrl}">invite code</a> from your instructor.</p>
    HTML);
}

function authHandleLogin(): never {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        authShowLogin('Please enter a valid email address.');
    }

    // Look up standalone user
    $user = dbOne("SELECT * FROM users WHERE sub = ? AND issuer = 'standalone'", [$email]);

    if (!$user) {
        // Don't reveal whether the user exists — show the same success message
        authShowLogin('', 'If that email is registered, a sign-in link has been sent. Check your inbox.');
    }

    $userId = (int)$user['id'];

    // Find the user's enrolled standalone courses
    $courses = dbAll(
        "SELECT c.id FROM enrollments e
           JOIN courses c ON c.id = e.course_id
          WHERE e.user_id = ? AND c.course_type = 'standalone'",
        [$userId]
    );

    $courseId = count($courses) === 1 ? (int)$courses[0]['id'] : null;

    // Create a 15-minute magic token
    $token = bin2hex(random_bytes(24)); // 48-char hex
    dbExec(
        'INSERT INTO local_auth_tokens (token, user_id, course_id, expires_at)
         VALUES (?, ?, ?, ?)',
        [$token, $userId, $courseId, time() + 900]
    );

    $link = APP_URL . '/auth.php?action=magic&token=' . urlencode($token);
    authSendMagicLink($email, $user['name'], $link);

    // In dev mode, show the link on-screen so testing doesn't require working email
    if (DEV_MODE) {
        $safeLink = htmlspecialchars($link);
        authPage('Sign In — Dev Mode', <<<HTML
        <h1 class="auth-title">Dev Mode: Magic Link</h1>
        <p class="auth-subtitle">Email is not sent in dev mode. Click the link below to sign in:</p>
        <div style="background:var(--bg);border:1.5px solid var(--border);border-radius:8px;padding:14px 16px;word-break:break-all;font-size:13px;margin-bottom:18px;">
            <a href="{$safeLink}" style="color:var(--accent);text-decoration:none;">{$safeLink}</a>
        </div>
        <p class="auth-link"><a href="{$safeLink}" class="auth-btn" style="display:block;text-align:center;text-decoration:none;">Sign in now →</a></p>
        HTML);
    }

    authShowLogin('', 'Sign-in link sent! Check your inbox (and spam folder). The link expires in 15 minutes.');
}

// ── /auth/magic — Redeem magic link ──────────────────────────────────────────

function authHandleMagic(): never {
    $token = trim($_GET['token'] ?? '');

    if (!$token) {
        authPage('Invalid Link', '<div class="auth-error">Missing token. Please request a new sign-in link.</div>
            <p class="auth-link"><a href="' . APP_URL . '/login">Back to sign in</a></p>');
    }

    $row = dbOne(
        'SELECT * FROM local_auth_tokens WHERE token = ? AND expires_at > ? AND used_at IS NULL',
        [$token, time()]
    );

    if (!$row) {
        authPage('Link Expired', '<div class="auth-error">This sign-in link has expired or already been used. Please request a new one.</div>
            <p class="auth-link"><a href="' . APP_URL . '/login">Request a new link</a></p>');
    }

    // Mark token as used
    dbRun('UPDATE local_auth_tokens SET used_at = ? WHERE token = ?', [time(), $token]);

    $userId   = (int)$row['user_id'];
    $courseId = $row['course_id'] ? (int)$row['course_id'] : null;

    if ($courseId) {
        // Single course — create session immediately
        $enrollment = dbOne('SELECT role FROM enrollments WHERE user_id = ? AND course_id = ?', [$userId, $courseId]);
        if (!$enrollment) {
            authPage('Access Error', '<div class="auth-error">You are not enrolled in that course. Please use a valid invite code to join.</div>
                <p class="auth-link"><a href="' . APP_URL . '/join">Join with invite code</a></p>');
        }
        authCreateSession($userId, $courseId, $enrollment['role']);
        header('Location: ' . APP_URL . '/');
        exit;
    }

    // Multiple courses — show picker via session-less cookie
    // Store userId in a short-lived signed token
    $pickerToken = bin2hex(random_bytes(24));
    dbExec(
        'INSERT INTO local_auth_tokens (token, user_id, course_id, expires_at)
         VALUES (?, ?, NULL, ?)',
        [$pickerToken, $userId, time() + 600] // 10-minute picker session
    );

    $cookiePath = rtrim(parse_url(APP_URL, PHP_URL_PATH) ?: '/', '/') . '/';
    setcookie('cc_picker', $pickerToken, [
        'expires'  => time() + 600,
        'path'     => $cookiePath,
        'secure'   => !DEV_MODE,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    header('Location: ' . APP_URL . '/auth.php?action=picker');
    exit;
}

// ── /auth/course-picker — Choose a course ────────────────────────────────────

function authShowCoursePicker(): never {
    $pickerToken = $_COOKIE['cc_picker'] ?? '';
    if (!$pickerToken) {
        header('Location: ' . APP_URL . '/auth.php?action=login');
        exit;
    }

    $row = dbOne(
        'SELECT * FROM local_auth_tokens WHERE token = ? AND expires_at > ? AND used_at IS NULL',
        [$pickerToken, time()]
    );
    if (!$row) {
        header('Location: ' . APP_URL . '/auth.php?action=login');
        exit;
    }

    $userId  = (int)$row['user_id'];
    $courses = dbAll(
        "SELECT c.id, c.title, c.label, e.role
           FROM enrollments e
           JOIN courses c ON c.id = e.course_id
          WHERE e.user_id = ? AND c.course_type = 'standalone'
          ORDER BY c.title",
        [$userId]
    );

    if (empty($courses)) {
        authPage('No Courses', '<div class="auth-error">You are not enrolled in any courses.</div>
            <p class="auth-link"><a href="' . APP_URL . '/join">Join with invite code</a></p>');
    }

    $items = '';
    foreach ($courses as $c) {
        $label = $c['label'] ? htmlspecialchars($c['label']) . ' — ' : '';
        $role  = ucfirst($c['role']);
        $items .= '<li><form method="POST" action="' . APP_URL . '/auth/select-course">'
            . '<input type="hidden" name="course_id" value="' . (int)$c['id'] . '">'
            . '<button type="submit" class="course-btn">'
            . $label . htmlspecialchars($c['title'])
            . '<small>' . $role . '</small>'
            . '</button></form></li>';
    }

    authPage('Choose Course', <<<HTML
    <h1 class="auth-title">Choose a Course</h1>
    <p class="auth-subtitle">You are enrolled in multiple courses. Which one would you like to open?</p>
    <ul class="course-list">{$items}</ul>
    HTML);
}

// ── /auth/select-course — POST: create session for chosen course ──────────────

function authHandleSelectCourse(): never {
    $pickerToken = $_COOKIE['cc_picker'] ?? '';
    $courseId    = (int)($_POST['course_id'] ?? 0);

    if (!$pickerToken || !$courseId) {
        header('Location: ' . APP_URL . '/auth.php?action=login');
        exit;
    }

    $row = dbOne(
        'SELECT * FROM local_auth_tokens WHERE token = ? AND expires_at > ? AND used_at IS NULL',
        [$pickerToken, time()]
    );
    if (!$row) {
        header('Location: ' . APP_URL . '/auth.php?action=login');
        exit;
    }

    $userId     = (int)$row['user_id'];
    $enrollment = dbOne(
        'SELECT role FROM enrollments WHERE user_id = ? AND course_id = ?',
        [$userId, $courseId]
    );

    if (!$enrollment) {
        header('Location: ' . APP_URL . '/auth.php?action=picker');
        exit;
    }

    // Mark picker token used
    dbRun('UPDATE local_auth_tokens SET used_at = ? WHERE token = ?', [time(), $pickerToken]);

    // Clear picker cookie
    $cookiePath = rtrim(parse_url(APP_URL, PHP_URL_PATH) ?: '/', '/') . '/';
    setcookie('cc_picker', '', ['expires' => time() - 3600, 'path' => $cookiePath]);

    authCreateSession($userId, $courseId, $enrollment['role']);
    header('Location: ' . APP_URL . '/');
    exit;
}

// ── Logging ───────────────────────────────────────────────────────────────────

function authLog(string $message): void {
    $line = date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
    // Write to a local file in data/ — reliable regardless of php.ini error_log setting
    $logFile = dirname(DB_PATH) . '/auth.log';
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    // Also send to the system error log as a secondary destination
    error_log('[auth] ' . $message);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function authCreateSession(int $userId, int $courseId, string $role): void {
    dbRun('DELETE FROM sessions WHERE expires_at < ?', [time()]);

    $sid = bin2hex(random_bytes(32));
    dbExec(
        'INSERT INTO sessions (id, user_id, course_id, role, expires_at) VALUES (?, ?, ?, ?, ?)',
        [$sid, $userId, $courseId, $role, time() + STANDALONE_SESSION_DURATION]
    );

    $cookiePath = rtrim(parse_url(APP_URL, PHP_URL_PATH) ?: '/', '/') . '/';
    setcookie('cc_session', $sid, [
        'expires'  => time() + STANDALONE_SESSION_DURATION,
        'path'     => $cookiePath,
        'secure'   => !DEV_MODE,
        'httponly' => true,
        'samesite' => 'Lax',  // Lax (not None) — standalone is first-party
    ]);
}

function authSendMagicLink(string $email, string $name, string $link): void {
    $fromName = MAIL_FROM_NAME;
    $from     = MAIL_FROM;
    $subject  = APP_NAME . ': Your sign-in link';

    $textBody = "Hi {$name},\n\nClick the link below to sign in to " . APP_NAME . ".\n\n{$link}\n\n"
              . "This link expires in 15 minutes and can only be used once.\n\n"
              . "If you didn't request this, you can safely ignore this email.\n\n— " . APP_NAME;

    // Always log the magic link — visible in data/auth.log regardless of php.ini settings
    authLog("Magic link for {$email}: {$link}");

    if (SMTP_HOST) {
        authSendSmtp($email, $from, $fromName, $subject, $textBody);
    } else {
        $headers  = "From: {$fromName} <{$from}>\r\n";
        $headers .= "Reply-To: {$from}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";
        // MAIL_EXTRA_PARAMS is intentionally empty by default. The web server user
        // (www-data/apache) is usually not trusted by sendmail to set the envelope
        // sender with -f, which causes silent delivery failures. Set MAIL_EXTRA_PARAMS
        // to '-f noreply@yourdomain.com' in config.php only if your host supports it.
        $params = MAIL_EXTRA_PARAMS !== '' ? MAIL_EXTRA_PARAMS : null;
        $sent = $params !== null
            ? @mail($email, $subject, $textBody, $headers, $params)
            : @mail($email, $subject, $textBody, $headers);
        if (!$sent) {
            authLog("mail() returned false for {$email} — check sendmail_path in php.ini or set SMTP_HOST in config.php");
        }
    }
}

/**
 * Minimal SMTP sender (no external dependencies).
 * Only supports STARTTLS + AUTH PLAIN (covers most SMTP relays).
 */
function authSendSmtp(string $to, string $from, string $fromName, string $subject, string $body): void {
    $host = SMTP_HOST;
    $port = SMTP_PORT;

    $sock = @fsockopen($host, $port, $errno, $errstr, 10);
    if (!$sock) {
        error_log("[auth] SMTP connect failed: $errstr ($errno)");
        return;
    }

    $read = function() use ($sock): string {
        $buf = '';
        while ($line = fgets($sock, 515)) {
            $buf .= $line;
            if ($line[3] === ' ') break;
        }
        return $buf;
    };

    $cmd = function(string $c) use ($sock, $read): string {
        fwrite($sock, $c . "\r\n");
        return $read();
    };

    $read(); // banner
    $cmd('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $cmd('STARTTLS');
    stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    $cmd('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

    if (SMTP_USER) {
        $auth = base64_encode("\0" . SMTP_USER . "\0" . SMTP_PASS);
        $cmd('AUTH PLAIN ' . $auth);
    }

    $cmd("MAIL FROM:<{$from}>");
    $cmd("RCPT TO:<{$to}>");
    $cmd('DATA');
    $date    = date('r');
    $msgId   = bin2hex(random_bytes(8)) . '@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $payload = "Date: {$date}\r\n"
             . "Message-ID: <{$msgId}>\r\n"
             . "From: {$fromName} <{$from}>\r\n"
             . "To: {$to}\r\n"
             . "Subject: {$subject}\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "\r\n"
             . str_replace("\n.", "\n..", $body)
             . "\r\n.";
    $cmd($payload);
    $cmd('QUIT');
    fclose($sock);
}
