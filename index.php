<?php
/**
 * Course Community — App Shell
 * Serves the SPA. Routes API calls to api.php, LTI calls to lti.php.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ── Simple router ─────────────────────────────────────────────────────────────

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/') ?: '/';

// Strip app base path so subdirectory installs work
$_basePath = rtrim(parse_url(APP_URL, PHP_URL_PATH) ?? '', '/');
$_relUri   = $_basePath ? substr($uri, strlen($_basePath)) : $uri;
$_relUri   = $uri === $_basePath ? '/' : ($_relUri ?: '/');

if (str_starts_with($_relUri, '/api/') || $_relUri === '/api') {
    require __DIR__ . '/api.php';
    exit;
}
if (str_starts_with($_relUri, '/lti')) {
    require __DIR__ . '/lti.php';
    exit;
}

// ── Check auth ────────────────────────────────────────────────────────────────

$sid = $_COOKIE['cc_session'] ?? '';
$authed = false;

if ($sid) {
    $session = dbOne('SELECT id FROM sessions WHERE id = ? AND expires_at > ?', [$sid, time()]);
    $authed  = (bool)$session;
}

// ── Public document viewer ────────────────────────────────────────────────────
// Serve /doc/{id} to unauthenticated users if the document is publicly visible.
// Authenticated users fall through to the SPA (which handles the /doc/:id route).
if (!$authed && preg_match('#^/doc/(\d+)$#', $_relUri, $_docMatch)) {
    $_pubDoc = dbOne(
        'SELECT d.id, d.title, d.content, d.access_level, d.updated_at,
                u.name AS creator_name, c.title AS course_title
         FROM documents d
         JOIN users u ON u.id = d.created_by
         JOIN courses c ON c.id = d.course_id
         WHERE d.id = ? AND d.access_level = 3',
        [(int)$_docMatch[1]]
    );
    if ($_pubDoc) {
        include __DIR__ . '/doc-public.php';
        exit;
    }
    // Not a public doc — fall through to landing
}

if (!$authed) {
    if (DEV_MODE) {
        header('Location: ' . APP_URL . '/lti.php?action=dev');
        exit;
    }
    include __DIR__ . '/landing.php';
    exit;
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1E2238">
    <title><?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..700;1,9..144,300..500&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/style.css?v=<?= APP_VERSION ?>">
</head>
<body>

<div id="app">
    <div class="app-loading">
        <div class="loading-logo">
            <span class="loading-mark">◈</span>
            <span>Course Community</span>
        </div>
        <div class="loading-bar"><div class="loading-fill"></div></div>
    </div>
</div>

<script>
    window.APP_CONFIG = {
        version: '<?= APP_VERSION ?>',
        devMode: <?= DEV_MODE ? 'true' : 'false' ?>,
        baseUrl: '<?= rtrim(APP_URL, '/') ?>',
    };
</script>
<script type="module" src="<?= APP_URL ?>/assets/app.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
