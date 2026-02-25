<?php
/**
 * Course Community - Configuration Template
 *
 * Copy this file to config.php and edit it to match your environment.
 *
 *   cp config.example.php config.php
 *
 * config.php is gitignored and will not be overwritten by git pulls.
 * Never commit config.php — it contains secrets.
 */

// ── App ─────────────────────────────────────────────────────────────────────
define('APP_NAME', 'Course Community');
define('APP_VERSION', '1.0.0');

// Base URL of this tool (no trailing slash)
// You can also set the APP_URL environment variable.
define('APP_URL', rtrim(getenv('APP_URL') ?: 'http://localhost:8080', '/'));

// SQLite database path
define('DB_PATH', __DIR__ . '/data/community.sqlite');

// Session lifetime in seconds (default 8 hours for LTI users)
define('SESSION_DURATION', 28800);

// Standalone (non-LTI) session lifetime — 30 days
define('STANDALONE_SESSION_DURATION', (int)(getenv('STANDALONE_SESSION_DURATION') ?: 2592000));

// ── Email (for magic-link auth) ───────────────────────────────────────────────
// MAIL_FROM must be a real address on a domain your server is authorised to send from.
// Using 'localhost' or a mismatched domain will cause most mail servers to reject or
// spam-filter the message.  Set the MAIL_FROM env var, or edit the fallback below.
define('MAIL_FROM',      getenv('MAIL_FROM')      ?: 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: APP_NAME);

// Email transport — three options:
//
//   1. PHP mail() / sendmail (default when SMTP_HOST is empty)
//      Requires sendmail or postfix on the server.  The MAIL_FROM address must match
//      a domain the server is allowed to send for (SPF/DKIM).
//      Verify with:  php -r "var_dump(mail('test@example.com','test','test'));"
//
//      MAIL_EXTRA_PARAMS is passed as the 5th argument to mail().
//      Leave it empty (the default) — it is the safest option for most web hosts
//      because the web server user (www-data / apache) is typically not trusted by
//      sendmail to override the envelope sender with -f.
//      Only set it to '-f noreply@yourdomain.com' if your host explicitly supports it.
//
//   2. SMTP relay (set SMTP_HOST to your relay hostname)
//      Works with any SMTP service (Gmail relay, Mailgun SMTP, etc.).
//
//   3. No email (DEV_MODE=true)
//      The magic link is shown directly on screen — no email needed.

define('MAIL_EXTRA_PARAMS', getenv('MAIL_EXTRA_PARAMS') ?: '');
define('SMTP_HOST', getenv('SMTP_HOST') ?: '');
define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587));
define('SMTP_USER', getenv('SMTP_USER') ?: '');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');

// ── Admin Panel ──────────────────────────────────────────────────────────────
// Password for /admin.php — set via env var or edit directly.
// Leave empty to disable the admin panel entirely.
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: '');

// ── Developer Mode ───────────────────────────────────────────────────────────
// Set DEV_MODE=true to bypass LTI authentication and use a simulated user.
// NEVER enable this in production.
define('DEV_MODE', filter_var(getenv('DEV_MODE') ?: 'false', FILTER_VALIDATE_BOOLEAN));

define('DEV_USER', [
    'sub'         => 'dev-user-1',
    'name'        => 'Dev Instructor',
    'given_name'  => 'Dev',
    'family_name' => 'Instructor',
    'email'       => 'dev@example.com',
    'picture'     => '',
    'role'        => 'instructor',  // 'instructor' or 'student'
]);

define('DEV_COURSE', [
    'context_id' => 'dev-course-101',
    'title'      => 'EDUC 101: Introduction to Learning',
    'label'      => 'EDUC 101',
]);

// ── LTI 1.3 Platform Registrations ──────────────────────────────────────────
// Each key is either:
//   (a) A plain issuer URL — one tool registration per LMS instance
//       e.g.  'https://uni.brightspace.com' => [...]
//   (b) A compound "issuer::client_id" key — multiple registrations per LMS
//       e.g.  'https://uni.brightspace.com::prod-client-id'  => [...]
//             'https://uni.brightspace.com::test-client-id'  => [...]
//
// Each platform entry needs values from your LMS tool registration.
// Brightspace LTI 1.3 guide: https://community.d2l.com/brightspace/kb/articles/4743
//
// Tool URLs to register in your LMS:
//   Login Initiation URL : APP_URL/lti.php?action=login
//   Redirect URI         : APP_URL/lti.php?action=launch
//   Target Link URI      : APP_URL/

$LTI_PLATFORMS = [
    // ── Primary platform (configure via env vars or edit directly) ──────────
    getenv('LTI_ISSUER') ?: 'https://your-brightspace.brightspace.com' => [
        'client_id'     => getenv('LTI_CLIENT_ID')     ?: 'your-client-id',
        'auth_endpoint' => getenv('LTI_AUTH_ENDPOINT') ?: 'https://your-brightspace.brightspace.com/d2l/lti/authenticate',
        'jwks_uri'      => getenv('LTI_JWKS_URI')      ?: 'https://your-brightspace.brightspace.com/d2l/.well-known/jwks',
    ],

    // ── Additional platforms — add as many as needed ─────────────────────────
    // 'https://second-uni.brightspace.com' => [
    //     'client_id'     => 'their-client-id',
    //     'auth_endpoint' => 'https://second-uni.brightspace.com/d2l/lti/authenticate',
    //     'jwks_uri'      => 'https://second-uni.brightspace.com/d2l/.well-known/jwks',
    // ],
    //
    // ── Multiple registrations from the same LMS (use compound key) ──────────
    // 'https://uni.brightspace.com::staging-client' => [
    //     'client_id'     => 'staging-client',
    //     'auth_endpoint' => 'https://uni.brightspace.com/d2l/lti/authenticate',
    //     'jwks_uri'      => 'https://uni.brightspace.com/d2l/.well-known/jwks',
    // ],
];
