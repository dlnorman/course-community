<?php
/**
 * Course Community - LTI 1.3 Handler
 *
 * Endpoints:
 *   GET/POST ?action=login   — OIDC login initiation (from Brightspace)
 *   POST     ?action=launch  — JWT validation, session creation
 *   GET      ?action=dev     — Dev mode bypass (DEV_MODE only)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'login';

try {
    match ($action) {
        'login'  => handleLogin(),
        'launch' => handleLaunch(),
        'dev'    => handleDevMode(),
        default  => sendError(400, 'Unknown action'),
    };
} catch (Throwable $e) {
    $detail = '[LTI] ' . $e->getMessage() . ' (action=' . $action . ')';
    error_log($detail);
    $showDetail = DEV_MODE || filter_var(getenv('CC_DEBUG'), FILTER_VALIDATE_BOOLEAN);
    sendError(500, $showDetail ? $e->getMessage() . ' (action=' . $action . ')' : 'LTI authentication failed. Please try relaunching from your course.');
}

// ── Platform registry helpers ─────────────────────────────────────────────────

/**
 * Look up a registered platform by issuer and optional client_id.
 *
 * Supports two $LTI_PLATFORMS key formats:
 *   1. Simple:   'https://lms.example.com'            => [...]
 *   2. Compound: 'https://lms.example.com::client123' => [...]
 *
 * Compound keys allow multiple tool registrations (client IDs) for the
 * same LMS instance. The lookup tries the compound key first, then falls
 * back to the simple issuer key.
 */
function findPlatform(string $iss, string $clientId = ''): ?array {
    global $LTI_PLATFORMS;
    if ($clientId && isset($LTI_PLATFORMS["$iss::$clientId"])) {
        return $LTI_PLATFORMS["$iss::$clientId"];
    }
    return $LTI_PLATFORMS[$iss] ?? null;
}

// ── OIDC Login Initiation ─────────────────────────────────────────────────────

function handleLogin(): void {
    $iss             = trim($_REQUEST['iss'] ?? '');
    $loginHint       = trim($_REQUEST['login_hint'] ?? '');
    $targetLinkUri   = trim($_REQUEST['target_link_uri'] ?? APP_URL . '/');
    $ltiMessageHint  = trim($_REQUEST['lti_message_hint'] ?? '');
    $clientId        = trim($_REQUEST['client_id'] ?? '');

    if (!$iss) sendError(400, 'Missing iss parameter');

    $platform = findPlatform($iss, $clientId);
    if (!$platform) sendError(400, "Unregistered platform: $iss");

    // Validate client_id from request against registered config (reject spoofed IDs)
    if ($clientId && $clientId !== $platform['client_id']) {
        sendError(400, 'Unregistered client_id for this platform');
    }

    $cid = $platform['client_id'];

    // Generate state + nonce
    $state = bin2hex(random_bytes(16));
    $nonce = bin2hex(random_bytes(16));

    // Store state in DB (expires in 10 minutes)
    cleanExpiredLtiState();
    dbExec(
        'INSERT INTO lti_states (state, data_json, expires_at) VALUES (?, ?, ?)',
        [$state, json_encode(['iss' => $iss, 'nonce' => $nonce, 'target_link_uri' => $targetLinkUri]), time() + 600]
    );

    // Store nonce
    dbExec(
        'INSERT OR REPLACE INTO lti_nonces (nonce, expires_at) VALUES (?, ?)',
        [$nonce, time() + 600]
    );

    $redirectUri = APP_URL . '/lti.php?action=launch';

    $params = http_build_query([
        'scope'           => 'openid',
        'response_type'   => 'id_token',
        'client_id'       => $cid,
        'redirect_uri'    => $redirectUri,
        'login_hint'      => $loginHint,
        'lti_message_hint'=> $ltiMessageHint,
        'state'           => $state,
        'nonce'           => $nonce,
        'response_mode'   => 'form_post',
        'prompt'          => 'none',
    ]);

    header('Location: ' . $platform['auth_endpoint'] . '?' . $params);
    exit;
}

// ── JWT Launch ────────────────────────────────────────────────────────────────

