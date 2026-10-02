# Deseo Radio

Official website and lightweight content-management system for **Deseo Radio** — a Greek online radio station focused on House, Afro House, Deep House and related electronic music.

The project combines a public radio website, live streaming integration, a dynamic DJ schedule, weekly airplay management, Progressive Web App support and a small password-protected PHP administration area.

## What the project does

### Public radio website

The main website is served from `index.php` and provides the listener-facing experience.

Key functionality:

- Embeds the **Deseo Radio live stream/player** through the iRadios widget.
- Displays the DJ/show that is **currently on air**.
- Determines the live DJ automatically from the database using the current day and time in the `Europe/Athens` timezone.
- Shows today's complete broadcast schedule.
- Displays the station's ranked **Airplay Top 10** with artwork and Spotify links.
- Presents distribution/network partners such as TuneIn, Online Radio Box, Streema, VRadio and iRadios.
- Includes the station's social links and contact details.
- Uses dependency-light progressive-enhancement animations; content stays visible even when JavaScript or external services are unavailable.
- Includes SEO metadata and Schema.org structured data for the radio station/business.

### Live DJ / programme system

The `program` database table controls which DJ is displayed on the website.

Each programme entry contains:

- DJ / show name
- DJ image
- day of week
- start time
- end time

On every request, the public homepage:

1. Uses the `Europe/Athens` timezone.
2. Finds the current weekday.
3. Loads that day's programme from MySQL.
4. Compares the current time with each programme slot.
5. Marks the matching entry as the current **Live Broadcast**.

If no programme entry matches the current time, the site falls back to the station's non-stop/Auto DJ state.

### Airplay Top 10

The administration area allows the station team to maintain positions `1–10` of the Airplay chart.

An administrator enters a Spotify track URL and selects the desired chart position. The CMS calls Spotify's public oEmbed endpoint to retrieve track metadata and artwork, then saves the result in MySQL.

If a track already exists in the selected position, it is replaced automatically.

Stored data includes:

- Spotify URL
- track title
- artist field
- artwork URL
- chart position

The public website reads the ranking from the database and links each entry back to Spotify.

## Administration area

The lightweight CMS lives under:

```text
/iluma/
```

Entry point:

```text
/iluma/index.php
```

The CMS uses PHP sessions and the `ADMIN_PASSWORD` environment variable for access.

### Dashboard

The dashboard provides navigation to:

- Airplay Top 10 management
- Radio programme management
- logout

### Airplay management

`iluma/airplay.php`

Features:

- add/update a Spotify track in positions 1–10
- retrieve Spotify metadata via oEmbed
- replace the track occupying an existing position
- show artwork for all stored tracks
- delete Airplay entries

### Programme management

`iluma/program.php`

Features:

- create DJ/show schedule entries
- assign one entry to multiple weekdays at once
- configure custom start/end times
- configure an all-day entry
- upload DJ images (`jpg`, `jpeg`, `png`, `webp`)
- list the weekly programme in chronological order
- delete programme entries
- safely retain an uploaded image when the same image is still referenced by another schedule entry

Uploaded DJ images are stored in:

```text
/iluma/uploads/
```

## Progressive Web App

Deseo Radio can be installed as a Progressive Web App on supported browsers/devices.

Relevant files:

- `manifest.json`
- `sw.js`
- `webpushr-sw.js`

The service worker caches the main application shell and selected front-end dependencies. Requests related to the streaming provider and Webpushr are deliberately excluded from normal cache handling.

The site also listens for the browser `beforeinstallprompt` event and displays a custom **Install App** button when installation is available.

## Push notifications

The front end integrates **Webpushr** for browser push notifications.

Webpushr is loaded from its CDN and uses `webpushr-sw.js` as its service worker integration.

## Cookie consent

`includes/cookiebanner.php` provides the cookie/privacy preference interface.

The current implementation stores consent preferences in `localStorage` and uses Google Consent Mode-style values for:

- analytics storage
- ad storage
- ad user data
- ad personalization

Visitors can:

- accept all
- reject optional categories
- customize analytics and marketing preferences

## Technology stack

### Backend

- PHP
- PDO
- MySQL / MariaDB
- PHP sessions
- PHP cURL

### Frontend

- Semantic HTML5
- Local handcrafted responsive CSS (`assets/css/style.css`)
- Dependency-light JavaScript with progressive enhancement
- Local CMS stylesheet (`iluma/admin.css`)
- Accessible fallbacks for missing images, missing database data and older browsers

### External integrations

