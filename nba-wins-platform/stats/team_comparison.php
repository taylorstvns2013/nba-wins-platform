<?php
/**
 * team_comparison.php - Game Preview / Team Comparison
 * 
 * Displays a head-to-head matchup preview between two teams including:
 *   - Team logos, records, and draft owners
 *   - Game time and arena info
 *   - Side-by-side team stats comparison with highlighting
 * 
 * Path: /data/www/default/nba-wins-platform/stats/team_comparison.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('America/New_York');

// =====================================================================
// AUTH & LEAGUE CONTEXT
// =====================================================================
require_once '/data/www/default/nba-wins-platform/config/db_connection.php';
require_once '/data/www/default/nba-wins-platform/config/season_config.php';
$season = getSeasonConfig();
requireAuthentication($auth);

$leagueContext = getCurrentLeagueContext($auth);
if (!$leagueContext || !$leagueContext['league_id']) {
    die('Error: No league selected.');
}

$currentLeagueId = $leagueContext['league_id'];
$currentUserId   = $_SESSION['user_id'] ?? null;

$currentUser = null;
if ($currentUserId) {
    $stmt = $pdo->prepare("SELECT display_name, username FROM users WHERE id = ?");
    $stmt->execute([$currentUserId]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
}


// ==========================================================================
// HELPER FUNCTIONS
// ==========================================================================

/**
 * Get team logo path from team name
 */
function getTeamLogo($teamName) {
    $logoMap = [
        'Atlanta Hawks'          => 'atlanta_hawks.png',
        'Boston Celtics'         => 'boston_celtics.png',
        'Brooklyn Nets'          => 'brooklyn_nets.png',
        'Charlotte Hornets'      => 'charlotte_hornets.png',
        'Chicago Bulls'          => 'chicago_bulls.png',
        'Cleveland Cavaliers'    => 'cleveland_cavaliers.png',
        'Dallas Mavericks'       => 'dallas_mavericks.png',
        'Denver Nuggets'         => 'denver_nuggets.png',
        'Detroit Pistons'        => 'detroit_pistons.png',
        'Golden State Warriors'  => 'golden_state_warriors.png',
        'Houston Rockets'        => 'houston_rockets.png',
        'Indiana Pacers'         => 'indiana_pacers.png',
        'Los Angeles Clippers'   => 'la_clippers.png',
        'Los Angeles Lakers'     => 'los_angeles_lakers.png',
        'Memphis Grizzlies'      => 'memphis_grizzlies.png',
        'Miami Heat'             => 'miami_heat.png',
        'Milwaukee Bucks'        => 'milwaukee_bucks.png',
        'Minnesota Timberwolves' => 'minnesota_timberwolves.png',
        'New Orleans Pelicans'   => 'new_orleans_pelicans.png',
        'New York Knicks'        => 'new_york_knicks.png',
        'Oklahoma City Thunder'  => 'oklahoma_city_thunder.png',
        'Orlando Magic'          => 'orlando_magic.png',
        'Philadelphia 76ers'     => 'philadelphia_76ers.png',
        'Phoenix Suns'           => 'phoenix_suns.png',
        'Portland Trail Blazers' => 'portland_trail_blazers.png',
        'Sacramento Kings'       => 'sacramento_kings.png',
        'San Antonio Spurs'      => 'san_antonio_spurs.png',
        'Toronto Raptors'        => 'toronto_raptors.png',
        'Utah Jazz'              => 'utah_jazz.png',
        'Washington Wizards'     => 'washington_wizards.png'
    ];

    if (isset($logoMap[$teamName])) {
        return '/nba-wins-platform/public/assets/team_logos/' . $logoMap[$teamName];
    }

    return '/nba-wins-platform/public/assets/team_logos/' . strtolower(str_replace(' ', '_', $teamName)) . '.png';
}


// ==========================================================================
// REQUEST PARAMETERS
// ==========================================================================
$home_team = $_GET['home_team'] ?? null;
$away_team = $_GET['away_team'] ?? null;
$game_date = $_GET['date'] ?? date('Y-m-d');

if (!$home_team || !$away_team) {
    die("Team information not provided");
}


// ==========================================================================
// DATA QUERIES
// ==========================================================================

// ------ Game + Team Records + Draft Owners ------
$stmt = $pdo->prepare("
    SELECT 
        g.*,
        nt1.name AS home_team_name,
        nt2.name AS away_team_name,
        nt1.logo_filename AS home_logo,
        nt2.logo_filename AS away_logo,
        t1.win AS home_wins,
        t1.loss AS home_losses,
        t2.win AS away_wins,
        t2.loss AS away_losses,
        nt1.id AS home_team_id,
        nt2.id AS away_team_id,
        (
            SELECT COALESCE(u1.display_name, lp1.participant_name)
            FROM league_participant_teams lpt1
            JOIN league_participants lp1 ON lpt1.league_participant_id = lp1.id
            LEFT JOIN users u1 ON lp1.user_id = u1.id
            WHERE lpt1.team_name = nt1.name AND lp1.league_id = ?
            LIMIT 1
        ) AS home_participant,
        (
            SELECT u1b.profile_photo
            FROM league_participant_teams lpt1b
            JOIN league_participants lp1b ON lpt1b.league_participant_id = lp1b.id
            LEFT JOIN users u1b ON lp1b.user_id = u1b.id
            WHERE lpt1b.team_name = nt1.name AND lp1b.league_id = ?
            LIMIT 1
        ) AS home_participant_photo,
        (
            SELECT COALESCE(u2.display_name, lp2.participant_name)
            FROM league_participant_teams lpt2
            JOIN league_participants lp2 ON lpt2.league_participant_id = lp2.id
            LEFT JOIN users u2 ON lp2.user_id = u2.id
            WHERE lpt2.team_name = nt2.name AND lp2.league_id = ?
            LIMIT 1
        ) AS away_participant,
        (
            SELECT u2b.profile_photo
            FROM league_participant_teams lpt2b
            JOIN league_participants lp2b ON lpt2b.league_participant_id = lp2b.id
            LEFT JOIN users u2b ON lp2b.user_id = u2b.id
            WHERE lpt2b.team_name = nt2.name AND lp2b.league_id = ?
            LIMIT 1
        ) AS away_participant_photo
    FROM games g
    JOIN nba_teams nt1 ON g.home_team = nt1.name
    JOIN nba_teams nt2 ON g.away_team = nt2.name
    LEFT JOIN {$season['standings_table']} t1 ON nt1.name = t1.name
    LEFT JOIN {$season['standings_table']} t2 ON nt2.name = t2.name
    WHERE g.home_team_code = ?
      AND g.away_team_code = ?
      AND DATE(g.start_time) = ?
    LIMIT 1
");
$stmt->execute([$currentLeagueId, $currentLeagueId, $currentLeagueId, $currentLeagueId, $home_team, $away_team, $game_date]);
$game = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$game) {
    die("Game not found");
}

