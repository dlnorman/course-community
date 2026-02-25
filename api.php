<?php
/**
 * Course Community — JSON REST API
 *
 * All responses: Content-Type: application/json
 * Authentication: cc_session cookie (created by lti.php)
 * Errors: { "error": "message" }
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Auth ─────────────────────────────────────────────────────────────────────

function requireAuth(): array {
    $sid = $_COOKIE['cc_session'] ?? '';
    if (!$sid) jsonError(401, 'Not authenticated');

    $session = dbOne(
        'SELECT s.*, u.id AS uid, u.name, u.given_name, u.family_name, u.email, u.picture, u.bio,
                c.id AS cid, c.context_id, c.title AS course_title, c.label AS course_label,
                c.course_type
         FROM sessions s
         JOIN users u   ON u.id = s.user_id
         JOIN courses c ON c.id = s.course_id
         WHERE s.id = ? AND s.expires_at > ?',
        [$sid, time()]
    );

    if (!$session) jsonError(401, 'Session expired. Please relaunch from your course.');

    // Renew session — standalone sessions get 30 days, LTI sessions get 8 hours
    $dur = ($session['course_type'] === 'standalone') ? STANDALONE_SESSION_DURATION : SESSION_DURATION;
    dbRun('UPDATE sessions SET expires_at = ? WHERE id = ?', [time() + $dur, $sid]);

    return $session;
}

function requireInstructor(array $session): void {
    if ($session['role'] !== 'instructor') jsonError(403, 'Instructor role required');
}

function optionalAuth(): ?array {
    $sid = $_COOKIE['cc_session'] ?? '';
    if (!$sid) return null;
    $session = dbOne(
        'SELECT s.*, u.id AS uid, u.name, u.given_name, u.family_name, u.email, u.picture, u.bio,
                c.id AS cid, c.context_id, c.title AS course_title, c.label AS course_label,
                c.course_type
         FROM sessions s
         JOIN users u   ON u.id = s.user_id
         JOIN courses c ON c.id = s.course_id
         WHERE s.id = ? AND s.expires_at > ?',
        [$sid, time()]
    );
    if ($session) {
        $dur = ($session['course_type'] === 'standalone') ? STANDALONE_SESSION_DURATION : SESSION_DURATION;
        dbRun('UPDATE sessions SET expires_at = ? WHERE id = ?', [time() + $dur, $sid]);
    }
    return $session ?: null;
}

// ── Router ────────────────────────────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'];
$path   = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// Strip the app base path so subdirectory installs work (e.g. /course-community/api/x → api/x)
$basePath = trim(parse_url(APP_URL, PHP_URL_PATH) ?? '', '/');
if ($basePath && str_starts_with($path, $basePath)) {
    $path = trim(substr($path, strlen($basePath)), '/');
}

// Strip leading 'api' segment if present
$path = preg_replace('#^api/?#', '', $path);
$segments = explode('/', $path);

function seg(int $i): string { global $segments; return $segments[$i] ?? ''; }
function segId(int $i): int  { return (int)seg($i); }

$body = [];
if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $raw = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true) ?? [];
    // Also accept form-encoded
    if (empty($body) && !empty($_POST)) $body = $_POST;
}

try {
    route($method, $segments, $body);
} catch (Throwable $e) {
    error_log('[API] ' . $e->getMessage());
    jsonError(500, DEV_MODE ? $e->getMessage() : 'Internal server error');
}

function route(string $method, array $seg, array $body): void {
    $s = $seg[0] ?? '';
    $id = (int)($seg[1] ?? 0);
    $sub = $seg[2] ?? '';

    match (true) {
        // Session
        $s === 'session'                               => handleSession($method, $body),

        // My courses (course switcher)
        $s === 'my-courses' && $method === 'GET'       => handleMyCourses(),

        // Course
        $s === 'course'                                => handleCourse($method),

        // Spaces
        $s === 'spaces' && $method === 'GET' && !$id  => handleListSpaces($method),
        $s === 'spaces' && $method === 'POST' && !$id => handleCreateSpace($body),
        $s === 'spaces' && $id && !$sub               => handleSpace($method, $id, $body),

        // Posts
        $s === 'posts' && $method === 'GET' && !$id   => handleListPosts(),
        $s === 'posts' && $method === 'POST' && !$id  => handleCreatePost($body),
        $s === 'posts' && $id && !$sub                => handlePost($method, $id, $body),
        $s === 'posts' && $id && $sub === 'vote'      => handlePostVote($id, $body),
        $s === 'posts' && $id && $sub === 'react'     => handlePostReact($id, $body),
        $s === 'posts' && $id && $sub === 'pin'       => handlePostPin($id),
        $s === 'posts' && $id && $sub === 'feature'   => handlePostFeature($id),
        $s === 'posts' && $id && $sub === 'resolve'   => handlePostResolve($id),
        $s === 'posts' && $id && $sub === 'comments'  => handleComments($method, $id, $body),

        // Comments
        $s === 'comments' && $id && !$sub             => handleComment($method, $id, $body),
        $s === 'comments' && $id && $sub === 'vote'   => handleCommentVote($id, $body),
        $s === 'comments' && $id && $sub === 'answer' => handleCommentAnswer($id),

        // Boards
        $s === 'boards' && !$id                       => handleBoards($method, $body),
        $s === 'boards' && $id && !$sub               => handleBoard($method, $id, $body),
        $s === 'boards' && $id && $sub === 'cards'    => handleAddCard($id, $body),

        // Cards
        $s === 'cards' && $id && !$sub                => handleCard($method, $id, $body),
        $s === 'cards' && $id && $sub === 'vote'      => handleCardVote($id),

        // Poll votes
        $s === 'polls' && $id && $sub === 'vote'      => handlePollVote($id, $body),

        // Members
        $s === 'members'                              => handleMembers(),

        // User profile
        $s === 'users' && $id                         => handleUser($method, $id, $body),
        $s === 'users' && !$id                        => handleMe($method, $body),

        // Notifications
        $s === 'notifications'                        => handleNotifications($method),

        // Analytics / Course Overview
        $s === 'analytics'                            => handleAnalytics(),
        $s === 'course-summary'                       => handleCourseSummary(),

        // Documents
        $s === 'docs' && !$id                                  => handleDocs($method, $body),
        $s === 'docs' && $id && $sub === 'access'              => handleDocAccess($id, $body),
        $s === 'docs' && $id && $sub === 'presence'            => handleDocPresence($id),
        $s === 'docs' && $id && $sub === 'raw'                 => handleDocRaw($id),
        $s === 'docs' && $id && !$sub                          => handleDoc($method, $id, $body),

        // Peer Feedback – sub-routes before catch-all
        $s === 'feedback' && !$id                              => handleFeedbackList($method, $body),
        $s === 'feedback' && $id && $sub === 'publish'         => handleFeedbackTransition($id, 'open'),
        $s === 'feedback' && $id && $sub === 'assign'          => handleFeedbackAssign($id),
        $s === 'feedback' && $id && $sub === 'close'           => handleFeedbackTransition($id, 'closed'),
        $s === 'feedback' && $id && $sub === 'submit'          => handleFeedbackSubmit($method, $id),
        $s === 'feedback' && $id && $sub === 'reviews'         => handleFeedbackMyReviews($id),
        $s === 'feedback' && $id && $sub === 'received'        => handleFeedbackReceived($id),
        $s === 'feedback' && $id && $sub === 'progress'        => handleFeedbackProgress($id),
        $s === 'feedback' && $id && !$sub                      => handleFeedback($method, $id, $body),
        $s === 'reviews'  && $id                               => handleReview($method, $id, $body),
        $s === 'file'     && $id                               => handleFileDownload($id),

        // Flagging — any authenticated user
        $s === 'posts'    && $id && $sub === 'flag'            => handleContentFlag('post', $id, $body),
        $s === 'comments' && $id && $sub === 'flag'            => handleContentFlag('comment', $id, $body),

        // Moderation actions — instructor only
        $s === 'posts'    && $id && $sub === 'moderate'        => handleModerate('post', $id, $body),
        $s === 'comments' && $id && $sub === 'moderate'        => handleModerate('comment', $id, $body),

        // Flag queue + resolve
        $s === 'flags'    && !$id && $method === 'GET'         => handleFlagsList(),
        $s === 'flags'    && $id && $sub === 'resolve'         => handleFlagResolve($id, $body),

        // Moderation log
        $s === 'moderation-log' && $method === 'GET'           => handleModerationLog(),

        // Invite codes (standalone courses)
        $s === 'invite-codes'                                  => handleInviteCodes($method, $id, $body),

        // Pulse Checks
        $s === 'pulse' && !$id && $method === 'GET'            => handlePulseList(),
        $s === 'pulse' && !$id && $method === 'POST'           => handleCreatePulse($body),
        $s === 'pulse' && $id && $sub === 'questions'          => handleAddPulseQuestion($id, $body),
        $s === 'pulse' && $id && $sub === 'results'            => handlePulseAllResults($id),
        $s === 'pulse' && $id && !$sub                         => handlePulse($method, $id, $body),

        // Pulse Questions
        $s === 'pulse-questions' && $id && $sub === 'open'     => handlePulseQuestionOpen($id),
        $s === 'pulse-questions' && $id && $sub === 'reveal'   => handlePulseQuestionReveal($id),
        $s === 'pulse-questions' && $id && $sub === 'respond'  => handlePulseRespond($id, $body),
        $s === 'pulse-questions' && $id && $sub === 'results'  => handleQuestionResults($id),
        $s === 'pulse-questions' && $id && !$sub               => handlePulseQuestion($method, $id, $body),

        // Public pulse (token-based, no auth)
        // /api/pulse-public/{token}/{qid}/respond
        $s === 'pulse-public' && ($seg[1] ?? '') && $sub && ($seg[3] ?? '') => handlePublicPulseAction($seg[1] ?? '', (int)$sub, $seg[3] ?? '', $body),
        $s === 'pulse-public' && ($seg[1] ?? '') && !$sub      => handlePublicPulse($seg[1] ?? ''),

        default => jsonError(404, 'Not found'),
    };
}

// ── Session ───────────────────────────────────────────────────────────────────

function handleSession(string $method, array $body): void {
    if ($method === 'DELETE') {
        $sid = $_COOKIE['cc_session'] ?? '';
        if ($sid) {
            dbRun('DELETE FROM sessions WHERE id = ?', [$sid]);
            setcookie('cc_session', '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        json(['ok' => true]);
        return;
    }

    if ($method === 'POST') {
        // Course switcher — create a new session for a different enrolled course
        $s        = requireAuth();
        $courseId = (int)($body['course_id'] ?? 0);
        if (!$courseId) jsonError(400, 'course_id required');

        $enrollment = dbOne(
            'SELECT e.role, c.course_type FROM enrollments e
             JOIN courses c ON c.id = e.course_id
             WHERE e.user_id = ? AND e.course_id = ?',
            [$s['uid'], $courseId]
        );
        if (!$enrollment) jsonError(403, 'Not enrolled in that course');

        $dur = ($enrollment['course_type'] === 'standalone') ? STANDALONE_SESSION_DURATION : SESSION_DURATION;
        $newSid = bin2hex(random_bytes(32));
        dbRun(
            'INSERT INTO sessions (id, user_id, course_id, role, expires_at) VALUES (?, ?, ?, ?, ?)',
            [$newSid, $s['uid'], $courseId, $enrollment['role'], time() + $dur]
        );

        $cookiePath = rtrim(parse_url(APP_URL, PHP_URL_PATH) ?: '/', '/') . '/';
        setcookie('cc_session', $newSid, [
            'expires'  => time() + $dur,
            'path'     => $cookiePath ?: '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        json(['ok' => true]);
        return;
    }

    $s = requireAuth();
    json(['user' => userPayload($s), 'course' => coursePayload($s), 'role' => $s['role']]);
}

function handleMyCourses(): void {
    $s = requireAuth();
    $courses = dbAll(
        'SELECT c.id, c.title, c.label, c.course_type, e.role
         FROM enrollments e
         JOIN courses c ON c.id = e.course_id
         WHERE e.user_id = ?
         ORDER BY c.title ASC',
        [$s['uid']]
    );
    json($courses);
}

// ── Course ────────────────────────────────────────────────────────────────────

function handleCourse(string $method): void {
    $s = requireAuth();
    $spaces = dbAll(
        'SELECT * FROM spaces WHERE course_id = ? ORDER BY sort_order ASC, id ASC',
        [$s['cid']]
    );
    $memberCount = dbOne(
        'SELECT COUNT(*) AS n FROM enrollments WHERE course_id = ?',
        [$s['cid']]
    );
    json([
        'course'  => coursePayload($s),
        'spaces'  => $spaces,
        'members' => (int)$memberCount['n'],
    ]);
}

// ── Spaces ────────────────────────────────────────────────────────────────────

function handleListSpaces(string $method): void {
    $s = requireAuth();
    $spaces = dbAll('SELECT * FROM spaces WHERE course_id = ? ORDER BY sort_order', [$s['cid']]);
    json($spaces);
}

function handleCreateSpace(array $body): void {
    $s = requireAuth();
    requireInstructor($s);

    $name = trim($body['name'] ?? '');
    if (!$name) jsonError(400, 'Name is required');

    $maxOrder = dbOne('SELECT MAX(sort_order) AS m FROM spaces WHERE course_id = ?', [$s['cid']]);

    $id = dbExec(
        'INSERT INTO spaces (course_id, name, description, icon, type, color, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
            $s['cid'], $name,
            trim($body['description'] ?? ''),
            trim($body['icon'] ?? '💬'),
            trim($body['type'] ?? 'discussion'),
            trim($body['color'] ?? '#4A6FA5'),
            ((int)($maxOrder['m'] ?? 0)) + 1,
        ]
    );
    json(dbOne('SELECT * FROM spaces WHERE id = ?', [$id]), 201);
}

function handleSpace(string $method, int $id, array $body): void {
    $s = requireAuth();
    $space = dbOne('SELECT * FROM spaces WHERE id = ? AND course_id = ?', [$id, $s['cid']]);
    if (!$space) jsonError(404, 'Space not found');

    if ($method === 'GET') {
        json($space);
    } elseif ($method === 'PUT') {
        requireInstructor($s);
        dbRun(
            'UPDATE spaces SET name=?, description=?, icon=?, color=? WHERE id=?',
            [
                trim($body['name'] ?? $space['name']),
                trim($body['description'] ?? $space['description']),
                trim($body['icon'] ?? $space['icon']),
                trim($body['color'] ?? $space['color']),
                $id,
            ]
        );
        json(dbOne('SELECT * FROM spaces WHERE id = ?', [$id]));
    } elseif ($method === 'DELETE') {
        requireInstructor($s);
        if ($space['is_default']) jsonError(400, 'Cannot delete a default space');
        dbRun('DELETE FROM spaces WHERE id = ?', [$id]);
        json(['ok' => true]);
    } else {
        jsonError(405, 'Method not allowed');
    }
}

// ── Posts ─────────────────────────────────────────────────────────────────────

function handleListPosts(): void {
    $s = requireAuth();

    $spaceId  = (int)($_GET['space_id'] ?? 0);
    $type     = trim($_GET['type']  ?? '');
    $sort     = trim($_GET['sort']  ?? 'recent');
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $limit    = 20;
    $offset   = ($page - 1) * $limit;

    $where = ['p.course_id = ?'];
    $params = [$s['cid']];

    if ($spaceId) {
        // Verify the space belongs to this course
        $space = dbOne('SELECT id FROM spaces WHERE id = ? AND course_id = ?', [$spaceId, $s['cid']]);
        if (!$space) jsonError(404, 'Space not found');
        $where[] = 'p.space_id = ?';
        $params[] = $spaceId;
    }
    if ($type) {
        $where[] = 'p.type = ?';
        $params[] = $type;
    }

    $orderBy = match ($sort) {
        'top'    => 'p.vote_count DESC, p.created_at DESC',
        'unanswered' => 'p.is_resolved ASC, p.vote_count DESC, p.created_at DESC',
        default  => 'p.is_pinned DESC, p.is_featured DESC, p.created_at DESC',
    };

    $whereClause = implode(' AND ', $where);

    $total = dbOne("SELECT COUNT(*) AS n FROM posts p WHERE $whereClause", $params);

    $posts = dbAll(
        "SELECT p.*,
                u.name AS author_name, u.given_name AS author_given, u.picture AS author_pic,
                sp.name AS space_name, sp.icon AS space_icon, sp.color AS space_color,
                (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count
         FROM posts p
         JOIN users u  ON u.id = p.author_id
         JOIN spaces sp ON sp.id = p.space_id
         WHERE $whereClause
         ORDER BY $orderBy
         LIMIT ? OFFSET ?",
        array_merge($params, [$limit, $offset])
    );

    // Add user's own vote and reactions; apply mod_status visibility
    foreach ($posts as &$post) {
        $post['meta'] = json_decode($post['meta_json'] ?? '{}', true);
        unset($post['meta_json']);
        $post['user_vote'] = getUserVote($s['uid'], 'post', $post['id']);
        $post['reactions'] = getReactionCounts('post', $post['id'], $s['uid']);
        if ($post['type'] === 'poll') {
            $post['poll_results'] = getPollResults($post['id'], $s['uid']);
        }
        applyModVisibility($post, $s);
    }
    unset($post);

    json(['posts' => $posts, 'total' => (int)$total['n'], 'page' => $page, 'pages' => (int)ceil($total['n'] / $limit)]);
}

function handleCreatePost(array $body): void {
    $s = requireAuth();

    $spaceId = (int)($body['space_id'] ?? 0);
    $type    = trim($body['type'] ?? 'discussion');
    $title   = trim($body['title'] ?? '');
    $content = trim($body['content'] ?? '');

    if (!$spaceId) jsonError(400, 'space_id is required');
    if (!$title)   jsonError(400, 'title is required');

    $space = dbOne('SELECT * FROM spaces WHERE id = ? AND course_id = ?', [$spaceId, $s['cid']]);
    if (!$space) jsonError(404, 'Space not found');

    // Announcements: instructor only
    if ($space['type'] === 'announcement' && $s['role'] !== 'instructor') {
        jsonError(403, 'Only instructors can post in Announcements');
    }

    $validTypes = ['discussion', 'question', 'resource', 'kudos', 'reflection', 'poll'];
    if (!in_array($type, $validTypes)) jsonError(400, "Invalid post type: $type");

    $meta = $body['meta'] ?? [];

    // Validate polls
    if ($type === 'poll') {
        $options = $meta['options'] ?? [];
        if (count($options) < 2 || count($options) > 8) jsonError(400, 'Polls need 2–8 options');
    }

    $id = dbExec(
        'INSERT INTO posts (space_id, course_id, author_id, type, title, content, meta_json)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$spaceId, $s['cid'], $s['uid'], $type, $title, $content, json_encode($meta)]
    );

    // Notify others who are active in the space (simplified: notify all enrolled)
    // In production, you'd implement subscription preferences
    $post = dbOne(
        'SELECT p.*, u.name AS author_name FROM posts p JOIN users u ON u.id = p.author_id WHERE p.id = ?',
        [$id]
    );
    $post['meta'] = $meta;

    json($post, 201);
}

function handlePost(string $method, int $id, array $body): void {
    $s = requireAuth();
    $post = dbOne('SELECT * FROM posts WHERE id = ? AND course_id = ?', [$id, $s['cid']]);
    if (!$post) jsonError(404, 'Post not found');

    if ($method === 'GET') {
        // Increment view
        dbRun('UPDATE posts SET view_count = view_count + 1 WHERE id = ?', [$id]);

        $post['meta'] = json_decode($post['meta_json'] ?? '{}', true);
        unset($post['meta_json']);

        $author = dbOne('SELECT id, name, given_name, family_name, picture, bio FROM users WHERE id = ?', [$post['author_id']]);
        $post['author'] = $author;
        $post['user_vote'] = getUserVote($s['uid'], 'post', $id);
        $post['reactions'] = getReactionCounts('post', $id, $s['uid']);

        if ($post['type'] === 'poll') {
            $post['poll_results'] = getPollResults($id, $s['uid']);
        }

        // Get comments with nested replies
        $comments = dbAll(
            'SELECT c.*, u.name AS author_name, u.given_name AS author_given,
                    u.picture AS author_pic, u.id AS author_uid
             FROM comments c
             JOIN users u ON u.id = c.author_id
             WHERE c.post_id = ? AND c.parent_id IS NULL
             ORDER BY c.is_answer DESC, c.vote_count DESC, c.created_at ASC',
            [$id]
        );

        foreach ($comments as &$comment) {
            $comment['vote'] = getUserVote($s['uid'], 'comment', $comment['id']);
            $comment['reactions'] = getReactionCounts('comment', $comment['id'], $s['uid']);
            $comment['replies'] = getCommentReplies($comment['id'], $s['uid']);
            applyModVisibility($comment, $s);
        }
        unset($comment);

        $post['comments'] = $comments;
        applyModVisibility($post, $s);

        json($post);

    } elseif ($method === 'PUT') {
        if ($post['author_id'] != $s['uid'] && $s['role'] !== 'instructor') {
            jsonError(403, 'Not your post');
        }
        $title   = trim($body['title']   ?? $post['title']);
        $content = trim($body['content'] ?? $post['content']);
        dbRun('UPDATE posts SET title=?, content=?, updated_at=? WHERE id=?', [$title, $content, time(), $id]);
        json(dbOne('SELECT * FROM posts WHERE id = ?', [$id]));

    } elseif ($method === 'DELETE') {
        if ($post['author_id'] != $s['uid'] && $s['role'] !== 'instructor') {
            jsonError(403, 'Not your post');
        }
        dbRun('DELETE FROM posts WHERE id = ?', [$id]);
        json(['ok' => true]);
    }
}

function handlePostVote(int $postId, array $body): void {
    $s = requireAuth();
    $post = dbOne('SELECT * FROM posts WHERE id = ? AND course_id = ?', [$postId, $s['cid']]);
    if (!$post) jsonError(404, 'Post not found');

    $value = (int)($body['value'] ?? 1);
    if (!in_array($value, [-1, 0, 1])) jsonError(400, 'value must be -1, 0, or 1');

    if ($value === 0) {
        dbRun('DELETE FROM votes WHERE user_id=? AND target_type=? AND target_id=?', [$s['uid'], 'post', $postId]);
    } else {
        dbRun(
            'INSERT INTO votes (user_id, target_type, target_id, value) VALUES (?, ?, ?, ?)
             ON CONFLICT(user_id, target_type, target_id) DO UPDATE SET value=excluded.value',
            [$s['uid'], 'post', $postId, $value]
        );
    }

    // Recalculate
    $total = dbOne('SELECT COALESCE(SUM(value),0) AS t FROM votes WHERE target_type=? AND target_id=?', ['post', $postId]);
    dbRun('UPDATE posts SET vote_count=? WHERE id=?', [(int)$total['t'], $postId]);

    json(['vote_count' => (int)$total['t'], 'user_vote' => $value]);
}

function handlePostReact(int $postId, array $body): void {
    $s = requireAuth();
    $post = dbOne('SELECT id FROM posts WHERE id = ? AND course_id = ?', [$postId, $s['cid']]);
    if (!$post) jsonError(404, 'Post not found');

    $emoji = trim($body['emoji'] ?? '');
    $allowed = ['👍','❤️','🔥','💡','🤔','😮','🎉','👏','⭐','🙏'];
    if (!in_array($emoji, $allowed)) jsonError(400, 'Invalid emoji');

    // Toggle
    $existing = dbOne(
        'SELECT id FROM reactions WHERE user_id=? AND target_type=? AND target_id=? AND emoji=?',
        [$s['uid'], 'post', $postId, $emoji]
    );
    if ($existing) {
        dbRun('DELETE FROM reactions WHERE id=?', [$existing['id']]);
        $added = false;
    } else {
        dbExec('INSERT INTO reactions (user_id, target_type, target_id, emoji) VALUES (?, ?, ?, ?)',
               [$s['uid'], 'post', $postId, $emoji]);
        $added = true;
    }

    json(['reactions' => getReactionCounts('post', $postId, $s['uid']), 'added' => $added]);
}

function handlePostPin(int $postId): void {
    $s = requireAuth();
    requireInstructor($s);
    $post = dbOne('SELECT * FROM posts WHERE id = ? AND course_id = ?', [$postId, $s['cid']]);
    if (!$post) jsonError(404, 'Not found');
    dbRun('UPDATE posts SET is_pinned = NOT is_pinned WHERE id = ?', [$postId]);
    json(['is_pinned' => !$post['is_pinned']]);
}

function handlePostFeature(int $postId): void {
    $s = requireAuth();
    requireInstructor($s);
    $post = dbOne('SELECT * FROM posts WHERE id = ? AND course_id = ?', [$postId, $s['cid']]);
    if (!$post) jsonError(404, 'Not found');
    dbRun('UPDATE posts SET is_featured = NOT is_featured WHERE id = ?', [$postId]);
    json(['is_featured' => !$post['is_featured']]);
}

function handlePostResolve(int $postId): void {
    $s = requireAuth();
    $post = dbOne('SELECT * FROM posts WHERE id = ? AND course_id = ?', [$postId, $s['cid']]);
    if (!$post) jsonError(404, 'Not found');
    if ($post['author_id'] != $s['uid'] && $s['role'] !== 'instructor') jsonError(403, 'Forbidden');
    dbRun('UPDATE posts SET is_resolved = NOT is_resolved WHERE id = ?', [$postId]);
    json(['is_resolved' => !$post['is_resolved']]);
}

// ── Comments ──────────────────────────────────────────────────────────────────

function handleComments(string $method, int $postId, array $body): void {
    $s = requireAuth();
    $post = dbOne('SELECT * FROM posts WHERE id = ? AND course_id = ?', [$postId, $s['cid']]);
    if (!$post) jsonError(404, 'Post not found');

    if ($method === 'GET') {
        $comments = dbAll(
            'SELECT c.*, u.name AS author_name, u.given_name AS author_given, u.picture AS author_pic
             FROM comments c JOIN users u ON u.id = c.author_id
             WHERE c.post_id = ? AND c.parent_id IS NULL
             ORDER BY c.is_answer DESC, c.vote_count DESC, c.created_at ASC',
            [$postId]
        );
        foreach ($comments as &$c) {
            $c['replies'] = getCommentReplies($c['id'], $s['uid']);
            $c['vote'] = getUserVote($s['uid'], 'comment', $c['id']);
        }
        json($comments);

    } elseif ($method === 'POST') {
        $content  = trim($body['content'] ?? '');
        $parentId = (int)($body['parent_id'] ?? 0) ?: null;

        if (!$content) jsonError(400, 'Content is required');

        $isInstructorNote = ($s['role'] === 'instructor' && !empty($body['is_instructor_note'])) ? 1 : 0;

        $cid = dbExec(
            'INSERT INTO comments (post_id, parent_id, author_id, content, is_instructor_note)
             VALUES (?, ?, ?, ?, ?)',
            [$postId, $parentId, $s['uid'], $content, $isInstructorNote]
        );

        // Notify post author
        if ($post['author_id'] != $s['uid']) {
            notify(
                $post['author_id'],
                $s['cid'],
                'comment',
                $s['name'] . ' replied to your post "' . mb_substr($post['title'], 0, 50) . '"',
                '/post/' . $postId
            );
        }

        // Notify parent comment author
        if ($parentId) {
            $parent = dbOne('SELECT author_id FROM comments WHERE id = ?', [$parentId]);
            if ($parent && $parent['author_id'] != $s['uid']) {
                notify($parent['author_id'], $s['cid'], 'reply', $s['name'] . ' replied to your comment', '/post/' . $postId);
            }
        }

        $comment = dbOne(
            'SELECT c.*, u.name AS author_name, u.given_name AS author_given, u.picture AS author_pic
             FROM comments c JOIN users u ON u.id = c.author_id WHERE c.id = ?',
            [$cid]
        );
        $comment['replies'] = [];
        $comment['vote'] = null;
        json($comment, 201);
    }
}

function handleComment(string $method, int $id, array $body): void {
    $s = requireAuth();
    $comment = dbOne('SELECT c.*, p.course_id FROM comments c JOIN posts p ON p.id = c.post_id WHERE c.id = ?', [$id]);
    if (!$comment || $comment['course_id'] != $s['cid']) jsonError(404, 'Comment not found');

    if ($method === 'PUT') {
        if ($comment['author_id'] != $s['uid'] && $s['role'] !== 'instructor') jsonError(403, 'Forbidden');
        $content = trim($body['content'] ?? $comment['content']);
        dbRun('UPDATE comments SET content=?, updated_at=? WHERE id=?', [$content, time(), $id]);
        json(dbOne('SELECT * FROM comments WHERE id=?', [$id]));
    } elseif ($method === 'DELETE') {
        if ($comment['author_id'] != $s['uid'] && $s['role'] !== 'instructor') jsonError(403, 'Forbidden');
        dbRun('DELETE FROM comments WHERE id=?', [$id]);
        json(['ok' => true]);
    }
}

function handleCommentVote(int $id, array $body): void {
    $s = requireAuth();
    $comment = dbOne('SELECT c.*, p.course_id FROM comments c JOIN posts p ON p.id=c.post_id WHERE c.id=?', [$id]);
    if (!$comment || $comment['course_id'] != $s['cid']) jsonError(404, 'Not found');

    $value = (int)($body['value'] ?? 1);
    if ($value === 0) {
        dbRun('DELETE FROM votes WHERE user_id=? AND target_type=? AND target_id=?', [$s['uid'], 'comment', $id]);
    } else {
        dbRun(
            'INSERT INTO votes (user_id, target_type, target_id, value) VALUES (?, ?, ?, ?)
             ON CONFLICT(user_id, target_type, target_id) DO UPDATE SET value=excluded.value',
            [$s['uid'], 'comment', $id, $value]
        );
    }
    $total = dbOne('SELECT COALESCE(SUM(value),0) AS t FROM votes WHERE target_type=? AND target_id=?', ['comment', $id]);
    dbRun('UPDATE comments SET vote_count=? WHERE id=?', [(int)$total['t'], $id]);
    json(['vote_count' => (int)$total['t'], 'user_vote' => $value]);
}

function handleCommentAnswer(int $id): void {
    $s = requireAuth();
    $comment = dbOne('SELECT c.*, p.course_id, p.author_id AS post_author FROM comments c JOIN posts p ON p.id=c.post_id WHERE c.id=?', [$id]);
    if (!$comment || $comment['course_id'] != $s['cid']) jsonError(404, 'Not found');
    if ($comment['post_author'] != $s['uid'] && $s['role'] !== 'instructor') jsonError(403, 'Only the post author or instructor can mark answers');

    // Toggle answer on this comment, clear others in the post
    $newVal = $comment['is_answer'] ? 0 : 1;
    if ($newVal) dbRun('UPDATE comments SET is_answer=0 WHERE post_id=?', [$comment['post_id']]);
    dbRun('UPDATE comments SET is_answer=? WHERE id=?', [$newVal, $id]);

    // Mark post resolved if an answer is set
    if ($newVal) dbRun('UPDATE posts SET is_resolved=1 WHERE id=?', [$comment['post_id']]);

    json(['is_answer' => (bool)$newVal]);
}

// ── Boards (Collaboration) ────────────────────────────────────────────────────

function handleBoards(string $method, array $body): void {
    $s = requireAuth();

    if ($method === 'GET') {
        $boards = dbAll(
            'SELECT b.*, u.name AS creator_name,
                    (SELECT COUNT(*) FROM board_cards bc WHERE bc.board_id = b.id) AS card_count
             FROM boards b JOIN users u ON u.id = b.created_by
             WHERE b.course_id = ? ORDER BY b.created_at DESC',
            [$s['cid']]
        );
        json($boards);
    } elseif ($method === 'POST') {
        $title = trim($body['title'] ?? '');
        if (!$title) jsonError(400, 'Title is required');

        $id = dbExec(
            'INSERT INTO boards (course_id, title, description, prompt, created_by) VALUES (?, ?, ?, ?, ?)',
            [$s['cid'], $title, trim($body['description'] ?? ''), trim($body['prompt'] ?? ''), $s['uid']]
        );
        json(dbOne('SELECT * FROM boards WHERE id=?', [$id]), 201);
    }
}

function handleBoard(string $method, int $id, array $body): void {
    $s = requireAuth();
    $board = dbOne('SELECT * FROM boards WHERE id=? AND course_id=?', [$id, $s['cid']]);
    if (!$board) jsonError(404, 'Board not found');

    if ($method === 'GET') {
        $cards = dbAll(
            'SELECT bc.*, u.name AS author_name, u.given_name AS author_given, u.picture AS author_pic,
                    (SELECT COUNT(*) FROM votes v WHERE v.target_type=\'card\' AND v.target_id=bc.id) AS vote_count,
                    (SELECT value FROM votes v WHERE v.target_type=\'card\' AND v.target_id=bc.id AND v.user_id=?) AS user_vote
             FROM board_cards bc JOIN users u ON u.id=bc.author_id
             WHERE bc.board_id=? ORDER BY bc.created_at ASC',
            [$s['uid'], $id]
        );
        $board['cards'] = $cards;
        json($board);
    } elseif ($method === 'PUT') {
        if ($board['created_by'] != $s['uid'] && $s['role'] !== 'instructor') jsonError(403, 'Forbidden');
        dbRun('UPDATE boards SET title=?, description=?, prompt=? WHERE id=?',
              [trim($body['title'] ?? $board['title']),
               trim($body['description'] ?? $board['description']),
               trim($body['prompt'] ?? $board['prompt']), $id]);
        json(dbOne('SELECT * FROM boards WHERE id=?', [$id]));
    } elseif ($method === 'DELETE') {
        if ($board['created_by'] != $s['uid'] && $s['role'] !== 'instructor') jsonError(403, 'Forbidden');
        dbRun('DELETE FROM board_cards WHERE board_id=?', [$id]);
        dbRun('DELETE FROM boards WHERE id=?', [$id]);
        json(['ok' => true]);
    }
}

function handleAddCard(int $boardId, array $body): void {
    $s = requireAuth();
    $board = dbOne('SELECT * FROM boards WHERE id=? AND course_id=?', [$boardId, $s['cid']]);
    if (!$board) jsonError(404, 'Board not found');

    $content = trim($body['content'] ?? '');
    if (!$content) jsonError(400, 'Content is required');

    $colors = ['#FFF9C4','#FFE0B2','#F8BBD0','#DCEDC8','#B3E5FC','#E1BEE7','#B2DFDB'];
    $color  = trim($body['color'] ?? $colors[array_rand($colors)]);

    $id = dbExec(
        'INSERT INTO board_cards (board_id, author_id, content, color, pos_x, pos_y) VALUES (?, ?, ?, ?, ?, ?)',
        [$boardId, $s['uid'], $content, $color,
         (float)($body['pos_x'] ?? rand(50, 500)),
         (float)($body['pos_y'] ?? rand(50, 400))]
    );

    $card = dbOne(
        'SELECT bc.*, u.name AS author_name, u.given_name AS author_given, u.picture AS author_pic
         FROM board_cards bc JOIN users u ON u.id=bc.author_id WHERE bc.id=?',
        [$id]
    );
    json($card, 201);
}

function handleCard(string $method, int $id, array $body): void {
    $s = requireAuth();
    $card = dbOne(
        'SELECT bc.*, b.course_id FROM board_cards bc JOIN boards b ON b.id=bc.board_id WHERE bc.id=?',
        [$id]
    );
    if (!$card || $card['course_id'] != $s['cid']) jsonError(404, 'Card not found');

    if ($method === 'PUT') {
        if ($card['author_id'] != $s['uid'] && $s['role'] !== 'instructor') jsonError(403, 'Forbidden');
        dbRun('UPDATE board_cards SET content=?, color=?, pos_x=?, pos_y=? WHERE id=?', [
            trim($body['content'] ?? $card['content']),
            trim($body['color'] ?? $card['color']),
            (float)($body['pos_x'] ?? $card['pos_x']),
            (float)($body['pos_y'] ?? $card['pos_y']),
            $id,
        ]);
        json(dbOne('SELECT * FROM board_cards WHERE id=?', [$id]));
    } elseif ($method === 'DELETE') {
        if ($card['author_id'] != $s['uid'] && $s['role'] !== 'instructor') jsonError(403, 'Forbidden');
        dbRun('DELETE FROM board_cards WHERE id=?', [$id]);
        json(['ok' => true]);
    }
}

function handleCardVote(int $id): void {
    $s = requireAuth();
    $card = dbOne('SELECT bc.*, b.course_id FROM board_cards bc JOIN boards b ON b.id=bc.board_id WHERE bc.id=?', [$id]);
    if (!$card || $card['course_id'] != $s['cid']) jsonError(404, 'Not found');

    $existing = dbOne('SELECT id FROM votes WHERE user_id=? AND target_type=\'card\' AND target_id=?', [$s['uid'], $id]);
    if ($existing) {
        dbRun('DELETE FROM votes WHERE id=?', [$existing['id']]);
        $voted = false;
    } else {
        dbExec('INSERT INTO votes (user_id, target_type, target_id, value) VALUES (?, \'card\', ?, 1)', [$s['uid'], $id]);
        $voted = true;
    }
    $count = dbOne('SELECT COUNT(*) AS n FROM votes WHERE target_type=\'card\' AND target_id=?', [$id]);
    json(['votes' => (int)$count['n'], 'voted' => $voted]);
}

// ── Polls ─────────────────────────────────────────────────────────────────────

function handlePollVote(int $postId, array $body): void {
    $s = requireAuth();
    $post = dbOne('SELECT * FROM posts WHERE id=? AND course_id=? AND type=\'poll\'', [$postId, $s['cid']]);
    if (!$post) jsonError(404, 'Poll not found');

    $meta    = json_decode($post['meta_json'], true);
    $options = $meta['options'] ?? [];
    $idx     = (int)($body['option'] ?? -1);

    if ($idx < 0 || $idx >= count($options)) jsonError(400, 'Invalid option index');

    $existing = dbOne('SELECT id FROM poll_votes WHERE post_id=? AND user_id=?', [$postId, $s['uid']]);
    if ($existing) {
        dbRun('UPDATE poll_votes SET option_idx=? WHERE id=?', [$idx, $existing['id']]);
    } else {
        dbExec('INSERT INTO poll_votes (post_id, user_id, option_idx) VALUES (?, ?, ?)', [$postId, $s['uid'], $idx]);
    }

    json(['results' => getPollResults($postId, $s['uid'])]);
}

// ── Members ───────────────────────────────────────────────────────────────────

function handleMembers(): void {
    $s = requireAuth();
    $members = dbAll(
        'SELECT u.id, u.name, u.given_name, u.picture, u.bio, e.role, e.last_seen,
                (SELECT COUNT(*) FROM posts p WHERE p.author_id=u.id AND p.course_id=?) AS post_count,
                (SELECT COUNT(*) FROM comments c JOIN posts p ON p.id=c.post_id WHERE c.author_id=u.id AND p.course_id=?) AS comment_count
         FROM users u
         JOIN enrollments e ON e.user_id=u.id AND e.course_id=?
         ORDER BY e.role ASC, u.name ASC',
        [$s['cid'], $s['cid'], $s['cid']]
    );
    json($members);
}

// ── Users ─────────────────────────────────────────────────────────────────────

function handleUser(string $method, int $id, array $body): void {
    $s = requireAuth();
    $user = dbOne('SELECT u.*, e.role, e.last_seen FROM users u JOIN enrollments e ON e.user_id=u.id WHERE u.id=? AND e.course_id=?', [$id, $s['cid']]);
    if (!$user) jsonError(404, 'User not found in this course');

    if ($method === 'GET') {
        $user['post_count'] = dbOne('SELECT COUNT(*) AS n FROM posts WHERE author_id=? AND course_id=?', [$id, $s['cid']])['n'];
        $user['comment_count'] = dbOne('SELECT COUNT(*) AS n FROM comments c JOIN posts p ON p.id=c.post_id WHERE c.author_id=? AND p.course_id=?', [$id, $s['cid']])['n'];
        $user['recent_posts'] = dbAll(
            'SELECT p.id, p.type, p.title, p.created_at, sp.name AS space_name, sp.icon AS space_icon
             FROM posts p JOIN spaces sp ON sp.id=p.space_id
             WHERE p.author_id=? AND p.course_id=? ORDER BY p.created_at DESC LIMIT 5',
            [$id, $s['cid']]
        );
        json($user);
    } elseif ($method === 'PUT' && $id === $s['uid']) {
        $bio = trim($body['bio'] ?? $user['bio']);
        dbRun('UPDATE users SET bio=? WHERE id=?', [$bio, $id]);
        json(dbOne('SELECT * FROM users WHERE id=?', [$id]));
    }
}

function handleMe(string $method, array $body): void {
    $s = requireAuth();
    handleUser($method, $s['uid'], $body);
}

// ── Notifications ─────────────────────────────────────────────────────────────

function handleNotifications(string $method): void {
    $s = requireAuth();

    if ($method === 'GET') {
        $notifs = dbAll(
            'SELECT * FROM notifications WHERE user_id=? AND course_id=? ORDER BY created_at DESC LIMIT 20',
            [$s['uid'], $s['cid']]
        );
        $unread = dbOne(
            'SELECT COUNT(*) AS n FROM notifications WHERE user_id=? AND course_id=? AND is_read=0',
            [$s['uid'], $s['cid']]
        );
        json(['notifications' => $notifs, 'unread' => (int)$unread['n']]);
    } elseif ($method === 'PUT') {
        dbRun('UPDATE notifications SET is_read=1 WHERE user_id=? AND course_id=?', [$s['uid'], $s['cid']]);
        json(['ok' => true]);
    }
}

// ── Analytics ─────────────────────────────────────────────────────────────────

function handleAnalytics(): void {
    $s = requireAuth();
    requireInstructor($s);
    $cid = $s['cid'];

    $totalMembers   = dbOne('SELECT COUNT(*) AS n FROM enrollments WHERE course_id=?', [$cid]);
    $totalPosts     = dbOne('SELECT COUNT(*) AS n FROM posts WHERE course_id=?', [$cid]);
    $totalComments  = dbOne('SELECT COUNT(*) AS n FROM comments c JOIN posts p ON p.id=c.post_id WHERE p.course_id=?', [$cid]);
    $unresolvedQs   = dbOne('SELECT COUNT(*) AS n FROM posts WHERE course_id=? AND type=\'question\' AND is_resolved=0', [$cid]);
    $postsThisWeek  = dbOne('SELECT COUNT(*) AS n FROM posts WHERE course_id=? AND created_at > ?', [$cid, time() - 604800]);
    $totalDocs      = dbOne('SELECT COUNT(*) AS n FROM documents WHERE course_id=?', [$cid]);
    $publishedDocs  = dbOne('SELECT COUNT(*) AS n FROM documents WHERE course_id=? AND access_level > 0', [$cid]);

    // Boards
    $totalBoards    = dbOne('SELECT COUNT(*) AS n FROM boards WHERE course_id=?', [$cid]);
    $totalCards     = dbOne('SELECT COUNT(*) AS n FROM board_cards bc JOIN boards b ON b.id=bc.board_id WHERE b.course_id=?', [$cid]);

    // Peer Feedback
    $totalPfAssign  = dbOne('SELECT COUNT(*) AS n FROM pf_assignments WHERE course_id=?', [$cid]);
    $activePfAssign = dbOne("SELECT COUNT(*) AS n FROM pf_assignments WHERE course_id=? AND status IN ('open','reviewing')", [$cid]);
    $totalPfSubs    = dbOne('SELECT COUNT(*) AS n FROM pf_submissions ps JOIN pf_assignments pa ON pa.id=ps.assignment_id WHERE pa.course_id=?', [$cid]);

    // Pulse Checks
    $totalPulse     = dbOne('SELECT COUNT(*) AS n FROM pulse_checks WHERE course_id=?', [$cid]);
    $activePulse    = dbOne("SELECT COUNT(*) AS n FROM pulse_checks WHERE course_id=? AND status='active'", [$cid]);
    $totalPulseResp = dbOne('SELECT COUNT(*) AS n FROM pulse_responses pr JOIN pulse_questions pq ON pq.id=pr.question_id JOIN pulse_checks pc ON pc.id=pq.check_id WHERE pc.course_id=?', [$cid]);

    // Top contributors
    $topContributors = dbAll(
        'SELECT u.id, u.name, u.picture,
                (SELECT COUNT(*) FROM posts p WHERE p.author_id=u.id AND p.course_id=?) AS posts,
                (SELECT COUNT(*) FROM comments c JOIN posts p ON p.id=c.post_id WHERE c.author_id=u.id AND p.course_id=?) AS comments,
                (SELECT COUNT(*) FROM posts p WHERE p.author_id=u.id AND p.course_id=?) +
                (SELECT COUNT(*) FROM comments c JOIN posts p ON p.id=c.post_id WHERE c.author_id=u.id AND p.course_id=?) AS total
         FROM users u JOIN enrollments e ON e.user_id=u.id AND e.course_id=? AND e.role=\'student\'
         ORDER BY total DESC LIMIT 10',
        [$cid, $cid, $cid, $cid, $cid]
    );

    // Activity by day (last 14 days)
    $activityByDay = dbAll(
        "SELECT date(created_at, 'unixepoch') AS day, COUNT(*) AS n
         FROM posts WHERE course_id=? AND created_at > ?
         GROUP BY day ORDER BY day",
        [$cid, time() - 1209600]
    );

    // Space activity
    $spaceActivity = dbAll(
        'SELECT s.name, s.icon, s.color, COUNT(p.id) AS post_count
         FROM spaces s LEFT JOIN posts p ON p.space_id=s.id
         WHERE s.course_id=? GROUP BY s.id ORDER BY post_count DESC',
        [$cid]
    );

    // Silent students (enrolled but no posts or comments in last 2 weeks)
    $silent = dbAll(
        'SELECT u.id, u.name, u.picture, e.last_seen
         FROM users u JOIN enrollments e ON e.user_id=u.id AND e.course_id=? AND e.role=\'student\'
         WHERE u.id NOT IN (
             SELECT DISTINCT p.author_id FROM posts p WHERE p.course_id=? AND p.created_at > ?
         ) AND u.id NOT IN (
             SELECT DISTINCT c.author_id FROM comments c JOIN posts p ON p.id=c.post_id WHERE p.course_id=? AND c.created_at > ?
         )
         ORDER BY e.last_seen IS NULL, e.last_seen DESC LIMIT 20',
        [$cid, time() - 1209600, $cid, time() - 1209600]
    );

    json([
        'total_members'     => (int)$totalMembers['n'],
        'total_posts'       => (int)$totalPosts['n'],
        'total_comments'    => (int)$totalComments['n'],
        'unresolved_questions' => (int)$unresolvedQs['n'],
        'posts_this_week'   => (int)$postsThisWeek['n'],
        'total_docs'        => (int)$totalDocs['n'],
        'published_docs'    => (int)$publishedDocs['n'],
        'top_contributors'  => $topContributors,
        'activity_by_day'   => $activityByDay,
        'space_activity'    => $spaceActivity,
        'silent_students'   => $silent,
        'total_boards'      => (int)$totalBoards['n'],
        'total_cards'       => (int)$totalCards['n'],
        'total_pf_assign'   => (int)$totalPfAssign['n'],
        'active_pf_assign'  => (int)$activePfAssign['n'],
        'total_pf_subs'     => (int)$totalPfSubs['n'],
        'total_pulse'       => (int)$totalPulse['n'],
        'active_pulse'      => (int)$activePulse['n'],
        'total_pulse_resp'  => (int)$totalPulseResp['n'],
    ]);
}

// ── Course Summary (lightweight, all-user) ────────────────────────────────────

function handleCourseSummary(): void {
    $s = requireAuth();
    $cid = $s['cid'];

    // Most recent announcement
    $announcement = dbOne(
        "SELECT id, title, content, created_at FROM posts WHERE course_id=? AND type='announcement' ORDER BY created_at DESC LIMIT 1",
        [$cid]
    );
    if ($announcement) {
        $announcement['content_short'] = mb_strimwidth(strip_tags($announcement['content'] ?? ''), 0, 120, '…');
    }

    // Active pulse check
    $activePulse = dbOne(
        "SELECT id, title FROM pulse_checks WHERE course_id=? AND status='active' LIMIT 1",
        [$cid]
    );

    // Unanswered questions
    $unanswered = dbOne(
        "SELECT COUNT(*) AS n FROM posts WHERE course_id=? AND type='question' AND is_resolved=0",
        [$cid]
    );

    // Open / reviewing peer feedback assignments
    $openFeedback = dbAll(
        "SELECT id, title, status, due_at FROM pf_assignments WHERE course_id=? AND status IN ('open','reviewing') ORDER BY due_at LIMIT 3",
        [$cid]
    );

    // Posts this week
    $postsThisWeek = dbOne(
        'SELECT COUNT(*) AS n FROM posts WHERE course_id=? AND created_at > ?',
        [$cid, time() - 604800]
    );

    json([
        'announcement'         => $announcement,
        'active_pulse'         => $activePulse,
        'unanswered_questions' => (int)$unanswered['n'],
        'open_feedback'        => $openFeedback,
        'posts_this_week'      => (int)$postsThisWeek['n'],
    ]);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function getUserVote(int $userId, string $type, int $targetId): ?int {
    $v = dbOne('SELECT value FROM votes WHERE user_id=? AND target_type=? AND target_id=?', [$userId, $type, $targetId]);
    return $v ? (int)$v['value'] : null;
}

function getReactionCounts(string $type, int $id, int $userId): array {
    $rows = dbAll(
        'SELECT emoji, COUNT(*) AS count FROM reactions WHERE target_type=? AND target_id=? GROUP BY emoji',
        [$type, $id]
    );
    $result = [];
    foreach ($rows as $r) {
        $mine = dbOne('SELECT id FROM reactions WHERE user_id=? AND target_type=? AND target_id=? AND emoji=?',
                      [$userId, $type, $id, $r['emoji']]);
        $result[] = ['emoji' => $r['emoji'], 'count' => (int)$r['count'], 'mine' => (bool)$mine];
    }
    return $result;
}

function getCommentReplies(int $parentId, int $userId): array {
    $replies = dbAll(
        'SELECT c.*, u.name AS author_name, u.given_name AS author_given, u.picture AS author_pic
         FROM comments c JOIN users u ON u.id=c.author_id
         WHERE c.parent_id=? ORDER BY c.created_at ASC',
        [$parentId]
    );
    foreach ($replies as &$r) {
        $r['vote'] = getUserVote($userId, 'comment', $r['id']);
        $r['reactions'] = getReactionCounts('comment', $r['id'], $userId);
        // Note: replies need session context for proper mod visibility; handled in callers
    }
    return $replies;
}

function getPollResults(int $postId, int $userId): array {
    $post    = dbOne('SELECT meta_json FROM posts WHERE id=?', [$postId]);
    $meta    = json_decode($post['meta_json'], true);
    $options = $meta['options'] ?? [];

    $votes = dbAll('SELECT option_idx, COUNT(*) AS n FROM poll_votes WHERE post_id=? GROUP BY option_idx', [$postId]);
    $voteMap = [];
    $total = 0;
    foreach ($votes as $v) {
        $voteMap[(int)$v['option_idx']] = (int)$v['n'];
        $total += (int)$v['n'];
    }

    $myVote = dbOne('SELECT option_idx FROM poll_votes WHERE post_id=? AND user_id=?', [$postId, $userId]);

    $results = [];
    foreach ($options as $i => $label) {
        $count = $voteMap[$i] ?? 0;
        $results[] = [
            'label'   => $label,
            'count'   => $count,
            'percent' => $total > 0 ? round($count / $total * 100) : 0,
        ];
    }
    return ['options' => $results, 'total' => $total, 'my_vote' => $myVote ? (int)$myVote['option_idx'] : null];
}

function userPayload(array $s): array {
    return [
        'id'          => $s['uid'],
        'name'        => $s['name'],
        'given_name'  => $s['given_name'],
        'family_name' => $s['family_name'],
        'email'       => $s['email'],
        'picture'     => $s['picture'],
        'bio'         => $s['bio'],
    ];
}

function coursePayload(array $s): array {
    return [
        'id'          => $s['cid'],
        'context_id'  => $s['context_id'],
        'title'       => $s['course_title'],
        'label'       => $s['course_label'],
        'course_type' => $s['course_type'] ?? 'lti',
    ];
}

// ── Documents ─────────────────────────────────────────────────────────────────

function handleDocs(string $method, array $body): void {
    $s = requireAuth();

    if ($method === 'GET') {
        // Return own docs + course-visible docs (access_level >= 1)
        $docs = dbAll(
            'SELECT d.id, d.title, d.access_level, d.version, d.created_by,
                    d.created_at, d.updated_at, u.name AS creator_name
             FROM documents d
             JOIN users u ON u.id = d.created_by
             WHERE d.course_id = ?
               AND (d.created_by = ? OR d.access_level >= 1)
             ORDER BY d.updated_at DESC',
            [$s['cid'], $s['uid']]
        );
        json($docs);
    } elseif ($method === 'POST') {
        $title = trim($body['title'] ?? '') ?: 'Untitled Document';
        $id = dbExec(
            'INSERT INTO documents (course_id, title, content, created_by) VALUES (?, ?, ?, ?)',
            [$s['cid'], $title, '', $s['uid']]
        );
        $doc = dbOne(
            'SELECT d.*, u.name AS creator_name FROM documents d JOIN users u ON u.id = d.created_by WHERE d.id = ?',
            [$id]
        );
        json($doc, 201);
    } else {
        jsonError(405, 'Method not allowed');
    }
}

function handleDoc(string $method, int $id, array $body): void {
    // GET with access_level=3 is publicly accessible without auth
    if ($method === 'GET') {
        $s = optionalAuth();
        if ($s) {
            $doc = dbOne(
                'SELECT d.*, u.name AS creator_name
                 FROM documents d JOIN users u ON u.id = d.created_by
                 WHERE d.id = ? AND d.course_id = ?',
                [$id, $s['cid']]
            );
            if ($doc && (int)$doc['access_level'] === 0
                && (int)$s['uid'] !== (int)$doc['created_by']
                && $s['role'] !== 'instructor') {
                jsonError(403, 'This document is private');
            }
        } else {
            // Unauthenticated: only publicly visible docs
            $doc = dbOne(
                'SELECT d.*, u.name AS creator_name
                 FROM documents d JOIN users u ON u.id = d.created_by
                 WHERE d.id = ? AND d.access_level = 3',
                [$id]
            );
        }
        if (!$doc) jsonError(404, 'Document not found');

        $editingBy = null;
        if ($s && $doc['editing_uid'] && $doc['editing_since']
            && (time() - (int)$doc['editing_since']) < 120
            && (int)$doc['editing_uid'] !== $s['uid']) {
            $eu = dbOne('SELECT name FROM users WHERE id = ?', [$doc['editing_uid']]);
            if ($eu) $editingBy = $eu['name'];
        }
        json(array_merge($doc, ['editing_by' => $editingBy]));
        return;
    }

    // All other methods require auth
    $s = requireAuth();
    $doc = dbOne(
        'SELECT d.*, u.name AS creator_name
         FROM documents d JOIN users u ON u.id = d.created_by
         WHERE d.id = ? AND d.course_id = ?',
        [$id, $s['cid']]
    );
    if (!$doc) jsonError(404, 'Document not found');

    if ($method === 'PUT') {
        $accessLevel = (int)$doc['access_level'];
        $isOwner = (int)$s['uid'] === (int)$doc['created_by'] || $s['role'] === 'instructor';

        // access_level 2 = course-collaborative: any course member may edit
        // all other levels: owner/instructor only
        if (!$isOwner && $accessLevel !== 2) {
            jsonError(403, 'You do not have permission to edit this document');
        }

        $clientVersion = isset($body['version']) ? (int)$body['version'] : 0;
        if ($clientVersion && $clientVersion !== (int)$doc['version']) {
            http_response_code(409);
            echo json_encode([
                'error'   => 'Document was updated by someone else. Refresh to see the latest version.',
                'current' => $doc,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $title   = trim($body['title']   ?? $doc['title']);
        $content = $body['content'] ?? $doc['content'];

        dbRun(
            'UPDATE documents SET title=?, content=?, version=version+1,
                     editing_uid=?, editing_since=?, updated_at=? WHERE id=?',
            [$title ?: 'Untitled Document', $content, $s['uid'], time(), time(), $id]
        );
        $updated = dbOne(
            'SELECT d.*, u.name AS creator_name FROM documents d JOIN users u ON u.id = d.created_by WHERE d.id = ?',
            [$id]
        );
        json($updated);

    } elseif ($method === 'DELETE') {
        if ((int)$s['uid'] !== (int)$doc['created_by'] && $s['role'] !== 'instructor') {
            jsonError(403, 'Only the creator or an instructor can delete this document');
        }
        dbRun('DELETE FROM documents WHERE id = ?', [$id]);
        json(['ok' => true]);

    } else {
        jsonError(405, 'Method not allowed');
    }
}

function handleDocAccess(int $id, array $body): void {
    $s = requireAuth();
    $doc = dbOne('SELECT * FROM documents WHERE id = ? AND course_id = ?', [$id, $s['cid']]);
    if (!$doc) jsonError(404, 'Document not found');

    if ((int)$s['uid'] !== (int)$doc['created_by'] && $s['role'] !== 'instructor') {
        jsonError(403, 'Only the creator or an instructor can change document access');
    }

    if (!isset($body['access_level']) || !in_array((int)$body['access_level'], [0, 1, 2, 3], true)) {
        jsonError(400, 'access_level must be 0 (private), 1 (course view), 2 (course edit), or 3 (public view)');
    }

    $level = (int)$body['access_level'];
    dbRun('UPDATE documents SET access_level = ? WHERE id = ?', [$level, $id]);
    json(['ok' => true, 'access_level' => $level]);
}

function handleDocPresence(int $id): void {
    $s = requireAuth();
    $doc = dbOne('SELECT * FROM documents WHERE id = ? AND course_id = ?', [$id, $s['cid']]);
    if (!$doc) jsonError(404, 'Document not found');

    // Clear stale presence (> 2 min since last save)
    if ($doc['editing_uid'] && $doc['editing_since'] && (time() - (int)$doc['editing_since']) >= 120) {
        dbRun('UPDATE documents SET editing_uid=NULL, editing_since=NULL WHERE id=?', [$id]);
        $doc['editing_uid']   = null;
        $doc['editing_since'] = null;
    }

    $editingBy = null;
    if ($doc['editing_uid'] && (int)$doc['editing_uid'] !== $s['uid']) {
        $eu = dbOne('SELECT name FROM users WHERE id = ?', [$doc['editing_uid']]);
        if ($eu) $editingBy = $eu['name'];
    }

    json(['version' => (int)$doc['version'], 'editing_by' => $editingBy]);
}

function handleDocRaw(int $id): void {
    $s = optionalAuth();
    if ($s) {
        $doc = dbOne('SELECT * FROM documents WHERE id = ? AND course_id = ?', [$id, $s['cid']]);
        if ($doc && (int)$doc['access_level'] === 0
            && (int)$s['uid'] !== (int)$doc['created_by']
            && $s['role'] !== 'instructor') {
            $doc = null; // treat as not found
        }
    } else {
        $doc = dbOne('SELECT * FROM documents WHERE id = ? AND access_level = 3', [$id]);
    }
    if (!$doc) jsonError(404, 'Document not found');

    $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $doc['title'] ?: 'document') . '.md';
    $content  = $doc['content'] ?? '';

    header_remove('Content-Type');
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: no-store');
    echo $content;
    exit;
}

// ── Moderation helpers ────────────────────────────────────────────────────────

/**
 * Applies mod_status visibility rules to a post or comment array (in-place).
 * - instructor: sees everything + flag_count badge info
 * - author: sees own content + mod_notice if hidden/redacted
 * - others: content replaced with placeholder if hidden/redacted
 */