- iRadios — live player widget
- Spotify oEmbed — Airplay metadata and artwork
- Webpushr — browser push notifications
- partner radio directories/platforms

## Project structure

```text
.
├── index.php                   # Public Deseo Radio homepage
├── manifest.json               # PWA manifest
├── sw.js                       # Main service worker
├── webpushr-sw.js              # Webpushr service worker
├── .env.example                # Environment configuration template
├── assets/
│   ├── css/
│   │   └── style.css           # Public responsive design system
│   └── img/                    # Branding, partners and visual assets
├── includes/
│   ├── head-meta.php           # Meta tags, SEO, PWA registration
│   ├── header.php              # Public header/navigation
│   ├── footer.php              # Footer, PWA prompt and scripts
│   └── cookiebanner.php        # Cookie/consent UI
└── iluma/
    ├── index.php               # CMS login/dashboard
    ├── admin-ui.php            # Shared responsive CMS shell
    ├── admin.css               # Local CMS design system
    ├── db.php                  # CMS session/auth + table initialization
    ├── connection.php          # Environment loading + PDO connection
    ├── airplay.php             # Airplay Top 10 administration
    ├── program.php             # DJ schedule administration
    └── uploads/                # Uploaded DJ images
```

## Database

The CMS initializes the required tables automatically if they do not already exist.

### `airplay`

```sql
CREATE TABLE airplay (
    id INT AUTO_INCREMENT PRIMARY KEY,
    spotify_url VARCHAR(255),
    track_name VARCHAR(255),
    artist_name VARCHAR(255),
    artwork_url TEXT,
    position INT DEFAULT 0
);
```

### `program`

```sql
CREATE TABLE program (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dj_name VARCHAR(255),
    photo_path VARCHAR(255),
    day_of_week INT,
    start_time TIME,
    end_time TIME
);
```

Weekdays use ISO-style numbering:

| Value | Day |
|---:|---|
| 1 | Monday |
| 2 | Tuesday |
| 3 | Wednesday |
| 4 | Thursday |
| 5 | Friday |
| 6 | Saturday |
| 7 | Sunday |

## Environment configuration

Production credentials are intentionally **not committed to Git**.

Copy the supplied template:

```bash
cp .env.example .env
```

Then configure:

```dotenv
DB_HOST=localhost
DB_NAME=your_database_name
DB_USER=your_database_user
DB_PASS=your_database_password
ADMIN_PASSWORD=change_this_admin_password
```

`iluma/connection.php` loads these values and creates the PDO connection using `utf8mb4` and exception-based error handling.

The `.env` file is excluded through `.gitignore`.

## Server requirements

Recommended production environment:

- PHP 8.x
- MySQL 5.7+ or MariaDB equivalent
- PDO MySQL extension
- cURL extension
- PHP sessions enabled
- file uploads enabled
- writable `iluma/uploads/` directory
- HTTPS

The application does not require Node.js to run in production because the compiled stylesheet is already included at:

```text
assets/css/style.css
```

## Front-end delivery and cache strategy

The public site no longer depends on Tailwind, GSAP or Font Awesome at runtime. The main stylesheet is committed directly as `assets/css/style.css`.

Dynamic PHP pages are served with revalidation headers, while CSS uses a deployment-aware version query generated from file modification times. The service worker follows a network-first strategy for navigations, CSS and JavaScript, and removes old Deseo caches on activation. This prevents visitors from being stuck on an outdated layout after a Hostinger redeploy.

If JavaScript, push notifications, the database or a remote image temporarily fails, the site renders an explicit fallback state rather than hiding content.

## Hostinger deployment

A typical Hostinger deployment can use the repository contents directly as the site's `public_html` application.

Deployment checklist:

1. Upload/pull the repository contents into the website document root.
2. Create the production MySQL/MariaDB database and database user.
3. Create `.env` from `.env.example`.
4. Add the production database credentials.
5. Set a strong `ADMIN_PASSWORD`.
6. Ensure `iluma/uploads/` is writable by PHP.
7. Confirm PHP has PDO MySQL and cURL enabled.
8. Open `/iluma/` once so the application can initialize its database tables.
9. Configure the Airplay chart and weekly DJ programme from the CMS.
10. Verify the public player, current DJ, schedule and PWA/service-worker behaviour over HTTPS.

## Security

The source archive used for the first import contained plaintext database/admin credentials. Those values were removed before preparing this repository.

Before the next production deployment:

