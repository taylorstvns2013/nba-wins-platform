<?php
/**
 * team_comparison_new.php - Game Preview / Team Comparison
 * 
 * Displays a head-to-head matchup preview between two teams including:
 *   - Team logos, records, and draft owners
 *   - Game time and arena info
 *   - Side-by-side team stats comparison with highlighting
 * 
 * Path: /data/www/default/nba-wins-platform/stats/team_comparison_new.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('America/New_York');

// =====================================================================
// AUTH & LEAGUE CONTEXT
// =====================================================================
require_once '/data/www/default/nba-wins-platform/config/db_connection.php';
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
            SELECT COALESCE(u2.display_name, lp2.participant_name)
            FROM league_participant_teams lpt2
            JOIN league_participants lp2 ON lpt2.league_participant_id = lp2.id
            LEFT JOIN users u2 ON lp2.user_id = u2.id
            WHERE lpt2.team_name = nt2.name AND lp2.league_id = ?
            LIMIT 1
        ) AS away_participant
    FROM games g
    JOIN nba_teams nt1 ON g.home_team = nt1.name
    JOIN nba_teams nt2 ON g.away_team = nt2.name
    LEFT JOIN 2025_2026 t1 ON nt1.name = t1.name
    LEFT JOIN 2025_2026 t2 ON nt2.name = t2.name
    WHERE g.home_team_code = ?
      AND g.away_team_code = ?
      AND DATE(g.start_time) = ?
    LIMIT 1
");
$stmt->execute([$currentLeagueId, $currentLeagueId, $home_team, $away_team, $game_date]);
$game = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$game) {
    die("Game not found");
}

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
    --bg-primary: #121a23;
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
.app-container { max-width: 900px; margin: 0 auto; padding: 0 12px 2rem; }

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
    overflow: hidden;
    min-height: 90px;
    display: flex;
    align-items: center;
    background: transparent;
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
    background: linear-gradient(to right, rgba(48, 62, 80, 0.7) 0%, rgba(32, 42, 56, 0) 75%);
}
.team-row.away-team::before {
    background: linear-gradient(to left, rgba(48, 62, 80, 0.7) 0%, rgba(32, 42, 56, 0) 75%);
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
    gap: 0.6rem;
    padding: 0.4rem 0.85rem;
    background: rgba(118, 165, 175, 0.15);
    color: var(--text-secondary);
    border-radius: var(--radius-md);
    font-weight: 500;
    font-size: 0.78rem;
    margin-top: 0.65rem;
}
.game-time-info .divider {
    color: var(--text-muted);
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
   MOBILE RESPONSIVE
   ========================================================================== */
@media (max-width: 600px) {
    .app-container { padding: 0 8px 2rem; }

    .matchup-header { padding: 1rem; border-radius: var(--radius-md); }
    .team-row { padding: 1rem 0.75rem; min-height: 70px; }
    .team-logo-visible { width: 48px; height: 48px; }
    .team-logo-background { width: 120px; height: 120px; }
    .team-name-small { font-size: 0.95rem; }
    .team-record { font-size: 1.25rem; }

    .stat-value { font-size: 0.9rem; padding: 0.75rem 0.5rem; }
    .stat-label { font-size: 0.8rem; padding: 0.75rem 0.5rem; }
}
    /* ===== FLOATING PILL NAV ===== */
    .floating-pill { position: fixed; bottom: 12px; left: 50%; z-index: 9999; display: flex; align-items: center; gap: 2px; background: rgba(32, 42, 56, 0.95); border: 1px solid var(--border-color); border-radius: 999px; padding: 5px; box-shadow: 0 4px 24px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.04); -webkit-backdrop-filter: blur(16px); backdrop-filter: blur(16px); -webkit-transform: translateX(-50%) translateZ(0); transform: translateX(-50%) translateZ(0); will-change: transform; }
    body { padding-bottom: 76px; }
    @media (max-width: 600px) { .floating-pill { bottom: calc(8px + env(safe-area-inset-bottom, 0px)); } }
    .pill-item { display: flex; align-items: center; justify-content: center; width: 42px; height: 42px; border-radius: 999px; text-decoration: none; color: var(--text-muted); font-size: 16px; transition: all 0.15s ease; cursor: pointer; border: none; background: none; -webkit-tap-highlight-color: transparent; position: relative; }
    .pill-item:hover { color: var(--text-primary); background: var(--bg-elevated); }
    .pill-item.active { color: white; background: var(--accent-blue); }
    .pill-item:active { transform: scale(0.92); }
    .pill-divider { width: 1px; height: 24px; background: var(--border-color); flex-shrink: 0; }
    @media (min-width: 601px) { .pill-item::after { content: attr(data-label); position: absolute; bottom: calc(100% + 8px); left: 50%; transform: translateX(-50%) scale(0.9); background: var(--bg-elevated); color: var(--text-primary); font-size: 11px; font-weight: 600; font-family: 'Outfit', sans-serif; padding: 4px 10px; border-radius: 6px; white-space: nowrap; opacity: 0; pointer-events: none; transition: all 0.15s ease; border: 1px solid var(--border-color); } .pill-item:hover::after { opacity: 1; transform: translateX(-50%) scale(1); } }