function applyModVisibility(array &$item, array $s): void {
    $modStatus = $item['mod_status'] ?? 'normal';
    if ($modStatus === 'normal') {
        // Still attach flag count for instructors
        if ($s['role'] === 'instructor') {
            $type = isset($item['post_id']) ? 'comment' : 'post';
            $fc = dbOne(
                "SELECT COUNT(*) AS n FROM flags WHERE target_type=? AND target_id=? AND status='open'",
                [$type, $item['id']]
            );
            $item['flag_count'] = (int)($fc['n'] ?? 0);
        }
        return;
    }

    $isInstructor = $s['role'] === 'instructor';
    $authorKey = isset($item['author_id']) ? 'author_id' : 'author_id';
    $isAuthor = (int)($item[$authorKey] ?? 0) === (int)$s['uid'];

    if ($isInstructor) {
        // Instructor sees full content + flag count
        $type = isset($item['post_id']) ? 'comment' : 'post';
        $fc = dbOne(
            "SELECT COUNT(*) AS n FROM flags WHERE target_type=? AND target_id=? AND status='open'",
            [$type, $item['id']]
        );
        $item['flag_count'] = (int)($fc['n'] ?? 0);
        return;
    }

    if ($isAuthor) {
        // Author sees own content but gets a notice
        $item['mod_notice'] = true;
        return;
    }

    // Everyone else: replace content with placeholder
    $item['content'] = null;
    if (isset($item['title'])) {
        $item['title'] = '[Post under review]';
    }
    $item['mod_note'] = null;
    $item['original_content'] = null;
}

