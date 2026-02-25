<?php
// Set timezone to EST
date_default_timezone_set('America/New_York');

// Start session and check authentication
session_start();

// Get current league and user context
$current_league_id = isset($_SESSION['current_league_id']) ? $_SESSION['current_league_id'] : '';
$current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : '';

$user_id = $_SESSION['user_id'];
$league_id = $_SESSION['current_league_id'];
$currentLeagueId = $league_id;

// Use centralized database connection
require_once '/data/www/default/nba-wins-platform/config/db_connection.php';

// ==================== NBA DIVISIONS DEFINITION ====================
$nba_divisions = [
    'Eastern Conference' => [
        'Atlantic' => ['Boston Celtics', 'Brooklyn Nets', 'New York Knicks', 'Philadelphia 76ers', 'Toronto Raptors'],
        'Central' => ['Chicago Bulls', 'Cleveland Cavaliers', 'Detroit Pistons', 'Indiana Pacers', 'Milwaukee Bucks'],
        'Southeast' => ['Atlanta Hawks', 'Charlotte Hornets', 'Miami Heat', 'Orlando Magic', 'Washington Wizards']
    ],
    'Western Conference' => [
        'Northwest' => ['Denver Nuggets', 'Minnesota Timberwolves', 'Oklahoma City Thunder', 'Portland Trail Blazers', 'Utah Jazz'],
        'Pacific' => ['Golden State Warriors', 'Los Angeles Clippers', 'LA Clippers', 'Los Angeles Lakers', 'Phoenix Suns', 'Sacramento Kings'],
        'Southwest' => ['Dallas Mavericks', 'Houston Rockets', 'Memphis Grizzlies', 'New Orleans Pelicans', 'San Antonio Spurs']
    ]
];

$team_to_division = [];
$team_to_conference = [];
foreach ($nba_divisions as $conference => $divisions) {
    foreach ($divisions as $division => $teams) {
        foreach ($teams as $team) {
            $team_to_division[$team] = $division;
            $team_to_conference[$team] = $conference;
        }
    }
}

// ==================== HELPER FUNCTIONS FOR TIE BREAKERS ====================