- rotate the previous database password
- rotate the previous CMS/admin password
- never commit `.env`
- use a strong unique `ADMIN_PASSWORD`
- keep the CMS and hosting account protected with strong credentials
- restrict production database permissions to only those required by this application

The repository should be treated as source code only; live secrets belong in the Hostinger environment/server configuration.

## Current implementation notes

The major redesign and stability pass is now integrated into `main`.

Key production safeguards include:

- responsive public site and responsive CMS
- correct overnight-show detection and next-show fallback
- explicit states when programme/Airplay data is unavailable
- automatic image fallbacks
- update-first service worker and cache busting
- offline fallback page
- CSRF protection and hardened CMS sessions
- validated Spotify requests and bounded network timeouts
- MIME/size validation for DJ uploads
- persistent production `.env` support outside `public_html`
- GitHub Actions quality checks for PHP/JavaScript syntax and critical files

## Brand / station information

**Deseo Radio**  
*The Soundtrack of your Life!*

Public website domain referenced by the application:

```text
https://deseoradio.com
```

The station is distributed through multiple online-radio platforms and is part of the ILUMA radio ecosystem.

---

This repository is the development base for the next major version of the Deseo Radio website and administration system.

## Persistent production credentials on Hostinger

For Hostinger Git deployments, keep the production `.env` **outside the deployment target** so a redeploy of `public_html` cannot overwrite or remove it.

Recommended layout:

```text
domains/your-domain/
├── .env                 # production secrets, persistent across Git redeploys
└── public_html/         # Git deployment target
    └── ... application files
```

The application searches for configuration in this order:

1. path specified by `DESEO_ENV_FILE`, when set
2. `.env` one directory above the project root (recommended for Hostinger)
3. `public_html/.env` as a legacy fallback
4. `$HOME/.deseo-radio.env` as an additional fallback

After confirming the parent-level `.env` works, remove the copy inside `public_html`. This keeps production credentials outside the web root and outside the Git deployment directory.


## MyLive App PWA & Email Automation

The private `/mylive/` DJ portal is an independent installable PWA named **MyLive App**.

### PWA files

- `/mylive/manifest.json` — dedicated manifest with `/mylive/` scope.
- `/mylive-sw.js` — dedicated MyLive service worker, registered with `/mylive/` scope.
- `/mylive/offline.html` — offline fallback that never caches private DJ dashboard HTML.
- `/mylive/app.js` — install UI plus the lightweight email-automation tick while MyLive is open.

### Automated DJ emails

The reminder engine lives in `/includes/mylive-email-reminders.php` and uses the real `program.mylive_account_id` mapping.

It sends two branded emails:

1. **DJ Set due** — on the calendar day three days before the next Program slot, only when there is no non-broadcasted DJ Set for that account. Once an episode becomes `broadcasted`, the next weekly occurrence expects the next episode and can trigger a new reminder.
2. **On Air / Social** — once during the actual live Program slot. The DJ is reminded to publish the official creative on social media and share `https://deseoradio.com`.

Every event is deduplicated in `dj_email_automation_log`, keyed by Program slot, broadcast date and episode where relevant.

### No cron

There is no cron job for this automation.

The scheduler is checked:
- during normal Deseo homepage traffic,
- during the DJ Call page traffic,
- when MyLive is used,
- and through `/mylive/email-tick.php` every 60 seconds while a public Deseo page or MyLive remains open.

The database throttle prevents repeated work and duplicate emails.

Because there is no always-on background process, the On Air email is sent on the first scheduler tick/request that occurs inside the live slot. With active site traffic this is normally close to the start time, but an exact minute cannot be guaranteed if the site receives no requests during the slot.


### HearThis Season 6 post-broadcast sync (protected rollout)

The CLI worker `iluma/cron-mylive-hearthis.php` runs every minute under **Europe/Athens**. The app now records `scheduled_show_end` when a set moves to `scheduled`, using the actual MyLive account `day_of_week`, `start_time`, `end_time` (NOT the generic music-zone Program) and the Season 6 boundary (14 Oct 2026). It reserves separate future time slots for multiple queued episodes. After the planned show ends, the worker marks the episode `broadcasted` and attempts its archive upload **only if the HearThis upload transport has been explicitly enabled**. One-off/guest episodes may be given an explicit **SHOW ENDS · ATHENS** date/time in either CMS episode editor when marked scheduled (enter the actual next-day date for slots crossing midnight); they do not need a recurring resident Program mapping. Guests must supply an explicit end date; residents without a valid MyLive slot or explicit end date cannot be scheduled.

