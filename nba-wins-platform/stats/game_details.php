<?php
// Start session for multi-league support
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone to EST
date_default_timezone_set('America/New_York');

// ============================================================================
// HELPER FUNCTIONS FOR API SCORE FETCHING
// ============================================================================

/**
 * Fetch current game scores from NBA API
 */
function getAPIScores() {
    try {
        // Use the same Python script that's working
        $command = "python3 /data/www/default/nba-wins-platform/tasks/get_games.py 2>&1";
        $output = shell_exec($command);
        
        if (!$output) {
            error_log("getAPIScores: No output from get_games.py");
            return ['scoreboard' => ['games' => []]];
        }
        
        $data = json_decode($output, true);
        
        if (!$data || !isset($data['scoreboard'])) {
            error_log("getAPIScores: Invalid JSON or missing scoreboard: " . substr($output, 0, 200));
            return ['scoreboard' => ['games' => []]];
        }
        
        return $data;
        
    } catch (Exception $e) {
        error_log("getAPIScores error: " . $e->getMessage());
        return ['scoreboard' => ['games' => []]];
    }
}

/**
 * Get latest scores for games, merging database and API data
 */
function getLatestGameScores($games, $api_scores) {
    $latest_scores = [];
    
    if (!isset($api_scores['scoreboard']['games'])) {
        return $latest_scores;
    }
    
    foreach ($games as $game) {
        $game_key = $game['home_team'] . ' vs ' . $game['away_team'];
        
        // Default to database scores
        $latest_scores[$game_key] = [
            'home_points' => $game['home_points'] ?? 0,
            'away_points' => $game['away_points'] ?? 0,
            'status' => $game['status_long'] ?? 'Scheduled',
            'source' => 'database'
        ];
        
        // Try to find matching game in API data
        foreach ($api_scores['scoreboard']['games'] as $api_game) {
            $api_home_team = $api_game['homeTeam']['teamCity'] . ' ' . $api_game['homeTeam']['teamName'];
            $api_away_team = $api_game['awayTeam']['teamCity'] . ' ' . $api_game['awayTeam']['teamName'];
            
            // Match by team names
            if ($api_home_team === $game['home_team'] && $api_away_team === $game['away_team']) {
                // Get status text
                $status = 'Scheduled';
                if ($api_game['gameStatus'] == 1) {
                    $status = 'Scheduled';
                } elseif ($api_game['gameStatus'] == 2) {
                    $status = 'Q' . $api_game['period'];
                    if ($api_game['gameClock']) {
                        $status .= ' - ' . $api_game['gameClock'];
                    }
                } elseif ($api_game['gameStatus'] == 3) {
                    $status = 'Final';
                }
                
                $latest_scores[$game_key] = [
                    'home_points' => $api_game['homeTeam']['score'] ?? 0,
                    'away_points' => $api_game['awayTeam']['score'] ?? 0,
                    'status' => $status,
                    'source' => 'api',
                    'game_status' => $api_game['gameStatus'],
                    'period' => $api_game['period'] ?? 0,
                    'clock' => $api_game['gameClock'] ?? ''
                ];
                break;
            }
        }
    }
    
    return $latest_scores;
}

/**
 * Get quarter-by-quarter scores from API data
 */
function getQuarterScores($home_team, $away_team, $api_scores) {
    if (!isset($api_scores['scoreboard']['games'])) {
        return [];
    }
    
    foreach ($api_scores['scoreboard']['games'] as $api_game) {
        $api_home_team = $api_game['homeTeam']['teamCity'] . ' ' . $api_game['homeTeam']['teamName'];
        $api_away_team = $api_game['awayTeam']['teamCity'] . ' ' . $api_game['awayTeam']['teamName'];
        
        if ($api_home_team === $home_team && $api_away_team === $away_team) {
            $home_quarters = [];
            $away_quarters = [];
            $home_overtimes = [];
            $away_overtimes = [];
            
            // Extract quarter and overtime scores
            foreach ($api_game['homeTeam']['periods'] as $period) {
                if ($period['periodType'] === 'REGULAR') {
                    $home_quarters[] = $period['score'];
                } elseif ($period['periodType'] === 'OVERTIME') {
                    $home_overtimes[] = $period['score'];
                }
            }
            
            foreach ($api_game['awayTeam']['periods'] as $period) {
                if ($period['periodType'] === 'REGULAR') {
                    $away_quarters[] = $period['score'];
                } elseif ($period['periodType'] === 'OVERTIME') {
                    $away_overtimes[] = $period['score'];
                }
            }
            
            return [
                'home' => $home_quarters,
                'away' => $away_quarters,
                'home_overtimes' => $home_overtimes,
                'away_overtimes' => $away_overtimes,
                'home_total' => $api_game['homeTeam']['score'] ?? 0,
                'away_total' => $api_game['awayTeam']['score'] ?? 0
            ];
        }
    }
    
    return [];
}

