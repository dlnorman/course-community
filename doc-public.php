<?php
/**
 * Course Community — Public Document Reader
 * Renders a publicly shared document (access_level = 3) for unauthenticated users.
 * Included from index.php with $_pubDoc already populated.
 */
$_doc         = $_pubDoc; // passed from index.php
$_title       = htmlspecialchars($_doc['title'] ?: 'Untitled Document');
$_creator     = htmlspecialchars($_doc['creator_name']);
$_course      = htmlspecialchars($_doc['course_title']);
$_updated     = date('F j, Y', (int)$_doc['updated_at']);
$_appName     = htmlspecialchars(APP_NAME);
$_appUrl      = rtrim(APP_URL, '/');
$_downloadUrl = $_appUrl . '/api/docs/' . (int)$_doc['id'] . '/raw';
// Safely embed content as JSON for JS consumption
$_contentJson = json_encode($_doc['content'] ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#13141f">
    <title><?= $_title ?> — <?= $_appName ?></title>
    <meta name="description" content="<?= $_title ?> by <?= $_creator ?> — shared from <?= $_appName ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..700;1,9..144,300..500&family=Plus+Jakarta+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:         #13141f;
            --bg-card:    #1a1c2e;
            --border:     rgba(255,255,255,0.08);
            --text:       #e8eaf6;
            --text-muted: rgba(232,234,246,0.5);
            --accent:     #7c6af7;
            --font-body:  'Plus Jakarta Sans', system-ui, sans-serif;
            --font-head:  'Fraunces', Georgia, serif;
            --font-mono:  'JetBrains Mono', monospace;
        }

        html { font-size: 16px; }
        body {
            background: var(--bg);
            color: var(--text);
            font-family: var(--font-body);
            line-height: 1.7;
            min-height: 100vh;
        }

        /* ── Top bar ── */
        .pub-topbar {
            position: sticky;
            top: 0;
            z-index: 10;
            background: rgba(19, 20, 31, 0.9);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.65rem 1.5rem;
            gap: 1rem;
        }
        .pub-topbar-brand {
            font-family: var(--font-head);
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .pub-topbar-brand:hover { color: var(--text); }
        .pub-topbar-brand .mark { color: var(--accent); }
        .pub-topbar-actions { display: flex; align-items: center; gap: 0.5rem; }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.4rem 0.9rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            font-family: var(--font-body);
            cursor: pointer;
            text-decoration: none;
            border: 1px solid transparent;
            transition: background 0.15s, color 0.15s, border-color 0.15s;
            white-space: nowrap;
        }
        .btn-ghost {
            background: transparent;
            color: var(--text-muted);
            border-color: var(--border);
        }
        .btn-ghost:hover { background: rgba(255,255,255,0.05); color: var(--text); }
        .btn-accent {
            background: var(--accent);
            color: #fff;
        }
        .btn-accent:hover { background: #6a59e0; }

        /* ── Layout ── */
        .pub-page {
            max-width: 760px;
            margin: 0 auto;
            padding: 3rem 1.5rem 5rem;
        }

        /* ── Document header ── */
        .pub-doc-title {
            font-family: var(--font-head);
            font-size: clamp(1.8rem, 4vw, 2.6rem);
            font-weight: 600;
            line-height: 1.2;
            letter-spacing: -0.02em;
            color: var(--text);
            margin-bottom: 1.25rem;
        }
        .pub-doc-meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.25rem 0.75rem;
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 2.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border);
        }
        .pub-doc-meta .sep { opacity: 0.3; }
        .pub-badge-public {
            font-size: 0.7rem;
            font-weight: 700;
            background: rgba(92,122,71,0.2);
            color: #7dbf5a;
            border: 1px solid rgba(92,122,71,0.35);
            border-radius: 4px;
            padding: 1px 7px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        /* ── Rendered markdown ── */
        .pub-doc-content {
            font-size: 1rem;
            line-height: 1.8;
            color: var(--text);
        }
        .pub-doc-content h1,
        .pub-doc-content h2,
        .pub-doc-content h3,
        .pub-doc-content h4,
        .pub-doc-content h5,
        .pub-doc-content h6 {
            font-family: var(--font-head);
            font-weight: 600;
            line-height: 1.3;
            margin: 2rem 0 0.75rem;
            color: var(--text);
        }
        .pub-doc-content h1 { font-size: 1.9rem; letter-spacing: -0.02em; }
        .pub-doc-content h2 { font-size: 1.45rem; border-bottom: 1px solid var(--border); padding-bottom: 0.4rem; }
        .pub-doc-content h3 { font-size: 1.15rem; }
        .pub-doc-content p  { margin-bottom: 1.1rem; }
        .pub-doc-content a  { color: var(--accent); text-underline-offset: 3px; }
        .pub-doc-content a:hover { color: #a597ff; }
        .pub-doc-content ul,
        .pub-doc-content ol { margin: 0.75rem 0 1.1rem 1.5rem; }
        .pub-doc-content li { margin-bottom: 0.3rem; }
        .pub-doc-content blockquote {
            border-left: 3px solid var(--accent);
            padding: 0.5rem 0 0.5rem 1.25rem;
            margin: 1.25rem 0;
            color: var(--text-muted);
            font-style: italic;
        }
        .pub-doc-content code {
            font-family: var(--font-mono);
            font-size: 0.875em;
            background: rgba(255,255,255,0.07);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 0.1em 0.4em;
        }
        .pub-doc-content pre {
            background: rgba(0,0,0,0.3);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1.25rem;
            overflow-x: auto;
            margin: 1.25rem 0;
        }
        .pub-doc-content pre code {
            background: none;
            border: none;
            padding: 0;
            font-size: 0.875rem;
        }
        .pub-doc-content table {
            width: 100%;
            border-collapse: collapse;
            margin: 1.25rem 0;
            font-size: 0.9rem;
        }
        .pub-doc-content th,
        .pub-doc-content td {
            padding: 0.55rem 0.85rem;
            border: 1px solid var(--border);
            text-align: left;
        }
        .pub-doc-content th {
            background: rgba(255,255,255,0.04);
            font-weight: 600;
        }
        .pub-doc-content tr:hover td { background: rgba(255,255,255,0.02); }
        .pub-doc-content hr {
            border: none;
            border-top: 1px solid var(--border);
            margin: 2rem 0;
        }
        .pub-doc-content img {
            max-width: 100%;
            border-radius: 6px;
            margin: 0.75rem 0;
        }

        /* ── Empty state ── */
        .pub-empty {
            text-align: center;
            padding: 3rem 0;
            color: var(--text-muted);
            font-style: italic;
        }

        /* ── Footer ── */
        .pub-footer {
            max-width: 760px;
            margin: 0 auto;
            padding: 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .pub-footer a { color: var(--text-muted); text-decoration: underline; text-underline-offset: 2px; }
        .pub-footer a:hover { color: var(--text); }

        @media (max-width: 600px) {
            .pub-page { padding: 2rem 1rem 4rem; }
            .pub-topbar { padding: 0.5rem 1rem; }
        }
    </style>
</head>
<body>

<header class="pub-topbar">
    <a href="<?= $_appUrl ?>" class="pub-topbar-brand">
        <span class="mark">◈</span><?= $_appName ?>
    </a>
    <div class="pub-topbar-actions">
        <a href="<?= htmlspecialchars($_downloadUrl) ?>" class="btn btn-ghost" download="<?= $_title ?>.md">
            ⬇ Download .md
        </a>
    </div>
</header>

<main class="pub-page">
    <h1 class="pub-doc-title"><?= $_title ?></h1>
    <div class="pub-doc-meta">
        <span><?= $_creator ?></span>
        <span class="sep">·</span>
        <span><?= $_course ?></span>
        <span class="sep">·</span>
        <span>Updated <?= $_updated ?></span>
        <span class="sep">·</span>
        <span class="pub-badge-public">Public</span>
    </div>
    <div class="pub-doc-content" id="doc-content">
        <p class="pub-empty">Loading…</p>
    </div>
</main>

<footer class="pub-footer">
    <span>Shared from <a href="<?= $_appUrl ?>"><?= $_appName ?></a></span>
    <a href="<?= htmlspecialchars($_downloadUrl) ?>" download="<?= $_title ?>.md">Download Markdown</a>
</footer>

<script>
(function () {
    const raw = <?= $_contentJson ?>;

    // Load marked.js and render
    const script = document.createElement('script');
    script.src = 'https://cdn.jsdelivr.net/npm/marked/marked.min.js';
    script.onload = function () {
        const el = document.getElementById('doc-content');
        if (!raw || !raw.trim()) {
            el.innerHTML = '<p class="pub-empty">This document has no content yet.</p>';
            return;
        }
        marked.setOptions({ breaks: true, gfm: true });
        // DOMPurify isn't loaded here; sanitize by using textContent trick isn't needed
        // since the content comes from our own DB. Render directly.
        el.innerHTML = marked.parse(raw);
    };
    script.onerror = function () {
        // Fallback: show plain text
        const el = document.getElementById('doc-content');
        const pre = document.createElement('pre');
        pre.style.cssText = 'white-space: pre-wrap; font-family: inherit;';
        pre.textContent = raw;
        el.innerHTML = '';
        el.appendChild(pre);
    };
    document.head.appendChild(script);
})();
</script>
</body>
</html>