// Build owner profile photo URLs
$photoBase = '/nba-wins-platform/public/assets/profile_photos/';
$game['home_photo_url'] = !empty($game['home_participant_photo']) 
    ? $photoBase . $game['home_participant_photo'] 
    : $photoBase . 'default.png';
$game['away_photo_url'] = !empty($game['away_participant_photo']) 
    ? $photoBase . $game['away_participant_photo'] 
    : $photoBase . 'default.png';

// ------ Format Game Time ------
list($hours, $minutes) = explode(':', substr($game['start_time'], 11, 5));
$hours   = (int)$hours;
$ampm    = $hours >= 12 ? 'PM' : 'AM';
$hours   = $hours > 12 ? $hours - 12 : ($hours == 0 ? 12 : $hours);
$game['formatted_time'] = $hours . ':' . $minutes . ' ' . $ampm;

// ------ Team Statistics ------
require_once '/data/www/default/nba-wins-platform/core/TeamStatsCalculator.php';

$statsCalculator = new TeamStatsCalculator($pdo);
$home_stats      = $statsCalculator->getTeamStats($game['home_team_name']);
$away_stats      = $statsCalculator->getTeamStats($game['away_team_name']);
$statsAvailable  = false;

if (($home_stats && $home_stats['GP'] > 0) || ($away_stats && $away_stats['GP'] > 0)) {
    $statsAvailable = true;

    // Default empty stats if one team has no data yet
    $emptyStats = [
        'GP' => 0, 'PTS' => 0, 'FG_PCT' => 0, 'FG3_PCT' => 0,
        'REB' => 0, 'AST' => 0, 'STL' => 0, 'BLK' => 0, 'PLUS_MINUS' => 0
    ];

    if (!$home_stats || $home_stats['GP'] == 0) {
        $home_stats = $emptyStats;
    }
    if (!$away_stats || $away_stats['GP'] == 0) {
        $away_stats = $emptyStats;
    }
}

