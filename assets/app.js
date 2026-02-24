/**
 * Course Community — Frontend SPA
 * Vanilla ES2022 · No build step required
 *
 * Design principles (Norman, "The Teaching Game"):
 *   Player     → multiple contribution modes, visible agency
 *   Performance→ community pulse, recognition, visible progress
 *   Narrative  → feed tells the story of the community over time
 *   Environment→ purposeful spaces, warm & inviting
 *   System     → transparent norms, enabling not just constraining
 */

'use strict';

// ═══════════════════════════════════════════════════════════════════
// STATE
// ═══════════════════════════════════════════════════════════════════

const state = {
    user:           null,
    course:         null,
    role:           'student',
    spaces:         [],
    currentSpaceId: null,
    view:           'feed',   // feed | space | post | board | boards | members | profile | analytics | feedback | feedbackDetail | feedbackReview
    viewData:       {},       // extra data for current view
    notifications:  [],
    unreadCount:    0,
    notifOpen:      false,
    members:        [],
};

// ═══════════════════════════════════════════════════════════════════
// API
// ═══════════════════════════════════════════════════════════════════

const api = {
    async request(method, path, data) {
        const opts = {
            method,
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        };
        if (data) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(data);
        }
        const base = window.APP_CONFIG?.baseUrl ?? '';
        const url = base + (path.startsWith('/') ? path : `/api/${path}`);
        const res = await fetch(url, opts);
        if (res.status === 401) { window.location.reload(); return null; }
        const json = await res.json();
        if (!res.ok) throw new Error(json.error || `HTTP ${res.status}`);
        return json;
    },
    get:    (p)    => api.request('GET', p),
    post:   (p, d) => api.request('POST', p, d),
    put:    (p, d) => api.request('PUT', p, d),
    del:    (p)    => api.request('DELETE', p),
    patch:  (p, d) => api.request('PATCH', p, d),
};

// ═══════════════════════════════════════════════════════════════════
// ROUTER (hash-based)
// ═══════════════════════════════════════════════════════════════════

const router = {
    base: (() => {
        const b = window.APP_CONFIG?.baseUrl ?? '';
        try { return new URL(b).pathname.replace(/\/$/, ''); } catch { return b.replace(/\/$/, ''); }
    })(),
    current: '/',

    navigate(path) {
        history.pushState({}, '', this.base + path);
        this.handle(path);
    },

    handle(rawPath = location.pathname) {
        // Strip base prefix so routing always works with plain paths like '/feedback'
        const path = this.base && rawPath.startsWith(this.base)
            ? rawPath.slice(this.base.length) || '/'
            : rawPath;
        this.current = path;
        const [, seg1, seg2] = path.split('/');

        if (!state.user) return; // wait for init

        if (seg1 === 'post' && seg2)        return views.post(+seg2);
        if (seg1 === 'board' && seg2)       return views.board(+seg2);
        if (seg1 === 'boards')              return views.boards();
        if (seg1 === 'members')             return views.members();
        if (seg1 === 'profile' && seg2)     return views.profile(+seg2);
        if (seg1 === 'analytics')           return views.analytics();
        if (seg1 === 'space' && seg2)       return views.space(+seg2);
        if (seg1 === 'feedback') {
            const segs = path.split('/');
            if (segs[3] === 'review' && segs[4]) return views.feedbackReview(+seg2, +segs[4]);
            if (seg2) return views.feedbackDetail(+seg2);
            return views.feedback();
        }
        return views.feed();
    },
};

window.addEventListener('popstate', () => router.handle());

// ═══════════════════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════════════════

async function init() {
    try {
        const session = await api.get('/api/session');
        state.user   = session.user;
        state.course = session.course;
        state.role   = session.role;

        const courseData = await api.get('/api/course');
        state.spaces = courseData.spaces || [];

        await loadNotifications();

        renderApp();
        router.handle();

        // Poll for notifications every 30s
        setInterval(loadNotifications, 30000);
    } catch (e) {
        document.getElementById('app').innerHTML = errorScreen(e.message);
    }
}

async function loadNotifications() {
    try {
        const data = await api.get('/api/notifications');
        state.notifications = data.notifications || [];
        state.unreadCount   = data.unread || 0;
        updateNotifBadge();
    } catch (_) {}
}

// ═══════════════════════════════════════════════════════════════════
// RENDER APP SHELL
// ═══════════════════════════════════════════════════════════════════

function renderApp() {
    document.getElementById('app').innerHTML = `
        <div class="app-layout">
            ${renderTopbar()}
            ${renderSidebar()}
            <main class="main" id="main-content">
                <div class="main-inner" id="view-content">
                    ${loadingInline()}
                </div>
            </main>
            ${renderRightPanel()}
        </div>
        <div id="modal-host"></div>
        <div id="notif-host"></div>
    `;

    bindTopbar();
    bindSidebar();
}

// ── Topbar ──────────────────────────────────────────────────────────

function renderTopbar() {
    const c = state.course;
    return `
    <header class="topbar">
        <div class="topbar-logo">
            <span class="logo-mark">◈</span>
            <span>Course Community</span>
        </div>
        <div class="topbar-course">
            ${c.label ? `<span class="topbar-course-label">${esc(c.label)}</span>` : ''}
            <span class="topbar-course-name">${esc(c.title)}</span>
        </div>
        <div class="topbar-actions">
            <button class="topbar-btn" id="notif-btn" aria-label="Notifications">
                🔔
                ${state.unreadCount > 0 ? `<span class="notif-badge" id="notif-badge">${state.unreadCount}</span>` : '<span class="notif-badge" id="notif-badge" style="display:none"></span>'}
            </button>
            <button class="avatar-btn" id="my-profile-btn" aria-label="My profile">
                ${avatarEl(state.user, 34)}
            </button>
        </div>
    </header>`;
}

function bindTopbar() {
    document.getElementById('notif-btn').addEventListener('click', toggleNotifPanel);
    document.getElementById('my-profile-btn').addEventListener('click', () => views.profile(state.user.id));
}

function updateNotifBadge() {
    const badge = document.getElementById('notif-badge');
    if (!badge) return;
    if (state.unreadCount > 0) {
        badge.textContent = state.unreadCount;
        badge.style.display = '';
    } else {
        badge.style.display = 'none';
    }
}

// ── Sidebar ─────────────────────────────────────────────────────────

function renderSidebar() {
    const spaceItems = state.spaces.map(s => `
        <div class="sidebar-item ${state.currentSpaceId === s.id ? 'active' : ''}"
             data-nav="/space/${s.id}" data-space-id="${s.id}">
            <span class="sidebar-icon">${s.icon}</span>
            <span>${esc(s.name)}</span>
        </div>
    `).join('');

    return `
    <nav class="sidebar">
        <div class="sidebar-section">
            <div class="sidebar-item ${state.view === 'feed' ? 'active' : ''}" data-nav="/">
                <span class="sidebar-icon">📣</span>
                <span>Community Feed</span>
            </div>
        </div>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section">
            <div class="sidebar-section-label">Spaces</div>
            ${spaceItems}
        </div>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section">
            <div class="sidebar-item ${state.view === 'boards' ? 'active' : ''}" data-nav="/boards">
                <span class="sidebar-icon">🧩</span>
                <span>Collaboration Boards</span>
            </div>
            <div class="sidebar-item ${'feedback|feedbackDetail|feedbackReview'.includes(state.view) ? 'active' : ''}" data-nav="/feedback">
                <span class="sidebar-icon">🔁</span>
                <span>Peer Feedback</span>
            </div>
            <div class="sidebar-item ${state.view === 'members' ? 'active' : ''}" data-nav="/members">
                <span class="sidebar-icon">👥</span>
                <span>Members</span>
            </div>
        </div>

        ${state.role === 'instructor' ? `
        <div class="sidebar-divider"></div>
        <div class="sidebar-section">
            <div class="sidebar-section-label">Instructor</div>
            <div class="sidebar-item ${state.view === 'analytics' ? 'active' : ''}" data-nav="/analytics">
                <span class="sidebar-icon">📊</span>
                <span>Community Pulse</span>
            </div>
        </div>` : ''}
    </nav>`;
}

function bindSidebar() {
    document.querySelector('.sidebar')?.addEventListener('click', e => {
        const item = e.target.closest('[data-nav]');
        if (item) {
            const path = item.dataset.nav;
            router.navigate(path);
            // Update active state
            refreshSidebar();
        }
    });
}

function refreshSidebar() {
    const sidebar = document.querySelector('.sidebar');
    if (!sidebar) return;
    sidebar.querySelectorAll('.sidebar-item').forEach(item => {
        item.classList.remove('active');
        const nav = item.dataset.nav;
        if (nav === '/' && state.view === 'feed') item.classList.add('active');
        if (nav === '/boards' && state.view === 'boards') item.classList.add('active');
        if (nav === '/members' && state.view === 'members') item.classList.add('active');
        if (nav === '/analytics' && state.view === 'analytics') item.classList.add('active');
        if (item.dataset.spaceId && +item.dataset.spaceId === state.currentSpaceId) item.classList.add('active');
    });
}

// ── Right panel ──────────────────────────────────────────────────────

function renderRightPanel() {
    const instructors = state.members.filter(m => m.role === 'instructor').slice(0, 3);
    const recent = state.members.filter(m => m.last_seen).slice(0, 6);
    return `
    <aside class="panel">
        ${instructors.length ? `
        <div class="panel-card">
            <div class="panel-card-header">Instructors</div>
            <div class="panel-card-body">
                ${instructors.map(m => `
                <div class="panel-member" style="cursor:pointer" data-nav="/profile/${m.id}">
                    <div class="panel-member-avatar">${avatarEl(m, 28)}</div>
                    <div>
                        <div class="panel-member-name">${esc(m.given_name || m.name)}</div>
                        <div class="panel-member-role">Instructor</div>
                    </div>
                </div>`).join('')}
            </div>
        </div>` : ''}

        <div class="panel-card">
            <div class="panel-card-header">Recently Active</div>
            <div class="panel-card-body">
                ${recent.length ? recent.map(m => `
                <div class="panel-member" style="cursor:pointer" data-nav="/profile/${m.id}">
                    <div class="panel-member-avatar">${avatarEl(m, 28)}</div>
                    <div>
                        <div class="panel-member-name">${esc(m.given_name || m.name)}</div>
                        <div class="panel-member-role">${timeAgo(m.last_seen)}</div>
                    </div>
                    <div class="online-dot" style="background:${m.last_seen > Date.now()/1000 - 300 ? 'var(--success)' : 'var(--border-dark)'}"></div>
                </div>`).join('') : '<div style="font-size:0.8rem;color:var(--text-muted);text-align:center;padding:0.5rem">No activity yet</div>'}
            </div>
        </div>

        <div class="panel-card">
            <div class="panel-card-header">Quick Post</div>
            <div class="panel-card-body" style="display:flex;flex-direction:column;gap:0.4rem">
                ${[
                    ['💬','Discussion','discussion'],
                    ['❓','Question','question'],
                    ['📚','Resource','resource'],
                    ['🎉','Kudos','kudos'],
                ].map(([icon, label, type]) => `
                <button class="btn btn-ghost btn-sm" style="justify-content:flex-start;gap:0.5rem"
                        onclick="openCompose('${type}')">
                    <span>${icon}</span> New ${label}
                </button>`).join('')}
            </div>
        </div>
    </aside>`;
}

// ═══════════════════════════════════════════════════════════════════
// VIEWS
// ═══════════════════════════════════════════════════════════════════

