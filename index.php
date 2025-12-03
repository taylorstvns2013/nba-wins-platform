<?php
// Set timezone to EST
date_default_timezone_set('America/New_York');

// Load database connection and authentication
require_once '/data/www/default/nba-wins-platform/config/db_connection.php';

// Require authentication - redirect to login if not authenticated
requireAuthentication($auth);

// Get current league context
$leagueContext = getCurrentLeagueContext($auth);
if (!$leagueContext || !$leagueContext['league_id']) {
    die('Error: No league selected. Please contact administrator.');
}

// Check if there are games today that aren't complete yet
// Complete statuses are: "Final" or "Finished"
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count 
    FROM games 
    WHERE date = CURDATE() 
    AND status_long NOT IN ('Final', 'Finished')
");
$stmt->execute();
$has_incomplete_games = $stmt->fetch()['count'] > 0;

if ($has_incomplete_games) {
    exec('python3 /data/www/default/nba-wins-platform/tasks/get_games.py --write > /dev/null 2>&1 &');
}

$currentLeagueId = $leagueContext['league_id'];

// Load widget classes for dashboard
require_once '/data/www/default/nba-wins-platform/core/DashboardWidget.php';
$dashboardWidget = new DashboardWidget($pdo);

// Get user's pinned widgets
$pinnedWidgets = [];
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("
        SELECT widget_type, display_order 
        FROM user_dashboard_widgets 
        WHERE user_id = ? AND is_active = 1
        ORDER BY display_order ASC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $pinnedWidgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Check if user is in edit mode for widgets
$widgetEditMode = isset($_GET['edit_widgets']) && $_GET['edit_widgets'] == '1';

// Call Live scores
require_once '/data/www/default/nba-wins-platform/core/game_scores_helper.php';

// Team logo mapping function - maps team names to actual logo filenames
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
    
    // Return mapped logo or fallback
    if (isset($logoMap[$teamName])) {
        return 'nba-wins-platform/public/assets/team_logos/' . $logoMap[$teamName];
    }
    
    // Fallback: try lowercase with underscores
    $filename = strtolower(str_replace(' ', '_', $teamName)) . '.png';
    return 'nba-wins-platform/public/assets/team_logos/' . $filename;
}

