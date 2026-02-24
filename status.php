<?php
/**
 * Course Community — Environment Diagnostics
 *
 * Visit this page to diagnose server configuration problems.
 * DELETE OR RESTRICT THIS FILE after you've resolved any issues —
 * it reveals PHP environment details that should not be public.
 */

require_once __DIR__ . '/config.php';

$checks = [];

function check(string $label, bool $pass, string $detail = ''): void {
    global $checks;
    $checks[] = ['label' => $label, 'pass' => $pass, 'detail' => $detail];
}

// ── PHP version ───────────────────────────────────────────────────────────────
$phpVer = PHP_VERSION;
check('PHP version ≥ 8.1', version_compare($phpVer, '8.1.0', '>='), "Running PHP $phpVer");

// ── Required extensions ───────────────────────────────────────────────────────
foreach (['pdo', 'pdo_sqlite', 'openssl', 'json', 'mbstring'] as $ext) {
    check("Extension: $ext", extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'MISSING');
}
check('Extension: zip (backup/restore)', extension_loaded('zip'),
    extension_loaded('zip') ? 'loaded' : 'missing — backup/restore will not work');

// ── data/ directory ───────────────────────────────────────────────────────────
$dataDir = dirname(DB_PATH);
$dataDirExists   = is_dir($dataDir);
$dataDirWritable = $dataDirExists && is_writable($dataDir);
check('data/ directory exists',   $dataDirExists,   $dataDirExists   ? $dataDir : "$dataDir — NOT FOUND");
check('data/ directory writable', $dataDirWritable, $dataDirWritable ? 'writable' : 'NOT WRITABLE — run: chmod 755 ' . $dataDir);

// ── data/uploads/ ─────────────────────────────────────────────────────────────
$uploadsDir = $dataDir . '/uploads';
if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0755, true);
}
check('data/uploads/ exists & writable', is_dir($uploadsDir) && is_writable($uploadsDir),
    is_dir($uploadsDir) ? (is_writable($uploadsDir) ? 'writable' : 'NOT WRITABLE') : 'could not create');

// ── SQLite DB ─────────────────────────────────────────────────────────────────
$dbOk = false;
$dbDetail = '';
try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $ver = $db->query('SELECT sqlite_version()')->fetchColumn();
    $dbOk     = true;
    $dbDetail = "SQLite $ver — " . DB_PATH;
} catch (Throwable $e) {
    $dbDetail = 'FAILED: ' . $e->getMessage();
}
check('SQLite database accessible', $dbOk, $dbDetail);

// ── APP_URL ───────────────────────────────────────────────────────────────────
$appUrl = APP_URL;
$appUrlOk = ($appUrl !== 'http://localhost:8080');
check('APP_URL configured', $appUrlOk,
    $appUrlOk ? $appUrl : "Still set to default (http://localhost:8080) — set APP_URL env var or edit config.php");

// ── LTI platform config ───────────────────────────────────────────────────────
global $LTI_PLATFORMS;
$platformCount = count($LTI_PLATFORMS);
$defaultIssuer = 'https://your-brightspace.brightspace.com';
$hasDefault    = isset($LTI_PLATFORMS[$defaultIssuer]);
check('LTI platforms configured', $platformCount > 0 && !$hasDefault,
    $hasDefault
        ? "Still using placeholder issuer — edit \$LTI_PLATFORMS in config.php"
        : "$platformCount platform(s) registered");

// ── ADMIN_PASSWORD ────────────────────────────────────────────────────────────
$adminPwSet = defined('ADMIN_PASSWORD') && ADMIN_PASSWORD !== '';
check('ADMIN_PASSWORD set', $adminPwSet, $adminPwSet ? '(set)' : 'empty — admin panel is disabled');

// ── HTTPS ─────────────────────────────────────────────────────────────────────
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
check('Running over HTTPS', $isHttps, $isHttps ? 'yes' : 'NO — LTI cookies will not work over plain HTTP');

// ── openssl_verify available ──────────────────────────────────────────────────
$sslFnOk = function_exists('openssl_verify') && function_exists('openssl_pkey_get_public');
check('openssl_verify / openssl_pkey_get_public', $sslFnOk,
    $sslFnOk ? 'available' : 'MISSING — JWT validation will fail');

// ── file_get_contents / allow_url_fopen ──────────────────────────────────────
$urlFopen = (bool)ini_get('allow_url_fopen');
check('allow_url_fopen (JWKS fetch)', $urlFopen,
    $urlFopen ? 'enabled' : 'DISABLED — cannot fetch Brightspace JWKS; LTI launch will fail');

// ── Summary ───────────────────────────────────────────────────────────────────
$failCount = count(array_filter($checks, fn($c) => !$c['pass']));
$allPass   = ($failCount === 0);

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Course Community — Status</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
       background:#0d1117; color:#e6edf3; margin:0; padding:40px 20px; font-size:14px; }
.wrap { max-width:700px; margin:0 auto; }
h1 { font-size:18px; margin-bottom:4px; color:#58a6ff; }
.subtitle { color:#8b949e; margin-bottom:28px; font-size:13px; }
.summary { padding:14px 18px; border-radius:6px; margin-bottom:24px; font-weight:600; font-size:15px; }
.ok  { background:rgba(63,185,80,.15); border:1px solid rgba(63,185,80,.4); color:#3fb950; }
.bad { background:rgba(248,81,73,.15); border:1px solid rgba(248,81,73,.4); color:#f85149; }
table { width:100%; border-collapse:collapse; background:#161b22;
        border:1px solid #30363d; border-radius:6px; overflow:hidden; }
th { text-align:left; padding:10px 14px; font-size:11px; text-transform:uppercase;
     letter-spacing:.06em; color:#8b949e; border-bottom:1px solid #30363d; }
td { padding:10px 14px; border-bottom:1px solid #21262d; font-size:13px; vertical-align:top; }
tr:last-child td { border-bottom:none; }
.pass { color:#3fb950; font-weight:700; }
.fail { color:#f85149; font-weight:700; }
.detail { color:#8b949e; font-size:12px; font-family:monospace; }
.warn { background:rgba(210,153,34,.12); border:1px solid rgba(210,153,34,.3);
        color:#d29922; padding:14px 18px; border-radius:6px; margin-top:24px; font-size:13px; }
</style>
</head>
<body>
<div class="wrap">
    <h1>◈ Course Community — Server Status</h1>
    <p class="subtitle"><?= htmlspecialchars($_SERVER['SERVER_NAME'] ?? 'unknown') ?> &nbsp;·&nbsp; PHP <?= PHP_VERSION ?></p>

    <div class="summary <?= $allPass ? 'ok' : 'bad' ?>">
        <?= $allPass
            ? '✓ All checks passed — environment looks good.'
            : "✗ $failCount check(s) failed — see details below." ?>
    </div>

    <table>
        <thead><tr><th style="width:50%">Check</th><th style="width:10%">Result</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($checks as $c): ?>
        <tr>
            <td><?= htmlspecialchars($c['label']) ?></td>
            <td class="<?= $c['pass'] ? 'pass' : 'fail' ?>"><?= $c['pass'] ? 'PASS' : 'FAIL' ?></td>
            <td class="detail"><?= htmlspecialchars($c['detail']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="warn">
        ⚠ Delete or password-protect this file once you've resolved any issues —
        it exposes server configuration details that should not be public.
    </div>
</div>
</body>
</html>
