<?php
// nba-wins-platform/config/platform_settings.php
// Platform-wide feature flags and limits
// Update these values to control feature availability across the platform

// =====================================================================
// TIMEZONE — Ensures all date comparisons use Eastern Time
// =====================================================================
date_default_timezone_set('America/New_York');

// =====================================================================
// SEASON CONTROLS
// =====================================================================

// Allow new league creation (disable during active season)
define('LEAGUE_CREATION_ENABLED', false);

// Allow new user registration (can leave on even mid-season)
define('REGISTRATION_ENABLED', true);

// Allow joining existing leagues via PIN (may want off if all leagues have drafted)
define('LEAGUE_JOINING_ENABLED', false);

// =====================================================================
// LEAGUE LIMITS
// =====================================================================

// Max leagues a single user can create as commissioner
define('MAX_LEAGUES_PER_USER', 3);

// Days before an unfilled/undrafted league is considered orphaned
// Used by cleanup cron to deactivate abandoned leagues
define('ORPHAN_LEAGUE_DAYS', 60);

// Minimum participants required before a league can draft
define('MIN_PARTICIPANTS_TO_DRAFT', 2);

// =====================================================================
// SEASON INFO
// =====================================================================

// Current season identifier (used for display and data scoping)
define('CURRENT_SEASON', '2025-26');

// Message shown when league creation is disabled
define('LEAGUE_CREATION_DISABLED_MESSAGE', 'League creation is closed during the active season. New leagues can be created once the 2025-26 season concludes.');

// Message shown when joining is disabled
define('LEAGUE_JOINING_DISABLED_MESSAGE', 'Joining leagues is currently closed. Check back when the next season begins.');