// Fetch team data with over/under projections and logos from nba_teams
$stmt = $pdo->query("
    SELECT t.*, 
           COALESCE(ou.over_under_number, 0) as projected_wins,
           nt.logo_filename as logo
    FROM 2025_2026 t
    LEFT JOIN over_under ou ON t.name = ou.team_name
    LEFT JOIN nba_teams nt ON t.name = nt.name
    ORDER BY t.win DESC
");
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

// UPDATED: Fetch participants and their teams from league-specific tables - now includes user_id
$stmt = $pdo->prepare("
    SELECT 
        lp.id, 
        lp.user_id, 
        COALESCE(u.display_name, lp.participant_name) as name, 
        lpt.team_name,
        COALESCE(dp.pick_number, 999) as draft_pick_number
    FROM league_participants lp 
    LEFT JOIN users u ON lp.user_id = u.id
    LEFT JOIN league_participant_teams lpt ON lp.id = lpt.league_participant_id 
    LEFT JOIN draft_picks dp ON (
        lp.id = dp.league_participant_id 
        AND dp.draft_session_id = (
            SELECT id FROM draft_sessions 
            WHERE league_id = ? AND status = 'completed' 
            ORDER BY created_at DESC LIMIT 1
        )
        AND dp.team_id = (
            SELECT id FROM nba_teams WHERE name = lpt.team_name LIMIT 1
        )
    )
    WHERE lp.league_id = ? AND lp.status = 'active'
    ORDER BY lp.id, COALESCE(dp.pick_number, 999) ASC
");
$stmt->execute([$currentLeagueId, $currentLeagueId]);
$participantData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Team name normalization function to handle database inconsistencies
function normalizeTeamName($teamName) {
    $teamName = trim($teamName);
    
    // Handle team name variations that might exist in different database tables
    $nameVariations = [
        // Clippers variations - most common issue
        'Los Angeles Clippers' => 'LA Clippers',
        'L.A. Clippers' => 'Los Angeles Clippers',
        'LAC' => 'Los Angeles Clippers',
        
        // Lakers variations  
        'LA Lakers' => 'Los Angeles Lakers',
        'L.A. Lakers' => 'Los Angeles Lakers',
        'LAL' => 'Los Angeles Lakers',
        
        // Other potential variations
        'Philadelphia Sixers' => 'Philadelphia 76ers',
        'Philly 76ers' => 'Philadelphia 76ers',
    ];
    
    return isset($nameVariations[$teamName]) ? $nameVariations[$teamName] : $teamName;
}

// Process participant data with uniqueness check - now stores user_id as well and normalizes team names
$participants = [];
$uniqueParticipants = [];
foreach ($participantData as $row) {
    // Normalize team name to handle database inconsistencies
    $normalizedTeamName = normalizeTeamName($row['team_name']);
    
    $uniqueKey = $row['name'] . '_' . $normalizedTeamName;
    if (!isset($uniqueParticipants[$uniqueKey])) {
        if (!isset($participants[$row['name']])) {
            $participants[$row['name']] = [
                'user_id' => $row['user_id'],
                'teams' => []
            ];
        }
        $participants[$row['name']]['teams'][] = $normalizedTeamName;
        $uniqueParticipants[$uniqueKey] = true;
    }
}

// Fetch previous day's and today's logged wins
$yesterdayDate = date('Y-m-d', strtotime('-1 day'));
$todayDate = date('Y-m-d');

// MODIFIED: Get yesterday's wins for baseline from league-specific table
$stmt = $pdo->prepare("
    SELECT COALESCE(u.display_name, lp.participant_name) as participant_name, lpw.total_wins 
    FROM league_participant_daily_wins lpw
    JOIN league_participants lp ON lpw.league_participant_id = lp.id
    LEFT JOIN users u ON lp.user_id = u.id
    WHERE lpw.date = ? AND lp.league_id = ?
");
$stmt->execute([$yesterdayDate, $currentLeagueId]);
$previousDayWins = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// MODIFIED: Get today's logged wins from league-specific table
$stmt = $pdo->prepare("
    SELECT COALESCE(u.display_name, lp.participant_name) as participant_name, lpw.total_wins 
    FROM league_participant_daily_wins lpw
    JOIN league_participants lp ON lpw.league_participant_id = lp.id
    LEFT JOIN users u ON lp.user_id = u.id
    WHERE lpw.date = ? AND lp.league_id = ?
");
$stmt->execute([$todayDate, $currentLeagueId]);
$todayLoggedWins = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Process data (update to include projected wins and streak data)
$standings = [];
foreach ($participants as $name => $participant_data) {
    $participant_teams = $participant_data['teams'];
    $user_id = $participant_data['user_id'];
    
    $total_wins = 0;
    $total_projected_wins = 0;
    $team_data = [];
    
    foreach ($participant_teams as $team) {
        // Use normalized team name for lookups
        $normalizedTeam = normalizeTeamName($team);
        
        $team_info = array_values(array_filter($teams, function($t) use ($normalizedTeam) {
            return normalizeTeamName($t['name']) == $normalizedTeam;
        }))[0] ?? null;
        
        if ($team_info) {
            // Get streak information for this team using normalized name
            $stmt = $pdo->prepare("SELECT streak, winstreak FROM 2025_2026 WHERE name = ? OR name = ?");
            $stmt->execute([$normalizedTeam, $team]);
            $streakInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            $streak = $streakInfo['streak'] ?? 0;
            $winstreak = $streakInfo['winstreak'] ?? 0;
            
            $total_wins += $team_info['win'];
            $total_projected_wins += $team_info['projected_wins'];
            $team_data[] = [
                'name' => $normalizedTeam,  // Use normalized name consistently
                'wins' => $team_info['win'],
                'projected_wins' => $team_info['projected_wins'],
                'logo' => getTeamLogo($normalizedTeam),
                'streak' => $streak,
                'winstreak' => $winstreak
            ];
        }
    }
    
    // Calculate wins change using only logged wins
    $wins_change = 0;
    if (isset($previousDayWins[$name]) && isset($todayLoggedWins[$name])) {
        $wins_change = $todayLoggedWins[$name] - $previousDayWins[$name];
    }
    
    $standings[] = [
        'name' => $name,
        'user_id' => $user_id,
        'total_wins' => $total_wins,
        'total_projected_wins' => $total_projected_wins,
        'teams' => $team_data,
        'wins_change' => $wins_change
    ];
}

// Sort standings by total wins
usort($standings, function($a, $b) {
    return $b['total_wins'] - $a['total_wins'];
});

// NBA Cup Tournament Dates (can be updated for future seasons)
$nbaCupDates = [
    '2025-10-31',
    '2025-11-07',
    '2025-11-14',
    '2025-11-21',
    '2025-11-25',
    '2025-11-26',
    '2025-11-28',
    '2025-12-09',
    '2025-12-10',
    '2025-12-13',
    '2025-12-16'
];

// Get the selected date from the query string, defaulting to today if not set
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Check if selected date is an NBA Cup date
$isNbaCupDate = in_array($selectedDate, $nbaCupDates);

// FIXED: Modified games query to prevent duplicates by using DISTINCT and better JOIN structure
$stmt = $pdo->prepare("
    SELECT DISTINCT g.*, 
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
            LIMIT 1) AS away_participant,
           nt1.logo_filename AS home_logo,
           nt2.logo_filename AS away_logo,
           gsu.game_time AS stream_game_time
    FROM games g
    LEFT JOIN nba_teams nt1 ON g.home_team = nt1.name
    LEFT JOIN nba_teams nt2 ON g.away_team = nt2.name
    LEFT JOIN game_stream_urls gsu ON (g.home_team = gsu.home_team AND g.away_team = gsu.away_team AND DATE(gsu.game_date) = ?)
    WHERE DATE(g.start_time) = ?
    ORDER BY COALESCE(gsu.game_time, TIME(g.start_time)) ASC
");
$stmt->execute([$currentLeagueId, $currentLeagueId, $selectedDate, $selectedDate]);
$games = $stmt->fetchAll(PDO::FETCH_ASSOC);

$api_scores = getAPIScores();
$latest_scores = getLatestGameScores($games, $api_scores);

// Fetch stream URLs for today's games
$stmt = $pdo->prepare("
    SELECT home_team, away_team, stream_url 
    FROM game_stream_urls 
    WHERE DATE(game_date) = ?
");
$stmt->execute([$selectedDate]);
$streamUrls = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Create normalized keys
    $homeTeam = trim($row['home_team']);
    $awayTeam = trim($row['away_team']);
    // Store URLs with both team name combinations
    $streamUrls[$homeTeam . '-' . $awayTeam] = $row['stream_url'];
    $streamUrls[$awayTeam . '-' . $homeTeam] = $row['stream_url'];
}

// Find the highest score
$highestScore = count($standings) > 0 ? $standings[0]['total_wins'] : 0;

// Count how many participants have the highest score
$tiedForFirst = 0;
foreach ($standings as $participant) {
    if ($participant['total_wins'] == $highestScore) {
        $tiedForFirst++;
    } else {
        break;
    }
}

// Get daily game counts
function getParticipantGameCounts($games, $participants) {
    $counts = [];
    foreach ($participants as $name => $participant_data) {
        $participant_teams = $participant_data['teams'];
        $gameCount = 0;
        $countedGames = []; // Track unique games

        foreach ($games as $game) {
            $gameKey = $game['home_team'] . '-' . $game['away_team'];
            
            // Check if this game involves the participant's teams
            $participantInvolved = false;
            foreach ($participant_teams as $team) {
                if ($team === $game['home_team'] || $team === $game['away_team']) {
                    $participantInvolved = true;
                    break;
                }
            }

            // Only count if participant is involved and we haven't counted this game yet
            if ($participantInvolved && !in_array($gameKey, $countedGames)) {
                $gameCount++;
                $countedGames[] = $gameKey;
            }
        }
        
        $counts[$name] = $gameCount;
    }
    return $counts;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="theme-color" content="#f5f5f5">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NBA Wins Pool League</title>
    <link rel="apple-touch-icon" type="image/png" href="nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
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
        --hover-color: #757575;
        --basketball-orange: #FF7F00;
        --basketball-brown: #8B4513;
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        line-height: 1.6;
        margin: 0;
        padding: 10px;
        background-image: url('nba-wins-platform/public/assets/background/geometric_white.png');
        background-repeat: repeat;
        background-attachment: fixed;
        color: var(--text-color);
        background-color: #f5f5f5;
        min-height: 100vh;
        min-height: -webkit-fill-available;
    }

    html {
        height: -webkit-fill-available;
        background-color: #f5f5f5;
    }
    
    .container {
        max-width: 100%;
        margin: 0 auto;
        padding: 10px;
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
    }
    
    #participantsTable {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        margin-bottom: 10px;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    
    #participantsTable th, #participantsTable td {
        padding: 8px;
        text-align: left;
        font-size: 14px;
    }
    
    #participantsTable th {
        background-color: var(--primary-color);
        color: white;
        font-weight: bold;
        text-transform: uppercase;
    }
    
    tr:nth-child(even) {
        background-color: rgba(248, 248, 248, 0.8);
    }
    
    .participant-name {
        color: var(--secondary-color);
        font-weight: bold;
        display: flex;
        align-items: center;
        gap: 5px;
        cursor: default;
    }
    
    .team-list {
        display: none;
        transition: all 0.3s ease-in-out;
        max-height: 0;
        overflow: hidden;
    }
    
    .team-list.show {
        display: table-row;
        max-height: 500px;
    }
    
    .team-list td {
        padding-left: 15px;
        background-color: rgba(238, 238, 238, 0.8);
    }
    
    .total-wins, .projected-wins {
        font-weight: bold;
        text-align: right;
    }
    
    .wins-change {
        display: inline-block;
        margin-left: 5px;
        color: #28a745;
        font-size: 0.9em;
    }
    
    .wins-change i {
        margin-right: 1px;
    }
    
    .team-logo {
        width: 20px;
        height: 20px;
        margin-right: 5px;
        vertical-align: middle;
    }
    
    .team-name {
        display: flex;
        align-items: center;
    }
    
    .win-streak-icon {
        color: #FFD700;
        margin-left: 5px;
        font-size: 12px;
    }
    
    .lose-streak-icon {
        color: #1E90FF;
        margin-left: 5px;
        font-size: 12px;
    }
    
    .games-container {
        margin-top: 20px;
        padding: 20px;
        background-color: var(--background-color);
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }
    
    .date-picker-container {
        margin-bottom: 20px;
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 15px;
    }
    
    #date-picker {
        padding: 8px 12px;
        font-size: 16px;
        border: 1px solid var(--border-color);
        border-radius: 4px;
        background-color: white;
        cursor: pointer;
    }
    
    .date-nav-btn {
        background-color: transparent;
        color: #bbb;
        border: none;
        border-radius: 4px;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 1rem;
        transition: all 0.2s;
    }
    
    .date-nav-btn:hover {
        color: #888;
        background-color: rgba(0, 0, 0, 0.05);
    }
    
    .date-nav-btn:active {
        transform: scale(0.95);
    }
    
    .games-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        justify-content: center;
    }
    
    .game {
        flex: 1 1 calc(50% - 10px);
        max-width: calc(50% - 10px);
        display: flex;
        flex-direction: column;
        background-color: rgba(211, 211, 211, 0.3);
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    
    .game.hidden {
        display: none !important;
    }

    .team {
        display: flex;
        align-items: center;
        padding: 10px;
        justify-content: space-between;
    }
    
    .team:first-child {
        border-bottom: 1px solid var(--border-color);
    }
    
    .team-info {
        display: flex;
        align-items: center;
    }
    
    .score-container {
        display: flex;
        align-items: center;
    }
    
    .team-logo {
        width: 30px;
        height: 30px;
        margin-right: 10px;
    }
    
    .team-code {
        font-weight: bold;
        font-size: 16px;
    }
    
    .score {
        font-weight: bold;
        font-size: 20px;
        margin-left: auto;
    }
    
    .game-time {
        text-align: center;
        padding: 8px;
        background-color: #76a5af;
        color: white;
        font-size: 14px;
    }
    
    /* FIXED: Added proper styling for finished games with grey background */
    .game-time.final {
        background-color: #76a5af;
        padding: 0;
        font-weight: bold;
    }
    
    /* Live game styling */
    .live-game {
        background-color: #e63946 !important;
        animation: pulse 2s infinite;
    }
    
    @keyframes pulse {
        0% { opacity: 1; }
        50% { opacity: 0.8; }
        100% { opacity: 1; }
    }
    
    /* Enhanced game buttons */
    .game-buttons {
        display: flex;
        gap: 10px;
        padding: 6px;
        justify-content: center;
        background-color: #76a5af; /* ADDED: Grey background for button container */
    }
    
    .game-button {
        padding: 8px 16px;
        border-radius: 6px;
        text-decoration: none;
        font-weight: 500;
        min-width: 100px;
        text-align: center;
        font-size: 14px;
        transition: all 0.2s ease;
    }
    
    .watch-button {
        background-color: #0066ff;
        color: white;
    }
    
    .watch-button:hover {
        background-color: #0052cc;
    }
    
    .stats-button {
        background-color: #e6f0ff;
        color: #0066ff;
        border: 1px solid #0066ff;
    }
    
    .stats-button:hover {
        background-color: #cce0ff;
    }
    
    .inner-table {
        width: 100%;
    }
    
    .inner-table th, .inner-table td {
        padding: 6px;
        text-align: left;
        font-size: 12px;
    }
    
    .inner-table th {
        background-color: #f2f2f2;
        font-weight: bold;
    }
    
    .game-participant-name {
        font-size: 12px;
        color: #666;
        font-style: italic;
        margin-left: 5px;
        display: inline-block;
    }
    
    .filter-container {
        margin: 15px 0;
        text-align: center;
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
    }
    
    #participant-filter {
        padding: 8px 12px;
        font-size: 16px;
        border: 1px solid var(--border-color);
        border-radius: 4px;
        background-color: white;
        cursor: pointer;
        width: 200px;
    }
    
    .refresh-button {
        background-color: transparent;
        color: #bbb;
        border: none;
        border-radius: 4px;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 1rem;
        transition: all 0.2s;
    }
    
    .refresh-button:hover {
        color: #888;
        background-color: rgba(0, 0, 0, 0.05);
    }
    
    .refresh-button:active {
        transform: scale(0.95);
    }
    
    .refresh-button i {
        transition: transform 0.3s ease;
    }
    
    .refresh-button:active i {
        transform: rotate(180deg);
    }
        width: 200px;
    }
    
    .menu-container {
        position: fixed;
        top: 0;
        left: 0;
        z-index: 1000;
    }
    
    .menu-button {
        position: fixed;
        top: 5.5rem;  /* Changed from 4rem to 5.5rem for more spacing */
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
    
    .expandable-row {
        cursor: pointer;
        transition: background-color 0.2s ease;
    }

    .expandable-row:hover {
        background-color: rgba(0, 0, 0, 0.05);
    }

    .expand-indicator {
        margin-left: 5px;
        color: #666;
        transition: transform 0.3s ease;
    }

    .expanded .expand-indicator {
        transform: rotate(180deg);
    }
    
    /* Rank container to properly align rank number, chevron, and trophy */
    .rank-container {
        display: flex;
        align-items: center;
        gap: 4px;
        white-space: nowrap;
    }
    
    .rank-container .fa-trophy {
        margin-left: 4px;
    }

    /* Profile link styling */
    .profile-link {
        display: block; 
        padding: 6px;
        background-color: rgba(100, 140, 255, 0.8);
        color: white; 
        font-size: 12px;
        text-decoration: none; 
        text-align: center;
        transition: opacity 0.2s;
        font-weight: bold;
        border-radius: 6px;
        margin-top: 8px;
    }
    
    .profile-link:hover {
        background-color: rgba(100, 140, 255, 1);
        opacity: 0.9;
    }
    
    /* Dashboard Widget Styles */
    .dashboard-widgets-container {
        margin: 20px 0;
        padding: 20px;
        background-color: var(--background-color);
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }
    
    .dashboard-widgets-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--border-color);
    }
    
    .dashboard-widgets-header h2 {
        margin: 0;
        font-size: 1.5rem;
        color: var(--primary-color);
    }
    
    .widget-edit-btn {
        padding: 6px;
        background-color: transparent;
        color: #bbb;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 1.1rem;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
    }
    
    .widget-edit-btn:hover {
        color: #888;
        background-color: rgba(0, 0, 0, 0.05);
    }
    
    .widget-edit-btn.active {
        color: #28a745;
    }
    
    .widget-edit-btn.active:hover {
        color: #218838;
        background-color: rgba(40, 167, 69, 0.1);
    }
    
    .dashboard-widget {
        margin-bottom: 20px;
        position: relative;
    }
    
    .widget-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
    }
    
    .widget-controls {
        display: none;
        gap: 8px;
    }
    
    .widget-controls.show {
        display: flex;
    }
    
    .widget-control-btn {
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 4px;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: #666;
        transition: all 0.2s;
    }
    
    .widget-control-btn:hover {
        background: #f0f0f0;
        color: #007bff;
        border-color: #007bff;
    }
    
    .widget-control-btn:disabled {
        opacity: 0.3;
        cursor: not-allowed;
    }
    
    .widget-remove-btn {
        border-color: #dc3545 !important;
        color: #dc3545 !important;
    }
    
    .widget-remove-btn:hover {
        background: #dc3545 !important;
        color: white !important;
    }
    
    .no-widgets-message {
        text-align: center;
        padding: 40px 20px;
        color: #666;
    }
    
    .no-widgets-message i {
        font-size: 3rem;
        color: #ddd;
        margin-bottom: 15px;
    }
    
    .no-widgets-message p {
        font-size: 1.1rem;
        margin: 10px 0;
    }
    
    .no-widgets-message a {
        color: #007bff;
        text-decoration: none;
        font-weight: 500;
    }
    
    .no-widgets-message a:hover {
        text-decoration: underline;
    }
    
    /* Game List Styles for Widgets */
    .games-list {
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 1rem;
    }
    
    .game-list-item {
        padding: 0.75rem;
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
    }
    
    .game-list-item:last-child {
        border-bottom: none;
    }
    
    .game-list-item.win {
        background-color: rgba(76, 175, 80, 0.1);
    }
    
    .game-list-item.loss {
        background-color: rgba(244, 67, 54, 0.1);
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
        font-size: 0.95rem;
    }
    
    .game-list-result {
        text-align: right;
        font-weight: bold;
    }
    
    .game-list-score {
        font-size: 1rem;
        margin-bottom: 0.25rem;
    }
    
    .game-list-outcome {
        font-size: 0.9rem;
    }
    
    .game-list-outcome.win {
        color: #4CAF50;
    }
    
    .game-list-outcome.loss {
        color: #F44336;
    }
    
    .stats-card {
        background-color: rgba(255, 255, 255, 0.95);
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    
    .section-title {
        font-size: 1.25rem;
        font-weight: bold;
        margin: 0 0 1rem 0;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid #eee;
    }
    
    .team-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px;
        border-bottom: 1px solid #eee;
        margin-bottom: 8px;
        background-color: #f8f9fa;
        border-radius: 6px;
        transition: background-color 0.2s;
    }
    
    .team-row:hover {
        background-color: #e9ecef;
    }
    
    .team-row:last-child {
        border-bottom: none;
        margin-bottom: 0;
    }
    
    .team-info {
        display: flex;
        align-items: center;
        flex: 1;
    }
    
    .team-record {
        font-weight: 600;
        min-width: 70px;
        text-align: right;
        color: #333;
    }
    
    .no-data {
        color: #666;
        font-style: italic;
        padding: 20px;
        text-align: center;
    }
    
    @media (max-width: 600px) {
        #participantsTable th, #participantsTable td {
            padding: 6px 4px;
            font-size: 12px;
        }
        
        .rank-container {
            gap: 2px;
        }
        
        .rank-container .fa-trophy {
            font-size: 14px;
            margin-left: 2px;
        }
        
        .expand-indicator {
            font-size: 12px;
        }
        
        .team-logo {
            width: 16px;
            height: 16px;
        }
        .game {
            padding: 8px;
        }
        .team-code {
            font-size: 12px;
        }
        .score {
            font-size: 14px;
        }
        .game-time {
            font-size: 10px;
        }
        .inner-table th, .inner-table td {
            padding: 4px;
            font-size: 10px;
        }
        .game-participant-name {
            font-size: 10px;
        }
        #participant-filter {
            width: 100%;
            font-size: 14px;
            padding: 6px 10px;
        }
        .profile-link {
            font-size: 10px;
            padding: 4px;
        }
        .game-button {
            min-width: 80px;
            padding: 6px 12px;
            font-size: 12px;
        }
        
        /* Dashboard Widget Mobile Styles */
        .dashboard-widgets-header h2 {
            font-size: 1.2rem;
        }
        
        .section-title {
            font-size: 1rem;
        }
        
        .team-row {
            padding: 8px 6px !important;
            font-size: 0.85rem;
        }
        
        .team-info {
            font-size: 0.85rem;
        }
        
        .team-record {
            font-size: 1rem !important;
        }
        
        /* Platform Leaderboard Mobile Styles */
        .participant-rank {
            font-size: 0.75rem !important;
        }
        
        .participant-name-text {
            font-size: 0.8rem !important;
        }
        
        .participant-league-text {
            font-size: 0.7rem !important;
        }
        
        .leaderboard-expandable-row .team-record {
            font-size: 0.95rem !important;
        }
        
        /* Weekly Rankings Mobile Styles */
        .weekly-rank-row {
            padding: 8px 6px !important;
        }
        
        .weekly-rank-number {
            font-size: 0.8rem !important;
            min-width: 25px !important;
        }
        
        .weekly-participant-name {
            font-size: 0.8rem !important;
        }
        
        .weekly-wins {
            font-size: 0.9rem !important;
        }
        
        /* Draft Steals & SOS Table Mobile Styles */
        .draft-steals-table,
        #sos-table {
            font-size: 0.75rem !important;
        }
        
        .draft-steals-table th,
        .draft-steals-table td,
        #sos-table th,
        #sos-table td {
            padding: 6px 4px !important;
            font-size: 0.75rem !important;
        }
        
        .draft-steal-team-name {
            font-size: 0.75rem !important;
        }
        
        /* League Stats & Rivals Mobile Styles */
        .stats-card .team-row span {
            font-size: 0.8rem !important;
        }
        
        .stats-card .team-row a {
            font-size: 0.8rem !important;
        }
        
        .stats-card .team-row .team-record {
            font-size: 0.85rem !important;
        }
        
        .stats-card .team-row .team-record div {
            font-size: 0.75rem !important;
        }
        
        .stats-card h3 {
            font-size: 0.95rem !important;
        }
        
        .stats-card i.fas {
            font-size: 0.8rem !important;
        }
        
        .game-list-date {
            font-size: 0.75rem;
        }
        
        .game-list-matchup {
            font-size: 0.85rem;
        }
        
        .game-list-score {
            font-size: 0.9rem;
        }
        
        .game-list-outcome {
            font-size: 0.8rem;
        }
    }
    
    @media (max-width: 768px) {
        .game {
            flex: 1 1 100%;
            max-width: 100%;
        }
    }
    
    @media (min-width: 601px) {
        .container {
            max-width: 1000px;
            padding: 20px;
        }
        h1 {
            font-size: 32px;
        }
        #participantsTable th, #participantsTable td {
            font-size: 16px;
        }
        .inner-table th, .inner-table td {
            padding: 8px;
            font-size: 14px;
        }
        .profile-link {
            font-size: 14px;
            padding: 8px;
        }
    }