const views = {

    // ── Community Feed ───────────────────────────────────────────

    async feed() {
        state.view = 'feed';
        state.currentSpaceId = null;
        refreshSidebar();
        setView(loadingInline());

        // Load members for panel and feed
        if (!state.members.length) {
            try { state.members = await api.get('/api/members'); updatePanel(); } catch (_) {}
        }

        try {
            const data = await api.get('/api/posts?page=1');
            setView(renderFeedView(data.posts, data));
            bindFeedEvents();
        } catch (e) {
            setView(errorState(e.message));
        }
    },

    // ── Space ────────────────────────────────────────────────────

    async space(spaceId) {
        state.view = 'space';
        state.currentSpaceId = spaceId;
        refreshSidebar();
        setView(loadingInline());

        const space = state.spaces.find(s => s.id === spaceId);
        if (!space) { setView(errorState('Space not found')); return; }

        if (!state.members.length) {
            try { state.members = await api.get('/api/members'); updatePanel(); } catch (_) {}
        }

        try {
            const data = await api.get(`/api/posts?space_id=${spaceId}&page=1`);
            setView(renderSpaceView(space, data.posts, data));
            bindFeedEvents();
        } catch (e) {
            setView(errorState(e.message));
        }
    },

    // ── Post detail ──────────────────────────────────────────────

    async post(postId) {
        state.view = 'post';
        setView(loadingInline());
        try {
            const post = await api.get(`/api/posts/${postId}`);
            const space = state.spaces.find(s => s.id === post.space_id);
            setView(renderPostDetail(post, space));
            bindPostDetailEvents(post);
        } catch (e) {
            setView(errorState(e.message));
        }
    },

    // ── Boards list ──────────────────────────────────────────────

    async boards() {
        state.view = 'boards';
        state.currentSpaceId = null;
        refreshSidebar();
        setView(loadingInline());
        try {
            const boards = await api.get('/api/boards');
            setView(renderBoardsList(boards));
            bindBoardsListEvents();
        } catch (e) {
            setView(errorState(e.message));
        }
    },

    // ── Board canvas ─────────────────────────────────────────────

    async board(boardId) {
        state.view = 'board';
        setView(loadingInline());
        try {
            const board = await api.get(`/api/boards/${boardId}`);
            setView(renderBoardCanvas(board));
            bindBoardCanvasEvents(board);
        } catch (e) {
            setView(errorState(e.message));
        }
    },

    // ── Members ──────────────────────────────────────────────────

    async members() {
        state.view = 'members';
        state.currentSpaceId = null;
        refreshSidebar();
        setView(loadingInline());
        try {
            const members = await api.get('/api/members');
            state.members = members;
            updatePanel();
            setView(renderMembersView(members));
        } catch (e) {
            setView(errorState(e.message));
        }
    },

    // ── User profile ─────────────────────────────────────────────

    async profile(userId) {
        try {
            const user = await api.get(`/api/users/${userId}`);
            openModal(renderProfileModal(user, userId === state.user.id));
        } catch (e) {
            toast(e.message);
        }
    },

    // ── Analytics ────────────────────────────────────────────────

    async analytics() {
        if (state.role !== 'instructor') { router.navigate('/'); return; }
        state.view = 'analytics';
        state.currentSpaceId = null;
        refreshSidebar();
        setView(loadingInline());
        try {
            const data = await api.get('/api/analytics');
            setView(renderAnalytics(data));
        } catch (e) {
            setView(errorState(e.message));
        }
    },

    // ── Peer Feedback List ───────────────────────────────────────

    async feedback() {
        state.view = 'feedback';
        state.currentSpaceId = null;
        refreshSidebar();
        setView(loadingInline());
        try {
            const assignments = await api.get('/api/feedback');
            setView(renderFeedbackList(assignments));
            bindFeedbackListEvents();
        } catch (e) {
            setView(errorState(e.message));
        }
    },

    // ── Peer Feedback Detail ─────────────────────────────────────

    async feedbackDetail(id) {
        state.view = 'feedbackDetail';
        state.currentSpaceId = null;
        refreshSidebar();
        setView(loadingInline());
        try {
            const a = await api.get(`/api/feedback/${id}`);
            let extra = {};

            if (state.role === 'instructor') {
                try { extra.progress = await api.get(`/api/feedback/${id}/progress`); } catch(_) {}
            } else {
                if (a.status === 'reviewing' || a.status === 'closed') {
                    try { extra.myReviews = await api.get(`/api/feedback/${id}/reviews`); } catch(_) {}
                }
                if (a.status === 'closed' && a.my_submission) {
                    try { extra.received = await api.get(`/api/feedback/${id}/received`); } catch(_) {}
                }
                if (a.status === 'open') {
                    try { extra.mySubmission = await api.get(`/api/feedback/${id}/submit`); } catch(_) {}
                }
            }

            setView(renderFeedbackDetail(a, extra));
            bindFeedbackDetailEvents(a);
        } catch (e) {
            setView(errorState(e.message));
        }
    },

    // ── Peer Feedback Review ─────────────────────────────────────

    async feedbackReview(assignmentId, raId) {
        state.view = 'feedbackReview';
        state.currentSpaceId = null;
        refreshSidebar();
        setView(loadingInline());
        try {
            const data = await api.get(`/api/reviews/${raId}`);
            setView(renderReviewView(assignmentId, data));
            bindReviewEvents(assignmentId, raId, data);
        } catch (e) {
            setView(errorState(e.message));
        }
    },
};

// ═══════════════════════════════════════════════════════════════════
// RENDER: FEED
// ═══════════════════════════════════════════════════════════════════

function renderFeedView(posts, meta) {
    return `
    <div class="page-header">
        <div class="page-header-left">
            <h1 class="page-title">Community Feed</h1>
            <p class="page-subtitle">${esc(state.course.title)} · ${meta.total} post${meta.total !== 1 ? 's' : ''}</p>
        </div>
        <button class="btn btn-primary" onclick="openCompose()">+ New Post</button>
    </div>

    <div class="tabs" id="sort-tabs">
        <button class="tab active" data-sort="recent">Recent</button>
        <button class="tab" data-sort="top">Top Voted</button>
    </div>

    ${renderComposeBubble()}
    ${renderPostCards(posts, true)}
    ${meta.pages > 1 ? renderPagination(meta.page, meta.pages) : ''}
    `;
}

function renderSpaceView(space, posts, meta) {
    const typeInfo = spaceTypeInfo(space.type);
    return `
    <div class="page-header">
        <div class="page-header-left">
            <div class="space-badge" style="background:${space.color}18;color:${space.color}">
                ${space.icon} ${esc(space.name)}
            </div>
            <h1 class="page-title" style="margin-top:0.5rem">${esc(space.name)}</h1>
            <p class="page-subtitle">${esc(space.description)}</p>
        </div>
        <button class="btn btn-primary" onclick="openCompose(null, ${space.id})">+ New Post</button>
    </div>

    ${space.type === 'qa' ? `
    <div class="tabs" id="sort-tabs">
        <button class="tab active" data-sort="recent">Recent</button>
        <button class="tab" data-sort="top">Most Helpful</button>
        <button class="tab" data-sort="unanswered">Unanswered</button>
    </div>` : `
    <div class="tabs" id="sort-tabs">
        <button class="tab active" data-sort="recent">Recent</button>
        <button class="tab" data-sort="top">Top</button>
    </div>`}

    ${renderComposeBubble()}
    ${renderPostCards(posts, false)}
    ${meta.pages > 1 ? renderPagination(meta.page, meta.pages) : ''}
    `;
}

function renderComposeBubble() {
    return `
    <div class="compose-bar" onclick="openCompose()">
        <div class="compose-avatar">${avatarEl(state.user, 36)}</div>
        <div class="compose-placeholder">Share something with the community…</div>
        <div class="compose-types">
            <span class="compose-type-btn" onclick="event.stopPropagation();openCompose('question')">❓</span>
            <span class="compose-type-btn" onclick="event.stopPropagation();openCompose('resource')">📚</span>
            <span class="compose-type-btn" onclick="event.stopPropagation();openCompose('poll')">📊</span>
        </div>
    </div>`;
}

function renderPostCards(posts, showSpaceBadge = true) {
    if (!posts.length) {
        return `<div class="empty-state">
            <div class="empty-state-icon">💬</div>
            <div class="empty-state-title">Nothing here yet</div>
            <p class="empty-state-text">Be the first to post in this space.</p>
        </div>`;
    }
    return `<div class="post-list" id="post-list">
        ${posts.map((p, i) => renderPostCard(p, showSpaceBadge, i)).join('')}
    </div>`;
}

function renderPostCard(post, showSpaceBadge, delay = 0) {
    const typeLabel = {
        discussion: '💬 Discussion', question: '❓ Question', resource: '📚 Resource',
        kudos: '🎉 Kudos', reflection: '🪞 Reflection', poll: '📊 Poll',
        announcement: '📣 Announcement',
    }[post.type] || post.type;

    const meta = post.meta || {};
    let extra = '';

    if (post.type === 'resource' && meta.url) {
        extra = `<a class="resource-link" href="${esc(meta.url)}" target="_blank" rel="noopener" onclick="event.stopPropagation()">
            <span>🔗</span>
            <div>
                <div class="resource-domain">${esc(meta.domain || new URL(meta.url).hostname)}</div>
                <div class="resource-title-link">Open resource ↗</div>
            </div>
        </a>`;
    }
    if (post.type === 'poll' && post.poll_results) {
        extra = renderPollPreview(post.poll_results, post.id);
    }
    if (post.type === 'kudos') {
        extra = '';
    }

    const reactions = (post.reactions || []).map(r =>
        `<span class="reaction-pill ${r.mine ? 'mine' : ''}" onclick="event.stopPropagation();reactPost(${post.id},'${r.emoji}',this)">
            ${r.emoji} ${r.count}
        </span>`
    ).join('');

    return `
    <article class="post-card type-${post.type} ${post.is_pinned ? 'pinned' : ''} ${post.is_featured ? 'featured' : ''} ${post.is_resolved ? 'resolved' : ''}"
             data-post-id="${post.id}"
             style="animation-delay:${delay * 50}ms"
             onclick="router.navigate('/post/${post.id}')">
        <div class="post-card-header">
            <span class="post-type-badge type-${post.type}">${typeLabel}</span>
            ${showSpaceBadge && post.space_name ? `
            <span class="post-space-badge">
                ${post.space_icon || ''} ${esc(post.space_name)}
            </span>` : ''}
            <div class="post-pins">
                ${post.is_pinned ? '<span class="pin-icon" title="Pinned">📌</span>' : ''}
                ${post.is_featured ? '<span class="pin-icon" title="Featured">⭐</span>' : ''}
            </div>
        </div>

        <h2 class="post-title">${esc(post.title)}</h2>

        ${post.content ? `<p class="post-excerpt">${esc(post.content)}</p>` : ''}
        ${extra}

        <div class="post-meta">
            <div class="post-author">
                <div class="post-author-avatar">${avatarEl({name: post.author_name, picture: post.author_pic}, 22)}</div>
                <span class="post-author-name">${esc(post.author_name || '')}</span>
            </div>
            <span class="post-time">${timeAgo(post.created_at)}</span>
            <div class="post-stats">
                ${post.vote_count ? `<span class="post-stat">▲ ${post.vote_count}</span>` : ''}
                ${post.comment_count > 0 ? `<span class="post-stat">💬 ${post.comment_count}</span>` : ''}
                <div class="post-reactions-preview">${reactions}</div>
            </div>
        </div>
    </article>`;
}

function renderPollPreview(results, postId) {
    const hasVoted = results.my_vote !== null;
    return `<div class="poll-preview">
        ${results.options.map((opt, i) => `
        <div class="poll-option ${hasVoted && results.my_vote === i ? 'voted' : ''}"
             onclick="event.stopPropagation();${hasVoted ? '' : `castPollVote(${postId},${i},this.closest('.poll-preview'))`}">
            <div class="poll-bar" style="width:${opt.percent}%"></div>
            <div class="poll-option-inner">
                <span>${esc(opt.label)}</span>
                ${hasVoted ? `<span class="poll-pct">${opt.percent}%</span>` : ''}
            </div>
        </div>`).join('')}
        <div class="poll-total">${results.total} vote${results.total !== 1 ? 's' : ''}</div>
    </div>`;
}

function renderPagination(page, pages) {
    return `<div style="display:flex;justify-content:center;gap:0.5rem;margin-top:1.5rem">
        ${page > 1 ? `<button class="btn btn-secondary btn-sm" data-page="${page-1}">← Prev</button>` : ''}
        <span style="padding:0.5rem 0.75rem;font-size:0.85rem;color:var(--text-muted)">Page ${page} of ${pages}</span>
        ${page < pages ? `<button class="btn btn-secondary btn-sm" data-page="${page+1}">Next →</button>` : ''}
    </div>`;
}

// ═══════════════════════════════════════════════════════════════════
// RENDER: POST DETAIL
// ═══════════════════════════════════════════════════════════════════

