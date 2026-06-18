# SkyMedia

Professional stream ingest, DVR recording, and relay management platform built with Laravel 11, Jetstream, Inertia.js, and Vue 3.

---

## Architecture

```
Source Stream (HLS / UDP / MPEG-TS / RTMP / SRT)
        │
        ▼
   FFmpeg Ingest
   ┌────────────────────────────────────────┐
   │  TEE muxer (single ffmpeg process)     │
   │  ┌──────────────────┐ ┌─────────────┐ │
   │  │  DVR Segmenter   │ │ Push Output │ │
   │  │  seg_00001.ts    │ │ RTMP / SRT  │ │
   │  │  seg_00002.ts    │ │  → Server   │ │
   │  │  seg_NNNNN.ts    │ └─────────────┘ │
   │  │  (rolling window)│                 │
   │  └──────────────────┘                 │
   └────────────────────────────────────────┘
        │
   Stream Monitor (daemon)
   checks source health every N seconds
        │
   Source offline?
        │
        ▼
   FFmpeg DVR Playback (loop concat)
   → Push Output (RTMP / SRT)
        │
   Source back online?
        │
        ▼
   Switch back to Live Ingest
```

### DVR Rolling Window

- FFmpeg writes fixed-duration `.ts` segment files (`seg_00000.ts` … `seg_NNNNN.ts`)
- `DVRService::syncSegments()` registers new segments in the database
- `DVRService::enforceWindow()` deletes oldest segments (both DB record and disk file) to maintain the configured window
- `dvr:cleanup` artisan command (runs every 5 minutes via scheduler) enforces windows across all channels
- When source goes offline, `concat.txt` is built from current segments and ffmpeg loops it to the push target

---

## Requirements

- Ubuntu 22.04 LTS
- PHP 8.2+ with extensions: pcntl, posix, mbstring, mysql, redis, xml, curl, zip
- MySQL 8.0+
- Redis 7+
- FFmpeg with SRT support (`ffmpeg -protocols | grep srt`)
- Node.js 20+
- Supervisor

---

## Deployment Scripts

| Script | Purpose | Run when |
|--------|---------|----------|
| `deployment/install.sh` | Install all server dependencies (Nginx, PHP, MySQL, Redis, FFmpeg, Supervisor) | **Once** on a fresh server |
| `deployment/finish.sh`  | Bootstrap the Laravel app (deps, key, migrate, caches, start services) | **Once** after install.sh, once your code is in `/var/www/skymedia` |
| `deployment/update.sh`  | Pull new code, backup DB, migrate, rebuild, restart | **Every update** |

> All scripts are **idempotent** and **data-safe**. They never drop the database, never overwrite `.env`, and never delete DVR segments.

---

## First-time Installation

```bash
# 1. Install server infrastructure (idempotent — safe to re-run)
sudo bash deployment/install.sh --domain=skymedia.yourdomain.com

# If you have a git repo:
sudo bash deployment/install.sh --domain=skymedia.yourdomain.com --repo=https://github.com/you/skymedia.git

# 2. Once your code is in /var/www/skymedia, complete the app bootstrap:
sudo bash /var/www/skymedia/deployment/finish.sh

# 3. SSL certificate (Let's Encrypt)
certbot --nginx -d skymedia.yourdomain.com
```

The `.env` is created automatically from the template during `install.sh`.
Edit `/var/www/skymedia/.env` to change `APP_URL`, `APP_KEY`, or other settings.

---

## Updating (zero-data-loss)

```bash
# Pull new code + backup DB + migrate + rebuild + restart services
sudo bash /var/www/skymedia/deployment/update.sh

# Update from a specific branch
sudo bash /var/www/skymedia/deployment/update.sh --branch=v2.0

# Update without git pull (you deployed files manually)
sudo bash /var/www/skymedia/deployment/update.sh --skip-git
```

What `update.sh` does **automatically**:
1. **DB backup** to `/var/backups/skymedia/skymedia_TIMESTAMP.sql.gz` (keeps last 10)
2. `git pull` the latest code
3. Gracefully stops stream daemons
4. `composer install` + `npm run build`
5. `php artisan migrate --force` (adds new columns/tables — never drops)
6. Rebuilds all caches
7. Restarts Nginx + Supervisor services
8. `streams:activate-all` to resume active channels

