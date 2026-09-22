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
