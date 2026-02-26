<?php
/**
 * Course Community — Admin Panel
 *
 * Protected by ADMIN_PASSWORD (config.php or ADMIN_PASSWORD env var).
 * Provides: course overview, course deletion, database backup, and restore.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
define('AUTH_FUNCTIONS_ONLY', true);  // load auth helpers without running the router
require_once __DIR__ . '/auth.php';

// ── Admin auth (separate PHP session, isolated from app sessions) ─────────────

session_name('cc_admin');
session_start();

$adminPassword = defined('ADMIN_PASSWORD') ? ADMIN_PASSWORD : '';
$disabled      = ($adminPassword === '');
$authed        = !$disabled && !empty($_SESSION['cc_admin_authed']);
$loginError    = '';
$message       = '';

// ── Handle POST actions ───────────────────────────────────────────────────────

$action = $_POST['action'] ?? '';

if ($action === 'login' && !$disabled) {
    $pw = $_POST['password'] ?? '';
    if (hash_equals($adminPassword, $pw)) {
        $_SESSION['cc_admin_authed'] = true;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    $loginError = 'Incorrect password.';
}

if ($action === 'logout') {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($authed) {

    $viewCourseId = 0; // set by GET ?course=N or by detail-view POST actions

    if ($action === 'create_standalone_course') {
        $title = trim($_POST['sa_title'] ?? '');
        $label = trim($_POST['sa_label'] ?? '');
        if ($title) {
            $newCourseId = createStandaloneCourse($title, $label);
            ensureDefaultSpaces($newCourseId);
            $message = "Standalone course \"" . htmlspecialchars($title) . "\" created (ID: $newCourseId).";
        } else {
            $message = 'Error: Course title is required.';
        }
    }

    if ($action === 'set_standalone_instructor') {
        $courseId  = (int)($_POST['sa_course_id'] ?? 0);
        $instrEmail = strtolower(trim($_POST['sa_email'] ?? ''));
        $instrName  = trim($_POST['sa_name'] ?? '');
        if ($courseId && filter_var($instrEmail, FILTER_VALIDATE_EMAIL)) {
            // Upsert instructor user
            $existing = dbOne("SELECT id FROM users WHERE sub = ? AND issuer = 'standalone'", [$instrEmail]);
            if ($existing) {
                $instrId = (int)$existing['id'];
                if ($instrName) {
                    dbRun("UPDATE users SET name = ?, given_name = ? WHERE id = ?",
                        [$instrName, explode(' ', $instrName)[0], $instrId]);
                }
            } else {
                $parts  = explode(' ', $instrName ?: $instrEmail, 2);
                $instrId = dbExec(
                    "INSERT INTO users (sub, issuer, name, given_name, family_name, email)
                     VALUES (?, 'standalone', ?, ?, ?, ?)",
                    [$instrEmail, $instrName ?: $instrEmail, $parts[0], $parts[1] ?? '', $instrEmail]
                );
            }
            // Enroll as instructor
            $enrolled = dbOne('SELECT id FROM enrollments WHERE user_id = ? AND course_id = ?', [$instrId, $courseId]);
            if ($enrolled) {
                dbRun('UPDATE enrollments SET role = ? WHERE user_id = ? AND course_id = ?',
                    ['instructor', $instrId, $courseId]);
            } else {
                dbExec('INSERT INTO enrollments (user_id, course_id, role) VALUES (?, ?, ?)',
                    [$instrId, $courseId, 'instructor']);
            }
            // Generate 7-day magic link
            $token = bin2hex(random_bytes(24));
            dbExec(
                'INSERT INTO local_auth_tokens (token, user_id, course_id, expires_at) VALUES (?, ?, ?, ?)',
                [$token, $instrId, $courseId, time() + 604800]
            );
            $magicLink = rtrim(APP_URL, '/') . '/auth.php?action=magic&token=' . urlencode($token);
            authSendMagicLink($instrEmail, $instrName ?: $instrEmail, $magicLink);
            $message = 'Instructor enrolled. A magic-link email has been sent to '
                . htmlspecialchars($instrEmail) . '. '
                . 'You can also share this one-time link directly (valid 7 days):<br>'
                . '<code style="font-family:monospace;font-size:12px;word-break:break-all;">'
                . htmlspecialchars($magicLink) . '</code>';
        } else {
            $message = 'Error: Valid course and email required.';
        }
    }

    if ($action === 'delete_course') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        if ($courseId > 0) {
            $courseName = dbOne('SELECT title FROM courses WHERE id = ?', [$courseId])['title'] ?? "Course #$courseId";
            adminDeleteCourse($courseId);
            $message = "Course \"" . htmlspecialchars($courseName) . "\" and all its data have been deleted.";
        }
    }

    if ($action === 'backup') {
        adminDoBackup();
        exit;
    }

    if ($action === 'restore') {
        $result  = adminDoRestore();
        $message = $result;
    }

    if ($action === 'admin_enter_course') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        if ($courseId) {
            $adminUser = dbOne("SELECT id FROM users WHERE sub='__admin__' AND issuer='standalone'");
            if (!$adminUser) {
                $adminId = (int)dbExec(
                    "INSERT INTO users (sub, issuer, name, given_name, email) VALUES ('__admin__', 'standalone', 'Admin', 'Admin', '')"
                );
            } else {
                $adminId = (int)$adminUser['id'];
            }
            $enrolled = dbOne('SELECT id FROM enrollments WHERE user_id=? AND course_id=?', [$adminId, $courseId]);
            if ($enrolled) {
                dbRun('UPDATE enrollments SET role=? WHERE user_id=? AND course_id=?', ['instructor', $adminId, $courseId]);
            } else {
                dbExec('INSERT INTO enrollments (user_id, course_id, role) VALUES (?,?,?)', [$adminId, $courseId, 'instructor']);
            }
            $token = bin2hex(random_bytes(24));
            dbExec('INSERT INTO local_auth_tokens (token, user_id, course_id, expires_at) VALUES (?,?,?,?)',
                [$token, $adminId, $courseId, time() + 3600]);
            header('Location: ' . rtrim(APP_URL, '/') . '/auth.php?action=magic&token=' . urlencode($token));
            exit;
        }
    }

    if ($action === 'admin_add_member') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        $email    = strtolower(trim($_POST['member_email'] ?? ''));
        $name     = trim($_POST['member_name'] ?? '');
        $role     = in_array($_POST['member_role'] ?? '', ['instructor', 'student']) ? $_POST['member_role'] : 'student';
        if ($courseId && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $existing = dbOne("SELECT id, name FROM users WHERE (email=? OR sub=?) AND issuer='standalone'", [$email, $email]);
            if ($existing) {
                $userId = (int)$existing['id'];
                if ($name && $name !== $existing['name']) {
                    $parts = explode(' ', $name, 2);
                    dbRun('UPDATE users SET name=?, given_name=? WHERE id=?', [$name, $parts[0], $userId]);
                }
            } else {
                $parts = explode(' ', $name ?: $email, 2);
                $userId = (int)dbExec(
                    "INSERT INTO users (sub, issuer, name, given_name, family_name, email) VALUES (?, 'standalone', ?, ?, ?, ?)",
                    [$email, $name ?: $email, $parts[0], $parts[1] ?? '', $email]
                );
            }
            $enrolled = dbOne('SELECT id FROM enrollments WHERE user_id=? AND course_id=?', [$userId, $courseId]);
            $verb = $enrolled ? 'Updated' : 'Added';
            if ($enrolled) {
                dbRun('UPDATE enrollments SET role=? WHERE user_id=? AND course_id=?', [$role, $userId, $courseId]);
            } else {
                dbExec('INSERT INTO enrollments (user_id, course_id, role) VALUES (?,?,?)', [$userId, $courseId, $role]);
            }
            $token = bin2hex(random_bytes(24));
            dbExec('INSERT INTO local_auth_tokens (token, user_id, course_id, expires_at) VALUES (?,?,?,?)',
                [$token, $userId, $courseId, time() + 604800]);
            $magicLink = rtrim(APP_URL, '/') . '/auth.php?action=magic&token=' . urlencode($token);
            $message = $verb . ' <strong>' . htmlspecialchars($email) . '</strong> as ' . $role
                . '. Magic link (7 days):<br><code style="word-break:break-all;font-size:11px;font-family:monospace;">'
                . htmlspecialchars($magicLink) . '</code>';
        } else {
            $message = 'Error: Valid email address required.';
        }
        $viewCourseId = $courseId;
    }

    if ($action === 'admin_remove_member') {
        $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
        $courseId     = (int)($_POST['course_id'] ?? 0);
        if ($enrollmentId && $courseId) {
            $e = dbOne('SELECT e.id, u.name FROM enrollments e JOIN users u ON u.id=e.user_id WHERE e.id=? AND e.course_id=?', [$enrollmentId, $courseId]);
            if ($e) {
                dbRun('DELETE FROM enrollments WHERE id=?', [$enrollmentId]);
                $message = htmlspecialchars($e['name']) . ' removed from course.';
            }
        }
        $viewCourseId = $courseId;
    }

    if ($action === 'admin_change_role') {
        $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
        $courseId     = (int)($_POST['course_id'] ?? 0);
        $newRole      = in_array($_POST['new_role'] ?? '', ['instructor', 'student']) ? $_POST['new_role'] : null;
        if ($enrollmentId && $courseId && $newRole) {
            dbRun('UPDATE enrollments SET role=? WHERE id=? AND course_id=?', [$newRole, $enrollmentId, $courseId]);
            $message = 'Role updated to ' . $newRole . '.';
        }
        $viewCourseId = $courseId;
    }

}

// ── Admin functions ───────────────────────────────────────────────────────────

function adminDeleteCourse(int $id): void
{
    $db = getDb();

    // Collect upload file paths before deleting rows
    $files = dbAll(
        'SELECT ps.file_path
           FROM pf_submissions ps
           JOIN pf_assignments pa ON ps.assignment_id = pa.id
          WHERE pa.course_id = ?
            AND ps.file_path IS NOT NULL',
        [$id]
    );

    $db->beginTransaction();

    $db->prepare(
        'DELETE FROM pf_responses
          WHERE review_assignment_id IN (
              SELECT ra.id FROM pf_review_assignments ra
              JOIN pf_assignments a ON ra.assignment_id = a.id
              WHERE a.course_id = ?
          )'
    )->execute([$id]);

    $db->prepare(
        'DELETE FROM pf_review_assignments
          WHERE assignment_id IN (SELECT id FROM pf_assignments WHERE course_id = ?)'
    )->execute([$id]);

    $db->prepare(
        'DELETE FROM pf_submissions
          WHERE assignment_id IN (SELECT id FROM pf_assignments WHERE course_id = ?)'
    )->execute([$id]);

    $db->prepare('DELETE FROM pf_assignments WHERE course_id = ?')->execute([$id]);

    $db->prepare(
        'DELETE FROM poll_votes
          WHERE post_id IN (SELECT id FROM posts WHERE course_id = ?)'
    )->execute([$id]);

    $db->prepare(
        'DELETE FROM reactions
          WHERE target_type = ? AND target_id IN (SELECT id FROM posts WHERE course_id = ?)'
    )->execute(['post', $id]);

    $db->prepare(
        'DELETE FROM reactions
          WHERE target_type = ? AND target_id IN (
              SELECT id FROM comments WHERE post_id IN (SELECT id FROM posts WHERE course_id = ?)
          )'
    )->execute(['comment', $id]);

    $db->prepare(
        'DELETE FROM votes
          WHERE target_type = ? AND target_id IN (SELECT id FROM posts WHERE course_id = ?)'
    )->execute(['post', $id]);

    $db->prepare(
        'DELETE FROM votes
          WHERE target_type = ? AND target_id IN (
              SELECT id FROM comments WHERE post_id IN (SELECT id FROM posts WHERE course_id = ?)
          )'
    )->execute(['comment', $id]);

    $db->prepare(
        'DELETE FROM comments
          WHERE post_id IN (SELECT id FROM posts WHERE course_id = ?)'
    )->execute([$id]);

    $db->prepare(
        'DELETE FROM post_tags
          WHERE post_id IN (SELECT id FROM posts WHERE course_id = ?)'
    )->execute([$id]);

    $db->prepare(
        'DELETE FROM board_cards
          WHERE board_id IN (SELECT id FROM boards WHERE course_id = ?)'
    )->execute([$id]);

    $db->prepare('DELETE FROM course_invite_codes WHERE course_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM documents    WHERE course_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM boards       WHERE course_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM posts        WHERE course_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM spaces       WHERE course_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM tags         WHERE course_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM notifications WHERE course_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM sessions     WHERE course_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM enrollments  WHERE course_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM courses      WHERE id = ?')->execute([$id]);

    $db->commit();

    // Delete uploaded files after successful DB transaction
    foreach ($files as $f) {
        $path = $f['file_path'];
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }
}

function adminDoBackup(): void
{
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo 'The PHP zip extension is not available on this server.';
        return;
    }

    $timestamp = date('Y-m-d_His');
    $tmpFile   = sys_get_temp_dir() . "/cc-backup-{$timestamp}.zip";

    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        echo 'Could not create backup archive.';
        return;
    }

    // Database
    if (file_exists(DB_PATH)) {
        $zip->addFile(DB_PATH, 'community.sqlite');
    }

    // Uploads directory
    $uploadsDir = dirname(DB_PATH) . '/uploads';
    if (is_dir($uploadsDir)) {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iter as $file) {
            if ($file->isFile()) {
                $zip->addFile($file->getRealPath(), 'uploads/' . $iter->getSubPathName());
            }
        }
    }

    $zip->close();

    header('Content-Type: application/zip');
    header("Content-Disposition: attachment; filename=\"course-community-backup-{$timestamp}.zip\"");
    header('Content-Length: ' . filesize($tmpFile));
    header('Pragma: no-cache');
    readfile($tmpFile);
    @unlink($tmpFile);
}

function adminDoRestore(): string
{
    if (!class_exists('ZipArchive')) {
        return 'Error: The PHP zip extension is not available on this server.';
    }

    if (!isset($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['backup']['error'] ?? -1;
        return "Upload failed (error code $errCode). Check your PHP upload_max_filesize setting.";
    }

    $tmpFile = $_FILES['backup']['tmp_name'];
    $zip     = new ZipArchive();

    if ($zip->open($tmpFile) !== true) {
        return 'Error: Uploaded file is not a valid zip archive.';
    }

    if ($zip->locateName('community.sqlite') === false) {
        $zip->close();
        return 'Error: This archive does not appear to be a Course Community backup (missing community.sqlite).';
    }

    // Restore database
    $dbContent = $zip->getFromName('community.sqlite');
    file_put_contents(DB_PATH, $dbContent);

    // Restore uploads
    $numFiles   = $zip->numFiles;
    $filesCount = 0;
    for ($i = 0; $i < $numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (str_starts_with($name, 'uploads/') && !str_ends_with($name, '/')) {
            $dest    = dirname(DB_PATH) . '/' . $name;
            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            file_put_contents($dest, $zip->getFromIndex($i));
            $filesCount++;
        }
    }

    $zip->close();
    return "Restore complete. Database replaced; {$filesCount} upload file(s) restored.";
}

// ── Gather stats for dashboard ────────────────────────────────────────────────

$courses           = [];
$standaloneCourses = [];
$stats             = [];

if ($authed) {
    $stats['courses']  = (int)(dbOne('SELECT COUNT(*) n FROM courses')['n'] ?? 0);
    $stats['users']    = (int)(dbOne('SELECT COUNT(*) n FROM users')['n'] ?? 0);
    $stats['posts']    = (int)(dbOne('SELECT COUNT(*) n FROM posts')['n'] ?? 0);
    $stats['sessions'] = (int)(dbOne('SELECT COUNT(*) n FROM sessions WHERE expires_at > ?', [time()])['n'] ?? 0);
    $stats['pf']       = (int)(dbOne('SELECT COUNT(*) n FROM pf_assignments')['n'] ?? 0);
    $stats['db_mb']    = file_exists(DB_PATH) ? round(filesize(DB_PATH) / 1048576, 2) : 0;

    $uploadsDir              = dirname(DB_PATH) . '/uploads';
    $stats['uploads_files']  = 0;
    $stats['uploads_mb']     = 0;
    if (is_dir($uploadsDir)) {
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS)
        ) as $f) {
            if ($f->isFile()) {
                $stats['uploads_files']++;
                $stats['uploads_mb'] += $f->getSize();
            }
        }
        $stats['uploads_mb'] = round($stats['uploads_mb'] / 1048576, 2);
    }

    $courses = dbAll(
        "SELECT c.id, c.issuer, c.context_id, c.title, c.label, c.created_at,
                COALESCE(c.course_type, 'lti') AS course_type,
                COUNT(DISTINCT e.user_id)  AS members,
                COUNT(DISTINCT p.id)       AS posts,
                COUNT(DISTINCT pfa.id)     AS pf_assignments,
                COUNT(DISTINCT ps.id)      AS pf_submissions
           FROM courses c
      LEFT JOIN enrollments e   ON e.course_id   = c.id
      LEFT JOIN posts p         ON p.course_id   = c.id
      LEFT JOIN pf_assignments pfa ON pfa.course_id = c.id
      LEFT JOIN pf_submissions ps
             ON ps.assignment_id IN (SELECT id FROM pf_assignments WHERE course_id = c.id)
          GROUP BY c.id
          ORDER BY c.created_at DESC"
    );

    $standaloneCourses = array_filter($courses, fn($c) => ($c['course_type'] ?? 'lti') === 'standalone');

    // Detail view: set from GET param (POST actions set $viewCourseId themselves)
    if (!$viewCourseId && isset($_GET['course'])) {
        $viewCourseId = (int)$_GET['course'];
    }

    $courseDetail        = null;
    $courseDetailStats   = [];
    $courseDetailMembers = [];
    if ($viewCourseId) {
        $courseDetail = dbOne('SELECT * FROM courses WHERE id=?', [$viewCourseId]);
        if ($courseDetail) {
            $courseDetailMembers = dbAll(
                "SELECT e.id AS enrollment_id, e.role, e.last_seen,
                        u.id AS user_id, u.name, u.given_name, u.email, u.sub, u.issuer
                   FROM enrollments e
                   JOIN users u ON u.id = e.user_id
                  WHERE e.course_id = ?
                    AND NOT (u.sub = '__admin__' AND u.issuer = 'standalone')
                  ORDER BY e.role DESC, u.name ASC",
                [$viewCourseId]
            );
            $courseDetailStats = [
                'members'  => count($courseDetailMembers),
                'posts'    => (int)(dbOne('SELECT COUNT(*) n FROM posts      WHERE course_id=?', [$viewCourseId])['n'] ?? 0),
                'comments' => (int)(dbOne('SELECT COUNT(*) n FROM comments c JOIN posts p ON p.id=c.post_id WHERE p.course_id=?', [$viewCourseId])['n'] ?? 0),
                'boards'   => (int)(dbOne('SELECT COUNT(*) n FROM boards     WHERE course_id=?', [$viewCourseId])['n'] ?? 0),
                'docs'     => (int)(dbOne('SELECT COUNT(*) n FROM documents  WHERE course_id=?', [$viewCourseId])['n'] ?? 0),
                'pulse'    => (int)(dbOne('SELECT COUNT(*) n FROM pulse_checks WHERE course_id=?', [$viewCourseId])['n'] ?? 0),
                'pf'       => (int)(dbOne('SELECT COUNT(*) n FROM pf_assignments WHERE course_id=?', [$viewCourseId])['n'] ?? 0),
            ];
        }
    }
}

// ── HTML ──────────────────────────────────────────────────────────────────────

$pageTitle = 'Admin — Course Community';
$adminUrl  = htmlspecialchars($_SERVER['PHP_SELF']);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:       #0d1117;
    --surface:  #161b22;
    --border:   #30363d;
    --text:     #e6edf3;
    --muted:    #8b949e;
    --accent:   #58a6ff;
    --danger:   #f85149;
    --success:  #3fb950;
    --warning:  #d29922;
    --radius:   6px;
    --font:     'Consolas', 'JetBrains Mono', 'Fira Code', monospace;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    background: var(--bg);
    color: var(--text);
    font-size: 14px;
    line-height: 1.6;
    min-height: 100vh;
}

a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }

/* ── Layout ─────────────────────────────────────────────── */