</style>
</head>
<body>
    <?php 
    // Include the navigation menu component
    include $_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/components/navigation_menu.php'; 
    ?>
    
    <!-- ADDED: Include the league switcher -->
    <?php include '/data/www/default/nba-wins-platform/components/LeagueSwitcher.php'; ?>
    
    <div class="container">
        <header>
            <img src="nba-wins-platform/public/assets/team_logos/Logo.png" alt="NBA Logo" class="basketball-logo">
            <h1>NBA Wins Pool League</h1>
        </header>

        <table id="participantsTable">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Participant</th>
                    <th>Total Wins</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $rank = 1;
                $prevScore = null;
                foreach ($standings as $index => $participant): 
                    if ($prevScore !== null && $participant['total_wins'] < $prevScore) {
                        $rank = $index + 1;
                    }
                    $prevScore = $participant['total_wins'];
                ?>
                <tr class="expandable-row" onclick="toggleTeams('<?php echo $participant['name']; ?>', this)" id="row-<?php echo $participant['name']; ?>">
                    <td>
                        <div class="rank-container">
                            <?php echo $rank; ?>
                            <i class="fas fa-chevron-down expand-indicator"></i>
                            <?php if ($participant['total_wins'] == $highestScore): ?>
                                <i class="fa-solid fa-trophy" style="color: gold;" title="1st Place"></i>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="participant-name">
                        <?php echo htmlspecialchars($participant['name']); ?>
                    </td>
                    <td class="total-wins">
                        <?php echo $participant['total_wins']; ?>
                        <?php if ($participant['wins_change'] > 0): ?>
                            <span class="wins-change">
                                <i class="fa-solid fa-angle-up"></i><?php echo $participant['wins_change']; ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="team-list" id="<?php echo $participant['name']; ?>">
                    <td colspan="3" class="expanded-content">
                        <table class="inner-table">
                            <thead>
                                <tr>
                                    <th>Team</th>
                                    <th>Wins</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($participant['teams'] as $team): ?>
                                <tr>
                                    <td class="team-name">
                                        <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($team['name']); ?>" style="text-decoration: none; color: inherit; display: flex; align-items: center;">
                                            <img src="<?php echo htmlspecialchars($team['logo']); ?>" 
                                                 alt="<?php echo htmlspecialchars($team['name']); ?>" 
                                                 class="team-logo"
                                                 onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjEyIiB5PSIxNiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                                            <span><?php echo htmlspecialchars($team['name']); ?></span>
                                            <?php if ($team['streak'] >= 5 && $team['winstreak'] == 1): ?>
                                                <i class="fas fa-fire win-streak-icon" title="Win streak: <?php echo $team['streak']; ?>"></i>
                                            <?php elseif ($team['streak'] >= 5 && $team['winstreak'] == 0): ?>
                                                <i class="fa-solid fa-snowflake lose-streak-icon" title="Lose streak: <?php echo $team['streak']; ?>"></i>
                                            <?php endif; ?>
                                        </a>
                                    </td>
                                    <td class="team-wins"><?php echo $team['wins']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div style="padding-top: 6px; border-top: 1px solid #eee;">
                            <!-- UPDATED: Using user_id instead of participant_name -->
                            <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?php echo $currentLeagueId; ?>&user_id=<?php echo $participant['user_id']; ?>" class="profile-link">
                                <i class="fa-regular fa-user"></i> <?php echo htmlspecialchars($participant['name']); ?>'s Profile <i class="fa-solid fa-right-from-bracket"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="games-container">
            <div class="date-picker-container">
                <button onclick="changeDate(-1)" class="date-nav-btn" title="Previous Day">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <input type="text" id="date-picker" value="<?php echo $selectedDate; ?>">
                <button onclick="changeDate(1)" class="date-nav-btn" title="Next Day">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
            <h2>
                Games for <?php echo date('F j, Y', strtotime($selectedDate)); ?>
                <?php if ($isNbaCupDate): ?>
                    <img src="/nba-wins-platform/public/assets/league_logos/nba_cup.png" 
                         alt="NBA Cup" 
                         style="height: 40px; vertical-align: middle; margin-left: 8px;"
                         title="NBA Cup Tournament Game">
                <?php endif; ?>
            </h2>
            <div class="filter-container">
                <?php
                $gameCounts = getParticipantGameCounts($games, $participants);
                ?>
                <select id="participant-filter" onchange="filterGames()">
                    <option value="">All Participants</option>
                    <?php foreach ($standings as $participant): 
                        $gameCount = $gameCounts[$participant['name']] ?? 0;
                    ?>
                        <option value="<?php echo htmlspecialchars($participant['name']); ?>">
                            (<?php echo $gameCount; ?>) <?php echo htmlspecialchars($participant['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button onclick="refreshScores()" class="refresh-button" title="Refresh Scores">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </button>
            </div>
            <?php if (empty($games)): ?>
                <p>No games scheduled for this date.</p>
            <?php else: ?>
                <div class="games-grid">
                    <?php 
                    foreach ($games as $gameIndex => $game):
                        // FIXED: Include date in game key to prevent mixing scores from different dates
                        $game_key = $game['date'] . '_' . $game['home_team'] . ' vs ' . $game['away_team'];
                        $current_scores = $latest_scores[$game_key] ?? null;
                        
                        $home_points = $current_scores ? $current_scores['home_points'] : $game['home_points'];
                        $away_points = $current_scores ? $current_scores['away_points'] : $game['away_points'];
                        $game_status = $current_scores ? $current_scores['status'] : $game['status_long'];
                        $is_live = $current_scores && $current_scores['source'] === 'api' && isset($current_scores['game_status']) && $current_scores['game_status'] === 2;
                    ?>
                        <div class="game" 
                             data-home-participant="<?php echo htmlspecialchars($game['home_participant'] ?? ''); ?>"
                             data-away-participant="<?php echo htmlspecialchars($game['away_participant'] ?? ''); ?>">
                            <div class="team home-team">
                                <div class="team-info">
                                    <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($game['home_team']); ?>" 
                                       style="text-decoration: none; color: inherit; display: flex; align-items: center;">
                                        <img src="<?php echo htmlspecialchars(getTeamLogo($game['home_team'])); ?>" 
                                             alt="<?php echo htmlspecialchars($game['home_team']); ?> logo" 
                                             class="team-logo"
                                             onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjEyIiB5PSIxNiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                                        <span class="team-code"><?php echo htmlspecialchars($game['home_team_code'] ?? substr($game['home_team'], 0, 3)); ?></span>
                                    </a>
                                    <?php if (!empty($game['home_participant'])): ?>
                                        <span class="game-participant-name">(<?php echo htmlspecialchars($game['home_participant']); ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="score-container">
                                    <?php if (($game_status === 'Final' || $game_status === 'Finished' || $game['status_long'] === 'Finished') && $home_points > $away_points): ?>
                                        <i class="fa-solid fa-circle-arrow-right" style="margin-right: 8px; color: #228B22;"></i>
                                    <?php endif; ?>
                                    <span class="score"><?php echo $home_points; ?></span>
                                </div>
                            </div>
                            <div class="team away-team">
                                <div class="team-info">
                                    <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($game['away_team']); ?>" 
                                       style="text-decoration: none; color: inherit; display: flex; align-items: center;">
                                        <img src="<?php echo htmlspecialchars(getTeamLogo($game['away_team'])); ?>" 
                                             alt="<?php echo htmlspecialchars($game['away_team']); ?> logo" 
                                             class="team-logo"
                                             onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjEyIiB5PSIxNiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                                        <span class="team-code"><?php echo htmlspecialchars($game['away_team_code'] ?? substr($game['away_team'], 0, 3)); ?></span>
                                    </a>
                                    <?php if (!empty($game['away_participant'])): ?>
                                        <span class="game-participant-name">(<?php echo htmlspecialchars($game['away_participant']); ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="score-container">
                                    <?php if (($game_status === 'Final' || $game_status === 'Finished' || $game['status_long'] === 'Finished') && $away_points > $home_points): ?>
                                        <i class="fa-solid fa-circle-arrow-right" style="margin-right: 8px; color: #228B22;"></i>
                                    <?php endif; ?> 
                                    <span class="score"><?php echo $away_points; ?></span>
                                </div>
                            </div>
                                                
                            <?php if ($game_status === 'Final' || $game['status_long'] === 'Finished'): ?>
                                <!-- FIXED: Finished game with proper grey background styling -->
                                <div class="game-buttons">
                                    <div class="game-button watch-button" style="background-color: #000000; cursor: default; pointer-events: none;">
                                        Final
                                    </div>
                                    <a href="/nba-wins-platform/stats/game_details.php?home_team=<?php echo urlencode($game['home_team_code'] ?? substr($game['home_team'], 0, 3)); ?>&away_team=<?php echo urlencode($game['away_team_code'] ?? substr($game['away_team'], 0, 3)); ?>&date=<?php echo urlencode($game['date']); ?>" 
                                       class="game-button stats-button">
                                        Box Score
                                    </a>
                                </div>
                            <?php else: ?>
                                <!-- Live/upcoming game buttons -->
                                <?php
                                $gameKey = $game['home_team'] . '-' . $game['away_team'];
                                $streamUrl = isset($streamUrls[$gameKey]) ? $streamUrls[$gameKey] : 'https://thetvapp.to/nba';
                                $startTime = new DateTime($game['start_time']);
                                $currentTime = new DateTime();
                                $hasStarted = $currentTime >= $startTime;
                                
                                $time_display = $startTime->format('g:i A');
                               if ($is_live && !empty($current_scores['clock']) && !empty($current_scores['period'])) {
                                    $time_display = $current_scores['clock'] . ' Q' . $current_scores['period'];
                                }
                                ?>
                                <div class="game-buttons">
                                    <a href="<?php echo htmlspecialchars($streamUrl); ?>" 
                                       target="_blank" 
                                       rel="noopener noreferrer" 
                                       class="game-button watch-button <?php echo $is_live ? 'live-game' : ''; ?>">
                                        <i class="fa-solid fa-video"></i> 
                                        <?php 
                                        if (!empty($game['stream_game_time'])) {
                                            $streamTime = new DateTime($game['stream_game_time']);
                                            echo $is_live ? $current_scores['clock'] . ' Q' . $current_scores['period'] : $streamTime->format('g:i A');
                                        } else {
                                            echo $is_live ? $current_scores['clock'] . ' Q' . $current_scores['period'] : $startTime->format('g:i A');
                                        }
                                        ?>
                                    </a>
                                    <a href="<?php echo $hasStarted ? 
                                        '/nba-wins-platform/stats/game_details.php?home_team=' . urlencode($game['home_team_code'] ?? substr($game['home_team'], 0, 3)) . '&away_team=' . urlencode($game['away_team_code'] ?? substr($game['away_team'], 0, 3)) . '&date=' . urlencode($game['date']) :
                                        '/nba-wins-platform/stats/team_comparison.php?home_team=' . urlencode($game['home_team_code'] ?? substr($game['home_team'], 0, 3)) . '&away_team=' . urlencode($game['away_team_code'] ?? substr($game['away_team'], 0, 3)) . '&date=' . urlencode($game['date']); ?>" 
                                       class="game-button stats-button">
                                        <?php echo $hasStarted ? 'Box Score' : 'Preview'; ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Dashboard Widgets Section -->
        <?php if (!empty($pinnedWidgets)): ?>
        <div class="dashboard-widgets-container">
            <div class="dashboard-widgets-header">
                <h2>Dashboard</h2>
                <button class="widget-edit-btn <?php echo $widgetEditMode ? 'active' : ''; ?>" onclick="toggleEditMode()" title="<?php echo $widgetEditMode ? 'Done editing' : 'Edit dashboard'; ?>">
                    <i class="fas fa-<?php echo $widgetEditMode ? 'check' : 'edit'; ?>"></i>
                </button>
            </div>
            
            <?php foreach ($pinnedWidgets as $widget): ?>
                <?php echo $dashboardWidget->render(
                    $widget['widget_type'], 
                    $_SESSION['user_id'], 
                    $currentLeagueId,
                    $widgetEditMode,
                    $selectedDate  // Pass the selected date to widgets
                ); ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <script>
        function toggleTeams(participantId, row) {
            var teamList = document.getElementById(participantId);
            var expandableRow = document.getElementById('row-' + participantId);
            
            expandableRow.classList.toggle('expanded');
            teamList.classList.toggle('show');
        }
        
        function filterGames() {
            const selectedParticipant = document.getElementById('participant-filter').value;
            const games = document.querySelectorAll('.game');
            
            games.forEach(game => {
                const homeParticipant = game.getAttribute('data-home-participant');
                const awayParticipant = game.getAttribute('data-away-participant');
                
                if (selectedParticipant === '' || 
                    homeParticipant === selectedParticipant || 
                    awayParticipant === selectedParticipant) {
                    game.classList.remove('hidden');
                } else {
                    game.classList.add('hidden');
                }
            });
        }

        function refreshScores() {
            // Save scroll position before refreshing
            sessionStorage.setItem('gamesScrollPosition', window.scrollY);
            window.location.href = window.location.pathname + "?date=<?php echo $selectedDate; ?>";
        }

        flatpickr("#date-picker", {
            dateFormat: "Y-m-d",
            defaultDate: "<?php echo $selectedDate; ?>",
            onChange: function(selectedDates, dateStr, instance) {
                // Save scroll position before navigating
                sessionStorage.setItem('gamesScrollPosition', window.scrollY);
                window.location.href = '?date=' + dateStr;
            }
        });
        
        // Function to change date by days
        function changeDate(days) {
            // Save scroll position before navigating
            sessionStorage.setItem('gamesScrollPosition', window.scrollY);
            
            const currentDate = new Date('<?php echo $selectedDate; ?>');
            currentDate.setDate(currentDate.getDate() + days);
            const newDate = currentDate.toISOString().split('T')[0];
            window.location.href = '?date=' + newDate;
        }
        
        // Restore games scroll position on page load
        window.addEventListener('load', function() {
            const savedGamesScroll = sessionStorage.getItem('gamesScrollPosition');
            if (savedGamesScroll !== null) {
                setTimeout(function() {
                    window.scrollTo(0, parseInt(savedGamesScroll));
                    sessionStorage.removeItem('gamesScrollPosition');
                }, 100);
            }
        });
        
        // Widget edit mode toggle
        function toggleEditMode() {
            // Save current scroll position
            sessionStorage.setItem('scrollPosition', window.scrollY);
            
            const currentUrl = new URL(window.location.href);
            const editMode = currentUrl.searchParams.get('edit_widgets');
            
            if (editMode === '1') {
                currentUrl.searchParams.delete('edit_widgets');
            } else {
                currentUrl.searchParams.set('edit_widgets', '1');
            }
            
            window.location.href = currentUrl.toString();
        }
        
        // Restore general scroll position on page load (for widget edits)
        document.addEventListener('DOMContentLoaded', function() {
            const savedScrollPosition = sessionStorage.getItem('scrollPosition');
            if (savedScrollPosition !== null) {
                window.scrollTo(0, parseInt(savedScrollPosition));
                sessionStorage.removeItem('scrollPosition');
            }
        });
        
        // Move widget up or down
        function moveWidget(widgetType, direction) {
            const formData = new FormData();
            formData.append('action', 'reorder');
            formData.append('widget_type', widgetType);
            formData.append('direction', direction);
            
            fetch('/nba-wins-platform/core/handle_widget_pin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Reload to show new order
                    window.location.reload();
                } else {
                    if (data.error && !data.error.includes('Already at')) {
                        alert('Error: ' + data.error);
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        }
        
        // Remove widget
        function removeWidget(widgetType) {
            if (!confirm('Are you sure you want to remove this widget from your dashboard?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'unpin');
            formData.append('widget_type', widgetType);
            
            fetch('/nba-wins-platform/core/handle_widget_pin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Reload to show updated widgets
                    window.location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        }
        
        // Show widget controls in edit mode
        document.addEventListener('DOMContentLoaded', function() {
            const editMode = <?php echo $widgetEditMode ? 'true' : 'false'; ?>;
            if (editMode) {
                document.querySelectorAll('.widget-controls').forEach(function(el) {
                    el.classList.add('show');
                });
            }
        });
    </script>
</body>
</html>