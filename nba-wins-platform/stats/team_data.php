<?php
header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Add session management
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['current_league_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

// Load database connection and NBA API integration
require_once '/data/www/default/nba-wins-platform/config/db_connection.php';
require_once '/data/www/default/nba-wins-platform/core/nba_api_integration.php';

$user_id = $_SESSION['user_id'];
$league_id = $_SESSION['current_league_id'];
$currentLeagueId = $league_id;

// Get team name and validate
$team_name = $_GET['team'] ?? null;
if (!$team_name) {
    die("No team specified");
}
$team_name = str_replace('+', ' ', $team_name);

// Initialize NBA API integration with short timeout
// NOTE: Currently not being used - API calls are disabled below to prevent 30+ second page loads
$nbaApi = new NBAApiIntegration([
    'python_path' => '/usr/bin/python3',
    'scripts_path' => '/data/www/default/nba-wins-platform/tasks',
    'cache_enabled' => true,
    'cache_timeout' => 300, // 5 minutes
    'api_timeout' => 3 // 3 second timeout for fast page loads
]);

// Team logo mapping function
function getTeamLogo($teamName) {
    $logoMap = [
        // Eastern Conference
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
        
        // Western Conference
        'Dallas Mavericks' => 'dallas_mavericks.png',
        'Denver Nuggets' => 'denver_nuggets.png',
        'Golden State Warriors' => 'golden_state_warriors.png',
        'Houston Rockets' => 'houston_rockets.png',
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
        return '/nba-wins-platform/public/assets/team_logos/' . $logoMap[$teamName];
    }
    
    $filename = strtolower(str_replace(' ', '_', $teamName)) . '.png';
    return '/nba-wins-platform/public/assets/team_logos/' . $filename;
}

// Get basic team data from database including streak information
try {
    $stmt = $pdo->prepare("SELECT name, win, loss, streak, winstreak FROM 2025_2026 WHERE name = ?");
    $stmt->execute([$team_name]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$team) {
        die("Team not found: " . htmlspecialchars($team_name));
    }
    
    $team['logo'] = getTeamLogo($team['name']);
    
    // Calculate basic stats
    $win_percentage = ($team['win'] + $team['loss'] > 0) ? ($team['win'] / ($team['win'] + $team['loss'])) * 100 : 0;
    $games_played = $team['win'] + $team['loss'];
    $games_remaining = 82 - $games_played;
    $current_pace = $games_played > 0 ? ($team['win'] / $games_played) * 82 : 0;
    
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

// Include TeamStatsCalculator for database-driven stats
require_once '/data/www/default/nba-wins-platform/core/TeamStatsCalculator.php';

// Initialize stats calculator (no API timeouts!)
$statsCalculator = new TeamStatsCalculator($pdo);

// Get comprehensive stats from database (instant response)
$liveStats = $statsCalculator->getTeamStats($team_name);
$statsError = null;

// Check if stats are available
if (!$liveStats || $liveStats['GP'] == 0) {
    // No games yet
    $liveStats = null;
    $statsError = "Stats will be available after the first regular season game";
} else {
    // MANUAL FG% CALCULATION - API's fg_pct is not accurate
    // Calculate FG% from FGM (field goals made) and FGA (field goals attempted)
    if (isset($liveStats['FGM']) && isset($liveStats['FGA']) && $liveStats['FGA'] > 0) {
        $liveStats['FG_PCT'] = $liveStats['FGM'] / $liveStats['FGA'];
    }
    
    // Also recalculate 3PT% if needed
    if (isset($liveStats['FG3M']) && isset($liveStats['FG3A']) && $liveStats['FG3A'] > 0) {
        $liveStats['FG3_PCT'] = $liveStats['FG3M'] / $liveStats['FG3A'];
    }
    
    // Recalculate FT% if needed
    if (isset($liveStats['FTM']) && isset($liveStats['FTA']) && $liveStats['FTA'] > 0) {
        $liveStats['FT_PCT'] = $liveStats['FTM'] / $liveStats['FTA'];
    }
}

// Get last 5 games with results
$lastGames = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM (
            SELECT 
                g.date as game_date,
                g.home_team,
                g.away_team,
                g.home_team_code,
                g.away_team_code,
                g.home_points,
                g.away_points,
                CASE 
                    WHEN g.home_team = ? THEN 'home'
                    WHEN g.away_team = ? THEN 'away'
                END as team_location,
                CASE 
                    WHEN g.home_team = ? THEN g.away_team
                    WHEN g.away_team = ? THEN g.home_team
                END as opponent,
                CASE 
                    WHEN (g.home_team = ? AND g.home_points > g.away_points) OR 
                         (g.away_team = ? AND g.away_points > g.home_points) THEN 'W'
                    WHEN g.home_points IS NOT NULL THEN 'L'
                    ELSE NULL
                END as result
            FROM games g
            WHERE (g.home_team = ? OR g.away_team = ?)
            AND g.status_long IN ('Final', 'Finished')
            AND g.date >= '2025-10-21'
            ORDER BY g.date DESC
            LIMIT 5
        ) AS recent_games
        ORDER BY game_date ASC
    ");
    $stmt->execute([
        $team_name, $team_name, $team_name, $team_name, 
        $team_name, $team_name, $team_name, $team_name
    ]);
    $lastGames = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Look up owner for each opponent team
    foreach ($lastGames as &$game) {
        $ownerStmt = $pdo->prepare("
            SELECT u.display_name
            FROM draft_picks dp
            JOIN nba_teams nt ON dp.team_id = nt.id
            JOIN league_participants lp ON dp.league_participant_id = lp.id
            JOIN users u ON lp.user_id = u.id
            WHERE nt.name = ? AND lp.league_id = ?
            LIMIT 1
        ");
        $ownerStmt->execute([$game['opponent'], $league_id]);
        $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
        $game['opponent_owner'] = $owner ? $owner['display_name'] : null;
    }
    unset($game); // Break reference
} catch (Exception $e) {
    error_log("Error fetching last games: " . $e->getMessage());
}

// Get upcoming 5 games
$upcomingGames = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            g.date as game_date,
            g.home_team,
            g.away_team,
            g.home_team_code,
            g.away_team_code,
            CASE 
                WHEN g.home_team = ? THEN 'home'
                WHEN g.away_team = ? THEN 'away'
            END as team_location,
            CASE 
                WHEN g.home_team = ? THEN g.away_team
                WHEN g.away_team = ? THEN g.home_team
            END as opponent
        FROM games g
        WHERE (g.home_team = ? OR g.away_team = ?)
        AND g.status_long = 'Scheduled'
        AND g.date >= '2025-10-21'
        ORDER BY g.date ASC
        LIMIT 5
    ");
    $stmt->execute([
        $team_name, $team_name, $team_name, $team_name,
        $team_name, $team_name
    ]);
    $upcomingGames = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Look up owner for each opponent team
    foreach ($upcomingGames as &$game) {
        $ownerStmt = $pdo->prepare("
            SELECT u.display_name
            FROM draft_picks dp
            JOIN nba_teams nt ON dp.team_id = nt.id
            JOIN league_participants lp ON dp.league_participant_id = lp.id
            JOIN users u ON lp.user_id = u.id
            WHERE nt.name = ? AND lp.league_id = ?
            LIMIT 1
        ");
        $ownerStmt->execute([$game['opponent'], $league_id]);
        $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
        $game['opponent_owner'] = $owner ? $owner['display_name'] : null;
    }
    unset($game); // Break reference
} catch (Exception $e) {
    error_log("Error fetching upcoming games: " . $e->getMessage());
}

// Get full schedule if on schedule tab
$fullSchedule = null;
if (isset($_GET['tab']) && $_GET['tab'] === 'schedule') {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                g.date as game_date,
                g.home_team,
                g.away_team,
                g.home_team_code,
                g.away_team_code,
                g.home_points,
                g.away_points,
                g.status_long,
                CASE 
                    WHEN g.home_team = ? THEN 'home'
                    WHEN g.away_team = ? THEN 'away'
                END as team_location,
                CASE 
                    WHEN g.home_team = ? THEN g.away_team
                    WHEN g.away_team = ? THEN g.home_team
                END as opponent,
                CASE 
                    WHEN (g.home_team = ? AND g.home_points > g.away_points) OR 
                         (g.away_team = ? AND g.away_points > g.home_points) THEN 'W'
                    WHEN g.home_points IS NOT NULL THEN 'L'
                    ELSE NULL
                END as result
            FROM games g
            WHERE (g.home_team = ? OR g.away_team = ?)
            AND g.date >= '2025-10-21'
            ORDER BY g.date ASC
        ");
        $stmt->execute([
            $team_name, $team_name, $team_name, $team_name, 
            $team_name, $team_name, $team_name, $team_name
        ]);
        $fullSchedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Look up owner and top scorers for each team
        foreach ($fullSchedule as $index => &$game) {
            // Get owner
            $ownerStmt = $pdo->prepare("
                SELECT u.display_name
                FROM draft_picks dp
                JOIN nba_teams nt ON dp.team_id = nt.id
                JOIN league_participants lp ON dp.league_participant_id = lp.id
                JOIN users u ON lp.user_id = u.id
                WHERE nt.name = ? AND lp.league_id = ?
                LIMIT 1
            ");
            $ownerStmt->execute([$game['opponent'], $league_id]);
            $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
            $game['opponent_owner'] = $owner ? $owner['display_name'] : null;
            
            // Calculate record up to this game (for completed games)
            if (in_array($game['status_long'], ['Final', 'Finished'])) {
                $wins = 0;
                $losses = 0;
                
                // Count all completed games up to and including this one
                for ($i = 0; $i <= $index; $i++) {
                    if (in_array($fullSchedule[$i]['status_long'], ['Final', 'Finished'])) {
                        if ($fullSchedule[$i]['result'] === 'W') {
                            $wins++;
                        } elseif ($fullSchedule[$i]['result'] === 'L') {
                            $losses++;
                        }
                    }
                }
                
                $game['record'] = $wins . '-' . $losses;
            }
            
            // Get top scorers for completed games
            if (in_array($game['status_long'], ['Final', 'Finished'])) {
                // Normalize team names for LA Clippers
                $userTeam = ($game['team_location'] === 'home') ? $game['home_team'] : $game['away_team'];
                $opponentTeam = $game['opponent'];
                
                // Check for LA Clippers variations
                $userTeamVariations = [$userTeam];
                $opponentTeamVariations = [$opponentTeam];
                
                if (strpos($userTeam, 'Clippers') !== false) {
                    $userTeamVariations = ['LA Clippers', 'Los Angeles Clippers'];
                }
                if (strpos($opponentTeam, 'Clippers') !== false) {
                    $opponentTeamVariations = ['LA Clippers', 'Los Angeles Clippers'];
                }
                
                // Get top scorer for user's team
                $placeholders = implode(',', array_fill(0, count($userTeamVariations), '?'));
                $topScorerStmt = $pdo->prepare("
                    SELECT player_name, points
                    FROM game_player_stats
                    WHERE team_name IN ($placeholders) AND game_date = ?
                    ORDER BY points DESC
                    LIMIT 1
                ");
                $params = array_merge($userTeamVariations, [$game['game_date']]);
                $topScorerStmt->execute($params);
                $game['user_top_scorer'] = $topScorerStmt->fetch(PDO::FETCH_ASSOC);
                
                // Get top scorer for opponent team
                $placeholders = implode(',', array_fill(0, count($opponentTeamVariations), '?'));
                $oppScorerStmt = $pdo->prepare("
                    SELECT player_name, points
                    FROM game_player_stats
                    WHERE team_name IN ($placeholders) AND game_date = ?
                    ORDER BY points DESC
                    LIMIT 1
                ");
                $params = array_merge($opponentTeamVariations, [$game['game_date']]);
                $oppScorerStmt->execute($params);
                $game['opp_top_scorer'] = $oppScorerStmt->fetch(PDO::FETCH_ASSOC);
            }
        }
        unset($game); // Break reference
    } catch (Exception $e) {
        error_log("Error fetching full schedule: " . $e->getMessage());
    }
}

// Get roster if on roster tab - UPDATED TO USE team_roster_stats with jersey numbers
$roster = null;
if (isset($_GET['tab']) && $_GET['tab'] === 'roster') {
    try {
        // Get roster with stats from team_roster_stats, sorted by PPG
        if (strpos($team_name, 'Clippers') !== false) {
            // For Clippers, check both variations
            $stmt = $pdo->prepare("
                SELECT 
                    trs.player_name,
                    trs.games_played,
                    trs.avg_minutes,
                    trs.avg_points,
                    trs.avg_rebounds,
                    trs.avg_assists,
                    trs.avg_fg_made,
                    trs.avg_fg_attempts,
                    trs.fg_percentage
                FROM team_roster_stats trs
                WHERE (trs.current_team_name = 'LA Clippers' OR trs.current_team_name = 'Los Angeles Clippers')
                ORDER BY trs.avg_points DESC
            ");
            $stmt->execute();
        } else {
            // For all other teams, use standard query
            $stmt = $pdo->prepare("
                SELECT 
                    trs.player_name,
                    trs.games_played,
                    trs.avg_minutes,
                    trs.avg_points,
                    trs.avg_rebounds,
                    trs.avg_assists,
                    trs.avg_fg_made,
                    trs.avg_fg_attempts,
                    trs.fg_percentage
                FROM team_roster_stats trs
                WHERE trs.current_team_name = ?
                ORDER BY trs.avg_points DESC
            ");
            $stmt->execute([$team_name]);
        }
        $roster_players = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($roster_players)) {
            $roster = ['success' => true, 'data' => $roster_players];
        } else {
            $roster = ['error' => 'No roster data available'];
        }
    } catch (Exception $e) {
        error_log("Roster error: " . $e->getMessage());
        $roster = ['error' => 'Error loading roster'];
    }
}

// Get draft information
$draft_info = null;
try {
    $stmt = $pdo->prepare("
        SELECT ds.id, ds.status, ds.completed_at
        FROM draft_sessions ds
        WHERE ds.league_id = ? AND ds.status = 'completed'
        ORDER BY ds.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$league_id]);
    $draft_session = $stmt->fetch();
    
    if ($draft_session) {
        $stmt = $pdo->prepare("
            SELECT 
                dp.pick_number,
                dp.round_number,
                dp.picked_at,
                dp.picked_by_commissioner,
                lp.participant_name,
                u.display_name,
                (dp.pick_number - 1) % (SELECT COUNT(DISTINCT league_participant_id) FROM draft_picks WHERE draft_session_id = ?) + 1 as position_in_round
            FROM draft_picks dp
            JOIN nba_teams nt ON dp.team_id = nt.id
            JOIN league_participants lp ON dp.league_participant_id = lp.id
            JOIN users u ON lp.user_id = u.id
            WHERE dp.draft_session_id = ? AND nt.name = ?
            LIMIT 1
        ");
        $stmt->execute([$draft_session['id'], $draft_session['id'], $team_name]);
        $draft_info = $stmt->fetch();
    }
} catch (Exception $e) {
    error_log("Error fetching draft info: " . $e->getMessage());
}

// Check if API dependencies are available
$apiStatus = $nbaApi->checkDependencies();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($team['name']); ?> - Team Data</title>
    <link rel="apple-touch-icon" type="image/png" href="/nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="/nba-wins-platform/public/assets/favicon/favicon.png">
    <link href="https://cdn.tailwindcss.com" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
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
        --hover-color: #757575;
        --success-color: #4CAF50;
        --warning-color: #FF9800;
        --error-color: #F44336;
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        line-height: 1.6;
        margin: 0;
        padding: 20px;
        background-image: url('/nba-wins-platform/public/assets/background/geometric_white.png');
        background-repeat: repeat;
        background-attachment: fixed;
        background-color: #f5f5f5;
    }
    
    .container {
        max-width: 1400px;
        margin: 0 auto;
        background-color: white;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        position: relative;
    }

    .team-header {
        background: linear-gradient(rgba(0,0,0,0.7), rgba(0,0,0,0.7));
        padding: 2rem;
        color: white;
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 2rem;
        min-height: 150px;
        margin-bottom: 2rem;
        border-radius: 8px;
        background-color: #333;
    }
    
    .team-header img {
        width: 100px;
        height: 100px;
        object-fit: contain;
    }
    
    .team-info {
        text-align: left;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }

    .stat-card {
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 1rem;
        text-align: center;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        border-left: 4px solid var(--primary-color);
    }

    .stat-card.live {
        border-left-color: var(--success-color);
        background-color: #f1f8e9;
    }

    .stat-card.error {
        border-left-color: var(--error-color);
        background-color: #ffebee;
    }

    .projection-value {
        font-size: 1.8rem;
        font-weight: bold;
        color: #333;
        margin-bottom: 0.5rem;
    }

    .projection-label {
        color: #666;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .live-indicator {
        color: var(--success-color);
        font-size: 0.8rem;
    }

    .error-indicator {
        color: var(--error-color);
        font-size: 0.8rem;
    }
    
    .section-title {
        font-size: 1.5rem;
        font-weight: bold;
        margin: 2rem 0 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #eee;
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .tabs {
        display: flex;
        gap: 1rem;
        border-bottom: 2px solid #eee;
        padding-bottom: 1rem;
        margin-bottom: 2rem;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .tab {
        padding: 0.5rem 2rem;
        border-radius: 0.5rem;
        color: #666;
        text-decoration: none;
        transition: all 0.2s;
    }
    
    .tab.active {
        background-color: var(--primary-color);
        color: white;
    }
    
    .tab:hover {
        background-color: var(--hover-color);
        color: white;
    }
    
    .no-data {
        text-align: center;
        padding: 2rem;
        color: #666;
        background-color: #f8f9fa;
        border-radius: 8px;
        margin: 1rem 0;
    }

    .roster-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 1rem;
        margin-top: 1rem;
    }

    .player-card {
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 1rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        transition: transform 0.2s;
    }

    .player-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }

    .player-name {
        font-weight: bold;
        font-size: 1.1rem;
        margin-bottom: 0.5rem;
    }

    .player-position {
        color: #666;
        font-size: 0.9rem;
        margin-bottom: 0.5rem;
    }

    .player-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.5rem;
        font-size: 0.85rem;
    }

    .status-banner {
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 2rem;
        text-align: center;
    }

    .status-banner.warning {
        background-color: #fff3cd;
        border: 1px solid #ffeaa7;
        color: #856404;
    }

    .status-banner.error {
        background-color: #f8d7da;
        border: 1px solid #f5c6cb;
        color: #721c24;
    }

    .status-banner.success {
        background-color: #d4edda;
        border: 1px solid #c3e6cb;
        color: #155724;
    }

    .refresh-button {
        background-color: var(--primary-color);
        color: white;
        border: none;
        padding: 0.5rem 1rem;
        border-radius: 4px;
        cursor: pointer;
        margin-left: 1rem;
    }

    .refresh-button:hover {
        background-color: var(--secondary-color);
    }

    /* Menu styles - matching other files */
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
    
    .close-button {
        background: none;
        border: none;
        color: var(--text-color);
        cursor: pointer;
        padding: 0.5rem;
    }
    
    .close-button:hover {
        color: var(--hover-color);
    }
    
    .menu-content {
        padding: 1rem;
    }
    
    .menu-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .menu-link {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 1rem;
        color: var(--text-color);
        text-decoration: none;
        transition: background-color 0.2s;
        border-radius: 4px;
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
            padding: 15px;
        }
        
        .team-header {
            flex-direction: column;
            padding: 1.5rem;
        }
        
        .team-header img {
            width: 80px;
            height: 80px;
        }

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }

        .projection-value {
            font-size: 1.5rem;
        }

        .roster-grid {
            grid-template-columns: 1fr;
        }
        
        /* Tabs - Mobile */
        .tabs {
            gap: 0.75rem;
            padding-bottom: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .tab {
            padding: 0.4rem 1.25rem;
            font-size: 0.85rem;
            white-space: nowrap;
        }
        
        .tab i {
            font-size: 0.8rem;
        }
        
        /* Roster Grid Enhanced - Mobile */
        .roster-grid-enhanced {
            grid-template-columns: 1fr;
            gap: 15px;
        }
        
        .player-header {
            gap: 10px;
        }
        
        .player-icon-enhanced {
            font-size: 2rem !important;
            min-width: 50px !important;
        }
        
        /* Schedule Mobile Optimization */
        .games-list {
            padding: 0.75rem;
        }
        
        .game-list-item {
            padding: 0.75rem 0.5rem;
            flex-direction: column;
            align-items: stretch !important;
        }
        
        .game-list-info {
            margin-bottom: 0.5rem;
        }
        
        .game-list-date {
            font-size: 0.75rem;
            margin-bottom: 0.25rem;
        }
        
        .game-list-matchup {
            font-size: 0.85rem;
            flex-wrap: wrap;
            line-height: 1.4;
        }
        
        .game-list-matchup img {
            width: 16px !important;
            height: 16px !important;
        }
        
        .game-list-result {
            text-align: left;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.5rem;
        }
        
        .game-list-score {
            font-size: 0.95rem;
        }
        
        .game-list-outcome {
            font-size: 0.8rem;
            margin-left: 0.25rem;
        }
        
        .top-scorers {
            flex-direction: column;
            gap: 0.4rem;
            font-size: 0.7rem;
            margin-top: 0.5rem;
            padding-top: 0.5rem;
        }
        
        .scorer-line {
            gap: 0.25rem;
        }
        
        .section-title {
            font-size: 1.1rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .section-title a {
            font-size: 0.8rem !important;
            margin-left: 0 !important;
        }
    }
    /* Game Card Styles */
    .games-container {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 1rem;
        margin-top: 1rem;
    }
    
    .game-card {
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 1.5rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        transition: transform 0.2s;
        border-left: 4px solid var(--border-color);
    }
    
    .game-card.win {
        border-left-color: var(--success-color);
        background-color: #f1f8e9;
    }
    
    .game-card.loss {
        border-left-color: var(--error-color);
        background-color: #ffebee;
    }
    
    .game-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }
    
    .game-date {
        font-size: 0.9rem;
        color: #666;
        margin-bottom: 0.5rem;
    }
    
    .game-matchup {
        font-size: 1.1rem;
        font-weight: bold;
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .game-location {
        font-size: 0.8rem;
        color: #888;
        text-transform: uppercase;
    }
    
    .game-result {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid var(--border-color);
    }
    
    .game-score {
        font-size: 1.5rem;
        font-weight: bold;
    }
    
    .game-outcome {
        font-size: 1.5rem;
        font-weight: bold;
    }
    
    .game-outcome.win {
        color: var(--success-color);
    }
    
    .game-outcome.loss {
        color: var(--error-color);
    }
    
    /* Game List Styles */
    .games-list {
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 1.5rem;
        margin-top: 1rem;
    }
    
    .game-list-item {
        padding: 1rem;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: background-color 0.2s;
        text-decoration: none;
        color: inherit;
        cursor: pointer;
    }
    
    .game-list-item.clickable:hover {
        background-color: rgba(0, 0, 0, 0.05);
        cursor: pointer;
    }
    
    .game-list-item:last-child {
        border-bottom: none;
    }
    
    .game-list-item:hover {
        background-color: rgba(0, 0, 0, 0.03);
    }
    
    .game-list-info {
        flex: 1;
    }
    
    .game-list-date {
        font-size: 0.85rem;
        color: #666;
        margin-bottom: 0.25rem;
    }
    
    .game-list-matchup {
        font-weight: 600;
        font-size: 1rem;
    }
    
    .game-list-result {
        text-align: right;
        font-weight: bold;
    }
    
    .game-list-score {
        font-size: 1.1rem;
        margin-bottom: 0.25rem;
    }
    
    .game-list-outcome {
        font-size: 0.9rem;
        margin-left: 0.5rem;
    }
    
    .game-list-outcome.w {
        color: var(--success-color);
    }
    
    .game-list-outcome.l {
        color: var(--error-color);
    }
    
    /* Top Scorers Display */
    .top-scorers {
        font-size: 0.8rem;
        color: #666;
        margin-top: 0.5rem;
        padding-top: 0.5rem;
        border-top: 1px solid #e0e0e0;
        display: flex;
        gap: 1.5rem;
    }
    
    .scorer-line {
        display: flex;
        align-items: center;
        gap: 0.3rem;
    }
    
    .scorer-points {
        font-weight: bold;
        color: var(--primary-color);
    }
    
    /* Enhanced Roster Styles with Stats */
        .roster-grid-enhanced {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .player-card-enhanced {
            background: #f8f9fa;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 20px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .player-card-enhanced:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
            border-color: var(--primary-color);
        }
        
        .player-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
        }
        
        .player-icon-enhanced {
            font-size: 2.5rem;
            color: var(--primary-color);
            min-width: 60px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .player-info {
            flex: 1;
        }
        
        .player-name-enhanced {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .player-games {
            font-size: 0.9rem;
            color: #666;
        }
        
        .player-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }
        
        .stat-item {
            text-align: center;
            padding: 8px;
            background: white;
            border-radius: 5px;
        }
        
        .stat-value {
            font-size: 1.3rem;
            font-weight: bold;
            color: var(--primary-color);
        }
        
        .stat-label {
            font-size: 0.75rem;
            color: #666;
            text-transform: uppercase;
            margin-top: 2px;
        }
</style>
</head>
<body>
    <?php 
    // Include the navigation menu component
    include $_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/components/navigation_menu.php'; 
    ?>
    
    <div class="container">
        <!-- API Status Banner -->
        <?php if ($statsError): ?>
        <div class="status-banner warning">
            <i class="fas fa-info-circle"></i>
            Live statistics: <?php echo htmlspecialchars($statsError); ?>
        </div>
        <?php elseif (isset($liveStats['success']) && $liveStats['success']): ?>
        <div class="status-banner success">
            <i class="fas fa-check-circle"></i>
            <strong>Live Stats Active:</strong> Data updated <?php echo date('g:i A', $liveStats['timestamp']); ?>
        </div>
        <?php endif; ?>
        
        <!-- DEBUG: Team Name Matching (only when ?debug in URL) -->
        <?php if (isset($_GET['debug'])): ?>
        <div class="status-banner warning">
            <strong>DEBUG - Team Name Matching:</strong><br>
            <strong>Searching for:</strong> "<?php echo htmlspecialchars($team_name); ?>"<br>
            <?php
            // Show all unique team names in games table
            $debugStmt = $pdo->prepare("
                SELECT DISTINCT home_team FROM games 
                UNION 
                SELECT DISTINCT away_team FROM games 
                ORDER BY home_team
            ");
            $debugStmt->execute();
            $allTeamsInGames = $debugStmt->fetchAll(PDO::FETCH_COLUMN);
            echo "<strong>Teams in games table:</strong> " . htmlspecialchars(implode(', ', $allTeamsInGames));
            
            // Debug: Show the actual column names in games table
            $debugColumnsStmt = $pdo->query("SHOW COLUMNS FROM games");
            $columns = $debugColumnsStmt->fetchAll(PDO::FETCH_COLUMN);
            echo "<br><strong>Columns in games table:</strong> " . htmlspecialchars(implode(', ', $columns));
            
            // Show a sample game
            $sampleStmt = $pdo->prepare("SELECT * FROM games WHERE home_team = ? OR away_team = ? LIMIT 1");
            $sampleStmt->execute([$team_name, $team_name]);
            $sampleGame = $sampleStmt->fetch(PDO::FETCH_ASSOC);
            if ($sampleGame) {
                echo "<br><strong>Sample game data:</strong> " . htmlspecialchars(json_encode($sampleGame));
            }
            ?>
        </div>
        <?php endif; ?>
        
        <!-- Tabs -->
        <div class="tabs">
            <a href="?team=<?php echo urlencode($team['name']); ?>&tab=home" 
               class="tab <?php echo (!isset($_GET['tab']) || $_GET['tab'] === 'home') ? 'active' : ''; ?>">
                <i class="fas fa-home"></i> Home
            </a>
            <a href="?team=<?php echo urlencode($team['name']); ?>&tab=roster" 
               class="tab <?php echo (isset($_GET['tab']) && $_GET['tab'] === 'roster') ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> Roster
            </a>
            <a href="?team=<?php echo urlencode($team['name']); ?>&tab=schedule" 
               class="tab <?php echo (isset($_GET['tab']) && $_GET['tab'] === 'schedule') ? 'active' : ''; ?>">
                <i class="fas fa-calendar"></i> Schedule
            </a>
        </div>

        <!-- Home Tab -->
        <?php if (!isset($_GET['tab']) || $_GET['tab'] === 'home'): ?>
        
        <div class="team-header">
            <img src="<?php echo htmlspecialchars($team['logo']); ?>" 
                 alt="<?php echo htmlspecialchars($team['name']); ?>"
                 onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwIiBoZWlnaHQ9IjEwMCIgdmlld0JveD0iMCAwIDEwMCAxMDAiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PGNpcmNsZSBjeD0iNTAiIGN5PSI1MCIgcj0iNDAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSI0Ii8+PHRleHQgeD0iNTAiIHk9IjU1IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmb250LXNpemU9IjE2IiBmaWxsPSIjMzMzMzMzIj4/PC90ZXh0Pjwvc3ZnPgo='">
            <div class="team-info">
                <h1 class="text-4xl font-bold mb-2"><?php echo htmlspecialchars($team['name']); ?></h1>
                <?php if ($draft_info): ?>
                    <p class="text-xl">
                        Round <?php echo $draft_info['round_number']; ?>, Pick <?php echo $draft_info['position_in_round']; ?> 
                        (Overall #<?php echo $draft_info['pick_number']; ?>)
                        <br>
                        <span class="opacity-75">
                            Drafted by <?php echo htmlspecialchars($draft_info['display_name']); ?>
                            <?php if ($draft_info['picked_by_commissioner']): ?>
                                <span style="color: #FF7F00;">(Commissioner Pick)</span>
                            <?php endif; ?>
                        </span>
                    </p>
                <?php else: ?>
                    <p class="text-xl opacity-75">Draft information not available</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Team Record Stats -->
        <h2 class="section-title">
            <i class="fas fa-trophy"></i>
            Season Record
        </h2>
        <div class="stats-grid mb-6">
            <div class="stat-card">
                <div class="projection-label">Record</div>
                <div class="projection-value"><?php echo $team['win']; ?>-<?php echo $team['loss']; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="projection-label">Win Percentage</div>
                <div class="projection-value"><?php echo number_format($win_percentage, 1); ?>%</div>
            </div>
            
            <div class="stat-card">
                <div class="projection-label">Games Remaining</div>
                <div class="projection-value"><?php echo $games_remaining; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="projection-label">Current Pace</div>
                <div class="projection-value"><?php echo number_format($current_pace, 1); ?> wins</div>
            </div>
            
            <?php 
            // Display streak information
            $streak = $team['streak'] ?? 0;
            $winstreak = $team['winstreak'] ?? 0;
            
            if ($streak != 0 || $winstreak != 0): 
                $isWinStreak = ($winstreak == 1);
                $streakLength = abs($streak);
                $streakLabel = $isWinStreak ? 'Win Streak' : 'Loss Streak';
                
                // Color logic: Green for win streak, Blue for loss streak, Red for 10+ game loss streak
                if ($isWinStreak) {
                    $streakColor = 'var(--success-color)'; // Green
                    $streakBgColor = '#f1f8e9'; // Light green background
                } else {
                    // Loss streak - check if it's 10+ games
                    if ($streakLength >= 10) {
                        $streakColor = 'var(--error-color)'; // Red for major loss streaks
                        $streakBgColor = '#ffebee'; // Light red background
                    } else {
                        $streakColor = '#2196F3'; // Blue for normal loss streaks
                        $streakBgColor = '#e3f2fd'; // Light blue background
                    }
                }
                
                $streakIcon = $isWinStreak ? 'fa-fire' : 'fa-snowflake';
            ?>
            <div class="stat-card" style="border-left-color: <?php echo $streakColor; ?>; background-color: <?php echo $streakBgColor; ?>">
                <div class="projection-label">
                    <i class="fa-solid <?php echo $streakIcon; ?>" style="color: <?php echo $streakColor; ?>;"></i>
                    <?php echo $streakLabel; ?>
                </div>
                <div class="projection-value" style="color: <?php echo $streakColor; ?>;">
                    <?php echo $streakLength; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

<!-- Live Team Statistics - ENHANCED & FIXED -->
        <?php if ($liveStats && $liveStats['GP'] > 0): ?>
        <h2 class="section-title">
            <i class="fas fa-basketball-ball"></i>
            Live Team Statistics
            <?php if (isset($liveStats['data_source']) && $liveStats['data_source'] === 'api_enhanced'): ?>
                <span class="live-indicator"><i class="fas fa-circle"></i> LIVE</span>
            <?php endif; ?>
        </h2>
        
        <!-- Shooting Stats -->
        <h3 style="font-size: 1.3rem; margin: 1.5rem 0 1rem 0; color: var(--primary-color);">
            <i class="fas fa-bullseye"></i> Shooting
        </h3>
        <div class="stats-grid mb-6">
            <div class="stat-card live">
                <div class="projection-label">Points Per Game</div>
                <div class="projection-value"><?php echo number_format($liveStats['PTS'], 1); ?></div>
            </div>
            
            <div class="stat-card live">
                <div class="projection-label">Field Goal %</div>
                <div class="projection-value"><?php echo number_format($liveStats['FG_PCT'] * 100, 1); ?>%</div>
            </div>
            
            <div class="stat-card live">
                <div class="projection-label">3-Point %</div>
                <div class="projection-value"><?php echo number_format($liveStats['FG3_PCT'] * 100, 1); ?>%</div>
            </div>
            
            <div class="stat-card live">
                <div class="projection-label">Free Throw %</div>
                <div class="projection-value"><?php echo number_format($liveStats['FT_PCT'] * 100, 1); ?>%</div>
            </div>
            
            <div class="stat-card live">
                <div class="projection-label">3-Pointers Made</div>
                <div class="projection-value"><?php echo number_format($liveStats['FG3M'], 1); ?></div>
            </div>
            
            <div class="stat-card live">
                <div class="projection-label">3-Point Attempts</div>
                <div class="projection-value"><?php echo number_format($liveStats['FG3A'], 1); ?></div>
            </div>
        </div>
        
        <!-- Core Stats -->
        <h3 style="font-size: 1.3rem; margin: 1.5rem 0 1rem 0; color: var(--primary-color);">
            <i class="fas fa-chart-line"></i> Core Stats
        </h3>
        <div class="stats-grid mb-6">
            <div class="stat-card live">
                <div class="projection-label">Rebounds Per Game</div>
                <div class="projection-value"><?php echo number_format($liveStats['REB'], 1); ?></div>
            </div>
            
            <div class="stat-card live">
                <div class="projection-label">Assists Per Game</div>
                <div class="projection-value"><?php echo number_format($liveStats['AST'], 1); ?></div>
            </div>
            
            <div class="stat-card live">
                <div class="projection-label">Steals Per Game</div>
                <div class="projection-value"><?php echo number_format($liveStats['STL'], 1); ?></div>
            </div>
            
            <div class="stat-card live">
                <div class="projection-label">Blocks Per Game</div>
                <div class="projection-value"><?php echo number_format($liveStats['BLK'], 1); ?></div>
            </div>
            
            <div class="stat-card live">
                <div class="projection-label">Turnovers Per Game</div>
                <div class="projection-value"><?php echo number_format($liveStats['TOV'], 1); ?></div>
            </div>
            
            <div class="stat-card live">
                <div class="projection-label">Plus/Minus Per Game</div>
                <div class="projection-value"><?php 
                    $pm = $liveStats['PLUS_MINUS'];
                    $color = $pm >= 0 ? '#10b981' : '#ef4444';
                    echo '<span style="color: ' . $color . ';">' . ($pm >= 0 ? '+' : '') . number_format($pm, 1) . '</span>';
                ?></div>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Show "Data coming soon" when stats aren't available -->
        <h2 class="section-title">
            <i class="fas fa-basketball-ball"></i>
            Live Team Statistics
        </h2>
        <div class="stats-grid mb-6">
            <div class="stat-card">
                <div class="projection-label">Points Per Game</div>
                <div class="projection-value" style="font-size: 1.2rem; color: #666;">Data coming soon</div>
            </div>
            
            <div class="stat-card">
                <div class="projection-label">Field Goal %</div>
                <div class="projection-value" style="font-size: 1.2rem; color: #666;">Data coming soon</div>
            </div>
            
            <div class="stat-card">
                <div class="projection-label">3-Point %</div>
                <div class="projection-value" style="font-size: 1.2rem; color: #666;">Data coming soon</div>
            </div>
            
            <div class="stat-card">
                <div class="projection-label">Rebounds Per Game</div>
                <div class="projection-value" style="font-size: 1.2rem; color: #666;">Data coming soon</div>
            </div>
            
            <div class="stat-card">
                <div class="projection-label">Assists Per Game</div>
                <div class="projection-value" style="font-size: 1.2rem; color: #666;">Data coming soon</div>
            </div>
            
            <div class="stat-card">
                <div class="projection-label">Plus/Minus</div>
                <div class="projection-value" style="font-size: 1.2rem; color: #666;">Data coming soon</div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Last 5 Games Section -->
        <h2 class="section-title">
            <i class="fas fa-history"></i>
            Last 5 Games
            <a href="?team=<?php echo urlencode($team['name']); ?>&tab=schedule" 
               style="margin-left: auto; font-size: 0.9rem; color: var(--primary-color); text-decoration: none; font-weight: normal;">
                View Full Schedule →
            </a>
        </h2>
        
        <?php if (!empty($lastGames)): ?>
        <div class="games-list">
            <?php foreach ($lastGames as $game): 
                $teamScore = ($game['team_location'] === 'home') ? $game['home_points'] : $game['away_points'];
                $oppScore = ($game['team_location'] === 'home') ? $game['away_points'] : $game['home_points'];
                $gameUrl = "/nba-wins-platform/stats/game_details.php?home_team=" . urlencode($game['home_team_code']) . "&away_team=" . urlencode($game['away_team_code']) . "&date=" . urlencode($game['game_date']);
            ?>
            <a href="<?php echo $gameUrl; ?>" class="game-list-item clickable" style="display: flex;">
                <div class="game-list-info">
                    <div class="game-list-date">
                        <?php echo date('M j, Y', strtotime($game['game_date'])); ?>
                    </div>
                    <div class="game-list-matchup">
                        <?php echo $game['team_location'] === 'home' ? 'vs' : '@'; ?>
                        <img src="<?php echo htmlspecialchars(getTeamLogo($game['opponent'])); ?>" 
                             alt="<?php echo htmlspecialchars($game['opponent']); ?>" 
                             style="width: 20px; height: 20px; vertical-align: middle; margin: 0 5px;"
                             onerror="this.style.display='none'">
                        <?php echo htmlspecialchars($game['opponent']); ?>
                        <?php if (!empty($game['opponent_owner'])): ?>
                            <span style="font-size: 0.85rem; color: #666; font-weight: normal;">
                                (<?php echo htmlspecialchars($game['opponent_owner']); ?>)
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="game-list-result">
                    <div class="game-list-score"><?php echo $teamScore . '-' . $oppScore; ?></div>
                    <div class="game-list-outcome <?php echo strtolower($game['result']); ?>">
                        <?php echo $game['result']; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="no-data">
            <p>No recent games to display</p>
        </div>
        <?php endif; ?>
        
        <!-- Upcoming 5 Games Section -->
        <h2 class="section-title">
            <i class="fas fa-calendar-alt"></i>
            Upcoming 5 Games
        </h2>
        
        <?php if (!empty($upcomingGames)): ?>
        <div class="games-list">
            <?php foreach ($upcomingGames as $game): 
                $comparisonUrl = "/nba-wins-platform/stats/team_comparison.php?home_team=" . urlencode($game['home_team_code']) . "&away_team=" . urlencode($game['away_team_code']) . "&date=" . urlencode($game['game_date']);
            ?>
            <a href="<?php echo $comparisonUrl; ?>" class="game-list-item clickable" style="display: flex;">
                <div class="game-list-info">
                    <div class="game-list-date">
                        <?php echo date('M j, Y', strtotime($game['game_date'])); ?>
                    </div>
                    <div class="game-list-matchup">
                        <?php echo $game['team_location'] === 'home' ? 'vs' : '@'; ?>
                        <img src="<?php echo htmlspecialchars(getTeamLogo($game['opponent'])); ?>" 
                             alt="<?php echo htmlspecialchars($game['opponent']); ?>" 
                             style="width: 20px; height: 20px; vertical-align: middle; margin: 0 5px;"
                             onerror="this.style.display='none'">
                        <?php echo htmlspecialchars($game['opponent']); ?>
                        <?php if (!empty($game['opponent_owner'])): ?>
                            <span style="font-size: 0.85rem; color: #666; font-weight: normal;">
                                (<?php echo htmlspecialchars($game['opponent_owner']); ?>)
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="no-data">
            <p>No upcoming games scheduled</p>
        </div>
        <?php endif; ?>
        
        <!-- Roster Tab - SIMPLIFIED DISPLAY -->
        <?php elseif (isset($_GET['tab']) && $_GET['tab'] === 'roster'): ?>
        
        <h2 class="section-title">
            <i class="fas fa-users"></i>
            Team Roster
        </h2>
        
        <?php if (isset($roster['error'])): ?>
        <div class="no-data">
            <h3>Team Roster</h3>
            <p style="font-size: 1.2rem; color: #666; margin-top: 1rem;">
                <?php echo htmlspecialchars($roster['error']); ?>
            </p>
        </div>
        <?php elseif (isset($roster['success']) && $roster['success'] && !empty($roster['data'])): ?>
        <div class="roster-grid-enhanced">
            <?php foreach ($roster['data'] as $player): ?>
            <div class="player-card-enhanced">
                <div class="player-header">
                    <div class="player-icon-enhanced">
                        <i class="fa-solid fa-user"></i>
                    </div>
                    <div class="player-info">
                        <div class="player-name-enhanced">
                            <?php echo htmlspecialchars($player['player_name']); ?>
                        </div>
                        <div class="player-games">
                            <?php echo $player['games_played']; ?> Games Played
                        </div>
                    </div>
                </div>
                
                <div class="player-stats-grid">
                    <div class="stat-item">
                        <div class="stat-value"><?php echo number_format($player['avg_points'], 1); ?></div>
                        <div class="stat-label">PPG</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo number_format($player['avg_rebounds'], 1); ?></div>
                        <div class="stat-label">RPG</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo number_format($player['avg_assists'], 1); ?></div>
                        <div class="stat-label">APG</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo number_format($player['avg_minutes'], 1); ?></div>
                        <div class="stat-label">MPG</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo number_format($player['fg_percentage'], 1); ?>%</div>
                        <div class="stat-label">FG%</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo number_format($player['avg_fg_made'], 1); ?>/<?php echo number_format($player['avg_fg_attempts'], 1); ?></div>
                        <div class="stat-label">FGM/A</div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="no-data">
            <h3>Team Roster</h3>
            <p style="font-size: 1.2rem; color: #666; margin-top: 1rem;">Data coming soon</p>
        </div>
        <?php endif; ?>
        
        <!-- Schedule Tab -->
        <?php elseif (isset($_GET['tab']) && $_GET['tab'] === 'schedule'): ?>
        
        <h2 class="section-title">
            <i class="fas fa-calendar"></i>
            Full Schedule
        </h2>
        
        <?php if (!empty($fullSchedule)): ?>
        <div class="games-list">
            <?php foreach ($fullSchedule as $game): 
                $isCompleted = in_array($game['status_long'], ['Final', 'Finished']);
                $teamScore = ($game['team_location'] === 'home') ? $game['home_points'] : $game['away_points'];
                $oppScore = ($game['team_location'] === 'home') ? $game['away_points'] : $game['home_points'];
                
                // Different URLs for completed vs upcoming games
                if ($isCompleted) {
                    $gameUrl = "/nba-wins-platform/stats/game_details.php?home_team=" . urlencode($game['home_team_code']) . "&away_team=" . urlencode($game['away_team_code']) . "&date=" . urlencode($game['game_date']);
                } else {
                    $gameUrl = "/nba-wins-platform/stats/team_comparison.php?home_team=" . urlencode($game['home_team_code']) . "&away_team=" . urlencode($game['away_team_code']) . "&date=" . urlencode($game['game_date']);
                }
            ?>
            <a href="<?php echo $gameUrl; ?>" class="game-list-item clickable" style="display: flex; flex-direction: column; align-items: stretch;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div class="game-list-info">
                        <div class="game-list-date">
                            <?php echo date('M j, Y', strtotime($game['game_date'])); ?>
                        </div>
                        <div class="game-list-matchup">
                            <?php echo $game['team_location'] === 'home' ? 'vs' : '@'; ?>
                            <img src="<?php echo htmlspecialchars(getTeamLogo($game['opponent'])); ?>" 
                                 alt="<?php echo htmlspecialchars($game['opponent']); ?>" 
                                 style="width: 20px; height: 20px; vertical-align: middle; margin: 0 5px;"
                                 onerror="this.style.display='none'">
                            <?php echo htmlspecialchars($game['opponent']); ?>
                            <?php if (!empty($game['opponent_owner'])): ?>
                                <span style="font-size: 0.85rem; color: #666; font-weight: normal;">
                                    (<?php echo htmlspecialchars($game['opponent_owner']); ?>)
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($isCompleted): ?>
                    <div class="game-list-result">
                        <div class="game-list-score"><?php echo $teamScore . '-' . $oppScore; ?></div>
                        <div class="game-list-outcome <?php echo strtolower($game['result']); ?>">
                            <?php echo $game['result']; ?>
                            <?php if (!empty($game['record'])): ?>
                                <span style="font-size: 0.85rem; color: #888; font-weight: normal; margin-left: 0.5rem;">
                                    (<?php echo $game['record']; ?>)
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($isCompleted && (!empty($game['user_top_scorer']) || !empty($game['opp_top_scorer']))): ?>
                <div class="top-scorers">
                    <?php if (!empty($game['user_top_scorer'])): ?>
                    <div class="scorer-line">
                        <span><?php echo htmlspecialchars($game['user_top_scorer']['player_name']); ?>:</span>
                        <span class="scorer-points"><?php echo $game['user_top_scorer']['points']; ?> pts</span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($game['opp_top_scorer'])): ?>
                    <div class="scorer-line">
                        <span style="color: #999;"><?php echo htmlspecialchars($game['opp_top_scorer']['player_name']); ?>:</span>
                        <span class="scorer-points" style="color: #666;"><?php echo $game['opp_top_scorer']['points']; ?> pts</span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="no-data">
            <p>No games found in the schedule</p>
        </div>
        <?php endif; ?>
        
        <?php endif; ?>
        
        <!-- Debug Information (only in development) -->
        <?php if (isset($_GET['debug'])): ?>
        <div class="section-title">Debug Information</div>
        <div style="background: #f8f9fa; padding: 1rem; border-radius: 8px; font-family: monospace; font-size: 0.8rem; margin: 1rem 0;">
            <strong>API Status:</strong><br>
            Python: <?php echo $apiStatus['python'] ? '✓' : '✗'; ?><br>
            NBA API: <?php echo $apiStatus['nba_api'] ? '✓' : '✗'; ?><br>
            Scripts: <?php echo json_encode($apiStatus['scripts']); ?><br>
            <br>
            <strong>Team Data:</strong><br>
            <?php echo htmlspecialchars(json_encode($liveStats, JSON_PRETTY_PRINT)); ?>
        </div>
        <?php endif; ?>
    </div>

</body>
</html>