// ------ Head-to-Head Matchups This Season ------
$h2hStmt = $pdo->prepare("
    SELECT date, home_team, away_team, home_points, away_points, status_long,
           home_team_code, away_team_code
    FROM games
    WHERE date >= '{$season['season_start_date']}'
      AND status_long IN ('Final', 'Finished')
      AND (
          (home_team = ? AND away_team = ?)
          OR
          (home_team = ? AND away_team = ?)
      )
    ORDER BY date DESC
");
$h2hStmt->execute([
    $game['home_team_name'], $game['away_team_name'],
    $game['away_team_name'], $game['home_team_name']
]);
$h2hGames = $h2hStmt->fetchAll(PDO::FETCH_ASSOC);

// ------ Participant IDs for streak badges ------
$partStmt = $pdo->prepare("
    SELECT lp.id AS participant_id, lp.user_id, u.display_name,
           lpt.team_name, 'home' AS side
    FROM league_participant_teams lpt
    JOIN league_participants lp ON lpt.league_participant_id = lp.id
    LEFT JOIN users u ON lp.user_id = u.id
    WHERE lpt.team_name = ? AND lp.league_id = ?
    LIMIT 1
");
$partStmt->execute([$game['home_team_name'], $currentLeagueId]);
$homeParticipant = $partStmt->fetch(PDO::FETCH_ASSOC);

$partStmt->execute([$game['away_team_name'], $currentLeagueId]);
$awayParticipant = $partStmt->fetch(PDO::FETCH_ASSOC);

// Helper: get all team names for a participant
function getParticipantTeamNames($pdo, $participantId) {
    $s = $pdo->prepare("SELECT team_name FROM league_participant_teams WHERE league_participant_id = ?");
    $s->execute([$participantId]);
    return array_column($s->fetchAll(PDO::FETCH_ASSOC), 'team_name');
}

// Helper: calculate current win streak from game results (W/L array newest-first)
function calcCurrentStreak($results) {
    if (empty($results)) return ['type' => null, 'count' => 0];
    $type = $results[0];
    $count = 0;
    foreach ($results as $r) {
        if ($r === $type) $count++;
        else break;
    }
    return ['type' => $type, 'count' => $count];
}

// Helper: returns ['streak' => N, 'leader' => 'home'|'away'|null] for H2H between two participants
function calcH2HStreak($pdo, $homeTeams, $awayTeams, $seasonStart) {
    if (empty($homeTeams) || empty($awayTeams)) return ['streak' => 0, 'leader' => null];
    $hPh = implode(',', array_fill(0, count($homeTeams), '?'));
    $aPh = implode(',', array_fill(0, count($awayTeams), '?'));
    $stmt = $pdo->prepare("
        SELECT
            CASE
                WHEN g.home_team IN ($hPh) AND g.away_team IN ($aPh) AND g.home_points > g.away_points THEN 'H'
                WHEN g.away_team IN ($hPh) AND g.home_team IN ($aPh) AND g.away_points > g.home_points THEN 'H'
                WHEN g.home_team IN ($aPh) AND g.away_team IN ($hPh) AND g.home_points > g.away_points THEN 'A'
                WHEN g.away_team IN ($aPh) AND g.home_team IN ($hPh) AND g.away_points > g.home_points THEN 'A'
                ELSE 'T'
            END AS winner
        FROM games g
        WHERE g.status_long IN ('Final','Finished')
          AND g.date >= ?
          AND ((g.home_team IN ($hPh) AND g.away_team IN ($aPh))
               OR (g.home_team IN ($aPh) AND g.away_team IN ($hPh)))
        ORDER BY g.date DESC, g.start_time DESC
    ");
    // CASE has 4 WHEN clauses: (h,a), (h,a), (a,h), (a,h) = 4h + 4a
    // WHERE filter: (h,a), (a,h) = 2h + 2a  |  date >= ? = 1
    // Total: 6h, 6a, 1 seasonStart
    $stmt->execute(array_merge(
        $homeTeams, $awayTeams,
        $homeTeams, $awayTeams,
        $awayTeams, $homeTeams,
        $awayTeams, $homeTeams,
        [$seasonStart],
        $homeTeams, $awayTeams,
        $awayTeams, $homeTeams
    ));
    $games = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'winner');
    if (empty($games) || $games[0] === 'T') return ['streak' => 0, 'leader' => null];
    $leader = $games[0];
    $streak = 0;
    foreach ($games as $g) {
        if ($g === $leader) $streak++;
        else break;
    }
    return ['streak' => $streak, 'leader' => $leader === 'H' ? 'home' : 'away'];
}

// Helper: calculate current win streak for a participant's teams
function calcWinStreak($pdo, $teamNames, $seasonStart) {
    if (empty($teamNames)) return 0;
    $ph = implode(',', array_fill(0, count($teamNames), '?'));
    $stmt = $pdo->prepare("
        SELECT
            CASE
                WHEN g.home_team IN ($ph) AND g.home_points > g.away_points THEN 'W'
                WHEN g.away_team IN ($ph) AND g.away_points > g.home_points THEN 'W'
                ELSE 'L'
            END AS result
        FROM games g
        WHERE g.status_long IN ('Final','Finished')
          AND g.date >= ?
          AND (g.home_team IN ($ph) OR g.away_team IN ($ph))
        ORDER BY g.date DESC, g.start_time DESC
    ");
    // CASE: ph x2 | WHERE: seasonStart, ph, ph => total 4x ph, 1x seasonStart
    $stmt->execute(array_merge($teamNames, $teamNames, [$seasonStart], $teamNames, $teamNames));
    $results = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'result');
    $s = calcCurrentStreak($results);
    return $s['type'] === 'W' ? $s['count'] : 0;
}

// Calculate streaks
$homeTeamNames = $homeParticipant ? getParticipantTeamNames($pdo, $homeParticipant['participant_id']) : [];
$awayTeamNames = $awayParticipant ? getParticipantTeamNames($pdo, $awayParticipant['participant_id']) : [];

$h2hStreak     = calcH2HStreak($pdo, $homeTeamNames, $awayTeamNames, $season['season_start_date']);
$homeWinStreak = calcWinStreak($pdo, $homeTeamNames, $season['season_start_date']);
$awayWinStreak = calcWinStreak($pdo, $awayTeamNames, $season['season_start_date']);

// Determine bully badge tier (5=I, 10=II, 15=III)
function bullyTier($streak) {
    if ($streak >= 15) return ['tier' => 'III', 'icon' => 'fa-skull-crossbones', 'color' => '#ef4444'];
    if ($streak >= 10) return ['tier' => 'II',  'icon' => 'fa-face-angry',       'color' => '#f97316'];
    if ($streak >= 5)  return ['tier' => 'I',   'icon' => 'fa-hand-fist',        'color' => '#10b981'];
    return null;
}

// Determine win streak badge tier (5=Heating Up, 10=On Fire, 15=Unstoppable)
function winStreakTier($streak) {
    if ($streak >= 15) return ['label' => 'Unstoppable', 'icon' => 'fa-bolt',      'color' => '#a855f7'];
    if ($streak >= 10) return ['label' => 'On Fire',     'icon' => 'fa-fire',      'color' => '#ef4444'];
    if ($streak >= 5)  return ['label' => 'Heating Up',  'icon' => 'fa-fire',      'color' => '#f59e0b'];
    return null;
}

$sameParticipant = ($homeParticipant && $awayParticipant && $homeParticipant['participant_id'] === $awayParticipant['participant_id']);
$homeBully    = (!$sameParticipant && $h2hStreak['leader'] === 'home') ? bullyTier($h2hStreak['streak']) : null;
$awayBully    = (!$sameParticipant && $h2hStreak['leader'] === 'away') ? bullyTier($h2hStreak['streak']) : null;
$homeWinBadge = winStreakTier($homeWinStreak);
$awayWinBadge = winStreakTier($awayWinStreak);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="theme-color" content="<?= ($_SESSION['theme_preference'] ?? 'dark') === 'classic' ? '#f5f5f5' : '#121a23' ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($game['home_team_name']) ?> vs <?= htmlspecialchars($game['away_team_name']) ?> - Preview</title>
    <link rel="apple-touch-icon" type="image/png" href="/nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="/nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
/* ==========================================================================
   CSS VARIABLES
   ========================================================================== */
:root {
    --bg-primary: #151d28;
    --bg-secondary: #1a222c;
    --bg-card: #202a38;
    --bg-card-hover: #273140;
    --bg-elevated: #2a3446;
    --border-color: rgba(255, 255, 255, 0.08);
    --text-primary: #e6edf3;
    --text-secondary: #8b949e;
    --text-muted: #545d68;
    --accent-blue: #388bfd;
    --accent-blue-dim: rgba(56, 139, 253, 0.15);
    --accent-green: #3fb950;
    --accent-red: #f85149;
    --accent-orange: #d29922;
    --accent-teal: #76a5af;
    --radius-md: 10px;
    --radius-lg: 14px;
    --shadow-card: 0 1px 3px rgba(0, 0, 0, 0.4), 0 0 0 1px var(--border-color);
    --transition-fast: 0.15s ease;
    --team-row-gradient: rgba(48, 62, 80, 0.7);
    --team-row-gradient-end: rgba(32, 42, 56, 0);
}

<?php if (($_SESSION['theme_preference'] ?? 'dark') === 'classic'): ?>
:root {
    --bg-primary: #f5f5f5;
    --bg-secondary: rgba(245, 245, 245, 0.95);
    --bg-card: #ffffff;
    --bg-card-hover: #f8f9fa;
    --bg-elevated: #f0f0f2;
    --border-color: #e0e0e0;
    --border-subtle: rgba(0, 0, 0, 0.06);
    --text-primary: #333333;
    --text-secondary: #666666;
    --text-muted: #999999;
    --accent-blue: #0066ff;
    --accent-blue-dim: rgba(0, 102, 255, 0.08);
    --accent-blue-glow: rgba(0, 102, 255, 0.15);
    --accent-green: #28a745;
    --accent-green-dim: rgba(40, 167, 69, 0.08);
    --accent-red: #dc3545;
    --accent-red-dim: rgba(220, 53, 69, 0.08);
    --accent-gold: #d4a017;
    --accent-silver: #8a8a8a;
    --accent-bronze: #b5651d;
    --shadow-card: 0 1px 4px rgba(0, 0, 0, 0.08), 0 0 0 1px rgba(0, 0, 0, 0.04);
    --shadow-elevated: 0 4px 16px rgba(0, 0, 0, 0.1), 0 0 0 1px rgba(0, 0, 0, 0.06);
    --team-row-gradient: rgba(0, 0, 0, 0.04);
    --team-row-gradient-end: rgba(0, 0, 0, 0);
}
body {
    background-image: url('nba-wins-platform/public/assets/background/geometric_white.png');
    background-repeat: repeat;
    background-attachment: fixed;
}
<?php endif; ?>

/* ==========================================================================
   BASE / RESET
   ========================================================================== */
* { margin: 0; padding: 0; box-sizing: border-box; }
html { background: var(--bg-primary); }
body {
    font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
    line-height: 1.5;
    color: var(--text-primary);
    background: var(--bg-primary);
    background-image: radial-gradient(ellipse at 50% 0%, rgba(56, 139, 253, 0.04) 0%, transparent 60%);
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
    padding: 0;
}

/* ==========================================================================
   LAYOUT
   ========================================================================== */
.app-container { max-width: 900px; margin: 0 auto; padding: 12px 12px 2rem; }

/* Desktop two-column layout */
@media (min-width: 1100px) {
    .app-container { max-width: 1400px; }
    .tc-two-col {
        display: grid;
        grid-template-columns: 1fr 1fr;
        grid-template-rows: auto 1fr;
        gap: 20px;
        align-items: start;
    }
    .tc-col-left {
        grid-column: 1;
        grid-row: 1;
    }
    .tc-col-right {
        grid-column: 2;
        grid-row: 1 / -1;
        position: sticky;
        top: 12px;
        max-height: calc(100vh - 24px);
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: var(--bg-elevated) transparent;
    }
    .tc-h2h {
        grid-column: 1;
        grid-row: 2;
    }
    .tc-col-right::-webkit-scrollbar { width: 5px; }
    .tc-col-right::-webkit-scrollbar-track { background: transparent; }
    .tc-col-right::-webkit-scrollbar-thumb {
        background: var(--bg-elevated);
        border-radius: 4px;
    }
    .tc-col-right::-webkit-scrollbar-thumb:hover {
        background: var(--text-muted);
    }

    /* ---- Desktop size scale-up ---- */
    .app-container { padding: 20px 24px 2rem; }
    .logo-flip-container { width: 88px; height: 88px; }
    .owner-photo-flip { width: 78px; height: 78px; }
    .team-name-small { font-size: 1.4rem; }
    .team-record { font-size: 1.9rem; }
    .team-owner-small { font-size: 0.95rem; }
    .match-card, .h2h-card { padding: 2rem 2rem 1.5rem; }
    .game-time-info { font-size: 1rem; padding: 0.6rem 1rem; }
    .vs-divider { font-size: 1.1rem; }
    /* Stats table */
    .stats-table td { padding: 1rem 1rem; font-size: 1.05rem; }
    .stats-table .stat-label { font-size: 0.9rem; }
    .stats-header { padding: 1.1rem 1.4rem; font-size: 1.2rem; }
    /* H2H matchup rows */
    .h2h-row { padding: 1rem 1.4rem; font-size: 1rem; }
    .h2h-score { font-size: 1rem; }
    .h2h-team-logo { width: 28px; height: 28px; }
    .h2h-date { font-size: 0.88rem; }
    /* Section headers */
    .section-header { padding: 1rem 1.4rem; font-size: 1.15rem; }
}
@media (max-width: 1099px) {
    .tc-two-col {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }
    .tc-col-left { order: 1; }
    .tc-col-right { order: 2; }
    .tc-h2h { order: 3; }
}

.app-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 16px 16px 12px;
    position: relative;
}
.app-header-logo { width: 36px; height: 36px; }
.app-header-title { font-size: 1.35rem; font-weight: 700; letter-spacing: -0.02em; }

.nav-toggle-btn {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    width: 38px; height: 38px;
    display: flex; align-items: center; justify-content: center;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    color: var(--text-secondary);
    font-size: 16px;
    cursor: pointer;
    transition: all var(--transition-fast);
}
.nav-toggle-btn:hover {
    color: var(--text-primary);
    border-color: rgba(56, 139, 253, 0.3);
    background: var(--accent-blue-dim);
}

/* ==========================================================================
   MATCHUP HEADER
   ========================================================================== */
.matchup-header {
    background: var(--bg-card);
    padding: 1.5rem;
    border-radius: var(--radius-lg);
    margin-bottom: 14px;
    box-shadow: var(--shadow-card);
}

.team-row {
    position: relative;
    padding: 1.5rem;
    margin-bottom: 0.75rem;
    border-radius: var(--radius-md);
    overflow: visible;
    min-height: 90px;
    display: flex;
    align-items: center;
    background: transparent;
}
/* Re-clip only the background pseudo-element */
.team-row::before {
    border-radius: var(--radius-md);
    overflow: hidden;
}
.team-row:last-of-type { margin-bottom: 0; }

/* Fading background from logo side */
.team-row::before {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    z-index: 0;
}
.team-row.home-team::before {
    background: linear-gradient(to right, var(--team-row-gradient) 0%, var(--team-row-gradient-end) 75%);
}
.team-row.away-team::before {
    background: linear-gradient(to left, var(--team-row-gradient) 0%, var(--team-row-gradient-end) 75%);
}

.team-info-left {
    position: relative; z-index: 2;
    flex: 1;
    display: flex; align-items: center; gap: 1.25rem;
}
.team-info-right {
    position: relative; z-index: 2;
    flex: 1;
    text-align: right;
    display: flex; align-items: center; gap: 1.25rem;
    flex-direction: row-reverse;
}

.team-logo-background {
    position: absolute;
    width: 160px; height: 160px;
    object-fit: contain;
    opacity: 0.16;
    z-index: 1;
    pointer-events: none;
}
.team-row.home-team .team-logo-background {
    left: 1rem; top: 50%; transform: translateY(-50%);
}
.team-row.away-team .team-logo-background {
    right: 1rem; top: 50%; transform: translateY(-50%);
}

.team-logo-visible {
    width: 64px; height: 64px;
    object-fit: contain;
    filter: drop-shadow(0 2px 6px rgba(0, 0, 0, 0.3));
    flex-shrink: 0;
}

/* Logo flip animation */
.logo-flip-container {
    width: 64px; height: 64px;
    perspective: 600px;
    flex-shrink: 0;
}
.logo-flip-inner {
    width: 100%; height: 100%;
    position: relative;
    transform-style: preserve-3d;
    transition: transform 0.6s ease-in-out;
}
.logo-flip-inner.flipped { transform: rotateY(180deg); }
.logo-flip-front, .logo-flip-back {
    position: absolute; inset: 0;
    backface-visibility: hidden;
    display: flex; align-items: center; justify-content: center;
}
.logo-flip-back { transform: rotateY(180deg); }
.owner-photo-flip {
    width: 56px; height: 56px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--accent-blue);
    box-shadow: 0 2px 8px rgba(56, 139, 253, 0.3);
}
.logo-flip-container .team-logo-visible {
    filter: drop-shadow(0 2px 6px rgba(0, 0, 0, 0.3));
}