// ── Flagging ──────────────────────────────────────────────────────────────────

function handleContentFlag(string $type, int $id, array $body): void {
    $s = requireAuth();

    $validReasons = ['inappropriate', 'harassment', 'spam', 'off_topic'];
    $reason = trim($body['reason'] ?? '');
    if (!in_array($reason, $validReasons)) {
        jsonError(400, 'reason must be one of: ' . implode(', ', $validReasons));
    }
    $details = trim($body['details'] ?? '');

    // Verify target exists and belongs to this course
    if ($type === 'post') {
        $target = dbOne('SELECT id, author_id, title FROM posts WHERE id=? AND course_id=?', [$id, $s['cid']]);
    } else {
        $target = dbOne(
            'SELECT c.id, c.author_id FROM comments c JOIN posts p ON p.id=c.post_id WHERE c.id=? AND p.course_id=?',
            [$id, $s['cid']]
        );
    }
    if (!$target) jsonError(404, ucfirst($type) . ' not found');

    // One flag per user per item
    try {
        dbExec(
            'INSERT INTO flags (course_id, target_type, target_id, flagged_by, reason, details, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, \'open\', ?)',
            [$s['cid'], $type, $id, $s['uid'], $reason, $details, time()]
        );
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            jsonError(409, 'You have already reported this content.');
        }
        throw $e;
    }

    // Check flag count; notify instructors if threshold reached
    $flagCount = dbOne(
        "SELECT COUNT(*) AS n FROM flags WHERE target_type=? AND target_id=? AND status='open'",
        [$type, $id]
    );
    if ((int)$flagCount['n'] >= 3) {
        $instructors = dbAll(
            "SELECT u.id FROM users u JOIN enrollments e ON e.user_id=u.id
             WHERE e.course_id=? AND e.role='instructor'",
            [$s['cid']]
        );
        $excerpt = $type === 'post' ? mb_substr($target['title'] ?? '', 0, 60) : 'a comment';
        foreach ($instructors as $inst) {
            notify($inst['id'], $s['cid'], 'flag',
                "Content has been flagged 3+ times and may need review: \"$excerpt\"",
                '/#moderation');
        }
    }

    // Notify author privately
    if ((int)$target['author_id'] !== (int)$s['uid']) {
        $label = $type === 'post' ? 'post' : 'comment';
        notify($target['author_id'], $s['cid'], 'flag',
            "Your $label has been flagged as potentially concerning. You can edit or remove it at any time.",
            $type === 'post' ? '/post/' . $id : '');
    }

    json(['message' => 'Report submitted']);
}

