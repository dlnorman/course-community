<?php
/**
 * Course Community — Public Pulse Check Page
 * Renders a public pulse check (access='public') for unauthenticated users.
 * Accessible via /p/{token}
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$token = trim($_GET['token'] ?? '');

$check = null;
$questions = [];
$error = null;

if (!$token) {
    $error = 'No pulse check token provided.';
} else {
    $check = dbOne(
        "SELECT pc.*, c.title AS course_title
         FROM pulse_checks pc
         JOIN courses c ON c.id = pc.course_id
         WHERE pc.share_token=? AND pc.access='public'",
        [$token]
    );
    if (!$check) {
        $error = 'Pulse check not found or not publicly available.';
    } elseif ($check['status'] === 'draft') {
        $error = 'This pulse check has not been activated yet.';
    } else {
        $questions = dbAll(
            'SELECT id, question, type, options_json, sort_order, is_open, results_visible
             FROM pulse_questions WHERE check_id=? ORDER BY sort_order ASC, id ASC',
            [$check['id']]
        );
        foreach ($questions as &$q) {
            $q['options'] = $q['options_json'] ? json_decode($q['options_json'], true) : null;
            if ($q['results_visible']) {
                // Pre-aggregate results for initial render
                $q['results'] = null; // will be loaded by JS
            }
        }
        unset($q);
    }
}

$_appUrl   = rtrim(APP_URL, '/');
$_appName  = htmlspecialchars(APP_NAME);
$_apiBase  = $_appUrl . '/api/pulse-public/' . htmlspecialchars($token);
$_currentUrl = $_appUrl . '/p/' . htmlspecialchars($token);
$_title    = $check ? htmlspecialchars($check['title']) : 'Pulse Check';
$_course   = $check ? htmlspecialchars($check['course_title']) : '';
$_status   = $check['status'] ?? '';

$_checkJson     = json_encode($check, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$_questionsJson = json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$_apiBaseJson   = json_encode($_apiBase);
$_currentUrlJson = json_encode($_currentUrl);
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1C1E2A">
    <title><?= $_title ?> — <?= $_appName ?></title>
    <meta name="description" content="<?= $_title ?> — live response session on <?= $_appName ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..700;1,9..144,300..500&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:          #F7F5F0;
            --bg-card:     #FFFFFF;
            --bg-sidebar:  #1C1E2A;
            --border:      #E2DDD8;
            --text:        #1C1E2A;
            --text-secondary: #5C6070;
            --text-muted:  #9097A6;
            --accent:      #D4531A;
            --accent-light:#FFF1EB;
            --green:       #3A7D44;
            --green-light: #EAF4EC;
            --font-body:   'Plus Jakarta Sans', system-ui, sans-serif;
            --font-head:   'Fraunces', Georgia, serif;
            --radius:      10px;
        }

        html { font-size: 16px; }
        body {
            background: var(--bg);
            color: var(--text);
            font-family: var(--font-body);
            line-height: 1.6;
            min-height: 100vh;
        }

        /* ── Topbar ── */
        .pub-topbar {
            background: var(--bg-sidebar);
            color: #E8EAF6;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1.5rem;
            gap: 1rem;
        }
        .pub-topbar-brand {
            font-family: var(--font-head);
            font-size: 0.95rem;
            font-weight: 600;
            color: rgba(232,234,246,0.7);
            text-decoration: none;
        }
        .pub-topbar-brand .mark { color: #D4531A; }
        .pub-topbar-brand:hover { color: #E8EAF6; }
        .pub-topbar-title {
            font-family: var(--font-head);
            font-size: 1rem;
            font-weight: 600;
            color: #E8EAF6;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 40vw;
        }
        .pub-status-badge {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 2px 8px;
            border-radius: 99px;
            background: rgba(58,125,68,0.3);
            color: #7dbf5a;
            border: 1px solid rgba(58,125,68,0.4);
        }
        .pub-status-badge.closed {
            background: rgba(156,163,175,0.2);
            color: #9CA3AF;
            border-color: rgba(156,163,175,0.3);
        }

        /* ── Layout ── */
        .pub-page {
            max-width: 680px;
            margin: 0 auto;
            padding: 2.5rem 1.5rem 5rem;
        }

        /* ── Check header ── */
        .pub-check-header {
            margin-bottom: 2rem;
        }
        .pub-check-title {
            font-family: var(--font-head);
            font-size: clamp(1.6rem, 4vw, 2.2rem);
            font-weight: 600;
            letter-spacing: -0.02em;
            line-height: 1.2;
            margin-bottom: 0.4rem;
            color: var(--text);
        }
        .pub-check-course {
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        /* ── QR box ── */
        .pub-qr-row {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.25rem 1.5rem;
            margin-bottom: 2rem;
        }
        .pub-qr-box { flex-shrink: 0; }
        .pub-qr-info { flex: 1; min-width: 0; }
        .pub-qr-label {
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            margin-bottom: 0.35rem;
        }
        .pub-qr-url {
            font-family: monospace;
            font-size: 0.9rem;
            color: var(--accent);
            word-break: break-all;
            margin-bottom: 0.5rem;
        }
        .btn-copy-url {
            font-size: 0.8rem;
            font-family: var(--font-body);
            padding: 0.3rem 0.75rem;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: var(--bg);
            color: var(--text-secondary);
            cursor: pointer;
            transition: background 0.15s;
        }
        .btn-copy-url:hover { background: var(--border); }

        /* ── Questions ── */
        .pub-question-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.25rem;
        }
        .pub-question-card.waiting {
            opacity: 0.6;
        }
        .pub-q-header {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .pub-q-num {
            flex-shrink: 0;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--accent-light);
            color: var(--accent);
            font-size: 0.8rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .pub-q-text {
            font-size: 1.05rem;
            font-weight: 600;
            line-height: 1.4;
            color: var(--text);
            flex: 1;
        }
        .pub-q-type {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 1px 6px;
            flex-shrink: 0;
        }

        /* ── Response forms ── */
        .pub-choice-options { display: flex; flex-direction: column; gap: 0.5rem; }
        .pub-choice-btn {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.7rem 1rem;
            border-radius: 8px;
            border: 2px solid var(--border);
            background: var(--bg);
            cursor: pointer;
            text-align: left;
            font-family: var(--font-body);
            font-size: 0.95rem;
            color: var(--text);
            transition: border-color 0.15s, background 0.15s;
        }
        .pub-choice-btn:hover { border-color: var(--accent); background: var(--accent-light); }
        .pub-choice-btn.selected { border-color: var(--accent); background: var(--accent-light); color: var(--accent); font-weight: 600; }
        .pub-choice-letter {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .pub-choice-btn.selected .pub-choice-letter { background: var(--accent); color: #fff; }

        .pub-rating-wrap {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .pub-rating-labels {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .pub-rating-scale {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        .pub-rating-btn {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            border: 2px solid var(--border);
            background: var(--bg);
            font-family: var(--font-body);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: border-color 0.15s, background 0.15s;
        }
        .pub-rating-btn:hover { border-color: var(--accent); background: var(--accent-light); }
        .pub-rating-btn.selected { border-color: var(--accent); background: var(--accent); color: #fff; }

        .pub-text-input {
            width: 100%;
            padding: 0.65rem 0.85rem;
            border-radius: 8px;
            border: 2px solid var(--border);
            font-family: var(--font-body);
            font-size: 0.95rem;
            background: var(--bg);
            color: var(--text);
            resize: vertical;
            min-height: 80px;
            transition: border-color 0.15s;
        }
        .pub-text-input:focus { outline: none; border-color: var(--accent); }

        .pub-submit-btn {
            margin-top: 1rem;
            padding: 0.65rem 1.5rem;
            border-radius: 8px;
            border: none;
            background: var(--accent);
            color: #fff;
            font-family: var(--font-body);
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }
        .pub-submit-btn:hover { background: #B8451A; }
        .pub-submit-btn:disabled { background: var(--text-muted); cursor: not-allowed; }

        /* ── Status messages ── */
        .pub-submitted-msg {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            color: var(--green);
            background: var(--green-light);
            border: 1px solid rgba(58,125,68,0.2);
            border-radius: 8px;
            padding: 0.6rem 1rem;
            margin-top: 0.75rem;
        }
        .pub-awaiting-msg {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-style: italic;
            margin-top: 0.5rem;
        }
        .pub-closed-msg {
            text-align: center;
            color: var(--text-muted);
            padding: 3rem 1rem;
        }
        .pub-closed-icon { font-size: 2.5rem; margin-bottom: 0.75rem; }
        .pub-waiting-card {
            text-align: center;
            color: var(--text-muted);
            padding: 2rem 1rem;
            font-size: 0.9rem;
        }

        /* ── Results ── */
        .pub-results-wrap { margin-top: 1rem; }
        .pub-results-title {
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 0.75rem;
        }
        .pub-bar-row {
            display: grid;
            grid-template-columns: minmax(80px, 1fr) 2fr auto;
            align-items: center;
            gap: 0.5rem 0.75rem;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
        }
        .pub-bar-label { color: var(--text); font-weight: 500; }
        .pub-bar-track {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 99px;
            height: 18px;
            overflow: hidden;
        }
        .pub-bar-fill {
            height: 100%;
            background: var(--accent);
            border-radius: 99px;
            transition: width 0.5s ease;
        }
        .pub-bar-count { color: var(--text-muted); font-size: 0.8rem; white-space: nowrap; }
        .pub-mean {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 0.5rem;
            font-style: italic;
        }
        .pub-wordcloud-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
            justify-content: center;
            padding: 1rem 0;
        }
        .pub-wordcloud-word {
            display: inline-block;
            color: var(--text);
            font-weight: 600;
            line-height: 1.3;
            opacity: 0.85;
        }
        .pub-text-list {
            max-height: 220px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }
        .pub-text-item {
            font-size: 0.88rem;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 0.4rem 0.7rem;
            color: var(--text-secondary);
        }
        .pub-response-count {
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
        }

        /* ── Error ── */
        .pub-error {
            max-width: 500px;
            margin: 6rem auto;
            text-align: center;
            padding: 1.5rem;
        }
        .pub-error-icon { font-size: 3rem; margin-bottom: 1rem; }
        .pub-error h1 {
            font-family: var(--font-head);
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        .pub-error p { color: var(--text-muted); }

        /* ── Footer ── */
        .pub-footer {
            max-width: 680px;
            margin: 0 auto;
            padding: 1.5rem;
            border-top: 1px solid var(--border);
            font-size: 0.8rem;
            color: var(--text-muted);
            text-align: center;
        }
        .pub-footer a { color: var(--text-muted); text-decoration: underline; text-underline-offset: 2px; }
        .pub-footer a:hover { color: var(--text); }

        @media (max-width: 600px) {
            .pub-page { padding: 1.5rem 1rem 4rem; }
            .pub-topbar { padding: 0.6rem 1rem; }
            .pub-qr-row { flex-direction: column; }
            .pub-qr-box { align-self: center; }
        }
    </style>
</head>
<body>

<header class="pub-topbar">
    <a href="<?= $_appUrl ?>" class="pub-topbar-brand">
        <span class="mark">◈</span> <?= $_appName ?>
    </a>
    <?php if ($check): ?>
    <span class="pub-topbar-title"><?= $_title ?></span>
    <span class="pub-status-badge <?= $_status === 'closed' ? 'closed' : '' ?>">
        <?= $_status === 'active' ? 'Live' : ucfirst($_status) ?>
    </span>
    <?php endif; ?>
</header>

<main id="main-content">
<?php if ($error): ?>
<div class="pub-error">
    <div class="pub-error-icon">📡</div>
    <h1>Not Found</h1>
    <p><?= htmlspecialchars($error) ?></p>
</div>
<?php else: ?>
<div class="pub-page">
    <div class="pub-check-header">
        <h1 class="pub-check-title"><?= $_title ?></h1>
        <div class="pub-check-course"><?= $_course ?></div>
    </div>

    <!-- QR code + share URL -->
    <div class="pub-qr-row">
        <div class="pub-qr-box" id="qr-container"></div>
        <div class="pub-qr-info">
            <div class="pub-qr-label">Share this session</div>
            <div class="pub-qr-url"><?= $_currentUrl ?></div>
            <button class="btn-copy-url" onclick="copyUrl()">Copy URL</button>
        </div>
    </div>

    <div id="questions-area">
        <!-- Rendered by JS -->
        <div style="text-align:center;padding:2rem;color:var(--text-muted)">Loading…</div>
    </div>
</div>
<?php endif; ?>
</main>

<footer class="pub-footer">
    Powered by <a href="<?= $_appUrl ?>"><?= $_appName ?></a>
</footer>

<script>
(function () {
    const CHECK      = <?= $_checkJson ?>;
    const INIT_QS    = <?= $_questionsJson ?>;
    const API_BASE   = <?= $_apiBaseJson ?>;
    const CURRENT_URL = <?= $_currentUrlJson ?>;

    if (!CHECK) return; // error state handled by PHP

    // ── QR Code ────────────────────────────────────────────────────────────
    function loadQR() {
        const s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
        s.onload = function () {
            const el = document.getElementById('qr-container');
            if (el) new QRCode(el, { text: CURRENT_URL, width: 120, height: 120, colorDark: '#1C1E2A', colorLight: '#fff' });
        };
        document.head.appendChild(s);
    }
    loadQR();

    function copyUrl() {
        navigator.clipboard?.writeText(CURRENT_URL).then(() => {
            const btn = document.querySelector('.btn-copy-url');
            if (btn) { btn.textContent = 'Copied!'; setTimeout(() => { btn.textContent = 'Copy URL'; }, 2000); }
        });
    }
    window.copyUrl = copyUrl;

    // ── localStorage for "already responded" UI ─────────────────────────
    function storageKey(qId) { return 'pc_resp_' + qId; }
    function markResponded(qId, val) { try { localStorage.setItem(storageKey(qId), val); } catch(_) {} }
    function getResponded(qId) { try { return localStorage.getItem(storageKey(qId)); } catch(_) { return null; } }

    // ── Escape helper ──────────────────────────────────────────────────
    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Render helpers ─────────────────────────────────────────────────
    function renderBarChart(data, total) {
        if (!data || !data.length) return '<p style="color:var(--text-muted);font-size:0.85rem">No responses yet.</p>';
        return data.map(item => `
            <div class="pub-bar-row">
                <span class="pub-bar-label">${esc(item.label ?? item.value)}</span>
                <div class="pub-bar-track">
                    <div class="pub-bar-fill" style="width:${item.percent}%"></div>
                </div>
                <span class="pub-bar-count">${item.count}</span>
            </div>`).join('');
    }

    function renderWordCloud(words) {
        if (!words || !words.length) return '<p style="color:var(--text-muted);font-size:0.85rem">No responses yet.</p>';
        const max = words[0].count || 1;
        return '<div class="pub-wordcloud-wrap">' +
            words.map(w => {
                const size = 0.9 + (w.count / max) * 2.1;
                return `<span class="pub-wordcloud-word" style="font-size:${size.toFixed(2)}rem">${esc(w.word)}</span>`;
            }).join('') +
            '</div>';
    }

    function renderTextList(responses) {
        if (!responses || !responses.length) return '<p style="color:var(--text-muted);font-size:0.85rem">No responses yet.</p>';
        return '<div class="pub-text-list">' +
            responses.map(r => `<div class="pub-text-item">${esc(r)}</div>`).join('') +
            '</div>';
    }

    function renderResults(q, results) {
        if (!results) return '';
        const count = q.response_count || 0;
        let body = '';
        if (q.type === 'choice') {
            body = renderBarChart(results, count);
        } else if (q.type === 'rating') {
            body = renderBarChart(results.bars, count);
            if (results.mean !== null) {
                const labels = [];
                if (results.min_label) labels.push(results.min_label);
                if (results.max_label) labels.push(results.max_label);
                body += `<div class="pub-mean">Mean: <strong>${results.mean}</strong>${labels.length ? ' &nbsp;(' + esc(labels.join(' → ')) + ')' : ''}</div>`;
            }
        } else if (q.type === 'wordcloud') {
            body = renderWordCloud(results);
        } else if (q.type === 'text') {
            body = renderTextList(results);
        }
        return `<div class="pub-results-wrap">
                    <div class="pub-results-title">Results <span class="pub-response-count">(${count} response${count !== 1 ? 's' : ''})</span></div>
                    ${body}
                </div>`;
    }

    function typeLabel(t) {
        return { choice: 'Multiple Choice', text: 'Short Text', rating: 'Rating', wordcloud: 'Word Cloud' }[t] || t;
    }

    function renderQuestion(q, idx, responded) {
        const opts = q.options;
        let formHtml = '';

        if (q.results_visible && q.results) {
            formHtml = renderResults(q, q.results);
        } else if (q.is_open && !responded) {
            if (q.type === 'choice' && Array.isArray(opts)) {
                formHtml = `<div class="pub-choice-options">` +
                    opts.map((o, i) => `
                        <button class="pub-choice-btn" onclick="selectChoice(this, ${q.id}, ${i})" data-idx="${i}">
                            <span class="pub-choice-letter">${String.fromCharCode(65+i)}</span>
                            ${esc(o)}
                        </button>`).join('') +
                    `</div>
                    <button class="pub-submit-btn" id="submit-${q.id}" onclick="submitResponse(${q.id}, 'choice')" disabled>Submit</button>`;

            } else if (q.type === 'rating' && opts) {
                const min = opts.min ?? 1, max = opts.max ?? 5;
                const btns = [];
                for (let v = min; v <= max; v++) {
                    btns.push(`<button class="pub-rating-btn" onclick="selectRating(this, ${q.id}, ${v})" data-val="${v}">${v}</button>`);
                }
                formHtml = `<div class="pub-rating-wrap">
                    ${opts.min_label || opts.max_label ? `<div class="pub-rating-labels"><span>${esc(opts.min_label||'')}</span><span>${esc(opts.max_label||'')}</span></div>` : ''}
                    <div class="pub-rating-scale">${btns.join('')}</div>
                </div>
                <button class="pub-submit-btn" id="submit-${q.id}" onclick="submitResponse(${q.id}, 'rating')" disabled>Submit</button>`;

            } else if (q.type === 'text') {
                formHtml = `<textarea class="pub-text-input" id="text-${q.id}" placeholder="Type your response…" maxlength="500" oninput="document.getElementById('submit-${q.id}').disabled = !this.value.trim()"></textarea>
                    <br>
                    <button class="pub-submit-btn" id="submit-${q.id}" onclick="submitResponse(${q.id}, 'text')" disabled>Submit</button>`;

            } else if (q.type === 'wordcloud') {
                formHtml = `<input type="text" class="pub-text-input" style="min-height:0;height:46px" id="text-${q.id}" placeholder="One word or short phrase…" maxlength="50" oninput="document.getElementById('submit-${q.id}').disabled = !this.value.trim()">
                    <br>
                    <button class="pub-submit-btn" id="submit-${q.id}" onclick="submitResponse(${q.id}, 'wordcloud')" disabled>Submit</button>`;
            }
        } else if (q.is_open && responded) {
            formHtml = `<div class="pub-submitted-msg">✓ Response recorded</div>`;
            if (!q.results_visible) {
                formHtml += `<div class="pub-awaiting-msg">Awaiting results from instructor…</div>`;
            }
        } else if (!q.is_open) {
            formHtml = `<div class="pub-waiting-card">Waiting for instructor to open this question…</div>`;
        }

        return `
        <div class="pub-question-card" id="qcard-${q.id}" data-qid="${q.id}">
            <div class="pub-q-header">
                <div class="pub-q-num">${idx + 1}</div>
                <div class="pub-q-text">${esc(q.question)}</div>
                <span class="pub-q-type">${esc(typeLabel(q.type))}</span>
            </div>
            <div id="qbody-${q.id}">${formHtml}</div>
        </div>`;
    }

    // ── Response interaction ───────────────────────────────────────────
    let selectedChoices = {};
    let selectedRatings = {};

    window.selectChoice = function(btn, qId, idx) {
        const card = document.getElementById('qcard-' + qId);
        card.querySelectorAll('.pub-choice-btn').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');
        selectedChoices[qId] = idx;
        const sub = document.getElementById('submit-' + qId);
        if (sub) sub.disabled = false;
    };

    window.selectRating = function(btn, qId, val) {
        const card = document.getElementById('qcard-' + qId);
        card.querySelectorAll('.pub-rating-btn').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');
        selectedRatings[qId] = val;
        const sub = document.getElementById('submit-' + qId);
        if (sub) sub.disabled = false;
    };

    window.submitResponse = async function(qId, type) {
        let response;
        if (type === 'choice')   response = String(selectedChoices[qId] ?? '');
        else if (type === 'rating') response = String(selectedRatings[qId] ?? '');
        else {
            const el = document.getElementById('text-' + qId);
            response = el ? el.value.trim() : '';
        }
        if (!response && response !== '0') { alert('Please select or enter a response.'); return; }

        const btn = document.getElementById('submit-' + qId);
        if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }

        try {
            const res = await fetch(API_BASE + '/' + qId + '/respond', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ response }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Submission failed');
            markResponded(qId, response);
            const body = document.getElementById('qbody-' + qId);
            if (body) {
                body.innerHTML = `<div class="pub-submitted-msg">✓ Response recorded</div>
                    <div class="pub-awaiting-msg">Awaiting results from instructor…</div>`;
            }
        } catch (e) {
            if (btn) { btn.disabled = false; btn.textContent = 'Submit'; }
            alert(e.message);
        }
    };

    // ── Main render ───────────────────────────────────────────────────
    function renderAll(questions) {
        const area = document.getElementById('questions-area');
        if (!area) return;

        if (CHECK.status === 'closed') {
            area.innerHTML = `<div class="pub-closed-msg">
                <div class="pub-closed-icon">🔒</div>
                <p>This pulse check has ended. Thank you for participating!</p>
            </div>`;
            return;
        }

        const openQs = questions.filter(q => q.is_open);
        if (!openQs.length) {
            area.innerHTML = `<div class="pub-waiting-card">
                <div style="font-size:2rem;margin-bottom:0.5rem">📡</div>
                <p>Waiting for the instructor to open the first question…</p>
            </div>`;
            return;
        }

        area.innerHTML = questions.map((q, i) => renderQuestion(q, i, getResponded(q.id))).join('');
    }

    // ── Poll for updates ──────────────────────────────────────────────
    renderAll(INIT_QS);

    if (CHECK.status === 'active') {
        setInterval(async function () {
            try {
                const res = await fetch(API_BASE);
                if (!res.ok) return;
                const data = await res.json();
                if (data.questions) renderAll(data.questions);
                if (data.check && data.check.status !== 'active') {
                    // Session ended — show closed message
                    const area = document.getElementById('questions-area');
                    if (area) area.innerHTML = `<div class="pub-closed-msg">
                        <div class="pub-closed-icon">🔒</div>
                        <p>This pulse check has ended. Thank you for participating!</p>
                    </div>`;
                    return;
                }
            } catch (_) {}
        }, 5000);
    }

})();
</script>
</body>
</html>