/* Streak badge overlays on team logos */
.logo-badge-wrap {
    position: relative;
    display: inline-block;
}
.streak-badge {
    position: absolute;
    bottom: -4px;
    right: -6px;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
    font-weight: 900;
    border: 2px solid var(--bg-card, #1a2332);
    z-index: 2;
    cursor: default;
    box-shadow: 0 2px 6px rgba(0,0,0,0.4);
}
.streak-badge.bully-badge { bottom: -4px; right: -6px; }
.streak-badge.win-badge   { bottom: 12px; right: -6px; }
.streak-badge-tooltip {
    position: absolute;
    bottom: 28px;
    right: -10px;
    background: rgba(10,15,25,0.95);
    color: #fff;
    font-size: 0.65rem;
    font-weight: 600;
    white-space: nowrap;
    padding: 4px 8px;
    border-radius: 6px;
    pointer-events: none;
    opacity: 0;
    transform: translateY(4px);
    transition: opacity 0.15s ease, transform 0.15s ease;
    z-index: 10;
}
.streak-badge:hover .streak-badge-tooltip { opacity: 1; transform: translateY(0); }

/* Home team is on the left — flip tooltip to open rightward */
.home-team .streak-badge-tooltip {
    right: auto;
    left: -10px;
}

.team-name-small {
    font-size: 1.15rem; font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 2px; line-height: 1.2;
}
.team-record {
    font-size: 1.5rem; font-weight: 800;
    color: var(--text-primary);
    letter-spacing: -0.5px;
    margin-bottom: 2px;
    font-variant-numeric: tabular-nums;
}
.team-owner-small {
    font-size: 0.82rem;
    color: var(--text-muted);
    font-style: italic;
    margin-top: 2px;
    font-weight: 500;
}

.vs-divider {
    text-align: center;
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--text-muted);
    padding: 0.4rem 0;
    letter-spacing: 2px;
}