// ── Moderation actions ────────────────────────────────────────────────────────

function handleModerate(string $type, int $id, array $body): void {
    $s = requireAuth();
    requireInstructor($s);

    $action = trim($body['action'] ?? '');
    $note   = trim($body['note'] ?? '');

    $validActions = ['hide', 'restore', 'redact', 'send_note'];
    if (!in_array($action, $validActions)) {
        jsonError(400, 'action must be one of: ' . implode(', ', $validActions));
    }

    if ($type === 'post') {
        $target = dbOne('SELECT * FROM posts WHERE id=? AND course_id=?', [$id, $s['cid']]);
    } else {
        $target = dbOne(
            'SELECT c.*, p.course_id FROM comments c JOIN posts p ON p.id=c.post_id WHERE c.id=? AND p.course_id=?',
            [$id, $s['cid']]
        );
    }
    if (!$target) jsonError(404, ucfirst($type) . ' not found');

    $table = $type === 'post' ? 'posts' : 'comments';
    $authorId = (int)$target['author_id'];

    if ($action === 'hide') {
        dbRun("UPDATE $table SET mod_status='hidden', mod_note=? WHERE id=?", [$note, $id]);
        if ($authorId) {
            $msg = "An instructor has reviewed your " . $type . " and it is currently not visible to others.";
            if ($note) $msg .= " Note: $note";
            notify($authorId, $s['cid'], 'moderation', $msg,
                   $type === 'post' ? '/post/' . $id : '');
        }
    } elseif ($action === 'restore') {
        dbRun("UPDATE $table SET mod_status='normal', mod_note='', original_content=NULL WHERE id=?", [$id]);
    } elseif ($action === 'redact') {
        $original = $target['content'];
        dbRun(
            "UPDATE $table SET original_content=?, content='[Content removed by instructor]', mod_status='redacted', mod_note=? WHERE id=?",
            [$original, $note, $id]
        );
        if ($authorId) {
            $msg = "An instructor has redacted the content of your " . $type . ".";
            if ($note) $msg .= " Note: $note";
            notify($authorId, $s['cid'], 'moderation', $msg,
                   $type === 'post' ? '/post/' . $id : '');
        }
    } elseif ($action === 'send_note') {
        if ($authorId && $note) {
            notify($authorId, $s['cid'], 'moderation',
                   "A private note from your instructor: $note",
                   $type === 'post' ? '/post/' . $id : '');
        }
    }

    // Log the action
    dbExec(
        'INSERT INTO moderation_log (course_id, actor_id, target_type, target_id, action, note, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$s['cid'], $s['uid'], $type, $id, $action, $note, time()]
    );

    // Mark open flags on this target as actioned (except send_note and restore)
    if (!in_array($action, ['send_note', 'restore'])) {
        dbRun(
            "UPDATE flags SET status='actioned', resolved_at=?, resolved_by=?
             WHERE target_type=? AND target_id=? AND course_id=? AND status='open'",
            [time(), $s['uid'], $type, $id, $s['cid']]
        );
    }

    json(['ok' => true]);
}