function handleLaunch(): void {
    $idToken = $_POST['id_token'] ?? '';
    $state   = $_POST['state']    ?? '';

    if (!$idToken || !$state) sendError(400, 'Missing id_token or state');

    // Validate state
    $stateRow = dbOne('SELECT * FROM lti_states WHERE state = ? AND expires_at > ?', [$state, time()]);
    if (!$stateRow) sendError(400, 'Invalid or expired state');
    $stateData = json_decode($stateRow['data_json'], true);
    dbRun('DELETE FROM lti_states WHERE state = ?', [$state]);

    $iss = $stateData['iss'];
    $platform = findPlatform($iss);
    if (!$platform) sendError(400, 'Unregistered platform');

    // Decode JWT header to find key id
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) sendError(400, 'Malformed JWT');

    $header = json_decode(base64UrlDecode($parts[0]), true);
    $kid    = $header['kid'] ?? null;

    // Fetch JWKS and find matching key
    $jwks = fetchJwks($platform['jwks_uri']);
    $jwk  = findJwk($jwks, $kid);
    if (!$jwk) sendError(400, 'No matching JWK found');

    $publicKey = jwkToPem($jwk);

    // Verify signature
    $payload = verifyJwt($idToken, $publicKey);

    // Validate standard claims
    $now = time();
    if (($payload['exp'] ?? 0) < $now)          sendError(400, 'JWT expired');
    if (($payload['iat'] ?? 0) > $now + 30)      sendError(400, 'JWT issued in the future');
    if (($payload['iss'] ?? '') !== $iss)         sendError(400, 'JWT iss mismatch');

    $aud = $payload['aud'] ?? '';
    $cid = $platform['client_id'];
    if (is_array($aud) ? !in_array($cid, $aud) : $aud !== $cid) {
        sendError(400, 'JWT aud mismatch');
    }

    // Validate nonce
    $nonce = $payload['nonce'] ?? '';
    $nonceRow = dbOne('SELECT * FROM lti_nonces WHERE nonce = ? AND expires_at > ?', [$nonce, $now]);
    if (!$nonceRow) sendError(400, 'Invalid or expired nonce');
    dbRun('DELETE FROM lti_nonces WHERE nonce = ?', [$nonce]);

    // Validate LTI version
    $ltiVersion = $payload['https://purl.imsglobal.org/spec/lti/claim/version'] ?? '';
    if ($ltiVersion !== '1.3.0') sendError(400, "Unsupported LTI version: $ltiVersion");

    // Extract context (course)
    $context = $payload['https://purl.imsglobal.org/spec/lti/claim/context'] ?? null;
    if (!$context || empty($context['id'])) sendError(400, 'No course context in JWT');

    $roles     = $payload['https://purl.imsglobal.org/spec/lti/claim/roles'] ?? [];
    $isInstructor = false;
    foreach ($roles as $r) {
        if (str_contains($r, '#Instructor') || str_contains($r, '#TeachingAssistant')) {
            $isInstructor = true;
            break;
        }
    }
    $role = $isInstructor ? 'instructor' : 'student';

    // Upsert user
    $sub  = $payload['sub'];
    $name = trim(($payload['given_name'] ?? '') . ' ' . ($payload['family_name'] ?? ''))
          ?: ($payload['name'] ?? 'Unknown');
    dbRun(
        'INSERT INTO users (sub, issuer, name, given_name, family_name, email, picture)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(sub, issuer) DO UPDATE SET
           name=excluded.name, given_name=excluded.given_name,
           family_name=excluded.family_name, email=excluded.email,
           picture=excluded.picture',
        [
            $sub, $iss, $name,
            $payload['given_name'] ?? '',
            $payload['family_name'] ?? '',
            $payload['email'] ?? '',
            $payload['picture'] ?? '',
        ]
    );
    $user = dbOne('SELECT id FROM users WHERE sub = ? AND issuer = ?', [$sub, $iss]);

    // Upsert course — keyed by (issuer, context_id) to prevent cross-platform collisions
    $contextId    = $context['id'];
    $courseTitle  = $context['title'] ?? 'Untitled Course';
    $courseLabel  = $context['label'] ?? '';
    dbRun(
        'INSERT INTO courses (issuer, context_id, title, label) VALUES (?, ?, ?, ?)
         ON CONFLICT(issuer, context_id) DO UPDATE SET title=excluded.title, label=excluded.label',
        [$iss, $contextId, $courseTitle, $courseLabel]
    );
    $course = dbOne('SELECT id FROM courses WHERE issuer = ? AND context_id = ?', [$iss, $contextId]);

    // Upsert enrollment
    dbRun(
        'INSERT INTO enrollments (user_id, course_id, role, last_seen) VALUES (?, ?, ?, ?)
         ON CONFLICT(user_id, course_id) DO UPDATE SET role=excluded.role, last_seen=excluded.last_seen',
        [$user['id'], $course['id'], $role, time()]
    );

    // Ensure default spaces exist for new courses
    ensureDefaultSpaces($course['id']);

    // Create session
    createSession($user['id'], $course['id'], $role);

    header('Location: ' . APP_URL . '/');
    exit;
}