.admin-wrap {
    max-width: 1100px;
    margin: 0 auto;
    padding: 0 20px 60px;
}

.admin-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 0 18px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 28px;
}

.admin-wordmark {
    display: flex;
    align-items: center;
    gap: 10px;
    font-family: var(--font);
    font-size: 15px;
    color: var(--text);
}

.admin-wordmark .mark {
    color: var(--accent);
    font-size: 20px;
}

.admin-wordmark .sub {
    color: var(--muted);
    font-size: 12px;
}

/* ── Login ──────────────────────────────────────────────── */

.login-wrap {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
}

.login-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 36px 40px;
    width: 360px;
}

.login-card h1 {
    font-family: var(--font);
    font-size: 16px;
    color: var(--accent);
    margin-bottom: 4px;
}

.login-card p {
    color: var(--muted);
    font-size: 13px;
    margin-bottom: 24px;
}

.form-group { margin-bottom: 16px; }

.form-group label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 6px;
}

.form-group input[type="password"],
.form-group input[type="file"] {
    width: 100%;
    padding: 8px 12px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text);
    font-size: 14px;
    outline: none;
    transition: border-color 0.15s;
}

.form-group input[type="password"]:focus,
.form-group input[type="file"]:focus {
    border-color: var(--accent);
}

.error-msg {
    background: rgba(248, 81, 73, 0.15);
    border: 1px solid rgba(248, 81, 73, 0.4);
    color: var(--danger);
    padding: 10px 14px;
    border-radius: var(--radius);
    font-size: 13px;
    margin-bottom: 16px;
}