// ── Flag queue ────────────────────────────────────────────────────────────────

function handleFlagsList(): void {
    $s = requireAuth();
    requireInstructor($s);

    // Get open flags grouped by target
    $rows = dbAll(
        "SELECT f.target_type, f.target_id,
                COUNT(*) AS flag_count,
                GROUP_CONCAT(DISTINCT f.reason) AS reasons,
                MAX(f.created_at) AS latest_flag_at,
                MIN(f.id) AS first_flag_id
         FROM flags f
         WHERE f.course_id=? AND f.status='open'
         GROUP BY f.target_type, f.target_id
         ORDER BY flag_count DESC, latest_flag_at DESC",
        [$s['cid']]
    );

    $result = [];
    foreach ($rows as $row) {
        $ttype = $row['target_type'];
        $tid   = (int)$row['target_id'];

        if ($ttype === 'post') {
            $content = dbOne(
                'SELECT p.id, p.title, p.content, p.author_id, p.mod_status,
                        u.name AS author_name
                 FROM posts p JOIN users u ON u.id=p.author_id
                 WHERE p.id=?',
                [$tid]
            );
            $excerpt = mb_substr($content['title'] ?? '', 0, 80);
            $body_excerpt = mb_substr($content['content'] ?? '', 0, 120);
        } else {
            $content = dbOne(
                'SELECT c.id, c.content, c.author_id, c.mod_status,
                        u.name AS author_name
                 FROM comments c JOIN users u ON u.id=c.author_id
                 WHERE c.id=?',
                [$tid]
            );
            $excerpt = mb_substr($content['content'] ?? '', 0, 120);
            $body_excerpt = '';
        }

        if (!$content) continue;

        $result[] = [
            'target_type'    => $ttype,
            'target_id'      => $tid,
            'first_flag_id'  => (int)$row['first_flag_id'],
            'flag_count'     => (int)$row['flag_count'],
            'reasons'        => array_unique(explode(',', $row['reasons'])),
            'latest_flag_at' => (int)$row['latest_flag_at'],
            'content_excerpt'=> $excerpt,
            'body_excerpt'   => $body_excerpt,
            'author_name'    => $content['author_name'] ?? '',
            'author_id'      => (int)($content['author_id'] ?? 0),
            'mod_status'     => $content['mod_status'] ?? 'normal',
        ];
    }

    json($result);
}