function renderPostDetail(post, space) {
    const isOwner      = post.author_id === state.user.id;
    const isInstructor = state.role === 'instructor';
    const meta         = post.meta || {};

    const EMOJIS = ['👍','❤️','🔥','💡','🤔','😮','🎉','👏','⭐','🙏'];

    const reactions = (post.reactions || []).map(r =>
        `<span class="reaction-pill ${r.mine ? 'mine' : ''}"
              onclick="reactPost(${post.id},'${r.emoji}',this)">${r.emoji} ${r.count}</span>`
    ).join('');

    return `
    <div class="post-detail">
        <div class="breadcrumb">
            <a href="#" onclick="event.preventDefault();history.back()">← Back</a>
            ${space ? `<span class="breadcrumb-sep">›</span>
            <a href="#" onclick="event.preventDefault();router.navigate('/space/${space.id}')">${space.icon} ${esc(space.name)}</a>` : ''}
        </div>

        <div class="post-detail-header">
            <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.5rem">
                <span class="post-type-badge type-${post.type}">${postTypeLabel(post.type)}</span>
                ${post.is_resolved ? '<span class="comment-answer-badge">✓ Resolved</span>' : ''}
                ${post.is_pinned   ? '<span class="tag" style="background:var(--warn-tint);color:var(--warn)">📌 Pinned</span>' : ''}
                ${post.is_featured ? '<span class="tag" style="background:var(--accent-2-tint);color:var(--accent-2)">⭐ Featured</span>' : ''}
            </div>

            <h1 class="post-detail-title">${esc(post.title)}</h1>

            <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1rem">
                <div style="display:flex;align-items:center;gap:0.5rem">
                    <div style="width:36px;height:36px;border-radius:50%;overflow:hidden">${avatarEl(post.author, 36)}</div>
                    <div>
                        <div style="font-weight:700;font-size:0.875rem;cursor:pointer"
                             onclick="views.profile(${post.author_id})">${esc(post.author?.name || '')}</div>
                        <div style="font-size:0.775rem;color:var(--text-muted)">${timeAgo(post.created_at)}</div>
                    </div>
                </div>
                <div style="margin-left:auto;display:flex;gap:0.4rem">
                    ${isOwner || isInstructor ? `
                    <button class="btn btn-ghost btn-sm" onclick="editPost(${post.id})">Edit</button>
                    <button class="btn btn-ghost btn-sm btn-danger" onclick="deletePost(${post.id})">Delete</button>` : ''}
                </div>
            </div>
        </div>

        <div class="post-detail-content" id="post-content">${renderMarkdown(post.content)}</div>

        ${post.type === 'resource' && meta.url ? `
        <a class="resource-link" href="${esc(meta.url)}" target="_blank" rel="noopener">
            <span>🔗</span>
            <div>
                <div class="resource-domain">${esc(meta.domain || '')}</div>
                <div class="resource-title-link">Open resource ↗</div>
            </div>
        </a>` : ''}

        ${post.type === 'poll' && post.poll_results ? renderPollDetail(post.poll_results, post.id) : ''}

        <div class="post-actions">
            <div class="vote-group">
                <button class="vote-btn up ${post.user_vote === 1 ? 'active' : ''}"
                        onclick="votePost(${post.id}, 1, this)" title="Upvote">▲</button>
                <span class="vote-count" id="post-vote-count">${post.vote_count}</span>
                <button class="vote-btn down ${post.user_vote === -1 ? 'active' : ''}"
                        onclick="votePost(${post.id}, -1, this)" title="Downvote">▼</button>
            </div>

            <div class="reaction-bar" id="reaction-bar">
                ${reactions}
                <div style="position:relative">
                    <button class="add-reaction-btn" id="add-reaction-btn" onclick="toggleEmojiPicker(${post.id})">+ React</button>
                    <div class="emoji-picker" id="emoji-picker-${post.id}" style="display:none;top:2rem;left:0">
                        ${EMOJIS.map(e => `<span class="emoji-option" onclick="reactPost(${post.id},'${e}',null);document.getElementById('emoji-picker-${post.id}').style.display='none'">${e}</span>`).join('')}
                    </div>
                </div>
            </div>

            <div class="instructor-actions">
                ${post.type === 'question' ? `
                <button class="btn btn-ghost btn-sm ${post.is_resolved ? 'btn-danger' : ''}"
                        onclick="toggleResolve(${post.id}, this)">${post.is_resolved ? '↩ Unresolve' : '✓ Mark Resolved'}</button>` : ''}
                ${isInstructor ? `
                <button class="btn btn-ghost btn-sm" onclick="togglePin(${post.id}, this)" title="Pin post">
                    ${post.is_pinned ? '📌 Unpin' : '📌 Pin'}
                </button>
                <button class="btn btn-ghost btn-sm" onclick="toggleFeature(${post.id}, this)" title="Feature post">
                    ${post.is_featured ? '⭐ Unfeature' : '⭐ Feature'}
                </button>` : ''}
            </div>
        </div>

        <section class="comments-section" id="comments-section">
            <h2 class="comments-header">${post.comments?.length || 0} Response${(post.comments?.length || 0) !== 1 ? 's' : ''}</h2>
            <div id="comments-list">
                ${(post.comments || []).map(c => renderComment(c, post, post.type === 'question')).join('')}
            </div>

            <div class="comment-composer" id="composer">
                <div style="display:flex;gap:0.75rem;align-items:flex-start;margin-bottom:0.75rem">
                    <div style="width:34px;height:34px;border-radius:50%;overflow:hidden;flex-shrink:0">${avatarEl(state.user, 34)}</div>
                    <textarea class="composer-textarea" id="comment-input"
                              placeholder="Add a response… Be specific, be generous. ✨"
                              rows="3"></textarea>
                </div>
                ${isInstructor ? `
                <div style="margin-bottom:0.5rem">
                    <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.825rem;color:var(--text-secondary);cursor:pointer">
                        <input type="checkbox" id="instructor-note-cb"> Post as instructor note
                    </label>
                </div>` : ''}
                <div class="composer-footer">
                    <button class="btn btn-ghost btn-sm" onclick="document.getElementById('comment-input').value=''">Clear</button>
                    <button class="btn btn-primary btn-sm" onclick="submitComment(${post.id})">Post Response</button>
                </div>
            </div>
        </section>
    </div>`;
}

function renderComment(comment, post, isQA = false) {
    const isOwner      = comment.author_id === state.user.id || comment.author_uid === state.user.id;
    const isInstructor = state.role === 'instructor';
    const canMarkAnswer = (post.author_id === state.user.id || isInstructor) && isQA;

    return `
    <div class="comment ${comment.is_answer ? 'is-answer' : ''}" id="comment-${comment.id}">
        <div class="comment-avatar">${avatarEl({name: comment.author_name, picture: comment.author_pic}, 34)}</div>
        <div class="comment-body">
            <div class="comment-header">
                <span class="comment-author" style="cursor:pointer" onclick="views.profile(${comment.author_uid || comment.author_id})">${esc(comment.author_name || '')}</span>
                <span class="comment-time">${timeAgo(comment.created_at)}</span>
                ${comment.is_answer ? '<span class="comment-answer-badge">✓ Accepted Answer</span>' : ''}
                ${comment.is_instructor_note ? '<span class="instructor-note-badge">Instructor Note</span>' : ''}
            </div>
            <div class="comment-content">${renderMarkdown(comment.content)}</div>
            <div class="comment-actions">
                <button class="comment-action-btn ${comment.vote === 1 ? 'upvoted' : ''}"
                        onclick="voteComment(${comment.id}, this)">
                    ▲ <span class="comment-vote-val">${comment.vote_count || 0}</span> helpful
                </button>
                <button class="comment-action-btn" onclick="showReplyBox(${comment.id}, ${post.id})">Reply</button>
                ${canMarkAnswer ? `
                <button class="comment-action-btn" style="color:var(--success)"
                        onclick="markAnswer(${comment.id}, this)">${comment.is_answer ? '✕ Unmark answer' : '✓ Mark as answer'}</button>` : ''}
                ${isOwner || isInstructor ? `
                <button class="comment-action-btn btn-danger" onclick="deleteComment(${comment.id})">Delete</button>` : ''}
            </div>
            ${(comment.replies || []).length ? `
            <div class="replies" id="replies-${comment.id}">
                ${comment.replies.map(r => renderComment(r, post, false)).join('')}
            </div>` : `<div class="replies" id="replies-${comment.id}" style="display:none"></div>`}
        </div>
    </div>`;
}

function renderPollDetail(results, postId) {
    const hasVoted = results.my_vote !== null;
    return `<div class="poll-preview" style="margin-bottom:1.25rem">
        <div style="font-size:0.8rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.75rem">
            ${hasVoted ? 'Results' : 'Cast your vote'}
        </div>
        ${results.options.map((opt, i) => `
        <div class="poll-option ${hasVoted && results.my_vote === i ? 'voted selected' : ''}"
             onclick="${!hasVoted ? `castPollVote(${postId},${i},this.closest('.poll-preview'))` : ''}">
            <div class="poll-bar" style="width:${opt.percent}%"></div>
            <div class="poll-option-inner">
                <span>${esc(opt.label)}</span>
                ${hasVoted ? `<span class="poll-pct">${opt.percent}%</span>` : ''}
            </div>
        </div>`).join('')}
        <div class="poll-total">${results.total} vote${results.total !== 1 ? 's' : ''}</div>
    </div>`;
}

// ═══════════════════════════════════════════════════════════════════
// RENDER: BOARDS
// ═══════════════════════════════════════════════════════════════════

function renderBoardsList(boards) {
    return `
    <div class="page-header">
        <div class="page-header-left">
            <h1 class="page-title">Collaboration Boards</h1>
            <p class="page-subtitle">Visual thinking spaces for co-constructing knowledge</p>
        </div>
        <button class="btn btn-primary" id="new-board-btn">+ New Board</button>
    </div>

    ${!boards.length ? `<div class="empty-state">
        <div class="empty-state-icon">🧩</div>
        <div class="empty-state-title">No boards yet</div>
        <p class="empty-state-text">Create a board to start collaborative thinking.</p>
    </div>` : `<div class="board-list">
        ${boards.map(b => `
        <div class="board-list-card" onclick="router.navigate('/board/${b.id}')">
            <div class="board-list-title">${esc(b.title)}</div>
            ${b.description ? `<div class="board-list-desc">${esc(b.description)}</div>` : ''}
            <div class="board-list-meta">
                <span>🗂 ${b.card_count} card${b.card_count !== 1 ? 's' : ''}</span>
                <span>by ${esc(b.creator_name)}</span>
                <span style="margin-left:auto">${timeAgo(b.created_at)}</span>
            </div>
        </div>`).join('')}
    </div>`}`;
}

function bindBoardsListEvents() {
    document.getElementById('new-board-btn')?.addEventListener('click', () => openNewBoardModal());
}

function renderBoardCanvas(board) {
    return `
    <div class="board-view">
        <div class="board-header">
            <div>
                <div class="breadcrumb">
                    <a href="#" onclick="event.preventDefault();router.navigate('/boards')">← Boards</a>
                </div>
                <h1 class="page-title" style="font-size:1.5rem">${esc(board.title)}</h1>
                ${board.prompt ? `<p class="page-subtitle" style="font-style:italic;margin-top:0.25rem">"${esc(board.prompt)}"</p>` : ''}
            </div>
            <div style="display:flex;gap:0.5rem">
                <button class="btn btn-primary" id="add-card-btn">+ Add Card</button>
                ${state.role === 'instructor' ? `<button class="btn btn-secondary" id="delete-board-btn">Delete Board</button>` : ''}
            </div>
        </div>
        <div class="board-canvas-wrap" id="board-canvas-wrap">
            <div class="board-canvas" id="board-canvas" data-board-id="${board.id}">
                ${(board.cards || []).map(card => renderStickyCard(card)).join('')}
            </div>
        </div>
    </div>`;
}

function renderStickyCard(card) {
    return `
    <div class="sticky-card" data-card-id="${card.id}"
         style="left:${card.pos_x}px;top:${card.pos_y}px;background:${esc(card.color)}">
        <div class="sticky-content">${esc(card.content)}</div>
        <div class="sticky-footer">
            <span class="sticky-author">${esc(card.author_given || card.author_name || '')}</span>
            <button class="sticky-vote-btn ${card.user_vote ? 'voted' : ''}"
                    onclick="event.stopPropagation();voteCard(${card.id}, this)">
                ▲ <span class="card-vote-val">${card.vote_count || 0}</span>
            </button>
        </div>
    </div>`;
}

