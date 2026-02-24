<?php
// Detect the base URL of this installation for LTI registration info.
// Priority: APP_URL constant (if set to a non-localhost value) → auto-detect from request.
require_once __DIR__ . '/config.php';

$_appBase = (function (): string {
    $configured = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    // Use explicitly-configured URL unless it's the default localhost placeholder
    if ($configured && !preg_match('#^https?://localhost#i', $configured)) {
        return $configured;
    }
    // Auto-detect: scheme, host (includes port when non-standard), and subdirectory path
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
               || (($_SERVER['SERVER_PORT'] ?? 80) == 443);
    $scheme  = $isHttps ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $script  = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $base    = rtrim(dirname($script), '/');
    return $scheme . '://' . $host . $base;
})();

$_ltiLogin  = $_appBase . '/lti.php?action=login';
$_ltiLaunch = $_appBase . '/lti.php?action=launch';
$_ltiTarget = $_appBase . '/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Community — A living learning space for your course</title>
    <meta name="description" content="An open-source course community platform that integrates with Brightspace via LTI 1.3. Discussion, Q&A, collaboration boards, peer recognition, and more.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..700;1,9..144,300..500&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:          #F2F0EA;
            --surface:     #FFFFFF;
            --sidebar:     #1C2035;
            --border:      #E3DFD5;
            --text:        #1A1D2E;
            --muted:       #6B7280;
            --accent:      #C84B10;
            --accent-l:    #F06B35;
            --accent-t:    #FDF1EC;
            --blue:        #3D5FA0;
            --blue-t:      #EDF2FB;
            --green:       #4A7E58;
            --green-t:     #EDF5F0;
            --purple:      #7B5EA7;
            --purple-t:    #F3EEFF;
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        html{scroll-behavior:smooth}
        body{font-family:'Plus Jakarta Sans',system-ui,sans-serif;background:var(--bg);color:var(--text);line-height:1.65;-webkit-font-smoothing:antialiased}
        a{color:var(--blue);text-decoration:none}
        a:hover{text-decoration:underline}
        :focus-visible{outline:2px solid var(--accent);outline-offset:2px}
        .container{max-width:1100px;margin:0 auto;padding:0 2rem}

        /* ── Nav ── */
        nav{background:var(--sidebar);position:sticky;top:0;z-index:100;border-bottom:1px solid rgba(255,255,255,0.06)}
        .nav-inner{display:flex;align-items:center;justify-content:space-between;padding:1rem 2rem;max-width:1100px;margin:0 auto}
        .nav-logo{display:flex;align-items:center;gap:0.6rem;color:#EEF0F8;font-weight:700;font-size:1.05rem}
        .nav-logo-mark{font-size:1.4rem;color:var(--accent-l)}
        .nav-links{display:flex;align-items:center;gap:2rem}
        .nav-links a{color:#A8AECA;font-size:0.875rem;font-weight:600;transition:color 0.15s}
        .nav-links a:hover{color:#EEF0F8;text-decoration:none}
        .nav-cta{background:var(--accent);color:white;padding:0.5rem 1.25rem;border-radius:8px;font-size:0.875rem;font-weight:700;transition:background 0.15s}
        .nav-cta:hover{background:var(--accent-l);text-decoration:none}

        /* ── Hero ── */
        .hero{
            padding:6rem 2rem 5rem;
            background:var(--sidebar);
            position:relative;overflow:hidden;
        }
        .hero::before{
            content:'';position:absolute;inset:0;
            background:radial-gradient(ellipse 80% 60% at 60% 40%, rgba(200,75,16,0.12) 0%, transparent 60%),
                        radial-gradient(ellipse 50% 40% at 20% 80%, rgba(61,95,160,0.1) 0%, transparent 60%);
        }
        .hero-inner{max-width:1100px;margin:0 auto;display:grid;grid-template-columns:1fr 1fr;gap:4rem;align-items:center;position:relative}
        .hero-eyebrow{display:inline-flex;align-items:center;gap:0.5rem;background:rgba(200,75,16,0.15);color:var(--accent-l);font-size:0.78rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;padding:0.35rem 0.875rem;border-radius:99px;margin-bottom:1.5rem}
        .hero-title{font-family:'Fraunces',Georgia,serif;font-size:clamp(2.5rem,5vw,3.75rem);font-weight:600;line-height:1.1;letter-spacing:-0.03em;color:#EEF0F8;margin-bottom:1.25rem}
        .hero-title em{font-style:italic;color:var(--accent-l)}
        .hero-desc{font-size:1.1rem;color:#A8AECA;line-height:1.7;margin-bottom:2rem}
        .hero-actions{display:flex;gap:1rem;flex-wrap:wrap}
        .btn-hero-primary{background:var(--accent);color:white;padding:0.875rem 2rem;border-radius:10px;font-weight:700;font-size:1rem;box-shadow:0 4px 16px rgba(200,75,16,0.35);transition:all 0.15s}
        .btn-hero-primary:hover{background:var(--accent-l);box-shadow:0 6px 24px rgba(200,75,16,0.45);text-decoration:none;transform:translateY(-1px)}
        .btn-hero-secondary{background:rgba(255,255,255,0.08);color:#EEF0F8;padding:0.875rem 2rem;border-radius:10px;font-weight:700;font-size:1rem;border:1px solid rgba(255,255,255,0.12);transition:all 0.15s}
        .btn-hero-secondary:hover{background:rgba(255,255,255,0.12);text-decoration:none}
        .hero-badges{display:flex;gap:0.75rem;margin-top:1.5rem;flex-wrap:wrap}
        .hero-badge{display:flex;align-items:center;gap:0.4rem;font-size:0.8rem;color:#8B90B8}
        .hero-badge::before{content:'✓';color:var(--accent-l);font-weight:700}

        /* Hero visual */
        .hero-visual{
            background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);
            border-radius:16px;overflow:hidden;
            box-shadow:0 24px 64px rgba(0,0,0,0.4);
        }
        .hv-topbar{background:rgba(0,0,0,0.3);padding:0.75rem 1rem;display:flex;align-items:center;gap:0.75rem;border-bottom:1px solid rgba(255,255,255,0.06)}
        .hv-dots{display:flex;gap:0.35rem}
        .hv-dot{width:10px;height:10px;border-radius:50%}
        .hv-title{font-size:0.75rem;color:#8B90B8;margin-left:0.25rem}
        .hv-body{display:flex;height:280px}
        .hv-sidebar{width:120px;background:rgba(0,0,0,0.2);padding:0.875rem 0.75rem;border-right:1px solid rgba(255,255,255,0.06);flex-shrink:0}
        .hv-si{font-size:0.72rem;color:#8B90B8;padding:0.35rem 0.5rem;border-radius:6px;margin-bottom:0.2rem}
        .hv-si.active{background:rgba(200,75,16,0.2);color:var(--accent-l)}
        .hv-main{flex:1;padding:0.875rem;overflow:hidden;display:flex;flex-direction:column;gap:0.6rem}
        .hv-card{background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.08);border-radius:8px;padding:0.625rem 0.75rem}
        .hv-card-title{font-size:0.72rem;font-weight:600;color:#C8CCDF;margin-bottom:0.25rem}
        .hv-card-meta{display:flex;gap:0.5rem;font-size:0.65rem;color:#5E6278}
        .hv-badge{padding:0.15rem 0.4rem;border-radius:99px;font-size:0.62rem;font-weight:700}
        .hv-badge.q{background:rgba(74,126,88,0.2);color:#7DB88C}
        .hv-badge.r{background:rgba(61,95,160,0.2);color:#7A9CD4}
        .hv-badge.k{background:rgba(184,115,46,0.2);color:#D4A06A}

        /* ── Section headers ── */
        .section{padding:5rem 2rem}
        .section-alt{background:var(--surface)}
        .section-header{text-align:center;margin-bottom:3.5rem}
        .section-label{display:inline-block;background:var(--accent-t);color:var(--accent);font-size:0.75rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;padding:0.3rem 0.75rem;border-radius:99px;margin-bottom:1rem}
        .section-title{font-family:'Fraunces',Georgia,serif;font-size:clamp(1.875rem,3vw,2.75rem);font-weight:600;line-height:1.2;letter-spacing:-0.02em;color:var(--text)}
        .section-subtitle{font-size:1.05rem;color:var(--muted);margin-top:0.75rem;max-width:600px;margin-left:auto;margin-right:auto}

        /* ── Features grid ── */
        .features-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1.5rem}
        .feature-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:1.75rem;transition:box-shadow 0.2s,transform 0.2s}
        .feature-card:hover{box-shadow:0 8px 32px rgba(0,0,0,0.08);transform:translateY(-2px)}
        .feature-icon{font-size:2rem;margin-bottom:1rem}
        .feature-title{font-family:'Fraunces',Georgia,serif;font-size:1.125rem;font-weight:600;margin-bottom:0.5rem}
        .feature-desc{font-size:0.9rem;color:var(--muted);line-height:1.65}
        .feature-tag{display:inline-block;font-size:0.7rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;padding:0.2rem 0.5rem;border-radius:99px;margin-top:0.875rem}

        /* ── Design principles ── */
        .principles-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:1.5rem}
        .principle{text-align:center;padding:1.5rem 1rem}
        .principle-number{font-family:'Fraunces',Georgia,serif;font-size:3rem;font-weight:700;line-height:1;color:var(--border);margin-bottom:0.75rem}
        .principle-name{font-weight:700;font-size:0.95rem;margin-bottom:0.4rem}
        .principle-desc{font-size:0.82rem;color:var(--muted)}

        /* ── LTI setup ── */
        .setup-grid{display:grid;grid-template-columns:1fr 1fr;gap:3rem;align-items:start;max-width:960px;margin:0 auto}
        .setup-steps{counter-reset:steps}
        .setup-step{counter-increment:steps;display:flex;gap:1rem;margin-bottom:2rem}
        .setup-step-num{width:36px;height:36px;border-radius:50%;background:var(--accent);color:white;font-weight:700;font-size:0.875rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px}
        .setup-step-content{}
        .setup-step-title{font-weight:700;margin-bottom:0.35rem}
        .setup-step-desc{font-size:0.875rem;color:var(--muted);line-height:1.6}
        .code-block{background:var(--sidebar);color:#C8CDD8;border-radius:10px;padding:1.25rem 1.5rem;font-family:'JetBrains Mono','Fira Code',monospace;font-size:0.8rem;line-height:1.75;overflow-x:auto;margin-top:0.5rem}
        .code-comment{color:#5E6278}
        .code-key{color:#A8AECA}
        .code-val{color:var(--accent-l)}
        .url-table{width:100%;border-collapse:collapse;font-size:0.875rem;margin-top:0.5rem}
        .url-table th{text-align:left;padding:0.5rem 0.75rem;background:var(--bg);font-size:0.75rem;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;color:var(--muted)}
        .url-table td{padding:0.5rem 0.75rem;border-top:1px solid var(--border)}
        .url-table td:first-child{font-weight:600;white-space:nowrap}
        .url-table td.url-cell{font-family:monospace;font-size:0.82rem;color:var(--blue);word-break:break-all}
        .url-row{display:flex;align-items:center;gap:0.5rem}
        .copy-btn{flex-shrink:0;padding:0.2rem 0.5rem;border-radius:5px;font-size:0.72rem;font-weight:700;font-family:'Plus Jakarta Sans',sans-serif;border:1px solid var(--border);background:var(--bg);color:var(--muted);cursor:pointer;transition:all 0.15s;white-space:nowrap}
        .copy-btn:hover{border-color:var(--blue);color:var(--blue);background:var(--blue-t)}
        .copy-btn.copied{border-color:var(--green);color:var(--green);background:var(--green-t)}
        .info-box{background:var(--blue-t);border:1px solid #C8D8EF;border-radius:12px;padding:1.25rem;margin-top:1.5rem;font-size:0.875rem;color:#2A4070}
        .info-box strong{color:var(--blue)}

        /* ── Tech stack ── */
        .stack-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;max-width:800px;margin:0 auto}
        .stack-item{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1.25rem;display:flex;align-items:center;gap:0.875rem}
        .stack-icon{font-size:1.75rem;flex-shrink:0}
        .stack-name{font-weight:700;font-size:0.9rem;margin-bottom:0.15rem}
        .stack-note{font-size:0.78rem;color:var(--muted)}

        /* ── CTA ── */
        .cta{background:var(--sidebar);padding:5rem 2rem;text-align:center;position:relative;overflow:hidden}
        .cta::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 60% 80% at 50% 50%,rgba(200,75,16,0.1) 0%,transparent 70%)}
        .cta-title{font-family:'Fraunces',Georgia,serif;font-size:clamp(2rem,4vw,3rem);font-weight:600;color:#EEF0F8;letter-spacing:-0.02em;margin-bottom:1rem;position:relative}
        .cta-subtitle{font-size:1.05rem;color:#8B90B8;margin-bottom:2rem;position:relative}
        .cta-actions{display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;position:relative}

        /* ── Footer ── */
        footer{background:var(--sidebar);border-top:1px solid rgba(255,255,255,0.06);padding:2rem;text-align:center}
        .footer-inner{max-width:1100px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem}
        .footer-logo{display:flex;align-items:center;gap:0.5rem;color:#A8AECA;font-size:0.9rem;font-weight:600}
        .footer-links{display:flex;gap:1.5rem}
        .footer-links a{color:#5E6278;font-size:0.85rem;transition:color 0.15s}
        .footer-links a:hover{color:#A8AECA}
        .footer-copy{color:#5E6278;font-size:0.8rem}

        @media(max-width:900px){
            .hero-inner{grid-template-columns:1fr}
            .hero-visual{display:none}
            .setup-grid{grid-template-columns:1fr}
            .principles-grid{grid-template-columns:repeat(2,1fr)}
        }
        @media(max-width:600px){
            .principles-grid{grid-template-columns:1fr}
            .nav-links a:not(.nav-cta){display:none}
        }
    </style>
</head>
<body>

<!-- ── Navigation ─────────────────────────────────────────────────────── -->
<nav>
    <div class="nav-inner">
        <div class="nav-logo">
            <span class="nav-logo-mark">◈</span>
            Course Community
        </div>
        <div class="nav-links">
            <a href="#features">Features</a>
            <a href="#peer-feedback">Peer Feedback</a>
            <a href="#design">Principles</a>
            <a href="#integration">LTI Setup</a>
            <a href="#install">Install</a>
            <a class="nav-cta" href="#install">Get Started</a>
        </div>
    </div>
</nav>

<!-- ── Hero ──────────────────────────────────────────────────────────── -->
<section class="hero">
    <div class="hero-inner">
        <div>
            <div class="hero-eyebrow">Open Source · LTI 1.3 · PHP + SQLite</div>
            <h1 class="hero-title">A <em>living</em> learning space for your course</h1>
            <p class="hero-desc">
                Course Community goes beyond the discussion board. It's a full community environment
                where students and instructors can communicate, collaborate, co-create, give and
                receive feedback — and make learning visible together.
            </p>
            <div class="hero-actions">
                <a class="btn-hero-primary" href="#install">Deploy it</a>
                <a class="btn-hero-secondary" href="#integration">LTI Integration Guide</a>
            </div>
            <div class="hero-badges">
                <span class="hero-badge">No vendor lock-in</span>
                <span class="hero-badge">Runs on shared hosting</span>
                <span class="hero-badge">Integrates with Brightspace</span>
                <span class="hero-badge">Student data stays yours</span>
            </div>
        </div>

        <!-- Fake app preview -->
        <div class="hero-visual">
            <div class="hv-topbar">
                <div class="hv-dots">
                    <div class="hv-dot" style="background:#FF5F57"></div>
                    <div class="hv-dot" style="background:#FEBC2E"></div>
                    <div class="hv-dot" style="background:#28C840"></div>
                </div>
                <div class="hv-title">◈ Course Community — EDUC 420</div>
            </div>
            <div class="hv-body">
                <div class="hv-sidebar">
                    <div class="hv-si active">📣 Feed</div>
                    <div class="hv-si">💬 Discussion</div>
                    <div class="hv-si">❓ Q&amp;A</div>
                    <div class="hv-si">📚 Resources</div>
                    <div class="hv-si">🎉 Kudos</div>
                    <div class="hv-si">🧩 Collab</div>
                    <div class="hv-si">🔁 Peer Review</div>
                    <div class="hv-si">👥 Members</div>
                    <div class="hv-si" style="margin-top:0.5rem">📊 Pulse</div>
                </div>
                <div class="hv-main">
                    <div class="hv-card">
                        <div style="display:flex;gap:0.4rem;margin-bottom:0.35rem">
                            <span class="hv-badge q">❓ Question</span>
                            <span class="hv-badge r">❓ Q&amp;A</span>
                        </div>
                        <div class="hv-card-title">What's the difference between formative and summative assessment?</div>
                        <div class="hv-card-meta"><span>Sam Student</span><span>·</span><span>2h ago</span><span>·</span><span>💬 1 reply</span></div>
                    </div>
                    <div class="hv-card">
                        <div style="display:flex;gap:0.4rem;margin-bottom:0.35rem">
                            <span class="hv-badge k">🎉 Kudos</span>
                        </div>
                        <div class="hv-card-title">Shoutout to Sam for a brilliant question!</div>
                        <div class="hv-card-meta"><span>Instructor</span><span>·</span><span>1h ago</span><span>·</span><span>👏 5</span></div>
                    </div>
                    <div class="hv-card">
                        <div style="display:flex;gap:0.4rem;margin-bottom:0.35rem">
                            <span class="hv-badge r">📊 Poll</span>
                        </div>
                        <div class="hv-card-title">Check-in: How are you feeling about Week 3?</div>
                        <div class="hv-card-meta"><span>Instructor</span><span>·</span><span>3h ago</span><span>·</span><span>12 votes</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── Features ───────────────────────────────────────────────────────── -->
<section class="section section-alt" id="features">
    <div class="container">
        <div class="section-header">
            <div class="section-label">Features</div>
            <h2 class="section-title">Everything a learning community needs</h2>
            <p class="section-subtitle">Designed around how people actually learn together — not just how they submit assignments.</p>
        </div>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">💬</div>
                <div class="feature-title">Threaded Discussions</div>
                <p class="feature-desc">Rich threaded conversations with nested replies, markdown formatting, reactions, and instructor notes. Not a flat forum — a real dialogue.</p>
                <span class="feature-tag" style="background:#EDF2FB;color:var(--blue)">Discussion</span>
            </div>
            <div class="feature-card">
                <div class="feature-icon">❓</div>
                <div class="feature-title">Q&amp;A with Accepted Answers</div>
                <p class="feature-desc">Students ask, the community answers. Upvoting surfaces the best responses. Instructors and post-authors can mark accepted answers. Unresolved questions surface automatically.</p>
                <span class="feature-tag" style="background:var(--green-t);color:var(--green)">Q&amp;A</span>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🧩</div>
                <div class="feature-title">Collaboration Boards</div>
                <p class="feature-desc">Visual sticky-note boards with drag-and-drop positioning. Perfect for brainstorming, mind-mapping, idea generation, and collecting thinking from the whole class.</p>
                <span class="feature-tag" style="background:#EEF5F2;color:var(--green)">Collaboration</span>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📚</div>
                <div class="feature-title">Resource Library</div>
                <p class="feature-desc">A community-curated collection of articles, videos, and tools. Anyone can contribute — making resource curation a participatory act rather than a one-way broadcast.</p>
                <span class="feature-tag" style="background:var(--purple-t);color:var(--purple)">Resources</span>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🎉</div>
                <div class="feature-title">Kudos &amp; Peer Recognition</div>
                <p class="feature-desc">A dedicated space for giving specific, public appreciation to classmates. Research consistently shows that recognition cultures improve motivation and belonging.</p>
                <span class="feature-tag" style="background:#FEF3E2;color:#B8732E">Recognition</span>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📊</div>
                <div class="feature-title">Polls &amp; Check-ins</div>
                <p class="feature-desc">Quick anonymous polls to check understanding, gather sentiment, or make decisions together. Results update in real-time as classmates vote.</p>
                <span class="feature-tag" style="background:#F0F8FF;color:#2872A8">Engagement</span>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🪞</div>
                <div class="feature-title">Reflections</div>
                <p class="feature-desc">A post type specifically for reflective writing — thinking out loud about the learning process, not just the content. Surfaces metacognition as a valued community contribution.</p>
                <span class="feature-tag" style="background:#FFF0F0;color:#C04040">Reflection</span>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📊</div>
                <div class="feature-title">Community Pulse Dashboard</div>
                <p class="feature-desc">Instructors see who's contributing, which spaces are thriving, unanswered questions, and — crucially — which students are quietly disengaged before it becomes a problem.</p>
                <span class="feature-tag" style="background:var(--accent-t);color:var(--accent)">Analytics</span>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🔔</div>
                <div class="feature-title">Notifications &amp; Activity Feed</div>
                <p class="feature-desc">Real-time notification of replies, reactions, and mentions. A unified activity feed shows the full community narrative at a glance. Scoped per-course so notifications never bleed between sections.</p>
                <span class="feature-tag" style="background:#F5F5F5;color:#555">Awareness</span>
            </div>
            <div class="feature-card" style="border-color:rgba(200,75,16,0.25);background:linear-gradient(135deg,#fff 80%,var(--accent-t))">
                <div class="feature-icon">🔁</div>
                <div class="feature-title">Structured Peer Feedback</div>
                <p class="feature-desc">A full anonymous peer review workflow: instructors define prompts and configure how many reviewers each submission receives. Students submit text or files; the system load-balances reviewer assignments automatically.</p>
                <span class="feature-tag" style="background:var(--accent-t);color:var(--accent)">Peer Learning</span>
            </div>
        </div>
    </div>
</section>

<!-- ── Peer Feedback Deep Dive ──────────────────────────────────────── -->
<section class="section" id="peer-feedback">
    <div class="container">
        <div class="section-header">
            <div class="section-label">Peer Feedback</div>
            <h2 class="section-title">A research-backed peer review workflow</h2>
            <p class="section-subtitle">Giving feedback is one of the most powerful ways to deepen understanding. Course Community makes it structured, anonymous, and fair.</p>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:4rem;align-items:start;max-width:960px;margin:0 auto">
            <div>
                <h3 style="font-family:'Fraunces',Georgia,serif;font-size:1.2rem;margin-bottom:1.5rem">How it works</h3>
                <div class="setup-steps">
                    <div class="setup-step">
                        <div class="setup-step-num" style="background:var(--sidebar)">1</div>
                        <div class="setup-step-content">
                            <div class="setup-step-title">Instructor creates the assignment</div>
                            <div class="setup-step-desc">Set a title, instructions, custom feedback prompts (e.g. "What are the strengths?", "What could be clearer?"), number of reviewers per submission, and whether text, file uploads, or both are accepted.</div>
                        </div>
                    </div>
                    <div class="setup-step">
                        <div class="setup-step-num" style="background:var(--sidebar)">2</div>
                        <div class="setup-step-content">
                            <div class="setup-step-title">Students submit their work</div>
                            <div class="setup-step-desc">Once opened, students submit their writing, documents (PDF, Word, etc.), or both. Submissions can be updated or withdrawn during the open period.</div>
                        </div>
                    </div>
                    <div class="setup-step">
                        <div class="setup-step-num" style="background:var(--sidebar)">3</div>
                        <div class="setup-step-content">
                            <div class="setup-step-title">Reviewers are automatically assigned</div>
                            <div class="setup-step-desc">A load-balancing algorithm assigns reviewers evenly — no student reviews their own work. The review phase begins, and each student sees only their assigned submissions, with author identities hidden.</div>
                        </div>
                    </div>
                    <div class="setup-step">
                        <div class="setup-step-num" style="background:var(--sidebar)">4</div>
                        <div class="setup-step-content">
                            <div class="setup-step-title">Feedback is released when closed</div>
                            <div class="setup-step-desc">When the instructor closes the assignment, each student sees the anonymous feedback received on their submission. Reviewer identities are never revealed to submitters.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <h3 style="font-family:'Fraunces',Georgia,serif;font-size:1.2rem;margin-bottom:1.5rem">Key design decisions</h3>

                <div style="display:flex;flex-direction:column;gap:1rem">
                    <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1.25rem">
                        <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.5rem">
                            <span style="font-size:1.25rem">🎭</span>
                            <strong>Bidirectional anonymity</strong>
                        </div>
                        <p style="font-size:0.875rem;color:var(--muted);line-height:1.6">Reviewers can't see who submitted the work. Submitters can't see who reviewed it. Instructors can see everything. This mirrors validated academic peer review processes.</p>
                    </div>
                    <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1.25rem">
                        <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.5rem">
                            <span style="font-size:1.25rem">⚖️</span>
                            <strong>Load-balanced assignment</strong>
                        </div>
                        <p style="font-size:0.875rem;color:var(--muted);line-height:1.6">The assignment algorithm distributes reviews evenly across the class, so no student gets significantly more or fewer reviews than others — even in small cohorts.</p>
                    </div>
                    <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1.25rem">
                        <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.5rem">
                            <span style="font-size:1.25rem">🔒</span>
                            <strong>Secure file handling</strong>
                        </div>
                        <p style="font-size:0.875rem;color:var(--muted);line-height:1.6">Uploaded documents are stored outside the public web root and served only through a permission-checked PHP endpoint. Only the author, assigned reviewers, and instructor can download a file.</p>
                    </div>
                    <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1.25rem">
                        <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.5rem">
                            <span style="font-size:1.25rem">📋</span>
                            <strong>Structured prompts</strong>
                        </div>
                        <p style="font-size:0.875rem;color:var(--muted);line-height:1.6">Instructors define the feedback prompts — so reviews are guided rather than free-form. Students respond to each prompt individually, keeping feedback specific and actionable.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Phase timeline -->
        <div style="max-width:760px;margin:3rem auto 0;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:2rem">
            <div style="font-size:0.75rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--muted);margin-bottom:1.25rem">Assignment lifecycle</div>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0;position:relative">
                <div style="position:absolute;top:20px;left:10%;right:10%;height:2px;background:var(--border);z-index:0"></div>
                <?php foreach ([
                    ['Draft',     '#888',    'Instructor is setting up the assignment. Not visible to students yet.'],
                    ['Open',      '#2ECC71', 'Students submit their work. Submissions can be edited or withdrawn.'],
                    ['Reviewing', '#F39C12', 'Reviewers are assigned. Students complete their peer reviews.'],
                    ['Closed',    '#4A6FA5', 'Reviews released. Students see the feedback they received.'],
                ] as [$phase, $color, $desc]): ?>
                <div style="text-align:center;position:relative;z-index:1;padding:0 0.5rem">
                    <div style="width:40px;height:40px;border-radius:50%;background:<?= $color ?>22;border:2px solid <?= $color ?>;margin:0 auto 0.75rem;display:flex;align-items:center;justify-content:center">
                        <div style="width:12px;height:12px;border-radius:50%;background:<?= $color ?>"></div>
                    </div>
                    <div style="font-weight:700;font-size:0.875rem;color:var(--text);margin-bottom:0.35rem"><?= $phase ?></div>
                    <div style="font-size:0.75rem;color:var(--muted);line-height:1.5"><?= $desc ?></div>
                </div>
                <?php endforeach ?>
            </div>
        </div>
    </div>
</section>

<!-- ── Design Principles ───────────────────────────────────────────── -->
<section class="section" id="design">
    <div class="container">
        <div class="section-header">
            <div class="section-label">Design Principles</div>
            <h2 class="section-title">Built on learning science</h2>
            <p class="section-subtitle">The feature set is grounded in D'Arcy Norman's five-dimensional course design framework — <em>The Teaching Game</em>.</p>
        </div>
        <div class="principles-grid">
            <div class="principle">
                <div class="principle-number">01</div>
                <div class="principle-name">Player</div>
                <p class="principle-desc">Multiple contribution modes give every student a meaningful way in — posting, replying, curating, recognizing, reflecting, submitting work, or giving structured peer feedback.</p>
            </div>
            <div class="principle">
                <div class="principle-number">02</div>
                <div class="principle-name">Performance</div>
                <p class="principle-desc">Votes, reactions, accepted answers, kudos, and structured peer feedback make contribution quality visible — both to individuals and to the instructor via the Community Pulse.</p>
            </div>
            <div class="principle">
                <div class="principle-number">03</div>
                <div class="principle-name">Narrative</div>
                <p class="principle-desc">The community feed creates a chronological story of shared learning. Pinned and featured posts curate the most important moments in that story.</p>
            </div>
            <div class="principle">
                <div class="principle-number">04</div>
                <div class="principle-name">Environment</div>
                <p class="principle-desc">Purposeful spaces — Discussion, Q&amp;A, Resources, Kudos, Collaboration, Peer Feedback — create distinct places with clear social norms and expectations.</p>
            </div>
            <div class="principle">
                <div class="principle-number">05</div>
                <div class="principle-name">System</div>
                <p class="principle-desc">Transparent roles, moderation tools, course-isolated sandboxing, and LTI-based authentication ensure the community operates within a coherent, trustworthy structure.</p>
            </div>
        </div>
    </div>
</section>

<!-- ── LTI Integration ─────────────────────────────────────────────── -->
<section class="section section-alt" id="integration">
    <div class="container">
        <div class="section-header">
            <div class="section-label">LTI 1.3 Integration</div>
            <h2 class="section-title">Works natively with Brightspace</h2>
            <p class="section-subtitle">Course Community uses the LTI 1.3 standard to launch from Brightspace, automatically authenticating users and loading course context.</p>
        </div>

        <div class="setup-grid">
            <div>
                <h3 style="font-family:'Fraunces',Georgia,serif;font-size:1.25rem;margin-bottom:1.5rem;color:var(--text)">How it works</h3>
                <div class="setup-steps">
                    <div class="setup-step">
                        <div class="setup-step-num">1</div>
                        <div class="setup-step-content">
                            <div class="setup-step-title">Register the tool in Brightspace</div>
                            <div class="setup-step-desc">Go to Admin → External Learning Tools → New Deployment. Choose LTI 1.3 and fill in the URLs below.</div>
                        </div>
                    </div>
                    <div class="setup-step">
                        <div class="setup-step-num">2</div>
                        <div class="setup-step-content">
                            <div class="setup-step-title">Copy credentials into config.php</div>
                            <div class="setup-step-desc">Brightspace will give you a client ID, issuer URL, JWKS URI, and authentication endpoint. Paste these into <code style="background:var(--bg);padding:0.1em 0.4em;border-radius:4px;font-family:monospace">config.php</code>.</div>
                        </div>
                    </div>
                    <div class="setup-step">
                        <div class="setup-step-num">3</div>
                        <div class="setup-step-content">
                            <div class="setup-step-title">Add a link in your course</div>
                            <div class="setup-step-desc">Add an External Learning Tool link anywhere in your Brightspace course — content area, navbar, or module. Students click it and are automatically authenticated.</div>
                        </div>
                    </div>
                    <div class="setup-step">
                        <div class="setup-step-num">4</div>
                        <div class="setup-step-content">
                            <div class="setup-step-title">Launch</div>
                            <div class="setup-step-desc">The tool reads the user's name, role (instructor/student), and course context from the LTI JWT. No separate login required. Each course gets its own isolated community.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <h3 style="font-family:'Fraunces',Georgia,serif;font-size:1.25rem;margin-bottom:1.5rem;color:var(--text)">URLs to register</h3>
                <table class="url-table">
                    <thead>
                        <tr>
                            <th>Setting</th>
                            <th>Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Login Initiation URL</td>
                            <td class="url-cell"><div class="url-row"><span><?= htmlspecialchars($_ltiLogin) ?></span><button class="copy-btn" onclick="copyUrl(this,'<?= htmlspecialchars($_ltiLogin, ENT_QUOTES) ?>')">Copy</button></div></td>
                        </tr>
                        <tr>
                            <td>Redirect URI (Launch URL)</td>
                            <td class="url-cell"><div class="url-row"><span><?= htmlspecialchars($_ltiLaunch) ?></span><button class="copy-btn" onclick="copyUrl(this,'<?= htmlspecialchars($_ltiLaunch, ENT_QUOTES) ?>')">Copy</button></div></td>
                        </tr>
                        <tr>
                            <td>Target Link URI</td>
                            <td class="url-cell"><div class="url-row"><span><?= htmlspecialchars($_ltiTarget) ?></span><button class="copy-btn" onclick="copyUrl(this,'<?= htmlspecialchars($_ltiTarget, ENT_QUOTES) ?>')">Copy</button></div></td>
                        </tr>
                    </tbody>
                </table>

                <div class="info-box">
                    <strong>No HTTPS? Use Dev Mode.</strong><br>
                    Set <code>DEV_MODE=true</code> as an environment variable (or in config.php) to bypass LTI authentication entirely. A simulated instructor + student are created automatically — perfect for local development and demos.
                </div>

                <div style="margin-top:1.5rem">
                    <h4 style="font-size:0.9rem;font-weight:700;margin-bottom:0.5rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.05em">config.php example</h4>
                    <div class="code-block">
<span class="code-comment">// Brightspace LTI 1.3 credentials</span>
<span class="code-key">$LTI_PLATFORMS</span> = [
    <span class="code-val">'https://your.brightspace.com'</span> => [
        <span class="code-key">'client_id'</span>     => <span class="code-val">'12345678'</span>,
        <span class="code-key">'auth_endpoint'</span> => <span class="code-val">'https://your.brightspace.com/d2l/lti/authenticate'</span>,
        <span class="code-key">'jwks_uri'</span>      => <span class="code-val">'https://your.brightspace.com/d2l/.well-known/jwks'</span>,
    ],
];
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── Installation ────────────────────────────────────────────────── -->
<section class="section" id="install">
    <div class="container">
        <div class="section-header">
            <div class="section-label">Installation</div>
            <h2 class="section-title">Up and running in minutes</h2>
            <p class="section-subtitle">No build tools, no npm, no containers required. Just PHP and a place to host it.</p>
        </div>

        <div style="max-width:700px;margin:0 auto">
            <div class="code-block">
<span class="code-comment"># 1. Copy files to your web server</span>
scp -r course-community/ user@yourserver.com:/var/www/html/community/

<span class="code-comment"># 2. Make the data directory writable</span>
chmod 755 /var/www/html/community/data/

<span class="code-comment"># 3. Set environment variables (or edit config.php directly)</span>
export APP_URL="https://yourserver.com/community"
export LTI_ISSUER="https://your.brightspace.com"
export LTI_CLIENT_ID="your-client-id-from-brightspace"
export LTI_AUTH_ENDPOINT="https://your.brightspace.com/d2l/lti/authenticate"
export LTI_JWKS_URI="https://your.brightspace.com/d2l/.well-known/jwks"

<span class="code-comment"># 4. (Optional) Test locally with dev mode</span>
export DEV_MODE=true
php -S localhost:8080

<span class="code-comment"># Then visit: http://localhost:8080/lti.php?action=dev</span>
            </div>

            <h3 style="font-family:'Fraunces',Georgia,serif;font-size:1.25rem;margin:2.5rem 0 1rem">Requirements</h3>
            <div class="stack-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr))">
                <div class="stack-item">
                    <div class="stack-icon">🐘</div>
                    <div>
                        <div class="stack-name">PHP 8.1+</div>
                        <div class="stack-note">PDO, SQLite3, OpenSSL</div>
                    </div>
                </div>
                <div class="stack-item">
                    <div class="stack-icon">🗃️</div>
                    <div>
                        <div class="stack-name">SQLite 3</div>
                        <div class="stack-note">Auto-created on first run</div>
                    </div>
                </div>
                <div class="stack-item">
                    <div class="stack-icon">🔒</div>
                    <div>
                        <div class="stack-name">HTTPS</div>
                        <div class="stack-note">Required for LTI 1.3</div>
                    </div>
                </div>
                <div class="stack-item">
                    <div class="stack-icon">🌐</div>
                    <div>
                        <div class="stack-name">Apache/Nginx</div>
                        <div class="stack-note">Or any PHP host</div>
                    </div>
                </div>
            </div>

            <h3 style="font-family:'Fraunces',Georgia,serif;font-size:1.25rem;margin:2.5rem 0 1rem">Apache .htaccess (recommended)</h3>
            <div class="code-block">
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_URI}      !^/assets/
RewriteCond %{REQUEST_URI}      !^/api/
RewriteCond %{REQUEST_URI}      !^/lti.php
RewriteRule ^(.*)$              /index.php [L,QSA]
            </div>
        </div>
    </div>
</section>

<!-- ── CTA ────────────────────────────────────────────────────────── -->
<section class="cta">
    <div class="container">
        <h2 class="cta-title">Ready to build a better learning community?</h2>
        <p class="cta-subtitle">Open source, self-hosted, and yours to adapt. Start with dev mode to explore it today.</p>
        <div class="cta-actions">
            <a class="btn-hero-primary" href="#install">Installation Guide</a>
            <a class="btn-hero-secondary" href="#features">See All Features</a>
        </div>
    </div>
</section>

<!-- ── Footer ─────────────────────────────────────────────────────── -->
<footer>
    <div class="footer-inner">
        <div class="footer-logo">
            <span style="color:var(--accent-l)">◈</span> Course Community
        </div>
        <div class="footer-links">
            <a href="#features">Features</a>
            <a href="#integration">LTI Guide</a>
            <a href="#install">Install</a>
        </div>
        <div class="footer-copy">Open source · PHP + SQLite · LTI 1.3</div>
    </div>
</footer>


<script>
function copyUrl(btn, text) {
    navigator.clipboard.writeText(text).then(() => {
        btn.textContent = 'Copied!';
        btn.classList.add('copied');
        setTimeout(() => { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 2000);
    });
}
</script>
</body>
</html>