.success-msg {
    background: rgba(63, 185, 80, 0.12);
    border: 1px solid rgba(63, 185, 80, 0.35);
    color: var(--success);
    padding: 10px 14px;
    border-radius: var(--radius);
    font-size: 13px;
    margin-bottom: 20px;
}

/* ── Buttons ────────────────────────────────────────────── */

.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 16px;
    border-radius: var(--radius);
    border: 1px solid transparent;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: opacity 0.15s, background 0.15s;
    text-decoration: none;
}

.btn:hover { opacity: 0.85; text-decoration: none; }

.btn-primary {
    background: var(--accent);
    color: #0d1117;
    border-color: var(--accent);
    width: 100%;
    justify-content: center;
    padding: 10px;
    font-size: 14px;
    font-weight: 600;
}

.btn-default {
    background: var(--surface);
    color: var(--text);
    border-color: var(--border);
}

.btn-success {
    background: rgba(63, 185, 80, 0.15);
    color: var(--success);
    border-color: rgba(63, 185, 80, 0.4);
}

.btn-warning {
    background: rgba(210, 153, 34, 0.15);
    color: var(--warning);
    border-color: rgba(210, 153, 34, 0.4);
}

.btn-danger {
    background: rgba(248, 81, 73, 0.1);
    color: var(--danger);
    border-color: rgba(248, 81, 73, 0.35);
}