// ============================================================================
// END HELPER FUNCTIONS
// ============================================================================

// Load database connection  
require_once '/data/www/default/nba-wins-platform/config/db_connection.php';

// Get current league from session
$league_id = $_SESSION['current_league_id'] ?? null;

if (!$league_id) {
    die("No league selected. Please go back to the main page and select a league.");
}

// Get parameters from URL
$home_team = $_GET['home_team'] ?? null;
$away_team = $_GET['away_team'] ?? null;
$date = $_GET['date'] ?? null;

// Validate input parameters
if (!$home_team || !$away_team || !$date) {
    die("Game information not provided");
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    die("Invalid date format");
}

if (!preg_match('/^[A-Z]{3}$/', $home_team) || !preg_match('/^[A-Z]{3}$/', $away_team)) {
    die("Invalid team code format");
}

// Fetch game info with multi-league participant data
$stmt = $pdo->prepare("
    SELECT g.*, 
           t1.logo AS home_logo,
           t2.logo AS away_logo,
           (SELECT COALESCE(u1.display_name, lp1.participant_name)
            FROM league_participant_teams lpt1 
            JOIN league_participants lp1 ON lpt1.league_participant_id = lp1.id 
            LEFT JOIN users u1 ON lp1.user_id = u1.id
            WHERE lpt1.team_name = g.home_team AND lp1.league_id = ? 
            LIMIT 1) AS home_participant,
           (SELECT COALESCE(u2.display_name, lp2.participant_name)
            FROM league_participant_teams lpt2 
            JOIN league_participants lp2 ON lpt2.league_participant_id = lp2.id 
            LEFT JOIN users u2 ON lp2.user_id = u2.id
            WHERE lpt2.team_name = g.away_team AND lp2.league_id = ? 
            LIMIT 1) AS away_participant
    FROM games g
    LEFT JOIN 2025_2026 t1 ON g.home_team = t1.name
    LEFT JOIN 2025_2026 t2 ON g.away_team = t2.name
    WHERE g.date = ? 
    AND g.home_team_code = ?
    AND g.away_team_code = ?
");
$stmt->execute([$league_id, $league_id, $date, $home_team, $away_team]);
$game = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$game) {
    die("Game not found");
}

// Get API scores
$api_scores = getAPIScores();

// Debug logging (can be removed after testing)
error_log("API Scores fetched: " . (isset($api_scores['scoreboard']['games']) ? count($api_scores['scoreboard']['games']) . " games" : "No games"));

$games_for_api = [$game];
$latest_scores = getLatestGameScores($games_for_api, $api_scores);
$game_key = $game['home_team'] . ' vs ' . $game['away_team'];
$current_scores = $latest_scores[$game_key] ?? null;

// Debug logging
if ($current_scores) {
    error_log("Found scores for {$game_key}: Home={$current_scores['home_points']}, Away={$current_scores['away_points']}, Source={$current_scores['source']}");
}

// Update game scores with API data if available
if ($current_scores) {
    $game['home_points'] = $current_scores['home_points'];
    $game['away_points'] = $current_scores['away_points'];
    if (isset($current_scores['status'])) {
        $game['status_long'] = $current_scores['status'];
    }
}

// Get quarter scores from API using new helper function
$quarter_data = getQuarterScores($game['home_team'], $game['away_team'], $api_scores);

// Build quarter scores array for display
$quarterScores = [];
$numOvertimes = 0; // Track number of OT periods for display