function bindBoardCanvasEvents(board) {
    const canvas = document.getElementById('board-canvas');
    if (!canvas) return;

    // Drag cards
    let dragging = null, ox = 0, oy = 0;

    canvas.addEventListener('mousedown', e => {
        const card = e.target.closest('.sticky-card');
        if (!card || e.target.closest('.sticky-vote-btn')) return;
        e.preventDefault();
        dragging = card;
        card.classList.add('dragging');
        const rect = card.getBoundingClientRect();
        ox = e.clientX - rect.left;
        oy = e.clientY - rect.top;
    });

    document.addEventListener('mousemove', e => {
        if (!dragging) return;
        const wrapRect = document.getElementById('board-canvas-wrap').getBoundingClientRect();
        const newX = Math.max(0, e.clientX - wrapRect.left - ox + document.getElementById('board-canvas-wrap').scrollLeft);
        const newY = Math.max(0, e.clientY - wrapRect.top - oy + document.getElementById('board-canvas-wrap').scrollTop);
        dragging.style.left = newX + 'px';
        dragging.style.top  = newY + 'px';
    });

    document.addEventListener('mouseup', async () => {
        if (!dragging) return;
        dragging.classList.remove('dragging');
        const cardId = +dragging.dataset.cardId;
        const x = parseFloat(dragging.style.left);
        const y = parseFloat(dragging.style.top);
        try { await api.put(`/api/cards/${cardId}`, { pos_x: x, pos_y: y }); } catch (_) {}
        dragging = null;
    });

    // Add card button
    document.getElementById('add-card-btn')?.addEventListener('click', () => openAddCardModal(board.id));

    // Delete board button
    document.getElementById('delete-board-btn')?.addEventListener('click', async () => {
        if (!confirm('Delete this board and all its cards?')) return;
        try {
            await api.del(`/api/boards/${board.id}`);
            router.navigate('/boards');
        } catch (e) { toast(e.message); }
    });
}

// ═══════════════════════════════════════════════════════════════════
// RENDER: MEMBERS
// ═══════════════════════════════════════════════════════════════════

function renderMembersView(members) {
    const instructors = members.filter(m => m.role === 'instructor');
    const students    = members.filter(m => m.role === 'student');

    return `
    <div class="page-header">
        <div class="page-header-left">
            <h1 class="page-title">Community Members</h1>
            <p class="page-subtitle">${members.length} member${members.length !== 1 ? 's' : ''} · ${instructors.length} instructor${instructors.length !== 1 ? 's' : ''}</p>
        </div>
    </div>

    ${instructors.length ? `
    <h3 style="font-family:'Fraunces',serif;font-size:0.95rem;color:var(--text-secondary);margin-bottom:0.875rem;letter-spacing:0.03em">Instructors</h3>
    <div class="members-grid" style="margin-bottom:2rem">
        ${instructors.map(m => renderMemberCard(m)).join('')}
    </div>` : ''}

    <h3 style="font-family:'Fraunces',serif;font-size:0.95rem;color:var(--text-secondary);margin-bottom:0.875rem;letter-spacing:0.03em">Students (${students.length})</h3>
    <div class="members-grid">
        ${students.length ? students.map(m => renderMemberCard(m)).join('') : '<p style="color:var(--text-muted);font-size:0.875rem">No students yet.</p>'}
    </div>`;
}

function renderMemberCard(member) {
    return `
    <div class="member-card" onclick="views.profile(${member.id})">
        ${member.role === 'instructor' ? '<div class="instructor-badge">Instructor</div>' : ''}
        <div class="member-avatar">${avatarEl(member, 56)}</div>
        <div class="member-name">${esc(member.name)}</div>
        <div class="member-stats">
            <div class="member-stat"><strong>${member.post_count}</strong>posts</div>
            <div class="member-stat"><strong>${member.comment_count}</strong>replies</div>
        </div>
    </div>`;
}

// ═══════════════════════════════════════════════════════════════════
// RENDER: ANALYTICS
// ═══════════════════════════════════════════════════════════════════

function renderAnalytics(data) {
    const maxPosts = Math.max(...data.space_activity.map(s => s.post_count), 1);
    const maxContrib = Math.max(...data.top_contributors.map(c => c.total), 1);

    return `
    <div class="page-header">
        <div class="page-header-left">
            <h1 class="page-title">Community Pulse</h1>
            <p class="page-subtitle">Understanding engagement and participation in ${esc(state.course.title)}</p>
        </div>
    </div>

    <div class="analytics-grid">
        <div class="stat-card">
            <div class="stat-card-value">${data.total_members}</div>
            <div class="stat-card-label">Members</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-value">${data.total_posts}</div>
            <div class="stat-card-label">Posts</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-value">${data.total_comments}</div>
            <div class="stat-card-label">Responses</div>
        </div>
        <div class="stat-card" ${data.unresolved_questions > 0 ? 'style="border-color:var(--warn)"' : ''}>
            <div class="stat-card-value" style="${data.unresolved_questions > 0 ? 'color:var(--warn)' : ''}">${data.unresolved_questions}</div>
            <div class="stat-card-label">Unanswered Questions</div>
        </div>
    </div>

    <div class="analytics-section">
        <div class="analytics-section-title">Activity by Space</div>
        <div class="bar-chart">
            ${data.space_activity.map(s => `
            <div class="bar-item">
                <div class="bar-label">${s.icon} ${esc(s.name)}</div>
                <div class="bar-track">
                    <div class="bar-fill" style="width:${Math.round(s.post_count/maxPosts*100)}%;background:${esc(s.color)}55;border-right:2px solid ${esc(s.color)}"></div>
                </div>
                <div class="bar-value">${s.post_count}</div>
            </div>`).join('')}
        </div>
    </div>

    ${data.top_contributors.length ? `
    <div class="analytics-section">
        <div class="analytics-section-title">Top Contributors</div>
        <div class="bar-chart">
            ${data.top_contributors.map(c => `
            <div class="bar-item" style="cursor:pointer" onclick="views.profile(${c.id})">
                <div class="bar-label" style="display:flex;align-items:center;gap:0.4rem">
                    <div style="width:20px;height:20px;border-radius:50%;overflow:hidden;flex-shrink:0">${avatarEl(c, 20)}</div>
                    ${esc(c.name)}
                </div>
                <div class="bar-track">
                    <div class="bar-fill" style="width:${Math.round(c.total/maxContrib*100)}%;background:var(--accent-2-tint);border-right:2px solid var(--accent-2)"></div>
                </div>
                <div class="bar-value">${c.total}</div>
            </div>`).join('')}
        </div>
    </div>` : ''}

    ${data.silent_students.length ? `
    <div class="analytics-section">
        <div class="analytics-section-title" style="color:var(--warn)">
            👋 Students with low recent participation
        </div>
        <p style="font-size:0.875rem;color:var(--text-secondary);margin-bottom:0.875rem">
            These students haven't posted or commented in the past two weeks. Consider reaching out.
        </p>
        <div class="silent-list">
            ${data.silent_students.map(s => `
            <div class="silent-item" style="cursor:pointer" onclick="views.profile(${s.id})">
                <div class="silent-item-avatar">${avatarEl(s, 32)}</div>
                <span class="silent-item-name">${esc(s.name)}</span>
                <span class="silent-item-last">Last seen: ${s.last_seen ? timeAgo(s.last_seen) : 'never'}</span>
            </div>`).join('')}
        </div>
    </div>` : ''}`;
}

// ═══════════════════════════════════════════════════════════════════
// RENDER: PROFILE MODAL
// ═══════════════════════════════════════════════════════════════════

function renderProfileModal(user, isOwn) {
    return `
    <div class="modal-header">
        <div class="modal-title">Profile</div>
        <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
        <div class="profile-header">
            <div class="profile-avatar" style="width:72px;height:72px">${avatarEl(user, 72)}</div>
            <div class="profile-info">
                ${user.role === 'instructor' ? '<div class="instructor-badge">Instructor</div>' : ''}
                <div class="profile-name">${esc(user.name)}</div>
                ${user.email ? `<div class="profile-email">${esc(user.email)}</div>` : ''}
                <div class="profile-bio" id="profile-bio-display">${user.bio ? esc(user.bio) : '<em style="color:var(--text-muted)">No bio yet</em>'}</div>
                ${isOwn ? `<button class="btn btn-ghost btn-sm" style="margin-top:0.4rem" onclick="editBio(${user.id})">Edit bio</button>` : ''}
            </div>
        </div>
        <div class="profile-stats">
            <div class="profile-stat">
                <div class="profile-stat-value">${user.post_count || 0}</div>
                <div class="profile-stat-label">Posts</div>
            </div>
            <div class="profile-stat">
                <div class="profile-stat-value">${user.comment_count || 0}</div>
                <div class="profile-stat-label">Responses</div>
            </div>
        </div>
        ${user.recent_posts?.length ? `
        <div>
            <div style="font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);margin-bottom:0.625rem">Recent Posts</div>
            ${user.recent_posts.map(p => `
            <div style="display:flex;align-items:center;gap:0.625rem;padding:0.5rem 0;border-bottom:1px solid var(--border);cursor:pointer"
                 onclick="closeModal();router.navigate('/post/${p.id}')">
                <span>${p.space_icon || '💬'}</span>
                <div>
                    <div style="font-size:0.875rem;font-weight:600">${esc(p.title)}</div>
                    <div style="font-size:0.75rem;color:var(--text-muted)">${esc(p.space_name)} · ${timeAgo(p.created_at)}</div>
                </div>
            </div>`).join('')}
        </div>` : ''}
    </div>`;
}

// ═══════════════════════════════════════════════════════════════════
// COMPOSE MODAL
// ═══════════════════════════════════════════════════════════════════

function openCompose(defaultType = 'discussion', defaultSpaceId = null) {
    const space = defaultSpaceId ? state.spaces.find(s => s.id === +defaultSpaceId) : null;
    const preferredSpaceId = defaultSpaceId || state.currentSpaceId || state.spaces.find(s => s.type === 'discussion')?.id;

    // Filter post types based on space type
    const isAnnouncement = space?.type === 'announcement';
    if (isAnnouncement && state.role !== 'instructor') {
        toast('Only instructors can post in Announcements');
        return;
    }

    const types = isAnnouncement
        ? [['📣','Announcement','announcement']]
        : [
            ['💬','Discussion','discussion'],
            ['❓','Question','question'],
            ['📚','Resource','resource'],
            ['🎉','Kudos','kudos'],
            ['🪞','Reflection','reflection'],
            ['📊','Poll','poll'],
        ];

    const chosenType = defaultType || 'discussion';

    openModal(`
    <div class="modal-header">
        <div class="modal-title">Create a Post</div>
        <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
        <div class="form-group">
            <div class="form-label">Type</div>
            <div class="type-selector" id="type-selector">
                ${types.map(([icon, label, type]) => `
                <div class="type-option ${type === chosenType ? 'selected' : ''}" data-type="${type}" onclick="selectType(this,'${type}')">
                    <span class="type-option-icon">${icon}</span>
                    <span class="type-option-label">${label}</span>
                </div>`).join('')}
            </div>
        </div>

        <div class="form-group">
            <div class="form-label">Space</div>
            <div class="space-selector" id="space-selector">
                ${state.spaces.map(s => `
                <div class="space-option ${s.id === preferredSpaceId ? 'selected' : ''}"
                     data-space-id="${s.id}" onclick="selectSpace(this,${s.id})">
                    ${s.icon} ${esc(s.name)}
                </div>`).join('')}
            </div>
        </div>

        <div class="form-group">
            <label class="form-label" for="post-title">Title</label>
            <input type="text" class="form-input" id="post-title" placeholder="A clear, specific title helps others engage…" maxlength="200">
        </div>

        <div class="form-group" id="content-group">
            <label class="form-label" for="post-content">Content</label>
            <textarea class="form-textarea" id="post-content" placeholder="Add context, links, your thinking…" rows="5"></textarea>
            <div class="form-hint">Supports **bold**, *italic*, and \`code\` formatting.</div>
        </div>

        <div id="type-extras"></div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
        <button class="btn btn-primary" id="submit-post-btn" onclick="submitPost()">Post</button>
    </div>`, true);

    // Set initial type extras
    updateTypeExtras(chosenType);
}

function selectType(el, type) {
    document.querySelectorAll('.type-option').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
    updateTypeExtras(type);
}

function selectSpace(el, id) {
    document.querySelectorAll('.space-option').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
}