/* ── Stats grid ─────────────────────────────────────────── */

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 12px;
    margin-bottom: 28px;
}

.stat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 18px;
}

.stat-value {
    font-family: var(--font);
    font-size: 24px;
    font-weight: 600;
    color: var(--accent);
    line-height: 1.1;
}

.stat-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--muted);
    margin-top: 4px;
}

/* ── Section ────────────────────────────────────────────── */

.section {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: 20px;
}

.section-header {
    padding: 14px 18px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.section-title {
    font-family: var(--font);
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
}

.section-body {
    padding: 16px 18px;
}

/* ── Table ──────────────────────────────────────────────── */

.admin-table {
    width: 100%;
    border-collapse: collapse;
}

.admin-table th {
    text-align: left;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--muted);
    padding: 8px 12px;
    border-bottom: 1px solid var(--border);
}

.admin-table td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
    vertical-align: middle;
}

.admin-table tr:last-child td { border-bottom: none; }

.admin-table tr:hover td { background: rgba(255,255,255,0.02); }

.course-title {
    font-weight: 600;
    color: var(--text);
    display: block;
    line-height: 1.3;
}

.course-meta {
    font-family: var(--font);
    font-size: 11px;
    color: var(--muted);
    margin-top: 2px;
    display: block;
}

.badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-family: var(--font);
    background: rgba(88, 166, 255, 0.12);
    color: var(--accent);
    border: 1px solid rgba(88, 166, 255, 0.25);
}