</style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/components/navigation_menu_new.php'; ?>

<div class="app-container">

    <!-- ================================================================
         HEADER
         ================================================================ -->


    <!-- ================================================================
         MATCHUP HEADER
         ================================================================ -->
    <div class="matchup-header">

        <!-- Home Team -->
        <div class="team-row home-team">
            <img src="<?= htmlspecialchars(getTeamLogo($game['home_team_name'])) ?>" alt=""
                 class="team-logo-background" onerror="this.style.display='none'">
            <div class="team-info-left">
                <a href="/nba-wins-platform/stats/team_data_new.php?team=<?= urlencode($game['home_team_name']) ?>">
                    <img src="<?= htmlspecialchars(getTeamLogo($game['home_team_name'])) ?>"
                         alt="<?= htmlspecialchars($game['home_team_name']) ?>"
                         class="team-logo-visible" onerror="this.style.opacity='0.3'">
                </a>
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
                <a href="/nba-wins-platform/stats/team_data_new.php?team=<?= urlencode($game['away_team_name']) ?>">
                    <img src="<?= htmlspecialchars(getTeamLogo($game['away_team_name'])) ?>"
                         alt="<?= htmlspecialchars($game['away_team_name']) ?>"
                         class="team-logo-visible" onerror="this.style.opacity='0.3'">
                </a>
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

    <!-- ================================================================
         STATS COMPARISON
         ================================================================ -->
    <div class="stats-section">
        <h2 class="section-title">Team Stats</h2>

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

</div>
    <nav class="floating-pill">
        <a href="/index_new.php" class="pill-item" data-label="Home"><i class="fas fa-home"></i></a>
        <a href="/nba-wins-platform/profiles/participant_profile_new.php?league_id=<?php echo $currentLeagueId ?? ($_SESSION['current_league_id'] ?? 0); ?>&user_id=<?php echo $profileUserId ?? ($_SESSION['user_id'] ?? 0); ?>" class="pill-item" data-label="Profile"><i class="fas fa-user"></i></a>
        <a href="/analytics_new.php" class="pill-item" data-label="Analytics"><i class="fas fa-chart-line"></i></a>
        <a href="/claudes-column_new.php" class="pill-item" data-label="Column" style="position:relative"><i class="fa-solid fa-newspaper"></i><?php if ($hasNewArticles): ?><span style="position:absolute;top:2px;right:2px;width:7px;height:7px;background:#f85149;border-radius:50%;box-shadow:0 0 4px rgba(248,81,73,0.5)"></span><?php endif; ?></a>
        <div class="pill-divider"></div>
        <button class="pill-item" data-label="Menu" onclick="toggleDarkNav()"><i class="fas fa-bars"></i></button>
    </nav>
</body>
</html>