function updateTypeExtras(type) {
    const extras = document.getElementById('type-extras');
    if (!extras) return;

    if (type === 'resource') {
        extras.innerHTML = `
        <div class="form-group">
            <label class="form-label" for="resource-url">Resource URL</label>
            <input type="url" class="form-input" id="resource-url" placeholder="https://…">
        </div>`;
    } else if (type === 'kudos') {
        const students = state.members.filter(m => m.id !== state.user.id);
        extras.innerHTML = `
        <div class="form-group">
            <label class="form-label" for="kudos-recipient">Give kudos to</label>
            <select class="form-select" id="kudos-recipient">
                <option value="">Select a community member…</option>
                ${students.map(m => `<option value="${m.id}">${esc(m.name)}</option>`).join('')}
            </select>
        </div>`;
    } else if (type === 'poll') {
        extras.innerHTML = `
        <div class="form-group">
            <label class="form-label">Poll Options</label>
            <div class="poll-options-builder" id="poll-builder">
                <div class="poll-option-row">
                    <input type="text" class="poll-option-input" placeholder="Option 1">
                    <button class="poll-remove-btn" onclick="removePollOption(this)">✕</button>
                </div>
                <div class="poll-option-row">
                    <input type="text" class="poll-option-input" placeholder="Option 2">
                    <button class="poll-remove-btn" onclick="removePollOption(this)">✕</button>
                </div>
            </div>
            <button class="add-option-btn btn-ghost" onclick="addPollOption()" style="margin-top:0.5rem">+ Add option</button>
        </div>`;
    } else {
        extras.innerHTML = '';
    }
}

function addPollOption() {
    const builder = document.getElementById('poll-builder');
    if (!builder) return;
    const count = builder.querySelectorAll('.poll-option-row').length + 1;
    const row = document.createElement('div');
    row.className = 'poll-option-row';
    row.innerHTML = `<input type="text" class="poll-option-input" placeholder="Option ${count}">
                     <button class="poll-remove-btn" onclick="removePollOption(this)">✕</button>`;
    builder.appendChild(row);
}

function removePollOption(btn) {
    const builder = document.getElementById('poll-builder');
    if (builder.querySelectorAll('.poll-option-row').length <= 2) { toast('Need at least 2 options'); return; }
    btn.closest('.poll-option-row').remove();
}

async function submitPost() {
    const type    = document.querySelector('.type-option.selected')?.dataset.type || 'discussion';
    const spaceId = +document.querySelector('.space-option.selected')?.dataset.spaceId;
    const title   = document.getElementById('post-title')?.value.trim();
    const content = document.getElementById('post-content')?.value.trim();

    if (!spaceId) { toast('Please select a space'); return; }
    if (!title)   { toast('Please add a title'); return; }

    const meta = {};

    if (type === 'resource') {
        const url = document.getElementById('resource-url')?.value.trim();
        if (!url) { toast('Please add a resource URL'); return; }
        try {
            const parsed = new URL(url);
            meta.url    = url;
            meta.domain = parsed.hostname;
        } catch { toast('Please enter a valid URL'); return; }
    }

    if (type === 'kudos') {
        const recipientId = document.getElementById('kudos-recipient')?.value;
        if (!recipientId) { toast('Please select someone to give kudos to'); return; }
        meta.recipient_id = +recipientId;
    }

    if (type === 'poll') {
        meta.options = Array.from(document.querySelectorAll('.poll-option-input'))
            .map(i => i.value.trim()).filter(Boolean);
        if (meta.options.length < 2) { toast('Need at least 2 poll options'); return; }
    }

    const btn = document.getElementById('submit-post-btn');
    btn.disabled = true; btn.textContent = 'Posting…';

    try {
        const post = await api.post('/api/posts', { space_id: spaceId, type, title, content, meta });
        closeModal();
        toast('Post created!');
        router.navigate(`/post/${post.id}`);
    } catch (e) {
        toast(e.message);
        btn.disabled = false; btn.textContent = 'Post';
    }
}

// ═══════════════════════════════════════════════════════════════════
// BOARD MODALS
// ═══════════════════════════════════════════════════════════════════

function openNewBoardModal() {
    openModal(`
    <div class="modal-header">
        <div class="modal-title">New Collaboration Board</div>
        <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
        <div class="form-group">
            <label class="form-label">Title</label>
            <input type="text" class="form-input" id="board-title" placeholder="e.g. Week 3 Brainstorm">
        </div>
        <div class="form-group">
            <label class="form-label">Description</label>
            <input type="text" class="form-input" id="board-desc" placeholder="What's this board for?">
        </div>
        <div class="form-group">
            <label class="form-label">Prompt <span style="font-weight:400;text-transform:none;font-size:0.85em;color:var(--text-muted)">(optional — displayed on the board)</span></label>
            <input type="text" class="form-input" id="board-prompt" placeholder="e.g. What challenges are you anticipating this week?">
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
        <button class="btn btn-primary" onclick="submitNewBoard()">Create Board</button>
    </div>`);
}

async function submitNewBoard() {
    const title  = document.getElementById('board-title')?.value.trim();
    const desc   = document.getElementById('board-desc')?.value.trim();
    const prompt = document.getElementById('board-prompt')?.value.trim();
    if (!title) { toast('Title is required'); return; }
    try {
        const board = await api.post('/api/boards', { title, description: desc, prompt });
        closeModal();
        router.navigate(`/board/${board.id}`);
    } catch (e) { toast(e.message); }
}

function openAddCardModal(boardId) {
    const colors = ['#FFF9C4','#FFE0B2','#F8BBD0','#DCEDC8','#B3E5FC','#E1BEE7','#B2DFDB'];
    let chosenColor = colors[Math.floor(Math.random() * colors.length)];

    openModal(`
    <div class="modal-header">
        <div class="modal-title">Add Card</div>
        <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
        <div class="form-group">
            <label class="form-label">Your idea or response</label>
            <textarea class="form-textarea" id="card-content" style="min-height:120px" placeholder="Write your contribution here…"></textarea>
        </div>
        <div class="form-group">
            <label class="form-label">Card colour</label>
            <div style="display:flex;gap:0.5rem">
                ${colors.map(c => `
                <button class="color-dot" data-color="${c}"
                        style="width:28px;height:28px;border-radius:50%;background:${c};border:2px solid ${c === chosenColor ? '#333' : 'transparent'};cursor:pointer"
                        onclick="document.querySelectorAll('.color-dot').forEach(d=>d.style.border='2px solid transparent');this.style.border='2px solid #333';window._cardColor='${c}'">
                </button>`).join('')}
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
        <button class="btn btn-primary" onclick="submitCard(${boardId})">Add Card</button>
    </div>`);
    window._cardColor = chosenColor;
}

async function submitCard(boardId) {
    const content = document.getElementById('card-content')?.value.trim();
    if (!content) { toast('Please write something'); return; }
    try {
        const card = await api.post(`/api/boards/${boardId}/cards`, {
            content, color: window._cardColor || '#FFF9C4',
        });
        closeModal();
        // Add card to canvas
        const canvas = document.getElementById('board-canvas');
        if (canvas) {
            canvas.insertAdjacentHTML('beforeend', renderStickyCard(card));
        }
        toast('Card added!');
    } catch (e) { toast(e.message); }
}

// ═══════════════════════════════════════════════════════════════════
// EVENT HANDLERS
// ═══════════════════════════════════════════════════════════════════

