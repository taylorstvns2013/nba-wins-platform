<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

$currentLeagueId = $leagueContext['league_id'];

// Get current user info from session (since requireAuthentication sets up the auth context)
$currentUserId = $_SESSION['user_id'] ?? null;
$currentUser = null;
if ($currentUserId) {
    $stmt = $pdo->prepare("SELECT display_name, username FROM users WHERE id = ?");
    $stmt->execute([$currentUserId]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
}

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
        return '/nba-wins-platform/public/assets/team_logos/' . $logoMap[$teamName];
    }
    
    // Fallback: try lowercase with underscores
    $filename = strtolower(str_replace(' ', '_', $teamName)) . '.png';
    return '/nba-wins-platform/public/assets/team_logos/' . $filename;
}

// Get team codes and date from URL
$home_team = $_GET['home_team'] ?? null;
$away_team = $_GET['away_team'] ?? null;
$game_date = $_GET['date'] ?? date('Y-m-d');

if (!$home_team || !$away_team) {
    die("Team information not provided");
}

// Query using nba_wins_platform database structure
$stmt = $pdo->prepare("
    SELECT 
        g.*,
        nt1.name as home_team_name,
        nt2.name as away_team_name,
        nt1.logo_filename as home_logo,
        nt2.logo_filename as away_logo,
        t1.win as home_wins,
        t1.loss as home_losses,
        t2.win as away_wins,
        t2.loss as away_losses,
        nt1.id as home_team_id,
        nt2.id as away_team_id,
        (SELECT COALESCE(u1.display_name, lp1.participant_name)
         FROM league_participant_teams lpt1 
         JOIN league_participants lp1 ON lpt1.league_participant_id = lp1.id 
         LEFT JOIN users u1 ON lp1.user_id = u1.id
         WHERE lpt1.team_name = nt1.name AND lp1.league_id = ? 
         LIMIT 1) AS home_participant,
        (SELECT COALESCE(u2.display_name, lp2.participant_name)
         FROM league_participant_teams lpt2 
         JOIN league_participants lp2 ON lpt2.league_participant_id = lp2.id 
         LEFT JOIN users u2 ON lp2.user_id = u2.id
         WHERE lpt2.team_name = nt2.name AND lp2.league_id = ? 
         LIMIT 1) AS away_participant
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

// Format time manually from the database time
list($hours, $minutes) = explode(':', substr($game['start_time'], 11, 5));
$hours = (int)$hours;
$ampm = $hours >= 12 ? 'PM' : 'AM';
$hours = $hours > 12 ? $hours - 12 : ($hours == 0 ? 12 : $hours);
$game['formatted_time'] = $hours . ':' . $minutes . ' ' . $ampm;

// Include TeamStatsCalculator for database-driven stats
require_once '/data/www/default/nba-wins-platform/core/TeamStatsCalculator.php';

// Initialize stats calculator (instant database-driven stats)
$statsCalculator = new TeamStatsCalculator($pdo);

// Get comprehensive stats from database for both teams
$home_stats = $statsCalculator->getTeamStats($game['home_team_name']);
$away_stats = $statsCalculator->getTeamStats($game['away_team_name']);

// Check if EITHER team has stats available (changed from AND to OR)
$statsAvailable = false;
if (($home_stats && $home_stats['GP'] > 0) || ($away_stats && $away_stats['GP'] > 0)) {
    $statsAvailable = true;
    
    // If one team doesn't have stats, create empty array with zeros
    if (!$home_stats || $home_stats['GP'] == 0) {
        $home_stats = [
            'GP' => 0, 'PTS' => 0, 'FG_PCT' => 0, 'FG3_PCT' => 0,
            'REB' => 0, 'AST' => 0, 'STL' => 0, 'BLK' => 0, 'PLUS_MINUS' => 0
        ];
    }
    if (!$away_stats || $away_stats['GP'] == 0) {
        $away_stats = [
            'GP' => 0, 'PTS' => 0, 'FG_PCT' => 0, 'FG3_PCT' => 0,
            'REB' => 0, 'AST' => 0, 'STL' => 0, 'BLK' => 0, 'PLUS_MINUS' => 0
        ];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($game['home_team_name']); ?> vs <?php echo htmlspecialchars($game['away_team_name']); ?> - Game Preview</title>
    <link rel="apple-touch-icon" type="image/png" href="/nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="/nba-wins-platform/public/assets/favicon/favicon.png">
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
    
    /* Desktop Container */
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
    
    /* Team Matchup Header - Desktop */
    .matchup-header {
        background: linear-gradient(to bottom, #f8f9fa 0%, #ffffff 100%);
        padding: 2rem;
        border-radius: 12px;
        margin-bottom: 2rem;
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
    
    .team-row:first-child {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    }
    
    .team-row:last-of-type {
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
    
    .team-logo-small {
        display: none;
    }
    
    .team-details {
        position: relative;
    }
    
    .team-details-right {
        position: relative;
    }
    
    .team-name-small {
        font-size: 1.3rem;
        font-weight: 700;
        color: #1a1a1a;
        margin-bottom: 0.25rem;
        line-height: 1.2;
    }
    
    .team-record {
        font-size: 1.6rem;
        color: #212121;
        font-weight: 700;
        letter-spacing: -0.5px;
        margin-bottom: 0.25rem;
    }
    
    .team-owner-small {
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
    
    .game-time-info {
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
    
    .game-time-info .time-section {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .game-time-info .divider {
        color: rgba(255, 255, 255, 0.5);
        font-weight: 400;
    }
    
    .arena-name {
        display: inline;
        font-size: 0.95rem;
        color: white;
        font-weight: 500;
    }
    
    /* Stats Section - Desktop */
    .stats-section {
        padding: 0;
    }
    
    .section-title {
        padding: 1.5rem 0 1.25rem;
        font-size: 1.3rem;
        font-weight: 700;
        color: #1a1a1a;
        text-align: center;
        margin: 0;
        border-top: 2px solid #f0f0f0;
    }
    
    .stat-row {
        display: flex;
        align-items: stretch;
        border-bottom: 1px solid #f0f0f0;
        min-height: 64px;
    }
    
    .stat-row:last-child {
        border-bottom: none;
    }
    
    .stat-value {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.25rem 1rem;
        font-size: 1.2rem;
        font-weight: 700;
        color: #1a1a1a;
        background-color: #fafafa;
    }
    
    .stat-label {
        flex: 1.5;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.25rem 1.5rem;
        font-size: 1rem;
        font-weight: 600;
        color: #555;
        text-align: center;
        background-color: #ffffff;
        border-left: 1px solid #f0f0f0;
        border-right: 1px solid #f0f0f0;
    }
    
    .stat-value.higher {
        background-color: #d4edda;
        color: #1e7e34;
    }
    
    .no-stats-message {
        text-align: center;
        padding: 3rem 2rem;
        color: #666;
        font-size: 1rem;
    }
    
    .no-stats-message i {
        font-size: 3rem;
        margin-bottom: 1rem;
        color: #76a5af;
        display: block;
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
        
        .team-row {
            padding: 1.25rem 1rem;
            margin-bottom: 0.75rem;
            min-height: 80px;
        }
        
        .team-row:last-of-type {
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
        
        .team-name-small {
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }
        
        .team-record {
            font-size: 1.3rem;
            margin-bottom: 0.25rem;
        }
        
        .team-owner-small {
            font-size: 0.8rem;
            margin-top: 0.25rem;
        }
        
        .vs-divider {
            font-size: 0.85rem;
            padding: 0.375rem 0;
        }
        
        .game-time-info {
            font-size: 0.85rem;
            padding: 0.65rem 0.875rem;
            margin-top: 0.75rem;
            gap: 0.75rem;
        }
        
        .game-time-info .time-section {
            gap: 0.375rem;
        }
        
        .arena-name {
            font-size: 0.85rem;
        }
        
        .section-title {
            padding: 1rem;
            font-size: 1rem;
            background-color: #fafafa;
            border-top: 1px solid #e9ecef;
        }
        
        .stat-row {
            min-height: 52px;
        }
        
        .stat-value {
            font-size: 0.95rem;
            padding: 0.875rem 0.5rem;
        }
        
        .stat-label {
            font-size: 0.85rem;
            padding: 0.875rem 0.75rem;
            flex: 1.4;
        }
        
        .no-stats-message {
            padding: 2rem 1rem;
            font-size: 0.9rem;
        }
        
        .no-stats-message i {
            font-size: 2.5rem;
        }
    }
    
    @media (max-width: 400px) {
        .team-name-small {
            font-size: 1rem;
        }
        
        .team-record {
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
        
        .stat-value {
            font-size: 0.9rem;
            padding: 0.875rem 0.375rem;
        }
        
        .stat-label {
            font-size: 0.8rem;
            padding: 0.875rem 0.5rem;
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
                <img src="<?php echo htmlspecialchars(getTeamLogo($game['home_team_name'])); ?>" 
                     alt="<?php echo htmlspecialchars($game['home_team_name']); ?>" 
                     class="team-logo-background"
                     onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjEyIiB5PSIxNiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                <div class="team-info-left">
                    <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($game['home_team_name']); ?>">
                        <img src="<?php echo htmlspecialchars(getTeamLogo($game['home_team_name'])); ?>" 
                             alt="<?php echo htmlspecialchars($game['home_team_name']); ?>" 
                             class="team-logo-visible"
                             onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjEyIiB5PSIxNiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                    </a>
                    <div class="team-details">
                        <div class="team-name-small"><?php echo htmlspecialchars($game['home_team_name']); ?></div>
                        <div class="team-record"><?php echo $game['home_wins']; ?>-<?php echo $game['home_losses']; ?></div>
                        <?php if (isset($game['home_participant'])): ?>
                            <div class="team-owner-small">(<?php echo htmlspecialchars($game['home_participant']); ?>)</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="vs-divider">VS</div>
            
            <!-- Away Team -->
            <div class="team-row away-team">
                <img src="<?php echo htmlspecialchars(getTeamLogo($game['away_team_name'])); ?>" 
                     alt="<?php echo htmlspecialchars($game['away_team_name']); ?>" 
                     class="team-logo-background"
                     onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjEyIiB5PSIxNiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                <div class="team-info-right">
                    <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($game['away_team_name']); ?>">
                        <img src="<?php echo htmlspecialchars(getTeamLogo($game['away_team_name'])); ?>" 
                             alt="<?php echo htmlspecialchars($game['away_team_name']); ?>" 
                             class="team-logo-visible"
                             onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjEyIiB5PSIxNiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                    </a>
                    <div class="team-details-right">
                        <div class="team-name-small"><?php echo htmlspecialchars($game['away_team_name']); ?></div>
                        <div class="team-record"><?php echo $game['away_wins']; ?>-<?php echo $game['away_losses']; ?></div>
                        <?php if (isset($game['away_participant'])): ?>
                            <div class="team-owner-small">(<?php echo htmlspecialchars($game['away_participant']); ?>)</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Game Time & Arena -->
            <div class="game-time-info">
                <div class="time-section">
                    <i class="fa-regular fa-clock"></i>
                    <span><?php echo $game['formatted_time']; ?></span>
                </div>
                <span class="divider">•</span>
                <span class="arena-name"><?php echo htmlspecialchars($game['arena']); ?></span>
            </div>
        </div>
        
        <!-- Stats Section -->
        <div class="stats-section">
            <h2 class="section-title">Team Stats</h2>
            
            <?php if ($statsAvailable): ?>
                <!-- Points Per Game -->
                <div class="stat-row">
                    <div class="stat-value <?php echo $home_stats['PTS'] > $away_stats['PTS'] ? 'higher' : ''; ?>">
                        <?php echo number_format($home_stats['PTS'], 1); ?>
                    </div>
                    <div class="stat-label">Points Per Game</div>
                    <div class="stat-value <?php echo $away_stats['PTS'] > $home_stats['PTS'] ? 'higher' : ''; ?>">
                        <?php echo number_format($away_stats['PTS'], 1); ?>
                    </div>
                </div>
                
                <!-- Field Goal % -->
                <div class="stat-row">
                    <div class="stat-value <?php echo $home_stats['FG_PCT'] > $away_stats['FG_PCT'] ? 'higher' : ''; ?>">
                        <?php echo number_format($home_stats['FG_PCT'] * 100, 1); ?>%
                    </div>
                    <div class="stat-label">Field Goal %</div>
                    <div class="stat-value <?php echo $away_stats['FG_PCT'] > $home_stats['FG_PCT'] ? 'higher' : ''; ?>">
                        <?php echo number_format($away_stats['FG_PCT'] * 100, 1); ?>%
                    </div>
                </div>
                
                <!-- 3-Point % -->
                <div class="stat-row">
                    <div class="stat-value <?php echo $home_stats['FG3_PCT'] > $away_stats['FG3_PCT'] ? 'higher' : ''; ?>">
                        <?php echo number_format($home_stats['FG3_PCT'] * 100, 1); ?>%
                    </div>
                    <div class="stat-label">3-Point %</div>
                    <div class="stat-value <?php echo $away_stats['FG3_PCT'] > $home_stats['FG3_PCT'] ? 'higher' : ''; ?>">
                        <?php echo number_format($away_stats['FG3_PCT'] * 100, 1); ?>%
                    </div>
                </div>
                
                <!-- Rebounds -->
                <div class="stat-row">
                    <div class="stat-value <?php echo $home_stats['REB'] > $away_stats['REB'] ? 'higher' : ''; ?>">
                        <?php echo number_format($home_stats['REB'], 1); ?>
                    </div>
                    <div class="stat-label">Rebounds Per Game</div>
                    <div class="stat-value <?php echo $away_stats['REB'] > $home_stats['REB'] ? 'higher' : ''; ?>">
                        <?php echo number_format($away_stats['REB'], 1); ?>
                    </div>
                </div>
                
                <!-- Assists -->
                <div class="stat-row">
                    <div class="stat-value <?php echo $home_stats['AST'] > $away_stats['AST'] ? 'higher' : ''; ?>">
                        <?php echo number_format($home_stats['AST'], 1); ?>
                    </div>
                    <div class="stat-label">Assists Per Game</div>
                    <div class="stat-value <?php echo $away_stats['AST'] > $home_stats['AST'] ? 'higher' : ''; ?>">
                        <?php echo number_format($away_stats['AST'], 1); ?>
                    </div>
                </div>
                
                <!-- Steals -->
                <div class="stat-row">
                    <div class="stat-value <?php echo $home_stats['STL'] > $away_stats['STL'] ? 'higher' : ''; ?>">
                        <?php echo number_format($home_stats['STL'], 1); ?>
                    </div>
                    <div class="stat-label">Steals Per Game</div>
                    <div class="stat-value <?php echo $away_stats['STL'] > $home_stats['STL'] ? 'higher' : ''; ?>">
                        <?php echo number_format($away_stats['STL'], 1); ?>
                    </div>
                </div>
                
                <!-- Blocks -->
                <div class="stat-row">
                    <div class="stat-value <?php echo $home_stats['BLK'] > $away_stats['BLK'] ? 'higher' : ''; ?>">
                        <?php echo number_format($home_stats['BLK'], 1); ?>
                    </div>
                    <div class="stat-label">Blocks Per Game</div>
                    <div class="stat-value <?php echo $away_stats['BLK'] > $home_stats['BLK'] ? 'higher' : ''; ?>">
                        <?php echo number_format($away_stats['BLK'], 1); ?>
                    </div>
                </div>
                
                <!-- Plus/Minus -->
                <div class="stat-row">
                    <div class="stat-value <?php echo $home_stats['PLUS_MINUS'] > $away_stats['PLUS_MINUS'] ? 'higher' : ''; ?>">
                        <?php 
                            $home_pm = $home_stats['PLUS_MINUS'];
                            echo ($home_pm >= 0 ? '+' : '') . number_format($home_pm, 1); 
                        ?>
                    </div>
                    <div class="stat-label">Plus/Minus</div>
                    <div class="stat-value <?php echo $away_stats['PLUS_MINUS'] > $home_stats['PLUS_MINUS'] ? 'higher' : ''; ?>">
                        <?php 
                            $away_pm = $away_stats['PLUS_MINUS'];
                            echo ($away_pm >= 0 ? '+' : '') . number_format($away_pm, 1); 
                        ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="no-stats-message">
                    <i class="fas fa-info-circle"></i>
                    <p>Team statistics will be available after the first regular season games.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