// ── Dev Mode ──────────────────────────────────────────────────────────────────

function handleDevMode(): void {
    if (!DEV_MODE) sendError(403, 'Dev mode is disabled');

    // Upsert dev user
    $d = DEV_USER;
    $c = DEV_COURSE;
    $iss = 'dev';

    dbRun(
        'INSERT INTO users (sub, issuer, name, given_name, family_name, email)
         VALUES (?, ?, ?, ?, ?, ?)
         ON CONFLICT(sub, issuer) DO UPDATE SET name=excluded.name',
        [$d['sub'], $iss, $d['name'], $d['given_name'], $d['family_name'], $d['email']]
    );
    $user = dbOne('SELECT id FROM users WHERE sub = ? AND issuer = ?', [$d['sub'], $iss]);

    dbRun(
        'INSERT INTO courses (issuer, context_id, title, label) VALUES (?, ?, ?, ?)
         ON CONFLICT(issuer, context_id) DO UPDATE SET title=excluded.title',
        [$iss, $c['context_id'], $c['title'], $c['label']]
    );
    $course = dbOne('SELECT id FROM courses WHERE issuer = ? AND context_id = ?', [$iss, $c['context_id']]);

    $role = $d['role'];
    dbRun(
        'INSERT INTO enrollments (user_id, course_id, role, last_seen) VALUES (?, ?, ?, ?)
         ON CONFLICT(user_id, course_id) DO UPDATE SET role=excluded.role, last_seen=excluded.last_seen',
        [$user['id'], $course['id'], $role, time()]
    );

    // Also seed a second dev user (student) for testing interactions
    dbRun(
        'INSERT INTO users (sub, issuer, name, given_name, family_name, email)
         VALUES (?, ?, ?, ?, ?, ?)
         ON CONFLICT(sub, issuer) DO NOTHING',
        ['dev-user-2', $iss, 'Sam Student', 'Sam', 'Student', 'sam@example.com']
    );
    $student = dbOne('SELECT id FROM users WHERE sub = ? AND issuer = ?', ['dev-user-2', $iss]);
    dbRun(
        'INSERT INTO enrollments (user_id, course_id, role) VALUES (?, ?, ?)
         ON CONFLICT(user_id, course_id) DO NOTHING',
        [$student['id'], $course['id'], 'student']
    );

    ensureDefaultSpaces($course['id']);

    // Seed sample content if empty
    seedSampleContent($user['id'], $student['id'], $course['id']);

    createSession($user['id'], $course['id'], $role);

    header('Location: ' . APP_URL . '/');
    exit;
}