function bindFeedEvents() {
    // Sort tabs
    document.getElementById('sort-tabs')?.addEventListener('click', async e => {
        const tab = e.target.closest('.tab');
        if (!tab) return;
        document.querySelectorAll('#sort-tabs .tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        const sort = tab.dataset.sort;
        const spaceParam = state.currentSpaceId ? `&space_id=${state.currentSpaceId}` : '';
        const data = await api.get(`/api/posts?sort=${sort}${spaceParam}`);
        document.getElementById('post-list').innerHTML = data.posts.map((p,i) => renderPostCard(p, !state.currentSpaceId, i)).join('');
    });

    // Pagination
    document.addEventListener('click', async e => {
        const btn = e.target.closest('[data-page]');
        if (!btn) return;
        const page = btn.dataset.page;
        const spaceParam = state.currentSpaceId ? `&space_id=${state.currentSpaceId}` : '';
        const data = await api.get(`/api/posts?page=${page}${spaceParam}`);
        setView(state.currentSpaceId
            ? renderSpaceView(state.spaces.find(s => s.id === state.currentSpaceId), data.posts, data)
            : renderFeedView(data.posts, data));
        bindFeedEvents();
    });
}

function bindPostDetailEvents(post) {
    // Nothing extra needed — inline event handlers are used
}

// Voting
window.votePost = async (postId, value, btn) => {
    const isActive = btn.classList.contains('active');
    const newValue = isActive ? 0 : value;
    try {
        const res = await api.post(`/api/posts/${postId}/vote`, { value: newValue });
        document.getElementById('post-vote-count').textContent = res.vote_count;
        document.querySelector('.vote-btn.up')?.classList.toggle('active', res.user_vote === 1);
        document.querySelector('.vote-btn.down')?.classList.toggle('active', res.user_vote === -1);
    } catch (e) { toast(e.message); }
};

window.voteComment = async (commentId, btn) => {
    const isActive = btn.classList.contains('upvoted');
    const value = isActive ? 0 : 1;
    try {
        const res = await api.post(`/api/comments/${commentId}/vote`, { value });
        btn.classList.toggle('upvoted', !isActive);
        btn.querySelector('.comment-vote-val').textContent = res.vote_count;
    } catch (e) { toast(e.message); }
};

window.voteCard = async (cardId, btn) => {
    try {
        const res = await api.post(`/api/cards/${cardId}/vote`);
        btn.classList.toggle('voted', res.voted);
        btn.querySelector('.card-vote-val').textContent = res.votes;
    } catch (e) { toast(e.message); }
};

// Reactions
window.reactPost = async (postId, emoji, btn) => {
    try {
        const res = await api.post(`/api/posts/${postId}/react`, { emoji });
        // Re-render reaction bar
        const bar = document.getElementById('reaction-bar');
        if (bar) {
            const EMOJIS = ['👍','❤️','🔥','💡','🤔','😮','🎉','👏','⭐','🙏'];
            const reactions = res.reactions.map(r =>
                `<span class="reaction-pill ${r.mine ? 'mine' : ''}"
                      onclick="reactPost(${postId},'${r.emoji}',null)">${r.emoji} ${r.count}</span>`
            ).join('');
            // Keep emoji picker button
            bar.querySelector('.reaction-pill')?.remove();
            bar.insertAdjacentHTML('afterbegin', reactions);
        }
    } catch (e) { toast(e.message); }
};

window.toggleEmojiPicker = (postId) => {
    const picker = document.getElementById(`emoji-picker-${postId}`);
    if (picker) picker.style.display = picker.style.display === 'none' ? 'flex' : 'none';
};
document.addEventListener('click', e => {
    if (!e.target.closest('#add-reaction-btn') && !e.target.closest('.emoji-picker')) {
        document.querySelectorAll('.emoji-picker').forEach(p => p.style.display = 'none');
    }
});

// Comment actions
window.submitComment = async (postId) => {
    const content = document.getElementById('comment-input')?.value.trim();
    if (!content) { toast('Please write a response'); return; }
    const isNote = document.getElementById('instructor-note-cb')?.checked;
    try {
        const comment = await api.post(`/api/posts/${postId}/comments`, {
            content, is_instructor_note: isNote,
        });
        document.getElementById('comments-list').insertAdjacentHTML('beforeend',
            renderComment(comment, { author_id: state.user.id, type: 'discussion' }, false));
        document.getElementById('comment-input').value = '';
        // Update count
        const h2 = document.querySelector('.comments-header');
        if (h2) {
            const count = document.querySelectorAll('#comments-list > .comment').length;
            h2.textContent = `${count} Response${count !== 1 ? 's' : ''}`;
        }
    } catch (e) { toast(e.message); }
};

window.showReplyBox = (commentId, postId) => {
    const existing = document.getElementById(`reply-box-${commentId}`);
    if (existing) { existing.remove(); return; }
    const repliesEl = document.getElementById(`replies-${commentId}`);
    if (!repliesEl) return;
    repliesEl.style.display = '';
    repliesEl.insertAdjacentHTML('beforeend', `
    <div id="reply-box-${commentId}" style="display:flex;gap:0.625rem;align-items:flex-start;margin-top:0.5rem;padding-top:0.5rem">
        <div style="width:28px;height:28px;border-radius:50%;overflow:hidden;flex-shrink:0">${avatarEl(state.user, 28)}</div>
        <div style="flex:1">
            <textarea class="composer-textarea" id="reply-input-${commentId}"
                      style="min-height:70px;font-size:0.85rem" placeholder="Reply…"></textarea>
            <div style="display:flex;gap:0.4rem;margin-top:0.4rem;justify-content:flex-end">
                <button class="btn btn-ghost btn-sm" onclick="document.getElementById('reply-box-${commentId}').remove()">Cancel</button>
                <button class="btn btn-primary btn-sm" onclick="submitReply(${commentId},${postId})">Reply</button>
            </div>
        </div>
    </div>`);
    document.getElementById(`reply-input-${commentId}`)?.focus();
};

window.submitReply = async (parentId, postId) => {
    const content = document.getElementById(`reply-input-${parentId}`)?.value.trim();
    if (!content) { toast('Please write a reply'); return; }
    try {
        const comment = await api.post(`/api/posts/${postId}/comments`, { content, parent_id: parentId });
        document.getElementById(`reply-box-${parentId}`)?.remove();
        const repliesEl = document.getElementById(`replies-${parentId}`);
        repliesEl?.insertAdjacentHTML('beforeend', renderComment(comment, {author_id: state.user.id}, false));
        toast('Reply posted!');
    } catch (e) { toast(e.message); }
};

window.markAnswer = async (commentId, btn) => {
    try {
        const res = await api.post(`/api/comments/${commentId}/answer`);
        // Reload post
        const match = location.pathname.match(/\/post\/(\d+)/);
        if (match) views.post(+match[1]);
        toast(res.is_answer ? 'Marked as accepted answer' : 'Unmarked');
    } catch (e) { toast(e.message); }
};

window.deleteComment = async (commentId) => {
    if (!confirm('Delete this response?')) return;
    try {
        await api.del(`/api/comments/${commentId}`);
        document.getElementById(`comment-${commentId}`)?.remove();
        toast('Deleted');
    } catch (e) { toast(e.message); }
};

window.toggleResolve = async (postId, btn) => {
    try {
        const res = await api.post(`/api/posts/${postId}/resolve`);
        btn.textContent = res.is_resolved ? '↩ Unresolve' : '✓ Mark Resolved';
        if (res.is_resolved) {
            document.querySelector('.post-detail-header')?.insertAdjacentHTML('afterbegin',
                '<div class="resolved-banner">✓ This question has been resolved</div>');
        } else {
            document.querySelector('.resolved-banner')?.remove();
        }
    } catch (e) { toast(e.message); }
};

window.togglePin = async (postId, btn) => {
    try {
        const res = await api.post(`/api/posts/${postId}/pin`);
        btn.textContent = res.is_pinned ? '📌 Unpin' : '📌 Pin';
        toast(res.is_pinned ? 'Pinned!' : 'Unpinned');
    } catch (e) { toast(e.message); }
};

window.toggleFeature = async (postId, btn) => {
    try {
        const res = await api.post(`/api/posts/${postId}/feature`);
        btn.textContent = res.is_featured ? '⭐ Unfeature' : '⭐ Feature';
        toast(res.is_featured ? 'Featured!' : 'Unfeatured');
    } catch (e) { toast(e.message); }
};

window.deletePost = async (postId) => {
    if (!confirm('Delete this post and all its responses?')) return;
    try {
        await api.del(`/api/posts/${postId}`);
        toast('Post deleted');
        history.back();
    } catch (e) { toast(e.message); }
};

window.editPost = (postId) => {
    toast('Edit functionality — coming soon in full version');
};

window.editBio = async (userId) => {
    const newBio = prompt('Update your bio (max 200 characters):', document.getElementById('profile-bio-display')?.textContent.trim() || '');
    if (newBio === null) return;
    try {
        await api.put(`/api/users/${userId}`, { bio: newBio.slice(0, 200) });
        document.getElementById('profile-bio-display').textContent = newBio || '';
        toast('Bio updated!');
    } catch (e) { toast(e.message); }
};

window.castPollVote = async (postId, optionIdx, container) => {
    try {
        const res = await api.post(`/api/polls/${postId}/vote`, { option: optionIdx });
        if (container) container.outerHTML = renderPollDetail(res.results, postId);
        toast('Vote cast!');
    } catch (e) { toast(e.message); }
};

// ═══════════════════════════════════════════════════════════════════
// NOTIFICATIONS
// ═══════════════════════════════════════════════════════════════════

function toggleNotifPanel() {
    state.notifOpen = !state.notifOpen;
    const host = document.getElementById('notif-host');
    if (!state.notifOpen) {
        host.innerHTML = '';
        return;
    }

    host.innerHTML = `
    <div class="notif-panel">
        <div class="notif-panel-header">
            <strong>Notifications</strong>
            <button class="btn btn-ghost btn-sm" onclick="markNotifsRead()">Mark all read</button>
        </div>
        ${state.notifications.length ? state.notifications.map(n => `
        <div class="notif-item ${n.is_read ? '' : 'unread'}" onclick="${n.link ? `closeNotifPanel();router.navigate('${n.link}')` : 'void 0'}">
            <div class="notif-dot" style="${n.is_read ? 'opacity:0' : ''}"></div>
            <div>
                <div class="notif-text">${esc(n.message)}</div>
                <div class="notif-time">${timeAgo(n.created_at)}</div>
            </div>
        </div>`).join('') : `
        <div style="padding:2rem;text-align:center;color:var(--text-muted);font-size:0.875rem">No notifications yet</div>`}
    </div>`;

    // Close on outside click
    setTimeout(() => {
        document.addEventListener('click', closeNotifOnOutside, { once: false });
    }, 100);
}

function closeNotifOnOutside(e) {
    if (!e.target.closest('.notif-panel') && !e.target.closest('#notif-btn')) {
        closeNotifPanel();
        document.removeEventListener('click', closeNotifOnOutside);
    }
}

window.closeNotifPanel = () => {
    state.notifOpen = false;
    document.getElementById('notif-host').innerHTML = '';
};

window.markNotifsRead = async () => {
    try {
        await api.put('/api/notifications/read');
        state.unreadCount = 0;
        state.notifications = state.notifications.map(n => ({...n, is_read: 1}));
        updateNotifBadge();
        closeNotifPanel();
    } catch (_) {}
};

// ═══════════════════════════════════════════════════════════════════
// MODAL
// ═══════════════════════════════════════════════════════════════════

function openModal(html, large = false) {
    const host = document.getElementById('modal-host');
    host.innerHTML = `
    <div class="modal-overlay" id="modal-overlay" onclick="if(event.target===this)closeModal()">
        <div class="modal" style="${large ? 'max-width:720px' : ''}">
            ${html}
        </div>
    </div>`;
}

window.closeModal = () => {
    document.getElementById('modal-host').innerHTML = '';
};

// ═══════════════════════════════════════════════════════════════════
// UTILITIES
// ═══════════════════════════════════════════════════════════════════

function setView(html) {
    document.getElementById('view-content').innerHTML = html;
}

function updatePanel() {
    const panel = document.querySelector('.panel');
    if (panel) panel.outerHTML = renderRightPanel();
    // Re-bind panel nav links
    document.querySelector('.panel')?.addEventListener('click', e => {
        const nav = e.target.closest('[data-nav]');
        if (nav) router.navigate(nav.dataset.nav);
    });
}

function avatarEl(user, size = 36) {
    if (!user) return `<div style="width:${size}px;height:${size}px;border-radius:50%;background:var(--border)"></div>`;
    const name   = user.name || user.given_name || '?';
    const initials = name.split(' ').map(p => p[0]).join('').slice(0, 2).toUpperCase();
    const hue    = [...name].reduce((acc, c) => acc + c.charCodeAt(0), 0) % 360;
    if (user.picture) {
        return `<img src="${esc(user.picture)}" alt="${esc(name)}" style="width:${size}px;height:${size}px;border-radius:50%;object-fit:cover" onerror="this.outerHTML=\`<div style='width:${size}px;height:${size}px;border-radius:50%;background:hsl(${hue},45%,50%);display:flex;align-items:center;justify-content:center;color:white;font-size:${Math.round(size*0.36)}px;font-weight:700'>${initials}</div>\`">`;
    }
    return `<div style="width:${size}px;height:${size}px;border-radius:50%;background:hsl(${hue},45%,50%);display:flex;align-items:center;justify-content:center;color:white;font-size:${Math.round(size*0.36)}px;font-weight:700">${initials}</div>`;
}

function esc(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function renderMarkdown(text) {
    if (!text) return '';
    return esc(text)
        // Code blocks
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        // Bold
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        // Italic
        .replace(/\*([^*]+)\*/g, '<em>$1</em>')
        // Blockquote
        .replace(/^&gt; (.+)$/gm, '<blockquote>$1</blockquote>')
        // Double newlines → paragraph breaks
        .replace(/\n\n/g, '</p><p>')
        .replace(/\n/g, '<br>');
}

function timeAgo(ts) {
    if (!ts) return '';
    const seconds = Math.floor(Date.now() / 1000 - ts);
    if (seconds < 60)   return 'just now';
    if (seconds < 3600) return `${Math.floor(seconds/60)}m ago`;
    if (seconds < 86400)return `${Math.floor(seconds/3600)}h ago`;
    if (seconds < 604800) return `${Math.floor(seconds/86400)}d ago`;
    return new Date(ts * 1000).toLocaleDateString('en', { month:'short', day:'numeric' });
}

function postTypeLabel(type) {
    const map = {
        discussion: '💬 Discussion', question: '❓ Question', resource: '📚 Resource',
        kudos: '🎉 Kudos', reflection: '🪞 Reflection', poll: '📊 Poll',
        announcement: '📣 Announcement',
    };
    return map[type] || type;
}

function spaceTypeInfo(type) {
    const map = {
        announcement: { desc: 'Important updates from your instructor.' },
        discussion:   { desc: 'Open conversation about the course.' },
        qa:           { desc: 'Ask questions and get answers.' },
        resources:    { desc: 'Shared articles, tools, and references.' },
        kudos:        { desc: 'Celebrate each other.' },
        collaboration:{ desc: 'Visual thinking and co-creation.' },
    };
    return map[type] || {};
}

function loadingInline() {
    return `<div class="loading-inline"><div class="spinner"></div> Loading…</div>`;
}

function errorState(msg) {
    return `<div class="empty-state">
        <div class="empty-state-icon">⚠️</div>
        <div class="empty-state-title">Something went wrong</div>
        <p class="empty-state-text">${esc(msg)}</p>
    </div>`;
}

function errorScreen(msg) {
    return `<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;gap:1rem;padding:2rem;font-family:sans-serif">
        <div style="font-size:3rem">⚠️</div>
        <h2>Could not load</h2>
        <p style="color:#666;max-width:400px;text-align:center">${esc(msg)}</p>
        <button onclick="location.reload()" style="padding:0.5rem 1.5rem;background:#C84B10;color:white;border:none;border-radius:8px;cursor:pointer">Retry</button>
    </div>`;
}

function toast(msg, duration = 3000) {
    const el = document.createElement('div');
    el.className = 'toast';
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), duration);
}

// ═══════════════════════════════════════════════════════════════════
// PEER FEEDBACK VIEWS
// ═══════════════════════════════════════════════════════════════════

const PF_STATUS_LABELS = { draft: 'Draft', open: 'Open for Submissions', reviewing: 'Under Review', closed: 'Closed' };
const PF_STATUS_COLORS = { draft: '#888', open: '#2ECC71', reviewing: '#F39C12', closed: '#95a5a6' };

function pfStatusBadge(status) {
    const label = PF_STATUS_LABELS[status] || status;
    const color = PF_STATUS_COLORS[status] || '#888';
    return `<span class="pf-status-badge" style="background:${color}22;color:${color};border-color:${color}44">${label}</span>`;
}