.game-time-info {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.4rem 0.75rem;
    background: rgba(118, 165, 175, 0.15);
    color: var(--text-muted);
    border-radius: var(--radius-md);
    font-weight: 500;
    font-size: 0.78rem;
    margin-top: 0.5rem;
}
.game-time-info .divider {
    color: rgba(255, 255, 255, 0.25);
    font-weight: 400;
}

/* ==========================================================================
   STATS COMPARISON
   ========================================================================== */
.stats-section {
    background: var(--bg-card);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-card);
    overflow: hidden;
}

.section-title {
    padding: 1rem 1.25rem;
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-primary);
    text-align: center;
    border-bottom: 1px solid var(--border-color);
}

.stat-team-header {
    border-bottom: 2px solid var(--border-color);
}
.stat-team-label {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 0.85rem 0.75rem;
    font-size: 0.92rem;
    font-weight: 700;
    color: var(--text-primary);
    background: var(--bg-secondary);
}
.stat-team-logo {
    width: 24px;
    height: 24px;
    object-fit: contain;
}
.stat-header-mid {
    background: var(--bg-secondary) !important;
    border-left: none !important;
    border-right: none !important;
}

.stat-row {
    display: flex;
    align-items: stretch;
    border-bottom: 1px solid var(--border-color);
    min-height: 52px;
}
.stat-row:last-child { border-bottom: none; }