function handleFlagResolve(int $id, array $body): void {
    $s = requireAuth();
    requireInstructor($s);

    $flag = dbOne('SELECT * FROM flags WHERE id=? AND course_id=?', [$id, $s['cid']]);
    if (!$flag) jsonError(404, 'Flag not found');

    $action = trim($body['action'] ?? 'dismiss');
    $newStatus = $action === 'actioned' ? 'actioned' : 'dismissed';

    // Dismiss/action all flags on the same target
    dbRun(
        "UPDATE flags SET status=?, resolved_at=?, resolved_by=?
         WHERE target_type=? AND target_id=? AND course_id=? AND status='open'",
        [$newStatus, time(), $s['uid'], $flag['target_type'], $flag['target_id'], $s['cid']]
    );

    dbExec(
        'INSERT INTO moderation_log (course_id, actor_id, target_type, target_id, action, note, created_at)
         VALUES (?, ?, ?, ?, \'dismiss_flags\', \'\', ?)',
        [$s['cid'], $s['uid'], $flag['target_type'], $flag['target_id'], time()]
    );

    json(['ok' => true]);
}

// ── Moderation log ────────────────────────────────────────────────────────────

function handleModerationLog(): void {
    $s = requireAuth();
    requireInstructor($s);

    $rows = dbAll(
        'SELECT ml.id, ml.target_type, ml.target_id, ml.action, ml.note, ml.created_at,
                u.name AS actor_name
         FROM moderation_log ml
         JOIN users u ON u.id=ml.actor_id
         WHERE ml.course_id=?
         ORDER BY ml.created_at DESC LIMIT 50',
        [$s['cid']]
    );

    foreach ($rows as &$row) {
        if ($row['target_type'] === 'post') {
            $t = dbOne('SELECT title FROM posts WHERE id=?', [(int)$row['target_id']]);
            $row['target_excerpt'] = mb_substr($t['title'] ?? '[deleted]', 0, 60);
        } else {
            $t = dbOne('SELECT content FROM comments WHERE id=?', [(int)$row['target_id']]);
            $row['target_excerpt'] = mb_substr($t['content'] ?? '[deleted]', 0, 60);
        }
    }
    unset($row);

    json($rows);
}

// ── Invite Codes ──────────────────────────────────────────────────────────────

function handleInviteCodes(string $method, int $id, array $body): void {
    $s = requireAuth();

    if ($method === 'GET' && !$id) {
        // List active codes for this course
        requireInstructor($s);
        $codes = dbAll(
            'SELECT ic.*, u.name AS creator_name
               FROM course_invite_codes ic
               JOIN users u ON u.id = ic.created_by
              WHERE ic.course_id = ? AND ic.is_active = 1
              ORDER BY ic.created_at DESC',
            [$s['cid']]
        );
        json($codes);
    } elseif ($method === 'POST' && !$id) {
        // Create a new invite code
        requireInstructor($s);
        $role      = in_array($body['role'] ?? '', ['student', 'instructor']) ? $body['role'] : 'student';
        $label     = trim($body['label'] ?? '');
        $expiresAt = isset($body['expires_at']) && $body['expires_at'] ? (int)$body['expires_at'] : null;
        $maxUses   = isset($body['max_uses'])   && $body['max_uses']   ? (int)$body['max_uses']   : null;

        // Generate an 8-char uppercase alphanumeric code
        $code = generateInviteCode();

        $codeId = dbExec(
            'INSERT INTO course_invite_codes (course_id, code, role, label, created_by, expires_at, max_uses)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$s['cid'], $code, $role, $label, $s['uid'], $expiresAt, $maxUses]
        );

        json(dbOne('SELECT * FROM course_invite_codes WHERE id = ?', [$codeId]));
    } elseif ($method === 'DELETE' && $id) {
        // Deactivate a code
        requireInstructor($s);
        $existing = dbOne('SELECT * FROM course_invite_codes WHERE id = ? AND course_id = ?', [$id, $s['cid']]);
        if (!$existing) jsonError(404, 'Invite code not found');
        dbRun('UPDATE course_invite_codes SET is_active = 0 WHERE id = ?', [$id]);
        json(['ok' => true]);
    } else {
        jsonError(405, 'Method not allowed');
    }
}

function generateInviteCode(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no ambiguous chars I/1/O/0
    $code  = '';
    $bytes = random_bytes(8);
    for ($i = 0; $i < 8; $i++) {
        $code .= $chars[ord($bytes[$i]) % strlen($chars)];
    }
    return $code;
}

// ─────────────────────────────────────────────────────────────────────────────

function json(mixed $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

// ── Peer Feedback ─────────────────────────────────────────────────────────────

/** Attach submission + review counts to an assignment row */
function pfAssignmentPayload(array $a, int $uid, string $role): array {
    $subCount = dbOne('SELECT COUNT(*) AS n FROM pf_submissions WHERE assignment_id=?', [$a['id']]);
    $a['submission_count'] = (int)$subCount['n'];

    $mySubRow = dbOne('SELECT id, file_name, submitted_at FROM pf_submissions WHERE assignment_id=? AND author_id=?', [$a['id'], $uid]);
    $a['my_submission'] = $mySubRow ?: null;

    $pendingReviews = dbOne(
        'SELECT COUNT(*) AS n FROM pf_review_assignments WHERE assignment_id=? AND reviewer_id=? AND completed_at IS NULL',
        [$a['id'], $uid]
    );
    $a['pending_reviews'] = (int)$pendingReviews['n'];

    $totalReviews = dbOne(
        'SELECT COUNT(*) AS n FROM pf_review_assignments WHERE assignment_id=?',
        [$a['id']]
    );
    $a['review_assignment_count'] = (int)$totalReviews['n'];

    $completedReviews = dbOne(
        'SELECT COUNT(*) AS n FROM pf_review_assignments WHERE assignment_id=? AND completed_at IS NOT NULL',
        [$a['id']]
    );
    $a['completed_review_count'] = (int)$completedReviews['n'];

    $a['prompts'] = json_decode($a['prompts_json'] ?? '[]', true) ?: [];
    unset($a['prompts_json']);

    return $a;
}

function handleFeedbackList(string $method, array $body): void {
    $s = requireAuth();

    if ($method === 'GET') {
        $where = $s['role'] === 'instructor' ? '' : "AND status != 'draft'";
        $rows = dbAll(
            "SELECT * FROM pf_assignments WHERE course_id=? $where ORDER BY created_at DESC",
            [$s['cid']]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = pfAssignmentPayload($row, $s['uid'], $s['role']);
        }
        json($out);
    }

    if ($method === 'POST') {
        requireInstructor($s);
        $title   = trim($body['title'] ?? '');
        if (!$title) jsonError(400, 'Title required');
        $prompts = $body['prompts'] ?? [];
        if (!is_array($prompts)) $prompts = [];

        $id = dbExec(
            'INSERT INTO pf_assignments
             (course_id, title, description, prompts_json, allow_text, allow_files,
              accepted_types, max_file_mb, reviewers_per_sub, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $s['cid'],
                $title,
                trim($body['description'] ?? ''),
                json_encode($prompts),
                (int)($body['allow_text'] ?? 1),
                (int)($body['allow_files'] ?? 0),
                trim($body['accepted_types'] ?? 'pdf,doc,docx'),
                max(1, min(100, (int)($body['max_file_mb'] ?? 10))),
                max(1, (int)($body['reviewers_per_sub'] ?? 2)),
                $s['uid'],
            ]
        );
        $row = dbOne('SELECT * FROM pf_assignments WHERE id=?', [$id]);
        json(pfAssignmentPayload($row, $s['uid'], $s['role']), 201);
    }

    jsonError(405, 'Method not allowed');
}

function handleFeedback(string $method, int $id, array $body): void {
    $s = requireAuth();
    $a = dbOne('SELECT * FROM pf_assignments WHERE id=? AND course_id=?', [$id, $s['cid']]);
    if (!$a) jsonError(404, 'Assignment not found');
    if ($a['status'] === 'draft' && $s['role'] !== 'instructor') jsonError(404, 'Assignment not found');

    if ($method === 'GET') {
        json(pfAssignmentPayload($a, $s['uid'], $s['role']));
    }

    if ($method === 'PUT') {
        requireInstructor($s);
        if ($a['status'] !== 'draft') jsonError(400, 'Can only edit draft assignments');
        $prompts = $body['prompts'] ?? json_decode($a['prompts_json'], true);
        dbRun(
            'UPDATE pf_assignments SET title=?, description=?, prompts_json=?, allow_text=?, allow_files=?,
             accepted_types=?, max_file_mb=?, reviewers_per_sub=? WHERE id=?',
            [
                trim($body['title'] ?? $a['title']),
                trim($body['description'] ?? $a['description']),
                json_encode(is_array($prompts) ? $prompts : []),
                (int)($body['allow_text'] ?? $a['allow_text']),
                (int)($body['allow_files'] ?? $a['allow_files']),
                trim($body['accepted_types'] ?? $a['accepted_types']),
                max(1, min(100, (int)($body['max_file_mb'] ?? $a['max_file_mb']))),
                max(1, (int)($body['reviewers_per_sub'] ?? $a['reviewers_per_sub'])),
                $id,
            ]
        );
        $row = dbOne('SELECT * FROM pf_assignments WHERE id=?', [$id]);
        json(pfAssignmentPayload($row, $s['uid'], $s['role']));
    }

    if ($method === 'DELETE') {
        requireInstructor($s);
        // Clean up files
        $subs = dbAll('SELECT file_path FROM pf_submissions WHERE assignment_id=?', [$id]);
        foreach ($subs as $sub) {
            if ($sub['file_path'] && file_exists($sub['file_path'])) {
                @unlink($sub['file_path']);
            }
        }
        dbRun('DELETE FROM pf_responses WHERE review_assignment_id IN (SELECT id FROM pf_review_assignments WHERE assignment_id=?)', [$id]);
        dbRun('DELETE FROM pf_review_assignments WHERE assignment_id=?', [$id]);
        dbRun('DELETE FROM pf_submissions WHERE assignment_id=?', [$id]);
        dbRun('DELETE FROM pf_assignments WHERE id=?', [$id]);
        json(['ok' => true]);
    }

    jsonError(405, 'Method not allowed');
}

function handleFeedbackTransition(int $id, string $newStatus): void {
    $s = requireAuth();
    requireInstructor($s);
    $a = dbOne('SELECT * FROM pf_assignments WHERE id=? AND course_id=?', [$id, $s['cid']]);
    if (!$a) jsonError(404, 'Assignment not found');

    $allowed = match($newStatus) {
        'open'   => $a['status'] === 'draft',
        'closed' => in_array($a['status'], ['open', 'reviewing']),
        default  => false,
    };
    if (!$allowed) jsonError(400, "Cannot transition from '{$a['status']}' to '$newStatus'");

    dbRun('UPDATE pf_assignments SET status=? WHERE id=?', [$newStatus, $id]);
    $row = dbOne('SELECT * FROM pf_assignments WHERE id=?', [$id]);
    json(pfAssignmentPayload($row, $s['uid'], $s['role']));
}

function handleFeedbackAssign(int $id): void {
    $s = requireAuth();
    requireInstructor($s);
    $a = dbOne('SELECT * FROM pf_assignments WHERE id=? AND course_id=?', [$id, $s['cid']]);
    if (!$a) jsonError(404, 'Assignment not found');
    if (!in_array($a['status'], ['open', 'reviewing'])) {
        jsonError(400, 'Assignment must be open or reviewing to assign reviewers');
    }

    $submissions = dbAll('SELECT * FROM pf_submissions WHERE assignment_id=?', [$id]);
    if (empty($submissions)) jsonError(400, 'No submissions to assign');

    $reviewersPerSub = (int)$a['reviewers_per_sub'];
    $students = dbAll(
        "SELECT user_id FROM enrollments WHERE course_id=? AND role='student'",
        [$s['cid']]
    );
    $studentIds = array_column($students, 'user_id');
    if (empty($studentIds)) jsonError(400, 'No students enrolled');

    // Remove existing unfinished assignments (re-assign)
    dbRun(
        'DELETE FROM pf_review_assignments WHERE assignment_id=? AND completed_at IS NULL',
        [$id]
    );

    // Build load map (count already-completed reviews per reviewer)
    $loadMap = array_fill_keys($studentIds, 0);
    $existing = dbAll(
        'SELECT reviewer_id, COUNT(*) AS n FROM pf_review_assignments WHERE assignment_id=? GROUP BY reviewer_id',
        [$id]
    );
    foreach ($existing as $e) {
        if (isset($loadMap[(int)$e['reviewer_id']])) {
            $loadMap[(int)$e['reviewer_id']] = (int)$e['n'];
        }
    }

    $assigned = 0;
    foreach ($submissions as $sub) {
        $authorId = (int)$sub['author_id'];
        $eligible = array_values(array_filter($studentIds, fn($uid) => $uid !== $authorId));
        // Skip if already has a completed review from this reviewer
        $alreadyDone = dbAll(
            'SELECT reviewer_id FROM pf_review_assignments WHERE submission_id=? AND completed_at IS NOT NULL',
            [$sub['id']]
        );
        $doneIds = array_column($alreadyDone, 'reviewer_id');
        $eligible = array_values(array_filter($eligible, fn($uid) => !in_array($uid, $doneIds)));

        $n = min($reviewersPerSub, count($eligible));
        usort($eligible, fn($a, $b) => $loadMap[$a] <=> $loadMap[$b]);

        for ($i = 0; $i < $n; $i++) {
            $reviewerId = $eligible[$i];
            dbExec(
                'INSERT OR IGNORE INTO pf_review_assignments (assignment_id, submission_id, reviewer_id) VALUES (?, ?, ?)',
                [$id, $sub['id'], $reviewerId]
            );
            $loadMap[$reviewerId]++;
            $assigned++;
        }
    }

    dbRun("UPDATE pf_assignments SET status='reviewing' WHERE id=?", [$id]);
    $row = dbOne('SELECT * FROM pf_assignments WHERE id=?', [$id]);
    json(['ok' => true, 'assigned' => $assigned, 'assignment' => pfAssignmentPayload($row, $s['uid'], $s['role'])]);
}

function handleFeedbackSubmit(string $method, int $id): void {
    $s = requireAuth();
    $a = dbOne('SELECT * FROM pf_assignments WHERE id=? AND course_id=?', [$id, $s['cid']]);
    if (!$a) jsonError(404, 'Assignment not found');

    if ($method === 'GET') {
        $sub = dbOne('SELECT * FROM pf_submissions WHERE assignment_id=? AND author_id=?', [$id, $s['uid']]);
        json($sub ?: ['assignment_id' => $id, 'author_id' => $s['uid']]);
    }

    if ($method === 'POST' || $method === 'PUT') {
        if ($a['status'] !== 'open') jsonError(400, 'Submissions are not currently open');

        $textContent = null;
        $filePath    = null;
        $fileName    = null;
        $fileSize    = null;
        $fileMime    = null;

        // Handle file upload
        if (!empty($_FILES['file'])) {
            if (!(int)$a['allow_files']) jsonError(400, 'File submissions not allowed for this assignment');

            $file       = $_FILES['file'];
            if ($file['error'] !== UPLOAD_ERR_OK) jsonError(400, 'Upload error: ' . $file['error']);
            if ($file['size'] > (int)$a['max_file_mb'] * 1048576) {
                jsonError(400, "File exceeds maximum size of {$a['max_file_mb']} MB");
            }

            $allowedExts = array_map('trim', explode(',', strtolower($a['accepted_types'])));
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($allowedExts && !in_array($ext, $allowedExts)) {
                jsonError(400, "File type .$ext not allowed. Accepted: {$a['accepted_types']}");
            }

            $uploadDir = __DIR__ . '/data/uploads';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $safeName = 'pf_' . $id . '_' . $s['uid'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $dest     = $uploadDir . '/' . $safeName;
            if (!move_uploaded_file($file['tmp_name'], $dest)) jsonError(500, 'Failed to save file');

            // Delete old file if replacing
            $old = dbOne('SELECT file_path FROM pf_submissions WHERE assignment_id=? AND author_id=?', [$id, $s['uid']]);
            if ($old && $old['file_path'] && file_exists($old['file_path'])) {
                @unlink($old['file_path']);
            }

            $filePath  = $dest;
            $fileName  = basename($file['name']);
            $fileSize  = (int)$file['size'];
            $fileMime  = mime_content_type($dest) ?: 'application/octet-stream';
        } else {
            // Text submission
            $raw = file_get_contents('php://input');
            $body = $raw ? (json_decode($raw, true) ?? []) : $_POST;
            if (!(int)$a['allow_text']) jsonError(400, 'Text submissions not allowed for this assignment');
            $textContent = trim($body['text_content'] ?? '');
            if (!$textContent) jsonError(400, 'Submission text is required');
        }

        $existing = dbOne('SELECT id FROM pf_submissions WHERE assignment_id=? AND author_id=?', [$id, $s['uid']]);
        if ($existing) {
            $sets  = ['submitted_at = ?'];
            $vals  = [time()];
            if ($textContent !== null) { $sets[] = 'text_content = ?'; $vals[] = $textContent; }
            if ($filePath    !== null) { $sets[] = 'file_path = ?'; $vals[] = $filePath;
                                         $sets[] = 'file_name = ?'; $vals[] = $fileName;
                                         $sets[] = 'file_size = ?'; $vals[] = $fileSize;
                                         $sets[] = 'file_mime = ?'; $vals[] = $fileMime; }
            $vals[] = $existing['id'];
            dbRun('UPDATE pf_submissions SET ' . implode(', ', $sets) . ' WHERE id=?', $vals);
            $sub = dbOne('SELECT * FROM pf_submissions WHERE id=?', [$existing['id']]);
        } else {
            $newId = dbExec(
                'INSERT INTO pf_submissions (assignment_id, author_id, text_content, file_path, file_name, file_size, file_mime) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, $s['uid'], $textContent, $filePath, $fileName, $fileSize, $fileMime]
            );
            $sub = dbOne('SELECT * FROM pf_submissions WHERE id=?', [$newId]);
        }
        // Don't expose full file path to client
        unset($sub['file_path']);
        json($sub, 201);
    }

    if ($method === 'DELETE') {
        if ($a['status'] !== 'open') jsonError(400, 'Cannot withdraw after submission period');
        $sub = dbOne('SELECT * FROM pf_submissions WHERE assignment_id=? AND author_id=?', [$id, $s['uid']]);
        if (!$sub) jsonError(404, 'No submission found');
        if ($sub['file_path'] && file_exists($sub['file_path'])) @unlink($sub['file_path']);
        dbRun('DELETE FROM pf_submissions WHERE id=?', [$sub['id']]);
        json(['ok' => true]);
    }

    jsonError(405, 'Method not allowed');
}

function handleFeedbackMyReviews(int $id): void {
    $s = requireAuth();
    $a = dbOne('SELECT * FROM pf_assignments WHERE id=? AND course_id=?', [$id, $s['cid']]);
    if (!$a) jsonError(404, 'Assignment not found');
    if ($a['status'] === 'draft') jsonError(404, 'Assignment not found');

    $reviews = dbAll(
        'SELECT ra.id, ra.submission_id, ra.assigned_at, ra.completed_at,
                ps.file_name, ps.submitted_at AS sub_submitted_at,
                CASE WHEN ps.text_content IS NOT NULL THEN 1 ELSE 0 END AS has_text
         FROM pf_review_assignments ra
         JOIN pf_submissions ps ON ps.id = ra.submission_id
         WHERE ra.assignment_id=? AND ra.reviewer_id=?
         ORDER BY ra.assigned_at',
        [$id, $s['uid']]
    );
    json(['assignment' => pfAssignmentPayload($a, $s['uid'], $s['role']), 'reviews' => $reviews]);
}

function handleFeedbackReceived(int $id): void {
    $s = requireAuth();
    $a = dbOne('SELECT * FROM pf_assignments WHERE id=? AND course_id=?', [$id, $s['cid']]);
    if (!$a) jsonError(404, 'Assignment not found');

    // Students only see feedback when closed; instructors can see anytime
    if ($s['role'] !== 'instructor' && $a['status'] !== 'closed') {
        jsonError(400, 'Feedback is not yet available');
    }

    // Find submission
    $targetId = $s['uid'];
    if ($s['role'] === 'instructor') {
        // Instructor can request for any user via ?user_id=
        $targetId = (int)($_GET['user_id'] ?? $s['uid']);
    }

    $sub = dbOne('SELECT id FROM pf_submissions WHERE assignment_id=? AND author_id=?', [$id, $targetId]);
    if (!$sub) jsonError(404, 'No submission found');

    $responses = dbAll(
        'SELECT pr.answers_json, pr.overall_comment, pr.submitted_at
         FROM pf_responses pr
         JOIN pf_review_assignments ra ON ra.id = pr.review_assignment_id
         WHERE ra.submission_id=? ORDER BY pr.submitted_at',
        [$sub['id']]
    );

    // Parse answers
    foreach ($responses as &$r) {
        $r['answers'] = json_decode($r['answers_json'], true) ?: [];
        unset($r['answers_json']);
    }

    json(['assignment' => pfAssignmentPayload($a, $s['uid'], $s['role']), 'responses' => $responses]);
}

function handleFeedbackProgress(int $id): void {
    $s = requireAuth();
    requireInstructor($s);
    $a = dbOne('SELECT * FROM pf_assignments WHERE id=? AND course_id=?', [$id, $s['cid']]);
    if (!$a) jsonError(404, 'Assignment not found');

    $submissions = dbAll(
        'SELECT ps.id, ps.submitted_at, ps.file_name,
                u.id AS author_id, u.name AS author_name,
                (SELECT COUNT(*) FROM pf_review_assignments ra WHERE ra.submission_id=ps.id) AS assigned_reviewers,
                (SELECT COUNT(*) FROM pf_review_assignments ra WHERE ra.submission_id=ps.id AND ra.completed_at IS NOT NULL) AS completed_reviews
         FROM pf_submissions ps
         JOIN users u ON u.id = ps.author_id
         WHERE ps.assignment_id=?
         ORDER BY ps.submitted_at',
        [$id]
    );

    $enrolled = dbOne("SELECT COUNT(*) AS n FROM enrollments WHERE course_id=? AND role='student'", [$s['cid']]);
    $subCount = count($submissions);
    $raTotal  = dbOne('SELECT COUNT(*) AS n FROM pf_review_assignments WHERE assignment_id=?', [$id]);
    $raDone   = dbOne('SELECT COUNT(*) AS n FROM pf_review_assignments WHERE assignment_id=? AND completed_at IS NOT NULL', [$id]);

    json([
        'assignment'        => pfAssignmentPayload($a, $s['uid'], $s['role']),
        'enrolled_students' => (int)$enrolled['n'],
        'submission_count'  => $subCount,
        'review_total'      => (int)$raTotal['n'],
        'review_done'       => (int)$raDone['n'],
        'submissions'       => $submissions,
    ]);
}

function handleReview(string $method, int $raId, array $body): void {
    $s = requireAuth();
    $ra = dbOne(
        'SELECT ra.*, pa.course_id, pa.status AS asgn_status, pa.prompts_json
         FROM pf_review_assignments ra
         JOIN pf_assignments pa ON pa.id = ra.assignment_id
         WHERE ra.id=?',
        [$raId]
    );
    if (!$ra || $ra['course_id'] != $s['cid']) jsonError(404, 'Review not found');

    // Only the assigned reviewer (or instructor) can see this
    if ($ra['reviewer_id'] != $s['uid'] && $s['role'] !== 'instructor') {
        jsonError(403, 'Not authorised');
    }

    if ($method === 'GET') {
        // Get the submission (anonymised – no author identity)
        $sub = dbOne(
            'SELECT id, text_content, file_name, file_size, file_mime, submitted_at FROM pf_submissions WHERE id=?',
            [$ra['submission_id']]
        );
        $existingResponse = dbOne('SELECT * FROM pf_responses WHERE review_assignment_id=?', [$raId]);
        if ($existingResponse) {
            $existingResponse['answers'] = json_decode($existingResponse['answers_json'], true) ?: [];
            unset($existingResponse['answers_json']);
        }
        $prompts = json_decode($ra['prompts_json'], true) ?: [];
        json([
            'review_assignment_id' => $raId,
            'submission'           => $sub,
            'prompts'              => $prompts,
            'response'             => $existingResponse,
            'completed'            => !is_null($ra['completed_at']),
            'asgn_status'          => $ra['asgn_status'],
        ]);
    }

    if ($method === 'POST') {
        if ($ra['reviewer_id'] != $s['uid']) jsonError(403, 'Not authorised');
        if (!in_array($ra['asgn_status'], ['reviewing', 'closed'])) {
            jsonError(400, 'Reviews are not currently open');
        }

        $answers        = $body['answers'] ?? [];
        $overallComment = trim($body['overall_comment'] ?? '');

        if (!is_array($answers)) $answers = [];

        $existing = dbOne('SELECT id FROM pf_responses WHERE review_assignment_id=?', [$raId]);
        if ($existing) {
            dbRun('UPDATE pf_responses SET answers_json=?, overall_comment=?, submitted_at=? WHERE id=?',
                  [json_encode($answers), $overallComment, time(), $existing['id']]);
        } else {
            dbExec('INSERT INTO pf_responses (review_assignment_id, answers_json, overall_comment) VALUES (?, ?, ?)',
                   [$raId, json_encode($answers), $overallComment]);
        }

        // Mark as completed
        dbRun('UPDATE pf_review_assignments SET completed_at=? WHERE id=?', [time(), $raId]);

        json(['ok' => true]);
    }

    jsonError(405, 'Method not allowed');
}

// ── Pulse Checks ──────────────────────────────────────────────────────────────

function handlePulseList(): void {
    $s = requireAuth();
    if ($s['role'] === 'instructor') {
        $checks = dbAll(
            'SELECT pc.*, u.name AS creator_name,
                    (SELECT COUNT(*) FROM pulse_questions WHERE check_id=pc.id) AS question_count
             FROM pulse_checks pc
             JOIN users u ON u.id = pc.created_by
             WHERE pc.course_id = ?
             ORDER BY pc.created_at DESC',
            [$s['cid']]
        );
    } else {
        $checks = dbAll(
            "SELECT pc.*, u.name AS creator_name,
                    (SELECT COUNT(*) FROM pulse_questions WHERE check_id=pc.id) AS question_count
             FROM pulse_checks pc
             JOIN users u ON u.id = pc.created_by
             WHERE pc.course_id = ? AND pc.status = 'active'
             ORDER BY pc.created_at DESC",
            [$s['cid']]
        );
    }
    json($checks);
}

function handleCreatePulse(array $body): void {
    $s = requireAuth();
    requireInstructor($s);
    $title  = trim($body['title'] ?? '');
    $access = in_array($body['access'] ?? '', ['course', 'public']) ? $body['access'] : 'course';
    if (!$title) jsonError(400, 'Title is required');

    // Generate unique share_token
    $token = null;
    if ($access === 'public') {
        for ($i = 0; $i < 10; $i++) {
            $candidate = generateInviteCode();
            $exists = dbOne('SELECT id FROM pulse_checks WHERE share_token=?', [$candidate]);
            if (!$exists) { $token = $candidate; break; }
        }
        if (!$token) jsonError(500, 'Could not generate share token');
    }

    $id = dbExec(
        'INSERT INTO pulse_checks (course_id, created_by, title, access, share_token) VALUES (?, ?, ?, ?, ?)',
        [$s['cid'], $s['uid'], $title, $access, $token]
    );
    $check = dbOne('SELECT * FROM pulse_checks WHERE id=?', [$id]);
    json($check, 201);
}

function handlePulse(string $method, int $id, array $body): void {
    $s = requireAuth();
    $check = dbOne('SELECT * FROM pulse_checks WHERE id=? AND course_id=?', [$id, $s['cid']]);
    if (!$check) jsonError(404, 'Pulse check not found');

    if ($method === 'GET') {
        $questions = dbAll(
            'SELECT * FROM pulse_questions WHERE check_id=? ORDER BY sort_order ASC, id ASC',
            [$id]
        );
        foreach ($questions as &$q) {
            $q['options'] = $q['options_json'] ? json_decode($q['options_json'], true) : null;
            unset($q['options_json']);
        }
        // Return IDs of questions this user has already answered
        $myResponses = [];
        if ($s['uid']) {
            $rows = dbAll(
                'SELECT pq.id AS question_id, pr.response
                 FROM pulse_responses pr
                 JOIN pulse_questions pq ON pq.id = pr.question_id
                 WHERE pq.check_id = ? AND pr.user_id = ?',
                [$id, $s['uid']]
            );
            foreach ($rows as $r) {
                $myResponses[$r['question_id']] = $r['response'];
            }
        }
        json(['check' => $check, 'questions' => $questions, 'my_responses' => $myResponses]);
        return;
    }

    if ($method === 'PUT') {
        requireInstructor($s);
        $fields = [];
        $params = [];
        if (isset($body['title'])) { $fields[] = 'title=?'; $params[] = trim($body['title']); }
        if (isset($body['access']) && in_array($body['access'], ['course', 'public'])) {
            $fields[] = 'access=?'; $params[] = $body['access'];
            // Generate token if switching to public
            if ($body['access'] === 'public' && !$check['share_token']) {
                for ($i = 0; $i < 10; $i++) {
                    $candidate = generateInviteCode();
                    $exists = dbOne('SELECT id FROM pulse_checks WHERE share_token=?', [$candidate]);
                    if (!$exists) { $fields[] = 'share_token=?'; $params[] = $candidate; break; }
                }
            }
        }
        if (isset($body['status']) && in_array($body['status'], ['draft', 'active', 'closed'])) {
            $fields[] = 'status=?'; $params[] = $body['status'];
        }
        if ($fields) {
            $params[] = $id;
            dbRun('UPDATE pulse_checks SET ' . implode(', ', $fields) . ' WHERE id=?', $params);
        }
        $check = dbOne('SELECT * FROM pulse_checks WHERE id=?', [$id]);
        json($check);
        return;
    }

    if ($method === 'DELETE') {
        requireInstructor($s);
        // Cascade delete
        $qIds = dbAll('SELECT id FROM pulse_questions WHERE check_id=?', [$id]);
        foreach ($qIds as $q) {
            dbRun('DELETE FROM pulse_responses WHERE question_id=?', [$q['id']]);
        }
        dbRun('DELETE FROM pulse_questions WHERE check_id=?', [$id]);
        dbRun('DELETE FROM pulse_checks WHERE id=?', [$id]);
        json(['ok' => true]);
        return;
    }

    jsonError(405, 'Method not allowed');
}

function handleAddPulseQuestion(int $checkId, array $body): void {
    $s = requireAuth();
    requireInstructor($s);
    $check = dbOne('SELECT * FROM pulse_checks WHERE id=? AND course_id=?', [$checkId, $s['cid']]);
    if (!$check) jsonError(404, 'Pulse check not found');

    $question = trim($body['question'] ?? '');
    $type     = $body['type'] ?? '';
    if (!$question) jsonError(400, 'Question text required');
    if (!in_array($type, ['choice', 'text', 'rating', 'wordcloud'])) {
        jsonError(400, 'Invalid question type');
    }

    $options = null;
    if ($type === 'choice') {
        $opts = array_values(array_filter(array_map('trim', $body['options'] ?? []), fn($o) => $o !== ''));
        if (count($opts) < 2) jsonError(400, 'Choice questions need at least 2 options');
        $options = json_encode($opts);
    } elseif ($type === 'rating') {
        $options = json_encode([
            'min'       => (int)($body['min'] ?? 1),
            'max'       => (int)($body['max'] ?? 5),
            'min_label' => trim($body['min_label'] ?? ''),
            'max_label' => trim($body['max_label'] ?? ''),
        ]);
    }

    $sortOrder = (int)(dbOne('SELECT MAX(sort_order) AS m FROM pulse_questions WHERE check_id=?', [$checkId])['m'] ?? 0) + 1;
    $qId = dbExec(
        'INSERT INTO pulse_questions (check_id, question, type, options_json, sort_order) VALUES (?, ?, ?, ?, ?)',
        [$checkId, $question, $type, $options, $sortOrder]
    );
    $q = dbOne('SELECT * FROM pulse_questions WHERE id=?', [$qId]);
    $q['options'] = $q['options_json'] ? json_decode($q['options_json'], true) : null;
    unset($q['options_json']);
    json($q, 201);
}

function handlePulseAllResults(int $checkId): void {
    $s = requireAuth();
    requireInstructor($s);
    $check = dbOne('SELECT * FROM pulse_checks WHERE id=? AND course_id=?', [$checkId, $s['cid']]);
    if (!$check) jsonError(404, 'Pulse check not found');

    $questions = dbAll(
        'SELECT * FROM pulse_questions WHERE check_id=? ORDER BY sort_order ASC, id ASC',
        [$checkId]
    );
    $results = [];
    foreach ($questions as $q) {
        $q['options'] = $q['options_json'] ? json_decode($q['options_json'], true) : null;
        unset($q['options_json']);
        $results[] = [
            'question' => $q,
            'results'  => aggregateResponses($q['id'], $q),
            'count'    => (int)(dbOne('SELECT COUNT(*) AS n FROM pulse_responses WHERE question_id=?', [$q['id']])['n'] ?? 0),
        ];
    }
    json(['check' => $check, 'results' => $results]);
}

function handlePulseQuestion(string $method, int $qId, array $body): void {
    $s = requireAuth();
    requireInstructor($s);
    $q = dbOne(
        'SELECT pq.*, pc.course_id FROM pulse_questions pq
         JOIN pulse_checks pc ON pc.id = pq.check_id
         WHERE pq.id=?',
        [$qId]
    );
    if (!$q || $q['course_id'] != $s['cid']) jsonError(404, 'Question not found');

    if ($method === 'PUT') {
        $fields = [];
        $params = [];
        if (isset($body['question'])) { $fields[] = 'question=?'; $params[] = trim($body['question']); }
        if (isset($body['options_json'])) { $fields[] = 'options_json=?'; $params[] = $body['options_json']; }
        if ($fields) { $params[] = $qId; dbRun('UPDATE pulse_questions SET ' . implode(', ', $fields) . ' WHERE id=?', $params); }
        $q = dbOne('SELECT * FROM pulse_questions WHERE id=?', [$qId]);
        $q['options'] = $q['options_json'] ? json_decode($q['options_json'], true) : null;
        unset($q['options_json']);
        json($q);
        return;
    }

    if ($method === 'DELETE') {
        dbRun('DELETE FROM pulse_responses WHERE question_id=?', [$qId]);
        dbRun('DELETE FROM pulse_questions WHERE id=?', [$qId]);
        json(['ok' => true]);
        return;
    }

    jsonError(405, 'Method not allowed');
}

function handlePulseQuestionOpen(int $qId): void {
    $s = requireAuth();
    requireInstructor($s);
    $q = dbOne(
        'SELECT pq.*, pc.course_id FROM pulse_questions pq
         JOIN pulse_checks pc ON pc.id = pq.check_id
         WHERE pq.id=?',
        [$qId]
    );
    if (!$q || $q['course_id'] != $s['cid']) jsonError(404, 'Question not found');
    $newVal = $q['is_open'] ? 0 : 1;
    dbRun('UPDATE pulse_questions SET is_open=? WHERE id=?', [$newVal, $qId]);
    json(['is_open' => $newVal]);
}

function handlePulseQuestionReveal(int $qId): void {
    $s = requireAuth();
    requireInstructor($s);
    $q = dbOne(
        'SELECT pq.*, pc.course_id FROM pulse_checks pc
         JOIN pulse_questions pq ON pq.check_id = pc.id
         WHERE pq.id=?',
        [$qId]
    );
    if (!$q || $q['course_id'] != $s['cid']) jsonError(404, 'Question not found');
    $newVal = $q['results_visible'] ? 0 : 1;
    dbRun('UPDATE pulse_questions SET results_visible=? WHERE id=?', [$newVal, $qId]);
    json(['results_visible' => $newVal]);
}

function handlePulseRespond(int $qId, array $body): void {
    $s = optionalAuth();
    $q = dbOne(
        'SELECT pq.*, pc.course_id, pc.status AS check_status, pc.access
         FROM pulse_questions pq
         JOIN pulse_checks pc ON pc.id = pq.check_id
         WHERE pq.id=?',
        [$qId]
    );
    if (!$q) jsonError(404, 'Question not found');
    if (!$q['is_open']) jsonError(400, 'This question is not accepting responses');
    if ($q['check_status'] !== 'active') jsonError(400, 'This pulse check is not active');

    // Auth check: course access requires session; public allows anonymous
    if ($q['access'] === 'course') {
        if (!$s || $s['cid'] != $q['course_id']) jsonError(403, 'Not enrolled in this course');
    }

    $response = trim($body['response'] ?? '');
    if ($response === '') jsonError(400, 'Response is required');

    // Validate by type
    $type = $q['type'];
    if ($type === 'choice') {
        $opts = json_decode($q['options_json'] ?? '[]', true);
        if (!isset($opts[(int)$response])) jsonError(400, 'Invalid option');
        $response = (string)(int)$response;
    } elseif ($type === 'rating') {
        $cfg = json_decode($q['options_json'] ?? '{}', true);
        $val = (int)$response;
        if ($val < ($cfg['min'] ?? 1) || $val > ($cfg['max'] ?? 5)) jsonError(400, 'Rating out of range');
        $response = (string)$val;
    } elseif ($type === 'text') {
        $response = mb_substr($response, 0, 500);
    } elseif ($type === 'wordcloud') {
        $response = mb_strtolower(mb_substr(trim($response), 0, 50));
    }

    $uid = $s ? $s['uid'] : null;

    if ($uid !== null) {
        // Authenticated: update if existing response
        $existing = dbOne('SELECT id FROM pulse_responses WHERE question_id=? AND user_id=?', [$qId, $uid]);
        if ($existing) {
            dbRun('UPDATE pulse_responses SET response=?, created_at=? WHERE id=?',
                  [$response, time(), $existing['id']]);
        } else {
            dbExec('INSERT INTO pulse_responses (question_id, user_id, response) VALUES (?, ?, ?)',
                   [$qId, $uid, $response]);
        }
    } else {
        // Anonymous: always insert
        dbExec('INSERT INTO pulse_responses (question_id, user_id, response) VALUES (?, NULL, ?)',
               [$qId, $response]);
    }

    json(['ok' => true]);
}

function handleQuestionResults(int $qId): void {
    $s = optionalAuth();
    $q = dbOne(
        'SELECT pq.*, pc.course_id, pc.access
         FROM pulse_questions pq
         JOIN pulse_checks pc ON pc.id = pq.check_id
         WHERE pq.id=?',
        [$qId]
    );
    if (!$q) jsonError(404, 'Question not found');

    $isInstructor = $s && $s['cid'] == $q['course_id'] && $s['role'] === 'instructor';

    if (!$isInstructor && !$q['results_visible']) {
        jsonError(403, 'Results not yet revealed');
    }
    if (!$isInstructor && $q['access'] === 'course' && (!$s || $s['cid'] != $q['course_id'])) {
        jsonError(403, 'Not enrolled in this course');
    }

    $q['options'] = $q['options_json'] ? json_decode($q['options_json'], true) : null;
    unset($q['options_json']);
    $count = (int)(dbOne('SELECT COUNT(*) AS n FROM pulse_responses WHERE question_id=?', [$qId])['n'] ?? 0);
    json(['question' => $q, 'results' => aggregateResponses($qId, $q), 'count' => $count]);
}

function aggregateResponses(int $questionId, array $q): array {
    $type      = $q['type'];
    $responses = dbAll('SELECT response FROM pulse_responses WHERE question_id=?', [$questionId]);
    $total     = count($responses);

    if ($type === 'choice') {
        $opts   = $q['options'] ?? (json_decode($q['options_json'] ?? '[]', true));
        $counts = array_fill(0, count($opts), 0);
        foreach ($responses as $r) {
            $idx = (int)$r['response'];
            if (isset($counts[$idx])) $counts[$idx]++;
        }
        $result = [];
        foreach ($opts as $i => $label) {
            $result[] = [
                'label'   => $label,
                'count'   => $counts[$i],
                'percent' => $total > 0 ? round($counts[$i] / $total * 100) : 0,
            ];
        }
        return $result;

    } elseif ($type === 'rating') {
        $cfg = $q['options'] ?? (json_decode($q['options_json'] ?? '{}', true));
        $min = (int)($cfg['min'] ?? 1);
        $max = (int)($cfg['max'] ?? 5);
        $counts = array_fill($min, $max - $min + 1, 0);
        $sum = 0;
        foreach ($responses as $r) {
            $v = (int)$r['response'];
            if ($v >= $min && $v <= $max) {
                $counts[$v]++;
                $sum += $v;
            }
        }
        $result = [];
        for ($v = $min; $v <= $max; $v++) {
            $result[] = [
                'value'   => $v,
                'count'   => $counts[$v],
                'percent' => $total > 0 ? round($counts[$v] / $total * 100) : 0,
            ];
        }
        return ['bars' => $result, 'mean' => $total > 0 ? round($sum / $total, 2) : null,
                'min_label' => $cfg['min_label'] ?? '', 'max_label' => $cfg['max_label'] ?? ''];

    } elseif ($type === 'text') {
        return array_slice(array_map(fn($r) => $r['response'], $responses), 0, 200);

    } elseif ($type === 'wordcloud') {
        $words = [];
        foreach ($responses as $r) {
            $w = strtolower(trim($r['response']));
            if ($w) $words[$w] = ($words[$w] ?? 0) + 1;
        }
        arsort($words);
        $result = [];
        foreach ($words as $word => $count) {
            $result[] = ['word' => $word, 'count' => $count];
        }
        return $result;
    }

    return [];
}

function handlePublicPulse(string $token): void {
    if (!$token) jsonError(400, 'Token required');
    $check = dbOne(
        "SELECT pc.*, c.title AS course_title
         FROM pulse_checks pc
         JOIN courses c ON c.id = pc.course_id
         WHERE pc.share_token=? AND pc.access='public'",
        [$token]
    );
    if (!$check) jsonError(404, 'Pulse check not found');
    if ($check['status'] === 'draft') jsonError(404, 'Pulse check not available');

    $questions = dbAll(
        'SELECT id, question, type, options_json, sort_order, is_open, results_visible
         FROM pulse_questions WHERE check_id=? ORDER BY sort_order ASC, id ASC',
        [$check['id']]
    );
    foreach ($questions as &$q) {
        $q['options'] = $q['options_json'] ? json_decode($q['options_json'], true) : null;
        unset($q['options_json']);
        if ($q['results_visible']) {
            $q['results'] = aggregateResponses($q['id'], $q);
            $q['response_count'] = (int)(dbOne('SELECT COUNT(*) AS n FROM pulse_responses WHERE question_id=?', [$q['id']])['n'] ?? 0);
        }
    }

    json(['check' => $check, 'questions' => $questions]);
}

function handlePublicPulseAction(string $token, int $qId, string $action, array $body): void {
    if (!$token) jsonError(400, 'Token required');
    $check = dbOne(
        "SELECT pc.* FROM pulse_checks pc
         WHERE pc.share_token=? AND pc.access='public' AND pc.status='active'",
        [$token]
    );
    if (!$check) jsonError(404, 'Pulse check not found or not active');

    if ($action === 'respond' && $qId) {
        $q = dbOne(
            'SELECT * FROM pulse_questions WHERE id=? AND check_id=?',
            [$qId, $check['id']]
        );
        if (!$q) jsonError(404, 'Question not found');
        if (!$q['is_open']) jsonError(400, 'This question is not accepting responses');

        $response = trim($body['response'] ?? '');
        if ($response === '') jsonError(400, 'Response is required');

        $type = $q['type'];
        if ($type === 'choice') {
            $opts = json_decode($q['options_json'] ?? '[]', true);
            if (!isset($opts[(int)$response])) jsonError(400, 'Invalid option');
            $response = (string)(int)$response;
        } elseif ($type === 'rating') {
            $cfg = json_decode($q['options_json'] ?? '{}', true);
            $val = (int)$response;
            if ($val < ($cfg['min'] ?? 1) || $val > ($cfg['max'] ?? 5)) jsonError(400, 'Rating out of range');
            $response = (string)$val;
        } elseif ($type === 'text') {
            $response = mb_substr($response, 0, 500);
        } elseif ($type === 'wordcloud') {
            $response = mb_strtolower(mb_substr(trim($response), 0, 50));
        }

        // Anonymous insert always
        dbExec('INSERT INTO pulse_responses (question_id, user_id, response) VALUES (?, NULL, ?)',
               [$qId, $response]);
        json(['ok' => true]);
        return;
    }

    jsonError(404, 'Not found');
}

function handleFileDownload(int $subId): void {
    $s = requireAuth();
    $sub = dbOne(
        'SELECT ps.*, pa.course_id, pa.status AS asgn_status
         FROM pf_submissions ps
         JOIN pf_assignments pa ON pa.id = ps.assignment_id
         WHERE ps.id=?',
        [$subId]
    );
    if (!$sub || $sub['course_id'] != $s['cid']) jsonError(404, 'File not found');

    // Permission check: author, assigned reviewer, or instructor
    $canAccess = false;
    if ($s['role'] === 'instructor') $canAccess = true;
    if ((int)$sub['author_id'] === $s['uid']) $canAccess = true;
    if (!$canAccess) {
        $ra = dbOne(
            'SELECT id FROM pf_review_assignments WHERE submission_id=? AND reviewer_id=?',
            [$subId, $s['uid']]
        );
        if ($ra) $canAccess = true;
    }
    if (!$canAccess) jsonError(403, 'Access denied');
    if (!$sub['file_path'] || !file_exists($sub['file_path'])) jsonError(404, 'File not found on server');

    // Serve the file
    header('Content-Type: ' . ($sub['file_mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($sub['file_path']));
    header('Content-Disposition: attachment; filename="' . addslashes($sub['file_name'] ?: 'submission') . '"');
    header('Cache-Control: no-store');
    // Remove JSON content-type set earlier
    header_remove('Content-Type');
    header('Content-Type: ' . ($sub['file_mime'] ?: 'application/octet-stream'));
    readfile($sub['file_path']);
    exit;
}
