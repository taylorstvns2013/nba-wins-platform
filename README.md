# NBA Wins Platform

A multi-league fantasy sports platform where participants draft NBA teams and compete based on total regular season wins.

## Features

- **Multi-League Support** - Multiple leagues with separate drafts and standings
- **Live Snake Draft** - Real-time draft system with auto-draft, pick timers, and commissioner controls
- **Live Game Tracking** - Automated game data collection from NBA API with RapidAPI backup
- **Analytics Dashboard** - Draft steals analysis, Vegas over/under tracking, strength of schedule
- **User Profiles** - Custom profile photos, draft preferences, and performance history
- **Mobile Responsive** - Optimized for all devices

## Tech Stack

- **Backend:** PHP 8.x, Python 3
- **Database:** MySQL
- **Server:** Apache on Ubuntu (Google Cloud)
- **APIs:** NBA API, RapidAPI (backup)
- **Frontend:** HTML/CSS/JavaScript, Chart.js

## Project Structure
```
/data/www/default/
├── index.php              # Main dashboard
├── analytics.php          # Analytics & statistics
├── draft.php              # Live draft interface
├── nba_standings.php      # NBA standings display
├── nba-wins-platform/
│   ├── admin/             # Commissioner tools
│   ├── api/               # API endpoints
│   ├── auth/              # Authentication system
│   ├── config/            # Database configuration
│   ├── core/              # Core classes (DraftManager, Auth, etc.)
│   ├── profiles/          # User profile pages
│   ├── public/assets/     # Images, logos, CSS
│   ├── stats/             # Team/player statistics
│   └── tasks/             # Cron jobs & data collection
```

## Cron Jobs

Game data is collected on a schedule optimized for NBA game times:
- Intensive polling (every 10 min) during prime time hours
- Hourly updates during off-peak hours
- Daily standings and roster updates

## Author

Taylor Stevens