**Rollback** if something goes wrong:
```bash
gunzip < /var/backups/skymedia/skymedia_TIMESTAMP.sql.gz | mysql -u skymedia -p skymedia
```

---

## Supervisor Services

| Service                | Purpose                                      |
|------------------------|----------------------------------------------|
| `skymedia-monitor`     | Long-running stream health monitor daemon    |
| `skymedia-scheduler`   | Laravel scheduler (dvr:cleanup, etc.)        |
| `skymedia-queue`       | Laravel queue worker for async jobs/events   |

```bash
supervisorctl status
supervisorctl restart skymedia-monitor
supervisorctl tail -f skymedia-monitor
```

---

## Artisan Commands

```bash
# Reset admin password (auto-generates password if --password is omitted)
php artisan admin:reset-password
php artisan admin:reset-password --email=admin@example.com
php artisan admin:reset-password --email=admin@example.com --password=mynewpassword

# Start all active channels
php artisan streams:activate-all

# Start/stop a specific channel
php artisan streams:start <id-or-slug>
php artisan streams:stop  <id-or-slug>

# Run monitor in foreground (for debugging)
php artisan streams:monitor
php artisan streams:monitor --channel=5

# Enforce DVR rolling windows and prune logs
php artisan dvr:cleanup
php artisan dvr:cleanup --channel=5 --log-days=7
```

---

## Stream Source URL Formats

| Protocol | Example URL                                    |
|----------|------------------------------------------------|
| HLS      | `https://cdn.example.com/live/stream.m3u8`     |
| UDP      | `udp://239.1.1.1:1234`                         |
| MPEG-TS  | `udp://239.1.1.1:1234` or `tcp://host:port`    |
| RTMP     | `rtmp://ingest.example.com/live/streamkey`     |
| SRT      | `192.168.1.100:9000` (host:port only)          |

---

## Push Output URL Formats

| Protocol | Push URL field             | Stream Key field |
|----------|----------------------------|------------------|
| RTMP     | `rtmp://wowza-server/live` | `channel1`       |
| SRT      | `192.168.1.50:9000`        | _(unused)_       |

---

## DVR Configuration

- **DVR Window** — Rolling time window in seconds (e.g. `18000` = 5 hours)
- **Segment Duration** — Individual `.ts` file length in seconds (2–30s recommended: 4s)
- The system maintains exactly `ceil(dvr_window / segment_duration)` segments at all times
- Old segments are deleted continuously as new ones arrive — disk usage is constant once the window is filled

---

## Admin Pages

| Route            | Description                              |
|------------------|------------------------------------------|
| `/`              | Dashboard — live stats + channel grid    |
| `/channels`      | Channel list management                  |
| `/channels/create` | Add new channel                        |
| `/channels/{id}` | Channel detail, DVR progress, probe      |
| `/channels/{id}/edit` | Edit channel settings              |
| `/dvr`           | DVR storage overview per channel         |
| `/dvr/{id}`      | Segment list for a channel               |
| `/logs`          | Filterable event log viewer              |
| `/settings`      | Application settings                     |

---

## API Endpoints

All endpoints require `auth:sanctum`. Prefix: `/api/`

| Method | Endpoint                        | Description           |
|--------|---------------------------------|-----------------------|
| GET    | `/api/channels`                 | List all channels     |
| GET    | `/api/channels/status-all`      | Lightweight status poll |
| GET    | `/api/channels/{id}/status`     | Single channel status |
| GET    | `/api/channels/{id}/logs`       | Last 50 events        |
| POST   | `/api/channels/{id}/start`      | Start channel         |
| POST   | `/api/channels/{id}/stop`       | Stop channel          |
| GET    | `/api/stats`                    | System-wide stats     |

---

## Technology Stack

| Component     | Technology                        |
|---------------|-----------------------------------|
| Backend       | Laravel 11, PHP 8.2               |
| Frontend      | Vue 3, Inertia.js, Tailwind CSS   |
| Auth          | Laravel Jetstream + Sanctum       |
| Database      | MySQL 8                           |
| Cache/Queue   | Redis                             |
| Stream Engine | FFmpeg (with SRT, H.264, AAC)     |
| Web Server    | Nginx                             |
| Process Mgr   | Supervisor                        |
| OS            | Ubuntu 22.04 LTS                  |