.empty-state {
    color: var(--muted);
    font-style: italic;
    text-align: center;
    padding: 24px;
}

/* ── Actions row ────────────────────────────────────────── */

.actions-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

/* ── Confirm dialog overlay ─────────────────────────────── */

.confirm-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.7);
    z-index: 100;
    align-items: center;
    justify-content: center;
}

.confirm-overlay.open { display: flex; }

.confirm-dialog {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 28px 32px;
    max-width: 420px;
    width: 90%;
}

.confirm-dialog h2 {
    font-size: 16px;
    margin-bottom: 10px;
    color: var(--danger);
}

.confirm-dialog p {
    color: var(--muted);
    font-size: 13px;
    margin-bottom: 20px;
    line-height: 1.5;
}

.confirm-dialog .confirm-name {
    color: var(--text);
    font-weight: 600;
}

.confirm-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

/* ── Detail view ────────────────────────────────────────── */

.breadcrumb {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--muted);
    margin-bottom: 20px;
}
.breadcrumb a { color: var(--accent); }
.breadcrumb-sep { color: var(--border); }

.detail-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.detail-title {
    font-size: 20px;
    font-weight: 700;
    color: var(--text);
    line-height: 1.2;
}
.detail-subtitle {
    font-family: var(--font);
    font-size: 12px;
    color: var(--muted);
    margin-top: 4px;
}
.detail-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start; }

.mini-stats {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}
.mini-stat {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 8px 14px;
    text-align: center;
    min-width: 70px;
}
.mini-stat-val {
    font-family: var(--font);
    font-size: 18px;
    font-weight: 700;
    color: var(--accent);
    line-height: 1;
}
.mini-stat-lbl {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--muted);
    margin-top: 3px;
}

.role-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-family: var(--font);
}
.role-badge.instructor {
    background: rgba(88,166,255,0.12);
    color: var(--accent);
    border: 1px solid rgba(88,166,255,0.3);
}
.role-badge.student {
    background: rgba(255,255,255,0.05);
    color: var(--muted);
    border: 1px solid var(--border);
}

.add-member-form {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: flex-end;
    padding: 14px 18px;
    background: rgba(255,255,255,0.02);
    border-top: 1px solid var(--border);
}
.add-member-form label {
    display: block;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--muted);
    margin-bottom: 4px;
}
.add-member-form input,
.add-member-form select {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text);
    padding: 6px 10px;
    font-size: 13px;
}
.add-member-form input:focus,
.add-member-form select:focus { border-color: var(--accent); outline: none; }
</style>
</head>
<body>

