# Course Community

A self-hosted, open-source course community platform that integrates with Brightspace (and other LTI 1.3-compatible LMSs). Goes beyond a discussion board — it's a full community environment for communication, collaboration, co-creation, peer recognition, and structured peer feedback.

## Features

| Feature | Description |
|---|---|
| 💬 Threaded Discussions | Nested replies, markdown, reactions, instructor notes |
| ❓ Q&A Board | Upvoting, accepted answers, unresolved question tracking |
| 🧩 Collaboration Boards | Drag-and-drop sticky notes for visual thinking |
| 📚 Resource Library | Community-curated links with previews |
| 🎉 Kudos | Peer recognition posts |
| 🪞 Reflections | Post type for metacognitive writing |
| 📊 Polls | Anonymous check-ins with live results |
| 📊 Community Pulse | Instructor analytics: engagement, contributors, silent students |
| 🔔 Notifications | Real-time reply and mention alerts |
| 🔁 Peer Feedback | Full anonymous peer review workflow with file uploads |
| 🔒 Admin Panel | Course management, backup & restore, file cleanup |

---

## Quick Start (Dev Mode)

```bash
git clone <repo>
cd course-community

# Set dev mode (bypasses LTI auth, creates sample data)
export DEV_MODE=true
export APP_URL=http://localhost:8080

php -S localhost:8080

# Open in browser:
# http://localhost:8080/lti.php?action=dev   ← logs in as Dev Instructor
# http://localhost:8080/                     ← public landing page when unauthenticated
```

Dev mode creates a sample instructor and student, seeds 5 posts across spaces.

---

## Requirements

- PHP 8.1+ with extensions: `pdo_sqlite`, `openssl`, `json`, `mbstring`, `zip` (for backup/restore)
- SQLite 3 (bundled with PHP)
- HTTPS required for production LTI use
- Apache with `mod_rewrite` enabled, or Nginx

---

## Installation

1. Copy all files to your web server's document root (or a subdirectory)
2. Make `data/` writable: `chmod 755 data/`
3. Configure `config.php` or set environment variables (see below)
4. Register the tool in your LMS (see LTI Setup)
5. Set an admin password in `config.php` or via `ADMIN_PASSWORD` env var
6. Visit `/admin.php` to access the admin panel

---

## LTI 1.3 Setup (Brightspace)

Brightspace requires four steps: **Register → Deploy → Create a Link → Add to course.**
Replace `https://your-server.com` with your actual tool URL throughout.

---

### Step 1 — Register the tool

In Brightspace: **Admin Tools → External Learning Tools → Register a Tool**