.stat-value {
    flex: 1;
    display: flex; align-items: center; justify-content: center;
    padding: 0.9rem 0.75rem;
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--text-primary);
    background: var(--bg-elevated);
    font-variant-numeric: tabular-nums;
}
.stat-value.higher {
    background: rgba(63, 185, 80, 0.12);
    color: var(--accent-green);
}

.stat-label {
    flex: 1.5;
    display: flex; align-items: center; justify-content: center;
    padding: 0.9rem 1rem;
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text-secondary);
    text-align: center;
    background: var(--bg-card);
    border-left: 1px solid var(--border-color);
    border-right: 1px solid var(--border-color);
}

.no-stats-message {
    text-align: center;
    padding: 2.5rem 1.5rem;
    color: var(--text-muted);
    font-size: 0.95rem;
}
.no-stats-message i {
    font-size: 2.5rem;
    margin-bottom: 0.75rem;
    color: var(--accent-teal);
    display: block;
}

/* ==========================================================================
   HEAD-TO-HEAD MATCHUPS
   ========================================================================== */
.h2h-section {
    background: var(--bg-card);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-card);
    overflow: hidden;
}
.h2h-title {
    padding: 0.85rem 1.25rem;
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--text-secondary);
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 8px;
}
.h2h-game {
    display: flex;
    align-items: center;
    padding: 0.75rem 1.25rem;
    border-bottom: 1px solid var(--border-color);
    gap: 12px;
    text-decoration: none;
    color: inherit;
    transition: background var(--transition-fast);
}
.h2h-game:last-child { border-bottom: none; }
.h2h-game:hover { background: var(--bg-card-hover); }
.h2h-date {
    font-size: 0.75rem;
    color: var(--text-muted);
    font-weight: 500;
    min-width: 70px;
    white-space: nowrap;
}
.h2h-teams {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.88rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}
.h2h-team {
    display: flex;
    align-items: center;
    gap: 5px;
}
.h2h-team-logo {
    width: 22px;
    height: 22px;
    object-fit: contain;
}
.h2h-score {
    font-weight: 700;
    min-width: 26px;
    text-align: center;
}
.h2h-score.winner { color: var(--accent-green); }
.h2h-score.loser { color: var(--text-muted); }
.h2h-at {
    color: var(--text-muted);
    font-size: 0.75rem;
    font-weight: 400;
}
.h2h-empty {
    padding: 1.5rem;
    text-align: center;
    color: var(--text-muted);
    font-size: 0.88rem;
}

/* ==========================================================================
   MOBILE RESPONSIVE
   ========================================================================== */
@media (max-width: 600px) {
    .app-container { padding: 8px 8px 2rem; }

    .matchup-header { padding: 1rem; border-radius: var(--radius-md); }
    .team-row { padding: 1rem 0.75rem; min-height: 70px; }
    .team-logo-visible { width: 48px; height: 48px; }
    .logo-flip-container { width: 48px; height: 48px; }
    .owner-photo-flip { width: 40px; height: 40px; }
    .team-logo-background { width: 120px; height: 120px; }
    .team-name-small { font-size: 0.95rem; }
    .team-record { font-size: 1.25rem; }

    .stat-value { font-size: 0.9rem; padding: 0.75rem 0.5rem; }
    .stat-label { font-size: 0.8rem; padding: 0.75rem 0.5rem; }
}
    
</style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/components/navigation_menu.php'; ?>

