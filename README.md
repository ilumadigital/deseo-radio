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

**Critical retention change:** `broadcasted` NEVER deletes MP3 audio. Both the legacy retention cron and page-load cleanup delete only if the row is `hearthis_status='synced'`, contains a validated HTTPS `hearthis.at` URL, and has a non-null `hearthis_synced_at`. The URL is saved before file removal, and the episode record remains. Existing already-deleted historical audio cannot be reconstructed or retroactively published.

The HearThis [official API documentation](https://hearthis.at/api) documents the Premium write operation `POST https://xhr.hearthis.at/upload_api.php` and authentication with `key` and `secret` as **HTTPS multipart POST fields**. The transport now implements that exact endpoint (blank `HEARTHIS_UPLOAD_ENDPOINT` uses the official value; any non-empty override must equal that URL). Blank `HEARTHIS_UPLOAD_AUTH_MODE` defaults to `post`; `post` is the only supported mode. Its multipart request includes `key`, `secret`, `file`, `title`, `private=0`, `description`, `genre`, and `tags`, plus optional JPG/PNG image in the documented **`image`** field (not `artwork`). It reads `files[0]`, checks the per-file `error`, captures `id`, `full.permalink_url` and an optional `meta_error`. Keys and secrets are never committed, sent to a public GET endpoint, or put in a URL. **Do not turn on `HEARTHIS_UPLOAD_ENABLED` until a separate staging track has proved the endpoint, published metadata, artwork fallback and public stream checks using rotated production credentials.**

Configure one minute CLI cron via the hosting dashboard (replace with the real PHP executable and deployment path):

```sh
* * * * * /usr/bin/php /absolute/path/to/public_html/iluma/cron-mylive-hearthis.php
```

The worker uses an advisory database lock and does not double-post concurrently. Uncertain network outcomes and missing/unrecognized response URLs become `review_required`, keep the MP3, and require checking HearThis before any manual retry. A successful `files[0]` result is stored as **`verifying`**, with remote track ID + URL. On later cron ticks an anonymous `GET https://api-v2.hearthis.at/{author}/{track}/` must agree on the returned ID, owner and public URL, indicate non-private content with positive duration and stream URL, and a bounded HTTPS audio-range probe must succeed. **Only then** is the row marked `synced` and `hearthis_synced_at` recorded; the retention hooks may then delete the local audio. Processing delays and temporary read outages leave the episode verifying without reupload or deletion. The status editor cannot change episodes while `uploading` or `verifying`. A pre-reserved slot remains recoverable after a >6-hour cron outage; manually broadcasted episodes with no recorded past `scheduled_show_end` are excluded from automatic publishing to prevent premature upload. Verify cron, storage permissions, cURL and the production MariaDB migration on a staging copy before merging. No production upload or deletion test has been performed.



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

`--check` only checks file header/type/size/path, presence of credentials and any prior test receipt; it never contacts the API. The second command requires explicit invocation and makes **one** Premium API call with `private=1`, without custom image; it does not connect to MyLive tables, install a cron, turn on the worker or delete audio. An attempt marker (`deseo-uploads/.hearthis-private-test-receipt.json`) is saved *before* sending the HTTP request, so interrupted/ambiguous outcomes cannot automatically duplicate the track. A successful response prints only the returned track ID and stores a minimal receipt (no credentials or raw response). A private upload alone does **not** establish public playback or production retention safety. Review the private track in the HearThis account after running; do not remove/retry the receipt without first reconciling that remote track. The exact user-supplied test audio must be owned or licensed appropriately.

#### Official public Season 6 release and playlist-gated DJ archiving

The existing `https://hearthis.at/deseoradio/set/season-6/` is the required archive for **all** Season 6 DJ Sets. A separate, database-independent CLI releases `domains/deseoradio.com/deseo-uploads/Deseo Radio - Season 6.mp3` publicly under the **exact title** `Deseo Radio Season 6 begins 14/10/2026`. The previous private-test receipt and track ID are retained unchanged. The user reports deleting the old private test on HearThis; do not automatically assume its deletion or erase the old receipt.

Deploy only `includes/hearthis-season6.php` and `iluma/cli-hearthis-season6-release.php` from the feature branch into matching `public_html` paths for this standalone test. Keep `HEARTHIS_UPLOAD_ENABLED=0`. No MyLive database, cron or merge is required to run these CLI commands:

```sh
cd ~/domains/deseoradio.com/public_html
php iluma/cli-hearthis-season6-release.php --check
# Only if the check resolves the UNIQUE existing Season 6 set ID:
php iluma/cli-hearthis-season6-release.php --publish-public
# After accepted upload, and later if the provider is still processing:
php iluma/cli-hearthis-season6-release.php --finalize
```

`--check` is entirely read-only, fetches the public account playlists and prints whether its exact Season 6 numeric set ID was found. `--publish-public` requires the original private-test receipt and unchanged MP3, creates a *distinct* public-release receipt BEFORE one HTTP POST, sets `private=0` with the exact title/description, and prints the new track ID or an uncertain-outcome warning; the source MP3 is retained. Never repeat it after any attempt; a duplicate-content error following remote deletion must be inspected manually. `--finalize` does not upload again. It independently verifies the owned public track ID, exact title, stream duration and bounded public audio playback, then checks its presence in the existing numeric Season 6 set. If missing and the public list is readable, it makes a single `POST https://api-v2.hearthis.at/set_ajax_add.php` with `action=add`, `set=<validated numeric ID>`, `track_id=<new track ID>` and HTTPS multipart POST credentials, writing an attempt receipt BEFORE any POST. If membership cannot be confirmed, subsequent invocations only READ and never blindly re-POST. Only after independently confirming both public playable audio and actual Season 6 set membership, and comparing the MP3 against its original SHA-256, may `--finalize` unlink that one MP3. All receipts remain outside `public_html`. The launch track is public IMMEDIATELY; `14/10/2026` is part of the title, not a `release_at` schedule.

Once the full PR is deployed and the separate worker is deliberately enabled after staging validation, every DJ episode follows the same playlist association rule. The worker resolves the unique existing Season 6 set ID BEFORE uploads; after a confirmed public track is playable, it checks membership; if absent it posts an add request ONCE with a durable `hearthis_set_status='adding'` record, then waits until the public set read contains that exact track ID. Only after that may `hearthis_status='synced'` and `hearthis_set_status='confirmed'` be set. The retention path requires both statuses. A missing/unreadable/ambiguous playlist holds the MP3 and never creates a new set implicitly. Stage-test the API's actual playlist response shape before enabling production cron.

#### Artwork 02 and episode metadata (optional artwork with provider default)

The per-DJ HearThis cover must be a **square, valid PNG/JPG/WEBP image whose original filename ends in 02** (e.g. `GregLef02.png`). The worker first resolves an explicitly assigned MyLive 02 asset for the same `account_id`. Otherwise it uses the existing `dj_portal_assets.original_name` of that account's registered promotional 01 image plus its `dj_portal_accounts.day_of_week` to discover the matching square **02 sibling** in `/iluma/uploads/deseo_djs/<number>. <weekday>/<number>. <DJ>/` (e.g. `/iluma/uploads/deseo_djs/3. Fri/2. GregLef/GregLef02.png`). Discovery requires the original 01 file in that same directory, or (if the 01 source has since been moved) an exact normalized match between the numbered DJ folder label and that account's registered 01 filename stem; it also requires exactly one qualifying 02 candidate. It never fuzzy-searches all DJ photos. Existing 02 files therefore do **not** need to be copied, moved, renamed, or assigned individually when the matching 01 sibling is available. MyLive CMS > Assets shows each DJ's actual artwork 02 preview and exact resolved relative path. If discovery is ambiguous/unavailable, choose the existing server 02 via `HearThis Square Cover · 02 > Browse server / File Manager` or upload it manually; the explicit per-account asset wins. There is **NO** fallback to another DJ's image, the promotional 01, or a guessed local station-logo file. When an upload attempt starts, `hearthis_cover_asset_id` or `hearthis_cover_source_path` stores the exact chosen source for audit. **Custom artwork is optional**: if the exact square 02 cannot be uniquely resolved (including a missing, invalid, ambiguous or broken explicitly assigned image), the worker continues the episode upload while omitting the multipart `image` field. Per the API docs, HearThis can also extract embedded ID3 artwork; otherwise the site may apply its own account/platform default. The CMS explicitly says `HEARTHIS ARTWORK · DEFAULT FALLBACK` instead of marking this as an upload-blocking error. Provider-side default artwork behavior needs to be verified in staging.

Provisional shared metadata example: `Greg Lef – Deseo Radio | S06 EP001`; description identifies the DJ, Season 6, actual scheduled broadcast date in Athens, live website and the Season 6 archive. The generic genre is `Radioshow`; do **not** invent DJ-specific genres or tracklists. Verify the exact `Radioshow` genre slug, provider default image when no custom image is sent, and any Season 6 playlist association with a separate staging track. The API documents `image`/`cover` JPG or PNG only (<=10 MB); valid square WEBP or oversized artwork is omitted rather than blocking the archive. A successful MP3 upload may still contain `meta_error` for optional metadata, so do not treat HTTP 200 alone as a complete success.
