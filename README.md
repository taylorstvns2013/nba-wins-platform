# NBA Wins Platform

A multi-league fantasy sports platform where participants draft NBA teams and compete based on total regular season, IST, and playoff wins.

**Live at:** [taylorstvns.com](https://taylorstvns.com)

---

## Features

- **Multi-League Support** — Multiple leagues with separate drafts, standings, and participants sharing the same NBA game data
- **Live Snake Draft** — Real-time draft system with auto-draft, pick timers, commissioner controls, and user draft preferences
- **Live Game Tracking** — Dual-API architecture using the free NBA CDN API with RapidAPI as backup, automated via cron jobs
- **Analytics Dashboard** — Draft steals analysis, Vegas over/under tracking, strength of schedule, weekly rankings
- **Claude's Column** — AI-generated editorial articles with a "NEW" badge when recent content exists
- **User Profiles** — Custom profile photos, draft preferences, badges, and performance history
- **Box Scores & Player Stats** — Quarter-by-quarter scoring and full player stat lines for every game
- **Mobile Responsive** — Optimized for all screen sizes with PWA support

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.4, Python 3.13 |
| Database | MariaDB (MySQL-compatible) |
| Server | Apache 2.4 on Debian Linux |
| Hosting | Raspberry Pi 5 (self-hosted via Cloudflare Zero Trust Tunnel) |
| APIs | NBA CDN Live API (primary), RapidAPI NBA API (backup) |
| Frontend | HTML/CSS/JavaScript, React + Babel (CDN), Chart.js, SortableJS |
| Automation | Cron jobs for game data, standings, player stats, quarter scores |
| Backups | Nightly SQL dumps to Google Cloud Storage with GCP VM cold-standby failover |

---

## Infrastructure

This platform runs on a self-hosted Raspberry Pi 5 with zero open inbound ports. Public access is provided through a Cloudflare Zero Trust Tunnel — all traffic is proxied through Cloudflare's edge network with automatic SSL.

```
Internet → Cloudflare Edge (SSL) → Cloudflare Tunnel → Apache on Pi → PHP/MariaDB
```

**Failover architecture:**
- Nightly database backups upload to Google Cloud Storage
- GCP VM sits in cold standby with a startup script that auto-restores the latest backup
- DNS cutover via Cloudflare takes under 5 minutes

---

## Project Structure

```
/
├── index.php                  # Main dashboard
├── analytics.php              # Analytics & statistics
├── draft.php                  # Live draft interface
├── nba_standings.php          # NBA standings display
└── nba-wins-platform/
    ├── admin/                 # Commissioner tools
    ├── api/                   # API endpoints
    ├── auth/                  # Authentication system
    ├── config/                # Database config & secrets (gitignored)
    │   ├── db_connection.php
    │   ├── secrets.php        # ← copy from secrets.php.example
    │   └── db_secrets.py      # ← copy from db_secrets.py.example
    ├── core/                  # Core classes (DraftManager, UserAuthentication, etc.)
    ├── profiles/              # User profile pages
    ├── public/assets/         # Images, logos, CSS
    ├── stats/                 # Team/player statistics & box scores
    └── tasks/                 # Cron jobs & data collection scripts
```

---

## Setup

### Prerequisites
- PHP 8.x with curl, pdo_mysql extensions
- Python 3.x with `nba_api`, `pymysql`, `pytz`, `requests`, `tenacity`
- MySQL / MariaDB
- Apache or similar web server

### Configuration

1. Copy the example secrets files and fill in your credentials:
```bash
cp nba-wins-platform/config/secrets.php.example nba-wins-platform/config/secrets.php
cp nba-wins-platform/config/db_secrets.py.example nba-wins-platform/config/db_secrets.py
```

2. Edit both files with your actual database credentials and RapidAPI key.

3. Install PHP dependencies:
```bash
cd nba-wins-platform
composer install
```

---

## Data Collection

Game data is collected on a schedule optimized for NBA game times (all times EST):

| Schedule | Script | Purpose |
|---|---|---|
| Every 5 min (midnight–3am) | `record_daily_wins.php` | Nightly win recording |
| Every 20 min (7pm–2am) | `fetch_games.php` | RapidAPI game sync |
| Every 5 min (7pm–2am) | `get_games.py` | NBA CDN live scores |
| Every 10 min (game hours) | `save_quarter_scores.py` | Quarter score tracking |
| Every 10 min (game hours) | `save_player_stats.py` | Player stat lines |
| Daily 4am | `update_standings.php` | NBA standings sync |
| Daily 7am | `update_team_api_stats.php` | Team statistics |
| Daily 3am | `update_roster_stats.py` | Roster updates |
| Daily 4am | `nba_backup.sh` | Database backup to GCS |

---

## Author

**Taylor Stevens** — Systems Engineer  
[taylorstvns.com](https://taylorstvns.com) · [resume.taylorstvns.com](https://resume.taylorstvns.com) · [LinkedIn](https://www.linkedin.com/in/taylor-stevens-71bb738a/)