**Revised local cleanup contract (2026-10-02):** after the scheduled broadcast ends, the worker uploads the MP3 once. An unambiguous successful HTTP response (`files[0]` without error, positive Track ID and owned HearThis permalink) is persisted with the exact title, source SHA-256 computed BEFORE upload, and acceptance timestamp. Immediately afterwards only that SHA-matched MP3 is unlinked from private Hostinger MyLive storage; no Season 6 set or podcast RSS wait. Uncertain HTTP outcomes, missing Track ID/permalink, and a changed source never authorize deletion. Legacy/page-load cleanup uses those SAME acceptance+SHA gates to recover a crash after the response. The worker keeps verifying playlist membership, public stream and podcast RSS even when `file_deleted_at` is already populated; `SYNCED` remains an archive/podcast completion state, NOT a deletion gate. Existing already-deleted historical audio cannot be reconstructed.

The HearThis [official API documentation](https://hearthis.at/api) documents the Premium write operation `POST https://xhr.hearthis.at/upload_api.php` and authentication with `key` and `secret` as **HTTPS multipart POST fields**. The transport now implements that exact endpoint (blank `HEARTHIS_UPLOAD_ENDPOINT` uses the official value; any non-empty override must equal that URL). Blank `HEARTHIS_UPLOAD_AUTH_MODE` defaults to `post`; `post` is the only supported mode. Its multipart request includes `key`, `secret`, `file`, `title`, `private=0`, `description`, `genre`, and `tags`, plus optional JPG/PNG image in the documented **`image`** field (not `artwork`). It reads `files[0]`, checks the per-file `error`, captures `id`, `full.permalink_url` and an optional `meta_error`. Keys and secrets are never committed, sent to a public GET endpoint, or put in a URL. **Do not turn on `HEARTHIS_UPLOAD_ENABLED` until a separate staging track has proved the endpoint, published metadata, artwork fallback and public stream checks using rotated production credentials.**

Configure one minute CLI cron via the hosting dashboard (replace with the real PHP executable and deployment path):

```sh
* * * * * /usr/bin/php /absolute/path/to/public_html/iluma/cron-mylive-hearthis.php
```

The worker uses an advisory database lock and does not double-post concurrently. Unknown network results or missing/unrecognized owner URLs become `review_required`, retain the MP3 and require manual reconciliation before any new attempt. A successful upload response is recorded as `verifying` with Track ID, owned URL, exact title, source SHA-256 and `hearthis_upload_accepted_at`; local cleanup follows immediately. Later cron ticks independently check public playback, membership in existing Season 6, and the exact RSS item/enclosure, even after original audio has been deleted. Missing RSS for an hour does NOT postpone local cleanup. The editor locks uploaded/verifying episodes against status regression. Test the production MariaDB migration on a staging copy before merging; the five already scheduled production sets must not be used for testing.



#### Isolated private upload smoke test (not the production worker)

For the Hostinger layout below, the uploaded test audio is intentionally **outside** `public_html`:

```text
domains/deseoradio.com/
├── .env
├── deseo-uploads/
│   └── Deseo Radio - Season 6.mp3
└── public_html/
    └── iluma/
        └── cli-hearthis-private-test.php
```

After deploying the *single* CLI test script from this branch to its `public_html/iluma/` location, rotating the API key/secret previously pasted in chat, and setting `HEARTHIS_UPLOAD_ENABLED=0`, execute through Hostinger SSH/Terminal (replace `/path/to/` with the absolute account path):

```sh
php /path/to/domains/deseoradio.com/public_html/iluma/cli-hearthis-private-test.php --check
php /path/to/domains/deseoradio.com/public_html/iluma/cli-hearthis-private-test.php --upload-private
```

`--check` only checks file header/type/size/path, presence of credentials and any prior test receipt; it never contacts the API. The second command requires explicit invocation and makes **one** Premium API call with `private=1`, without custom image; it does not connect to MyLive tables, install a cron, turn on the worker or delete audio. An attempt marker (`deseo-uploads/.hearthis-private-test-receipt.json`) is saved *before* sending the HTTP request, so interrupted/ambiguous outcomes cannot automatically duplicate the track. A successful response prints only the returned track ID and stores a minimal receipt (no credentials or raw response). The private test was subsequently run by the operator and returned a successful acceptance with a track ID; the operator reports that private track has now been deleted remotely. The PRIVATE receipt MUST remain intact as a historical duplicate-prevention record. A private upload alone does **not** establish public playback or production retention safety. Use the separate public-release CLI below, not a repeat of the private test. The exact user-supplied test audio must be owned or licensed appropriately.

#### Official public Season 6 release and playlist-gated DJ archiving

The station's existing Season 6 playlist is the required archive for **all** Season 6 DJ Sets. The operator's public account API returned the exact entry `title="Season 6"`, `id="561432"`, `permalink="561432-10808078"`, and canonical URL `https://hearthis.at/set/561432-10808078/`. The human-facing `https://hearthis.at/deseoradio/set/season-6/` must NOT be confused with the API playlist permalink; the old `GET /set/season-6/` returned an empty list. The resolver now dynamically matches the UNIQUE "Season 6" title with a numeric ID/prefix and exact canonical URL, and fetches tracks from `GET https://api-v2.hearthis.at/set/{actual_numeric_permalink}/`. It accepts an empty list only when the account playlist's `track_count` confirms zero tracks, and fails closed on missing/inconsistent/paginated collection responses. A separate, database-independent CLI releases **only** `domains/deseoradio.com/deseo-uploads/DeseoRadio - Season 6 Spot.mp3` publicly under the exact title **`DeseoRadio - Season 6 Spot`**. This is a DIFFERENT MP3 from the original private test (`Deseo Radio - Season 6.mp3`), so it neither requires a matching private-test receipt nor reads/modifies it. The user reports deleting the earlier private test on HearThis; preserve its old receipt untouched. The Spot receives an independent `.hearthis-season6-spot-release-receipt.json`.

Deploy `includes/hearthis-season6.php`, **`includes/hearthis-podcast.php`** and `iluma/cli-hearthis-season6-release.php` from the SAME feature-branch commit into matching `public_html` paths for this standalone test. Keep `HEARTHIS_UPLOAD_ENABLED=0`. No MyLive database, cron or merge is required to run these CLI commands:

```sh
cd ~/domains/deseoradio.com/public_html
php iluma/cli-hearthis-season6-release.php --check
# Only if the check resolves the UNIQUE existing Season 6 set ID:
php iluma/cli-hearthis-season6-release.php --publish-public
# After accepted upload, and later if the provider is still processing:
php iluma/cli-hearthis-season6-release.php --finalize
```

`--check` makes only public read API requests and validates the exact Spot source when present, credentials and the canonical Season 6 set. It reports RSS availability but RSS availability is **not** an upload/deletion gate. `--publish-public` records a Spot-specific receipt with original SHA-256 BEFORE its single HTTP POST. On a clear success (HTTP 2xx, valid response row, no upload error, positive Track ID and exact owned permalink), the SAME invocation saves the accepted receipt and deletes only the SHA-matched Spot MP3 immediately, then attempts Season 6 playlist association ONCE. Uncertain response or unavailable owned permalink retains the audio and prohibits blind retries. The already accepted Track ID `14703494` must NEVER be reuploaded: update the scripts and use `--finalize` to apply the revised local cleanup to that existing receipt. Both commands reconcile the existing Season 6 set via public read, with at most one documented set-add request protected by a pre-POST attempt marker. Future `--finalize` runs are read-only after any uncertain addition. Public playback and exact item in `https://hearthis.at/deseoradio/podcast.xml` are checked and reported separately, but neither delays the MP3 unlink. An RSS propagation delay only leaves `podcast_state=pending` in the receipt. The provider-set membership must be checked even when audio was already cleaned; do not assume POST success equals visible membership. All receipts stay outside `public_html`. The title is exactly `DeseoRadio - Season 6 Spot` and publication is immediate.

Once the full PR passes staging and the independent worker is deliberately enabled, every DJ episode uses the same acceptance-first local cleanup: positive response + owned URL + pre-upload source hash saved durably, then immediate unlink. The Season 6 set is resolved before upload to avoid unassignable releases. The DJ worker then independently confirms public playback and Season 6 membership, posts a SINGLE add request (receipt state before POST) if missing, and separately checks the one station podcast RSS. Only after all remote confirmations is `hearthis_status='synced'` with `hearthis_set_status='confirmed'` and `hearthis_podcast_status='confirmed'`, for reporting rather than local deletion. A missing/invalid RSS or delayed podcast propagation leaves `podcast_pending`, never blocks already-authorized local cleanup. RSS is downstream of HearThis and is not a second audio upload. The original file cannot be used for automatic recovery if remote playlist association later fails, so operators must reconcile remote association without blindly reuploading.

**Verified public CDN host:** A real anonymous API GET for Spot track `14703494` returned HTTP 200, account `deseoradio`, exact title `DeseoRadio - Season 6 Spot`, `private=0`, duration 29 seconds, and HTTPS `stream_url` on **`hearthis.app`**. The former stream probe (Spot and worker) incorrectly restricted hostnames to hearthis.at, so `--finalize` retained the MP3 with a processing/playback-pending result. The shared provider media allowlist now includes the EXACT `hearthis.app` host (and the previously permitted exact HearThis hosts), with tests rejecting lookalike hosts; this change does NOT permit `hearthis.app` as a public episode permalink, does NOT skip TLS/ID/owner/title/stream checks, and does NOT relax identity/playlist/RSS monitoring; only their former role as local deletion gates is removed. Update **all three** standalone files `includes/hearthis-podcast.php`, `includes/hearthis-season6.php` and `iluma/cli-hearthis-season6-release.php` from the same commit. Then rerun `--check` and `--finalize` against the *existing* Spot receipt; do NOT repeat `--publish-public`. This is a code fix awaiting a fresh real-hosting playback/playlist/RSS/cleanup test, not a claim of completed cleanup.

**HearThis dashboard Podcast checkbox limitation:** the supplied Premium API documentation does NOT provide a documented write parameter for **Show Mix inside Podcast / RSS feed**. The code does not invent an undocumented request. The setting may be enabled automatically by the HearThis account/provider; if the exact episode does not appear in the public RSS after normal propagation, inspect/enable the toggle manually. This is a separate archive-monitoring issue and does **not** hold the local MP3 for an hour. To implement automatic toggle changes, obtain a sanitized checkbox-only request endpoint and parameter NAMES (never cookies/API key/secret/values). The PHP RSS checker uses cURL and SimpleXML. Run `php tests/hearthis-podcast.php` to exercise exact matching.

#### Artwork 02 and episode metadata (optional artwork with provider default)

The per-DJ HearThis cover must be a **square, valid PNG/JPG/WEBP image whose original filename ends in 02** (e.g. `GregLef02.png`). The worker first resolves an explicitly assigned MyLive 02 asset for the same `account_id`. Otherwise it uses the existing `dj_portal_assets.original_name` of that account's registered promotional 01 image plus its `dj_portal_accounts.day_of_week` to discover the matching square **02 sibling** in `/iluma/uploads/deseo_djs/<number>. <weekday>/<number>. <DJ>/` (e.g. `/iluma/uploads/deseo_djs/3. Fri/2. GregLef/GregLef02.png`). Discovery requires the original 01 file in that same directory, or (if the 01 source has since been moved) an exact normalized match between the numbered DJ folder label and that account's registered 01 filename stem; it also requires exactly one qualifying 02 candidate. It never fuzzy-searches all DJ photos. Existing 02 files therefore do **not** need to be copied, moved, renamed, or assigned individually when the matching 01 sibling is available. MyLive CMS > Assets shows each DJ's actual artwork 02 preview and exact resolved relative path. If discovery is ambiguous/unavailable, choose the existing server 02 via `HearThis Square Cover · 02 > Browse server / File Manager` or upload it manually; the explicit per-account asset wins. There is **NO** fallback to another DJ's image, the promotional 01, or a guessed local station-logo file. When an upload attempt starts, `hearthis_cover_asset_id` or `hearthis_cover_source_path` stores the exact chosen source for audit. **Custom artwork is optional**: if the exact square 02 cannot be uniquely resolved (including a missing, invalid, ambiguous or broken explicitly assigned image), the worker continues the episode upload while omitting the multipart `image` field. Per the API docs, HearThis can also extract embedded ID3 artwork; otherwise the site may apply its own account/platform default. The CMS explicitly says `HEARTHIS ARTWORK · DEFAULT FALLBACK` instead of marking this as an upload-blocking error. Provider-side default artwork behavior needs to be verified in staging.

Provisional shared metadata example: `Greg Lef – Deseo Radio | S06 EP001`; description identifies the DJ, Season 6, actual scheduled broadcast date in Athens, live website and the Season 6 archive. The generic genre is `Radioshow`; do **not** invent DJ-specific genres or tracklists. Verify the exact `Radioshow` genre slug, provider default image when no custom image is sent, and any Season 6 playlist association with a separate staging track. The API documents `image`/`cover` JPG or PNG only (<=10 MB); valid square WEBP or oversized artwork is omitted rather than blocking the archive. A successful MP3 upload may still contain `meta_error` for optional metadata, so do not treat HTTP 200 alone as a complete success.
