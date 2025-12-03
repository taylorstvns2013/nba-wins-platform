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
$currentLeagueId = $league_id; // Define for navigation menu


// Use centralized database connection
require_once '/data/www/default/nba-wins-platform/config/db_connection.php';

// ==================== NBA DIVISIONS DEFINITION ====================
// Hardcoded NBA divisions based on current structure
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

// Create reverse lookup: team name -> division
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

/**
 * Get head-to-head record between two teams
 * Returns ['team1_wins' => X, 'team2_wins' => Y]
 */
function getHeadToHeadRecord($pdo, $team1_name, $team2_name) {
    $stmt = $pdo->prepare("
        SELECT 
            home_team,
            away_team,
            home_points,
            away_points
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
            if ($game['home_points'] > $game['away_points']) {
                $team1_wins++;
            } else {
                $team2_wins++;
            }
        } else {
            if ($game['away_points'] > $game['home_points']) {
                $team1_wins++;
            } else {
                $team2_wins++;
            }
        }
    }
    
    return ['team1_wins' => $team1_wins, 'team2_wins' => $team2_wins];
}

/**
 * Get division record for a team
 * Returns ['wins' => X, 'losses' => Y]
 */
function getDivisionRecord($pdo, $team_name, $division_teams) {
    $placeholders = str_repeat('?,', count($division_teams) - 1) . '?';
    
    $stmt = $pdo->prepare("
        SELECT 
            home_team,
            away_team,
            home_points,
            away_points
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
    
    $wins = 0;
    $losses = 0;
    
    foreach ($games as $game) {
        // Skip games against self
        if ($game['home_team'] === $team_name && $game['away_team'] === $team_name) {
            continue;
        }
        
        if ($game['home_team'] === $team_name) {
            if ($game['home_points'] > $game['away_points']) {
                $wins++;
            } else {
                $losses++;
            }
        } else {
            if ($game['away_points'] > $game['home_points']) {
                $wins++;
            } else {
                $losses++;
            }
        }
    }
    
    return ['wins' => $wins, 'losses' => $losses];
}

/**
 * Get conference record for a team
 */
function getConferenceRecord($pdo, $team_name, $conference_teams) {
    $placeholders = str_repeat('?,', count($conference_teams) - 1) . '?';
    
    $stmt = $pdo->prepare("
        SELECT 
            home_team,
            away_team,
            home_points,
            away_points
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
    
    $wins = 0;
    $losses = 0;
    
    foreach ($games as $game) {
        // Skip games against self
        if ($game['home_team'] === $team_name && $game['away_team'] === $team_name) {
            continue;
        }
        
        if ($game['home_team'] === $team_name) {
            if ($game['home_points'] > $game['away_points']) {
                $wins++;
            } else {
                $losses++;
            }
        } else {
            if ($game['away_points'] > $game['home_points']) {
                $wins++;
            } else {
                $losses++;
            }
        }
    }
    
    return ['wins' => $wins, 'losses' => $losses];
}

/**
 * Get point differential for a team
 */
function getPointDifferential($pdo, $team_name) {
    $stmt = $pdo->prepare("
        SELECT 
            home_team,
            away_team,
            home_points,
            away_points
        FROM games 
        WHERE status_long = 'Final'
        AND DATE(start_time) >= '2025-10-21'
        AND (home_team = ? OR away_team = ?)
    ");
    $stmt->execute([$team_name, $team_name]);
    $games = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $points_for = 0;
    $points_against = 0;
    
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

/**
 * Compare two teams using NBA tie breaker rules
 * Returns: negative if team1 ranks higher, positive if team2 ranks higher
 */
function compareTwoTeams($pdo, $team1, $team2, $team_to_division, $team_to_conference, $nba_divisions, $all_conference_teams) {
    // CRITICAL: Sort by WINS first, then by win percentage
    // This ensures 4-0 ranks above 3-0
    if ($team1['win'] != $team2['win']) {
        // More wins = rank higher = return negative if team1 has more wins
        return $team2['win'] - $team1['win'];
    }
    
    // If wins are equal, sort by losses (fewer losses = better)
    if ($team1['loss'] != $team2['loss']) {
        // Fewer losses = rank higher = return negative if team1 has fewer losses
        return $team1['loss'] - $team2['loss'];
    }
    
    // Only if BOTH wins AND losses are equal, apply tie breakers
    
    // Rule 1: Better head-to-head record
    $h2h = getHeadToHeadRecord($pdo, $team1['name'], $team2['name']);
    if ($h2h['team1_wins'] != $h2h['team2_wins']) {
        return $h2h['team2_wins'] - $h2h['team1_wins'];
    }
    
    // Rule 2: Division leader wins tie over non-division leader
    $team1_division = $team_to_division[$team1['name']] ?? null;
    $team2_division = $team_to_division[$team2['name']] ?? null;
    
    $team1_is_leader = false;
    $team2_is_leader = false;
    
    if ($team1_is_leader && !$team2_is_leader) return -1;
    if ($team2_is_leader && !$team1_is_leader) return 1;
    
    // Rule 3: Division win-loss percentage (only if same division)
    if ($team1_division === $team2_division && $team1_division !== null) {
        $division_teams = [];
        foreach ($nba_divisions as $conf => $divs) {
            foreach ($divs as $div => $teams) {
                if ($div === $team1_division) {
                    $division_teams = $teams;
                    break 2;
                }
            }
        }
        
        $team1_div_record = getDivisionRecord($pdo, $team1['name'], $division_teams);
        $team2_div_record = getDivisionRecord($pdo, $team2['name'], $division_teams);
        
        $team1_div_pct = ($team1_div_record['wins'] + $team1_div_record['losses'] > 0) 
            ? $team1_div_record['wins'] / ($team1_div_record['wins'] + $team1_div_record['losses']) 
            : 0;
        $team2_div_pct = ($team2_div_record['wins'] + $team2_div_record['losses'] > 0) 
            ? $team2_div_record['wins'] / ($team2_div_record['wins'] + $team2_div_record['losses']) 
            : 0;
        
        if (abs($team1_div_pct - $team2_div_pct) > 0.001) {
            return ($team1_div_pct > $team2_div_pct) ? -1 : 1;
        }
    }
    
    // Rule 4: Conference win-loss percentage
    $team1_conf_record = getConferenceRecord($pdo, $team1['name'], $all_conference_teams);
    $team2_conf_record = getConferenceRecord($pdo, $team2['name'], $all_conference_teams);
    
    $team1_conf_pct = ($team1_conf_record['wins'] + $team1_conf_record['losses'] > 0) 
        ? $team1_conf_record['wins'] / ($team1_conf_record['wins'] + $team1_conf_record['losses']) 
        : 0;
    $team2_conf_pct = ($team2_conf_record['wins'] + $team2_conf_record['losses'] > 0) 
        ? $team2_conf_record['wins'] / ($team2_conf_record['wins'] + $team2_conf_record['losses']) 
        : 0;
    
    if (abs($team1_conf_pct - $team2_conf_pct) > 0.001) {
        return ($team1_conf_pct > $team2_conf_pct) ? -1 : 1;
    }
    
    // Rule 7: Point differential
    $team1_diff = getPointDifferential($pdo, $team1['name']);
    $team2_diff = getPointDifferential($pdo, $team2['name']);
    
    if ($team1_diff != $team2_diff) {
        return $team2_diff - $team1_diff;
    }
    
    return 0;
}

/**
 * Apply NBA tie breaker rules to sort teams
 */
function applyTieBreakers($pdo, $teams, $conference, $team_to_division, $team_to_conference, $nba_divisions) {
    $all_conference_teams = [];
    foreach ($teams as $team) {
        $all_conference_teams[] = $team['name'];
    }
    
    usort($teams, function($a, $b) use ($pdo, $team_to_division, $team_to_conference, $nba_divisions, $all_conference_teams) {
        return compareTwoTeams($pdo, $a, $b, $team_to_division, $team_to_conference, $nba_divisions, $all_conference_teams);
    });
    
    return $teams;
}

/**
 * Get playoff status class for styling
 */
function getPlayoffStatus($index) {
    if ($index >= 10) {
        return 'eliminated';
    }
    return '';
}

// ==================== FETCH AND PROCESS TEAM DATA ====================

try {
    $stmt = $pdo->query("
        SELECT 
            name,
            logo,
            win,
            loss,
            ROUND((win / (win + loss)) * 100, 1) as win_percentage,
            streak,
            winstreak,
            conference
        FROM 2025_2026 
        ORDER BY conference ASC, win DESC
    ");
    $teamRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    die("Could not connect to the database $db_name :" . $e->getMessage());
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
    
    if (isset($logoMap[$teamName])) {
        return 'nba-wins-platform/public/assets/team_logos/' . $logoMap[$teamName];
    }
    
    $filename = strtolower(str_replace(' ', '_', $teamName)) . '.png';
    return 'nba-wins-platform/public/assets/team_logos/' . $filename;
}

$eastTeams = [];
$westTeams = [];

foreach ($teamRecords as $team) {
    if ($team['conference'] === 'east') {
        $eastTeams[] = $team;
    } elseif ($team['conference'] === 'west') {
        $westTeams[] = $team;
    }
}

$eastTeams = applyTieBreakers($pdo, $eastTeams, 'Eastern Conference', $team_to_division, $team_to_conference, $nba_divisions);
$westTeams = applyTieBreakers($pdo, $westTeams, 'Western Conference', $team_to_division, $team_to_conference, $nba_divisions);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="theme-color" content="#f5f5f5">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NBA Standings</title>
    <link rel="apple-touch-icon" type="image/png" href="nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <!-- React and Babel for Navigation Component -->
    <script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
<style>
    :root {
        --primary-color: #212121;
        --secondary-color: #424242;
        --background-color: rgba(245, 245, 245, 0.8);
        --text-color: #333333;
        --border-color: #e0e0e0;
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        line-height: 1.6;
        margin: 0;
        padding: 20px;
        background-image: url('nba-wins-platform/public/assets/background/geometric_white.png');
        background-repeat: repeat;
        background-attachment: fixed;
        color: var(--text-color);
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }
    
    .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
        background-color: var(--background-color);
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    header {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        margin-bottom: 10px;
    }
    
    .basketball-logo {
        max-width: 60px;
        margin-bottom: 5px;
    }
    
    h1 {
        margin: 0;
        font-size: 24px;
        color: var(--primary-color);
    }

    h2 {
        color: var(--primary-color);
        margin-bottom: 20px;
    }

    .conference-container {
        margin: 0;
        width: 100%;
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
    }
    
    .conference {
        flex: 1;
        min-width: 300px;
        background-color: white;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    
    .conference h3 {
        color: var(--primary-color);
        margin: 0;
        padding: 15px;
        background-color: white;
    }
    
    .eastern h3 {
        color: #1d428a;
        border-bottom: 2px solid #1d428a;
    }
    
    .western h3 {
        color: #c8102e;
        border-bottom: 2px solid #c8102e;
    }

    .team-records {
        width: 100%;
        border-collapse: collapse;
        background-color: white;
        table-layout: fixed;
    }

    .team-records th,
    .team-records td {
        padding: 12px;
        background-color: white;
        border: none;
    }
    
    .team-records tbody tr {
        border-bottom: 1px solid transparent;
    }

    .team-records th {
        background-color: var(--primary-color);
        color: white;
        font-weight: bold;
        position: sticky;
        top: 0;
        z-index: 1;
        white-space: nowrap;
    }

    /* Playoff status - greyed out teams */
    .team-records tbody tr.eliminated {
        opacity: 0.5;
    }

    /* DASHED LINE after 6th place */
    .team-records tbody tr:nth-child(6) {
        border-bottom: 1px dashed #bbb !important;
    }

    .team-records th:nth-child(1),
    .team-records td:nth-child(1) {
        width: 10%;
        text-align: center;
    }

    .team-records th:nth-child(2),
    .team-records td:nth-child(2) {
        width: 60%;
        text-align: left;
        padding-left: 12px;
    }

    .team-records th:nth-child(3),
    .team-records td:nth-child(3) {
        width: 15%;
        text-align: center;
    }

    .team-records th:nth-child(4),
    .team-records td:nth-child(4) {
        width: 15%;
        text-align: center;
    }

    .rank-number {
        font-weight: bold;
        color: #666;
    }

    .team-logo {
        width: 30px;
        height: 30px;
        vertical-align: middle;
        margin-right: 10px;
    }
    
    .team-name {
        display: flex;
        align-items: center;
    }
    
    .team-name a {
        display: flex;
        align-items: center;
        color: var(--text-color);
        text-decoration: none;
        transition: color 0.2s;
    }
    
    .team-name a:hover {
        color: var(--primary-color);
        text-decoration: underline;
    }
    
    .win-streak {
        color: #28a745;
        font-weight: bold;
    }
    
    .lose-streak {
        color: #dc3545;
        font-weight: bold;
    }

    .menu-container {
        position: fixed;
        top: 0;
        left: 0;
        z-index: 1000;
    }
    
    .menu-button {
        position: fixed;
        top: 1rem;
        left: 1rem;
        background-color: var(--primary-color);
        color: white;
        border: none;
        border-radius: 4px;
        padding: 0.5rem;
        cursor: pointer;
        z-index: 1002;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .menu-button:hover {
        background-color: var(--secondary-color);
    }
    
    .menu-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 1001;
    }
    
    .menu-panel {
        position: fixed;
        top: 0;
        left: -300px;
        width: 300px;
        height: 100vh;
        background-color: white;
        box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        transition: left 0.3s ease;
        z-index: 1002;
    }
    
    .menu-panel.menu-open {
        left: 0;
    }
    
    .menu-header {
        padding: 1rem;
        display: flex;
        justify-content: flex-end;
        border-bottom: 1px solid var(--border-color);
    }
    
    .menu-content {
        padding-top: 4rem;
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    .menu-list {
        list-style: none;
        padding: 0;
        margin: 0;
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    
    .menu-link {
        display: block;
        padding: 0.5rem 1rem;
        color: #374151;
        text-decoration: none;
        transition: background-color 0.2s;
        border-radius: 0.375rem;
    }
    
    .menu-link:hover {
        background-color: var(--background-color);
        color: var(--secondary-color);
    }
    
    .menu-link i {
        width: 20px;
    }

    @media (max-width: 768px) {
        body {
            padding: 10px;
        }

        .container {
            padding: 10px;
            margin: 0;
            border-radius: 0;
        }

        h1 {
            font-size: 24px;
            text-align: center;
        }

        .conference {
            flex: 100%;
            min-width: unset;
            margin: 0;
            border-radius: 0;
        }

        .conference h3 {
            padding: 10px;
            text-align: center;
        }

        .team-records th,
        .team-records td {
            padding: 8px 4px;
            font-size: 13px;
        }

        .team-records th:nth-child(1),
        .team-records td:nth-child(1) {
            width: 8%;
            padding-left: 4px;
            padding-right: 2px;
        }

        .team-records th:nth-child(2),
        .team-records td:nth-child(2) {
            width: 55%;
            padding-left: 4px;
            padding-right: 4px;
        }

        .team-records th:nth-child(3),
        .team-records td:nth-child(3) {
            width: 22%;
            padding-left: 4px;
            padding-right: 4px;
        }
        
        .team-records th:nth-child(4),
        .team-records td:nth-child(4) {
            width: 15%;
            padding-left: 4px;
            padding-right: 4px;
        }

        .team-logo {
            width: 20px;
            height: 20px;
            margin-right: 6px;
            flex-shrink: 0;
        }
        
        .team-name a {
            font-size: 13px;
            line-height: 1.3;
            word-wrap: break-word;
        }

        .rank-number {
            font-size: 13px;
        }
    }
</style>
</head>
<body>
    <?php 
    // Include the navigation menu component
    include $_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/components/navigation_menu.php'; 
    ?>
    <div class="container">
        <header>
            <img src="nba-wins-platform/public/assets/team_logos/Logo.png" alt="NBA Logo" class="basketball-logo">
            <h1>NBA Standings</h1>
        </header>

            <div class="conference-container">
                <div class="conference eastern">
                    <h3>Eastern Conference</h3>
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
                                    <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($team['name']); ?>">
                                        <img src="<?php echo htmlspecialchars(getTeamLogo($team['name'])); ?>" 
                                             alt="<?php echo htmlspecialchars($team['name']); ?> logo" 
                                             class="team-logo"
                                             onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzAiIGhlaWdodD0iMzAiIHZpZXdCb3g9IjAgMCAzMCAzMCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTUiIGN5PSIxNSIgcj0iMTIiIGZpbGw9IiNmM2Y0ZjYiIHN0cm9rZT0iIzM3NDE1MSIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjE1IiB5PSIyMCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzM3NDE1MSI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                                        <?php echo htmlspecialchars($team['name']); ?>
                                    </a>
                                </td>
                                <td><?php echo $team['win'] . '-' . $team['loss']; ?></td>
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
                        
                <div class="conference western">
                    <h3>Western Conference</h3>
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
                                    <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($team['name']); ?>">
                                        <img src="<?php echo htmlspecialchars(getTeamLogo($team['name'])); ?>" 
                                             alt="<?php echo htmlspecialchars($team['name']); ?> logo" 
                                             class="team-logo"
                                             onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzAiIGhlaWdodD0iMzAiIHZpZXdCb3g9IjAgMCAzMCAzMCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTUiIGN5PSIxNSIgcj0iMTIiIGZpbGw9IiNmM2Y0ZjYiIHN0cm9rZT0iIzM3NDE1MSIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjE1IiB5PSIyMCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzM3NDE1MSI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                                        <?php echo htmlspecialchars($team['name']); ?>
                                    </a>
                                </td>
                                <td><?php echo $team['win'] . '-' . $team['loss']; ?></td>
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
    </div>

</body>
</html>