function getHeadToHeadRecord($pdo, $team1_name, $team2_name) {
    $stmt = $pdo->prepare("
        SELECT home_team, away_team, home_points, away_points
        FROM games 
        WHERE status_long = 'Final'
        AND DATE(start_time) >= '2025-10-21'
        AND (
            (home_team = ? AND away_team = ?)
            OR (home_team = ? AND away_team = ?)
        )
    ");
    $stmt->execute([$team1_name, $team2_name, $team2_name, $team1_name]);
    $games = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $team1_wins = 0;
    $team2_wins = 0;
    
    foreach ($games as $game) {
        if ($game['home_team'] === $team1_name) {
            if ($game['home_points'] > $game['away_points']) $team1_wins++;
            else $team2_wins++;
        } else {
            if ($game['away_points'] > $game['home_points']) $team1_wins++;
            else $team2_wins++;
        }
    }
    
    return ['team1_wins' => $team1_wins, 'team2_wins' => $team2_wins];
}

function getDivisionRecord($pdo, $team_name, $division_teams) {
    $placeholders = str_repeat('?,', count($division_teams) - 1) . '?';
    
    $stmt = $pdo->prepare("
        SELECT home_team, away_team, home_points, away_points
        FROM games 
        WHERE status_long = 'Final'
        AND DATE(start_time) >= '2025-10-21'
        AND (
            (home_team = ? AND away_team IN ($placeholders))
            OR (away_team = ? AND home_team IN ($placeholders))
        )
    ");
    
    $params = array_merge([$team_name], $division_teams, [$team_name], $division_teams);
    $stmt->execute($params);
    $games = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $wins = 0; $losses = 0;
    foreach ($games as $game) {
        if ($game['home_team'] === $team_name && $game['away_team'] === $team_name) continue;
        if ($game['home_team'] === $team_name) {
            if ($game['home_points'] > $game['away_points']) $wins++; else $losses++;
        } else {
            if ($game['away_points'] > $game['home_points']) $wins++; else $losses++;
        }
    }
    return ['wins' => $wins, 'losses' => $losses];
}

function getConferenceRecord($pdo, $team_name, $conference_teams) {
    $placeholders = str_repeat('?,', count($conference_teams) - 1) . '?';
    
    $stmt = $pdo->prepare("
        SELECT home_team, away_team, home_points, away_points
        FROM games 
        WHERE status_long = 'Final'
        AND DATE(start_time) >= '2025-10-21'
        AND (
            (home_team = ? AND away_team IN ($placeholders))
            OR (away_team = ? AND home_team IN ($placeholders))
        )
    ");
    
    $params = array_merge([$team_name], $conference_teams, [$team_name], $conference_teams);
    $stmt->execute($params);
    $games = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $wins = 0; $losses = 0;
    foreach ($games as $game) {
        if ($game['home_team'] === $team_name && $game['away_team'] === $team_name) continue;
        if ($game['home_team'] === $team_name) {
            if ($game['home_points'] > $game['away_points']) $wins++; else $losses++;
        } else {
            if ($game['away_points'] > $game['home_points']) $wins++; else $losses++;
        }
    }
    return ['wins' => $wins, 'losses' => $losses];
}

function getPointDifferential($pdo, $team_name) {
    $stmt = $pdo->prepare("
        SELECT home_team, away_team, home_points, away_points
        FROM games 
        WHERE status_long = 'Final'
        AND DATE(start_time) >= '2025-10-21'
        AND (home_team = ? OR away_team = ?)
    ");
    $stmt->execute([$team_name, $team_name]);
    $games = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $points_for = 0; $points_against = 0;
    foreach ($games as $game) {
        if ($game['home_team'] === $team_name) {
            $points_for += $game['home_points'];
            $points_against += $game['away_points'];
        } else {
            $points_for += $game['away_points'];
            $points_against += $game['home_points'];
        }
    }
    return $points_for - $points_against;
}

function compareTwoTeams($pdo, $team1, $team2, $team_to_division, $team_to_conference, $nba_divisions, $all_conference_teams) {
    if ($team1['win'] != $team2['win']) return $team2['win'] - $team1['win'];
    if ($team1['loss'] != $team2['loss']) return $team1['loss'] - $team2['loss'];
    
    $h2h = getHeadToHeadRecord($pdo, $team1['name'], $team2['name']);
    if ($h2h['team1_wins'] != $h2h['team2_wins']) return $h2h['team2_wins'] - $h2h['team1_wins'];
    
    $team1_division = $team_to_division[$team1['name']] ?? null;
    $team2_division = $team_to_division[$team2['name']] ?? null;
    
    if ($team1_division === $team2_division && $team1_division !== null) {
        $division_teams = [];
        foreach ($nba_divisions as $conf => $divs) {
            foreach ($divs as $div => $teams) {
                if ($div === $team1_division) { $division_teams = $teams; break 2; }
            }
        }
        $team1_div_record = getDivisionRecord($pdo, $team1['name'], $division_teams);
        $team2_div_record = getDivisionRecord($pdo, $team2['name'], $division_teams);
        $team1_div_pct = ($team1_div_record['wins'] + $team1_div_record['losses'] > 0) 
            ? $team1_div_record['wins'] / ($team1_div_record['wins'] + $team1_div_record['losses']) : 0;
        $team2_div_pct = ($team2_div_record['wins'] + $team2_div_record['losses'] > 0) 
            ? $team2_div_record['wins'] / ($team2_div_record['wins'] + $team2_div_record['losses']) : 0;
        if (abs($team1_div_pct - $team2_div_pct) > 0.001) return ($team1_div_pct > $team2_div_pct) ? -1 : 1;
    }
    
    $team1_conf_record = getConferenceRecord($pdo, $team1['name'], $all_conference_teams);
    $team2_conf_record = getConferenceRecord($pdo, $team2['name'], $all_conference_teams);
    $team1_conf_pct = ($team1_conf_record['wins'] + $team1_conf_record['losses'] > 0) 
        ? $team1_conf_record['wins'] / ($team1_conf_record['wins'] + $team1_conf_record['losses']) : 0;
    $team2_conf_pct = ($team2_conf_record['wins'] + $team2_conf_record['losses'] > 0) 
        ? $team2_conf_record['wins'] / ($team2_conf_record['wins'] + $team2_conf_record['losses']) : 0;
    if (abs($team1_conf_pct - $team2_conf_pct) > 0.001) return ($team1_conf_pct > $team2_conf_pct) ? -1 : 1;
    
    $team1_diff = getPointDifferential($pdo, $team1['name']);
    $team2_diff = getPointDifferential($pdo, $team2['name']);
    if ($team1_diff != $team2_diff) return $team2_diff - $team1_diff;
    
    return 0;
}

function applyTieBreakers($pdo, $teams, $conference, $team_to_division, $team_to_conference, $nba_divisions) {
    $all_conference_teams = [];
    foreach ($teams as $team) $all_conference_teams[] = $team['name'];
    
    usort($teams, function($a, $b) use ($pdo, $team_to_division, $team_to_conference, $nba_divisions, $all_conference_teams) {
        return compareTwoTeams($pdo, $a, $b, $team_to_division, $team_to_conference, $nba_divisions, $all_conference_teams);
    });
    return $teams;
}

function getPlayoffStatus($index) {
    return ($index >= 10) ? 'eliminated' : '';
}

// ==================== FETCH AND PROCESS TEAM DATA ====================

try {
    $stmt = $pdo->query("
        SELECT 
            name, logo, win, loss,
            ROUND((win / (win + loss)) * 100, 1) as win_percentage,
            streak, winstreak, conference
        FROM 2025_2026 
        ORDER BY conference ASC, win DESC
    ");
    $teamRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $nbaCupChampion = 'New York Knicks';
    $hasNbaCupChampion = false;
    foreach ($teamRecords as &$team) {
        $team['nba_cup_champion'] = ($team['name'] === $nbaCupChampion);
        if ($team['nba_cup_champion']) $hasNbaCupChampion = true;
    }
    unset($team);
} catch(PDOException $e) {
    die("Could not connect to the database: " . $e->getMessage());
}

function getTeamLogo($teamName) {
    $logoMap = [
        'Atlanta Hawks' => 'atlanta_hawks.png',
        'Boston Celtics' => 'boston_celtics.png',
        'Brooklyn Nets' => 'brooklyn_nets.png',
        'Charlotte Hornets' => 'charlotte_hornets.png',
        'Chicago Bulls' => 'chicago_bulls.png',
        'Cleveland Cavaliers' => 'cleveland_cavaliers.png',
        'Detroit Pistons' => 'detroit_pistons.png',
        'Indiana Pacers' => 'indiana_pacers.png',
        'Miami Heat' => 'miami_heat.png',
        'Milwaukee Bucks' => 'milwaukee_bucks.png',
        'New York Knicks' => 'new_york_knicks.png',
        'Orlando Magic' => 'orlando_magic.png',
        'Philadelphia 76ers' => 'philadelphia_76ers.png',
        'Toronto Raptors' => 'toronto_raptors.png',
        'Washington Wizards' => 'washington_wizards.png',
        'Dallas Mavericks' => 'dallas_mavericks.png',
        'Denver Nuggets' => 'denver_nuggets.png',
        'Golden State Warriors' => 'golden_state_warriors.png',
        'Houston Rockets' => 'houston_rockets.png',
        'LA Clippers' => 'la_clippers.png',
        'Los Angeles Clippers' => 'la_clippers.png',
        'Los Angeles Lakers' => 'los_angeles_lakers.png',
        'Memphis Grizzlies' => 'memphis_grizzlies.png',
        'Minnesota Timberwolves' => 'minnesota_timberwolves.png',
        'New Orleans Pelicans' => 'new_orleans_pelicans.png',
        'Oklahoma City Thunder' => 'oklahoma_city_thunder.png',
        'Phoenix Suns' => 'phoenix_suns.png',
        'Portland Trail Blazers' => 'portland_trail_blazers.png',
        'Sacramento Kings' => 'sacramento_kings.png',
        'San Antonio Spurs' => 'san_antonio_spurs.png',
        'Utah Jazz' => 'utah_jazz.png'
    ];
    if (isset($logoMap[$teamName])) return 'nba-wins-platform/public/assets/team_logos/' . $logoMap[$teamName];
    $filename = strtolower(str_replace(' ', '_', $teamName)) . '.png';
    return 'nba-wins-platform/public/assets/team_logos/' . $filename;
}

$eastTeams = [];
$westTeams = [];
foreach ($teamRecords as $team) {
    if ($team['conference'] === 'east') $eastTeams[] = $team;
    elseif ($team['conference'] === 'west') $westTeams[] = $team;
}

$eastTeams = applyTieBreakers($pdo, $eastTeams, 'Eastern Conference', $team_to_division, $team_to_conference, $nba_divisions);
$westTeams = applyTieBreakers($pdo, $westTeams, 'Western Conference', $team_to_division, $team_to_conference, $nba_divisions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="theme-color" content="<?= ($_SESSION['theme_preference'] ?? 'dark') === 'classic' ? '#f5f5f5' : '#121a23' ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NBA Standings</title>
    <link rel="apple-touch-icon" type="image/png" href="nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    :root {
        --bg-primary: #121a23;
        --bg-secondary: #1a222c;
        --bg-card: #202a38;
        --bg-card-hover: #273140;
        --bg-elevated: #2a3446;
        --border-color: rgba(255, 255, 255, 0.08);
        --border-subtle: rgba(255, 255, 255, 0.05);
        --text-primary: #e6edf3;
        --text-secondary: #8b949e;
        --text-muted: #545d68;
        --accent-blue: #388bfd;
        --accent-blue-dim: rgba(56, 139, 253, 0.15);
        --accent-green: #3fb950;
        --accent-red: #f85149;
        --accent-gold: #f0c644;
        --accent-silver: #a0aec0;
        --accent-bronze: #cd7f32;
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

    * { margin: 0; padding: 0; box-sizing: border-box; }

    html { background-color: var(--bg-primary); }

    body {
        font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
        line-height: 1.5;
        color: var(--text-primary);
        background: var(--bg-primary);
        background-image: radial-gradient(ellipse at 50% 0%, rgba(56, 139, 253, 0.04) 0%, transparent 60%);
        min-height: 100vh;
        -webkit-font-smoothing: antialiased;
    }

    .app-container {
        max-width: 1000px;
        margin: 0 auto;
        padding: 0 12px 2rem;
    }

    /* Header */
    .app-header {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 16px 16px 12px;
        position: relative;
    }

    .nav-toggle-btn {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        width: 38px;
        height: 38px;
        display: flex;
        align-items: center;
        justify-content: center;
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

    .app-header-logo { width: 36px; height: 36px; }

    .app-header-title {
        font-size: 1.35rem;
        font-weight: 700;
        letter-spacing: -0.02em;
    }

    /* Page title */
    .page-title {
        font-size: 1.35rem;
        font-weight: 700;
        letter-spacing: -0.02em;
        text-align: center;
        padding: 16px 0 12px;
    }

    /* Conference layout */
    .conference-container {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }

    .conference {
        flex: 1;
        min-width: 300px;
        background: var(--bg-card);
        border-radius: var(--radius-lg);
        overflow: hidden;
        box-shadow: var(--shadow-card);
    }

    .conference-title {
        padding: 12px 16px;
        font-size: 15px;
        font-weight: 700;
        letter-spacing: 0.02em;
        border-bottom: 2px solid;
    }

    .eastern .conference-title {
        color: #5b9aff;
        border-bottom-color: #1d428a;
    }

    .western .conference-title {
        color: #ff6b6b;
        border-bottom-color: #c8102e;
    }

    /* Table */
    .team-records {
        width: 100%;
        border-collapse: collapse;
    }

    .team-records th {
        padding: 8px 12px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-muted);
        background: var(--bg-elevated);
        border-bottom: 1px solid var(--border-color);
        white-space: nowrap;
    }

    .team-records td {
        padding: 10px 12px;
        border-bottom: 1px solid var(--border-subtle);
        font-size: 14px;
    }

    .team-records tbody tr {
        transition: background var(--transition-fast);
    }

    .team-records tbody tr:hover {
        background: var(--bg-card-hover);
    }

    .team-records tbody tr:last-child td {
        border-bottom: none;
    }

    /* Playoff cutoff line after 6th */
    .team-records tbody tr:nth-child(6) td {
        border-bottom: 1px dashed rgba(255, 255, 255, 0.15);
    }

    /* Play-in cutoff after 10th */
    .team-records tbody tr.eliminated {
        opacity: 0.4;
    }

    /* Column widths */
    .team-records th:nth-child(1),
    .team-records td:nth-child(1) {
        width: 40px;
        text-align: center;
    }

    .team-records th:nth-child(2),
    .team-records td:nth-child(2) {
        text-align: left;
        padding-left: 12px;
    }

    .team-records th:nth-child(3),
    .team-records td:nth-child(3) {
        width: 80px;
        text-align: center;
    }

    .team-records th:nth-child(4),
    .team-records td:nth-child(4) {
        width: 70px;
        text-align: center;
    }

    .rank-number {
        font-weight: 700;
        color: var(--text-muted);
        font-variant-numeric: tabular-nums;
    }

    /* Top 3 rank colors */
    .team-records tbody tr:nth-child(1) .rank-number { color: var(--accent-gold); }
    .team-records tbody tr:nth-child(2) .rank-number { color: var(--accent-silver); }
    .team-records tbody tr:nth-child(3) .rank-number { color: var(--accent-bronze); }

    .team-name { display: flex; align-items: center; }

    .team-name a {
        display: flex;
        align-items: center;
        color: var(--text-primary);
        text-decoration: none;
        transition: color var(--transition-fast);
        font-weight: 500;
    }

    .team-name a:hover { color: var(--accent-blue); }

    .team-logo {
        width: 28px;
        height: 28px;
        margin-right: 10px;
        flex-shrink: 0;
    }

    .record-text {
        font-variant-numeric: tabular-nums;
        font-weight: 500;
        color: var(--text-secondary);
    }

    .win-streak {
        color: var(--accent-green);
        font-weight: 700;
        font-size: 13px;
    }

    .lose-streak {
        color: var(--accent-red);
        font-weight: 700;
        font-size: 13px;
    }

    .nba-cup-indicator {
        color: var(--accent-green);
        font-weight: 700;
        font-size: 10px;
        margin-left: 4px;
        vertical-align: super;
    }

    .nba-cup-legend {
        margin-top: 12px;
        padding: 12px 16px;
        background: var(--bg-card);
        border-radius: var(--radius-md);
        box-shadow: var(--shadow-card);
        font-size: 13px;
        color: var(--text-muted);
        text-align: center;
    }

    .nba-cup-legend .indicator {
        color: var(--accent-green);
        font-weight: 700;
    }

    /* Responsive */
    @media (max-width: 700px) {
        .conference-container {
            flex-direction: column;
            gap: 12px;
        }

        .conference { min-width: unset; }

        .team-records th,
        .team-records td {
            padding: 8px 6px;
            font-size: 13px;
        }

        .team-records th:nth-child(1),
        .team-records td:nth-child(1) { width: 32px; }

        .team-records th:nth-child(3),
        .team-records td:nth-child(3) { width: 65px; }

        .team-records th:nth-child(4),
        .team-records td:nth-child(4) { width: 55px; }

        .team-logo { width: 22px; height: 22px; margin-right: 6px; }

        .team-name a { font-size: 13px; }
    }

    @media (min-width: 701px) {
        .app-container { padding: 0 20px 2rem; }
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

    <?php include '/data/www/default/nba-wins-platform/components/navigation_menu_new.php'; ?>

    <div class="app-container">

        <div class="page-title">NBA Standings</div>

        <div class="conference-container">

            <!-- Eastern Conference -->
            <div class="conference eastern">
                <div class="conference-title">Eastern Conference</div>
                <table class="team-records">
                    <thead>
                        <tr>
                            <th></th>
                            <th>Team</th>
                            <th>Record</th>
                            <th>Streak</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eastTeams as $index => $team): ?>
                        <tr class="<?php echo getPlayoffStatus($index); ?>">
                            <td><span class="rank-number"><?php echo $index + 1; ?></span></td>
                            <td class="team-name">
                                <a href="/nba-wins-platform/stats/team_data_new.php?team=<?php echo urlencode($team['name']); ?>">
                                    <img src="<?php echo htmlspecialchars(getTeamLogo($team['name'])); ?>" 
                                         alt="" class="team-logo"
                                         onerror="this.style.opacity='0.3'">
                                    <?php echo htmlspecialchars($team['name']); ?>
                                </a>
                            </td>
                            <td>
                                <span class="record-text">
                                <?php 
                                $displayWins = $team['nba_cup_champion'] ? $team['win'] - 1 : $team['win'];
                                echo $displayWins . '-' . $team['loss']; 
                                if ($team['nba_cup_champion']): ?>
                                    <span class="nba-cup-indicator">+1</span>
                                <?php endif; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($team['streak'] > 0): ?>
                                    <span class="<?php echo $team['winstreak'] == 1 ? 'win-streak' : 'lose-streak'; ?>">
                                        <?php echo $team['winstreak'] == 1 ? 'W' : 'L'; ?><?php echo $team['streak']; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Western Conference -->
            <div class="conference western">
                <div class="conference-title">Western Conference</div>
                <table class="team-records">
                    <thead>
                        <tr>
                            <th></th>
                            <th>Team</th>
                            <th>Record</th>
                            <th>Streak</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($westTeams as $index => $team): ?>
                        <tr class="<?php echo getPlayoffStatus($index); ?>">
                            <td><span class="rank-number"><?php echo $index + 1; ?></span></td>
                            <td class="team-name">
                                <a href="/nba-wins-platform/stats/team_data_new.php?team=<?php echo urlencode($team['name']); ?>">
                                    <img src="<?php echo htmlspecialchars(getTeamLogo($team['name'])); ?>" 
                                         alt="" class="team-logo"
                                         onerror="this.style.opacity='0.3'">
                                    <?php echo htmlspecialchars($team['name']); ?>
                                </a>
                            </td>
                            <td>
                                <span class="record-text">
                                <?php 
                                $displayWins = $team['nba_cup_champion'] ? $team['win'] - 1 : $team['win'];
                                echo $displayWins . '-' . $team['loss']; 
                                if ($team['nba_cup_champion']): ?>
                                    <span class="nba-cup-indicator">+1</span>
                                <?php endif; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($team['streak'] > 0): ?>
                                    <span class="<?php echo $team['winstreak'] == 1 ? 'win-streak' : 'lose-streak'; ?>">
                                        <?php echo $team['winstreak'] == 1 ? 'W' : 'L'; ?><?php echo $team['streak']; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>

        <?php if ($hasNbaCupChampion): ?>
        <div class="nba-cup-legend">
            <span class="indicator">+1</span> indicates NBA Cup Champion. Win is included in win totals for the league but does not impact NBA standings.
        </div>
        <?php endif; ?>

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