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
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        line-height: 1.6;
        margin: 0;
        padding: 20px;
        background-image: url('/nba-wins-platform/public/assets/background/geometric_white.png');
        background-repeat: repeat;
        background-attachment: fixed;
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
        background-color: #424242;
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
        background-color: rgba(245, 245, 245, 0.8);
        color: #424242;
    }
    
    .menu-link i {
        width: 20px;
    }
    
    .container {
        max-width: 1200px;
        margin: 0 auto;
        background-color: white;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        position: relative;
    }
    
    .back-button {
        display: inline-block;
        padding: 10px 20px;
        background-color: #000;
        color: white;
        text-decoration: none;
        border-radius: 4px;
        margin-bottom: 1rem;
    }
    
    .header-navigation {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #e0e0e0;
    }
    
    .user-info {
        display: flex;
        align-items: center;
        gap: 1rem;
        font-size: 0.9rem;
        color: #666;
    }
    
    .logout-link {
        color: #dc3545;
        text-decoration: none;
        font-weight: 500;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        transition: background-color 0.2s;
    }
    
    .logout-link:hover {
        background-color: #f8f9fa;
        text-decoration: underline;
    }
    
    /* Header and Team Styles */
    .comparison-header {
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 1.5rem;
        margin-bottom: 2rem;
    }
    
    .team-matchup {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 2rem;
    }
    
    .team-container {
        text-align: center;
        flex: 1;
    }
    
    .team-logo {
        width: 120px;
        height: 120px;
        object-fit: contain;
        margin-bottom: 0.5rem;
    }
    
    .team-name {
        font-size: 1.25rem;
        font-weight: bold;
        margin-bottom: 0.25rem;
    }
    
    .game-info {
        text-align: center;
        flex: 0 0 auto;
        max-width: 200px;
    }
    
    .game-time {
        background-color: #76a5af;
        color: white;
        padding: 0.75rem;
        border-radius: 4px;
        margin-bottom: 0.5rem;
    }
    
    .arena-info {
        color: #666;
        font-size: 0.9rem;
    }
    
    .record {
        font-size: 2rem;
        font-weight: bold;
        margin: 0.5rem 0;
    }
    
    .team-owner {
        color: #666;
        font-style: italic;
        font-size: 0.9rem;
    }
    
    /* Stats Grid */
    .team-names-container {
        display: none;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        gap: 2rem;
        margin: 2rem 0;
        max-width: 900px;
        margin-left: auto;
        margin-right: auto;
    }
    
    .stat-row {
        display: contents;
    }
    
    .stat-values-container {
        grid-column: 1 / -1;
        display: grid;
        grid-template-columns: subgrid;
    }
    
    .stat-label {
        grid-column: 2;
        text-align: center;
        font-weight: bold;
        padding: 1rem;
        background-color: #f8f9fa;
        border-radius: 8px;
        min-width: 160px;
    }
    
    .stat-value {
        text-align: center;
        padding: 1rem;
        background-color: #ffffff;
        border: 1px solid #f0f0f0;
        border-radius: 8px;
    }
    
    .stat-row .stat-values-container .stat-value:first-child {
        grid-column: 1;
        justify-content: flex-end;
    }
    
    .stat-row .stat-values-container .stat-value:last-child {
        grid-column: 3;
        justify-content: flex-start;
    }
    
    .stat-value.higher {
        color: #228B22;
        font-weight: bold;
        background-color: #f0fff0;
    }
    
    /* Mobile styles */
    @media (max-width: 768px) {
        body {
            padding: 10px;
        }
    
        .container {
            padding: 15px;
        }
    
        .comparison-header {
            padding: 1rem;
        }
    
        .team-matchup {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }
    
        .team-logo {
            width: 80px;
            height: 80px;
        }
    
        .team-name {
            font-size: 1.1rem;
        }
    
        .record {
            font-size: 1.5rem;
            margin: 0.25rem 0;
        }
    
        .team-owner {
            font-size: 0.8rem;
        }
    
        .game-info {
            max-width: 150px;
        }
    
        .game-time {
            font-size: 0.9rem;
            padding: 0.5rem;
        }
    
        .back-button {
            display: inline-block;
            padding: 10px 20px;
            background-color: #000;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        
        .header-navigation {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }
        
        .user-info {
            font-size: 0.8rem;
            align-self: flex-end;
        }
    
        /* Stats Mobile Layout */
        .stats-grid {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
    
        .stat-row {
            display: flex;
            flex-direction: column;
            background-color: #f8f9fa;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }
    
        .stat-label {
            width: 180px;
            margin: 0 auto;
            text-align: center;
            padding: 0.75rem;
            background-color: #e9ecef;
            font-weight: bold;
            font-size: 0.9rem;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    
        .stat-values-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem;
            flex-direction: row;
            min-height: 60px;
        }
    
        .stat-value {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            padding: 0.75rem;
            font-size: 0.9rem;
            border-right: 1px solid #e9ecef;
        }
    
        .stat-values-container .stat-value:first-child,
        .stat-values-container .stat-value:last-child {
            grid-column: unset;
            justify-self: unset;
        }
    
        .stat-value:last-child {
            border-right: none;
        }
        
        /* Additional Mobile Refinements */
        .stat-row {
            margin-bottom: 0.75rem;
        }
        
        .stat-label {
            font-size: 0.85rem !important;
            padding: 0.6rem !important;
        }
        
        .stat-value {
            font-size: 0.85rem !important;
            padding: 0.6rem 0.5rem !important;
        }
    }
        .section {
        margin: 2rem 0;
    }
    
    .inactive-icon {
        margin-right: 8px;
        color: #dc3545;
    }
    
    @media (max-width: 768px) {
        .section {
            padding: 0 0.5rem;
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
        <div class="header-navigation">
            <a href="/index.php" class="back-button">← Back to League</a>
            <?php if ($currentUser): ?>
                <div class="user-info">
                    Welcome, <?php echo htmlspecialchars($currentUser['display_name']); ?>
                    <a href="/nba-wins-platform/auth/logout.php" class="logout-link">Logout</a>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="comparison-header">
            <h1 class="text-3xl font-bold text-center mb-4">Game Details</h1>
            
            <div class="team-matchup">
                <div class="team-container">
                    <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($game['home_team_name']); ?>">
                        <img src="<?php echo htmlspecialchars(getTeamLogo($game['home_team_name'])); ?>" 
                             alt="<?php echo htmlspecialchars($game['home_team_name']); ?>" 
                             class="team-logo"
                             style="cursor: pointer;"
                             onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjEyIiB5PSIxNiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                    </a>
                    <div class="team-name"><?php echo htmlspecialchars($game['home_team_name']); ?></div>
                    <div class="record"><?php echo $game['home_wins']; ?>-<?php echo $game['home_losses']; ?></div>
                    <div class="team-owner"><?php echo isset($game['home_participant']) ? "({$game['home_participant']})" : ''; ?></div>
                </div>
                
                <div class="game-info">
                    <div class="game-time">
                        <i class="fa-regular fa-clock"></i> <?php echo $game['formatted_time']; ?>
                    </div>
                    <div class="arena-info">
                        <?php echo htmlspecialchars($game['arena']); ?>
                    </div>
                </div>
                
                <div class="team-container">
                    <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($game['away_team_name']); ?>">
                        <img src="<?php echo htmlspecialchars(getTeamLogo($game['away_team_name'])); ?>" 
                             alt="<?php echo htmlspecialchars($game['away_team_name']); ?>" 
                             class="team-logo"
                             style="cursor: pointer;"
                             onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjEyIiB5PSIxNiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                    </a>
                    <div class="team-name"><?php echo htmlspecialchars($game['away_team_name']); ?></div>
                    <div class="record"><?php echo $game['away_wins']; ?>-<?php echo $game['away_losses']; ?></div>
                    <div class="team-owner"><?php echo isset($game['away_participant']) ? "({$game['away_participant']})" : ''; ?></div>
                </div>
            </div>
        </div>
        
        <div class="stats-grid">
            <?php if ($statsAvailable): ?>
                <!-- Points Per Game -->
                <div class="stat-row">
                    <div class="stat-values-container">
                        <div class="stat-value <?php echo $home_stats['PTS'] > $away_stats['PTS'] ? 'higher' : ''; ?>">
                            <?php echo number_format($home_stats['PTS'], 1); ?>
                        </div>
                        <div class="stat-label">Points Per Game</div>
                        <div class="stat-value <?php echo $away_stats['PTS'] > $home_stats['PTS'] ? 'higher' : ''; ?>">
                            <?php echo number_format($away_stats['PTS'], 1); ?>
                        </div>
                    </div>
                </div>
        
                <!-- Field Goal % -->
                <div class="stat-row">
                    <div class="stat-values-container">
                        <div class="stat-value <?php echo $home_stats['FG_PCT'] > $away_stats['FG_PCT'] ? 'higher' : ''; ?>">
                            <?php echo number_format($home_stats['FG_PCT'] * 100, 1); ?>%
                        </div>
                        <div class="stat-label">Field Goal %</div>
                        <div class="stat-value <?php echo $away_stats['FG_PCT'] > $home_stats['FG_PCT'] ? 'higher' : ''; ?>">
                            <?php echo number_format($away_stats['FG_PCT'] * 100, 1); ?>%
                        </div>
                    </div>
                </div>
        
                <!-- 3-Point % -->
                <div class="stat-row">
                    <div class="stat-values-container">
                        <div class="stat-value <?php echo $home_stats['FG3_PCT'] > $away_stats['FG3_PCT'] ? 'higher' : ''; ?>">
                            <?php echo number_format($home_stats['FG3_PCT'] * 100, 1); ?>%
                        </div>
                        <div class="stat-label">3-Point %</div>
                        <div class="stat-value <?php echo $away_stats['FG3_PCT'] > $home_stats['FG3_PCT'] ? 'higher' : ''; ?>">
                            <?php echo number_format($away_stats['FG3_PCT'] * 100, 1); ?>%
                        </div>
                    </div>
                </div>
        
                <!-- Rebounds -->
                <div class="stat-row">
                    <div class="stat-values-container">
                        <div class="stat-value <?php echo $home_stats['REB'] > $away_stats['REB'] ? 'higher' : ''; ?>">
                            <?php echo number_format($home_stats['REB'], 1); ?>
                        </div>
                        <div class="stat-label">Rebounds Per Game</div>
                        <div class="stat-value <?php echo $away_stats['REB'] > $home_stats['REB'] ? 'higher' : ''; ?>">
                            <?php echo number_format($away_stats['REB'], 1); ?>
                        </div>
                    </div>
                </div>
        
                <!-- Assists -->
                <div class="stat-row">
                    <div class="stat-values-container">
                        <div class="stat-value <?php echo $home_stats['AST'] > $away_stats['AST'] ? 'higher' : ''; ?>">
                            <?php echo number_format($home_stats['AST'], 1); ?>
                        </div>
                        <div class="stat-label">Assists Per Game</div>
                        <div class="stat-value <?php echo $away_stats['AST'] > $home_stats['AST'] ? 'higher' : ''; ?>">
                            <?php echo number_format($away_stats['AST'], 1); ?>
                        </div>
                    </div>
                </div>
        
                <!-- Steals -->
                <div class="stat-row">
                    <div class="stat-values-container">
                        <div class="stat-value <?php echo $home_stats['STL'] > $away_stats['STL'] ? 'higher' : ''; ?>">
                            <?php echo number_format($home_stats['STL'], 1); ?>
                        </div>
                        <div class="stat-label">Steals Per Game</div>
                        <div class="stat-value <?php echo $away_stats['STL'] > $home_stats['STL'] ? 'higher' : ''; ?>">
                            <?php echo number_format($away_stats['STL'], 1); ?>
                        </div>
                    </div>
                </div>
        
                <!-- Blocks -->
                <div class="stat-row">
                    <div class="stat-values-container">
                        <div class="stat-value <?php echo $home_stats['BLK'] > $away_stats['BLK'] ? 'higher' : ''; ?>">
                            <?php echo number_format($home_stats['BLK'], 1); ?>
                        </div>
                        <div class="stat-label">Blocks Per Game</div>
                        <div class="stat-value <?php echo $away_stats['BLK'] > $home_stats['BLK'] ? 'higher' : ''; ?>">
                            <?php echo number_format($away_stats['BLK'], 1); ?>
                        </div>
                    </div>
                </div>
        
                <!-- Plus/Minus -->
                <div class="stat-row">
                    <div class="stat-values-container">
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
                </div>
            <?php else: ?>
                <p class="text-center col-span-3">Team statistics will be available after the first regular season games.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>