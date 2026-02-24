<?php
/**
 * Course Community - Database Layer
 */

require_once __DIR__ . '/config.php';

function getDb(): PDO {
    static $db = null;
    if ($db !== null) return $db;

    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('PRAGMA synchronous=NORMAL');

    initSchema($db);
    return $db;
}

function initSchema(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS lti_states (
            state       TEXT PRIMARY KEY,
            data_json   TEXT NOT NULL,
            created_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            expires_at  INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS lti_nonces (
            nonce      TEXT PRIMARY KEY,
            expires_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS platforms (
            id         INTEGER PRIMARY KEY,
            issuer     TEXT NOT NULL UNIQUE,
            client_id  TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS courses (
            id         INTEGER PRIMARY KEY,
            issuer     TEXT NOT NULL DEFAULT '',
            context_id TEXT NOT NULL,
            title      TEXT NOT NULL DEFAULT 'Untitled Course',
            label      TEXT,
            created_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            UNIQUE(issuer, context_id)
        );

        CREATE TABLE IF NOT EXISTS users (
            id          INTEGER PRIMARY KEY,
            sub         TEXT NOT NULL,
            issuer      TEXT NOT NULL,
            name        TEXT NOT NULL DEFAULT '',
            given_name  TEXT NOT NULL DEFAULT '',
            family_name TEXT NOT NULL DEFAULT '',
            email       TEXT NOT NULL DEFAULT '',
            picture     TEXT NOT NULL DEFAULT '',
            bio         TEXT NOT NULL DEFAULT '',
            created_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            UNIQUE(sub, issuer)
        );

        CREATE TABLE IF NOT EXISTS enrollments (
            id        INTEGER PRIMARY KEY,
            user_id   INTEGER NOT NULL,
            course_id INTEGER NOT NULL,
            role      TEXT NOT NULL DEFAULT 'student',
            last_seen INTEGER,
            FOREIGN KEY (user_id)   REFERENCES users(id),
            FOREIGN KEY (course_id) REFERENCES courses(id),
            UNIQUE(user_id, course_id)
        );

        CREATE TABLE IF NOT EXISTS spaces (
            id          INTEGER PRIMARY KEY,
            course_id   INTEGER NOT NULL,
            name        TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            icon        TEXT NOT NULL DEFAULT '💬',
            type        TEXT NOT NULL DEFAULT 'discussion',
            color       TEXT NOT NULL DEFAULT '#4A6FA5',
            sort_order  INTEGER NOT NULL DEFAULT 0,
            is_default  INTEGER NOT NULL DEFAULT 0,
            created_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            FOREIGN KEY (course_id) REFERENCES courses(id)
        );

        CREATE TABLE IF NOT EXISTS posts (
            id          INTEGER PRIMARY KEY,
            space_id    INTEGER NOT NULL,
            course_id   INTEGER NOT NULL,
            author_id   INTEGER NOT NULL,
            type        TEXT NOT NULL DEFAULT 'discussion',
            title       TEXT NOT NULL DEFAULT '',
            content     TEXT NOT NULL DEFAULT '',
            meta_json   TEXT NOT NULL DEFAULT '{}',
            is_pinned   INTEGER NOT NULL DEFAULT 0,
            is_featured INTEGER NOT NULL DEFAULT 0,
            is_resolved INTEGER NOT NULL DEFAULT 0,
            vote_count  INTEGER NOT NULL DEFAULT 0,
            view_count  INTEGER NOT NULL DEFAULT 0,
            created_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            updated_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            FOREIGN KEY (space_id)  REFERENCES spaces(id),
            FOREIGN KEY (course_id) REFERENCES courses(id),
            FOREIGN KEY (author_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS comments (
            id                  INTEGER PRIMARY KEY,
            post_id             INTEGER NOT NULL,
            parent_id           INTEGER,
            author_id           INTEGER NOT NULL,
            content             TEXT NOT NULL DEFAULT '',
            is_answer           INTEGER NOT NULL DEFAULT 0,
            is_instructor_note  INTEGER NOT NULL DEFAULT 0,
            vote_count          INTEGER NOT NULL DEFAULT 0,
            created_at          INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            updated_at          INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            FOREIGN KEY (post_id)   REFERENCES posts(id),
            FOREIGN KEY (parent_id) REFERENCES comments(id),
            FOREIGN KEY (author_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS reactions (
            id          INTEGER PRIMARY KEY,
            user_id     INTEGER NOT NULL,
            target_type TEXT NOT NULL,
            target_id   INTEGER NOT NULL,
            emoji       TEXT NOT NULL,
            created_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            UNIQUE(user_id, target_type, target_id, emoji),
            FOREIGN KEY (user_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS votes (
            id          INTEGER PRIMARY KEY,
            user_id     INTEGER NOT NULL,
            target_type TEXT NOT NULL,
            target_id   INTEGER NOT NULL,
            value       INTEGER NOT NULL DEFAULT 1,
            created_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            UNIQUE(user_id, target_type, target_id),
            FOREIGN KEY (user_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS tags (
            id        INTEGER PRIMARY KEY,
            course_id INTEGER NOT NULL,
            name      TEXT NOT NULL,
            color     TEXT NOT NULL DEFAULT '#6B7280',
            UNIQUE(course_id, name)
        );

        CREATE TABLE IF NOT EXISTS post_tags (
            post_id INTEGER NOT NULL,
            tag_id  INTEGER NOT NULL,
            PRIMARY KEY (post_id, tag_id)
        );

        CREATE TABLE IF NOT EXISTS boards (
            id          INTEGER PRIMARY KEY,
            course_id   INTEGER NOT NULL,
            title       TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            prompt      TEXT NOT NULL DEFAULT '',
            created_by  INTEGER NOT NULL,
            created_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            FOREIGN KEY (course_id)  REFERENCES courses(id),
            FOREIGN KEY (created_by) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS board_cards (
            id         INTEGER PRIMARY KEY,
            board_id   INTEGER NOT NULL,
            author_id  INTEGER NOT NULL,
            content    TEXT NOT NULL DEFAULT '',
            color      TEXT NOT NULL DEFAULT '#FFF9C4',
            pos_x      REAL NOT NULL DEFAULT 0,
            pos_y      REAL NOT NULL DEFAULT 0,
            votes      INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            FOREIGN KEY (board_id)  REFERENCES boards(id),
            FOREIGN KEY (author_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS poll_votes (
            id         INTEGER PRIMARY KEY,
            post_id    INTEGER NOT NULL,
            user_id    INTEGER NOT NULL,
            option_idx INTEGER NOT NULL,
            created_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            UNIQUE(post_id, user_id),
            FOREIGN KEY (post_id) REFERENCES posts(id),
            FOREIGN KEY (user_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS notifications (
            id         INTEGER PRIMARY KEY,
            user_id    INTEGER NOT NULL,
            course_id  INTEGER NOT NULL DEFAULT 0,
            type       TEXT NOT NULL,
            message    TEXT NOT NULL,
            link       TEXT NOT NULL DEFAULT '',
            is_read    INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            FOREIGN KEY (user_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS sessions (
            id         TEXT PRIMARY KEY,
            user_id    INTEGER NOT NULL,
            course_id  INTEGER NOT NULL,
            role       TEXT NOT NULL DEFAULT 'student',
            data_json  TEXT NOT NULL DEFAULT '{}',
            created_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            expires_at INTEGER NOT NULL,
            FOREIGN KEY (user_id)   REFERENCES users(id),
            FOREIGN KEY (course_id) REFERENCES courses(id)
        );
    ");

    // Indexes
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_posts_space    ON posts(space_id, created_at DESC);
        CREATE INDEX IF NOT EXISTS idx_posts_course   ON posts(course_id, created_at DESC);
        CREATE INDEX IF NOT EXISTS idx_comments_post  ON comments(post_id);
        CREATE INDEX IF NOT EXISTS idx_reactions      ON reactions(target_type, target_id);
        CREATE INDEX IF NOT EXISTS idx_votes          ON votes(target_type, target_id);
        CREATE INDEX IF NOT EXISTS idx_notifs_user    ON notifications(user_id, course_id, is_read);
        CREATE INDEX IF NOT EXISTS idx_sessions_exp   ON sessions(expires_at);
    ");

    // Peer Feedback Tables
    $db->exec("
        CREATE TABLE IF NOT EXISTS pf_assignments (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id         INTEGER NOT NULL,
            title             TEXT    NOT NULL,
            description       TEXT    NOT NULL DEFAULT '',
            prompts_json      TEXT    NOT NULL DEFAULT '[]',
            allow_text        INTEGER NOT NULL DEFAULT 1,
            allow_files       INTEGER NOT NULL DEFAULT 0,
            accepted_types    TEXT    NOT NULL DEFAULT 'pdf,doc,docx',
            max_file_mb       INTEGER NOT NULL DEFAULT 10,
            reviewers_per_sub INTEGER NOT NULL DEFAULT 2,
            status            TEXT    NOT NULL DEFAULT 'draft',
            created_at        INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            created_by        INTEGER NOT NULL,
            FOREIGN KEY (course_id)  REFERENCES courses(id),
            FOREIGN KEY (created_by) REFERENCES users(id)
        );
        CREATE TABLE IF NOT EXISTS pf_submissions (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            assignment_id INTEGER NOT NULL,
            author_id     INTEGER NOT NULL,
            text_content  TEXT,
            file_path     TEXT,
            file_name     TEXT,
            file_size     INTEGER,
            file_mime     TEXT,
            submitted_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            FOREIGN KEY (assignment_id) REFERENCES pf_assignments(id),
            FOREIGN KEY (author_id)     REFERENCES users(id),
            UNIQUE(assignment_id, author_id)
        );
        CREATE TABLE IF NOT EXISTS pf_review_assignments (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            assignment_id INTEGER NOT NULL,
            submission_id INTEGER NOT NULL,
            reviewer_id   INTEGER NOT NULL,
            assigned_at   INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            completed_at  INTEGER,
            FOREIGN KEY (assignment_id) REFERENCES pf_assignments(id),
            FOREIGN KEY (submission_id) REFERENCES pf_submissions(id),
            FOREIGN KEY (reviewer_id)   REFERENCES users(id),
            UNIQUE(submission_id, reviewer_id)
        );
        CREATE TABLE IF NOT EXISTS pf_responses (
            id                   INTEGER PRIMARY KEY AUTOINCREMENT,
            review_assignment_id INTEGER NOT NULL UNIQUE,
            answers_json         TEXT    NOT NULL DEFAULT '[]',
            overall_comment      TEXT    NOT NULL DEFAULT '',
            submitted_at         INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            FOREIGN KEY (review_assignment_id) REFERENCES pf_review_assignments(id)
        );
        CREATE INDEX IF NOT EXISTS idx_pf_asgn_course  ON pf_assignments(course_id);
        CREATE INDEX IF NOT EXISTS idx_pf_sub_asgn     ON pf_submissions(assignment_id);
        CREATE INDEX IF NOT EXISTS idx_pf_ra_reviewer  ON pf_review_assignments(reviewer_id, completed_at);
        CREATE INDEX IF NOT EXISTS idx_pf_ra_asgn      ON pf_review_assignments(assignment_id);
    ");

    migrateSchema($db);
}

/**
 * Incremental schema migrations for databases created before schema updates.
 * Each migration is idempotent — safe to run on already-migrated databases.
 */
function migrateSchema(PDO $db): void {
    // ── Migration: add issuer to courses, replace single-column UNIQUE ─────────
    $courseCols = array_column(
        $db->query('PRAGMA table_info(courses)')->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    if (!in_array('issuer', $courseCols)) {
        // Disable FK checks during table recreation
        $db->exec('PRAGMA foreign_keys=OFF');
        $db->exec('BEGIN');
        $db->exec("ALTER TABLE courses RENAME TO _courses_old");
        $db->exec("CREATE TABLE courses (
            id         INTEGER PRIMARY KEY,
            issuer     TEXT NOT NULL DEFAULT '',
            context_id TEXT NOT NULL,
            title      TEXT NOT NULL DEFAULT 'Untitled Course',
            label      TEXT,
            created_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            UNIQUE(issuer, context_id)
        )");
        $db->exec("INSERT INTO courses (id, issuer, context_id, title, label, created_at)
                   SELECT id, '', context_id, title, label, created_at FROM _courses_old");
        $db->exec("DROP TABLE _courses_old");
        $db->exec('COMMIT');
        $db->exec('PRAGMA foreign_keys=ON');
    }

    // ── Migration: add course_id to notifications ───────────────────────────────
    $notifCols = array_column(
        $db->query('PRAGMA table_info(notifications)')->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    if (!in_array('course_id', $notifCols)) {
        $db->exec('ALTER TABLE notifications ADD COLUMN course_id INTEGER NOT NULL DEFAULT 0');
    }
    // Ensure updated index exists (DROP + CREATE is safe with IF NOT EXISTS / IF EXISTS)
    $db->exec('DROP INDEX IF EXISTS idx_notifs_user');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_notifs_user ON notifications(user_id, course_id, is_read)');

    // ── Migration: fix unixepoch() defaults → strftime('%s','now') ──────────────
    // Needed when the DB was first created with an old db.php on SQLite < 3.38.0.
    // We detect affected tables by checking their stored schema in sqlite_master.
    $tablesWithUnixepoch = $db->query(
        "SELECT name FROM sqlite_master
          WHERE type='table' AND sql LIKE '%unixepoch()%'"
    )->fetchAll(PDO::FETCH_COLUMN);

    if ($tablesWithUnixepoch) {
        $db->exec('PRAGMA foreign_keys=OFF');
        $db->exec('BEGIN');

        foreach ($tablesWithUnixepoch as $tbl) {
            // Fetch the stored CREATE TABLE SQL and replace the bad function name
            $oldSql = $db->query(
                "SELECT sql FROM sqlite_master WHERE type='table' AND name=" . $db->quote($tbl)
            )->fetchColumn();
            $newSql = str_replace('unixepoch()', "strftime('%s','now')", $oldSql);

            // Recreate the table: rename old, create fixed, copy data, drop old
            $db->exec("ALTER TABLE \"$tbl\" RENAME TO \"_{$tbl}_ue_old\"");
            $db->exec($newSql);
            // Copy all columns that exist in both old and new table
            $cols = array_column(
                $db->query("PRAGMA table_info(\"_{$tbl}_ue_old\")")->fetchAll(PDO::FETCH_ASSOC),
                'name'
            );
            $colList = implode(', ', array_map(fn($c) => "\"$c\"", $cols));
            $db->exec("INSERT INTO \"$tbl\" ($colList) SELECT $colList FROM \"_{$tbl}_ue_old\"");
            $db->exec("DROP TABLE \"_{$tbl}_ue_old\"");
        }

        $db->exec('COMMIT');
        $db->exec('PRAGMA foreign_keys=ON');
    }
}

// ── Query helpers ─────────────────────────────────────────────────────────────

function dbOne(string $sql, array $params = []): ?array {
    $stmt = getDb()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function dbAll(string $sql, array $params = []): array {
    $stmt = getDb()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function dbExec(string $sql, array $params = []): int {
    $db   = getDb();
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int) $db->lastInsertId();
}

function dbRun(string $sql, array $params = []): void {
    $stmt = getDb()->prepare($sql);
    $stmt->execute($params);
}

// ── Default spaces ────────────────────────────────────────────────────────────

function ensureDefaultSpaces(int $courseId): void {
    $existing = dbOne('SELECT id FROM spaces WHERE course_id = ? LIMIT 1', [$courseId]);
    if ($existing) return;

    $defaults = [
        ['📣', 'Announcements',      'Important updates from your instructor.',                     'announcement', '#D4531A', 0, 1],
        ['💬', 'General Discussion', 'Open conversation about the course and beyond.',              'discussion',   '#4A6FA5', 1, 0],
        ['❓', 'Q&A',                'Ask questions and help each other understand the material.',  'qa',           '#5C7A47', 2, 0],
        ['📚', 'Resources',          'Share useful articles, videos, tools, and references.',       'resources',    '#7B5EA7', 3, 0],
        ['🎉', 'Kudos & Wins',       'Celebrate each other\'s efforts and breakthroughs.',          'kudos',        '#C0763A', 4, 0],
    ];

    foreach ($defaults as $d) {
        dbExec(
            'INSERT INTO spaces (course_id, icon, name, description, type, color, sort_order, is_default)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            array_merge([$courseId], $d)
        );
    }
}

// ── Notification helper ───────────────────────────────────────────────────────

function notify(int $userId, int $courseId, string $type, string $message, string $link = ''): void {
    dbExec(
        'INSERT INTO notifications (user_id, course_id, type, message, link) VALUES (?, ?, ?, ?, ?)',
        [$userId, $courseId, $type, $message, $link]
    );
}
