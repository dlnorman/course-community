<?php
/**
 * Course Community - Configuration
 * Edit this file to match your deployment environment.
 */

// ── App ─────────────────────────────────────────────────────────────────────
define('APP_NAME', 'Course Community');
define('APP_VERSION', '1.0.0');

// Base URL of this tool (no trailing slash)
// You can also set the APP_URL environment variable.
define('APP_URL', rtrim(getenv('APP_URL') ?: 'http://localhost:8080', '/'));

// SQLite database path
define('DB_PATH', __DIR__ . '/data/community.sqlite');

// Session lifetime in seconds (default 8 hours)
define('SESSION_DURATION', 28800);

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