<div class="app-container">

    <!-- ================================================================
         TWO-COLUMN GRID (desktop) / STACKED (mobile)
         ================================================================ -->
    <div class="tc-two-col">

        <!-- LEFT COLUMN: Matchup Header -->
        <div class="tc-col-left">
            <div class="matchup-header">

        <!-- Home Team -->
        <div class="team-row home-team">
            <img src="<?= htmlspecialchars(getTeamLogo($game['home_team_name'])) ?>" alt=""
                 class="team-logo-background" onerror="this.style.display='none'">
            <div class="team-info-left">
                <div class="logo-badge-wrap">
                <a href="/nba-wins-platform/stats/team_data.php?team=<?= urlencode($game['home_team_name']) ?>">
                    <div class="logo-flip-container">
                        <div class="logo-flip-inner">
                            <div class="logo-flip-front">
                                <img src="<?= htmlspecialchars($game['home_photo_url']) ?>" alt=""
                                     class="owner-photo-flip"
                                     onerror="this.src='<?= $photoBase ?>default.png'">
                            </div>
                            <div class="logo-flip-back">
                                <img src="<?= htmlspecialchars(getTeamLogo($game['home_team_name'])) ?>"
                                     alt="<?= htmlspecialchars($game['home_team_name']) ?>"
                                     class="team-logo-visible" onerror="this.style.opacity='0.3'">
                            </div>
                        </div>
                    </div>
                </a>
                <?php if ($homeBully): ?>
                    <span class="streak-badge bully-badge" style="background:<?= $homeBully['color'] ?>;">
                        <i class="fas <?= $homeBully['icon'] ?>"></i>
                        <span class="streak-badge-tooltip">Bully <?= $homeBully['tier'] ?> · <?= $h2hStreak['streak'] ?> H2H wins</span>
                    </span>
                <?php endif; ?>
                <?php if ($homeWinBadge): ?>
                    <span class="streak-badge win-badge" style="background:<?= $homeWinBadge['color'] ?>;">
                        <i class="fas <?= $homeWinBadge['icon'] ?>"></i>
                        <span class="streak-badge-tooltip"><?= $homeWinBadge['label'] ?> · <?= $homeWinStreak ?> win streak</span>
                    </span>
                <?php endif; ?>
                </div>
                <div>
                    <div class="team-name-small"><?= htmlspecialchars($game['home_team_name']) ?></div>
                    <div class="team-record"><?= $game['home_wins'] ?>-<?= $game['home_losses'] ?></div>
                    <?php if (isset($game['home_participant'])): ?>
                        <div class="team-owner-small">(<?= htmlspecialchars($game['home_participant']) ?>)</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="vs-divider">VS</div>

        <!-- Away Team -->
        <div class="team-row away-team">
            <img src="<?= htmlspecialchars(getTeamLogo($game['away_team_name'])) ?>" alt=""
                 class="team-logo-background" onerror="this.style.display='none'">
            <div class="team-info-right">
                <div class="logo-badge-wrap">
                <a href="/nba-wins-platform/stats/team_data.php?team=<?= urlencode($game['away_team_name']) ?>">
                    <div class="logo-flip-container">
                        <div class="logo-flip-inner">
                            <div class="logo-flip-front">
                                <img src="<?= htmlspecialchars($game['away_photo_url']) ?>" alt=""
                                     class="owner-photo-flip"
                                     onerror="this.src='<?= $photoBase ?>default.png'">
                            </div>
                            <div class="logo-flip-back">
                                <img src="<?= htmlspecialchars(getTeamLogo($game['away_team_name'])) ?>"
                                     alt="<?= htmlspecialchars($game['away_team_name']) ?>"
                                     class="team-logo-visible" onerror="this.style.opacity='0.3'">
                            </div>
                        </div>
                    </div>
                </a>
                <?php if ($awayBully): ?>
                    <span class="streak-badge bully-badge" style="background:<?= $awayBully['color'] ?>;">
                        <i class="fas <?= $awayBully['icon'] ?>"></i>
                        <span class="streak-badge-tooltip">Bully <?= $awayBully['tier'] ?> · <?= $h2hStreak['streak'] ?> H2H wins</span>
                    </span>
                <?php endif; ?>
                <?php if ($awayWinBadge): ?>
                    <span class="streak-badge win-badge" style="background:<?= $awayWinBadge['color'] ?>;">
                        <i class="fas <?= $awayWinBadge['icon'] ?>"></i>
                        <span class="streak-badge-tooltip"><?= $awayWinBadge['label'] ?> · <?= $awayWinStreak ?> win streak</span>
                    </span>
                <?php endif; ?>
                </div>
                <div>
                    <div class="team-name-small"><?= htmlspecialchars($game['away_team_name']) ?></div>
                    <div class="team-record"><?= $game['away_wins'] ?>-<?= $game['away_losses'] ?></div>
                    <?php if (isset($game['away_participant'])): ?>
                        <div class="team-owner-small">(<?= htmlspecialchars($game['away_participant']) ?>)</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Game Time & Arena -->
        <div class="game-time-info">
            <div style="display: flex; align-items: center; gap: 6px">
                <i class="fa-regular fa-clock"></i>
                <span><?= $game['formatted_time'] ?></span>
            </div>
            <span class="divider">•</span>
            <span><?= htmlspecialchars($game['arena']) ?></span>
        </div>
    </div>

        </div><!-- /.tc-col-left -->

        <!-- RIGHT COLUMN: Stats Comparison -->
        <div class="tc-col-right">
    <!-- ================================================================
         STATS COMPARISON
         ================================================================ -->
    <div class="stats-section">
        <?php
        // Extract mascot names (last word of team name, or handle special cases)
        $homeParts = explode(' ', $game['home_team_name']);
        $homeMascot = end($homeParts);
        $awayParts = explode(' ', $game['away_team_name']);
        $awayMascot = end($awayParts);
        // Handle "Trail Blazers" and "76ers" type names
        if ($game['home_team_name'] === 'Portland Trail Blazers') $homeMascot = 'Trail Blazers';
        if ($game['away_team_name'] === 'Portland Trail Blazers') $awayMascot = 'Trail Blazers';
        ?>
        <div class="stat-row stat-team-header">
            <div class="stat-team-label">
                <img src="<?= htmlspecialchars(getTeamLogo($game['home_team_name'])) ?>" alt="" class="stat-team-logo">
                <span><?= htmlspecialchars($homeMascot) ?></span>
            </div>
            <div class="stat-label stat-header-mid"></div>
            <div class="stat-team-label">
                <img src="<?= htmlspecialchars(getTeamLogo($game['away_team_name'])) ?>" alt="" class="stat-team-logo">
                <span><?= htmlspecialchars($awayMascot) ?></span>
            </div>
        </div>

        <?php if ($statsAvailable): ?>
            <?php
            // Stat definitions: [key, label, multiplier]
            // multiplier > 1 = percentage (multiply by 100 and append %)
            $stats = [
                ['PTS',        'Points Per Game', 1],
                ['FG_PCT',     'Field Goal %',    100],
                ['FG3_PCT',    '3-Point %',       100],
                ['REB',        'Rebounds',         1],
                ['AST',        'Assists',          1],
                ['STL',        'Steals',           1],
                ['BLK',        'Blocks',           1],
                ['PLUS_MINUS', 'Plus/Minus',       1]
            ];

            foreach ($stats as $s):
                $homeVal = $s[2] > 1 ? $home_stats[$s[0]] * $s[2] : $home_stats[$s[0]];
                $awayVal = $s[2] > 1 ? $away_stats[$s[0]] * $s[2] : $away_stats[$s[0]];

                // Highlight the higher value
                $homeHighlight = $homeVal > $awayVal ? 'higher' : '';
                $awayHighlight = $awayVal > $homeVal ? 'higher' : '';

                // Format suffix (% for percentages)
                $suffix = $s[2] > 1 ? '%' : '';

                // Plus/minus prefix
                $homePrefix = $s[0] === 'PLUS_MINUS' ? ($homeVal >= 0 ? '+' : '') : '';
                $awayPrefix = $s[0] === 'PLUS_MINUS' ? ($awayVal >= 0 ? '+' : '') : '';
            ?>
                <div class="stat-row">
                    <div class="stat-value <?= $homeHighlight ?>">
                        <?= $homePrefix . number_format($homeVal, 1) . $suffix ?>
                    </div>
                    <div class="stat-label"><?= $s[1] ?></div>
                    <div class="stat-value <?= $awayHighlight ?>">
                        <?= $awayPrefix . number_format($awayVal, 1) . $suffix ?>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php else: ?>
            <div class="no-stats-message">
                <i class="fas fa-info-circle"></i>
                <p>Stats available after the first regular season games.</p>
            </div>
        <?php endif; ?>
    </div>
        </div><!-- /.tc-col-right -->

        <!-- SEASON MATCHUPS (left col on desktop, after stats on mobile) -->
        <div class="tc-h2h">
            <div class="h2h-section">
                <div class="h2h-title">
                    <i class="fas fa-repeat" style="color: var(--accent-teal);"></i>
                    Season Matchups
                </div>
                <?php if (!empty($h2hGames)): ?>
                    <?php foreach ($h2hGames as $h2h):
                        $homeWon = $h2h['home_points'] > $h2h['away_points'];
                        $h2hUrl = '/nba-wins-platform/stats/game_details.php?home_team=' . urlencode($h2h['home_team_code'])
                                . '&away_team=' . urlencode($h2h['away_team_code'])
                                . '&date=' . urlencode($h2h['date']);
                    ?>
                        <a href="<?= $h2hUrl ?>" class="h2h-game">
                            <span class="h2h-date"><?= date('M j', strtotime($h2h['date'])) ?></span>
                            <div class="h2h-teams">
                                <div class="h2h-team">
                                    <img src="<?= htmlspecialchars(getTeamLogo($h2h['away_team'])) ?>" alt="" class="h2h-team-logo">
                                    <span class="h2h-score <?= !$homeWon ? 'winner' : 'loser' ?>"><?= $h2h['away_points'] ?></span>
                                </div>
                                <span class="h2h-at">@</span>
                                <div class="h2h-team">
                                    <img src="<?= htmlspecialchars(getTeamLogo($h2h['home_team'])) ?>" alt="" class="h2h-team-logo">
                                    <span class="h2h-score <?= $homeWon ? 'winner' : 'loser' ?>"><?= $h2h['home_points'] ?></span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="h2h-empty">No matchups yet this season</div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /.tc-two-col -->

</div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var flippers = document.querySelectorAll('.logo-flip-inner');
        if (flippers.length === 0) return;

        // Flip to owner photo after delay
        setTimeout(function() {
            flippers.forEach(function(el, i) {
                setTimeout(function() { el.classList.add('flipped'); }, i * 200);
            });

            // Hold, then flip back to logo
            setTimeout(function() {
                flippers.forEach(function(el, i) {
                    setTimeout(function() { el.classList.remove('flipped'); }, i * 200);
                });
            }, 2000);
        }, 600);
    });
    </script>

    <?php include '/data/www/default/nba-wins-platform/components/pill_nav.php'; ?>
</body>
</html>