function renderFeedbackList(assignments) {
    const isInstructor = state.role === 'instructor';
    const createBtn = isInstructor
        ? `<button class="btn btn-primary" id="pf-create-btn">+ New Assignment</button>`
        : '';

    const cards = assignments.length ? assignments.map(a => `
    <div class="pf-card" data-id="${a.id}" style="cursor:pointer" onclick="router.navigate('/feedback/${a.id}')">
        <div class="pf-card-header">
            <div class="pf-card-title-row">
                <h3 class="pf-card-title">${esc(a.title)}</h3>
                ${pfStatusBadge(a.status)}
            </div>
            ${a.description ? `<p class="pf-card-desc">${esc(a.description)}</p>` : ''}
        </div>
        <div class="pf-card-meta">
            <span>📄 ${a.submission_count} submission${a.submission_count !== 1 ? 's' : ''}</span>
            <span>👥 ${a.reviewers_per_sub} reviewer${a.reviewers_per_sub !== 1 ? 's' : ''} per submission</span>
            ${a.pending_reviews > 0 ? `<span class="pf-pending-badge">⚠️ ${a.pending_reviews} review${a.pending_reviews !== 1 ? 's' : ''} pending</span>` : ''}
            ${a.my_submission ? `<span class="pf-done-badge">✓ Submitted</span>` : ''}
        </div>
    </div>`).join('') : `<div class="empty-state"><p class="empty-state-text">No peer feedback assignments yet.${isInstructor ? ' Create one to get started.' : ''}</p></div>`;

    return `
    <div class="page-header">
        <div class="page-header-left">
            <h1 class="page-title">🔁 Peer Feedback</h1>
            <p class="page-subtitle">Anonymous peer review assignments for ${esc(state.course.title)}</p>
        </div>
        ${createBtn}
    </div>
    <div class="pf-list">${cards}</div>`;
}

function bindFeedbackListEvents() {
    document.getElementById('pf-create-btn')?.addEventListener('click', () => openCreateFeedbackModal());
}

function openCreateFeedbackModal() {
    openModal(`
    <div class="modal-header"><h2>New Peer Feedback Assignment</h2></div>
    <div class="modal-body">
        <label class="field-label">Title <span style="color:var(--accent)">*</span></label>
        <input id="pf-title" class="input" placeholder="e.g. Draft Essay Review" maxlength="120">

        <label class="field-label" style="margin-top:1rem">Description / Instructions</label>
        <textarea id="pf-desc" class="textarea" rows="3" placeholder="What should students submit? What criteria should reviewers use?"></textarea>

        <label class="field-label" style="margin-top:1rem">Feedback Prompts</label>
        <div id="pf-prompts-list"></div>
        <button class="btn btn-ghost btn-sm" id="pf-add-prompt" style="margin-top:0.5rem">+ Add Prompt</button>

        <div class="pf-options-grid" style="margin-top:1rem">
            <div>
                <label class="field-label">Reviewers per submission</label>
                <input id="pf-rps" class="input" type="number" min="1" max="10" value="2" style="width:80px">
            </div>
            <div>
                <label class="field-label">Submission type</label>
                <div style="display:flex;gap:1rem;margin-top:0.5rem">
                    <label><input type="checkbox" id="pf-allow-text" checked> Text</label>
                    <label><input type="checkbox" id="pf-allow-files"> File upload</label>
                </div>
            </div>
        </div>
        <div id="pf-file-opts" style="display:none;margin-top:1rem">
            <label class="field-label">Accepted file types (comma-separated, e.g. pdf,docx)</label>
            <input id="pf-types" class="input" value="pdf,doc,docx" placeholder="pdf,doc,docx">
            <label class="field-label" style="margin-top:0.75rem">Max file size (MB)</label>
            <input id="pf-maxmb" class="input" type="number" min="1" max="50" value="10" style="width:80px">
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
        <button class="btn btn-primary" id="pf-save-btn">Create Assignment</button>
    </div>`, true);

    // Dynamic prompts
    const promptsList = document.getElementById('pf-prompts-list');
    let promptCount = 0;

    const addPrompt = (label = '', desc = '') => {
        const idx = promptCount++;
        const row = document.createElement('div');
        row.className = 'pf-prompt-row';
        row.dataset.idx = idx;
        row.innerHTML = `
            <input class="input pf-prompt-label" placeholder="Prompt label (e.g. Clarity)" value="${esc(label)}" style="flex:1">
            <input class="input pf-prompt-desc" placeholder="Description (optional)" value="${esc(desc)}" style="flex:2">
            <button class="btn btn-ghost btn-sm pf-remove-prompt" title="Remove">✕</button>`;
        row.querySelector('.pf-remove-prompt').addEventListener('click', () => row.remove());
        promptsList.appendChild(row);
    };

    // Add two default prompts
    addPrompt('Strengths', 'What did this work do well?');
    addPrompt('Suggestions', 'What could be improved?');

    document.getElementById('pf-add-prompt').addEventListener('click', () => addPrompt());

    document.getElementById('pf-allow-files').addEventListener('change', e => {
        document.getElementById('pf-file-opts').style.display = e.target.checked ? '' : 'none';
    });

    document.getElementById('pf-save-btn').addEventListener('click', async () => {
        const title = document.getElementById('pf-title').value.trim();
        if (!title) { toast('Please enter a title'); return; }

        const prompts = [...promptsList.querySelectorAll('.pf-prompt-row')].map(row => ({
            label: row.querySelector('.pf-prompt-label').value.trim(),
            description: row.querySelector('.pf-prompt-desc').value.trim(),
        })).filter(p => p.label);

        const payload = {
            title,
            description: document.getElementById('pf-desc').value.trim(),
            prompts,
            reviewers_per_sub: parseInt(document.getElementById('pf-rps').value) || 2,
            allow_text:  document.getElementById('pf-allow-text').checked  ? 1 : 0,
            allow_files: document.getElementById('pf-allow-files').checked ? 1 : 0,
            accepted_types: document.getElementById('pf-types').value.trim() || 'pdf,doc,docx',
            max_file_mb: parseInt(document.getElementById('pf-maxmb').value) || 10,
        };

        try {
            const a = await api.post('/api/feedback', payload);
            closeModal();
            router.navigate(`/feedback/${a.id}`);
        } catch (e) { toast(e.message); }
    });
}

function renderFeedbackDetail(a, extra = {}) {
    const isInstructor = state.role === 'instructor';

    // Build instructor controls
    let controls = '';
    if (isInstructor) {
        if (a.status === 'draft') {
            controls = `
            <div class="pf-controls">
                <button class="btn btn-secondary" onclick="pfEditAssignment(${a.id})">Edit</button>
                <button class="btn btn-primary" id="pf-publish-btn">Open for Submissions</button>
                <button class="btn btn-danger-ghost" onclick="pfDeleteAssignment(${a.id})">Delete</button>
            </div>`;
        } else if (a.status === 'open') {
            controls = `
            <div class="pf-controls">
                <button class="btn btn-primary" id="pf-assign-btn">Assign Reviewers & Start Review Phase</button>
                <button class="btn btn-secondary" id="pf-close-btn">Close Assignment</button>
            </div>`;
        } else if (a.status === 'reviewing') {
            controls = `
            <div class="pf-controls">
                <button class="btn btn-primary" id="pf-assign-btn">Re-assign Reviewers</button>
                <button class="btn btn-secondary" id="pf-close-btn">Close & Release Feedback</button>
            </div>`;
        }
    }

    // Progress bar (instructor)
    let progressSection = '';
    if (isInstructor && extra.progress) {
        const p = extra.progress;
        const subPct  = p.enrolled_students > 0 ? Math.round(p.submission_count  / p.enrolled_students * 100) : 0;
        const revPct  = p.review_total      > 0 ? Math.round(p.review_done       / p.review_total      * 100) : 0;
        progressSection = `
        <div class="pf-section">
            <h3 class="pf-section-title">Progress</h3>
            <div class="pf-progress-row">
                <span class="pf-progress-label">Submissions</span>
                <div class="pf-progress-bar"><div class="pf-progress-fill" style="width:${subPct}%"></div></div>
                <span class="pf-progress-pct">${p.submission_count} / ${p.enrolled_students}</span>
            </div>
            ${p.review_total > 0 ? `
            <div class="pf-progress-row">
                <span class="pf-progress-label">Reviews done</span>
                <div class="pf-progress-bar"><div class="pf-progress-fill" style="width:${revPct}%"></div></div>
                <span class="pf-progress-pct">${p.review_done} / ${p.review_total}</span>
            </div>` : ''}
        </div>
        <div class="pf-section">
            <h3 class="pf-section-title">Submissions (${p.submission_count})</h3>
            ${p.submissions.length ? `
            <table class="pf-table">
                <thead><tr><th>Student</th><th>Submitted</th><th>Reviews</th></tr></thead>
                <tbody>
                ${p.submissions.map(sub => `
                <tr>
                    <td>${esc(sub.author_name)}</td>
                    <td>${new Date(sub.submitted_at * 1000).toLocaleDateString()}</td>
                    <td>${sub.completed_reviews} / ${sub.assigned_reviewers}</td>
                </tr>`).join('')}
                </tbody>
            </table>` : '<p class="pf-empty">No submissions yet.</p>'}
        </div>`;
    }

    // My submission section (student, open phase)
    let submitSection = '';
    if (!isInstructor && a.status === 'open') {
        const sub = extra.mySubmission;
        const hasSub = sub && (sub.text_content || sub.file_name);
        submitSection = `
        <div class="pf-section" id="pf-submit-section">
            <h3 class="pf-section-title">Your Submission</h3>
            ${hasSub ? `
            <div class="pf-submitted-state">
                <span class="pf-done-badge">✓ Submitted</span>
                ${sub.file_name ? `<span>📎 ${esc(sub.file_name)}</span>` : ''}
                ${sub.text_content ? `<div class="pf-submitted-text">${esc(sub.text_content.slice(0, 200))}${sub.text_content.length > 200 ? '…' : ''}</div>` : ''}
                <div style="margin-top:0.75rem">
                    <button class="btn btn-secondary btn-sm" onclick="pfShowSubmitForm(${a.id}, ${JSON.stringify(sub).replace(/</g,'&lt;').replace(/"/g,'&quot;')})">Edit Submission</button>
                    <button class="btn btn-danger-ghost btn-sm" onclick="pfWithdrawSubmission(${a.id})">Withdraw</button>
                </div>
            </div>` : `
            <p style="color:var(--text-muted);font-size:0.875rem;margin-bottom:1rem">Submit your work for peer review. Your identity will be kept anonymous to reviewers.</p>
            <div id="pf-submit-form-area">
                ${renderSubmitForm(a)}
            </div>`}
        </div>`;
    }

    // My reviews section (student, reviewing/closed)
    let reviewsSection = '';
    if (!isInstructor && extra.myReviews) {
        const reviews = extra.myReviews.reviews || [];
        reviewsSection = `
        <div class="pf-section">
            <h3 class="pf-section-title">Reviews Assigned to You (${reviews.length})</h3>
            ${reviews.length ? `
            <div class="pf-review-list">
                ${reviews.map(r => `
                <div class="pf-review-item ${r.completed_at ? 'done' : 'pending'}">
                    <div class="pf-review-item-info">
                        <span class="pf-review-num">Submission #${r.submission_id}</span>
                        <span>${r.file_name ? `📎 ${esc(r.file_name)}` : '📝 Text submission'}</span>
                    </div>
                    <div class="pf-review-item-status">
                        ${r.completed_at
                            ? `<span class="pf-done-badge">✓ Reviewed</span>
                               <button class="btn btn-ghost btn-sm" onclick="router.navigate('/feedback/${a.id}/review/${r.id}')">View</button>`
                            : `<span class="pf-pending-badge">Pending</span>
                               <button class="btn btn-primary btn-sm" onclick="router.navigate('/feedback/${a.id}/review/${r.id}')">Give Feedback</button>`}
                    </div>
                </div>`).join('')}
            </div>` : '<p class="pf-empty">No reviews assigned to you yet.</p>'}
        </div>`;
    }

    // Feedback received (student, closed)
    let receivedSection = '';
    if (!isInstructor && a.status === 'closed' && extra.received) {
        const responses = extra.received.responses || [];
        const prompts   = a.prompts || [];
        receivedSection = `
        <div class="pf-section">
            <h3 class="pf-section-title">Feedback You Received (${responses.length} review${responses.length !== 1 ? 's' : ''})</h3>
            ${responses.length ? responses.map((r, i) => `
            <div class="pf-response-card">
                <div class="pf-response-header">Review ${i + 1}</div>
                ${prompts.map((p, pi) => r.answers && r.answers[pi] ? `
                <div class="pf-response-prompt">
                    <div class="pf-response-prompt-label">${esc(p.label)}</div>
                    <div class="pf-response-answer">${renderMarkdown(r.answers[pi])}</div>
                </div>` : '').join('')}
                ${r.overall_comment ? `
                <div class="pf-response-prompt">
                    <div class="pf-response-prompt-label">Overall Comment</div>
                    <div class="pf-response-answer">${renderMarkdown(r.overall_comment)}</div>
                </div>` : ''}
            </div>`).join('') : '<p class="pf-empty">No feedback received yet.</p>'}
        </div>`;
    }

    // Status-based student info banner
    let statusBanner = '';
    if (!isInstructor) {
        const banners = {
            draft:     '',
            open:      '',
            reviewing: '<div class="pf-info-banner">🔒 Submission period is closed. Complete your assigned reviews below.</div>',
            closed:    '<div class="pf-info-banner success">✅ This assignment is closed. Your feedback is shown below.</div>',
        };
        statusBanner = banners[a.status] || '';
    }

    const promptsHtml = (a.prompts || []).length ? `
    <div class="pf-section">
        <h3 class="pf-section-title">Review Prompts</h3>
        <ol class="pf-prompts-list">
            ${a.prompts.map(p => `<li><strong>${esc(p.label)}</strong>${p.description ? ` — ${esc(p.description)}` : ''}</li>`).join('')}
        </ol>
    </div>` : '';

    return `
    <div class="pf-detail">
        <div class="pf-detail-header">
            <button class="btn btn-ghost btn-sm" onclick="router.navigate('/feedback')">← Back</button>
            <div class="pf-detail-title-row">
                <h1 class="page-title">${esc(a.title)}</h1>
                ${pfStatusBadge(a.status)}
            </div>
            ${a.description ? `<p class="page-subtitle">${esc(a.description)}</p>` : ''}
        </div>

        ${controls}
        ${statusBanner}

        <div class="pf-meta-row">
            <span>👥 ${a.reviewers_per_sub} reviewer${a.reviewers_per_sub !== 1 ? 's' : ''} per submission</span>
            <span>${a.allow_text ? '📝 Text' : ''}${a.allow_text && a.allow_files ? ' &amp; ' : ''}${a.allow_files ? '📎 Files' : ''}</span>
            ${a.allow_files ? `<span>Max ${a.max_file_mb} MB · ${esc(a.accepted_types)}</span>` : ''}
        </div>

        ${promptsHtml}
        ${submitSection}
        ${reviewsSection}
        ${receivedSection}
        ${progressSection}
    </div>`;
}