<?php if ($disabled): ?>
<!-- ── Admin disabled ─────────────────────────────────── -->
<div class="login-wrap">
    <div class="login-card">
        <h1>◈ Admin Panel</h1>
        <p>The admin panel is disabled.</p>
        <p style="color: var(--text); font-size: 13px;">
            Set <code style="font-family: monospace; background: var(--bg); padding: 2px 6px; border-radius: 3px;">ADMIN_PASSWORD</code>
            in <code style="font-family: monospace; background: var(--bg); padding: 2px 6px; border-radius: 3px;">config.php</code>
            or as an environment variable to enable it.
        </p>
    </div>
</div>

<?php elseif (!$authed): ?>
<!-- ── Login form ─────────────────────────────────────── -->
<div class="login-wrap">
    <div class="login-card">
        <h1>◈ Admin Panel</h1>
        <p>Course Community administration</p>

        <?php if ($loginError): ?>
            <div class="error-msg"><?= htmlspecialchars($loginError) ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= $adminUrl ?>">
            <input type="hidden" name="action" value="login">
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" autofocus required>
            </div>
            <button type="submit" class="btn btn-primary">Sign in</button>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ── Dashboard ──────────────────────────────────────── -->
<div class="admin-wrap">

    <div class="admin-header">
        <div class="admin-wordmark">
            <span class="mark">◈</span>
            <span>Course Community <span class="sub">/ admin</span></span>
        </div>
        <form method="POST" action="<?= $adminUrl ?>" style="display:inline;">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="btn btn-default">Sign out</button>
        </form>
    </div>

    <?php if ($message): ?>
        <div class="<?= str_starts_with($message, 'Error') ? 'error-msg' : 'success-msg' ?>">
            <?= $message ?>
        </div>
    <?php endif; ?>

    <?php if ($courseDetail): ?>
    <!-- Course detail view -->
    <div class="breadcrumb">
        <a href="<?= $adminUrl ?>">All Courses</a>
        <span class="breadcrumb-sep">›</span>
        <span><?= htmlspecialchars($courseDetail['title']) ?></span>
    </div>

    <div class="detail-header">
        <div>
            <div class="detail-title"><?= htmlspecialchars($courseDetail['title']) ?></div>
            <div class="detail-subtitle">
                ID: <?= (int)$courseDetail['id'] ?>
                <?php if ($courseDetail['label']): ?>
                    &nbsp;·&nbsp; <?= htmlspecialchars($courseDetail['label']) ?>
                <?php endif; ?>
                &nbsp;·&nbsp;
                <?php if (($courseDetail['course_type'] ?? 'lti') === 'standalone'): ?>
                    standalone
                <?php else: ?>
                    LTI — <?= htmlspecialchars(parse_url($courseDetail['issuer'] ?? '', PHP_URL_HOST) ?: ($courseDetail['issuer'] ?? 'unknown')) ?>
                <?php endif; ?>
                &nbsp;·&nbsp; Created <?= date('Y-m-d', (int)$courseDetail['created_at']) ?>
            </div>
        </div>
        <div class="detail-actions">
            <form method="POST" action="<?= $adminUrl ?>">
                <input type="hidden" name="action" value="admin_enter_course">
                <input type="hidden" name="course_id" value="<?= (int)$courseDetail['id'] ?>">
                <button type="submit" class="btn btn-success">Enter Course →</button>
            </form>
            <button class="btn btn-danger"
                    onclick="confirmDelete(<?= (int)$courseDetail['id'] ?>, <?= json_encode($courseDetail['title']) ?>)">
                Delete Course
            </button>
        </div>
    </div>

    <!-- Mini stats -->
    <div class="mini-stats">
        <?php foreach ([
            ['Members',   $courseDetailStats['members']],
            ['Posts',     $courseDetailStats['posts']],
            ['Replies',   $courseDetailStats['comments']],
            ['Boards',    $courseDetailStats['boards']],
            ['Docs',      $courseDetailStats['docs']],
            ['Pulse',     $courseDetailStats['pulse']],
            ['Peer FB',   $courseDetailStats['pf']],
        ] as [$lbl, $val]): ?>
        <div class="mini-stat">
            <div class="mini-stat-val"><?= $val ?></div>
            <div class="mini-stat-lbl"><?= $lbl ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Members -->
    <div class="section">
        <div class="section-header">
            <span class="section-title">Members</span>
            <span style="color:var(--muted);font-size:12px;"><?= count($courseDetailMembers) ?> enrolled</span>
        </div>

        <?php if (empty($courseDetailMembers)): ?>
            <div class="empty-state">No members yet.</div>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email / ID</th>
                    <th>Role</th>
                    <th>Last Seen</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($courseDetailMembers as $m): ?>
                <tr>
                    <td style="font-weight:600;"><?= htmlspecialchars($m['given_name'] ?: $m['name']) ?></td>
                    <td>
                        <span style="font-size:12px;color:var(--muted);">
                            <?= htmlspecialchars($m['email'] ?: $m['sub']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="role-badge <?= htmlspecialchars($m['role']) ?>">
                            <?= htmlspecialchars($m['role']) ?>
                        </span>
                    </td>
                    <td style="font-size:12px;color:var(--muted);white-space:nowrap;">
                        <?= $m['last_seen'] ? date('Y-m-d', (int)$m['last_seen']) : 'never' ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <div style="display:flex;gap:6px;align-items:center;">
                            <!-- Change role -->
                            <form method="POST" action="<?= $adminUrl ?>?course=<?= (int)$courseDetail['id'] ?>" style="display:inline;">
                                <input type="hidden" name="action" value="admin_change_role">
                                <input type="hidden" name="course_id" value="<?= (int)$courseDetail['id'] ?>">
                                <input type="hidden" name="enrollment_id" value="<?= (int)$m['enrollment_id'] ?>">
                                <input type="hidden" name="new_role" value="<?= $m['role'] === 'instructor' ? 'student' : 'instructor' ?>">
                                <button type="submit" class="btn btn-default" style="font-size:11px;padding:4px 10px;">
                                    → <?= $m['role'] === 'instructor' ? 'Student' : 'Instructor' ?>
                                </button>
                            </form>
                            <!-- Remove -->
                            <form method="POST" action="<?= $adminUrl ?>?course=<?= (int)$courseDetail['id'] ?>"
                                  style="display:inline;"
                                  onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($m['name'])) ?> from this course?')">
                                <input type="hidden" name="action" value="admin_remove_member">
                                <input type="hidden" name="course_id" value="<?= (int)$courseDetail['id'] ?>">
                                <input type="hidden" name="enrollment_id" value="<?= (int)$m['enrollment_id'] ?>">
                                <button type="submit" class="btn btn-danger" style="font-size:11px;padding:4px 10px;">Remove</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Add member form -->
        <form method="POST" action="<?= $adminUrl ?>?course=<?= (int)$courseDetail['id'] ?>" class="add-member-form">
            <input type="hidden" name="action" value="admin_add_member">
            <input type="hidden" name="course_id" value="<?= (int)$courseDetail['id'] ?>">
            <div>
                <label>Email *</label>
                <input type="email" name="member_email" required placeholder="user@example.com" style="width:200px;">
            </div>
            <div>
                <label>Full Name</label>
                <input type="text" name="member_name" placeholder="Optional" style="width:160px;">
            </div>
            <div>
                <label>Role</label>
                <select name="member_role">
                    <option value="student">Student</option>
                    <option value="instructor">Instructor</option>
                </select>
            </div>
            <button type="submit" class="btn btn-default" style="margin-bottom:1px;">Add &amp; Generate Link</button>
        </form>
    </div>

    <?php else: // main dashboard ?>
    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $stats['courses'] ?></div>
            <div class="stat-label">Courses</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['users'] ?></div>
            <div class="stat-label">Users</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['posts'] ?></div>
            <div class="stat-label">Posts</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['pf'] ?></div>
            <div class="stat-label">Peer Feedback<br>Assignments</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['sessions'] ?></div>
            <div class="stat-label">Active Sessions</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['db_mb'] ?> <span style="font-size:13px;color:var(--muted)">MB</span></div>
            <div class="stat-label">Database Size</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['uploads_files'] ?></div>
            <div class="stat-label">Uploaded Files<br><span style="font-size:12px;"><?= $stats['uploads_mb'] ?> MB</span></div>
        </div>
    </div>

    <!-- Backup & Restore -->
    <div class="section">
        <div class="section-header">
            <span class="section-title">Backup &amp; Restore</span>
        </div>
        <div class="section-body">
            <p style="color: var(--muted); font-size: 13px; margin-bottom: 16px;">
                Backup downloads a <code style="font-family:monospace;">.zip</code> containing the SQLite database
                and all peer feedback uploads. Restore replaces the live database — take a backup first.
            </p>
            <div class="actions-row">
                <form method="POST" action="<?= $adminUrl ?>">
                    <input type="hidden" name="action" value="backup">
                    <button type="submit" class="btn btn-success">⬇ Download Backup</button>
                </form>

                <form method="POST" action="<?= $adminUrl ?>" enctype="multipart/form-data"
                      onsubmit="return confirm('This will replace the live database and uploads. Proceed?')">
                    <input type="hidden" name="action" value="restore">
                    <input type="file" name="backup" accept=".zip" required
                           style="display:inline; background:var(--bg); color:var(--text);
                                  border:1px solid var(--border); border-radius:var(--radius);
                                  padding:6px 10px; font-size:13px; margin-right:8px;">
                    <button type="submit" class="btn btn-warning">⬆ Restore from Backup</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Courses -->
    <div class="section">
        <div class="section-header">
            <span class="section-title">All Courses</span>
            <span style="color: var(--muted); font-size: 12px;"><?= count($courses) ?> total</span>
        </div>

        <?php if (empty($courses)): ?>
            <div class="empty-state">No courses yet.</div>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Course</th>
                    <th>Platform</th>
                    <th style="text-align:right;">Members</th>
                    <th style="text-align:right;">Posts</th>
                    <th style="text-align:right;">Peer FB</th>
                    <th style="text-align:right;">Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($courses as $c): ?>
                <tr>
                    <td>
                        <span class="course-title"><?= htmlspecialchars($c['title']) ?></span>
                        <?php if ($c['label']): ?>
                            <span class="course-meta"><?= htmlspecialchars($c['label']) ?></span>
                        <?php endif; ?>
                        <span class="course-meta">ID: <?= (int)$c['id'] ?> &nbsp;·&nbsp; context: <?= htmlspecialchars($c['context_id']) ?></span>
                    </td>
                    <td>
                        <?php if (($c['course_type'] ?? '') === 'standalone'): ?>
                            <span class="badge" style="background:rgba(63,185,80,0.1);color:var(--success);border-color:rgba(63,185,80,0.3);">standalone</span>
                        <?php elseif ($c['issuer']): ?>
                            <span class="badge" title="<?= htmlspecialchars($c['issuer']) ?>">
                                <?= htmlspecialchars(parse_url($c['issuer'], PHP_URL_HOST) ?: $c['issuer']) ?>
                            </span>
                        <?php else: ?>
                            <span style="color:var(--muted); font-size:12px;">dev</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right; font-family: monospace;"><?= (int)$c['members'] ?></td>
                    <td style="text-align:right; font-family: monospace;"><?= (int)$c['posts'] ?></td>
                    <td style="text-align:right; font-family: monospace;"><?= (int)$c['pf_assignments'] ?> / <?= (int)$c['pf_submissions'] ?></td>
                    <td style="text-align:right; color: var(--muted); font-size: 12px; white-space:nowrap;">
                        <?= date('Y-m-d', (int)$c['created_at']) ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <div style="display:flex;gap:6px;">
                            <a href="<?= $adminUrl ?>?course=<?= (int)$c['id'] ?>" class="btn btn-default">View</a>
                            <button class="btn btn-danger"
                                    onclick="confirmDelete(<?= (int)$c['id'] ?>, <?= json_encode($c['title']) ?>)">
                                Delete
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Standalone Courses -->
    <div class="section">
        <div class="section-header">
            <span class="section-title">Standalone Courses</span>
            <span style="color: var(--muted); font-size: 12px;"><?= count($standaloneCourses) ?> total</span>
        </div>
        <div class="section-body">
            <p style="color:var(--muted);font-size:13px;margin-bottom:16px;">
                Create courses that don't require an LMS. Students join via invite codes; instructors sign in via magic link.
            </p>

            <!-- Create course form -->
            <form method="POST" action="<?= $adminUrl ?>" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;align-items:flex-end;">
                <input type="hidden" name="action" value="create_standalone_course">
                <div>
                    <label style="display:block;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Course Title</label>
                    <input type="text" name="sa_title" required placeholder="e.g. Introduction to Photography"
                           style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);padding:7px 12px;font-size:13px;width:260px;">
                </div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Short Label</label>
                    <input type="text" name="sa_label" placeholder="e.g. PHOT 101"
                           style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);padding:7px 12px;font-size:13px;width:140px;">
                </div>
                <button type="submit" class="btn btn-success">+ Create Course</button>
            </form>

            <?php if (empty($standaloneCourses)): ?>
                <div class="empty-state">No standalone courses yet.</div>
            <?php else: ?>
            <!-- Set instructor / list courses -->
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Course</th>
                        <th style="text-align:right;">Members</th>
                        <th style="text-align:right;">Created</th>
                        <th>Add / Set Instructor</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($standaloneCourses as $sc): ?>
                    <tr>
                        <td>
                            <span class="course-title"><?= htmlspecialchars($sc['title']) ?></span>
                            <?php if ($sc['label']): ?>
                                <span class="course-meta"><?= htmlspecialchars($sc['label']) ?></span>
                            <?php endif; ?>
                            <span class="course-meta">ID: <?= (int)$sc['id'] ?></span>
                        </td>
                        <td style="text-align:right;font-family:monospace;"><?= (int)$sc['members'] ?></td>
                        <td style="text-align:right;color:var(--muted);font-size:12px;white-space:nowrap;">
                            <?= date('Y-m-d', (int)$sc['created_at']) ?>
                        </td>
                        <td>
                            <form method="POST" action="<?= $adminUrl ?>" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                                <input type="hidden" name="action" value="set_standalone_instructor">
                                <input type="hidden" name="sa_course_id" value="<?= (int)$sc['id'] ?>">
                                <input type="email" name="sa_email" required placeholder="instructor@example.com"
                                       style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);padding:5px 10px;font-size:12px;width:200px;">
                                <input type="text" name="sa_name" placeholder="Full Name (optional)"
                                       style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);padding:5px 10px;font-size:12px;width:160px;">
                                <button type="submit" class="btn btn-default" style="font-size:12px;padding:5px 12px;">Generate Link</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; // courseDetail vs dashboard ?>