| Field | Value |
|---|---|
| **Domain** | `your-server.com` |
| **Redirect URLs** | `https://your-server.com/lti.php?action=launch` |
| **OpenID Connect Login URL** | `https://your-server.com/lti.php?action=login` |
| **Target Link URI** | `https://your-server.com/` |
| **Keyset URL** | *(leave blank — tool doesn't initiate back-channel requests)* |

After saving, Brightspace shows you a **Client ID**, an **auth endpoint**, and a **JWKS URI**. Copy all three — you'll need them for `config.php`.

---

### Step 2 — Update config.php

```php
$LTI_PLATFORMS = [
    'https://your.brightspace.com' => [          // issuer — your Brightspace hostname
        'client_id'     => 'PASTE_CLIENT_ID',    // from Step 1
        'auth_endpoint' => 'https://your.brightspace.com/d2l/lti/authenticate',
        'jwks_uri'      => 'https://your.brightspace.com/d2l/.well-known/jwks',
    ],
];
```

The `auth_endpoint` and `jwks_uri` patterns are always the same — just substitute your institution's Brightspace hostname.

---

### Step 3 — Deploy the tool

In Brightspace: **Admin Tools → External Learning Tools → find Course Community → Deploy**

**Security Settings** — check the following:

| Checkbox | Share? |
|---|---|
| Name (First Name + Last Name) | ✅ Yes |
| Email | ✅ Yes |
| Middle Name, User ID, Username, Org Defined Id | ☐ No |
| Classlist including users not known to this deployment | ☐ No |

**Configuration Settings** — leave all unchecked (no grade passback, no external resource mode).

**Substitution Parameters / Custom Parameters** — leave both empty.

Set the deployment scope to **org-wide** (or to the specific org units that need it) so the tool is visible in courses.

---

### Step 4 — Create a Link

Without a Link, the tool won't appear in the course activity picker.

In Brightspace: open the deployment you just created → find the **Links** tab → add a new link:

| Field | Value |
|---|---|
| **Title** | Course Community |
| **URL** | `https://your-server.com/` |
| **Type** | Basic Launch |

---

### Step 5 — Add to a course

1. Inside your Brightspace course, go to **Content**
2. Choose **Add Existing Activities → External Learning Tools**
3. Find **Course Community** in the list and select it

That creates the launch link. When anyone clicks it, Brightspace runs the LTI 1.3 OIDC flow and they arrive in Course Community already authenticated with their name, email, and role.

> **First launch tip:** Have an instructor open the tool first. Instructors get course-management permissions (pinning posts, creating peer feedback assignments, etc.) that students don't.

---

### Single platform (config.php)

```php
$LTI_PLATFORMS = [
    'https://your.brightspace.com' => [
        'client_id'     => 'your-client-id-from-brightspace',
        'auth_endpoint' => 'https://your.brightspace.com/d2l/lti/authenticate',
        'jwks_uri'      => 'https://your.brightspace.com/d2l/.well-known/jwks',
    ],
];
```

### Multiple platforms

You can register any number of LMS instances. Each entry is either a plain issuer URL (one registration per LMS) or a compound `"issuer::client_id"` key (multiple registrations per LMS):

```php
$LTI_PLATFORMS = [
    // One registration from one Brightspace instance
    'https://uni-a.brightspace.com' => [
        'client_id'     => 'abc123',
        'auth_endpoint' => 'https://uni-a.brightspace.com/d2l/lti/authenticate',
        'jwks_uri'      => 'https://uni-a.brightspace.com/d2l/.well-known/jwks',
    ],

    // Second institution
    'https://uni-b.brightspace.com' => [
        'client_id'     => 'xyz789',
        'auth_endpoint' => 'https://uni-b.brightspace.com/d2l/lti/authenticate',
        'jwks_uri'      => 'https://uni-b.brightspace.com/d2l/.well-known/jwks',
    ],

    // Two separate registrations from the same LMS (use compound key)
    'https://uni-c.brightspace.com::prod-client' => [
        'client_id'     => 'prod-client',
        'auth_endpoint' => 'https://uni-c.brightspace.com/d2l/lti/authenticate',
        'jwks_uri'      => 'https://uni-c.brightspace.com/d2l/.well-known/jwks',
    ],
    'https://uni-c.brightspace.com::test-client' => [
        'client_id'     => 'test-client',
        'auth_endpoint' => 'https://uni-c.brightspace.com/d2l/lti/authenticate',
        'jwks_uri'      => 'https://uni-c.brightspace.com/d2l/.well-known/jwks',
    ],
];
```

### Environment variables

Instead of editing `config.php`, set these environment variables (useful for containers/CI):

```
APP_URL=https://your-server.com
LTI_ISSUER=https://your.brightspace.com
LTI_CLIENT_ID=12345678
LTI_AUTH_ENDPOINT=https://your.brightspace.com/d2l/lti/authenticate
LTI_JWKS_URI=https://your.brightspace.com/d2l/.well-known/jwks
ADMIN_PASSWORD=your-secure-admin-password
```

---

## Troubleshooting

### Diagnostic tool

Upload `status.php` and visit it in your browser before attempting a launch. It checks PHP version, required extensions, `data/` writability, `APP_URL` configuration, SQLite access, and HTTPS. Delete it once you've resolved any issues — it exposes server details that should not be public.

---

### Server errors (PHP log)

These appear in your PHP error log tagged `[LTI]` or `[API]`. Set `CC_DEBUG=true` as an environment variable to show the real message in the browser instead of the generic error page.

| Log message | Cause | Fix |
|---|---|---|
| `unknown function: unixepoch()` | SQLite < 3.38.0 on server; old database schema stored with `unixepoch()` defaults | Delete `data/community.sqlite` and re-upload `db.php` — the database will be recreated with compatible `strftime('%s','now')` defaults |
| `Failed to fetch JWKS from …` | `allow_url_fopen` is disabled in `php.ini` | Enable `allow_url_fopen` in `php.ini` or ask your host |
| `Unregistered platform: https://…` | The issuer URL in `$LTI_PLATFORMS` doesn't exactly match what Brightspace sends | Copy the exact issuer from the Brightspace registration page into `config.php` |
| `JWT aud mismatch` | `client_id` in config doesn't match the token | Double-check the Client ID copied from Brightspace Step 1 |
| `Invalid or expired state` | The OIDC state cookie expired or was lost | Usually caused by the browser blocking cross-site cookies in an iframe — try opening the tool in a new tab |
| `near "NULLS": syntax error` | SQLite < 3.30.0 doesn't support `NULLS LAST` | Ensure you have the latest `api.php` |
| `SQLSTATE … General error: 1 …` | Database not writable, or wrong PHP/SQLite version | Run `status.php` to identify the specific issue |

---

### Brightspace launch errors

These appear as error pages shown by Brightspace or the browser during the login flow.

| Error | Cause | Fix |
|---|---|---|
| **"Invalid authentication request parameters"** | The `redirect_uri` sent to Brightspace doesn't match the registered Redirect URL — almost always because `APP_URL` is wrong or unset | Set `APP_URL=https://your-server.com/path` as an environment variable or in `config.php`; the value must match the subdirectory the app is deployed in |
| **500 Internal Server Error** on `lti.php?action=login` | PHP fatal error before any output — common causes: `data/` not writable, PHP < 8.1, missing `pdo_sqlite` extension | Run `status.php` to identify; set `CC_DEBUG=true` to see the error in the browser |
| **"LTI authentication failed. Please try relaunching."** | An exception was thrown during the launch — see PHP error log for `[LTI]` entry | Set `CC_DEBUG=true` temporarily to see the real message in the browser |
| **Tool not appearing** in Add Existing Activities → External Learning Tools | The Link step (Step 4) was skipped, or the deployment scope doesn't include this course's org unit | Create a Link on the deployment; check the deployment's org unit scope |
| **Blank page / CSS loads but JS errors** | `APP_URL` subdirectory is set but asset paths or API calls are hitting the wrong URL | Ensure `APP_URL` includes the full subdirectory path, e.g. `https://server.com/course-community` not just `https://server.com` |

---

### JavaScript console errors

| Error | Cause | Fix |
|---|---|---|
| `MIME type ("text/html")` blocked for `app.js` or `style.css` | Asset paths don't include the subdirectory — `APP_URL` not set correctly | Set `APP_URL` to the full path including subdirectory |
| `selectSpace is not defined` / `submitPost is not defined` | Function not exported to `window` scope from ES module | Ensure you have the latest `assets/app.js` |
| API calls returning 404 or HTML | `baseUrl` missing from `APP_CONFIG` — API requests go to domain root instead of app subdirectory | Ensure `APP_URL` is set; upload the latest `index.php` and `api.php` |

---

### APP_URL — the most common source of problems

`APP_URL` must be set to the exact base URL of the app with no trailing slash. Every broken redirect, wrong asset path, and Brightspace auth rejection in a subdirectory deployment traces back to this.

**Apache `.htaccess`** (simplest for shared hosting):
```apache
SetEnv APP_URL https://your-server.com/course-community
```

**PHP-FPM / server `php.ini`**:
```ini
env[APP_URL] = https://your-server.com/course-community
```

**Shell / Docker**:
```bash
export APP_URL=https://your-server.com/course-community
```

---

## Course Isolation

Every course context is fully sandboxed:

- Courses are identified by a **composite key of `(issuer, context_id)`** — two different LMS instances with the same `context_id` value never share a course record.
- All content (posts, comments, boards, peer feedback, notifications) is scoped to a `course_id`.
- Session tokens are bound to a `course_id`; users cannot access data from other courses even within the same browser session.
- LTI `client_id` values are validated against the registered platform config — the tool rejects logins that present an unregistered client.

---

## Peer Feedback

Instructors can create structured peer review assignments within any course.

### Workflow

1. **Instructor creates assignment** — sets title, description, custom review prompts, number of reviewers per submission, and whether to accept text and/or file uploads.
2. **Instructor opens assignment** — students can now submit their work (text and/or file).
3. **Instructor triggers assignment** — the tool automatically assigns each submission to reviewers using a load-balancing algorithm (no student reviews their own work; workload is distributed evenly).
4. **Students complete reviews** — each reviewer responds to the prompts and submits feedback.
5. **Instructor closes assignment** — feedback is released to authors.

### Phases

| Phase | Description |
|---|---|
| `draft` | Visible to instructors only; not yet open for submissions |
| `open` | Students can submit their work |
| `reviewing` | Reviewers have been assigned; review forms are active |
| `closed` | Feedback is visible to authors |

### Privacy & Security

- **Bidirectional anonymity**: reviewers cannot see the author's name; authors cannot see who reviewed them. Instructors see all.
- **File access control**: uploaded files are stored outside the web root (`data/uploads/`) and served only through a permission-checked API endpoint. Only the file's author, their assigned reviewers, and instructors can download a file.
- **Load-balanced assignment**: the assignment algorithm distributes review workload as evenly as possible across the cohort.

### Configuration options

| Setting | Default | Description |
|---|---|---|
| `reviewers_per_sub` | 2 | How many students review each submission |
| `allow_text` | true | Accept inline text submissions |
| `allow_files` | false | Accept file uploads |
| `accepted_types` | `pdf,doc,docx` | Allowed file extensions (comma-separated) |
| `max_file_mb` | 10 | Maximum file size in megabytes |
| `prompts_json` | `[]` | Array of review prompt strings shown to reviewers |

---

## Admin Panel

Access the admin panel at `/admin.php`. Login uses the `ADMIN_PASSWORD` set in `config.php` or the environment.

### Features

- **Dashboard** — overview of all courses across all LMS platforms, active sessions, database size, upload storage used
- **Delete course** — permanently removes a course and all its data (posts, comments, boards, peer feedback, uploaded files, notifications, enrollments, sessions)
- **Backup** — downloads a `.zip` archive containing the SQLite database and all peer feedback uploads
- **Restore** — uploads a backup `.zip` to replace the current database and uploads

> **Note**: Restore replaces the live database. Take a fresh backup before restoring. The PHP `zip` extension must be installed for backup/restore to work.

To set the admin password, add this to `config.php` or set the `ADMIN_PASSWORD` environment variable:

```php
define('ADMIN_PASSWORD', 'your-secure-password-here');
```

If `ADMIN_PASSWORD` is empty or not set, the admin panel is disabled.

---

## Design Principles

Built around D'Arcy Norman's five-dimensional course design framework (*The Teaching Game*):

1. **Player** — Multiple contribution modes: post, reply, curate, recognize, reflect, submit, review
2. **Performance** — Votes, reactions, accepted answers, and structured rubrics make quality visible
3. **Narrative** — The feed tells the story of the community over time; peer feedback captures the arc of learning
4. **Environment** — Purposeful spaces (discussion, Q&A, boards, peer review) with distinct social norms
5. **System** — Transparent roles, moderation tools, LTI-grounded isolation, admin oversight

---

## File Structure

```
course-community/
├── config.php          ← LTI credentials, admin password, app settings
├── db.php              ← Database layer (SQLite schema + helpers + migrations)
├── lti.php             ← LTI 1.3 OIDC authentication handler
├── api.php             ← JSON REST API (all endpoints)
├── index.php           ← App shell / SPA (serves landing page to unauthenticated visitors)
├── landing.php         ← Public info/integration guide (included by index.php)
├── admin.php           ← Admin panel (protected by ADMIN_PASSWORD)
├── thumbnail.png       ← 800×800 app thumbnail
├── .htaccess           ← Apache rewrite rules + security headers
├── assets/
│   ├── style.css       ← "Warm Commons" design system & component styles
│   └── app.js          ← Frontend SPA (vanilla ES2022, no build step)
└── data/               ← Created automatically; must be writable
    ├── .htaccess       ← Blocks direct web access to data/
    ├── community.sqlite← Auto-created SQLite database
    └── uploads/        ← Peer feedback file uploads (served via API only)
```

---

## Schema Overview

```
courses          — one row per (issuer, context_id) pair
users            — one row per (sub, issuer) pair
enrollments      — user ↔ course membership + role
spaces           — discussion channels within a course
posts            — content items (discussion, Q&A, kudos, reflection, poll, resource)
comments         — threaded replies on posts
reactions        — emoji reactions on posts/comments
votes            — upvotes on posts/comments
tags / post_tags — tagging system
boards           — collaboration boards
board_cards      — sticky notes on boards
poll_votes       — anonymous poll responses
notifications    — per-user, per-course alerts
sessions         — authenticated session tokens
lti_states       — OIDC login state (short-lived)
lti_nonces       — replay-attack prevention (short-lived)
pf_assignments   — peer feedback assignments (per course)
pf_submissions   — student work submissions
pf_review_assignments — reviewer-to-submission mapping
pf_responses     — completed review responses
```

---

## License

MIT — use it, adapt it, improve it.