if (!empty($quarter_data)) {
    // Use API data
    $home_abbr = $home_team;
    $away_abbr = $away_team;
    $numOvertimes = count($quarter_data['home_overtimes'] ?? []);
    
    $homeRow = [
        'team_abbrev' => $home_abbr,
        'q1_points' => $quarter_data['home'][0] ?? null,
        'q2_points' => $quarter_data['home'][1] ?? null,
        'q3_points' => $quarter_data['home'][2] ?? null,
        'q4_points' => $quarter_data['home'][3] ?? null,
        'total_points' => $quarter_data['home_total']
    ];
    
    // Add overtime periods
    for ($i = 0; $i < $numOvertimes; $i++) {
        $homeRow['ot' . ($i + 1) . '_points'] = $quarter_data['home_overtimes'][$i] ?? null;
    }
    
    $awayRow = [
        'team_abbrev' => $away_abbr,
        'q1_points' => $quarter_data['away'][0] ?? null,
        'q2_points' => $quarter_data['away'][1] ?? null,
        'q3_points' => $quarter_data['away'][2] ?? null,
        'q4_points' => $quarter_data['away'][3] ?? null,
        'total_points' => $quarter_data['away_total']
    ];
    
    // Add overtime periods
    for ($i = 0; $i < $numOvertimes; $i++) {
        $awayRow['ot' . ($i + 1) . '_points'] = $quarter_data['away_overtimes'][$i] ?? null;
    }
    
    $quarterScores[] = $homeRow;
    $quarterScores[] = $awayRow;
    
    error_log("Using API quarter scores for {$game['home_team']}" . ($numOvertimes > 0 ? " with {$numOvertimes} OT periods" : ""));
} else {
    // Fall back to database quarter scores
    $stmt = $pdo->prepare("
        SELECT * 
        FROM game_quarter_scores 
        WHERE game_date = ? 
        AND team_abbrev IN (?, ?)
        ORDER BY CASE WHEN team_abbrev = ? THEN 0 ELSE 1 END
    ");
    $stmt->execute([$date, $home_team, $away_team, $home_team]);
    $quarterScores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check for OT columns in database results
    if (!empty($quarterScores)) {
        $firstRow = $quarterScores[0];
        for ($i = 1; $i <= 10; $i++) {
            $otKey = 'ot' . $i . '_points';
            if (isset($firstRow[$otKey]) && $firstRow[$otKey] !== null && $firstRow[$otKey] !== '') {
                $numOvertimes = $i;
            } else {
                break;
            }
        }
    }
    
    error_log("Using database quarter scores (API had no data)" . ($numOvertimes > 0 ? " with {$numOvertimes} OT periods" : ""));
}

// Fetch player stats - handle LA Clippers / Los Angeles Clippers naming variation
$home_team_variants = [$game['home_team']];
$away_team_variants = [$game['away_team']];

// Add alternate names for LA Clippers
if ($game['home_team'] === 'LA Clippers') {
    $home_team_variants[] = 'Los Angeles Clippers';
} elseif ($game['home_team'] === 'Los Angeles Clippers') {
    $home_team_variants[] = 'LA Clippers';
}

if ($game['away_team'] === 'LA Clippers') {
    $away_team_variants[] = 'Los Angeles Clippers';
} elseif ($game['away_team'] === 'Los Angeles Clippers') {
    $away_team_variants[] = 'LA Clippers';
}

$all_team_variants = array_merge($home_team_variants, $away_team_variants);
$placeholders = implode(',', array_fill(0, count($all_team_variants), '?'));

$stmt = $pdo->prepare("
    SELECT gps.* 
    FROM game_player_stats gps
    WHERE gps.game_date = ?
    AND gps.team_name IN ($placeholders)
    AND gps.player_name IS NOT NULL
    AND gps.player_name != ''
    ORDER BY gps.team_name, 
             CASE WHEN gps.minutes IS NULL OR gps.minutes = '-' THEN 1 ELSE 0 END,
             CASE WHEN gps.points IS NULL THEN 0 ELSE gps.points END DESC
");
$stmt->execute(array_merge([$date], $all_team_variants));
$playerStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch inactive players with proper team mapping
$stmt = $pdo->prepare("
    SELECT * 
    FROM game_inactive_players 
    WHERE game_date = ?
    AND team_city IN (
        SELECT CASE 
            WHEN home_team = 'Atlanta Hawks' THEN 'Atlanta'
            WHEN home_team = 'Boston Celtics' THEN 'Boston'
            WHEN home_team = 'Brooklyn Nets' THEN 'Brooklyn'
            WHEN home_team = 'Charlotte Hornets' THEN 'Charlotte'
            WHEN home_team = 'Chicago Bulls' THEN 'Chicago'
            WHEN home_team = 'Cleveland Cavaliers' THEN 'Cleveland'
            WHEN home_team = 'Dallas Mavericks' THEN 'Dallas'
            WHEN home_team = 'Denver Nuggets' THEN 'Denver'
            WHEN home_team = 'Detroit Pistons' THEN 'Detroit'
            WHEN home_team = 'Golden State Warriors' THEN 'Golden State'
            WHEN home_team = 'Houston Rockets' THEN 'Houston'
            WHEN home_team = 'Indiana Pacers' THEN 'Indiana'
            WHEN home_team = 'LA Clippers' THEN 'LA'
            WHEN home_team = 'Los Angeles Clippers' THEN 'LA'
            WHEN home_team = 'Los Angeles Lakers' THEN 'Los Angeles'
            WHEN home_team = 'Memphis Grizzlies' THEN 'Memphis'
            WHEN home_team = 'Miami Heat' THEN 'Miami'
            WHEN home_team = 'Milwaukee Bucks' THEN 'Milwaukee'
            WHEN home_team = 'Minnesota Timberwolves' THEN 'Minnesota'
            WHEN home_team = 'New Orleans Pelicans' THEN 'New Orleans'
            WHEN home_team = 'New York Knicks' THEN 'New York'
            WHEN home_team = 'Oklahoma City Thunder' THEN 'Oklahoma City'
            WHEN home_team = 'Orlando Magic' THEN 'Orlando'
            WHEN home_team = 'Philadelphia 76ers' THEN 'Philadelphia'
            WHEN home_team = 'Phoenix Suns' THEN 'Phoenix'
            WHEN home_team = 'Portland Trail Blazers' THEN 'Portland'
            WHEN home_team = 'Sacramento Kings' THEN 'Sacramento'
            WHEN home_team = 'San Antonio Spurs' THEN 'San Antonio'
            WHEN home_team = 'Toronto Raptors' THEN 'Toronto'
            WHEN home_team = 'Utah Jazz' THEN 'Utah'
            WHEN home_team = 'Washington Wizards' THEN 'Washington'
        END
        FROM games
        WHERE date = ? AND (home_team = ? OR away_team = ?)
        UNION
        SELECT CASE 
            WHEN away_team = 'Atlanta Hawks' THEN 'Atlanta'
            WHEN away_team = 'Boston Celtics' THEN 'Boston'
            WHEN away_team = 'Brooklyn Nets' THEN 'Brooklyn'
            WHEN away_team = 'Charlotte Hornets' THEN 'Charlotte'
            WHEN away_team = 'Chicago Bulls' THEN 'Chicago'
            WHEN away_team = 'Cleveland Cavaliers' THEN 'Cleveland'
            WHEN away_team = 'Dallas Mavericks' THEN 'Dallas'
            WHEN away_team = 'Denver Nuggets' THEN 'Denver'
            WHEN away_team = 'Detroit Pistons' THEN 'Detroit'
            WHEN away_team = 'Golden State Warriors' THEN 'Golden State'
            WHEN away_team = 'Houston Rockets' THEN 'Houston'
            WHEN away_team = 'Indiana Pacers' THEN 'Indiana'
            WHEN away_team = 'LA Clippers' THEN 'LA'
            WHEN away_team = 'Los Angeles Clippers' THEN 'LA'
            WHEN away_team = 'Los Angeles Lakers' THEN 'Los Angeles'
            WHEN away_team = 'Memphis Grizzlies' THEN 'Memphis'
            WHEN away_team = 'Miami Heat' THEN 'Miami'
            WHEN away_team = 'Milwaukee Bucks' THEN 'Milwaukee'
            WHEN away_team = 'Minnesota Timberwolves' THEN 'Minnesota'
            WHEN away_team = 'New Orleans Pelicans' THEN 'New Orleans'
            WHEN away_team = 'New York Knicks' THEN 'New York'
            WHEN away_team = 'Oklahoma City Thunder' THEN 'Oklahoma City'
            WHEN away_team = 'Orlando Magic' THEN 'Orlando'
            WHEN away_team = 'Philadelphia 76ers' THEN 'Philadelphia'
            WHEN away_team = 'Phoenix Suns' THEN 'Phoenix'
            WHEN away_team = 'Portland Trail Blazers' THEN 'Portland'
            WHEN away_team = 'Sacramento Kings' THEN 'Sacramento'
            WHEN away_team = 'San Antonio Spurs' THEN 'San Antonio'
            WHEN away_team = 'Toronto Raptors' THEN 'Toronto'
            WHEN away_team = 'Utah Jazz' THEN 'Utah'
            WHEN away_team = 'Washington Wizards' THEN 'Washington'
        END
        FROM games
        WHERE date = ? AND (home_team = ? OR away_team = ?)
    )
    ORDER BY team_city
");

$stmt->execute([
    $date, 
    $date, $game['home_team'], $game['away_team'],
    $date, $game['home_team'], $game['away_team']
]);
$inactivePlayers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Function to format minutes
function formatMinutes($minutes) {
    if (!$minutes || $minutes === '-') return '-';
    
    // Split the time format "4.000000:08" into parts
    $parts = explode(':', $minutes);
    if (count($parts) === 2) {
        $minutes = floatval($parts[0]);
        $seconds = intval($parts[1]);
        return sprintf("%02d:%02d", floor($minutes), $seconds);
    }
    return '-';
}

// Function to normalize team name display (handle LA Clippers / Los Angeles Clippers)
function normalizeTeamName($teamName) {
    if ($teamName === 'Los Angeles Clippers') {
        return 'LA Clippers';
    }
    return $teamName;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="apple-touch-icon" type="image/png" href="/nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="/nba-wins-platform/public/assets/favicon/favicon.png">
    <title>Box Score - NBA Wins Pool League</title>
    <link href="https://cdn.tailwindcss.com" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    /* Base styles */
    * {
        box-sizing: border-box;
    }
    
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Helvetica', 'Arial', sans-serif;
        line-height: 1.5;
        margin: 0;
        padding: 20px;
        background-image: url('/nba-wins-platform/public/assets/background/geometric_white.png');
        background-repeat: repeat;
        background-attachment: fixed;
        color: #1a1a1a;
    }
    
    /* Navigation Menu Styles */
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
        background-color: #212121;
        color: white;
        border: none;
        border-radius: 6px;
        padding: 0.5rem;
        cursor: pointer;
        z-index: 1002;
        width: 44px;
        height: 44px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        transition: all 0.2s ease;
    }
    
    .menu-button:hover {
        background-color: #424242;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }
    
    .menu-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 1001;
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    
    .menu-overlay.menu-open {
        opacity: 1;
    }
    
    .menu-panel {
        position: fixed;
        top: 0;
        left: -300px;
        width: 300px;
        height: 100vh;
        background-color: white;
        box-shadow: 2px 0 15px rgba(0,0,0,0.1);
        transition: left 0.3s ease;
        z-index: 1002;
        overflow-y: auto;
    }
    
    .menu-panel.menu-open {
        left: 0;
    }
    
    .menu-header {
        padding: 1rem;
        display: flex;
        justify-content: flex-end;
        border-bottom: 1px solid #e0e0e0;
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
        gap: 0.5rem;
    }
    
    .menu-link {
        display: block;
        padding: 0.75rem 1rem;
        color: #374151;
        text-decoration: none;
        transition: all 0.2s ease;
        border-radius: 6px;
        font-weight: 500;
    }
    
    .menu-link:hover {
        background-color: #f5f5f5;
        color: #212121;
        transform: translateX(4px);
    }
    
    .menu-link i {
        width: 20px;
        margin-right: 0.5rem;
    }
    
    .container {
        max-width: 900px;
        margin: 0 auto;
        background-color: white;
        padding: 2rem;
        border-radius: 16px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    }
    
    .header-section {
        display: none;
    }
    
    /* Team Matchup Header */
    .matchup-header {
        background: linear-gradient(to bottom, #f8f9fa 0%, #ffffff 100%);
        padding: 2rem;
        border-radius: 12px;
        margin-bottom: 2rem;
    }
    
    h1 {
        margin: 0 0 2rem 0;
        font-size: 2rem;
        font-weight: 700;
        text-align: center;
        color: #1a1a1a;
    }
    
    .team-row {
        position: relative;
        padding: 2rem;
        margin-bottom: 1rem;
        border-radius: 12px;
        overflow: hidden;
        min-height: 100px;
        display: flex;
        align-items: center;
    }
    
    .team-row.home-team {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    }
    
    .team-row.away-team {
        background: linear-gradient(135deg, #e9ecef 0%, #f8f9fa 100%);
        margin-bottom: 0;
    }
    
    .team-info-left {
        position: relative;
        z-index: 2;
        flex: 1;
        display: flex;
        align-items: center;
        gap: 1.5rem;
    }
    
    .team-info-right {
        position: relative;
        z-index: 2;
        flex: 1;
        text-align: right;
        display: flex;
        align-items: center;
        gap: 1.5rem;
        flex-direction: row-reverse;
    }
    
    .team-logo-background {
        position: absolute;
        width: 180px;
        height: 180px;
        object-fit: contain;
        opacity: 0.25;
        z-index: 1;
        pointer-events: none;
    }
    
    .team-logo-visible {
        width: 72px;
        height: 72px;
        object-fit: contain;
        filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
        flex-shrink: 0;
    }
    
    .team-row.home-team .team-logo-background {
        left: 1.5rem;
        right: auto;
        top: 50%;
        transform: translateY(-50%);
    }
    
    .team-row.away-team .team-logo-background {
        right: 1.5rem;
        left: auto;
        top: 50%;
        transform: translateY(-50%);
    }
    
    .team-details {
        position: relative;
    }
    
    .team-details-right {
        position: relative;
    }
    
    .team-name {
        font-size: 1.3rem;
        font-weight: 700;
        color: #1a1a1a;
        margin-bottom: 0.25rem;
        line-height: 1.2;
    }
    
    .team-score {
        font-size: 1.6rem;
        color: #212121;
        font-weight: 700;
        letter-spacing: -0.5px;
        margin-bottom: 0.25rem;
    }
    
    .team-owner {
        font-size: 0.9rem;
        color: #666;
        font-style: italic;
        margin-top: 0.25rem;
        font-weight: 500;
    }
    
    .vs-divider {
        text-align: center;
        font-size: 1rem;
        font-weight: 700;
        color: #999;
        padding: 0.5rem 0;
        letter-spacing: 1px;
    }
    
    /* Venue Info */
    .venue-info {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 1rem;
        padding: 0.75rem 1rem;
        background-color: #76a5af;
        color: white;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.95rem;
        margin-top: 1rem;
        box-shadow: 0 2px 8px rgba(118, 165, 175, 0.3);
    }
    
    .venue-info .divider {
        color: rgba(255, 255, 255, 0.5);
        font-weight: 400;
    }

    /* Sections */
    .section {
        margin: 2.5rem 0;
        padding: 2rem;
        background-color: #ffffff;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid #f0f0f0;
        transition: all 0.2s ease;
    }
    
    .section:hover {
        box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    }
    
    .section h3 {
        margin: 0 0 1.5rem 0;
        padding-bottom: 0.75rem;
        border-bottom: 2px solid #f0f0f0;
        color: #212121;
        font-size: 1.35rem;
        font-weight: 600;
    }
    
    .section h4 {
        margin: 1.5rem 0 1rem 0;
        color: #424242;
        font-size: 1.1rem;
        font-weight: 600;
    }

    /* Tables */
    table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        margin: 1.5rem 0;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    }

    th, td {
        padding: 12px 16px;
        text-align: left;
        border-bottom: 1px solid #e9ecef;
    }

    th {
        background-color: #212121;
        color: white;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 0.5px;
    }
    
    tbody tr {
        background-color: #ffffff;
        transition: all 0.2s ease;
    }
    
    tbody tr:hover {
        background-color: #f8f9fa;
        transform: scale(1.01);
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    
    tbody tr:last-child td {
        border-bottom: none;
    }
    
    td.font-bold {
        font-weight: 600;
        color: #212121;
    }

    .inactive-icon {
        margin-right: 8px;
        color: #dc3545;
    }
    
    .inactive-list {
        list-style: none;
        padding-left: 0;
        margin: 1rem 0;
    }
    
    .inactive-list li {
        padding: 0.5rem 1rem;
        margin: 0.25rem 0;
        background-color: #f8f9fa;
        border-left: 3px solid #dc3545;
        border-radius: 4px;
        transition: all 0.2s ease;
    }
    
    .inactive-list li:hover {
        background-color: #e9ecef;
        transform: translateX(4px);
    }

    /* Mobile Optimizations */
    @media (max-width: 768px) {
        body {
            padding: 10px;
        }
        
        .container {
            max-width: 500px;
            padding: 0;
            border-radius: 12px;
            overflow: hidden;
        }
        
        .matchup-header {
            padding: 1.25rem 1rem;
            border-radius: 0;
            margin-bottom: 0;
        }
        
        h1 {
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .team-row {
            padding: 1.25rem 1rem;
            margin-bottom: 0.75rem;
            min-height: 80px;
        }
        
        .team-row.away-team {
            margin-bottom: 0;
        }
        
        .team-logo-background {
            width: 130px;
            height: 130px;
            opacity: 0.22;
        }
        
        .team-logo-visible {
            width: 56px;
            height: 56px;
        }
        
        .team-row.home-team .team-logo-background {
            left: 1rem;
        }
        
        .team-row.away-team .team-logo-background {
            right: 1rem;
        }
        
        .team-info-left {
            text-align: left;
        }
        
        .team-info-right {
            text-align: right;
        }
        
        .team-name {
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }
        
        .team-score {
            font-size: 1.3rem;
            margin-bottom: 0.25rem;
        }
        
        .team-owner {
            font-size: 0.8rem;
            margin-top: 0.25rem;
        }
        
        .vs-divider {
            font-size: 0.85rem;
            padding: 0.375rem 0;
        }
        
        .venue-info {
            font-size: 0.85rem;
            padding: 0.65rem 0.875rem;
            margin-top: 0.75rem;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        
        .section {
            padding: 1.25rem;
            margin: 1.5rem 0;
            border-radius: 0;
        }
        
        .section h3 {
            font-size: 1.15rem;
            margin-bottom: 1rem;
        }

        /* Hide FG column on mobile */
        .player-stats-table th:last-child,
        .player-stats-table td:last-child {
            display: none;
        }

        table {
            font-size: 0.8rem;
            margin: 1rem 0;
        }

        th, td {
            padding: 8px 6px;
        }
        
        th {
            font-size: 0.75rem;
        }
    }
    
    @media (max-width: 400px) {
        .team-name {
            font-size: 1rem;
        }
        
        .team-score {
            font-size: 1.4rem;
        }
        
        .team-logo-background {
            width: 110px;
            height: 110px;
        }
        
        .team-logo-visible {
            width: 48px;
            height: 48px;
        }
    }
</style>

<!-- React and Babel for Navigation Component -->
<script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
<script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
<script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>

</head>
<body>
    <?php 
    // Include the navigation menu component
    include $_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/components/navigation_menu.php'; 
    ?>
    
    <div class="container">
        <!-- Team Matchup Header -->
        <div class="matchup-header">
            
            <!-- Home Team -->
            <div class="team-row home-team">
                <img src="<?php echo htmlspecialchars($game['home_logo']); ?>" 
                     alt="<?php echo htmlspecialchars($game['home_team']); ?>" 
                     class="team-logo-background">
                <div class="team-info-left">
                    <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($game['home_team']); ?>">
                        <img src="<?php echo htmlspecialchars($game['home_logo']); ?>" 
                             alt="<?php echo htmlspecialchars($game['home_team']); ?>" 
                             class="team-logo-visible">
                    </a>
                    <div class="team-details">
                        <div class="team-name"><?php echo htmlspecialchars($game['home_team']); ?></div>
                        <div class="team-score"><?php echo $game['home_points']; ?></div>
                        <?php if ($game['home_participant']): ?>
                            <div class="team-owner">(<?php echo htmlspecialchars($game['home_participant']); ?>)</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="vs-divider">VS</div>
            
            <!-- Away Team -->
            <div class="team-row away-team">
                <img src="<?php echo htmlspecialchars($game['away_logo']); ?>" 
                     alt="<?php echo htmlspecialchars($game['away_team']); ?>" 
                     class="team-logo-background">
                <div class="team-info-right">
                    <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($game['away_team']); ?>">
                        <img src="<?php echo htmlspecialchars($game['away_logo']); ?>" 
                             alt="<?php echo htmlspecialchars($game['away_team']); ?>" 
                             class="team-logo-visible">
                    </a>
                    <div class="team-details-right">
                        <div class="team-name"><?php echo htmlspecialchars($game['away_team']); ?></div>
                        <div class="team-score"><?php echo $game['away_points']; ?></div>
                        <?php if ($game['away_participant']): ?>
                            <div class="team-owner">(<?php echo htmlspecialchars($game['away_participant']); ?>)</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Venue Info -->
            <div class="venue-info">
                <span><i class="fas fa-building"></i> <?php echo htmlspecialchars($game['arena']); ?></span>
                <span class="divider">•</span>
                <span>
                    <i class="far fa-calendar"></i>
                    <?php echo date('F j, Y', strtotime($game['date'])); ?>
                </span>
            </div>
        </div>

        <!-- Quarter Scores -->
        <section class="section">
            <h3><i class="fas fa-chart-bar"></i> Box Score</h3>
            <table>
                <thead>
                    <tr>
                        <th>Team</th>
                        <th>Q1</th>
                        <th>Q2</th>
                        <th>Q3</th>
                        <th>Q4</th>
                        <?php for ($ot = 1; $ot <= $numOvertimes; $ot++): ?>
                            <th><?php echo $numOvertimes === 1 ? 'OT' : 'OT' . $ot; ?></th>
                        <?php endfor; ?>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($quarterScores as $score): ?>
                    <tr>
                        <td class="font-bold"><?php echo htmlspecialchars($score['team_abbrev']); ?></td>
                        <td><?php echo $score['q1_points'] ?? '-'; ?></td>
                        <td><?php echo $score['q2_points'] ?? '-'; ?></td>
                        <td><?php echo $score['q3_points'] ?? '-'; ?></td>
                        <td><?php echo $score['q4_points'] ?? '-'; ?></td>
                        <?php for ($ot = 1; $ot <= $numOvertimes; $ot++): ?>
                            <td><?php echo $score['ot' . $ot . '_points'] ?? '-'; ?></td>
                        <?php endfor; ?>
                        <td class="font-bold"><?php echo $score['total_points']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <!-- Player Stats -->
        <section class="section">
            <h3><i class="fas fa-users"></i> Player Statistics</h3>
            <?php 
            $teams = array_unique(array_column($playerStats, 'team_name'));
            foreach ($teams as $team): 
                $teamPlayers = array_filter($playerStats, function($player) use ($team) {
                    return $player['team_name'] === $team;
                });
            ?>
                <h4><?php echo htmlspecialchars(normalizeTeamName($team)); ?></h4>
                <table class="player-stats-table">
                    <thead>
                        <tr>
                            <th>Player</th>
                            <th>MIN</th>
                            <th>PTS</th>
                            <th>REB</th>
                            <th>AST</th>
                            <th>FG</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teamPlayers as $player): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($player['player_name']); ?></td>
                            <td><?php echo formatMinutes($player['minutes']); ?></td>
                            <td><?php echo $player['points'] ?? '-'; ?></td>
                            <td><?php echo $player['rebounds'] ?? '-'; ?></td>
                            <td><?php echo $player['assists'] ?? '-'; ?></td>
                            <td><?php echo ($player['fg_made'] ?? '-') . '-' . ($player['fg_attempts'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        </section>

        <!-- Inactive Players -->
        <?php if (!empty($inactivePlayers)): ?>
        <section class="section">
            <h3>
                <i class="fa-solid fa-notes-medical inactive-icon"></i>
                Inactive Players
            </h3>
            <?php 
            $teams = array_unique(array_column($inactivePlayers, 'team_city'));
            foreach ($teams as $team): 
                $teamInactives = array_filter($inactivePlayers, function($player) use ($team) {
                    return $player['team_city'] === $team;
                });
            ?>
                <h4><?php echo htmlspecialchars($team); ?></h4>
                <ul class="inactive-list">
                    <?php foreach ($teamInactives as $player): ?>
                        <li><?php echo htmlspecialchars($player['player_name']); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>
    </div>
</body>
</html>