function renderSubmitForm(a, existingText = '') {
    const tabs = (a.allow_text && a.allow_files)
        ? `<div class="pf-submit-tabs">
               <button class="pf-submit-tab active" data-tab="text">Text</button>
               <button class="pf-submit-tab" data-tab="file">File</button>
           </div>`
        : '';
    const textPane = a.allow_text ? `
        <div class="pf-submit-pane" data-pane="text">
            <textarea id="pf-text-input" class="textarea" rows="8" placeholder="Write your submission here…">${esc(existingText)}</textarea>
        </div>` : '';
    const filePane = a.allow_files ? `
        <div class="pf-submit-pane" data-pane="file" style="${a.allow_text ? 'display:none' : ''}">
            <div class="pf-file-drop" id="pf-file-drop">
                <input type="file" id="pf-file-input" accept="${esc(a.accepted_types.split(',').map(t=>'.'+t.trim()).join(','))}" style="display:none">
                <button class="btn btn-secondary" onclick="document.getElementById('pf-file-input').click()">Choose File</button>
                <span id="pf-file-name" style="color:var(--text-muted);font-size:0.875rem">No file chosen</span>
                <p style="font-size:0.75rem;color:var(--text-muted);margin-top:0.5rem">Accepted: ${esc(a.accepted_types)} · Max ${a.max_file_mb} MB</p>
            </div>
        </div>` : '';
    return `
    ${tabs}
    ${textPane}
    ${filePane}
    <div style="margin-top:1rem">
        <button class="btn btn-primary" id="pf-submit-btn">Submit for Review</button>
    </div>`;
}

function bindFeedbackDetailEvents(a) {
    // Instructor: publish
    document.getElementById('pf-publish-btn')?.addEventListener('click', async () => {
        if (!confirm('Open this assignment for student submissions?')) return;
        try {
            await api.post(`/api/feedback/${a.id}/publish`);
            toast('Assignment opened for submissions!');
            views.feedbackDetail(a.id);
        } catch (e) { toast(e.message); }
    });

    // Instructor: assign
    document.getElementById('pf-assign-btn')?.addEventListener('click', async () => {
        if (!confirm('Assign reviewers now? Unfinished pending assignments will be re-assigned.')) return;
        try {
            const res = await api.post(`/api/feedback/${a.id}/assign`);
            toast(`Assigned ${res.assigned} review${res.assigned !== 1 ? 's' : ''} across submissions!`);
            views.feedbackDetail(a.id);
        } catch (e) { toast(e.message); }
    });

    // Instructor: close
    document.getElementById('pf-close-btn')?.addEventListener('click', async () => {
        if (!confirm('Close this assignment and release feedback to students?')) return;
        try {
            await api.post(`/api/feedback/${a.id}/close`);
            toast('Assignment closed. Feedback is now visible to students.');
            views.feedbackDetail(a.id);
        } catch (e) { toast(e.message); }
    });

    // Student: submit form tab switching
    document.querySelectorAll('.pf-submit-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.pf-submit-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            const which = tab.dataset.tab;
            document.querySelectorAll('.pf-submit-pane').forEach(p => {
                p.style.display = p.dataset.pane === which ? '' : 'none';
            });
        });
    });

    // Student: file input label
    document.getElementById('pf-file-input')?.addEventListener('change', e => {
        const f = e.target.files[0];
        document.getElementById('pf-file-name').textContent = f ? f.name : 'No file chosen';
    });

    // Student: submit button
    document.getElementById('pf-submit-btn')?.addEventListener('click', async () => {
        const activeTab = document.querySelector('.pf-submit-tab.active')?.dataset.tab
                       || (a.allow_text ? 'text' : 'file');

        if (activeTab === 'text') {
            const text = document.getElementById('pf-text-input').value.trim();
            if (!text) { toast('Please write something before submitting'); return; }
            try {
                await api.post(`/api/feedback/${a.id}/submit`, { text_content: text });
                toast('Submitted! Your work is in for peer review.');
                views.feedbackDetail(a.id);
            } catch (e) { toast(e.message); }
        } else {
            const file = document.getElementById('pf-file-input')?.files[0];
            if (!file) { toast('Please choose a file to upload'); return; }
            try {
                const fd = new FormData();
                fd.append('file', file);
                const res = await fetch(`${window.APP_CONFIG?.baseUrl ?? ''}/api/feedback/${a.id}/submit`, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                });
                if (!res.ok) {
                    const err = await res.json();
                    throw new Error(err.error || `HTTP ${res.status}`);
                }
                toast('File submitted for peer review!');
                views.feedbackDetail(a.id);
            } catch (e) { toast(e.message); }
        }
    });
}

window.pfWithdrawSubmission = async (aId) => {
    if (!confirm('Withdraw your submission? This cannot be undone.')) return;
    try {
        await api.del(`/api/feedback/${aId}/submit`);
        toast('Submission withdrawn');
        views.feedbackDetail(aId);
    } catch (e) { toast(e.message); }
};

window.pfEditAssignment = async (aId) => {
    toast('Edit — reopen the assignment by clicking "Open for Submissions" after reviewing settings.');
};

window.pfDeleteAssignment = async (aId) => {
    if (!confirm('Permanently delete this assignment, all submissions, and all feedback?')) return;
    try {
        await api.del(`/api/feedback/${aId}`);
        toast('Assignment deleted');
        router.navigate('/feedback');
    } catch (e) { toast(e.message); }
};

window.pfShowSubmitForm = (aId, sub) => {
    const area = document.getElementById('pf-submit-form-area');
    if (!area) return;
    const a = { id: aId, allow_text: 1, allow_files: !!sub.file_name, accepted_types: '', max_file_mb: 10 };
    const text = typeof sub === 'object' ? (sub.text_content || '') : '';
    area.innerHTML = renderSubmitForm(a, text);
    // Re-bind submit
    document.getElementById('pf-submit-btn')?.addEventListener('click', async () => {
        const t = document.getElementById('pf-text-input')?.value.trim();
        if (!t) { toast('Please write something'); return; }
        try {
            await api.put(`/api/feedback/${aId}/submit`, { text_content: t });
            toast('Submission updated!');
            views.feedbackDetail(aId);
        } catch (e) { toast(e.message); }
    });
};

// ── Review View ──────────────────────────────────────────────────

function renderReviewView(assignmentId, data) {
    const { submission, prompts, response, completed, asgn_status } = data;
    const readonly = completed && asgn_status === 'closed';

    const submissionHtml = submission.text_content
        ? `<div class="pf-submission-text">${renderMarkdown(submission.text_content)}</div>`
        : (submission.file_name
            ? `<div class="pf-file-download">
                   <span>📎 ${esc(submission.file_name)}</span>
                   <a href="${window.APP_CONFIG?.baseUrl ?? ''}/api/file/${submission.id}" download="${esc(submission.file_name)}" class="btn btn-secondary btn-sm">Download</a>
               </div>`
            : '<p class="pf-empty">No content.</p>');

    const promptFields = (prompts || []).map((p, i) => `
    <div class="pf-prompt-field">
        <label class="pf-prompt-field-label">${i + 1}. ${esc(p.label)}${p.description ? `<span class="pf-prompt-hint"> — ${esc(p.description)}</span>` : ''}</label>
        <textarea class="textarea pf-answer-input" data-prompt="${i}" rows="4" placeholder="Your feedback…" ${readonly ? 'readonly' : ''}>${esc((response?.answers || [])[i] || '')}</textarea>
    </div>`).join('');

    const overallField = `
    <div class="pf-prompt-field">
        <label class="pf-prompt-field-label">Overall Comment <span class="pf-prompt-hint">(optional)</span></label>
        <textarea class="textarea pf-overall-input" rows="4" placeholder="Any overall thoughts…" ${readonly ? 'readonly' : ''}>${esc(response?.overall_comment || '')}</textarea>
    </div>`;

    const submitBtn = !readonly
        ? `<button class="btn btn-primary" id="pf-review-submit">Submit Feedback</button>`
        : `<p class="pf-done-badge" style="font-size:1rem;padding:0.5rem 1rem">✓ Review submitted</p>`;

    return `
    <div class="pf-review-page">
        <div class="pf-detail-header">
            <button class="btn btn-ghost btn-sm" onclick="router.navigate('/feedback/${assignmentId}')">← Back to Assignment</button>
            <h1 class="page-title" style="margin-top:0.75rem">Give Feedback</h1>
            <p class="page-subtitle">Your identity is anonymous to the author.</p>
        </div>

        <div class="pf-review-layout">
            <div class="pf-review-submission">
                <h3 class="pf-section-title">Submission to Review</h3>
                ${submissionHtml}
            </div>
            <div class="pf-review-form">
                <h3 class="pf-section-title">Your Feedback</h3>
                ${promptFields}
                ${overallField}
                <div style="margin-top:1.5rem">${submitBtn}</div>
            </div>
        </div>
    </div>`;
}

function bindReviewEvents(assignmentId, raId, data) {
    document.getElementById('pf-review-submit')?.addEventListener('click', async () => {
        const answers = [...document.querySelectorAll('.pf-answer-input')]
            .map(el => el.value.trim());
        const overall = document.querySelector('.pf-overall-input')?.value.trim() || '';

        try {
            await api.post(`/api/reviews/${raId}`, { answers, overall_comment: overall });
            toast('Feedback submitted!');
            router.navigate(`/feedback/${assignmentId}`);
        } catch (e) { toast(e.message); }
    });
}

// Make key functions global for inline handlers
window.openCompose      = openCompose;
window.openModal        = openModal;
window.views            = views;
window.router           = router;
window.selectType       = selectType;
window.selectSpace      = selectSpace;
window.submitPost       = submitPost;
window.addPollOption    = addPollOption;
window.removePollOption = removePollOption;
window.submitNewBoard   = submitNewBoard;
window.submitCard       = submitCard;

// ── Start ─────────────────────────────────────────────────────────
init();