function seedSampleContent(int $instructorId, int $studentId, int $courseId): void {
    $existingPosts = dbOne('SELECT id FROM posts WHERE course_id = ? LIMIT 1', [$courseId]);
    if ($existingPosts) return;

    $spaces = dbAll('SELECT id, type FROM spaces WHERE course_id = ?', [$courseId]);
    $spaceMap = [];
    foreach ($spaces as $s) $spaceMap[$s['type']] = $s['id'];

    // Pinned announcement
    if (isset($spaceMap['announcement'])) {
        dbExec(
            "INSERT INTO posts (space_id, course_id, author_id, type, title, content, is_pinned)
             VALUES (?, ?, ?, 'discussion', ?, ?, 1)",
            [$spaceMap['announcement'], $courseId, $instructorId,
             'Welcome to our Course Community!',
             "This space is here for us to think, question, create, and support each other — not just consume content.\n\nExpect to see discussions, questions, shared resources, and peer kudos here. You're encouraged to contribute in whatever way feels authentic to you.\n\nIf you have questions about how this works, post in the Q&A space. Looking forward to learning together! 🌱"]
        );
    }

    // Sample question
    if (isset($spaceMap['qa'])) {
        $qId = dbExec(
            "INSERT INTO posts (space_id, course_id, author_id, type, title, content)
             VALUES (?, ?, ?, 'question', ?, ?)",
            [$spaceMap['qa'], $courseId, $studentId,
             'What\'s the difference between formative and summative assessment?',
             "I keep seeing these terms but I\'m not 100% sure I understand the distinction in practice. Could someone explain with an example from a real classroom context?"]
        );
        dbExec(
            "INSERT INTO comments (post_id, author_id, content, is_answer)
             VALUES (?, ?, ?, 1)",
            [$qId, $instructorId,
             "Great question! **Formative** assessment happens *during* learning — its purpose is to give feedback that shapes what comes next (e.g., exit tickets, low-stakes quizzes, peer review drafts). **Summative** assessment happens *at the end* of a learning period and measures achievement (e.g., final exams, major papers).\n\nThe key is intent: formative is about informing and adjusting; summative is about evaluating and grading."]
        );
    }

    // Sample resource
    if (isset($spaceMap['resources'])) {
        dbExec(
            "INSERT INTO posts (space_id, course_id, author_id, type, title, content, meta_json)
             VALUES (?, ?, ?, 'resource', ?, ?, ?)",
            [$spaceMap['resources'], $courseId, $instructorId,
             'How People Learn (National Academies, free PDF)',
             'This is the foundational research synthesis on learning science. Chapters 1–3 are especially relevant to what we\'re covering this week. Highly recommend bookmarking this.',
             json_encode(['url' => 'https://nap.nationalacademies.org/catalog/24783/how-people-learn-ii-learners-contexts-and-cultures', 'domain' => 'nap.nationalacademies.org'])]
        );
    }

    // Sample kudos
    if (isset($spaceMap['kudos'])) {
        dbExec(
            "INSERT INTO posts (space_id, course_id, author_id, type, title, content, meta_json)
             VALUES (?, ?, ?, 'kudos', ?, ?, ?)",
            [$spaceMap['kudos'], $courseId, $instructorId,
             '🎉 Shoutout to Sam Student',
             'Sam asked an incredibly thoughtful question in Q&A today about assessment — exactly the kind of thinking-out-loud this community is built for. Thank you!',
             json_encode(['recipient_id' => $studentId])]
        );
    }

    // Sample poll
    if (isset($spaceMap['discussion'])) {
        dbExec(
            "INSERT INTO posts (space_id, course_id, author_id, type, title, content, meta_json)
             VALUES (?, ?, ?, 'poll', ?, ?, ?)",
            [$spaceMap['discussion'], $courseId, $instructorId,
             'Quick check-in: How are you feeling about the course so far?',
             'This helps me calibrate the pace and focus. All responses are anonymous.',
             json_encode(['options' => ['Keeping up well, feeling confident', 'Mostly following along', 'A bit lost — would appreciate more support', 'Overwhelmed right now']])]
        );
    }
}

// ── Session ───────────────────────────────────────────────────────────────────

function createSession(int $userId, int $courseId, string $role): void {
    // Clean old sessions
    dbRun('DELETE FROM sessions WHERE expires_at < ?', [time()]);

    $sid = bin2hex(random_bytes(32));
    dbExec(
        'INSERT INTO sessions (id, user_id, course_id, role, expires_at) VALUES (?, ?, ?, ?, ?)',
        [$sid, $userId, $courseId, $role, time() + SESSION_DURATION]
    );

    $cookiePath = rtrim(parse_url(APP_URL, PHP_URL_PATH) ?: '/', '/') . '/';
    setcookie('cc_session', $sid, [
        'expires'  => time() + SESSION_DURATION,
        'path'     => $cookiePath,
        'secure'   => !DEV_MODE,
        'httponly' => true,
        'samesite' => 'None',
    ]);
}