</div><!-- /admin-wrap -->

<!-- Confirm delete dialog -->
<div class="confirm-overlay" id="confirm-overlay">
    <div class="confirm-dialog">
        <h2>Delete course?</h2>
        <p>
            This will permanently delete <span class="confirm-name" id="confirm-course-name"></span>
            and <strong>all its data</strong> — posts, comments, boards, peer feedback assignments,
            submissions, reviews, uploaded files, notifications, and enrollments.
            <br><br>This action cannot be undone.
        </p>
        <div class="confirm-actions">
            <button class="btn btn-default" onclick="closeConfirm()">Cancel</button>
            <form id="delete-form" method="POST" action="<?= $adminUrl ?>" style="display:inline;">
                <input type="hidden" name="action" value="delete_course">
                <input type="hidden" name="course_id" id="delete-course-id" value="">
                <button type="submit" class="btn btn-danger">Delete permanently</button>
            </form>
        </div>
    </div>
</div>

<script>
function confirmDelete(id, name) {
    document.getElementById('confirm-course-name').textContent = '"' + name + '"';
    document.getElementById('delete-course-id').value = id;
    document.getElementById('confirm-overlay').classList.add('open');
}
function closeConfirm() {
    document.getElementById('confirm-overlay').classList.remove('open');
}
document.getElementById('confirm-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeConfirm();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeConfirm();
});
</script>

<?php endif; ?>

</body>
</html>