// ── JWT helpers ───────────────────────────────────────────────────────────────

function base64UrlDecode(string $input): string {
    return base64_decode(strtr($input, '-_', '+/') . str_repeat('=', (4 - strlen($input) % 4) % 4));
}

function verifyJwt(string $token, string $publicKey): array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) throw new RuntimeException('Malformed JWT');

    $data      = $parts[0] . '.' . $parts[1];
    $signature = base64UrlDecode($parts[2]);

    $key = openssl_pkey_get_public($publicKey);
    if (!$key) throw new RuntimeException('Invalid public key');

    $result = openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256);
    if ($result !== 1) throw new RuntimeException('Invalid JWT signature');

    $payload = json_decode(base64UrlDecode($parts[1]), true);
    if (!is_array($payload)) throw new RuntimeException('Invalid JWT payload');

    return $payload;
}

function fetchJwks(string $uri): array {
    $ctx = stream_context_create([
        'http' => ['timeout' => 5, 'method' => 'GET'],
        'ssl'  => ['verify_peer' => true],
    ]);
    $json = @file_get_contents($uri, false, $ctx);
    if (!$json) throw new RuntimeException("Failed to fetch JWKS from $uri");
    $data = json_decode($json, true);
    if (!isset($data['keys'])) throw new RuntimeException('Invalid JWKS response');
    return $data['keys'];
}

function findJwk(array $keys, ?string $kid): ?array {
    if ($kid) {
        foreach ($keys as $k) {
            if (($k['kid'] ?? null) === $kid) return $k;
        }
    }
    // Fall back to first RSA key
    foreach ($keys as $k) {
        if (($k['kty'] ?? '') === 'RSA') return $k;
    }
    return null;
}

/**
 * Convert an RSA JWK (with n, e) to a PEM public key.
 * Pure PHP, no external libraries.
 */
function jwkToPem(array $jwk): string {
    $n = base64UrlDecode($jwk['n']);
    $e = base64UrlDecode($jwk['e']);

    $nDer = asn1Integer($n);
    $eDer = asn1Integer($e);

    // SEQUENCE { INTEGER n, INTEGER e }
    $rsaPublicKey = asn1Sequence($nDer . $eDer);

    // BIT STRING (0 unused bits + key)
    $bitString = "\x03" . asn1Length(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;

    // rsaEncryption OID + NULL
    $oid = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $algorithmId = asn1Sequence($oid);

    // SubjectPublicKeyInfo
    $spki = asn1Sequence($algorithmId . $bitString);

    return "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($spki), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
}

function asn1Integer(string $bytes): string {
    $bytes = ltrim($bytes, "\x00");
    if (ord($bytes[0]) & 0x80) $bytes = "\x00" . $bytes;
    return "\x02" . asn1Length(strlen($bytes)) . $bytes;
}

function asn1Sequence(string $data): string {
    return "\x30" . asn1Length(strlen($data)) . $data;
}

function asn1Length(int $len): string {
    if ($len < 128)   return chr($len);
    if ($len < 256)   return "\x81" . chr($len);
    return "\x82" . chr($len >> 8) . chr($len & 0xff);
}

function cleanExpiredLtiState(): void {
    dbRun('DELETE FROM lti_states WHERE expires_at < ?', [time()]);
    dbRun('DELETE FROM lti_nonces WHERE expires_at < ?', [time()]);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function sendError(int $code, string $msg): void {
    http_response_code($code);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem">';
    echo '<h2>Authentication Error</h2>';
    echo '<p>' . htmlspecialchars($msg) . '</p>';
    if (DEV_MODE) {
        echo '<p><a href="' . APP_URL . '/lti.php?action=dev">Re-launch in Dev Mode</a></p>';
    }
    echo '</body></html>';
    exit;
}
