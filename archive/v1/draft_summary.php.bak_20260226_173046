<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['current_league_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once '/data/www/default/nba-wins-platform/config/db_connection.php';

$user_id = $_SESSION['user_id'];
$league_id = $_SESSION['current_league_id'];
$currentLeagueId = $league_id; // Define for navigation menu

// Team logo mapping function - maps team names to actual logo filenames
function getTeamLogo($teamName) {
    // Normalize the team name first
    $teamName = trim($teamName);
    
    // Handle specific team name variations
    $nameVariations = [
        // Clippers variations - most common issue
        'LA Clippers' => 'Los Angeles Clippers',
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
    
    // Check if we need to normalize the name
    if (isset($nameVariations[$teamName])) {
        $teamName = $nameVariations[$teamName];
    }
    
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

// Enhanced logo handling with fallbacks for teams
function getTeamLogoPath($team) {
    // Use team name for consistent logo handling
    if (!empty($team['team_name'])) {
        return getTeamLogo($team['team_name']);
    }
    
    // Final fallback to default SVG
    return 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMTgiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjIwIiB5PSIyNSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIyMCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo=';
}

// Get league info
$stmt = $pdo->prepare("SELECT * FROM leagues WHERE id = ?");
$stmt->execute([$league_id]);
$league = $stmt->fetch();

if (!$league) {
    die("League not found");
}

// Check if draft is completed
$stmt = $pdo->prepare("
    SELECT ds.*, COUNT(dp.id) as total_picks,
           (SELECT COUNT(*) FROM league_participants WHERE league_id = ? AND status = 'active') as participant_count
    FROM draft_sessions ds
    LEFT JOIN draft_picks dp ON ds.id = dp.draft_session_id
    WHERE ds.league_id = ?
    GROUP BY ds.id
    ORDER BY ds.created_at DESC
    LIMIT 1
");
$stmt->execute([$league_id, $league_id]);
$draft_info = $stmt->fetch();

if (!$draft_info || $draft_info['status'] !== 'completed') {
    header('Location: draft.php');
    exit;
}

// Get all draft picks with team and participant info - FIXED QUERY
$stmt = $pdo->prepare("
    SELECT 
        dp.id,
        dp.pick_number,
        dp.round_number,
        dp.picked_at,
        dp.picked_by_commissioner,
        nt.name as team_name,
        nt.abbreviation,
        nt.logo_filename as logo,
        lp.participant_name,
        u.display_name,
        -- Get current team record if available from NBA standings
        COALESCE(nss.win, 0) as wins,
        COALESCE(nss.loss, 0) as losses
    FROM draft_picks dp
    JOIN nba_teams nt ON dp.team_id = nt.id
    JOIN league_participants lp ON dp.league_participant_id = lp.id
    JOIN users u ON lp.user_id = u.id
    LEFT JOIN 2025_2026 nss ON nt.name = nss.name
    WHERE dp.draft_session_id = ?
    ORDER BY dp.pick_number ASC
");
$stmt->execute([$draft_info['id']]);
$all_picks = $stmt->fetchAll();

// AUTOMATIC DRAFT BACKUP - Creates a permanent text file backup
try {
    // Create backup directory if it doesn't exist
    $backup_dir = '/data/www/default/nba-wins-platform/draft-backup';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }
    
    // Generate filename
    $league_name_clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', $league['display_name']);
    $timestamp = date('Y-m-d_H-i-s', strtotime($draft_info['completed_at']));
    $filename = "draft_backup_{$league_name_clean}_L{$league_id}_{$timestamp}.txt";
    $filepath = $backup_dir . '/' . $filename;
    
    // Only create backup if it doesn't already exist
    if (!file_exists($filepath)) {
        // Generate backup content
        $content = "NBA WINS POOL - DRAFT BACKUP\n";
        $content .= "==============================\n\n";
        $content .= "League: " . $league['display_name'] . "\n";
        $content .= "League ID: " . $league_id . "\n";
        $content .= "Draft Completed: " . date('Y-m-d H:i:s', strtotime($draft_info['completed_at'])) . "\n";
        $content .= "Backup Created: " . date('Y-m-d H:i:s') . "\n";
        $content .= "Total Picks: " . count($all_picks) . "\n\n";
        
        $content .= "DRAFT RESULTS (Pick Order)\n";
        $content .= "==========================\n\n";
        
        foreach ($all_picks as $pick) {
            $commissioner_indicator = $pick['picked_by_commissioner'] ? ' (COMMISSIONER)' : '';
            $pick_time = date('H:i:s', strtotime($pick['picked_at']));
            
            $content .= sprintf(
                "Pick #%02d: %-20s selected %-25s (%s) [%d-%d] at %s%s\n",
                $pick['pick_number'],
                $pick['display_name'],
                $pick['team_name'],
                $pick['abbreviation'],
                $pick['wins'],
                $pick['losses'],
                $pick_time,
                $commissioner_indicator
            );
        }
        
        $content .= "\n\nTEAMS BY PARTICIPANT\n";
        $content .= "====================\n\n";
        
        // Group picks by participant
        $participants = [];
        foreach ($all_picks as $pick) {
            if (!isset($participants[$pick['display_name']])) {
                $participants[$pick['display_name']] = [];
            }
            $participants[$pick['display_name']][] = $pick;
        }
        
        foreach ($participants as $name => $participant_picks) {
            $total_wins = array_sum(array_column($participant_picks, 'wins'));
            $total_losses = array_sum(array_column($participant_picks, 'losses'));
            
            $content .= $name . " ({$total_wins}W-{$total_losses}L):\n";
            foreach ($participant_picks as $pick) {
                $content .= "  - " . $pick['team_name'] . " (" . $pick['abbreviation'] . ") [{$pick['wins']}-{$pick['losses']}]\n";
            }
            $content .= "\n";
        }
        
        // Write to file
        file_put_contents($filepath, $content);
        error_log("Draft backup created: $filename");
    }
    
} catch (Exception $e) {
    error_log("Failed to create draft backup: " . $e->getMessage());
}
// END AUTOMATIC DRAFT BACKUP

// FIXED: Calculate position_in_round for each pick with proper reference handling
foreach ($all_picks as &$pick) {
    // Calculate position within round based on pick_number and round_number
    // Assuming each round has the same number of participants
    $participants_count = $draft_info['participant_count'];
    $pick['position_in_round'] = (($pick['pick_number'] - 1) % $participants_count) + 1;
}
unset($pick); // CRITICAL: Clear the reference to prevent array corruption

// Get selected round from URL parameter, default to 1
$selected_round = isset($_GET['round']) ? max(1, min((int)$_GET['round'], $draft_info['total_rounds'])) : 1;
// Default to 'order' mode (by pick), but preserve user's selection
$view_mode = isset($_GET['mode']) ? $_GET['mode'] : 'order';

// FIXED: Filter picks for the selected round with explicit type conversion
$round_picks = array_filter($all_picks, function($pick) use ($selected_round) {
    return (int)$pick['round_number'] == (int)$selected_round;
});

// Sort based on view mode
if ($view_mode === 'rank') {
    // Sort by wins (descending), then losses (ascending)
    usort($round_picks, function($a, $b) {
        if ($a['wins'] === $b['wins']) {
            return $a['losses'] - $b['losses'];
        }
        return $b['wins'] - $a['wins'];
    });
} else {
    // Sort by pick order
    usort($round_picks, function($a, $b) {
        return $a['pick_number'] - $b['pick_number'];
    });
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
    <title>Draft Summary - <?= htmlspecialchars($league['display_name']) ?></title>
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
            --hover-color: #757575;
            --basketball-orange: #FF7F00;
            --success-color: #4CAF50;
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
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
            background-color: var(--background-color);
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        header {
            text-align: center;
            margin-bottom: 20px;
            background-color: rgba(255,255,255,0.8);
            padding: 20px;
            border-radius: 8px;
        }
        
        .basketball-logo {
            max-width: 60px;
            margin-bottom: 10px;
        }
        
        h1 {
            margin: 10px 0;
            font-size: 28px;
            color: var(--primary-color);
        }
        
        .draft-info {
            color: var(--secondary-color);
            margin-bottom: 10px;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            color: var(--text-color);
            text-decoration: none;
            font-weight: 500;
            padding: 8px 16px;
            background: rgba(255,255,255,0.8);
            border-radius: 6px;
            transition: background-color 0.2s;
        }
        
        .back-link:hover {
            background: rgba(255,255,255,1);
            color: var(--basketball-orange);
        }
        
        .header-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 20px 0;
            padding: 0 10px;
            background: rgba(255,255,255,0.8);
            border-radius: 8px;
            padding: 20px;
        }

        .round-selector {
            padding: 10px 16px;
            font-size: 16px;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            background-color: white;
            color: var(--text-color);
            cursor: pointer;
            transition: border-color 0.2s;
        }
        
        .round-selector:hover {
            border-color: var(--basketball-orange);
        }

        .toggle-switch {
            display: flex;
            background-color: #e0e0e0;
            border-radius: 25px;
            padding: 2px;
            cursor: pointer;
            width: 200px;
            position: relative;
            user-select: none;
        }

        .toggle-option {
            flex: 1;
            text-align: center;
            padding: 10px 16px;
            z-index: 1;
            color: #666;
            transition: color 0.3s ease;
            font-weight: 500;
        }

        .toggle-option.active {
            color: white;
        }

        .toggle-slider {
            position: absolute;
            top: 2px;
            left: 2px;
            width: calc(50% - 2px);
            height: calc(100% - 4px);
            background-color: var(--primary-color);
            border-radius: 25px;
            transition: transform 0.3s ease;
        }

        .toggle-slider.order {
            transform: translateX(100%);
        }
        
        .team-card {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255,255,255,0.9);
            padding: 15px 20px;
            margin-bottom: 12px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
            border: 1px solid var(--border-color);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .team-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.12);
        }
        
        .team-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex: 1;
        }
        
        .team-logo {
            width: 50px;
            height: 50px;
            object-fit: contain;
            border-radius: 50%;
        }
        
        .rank-number {
            font-weight: bold;
            min-width: 30px;
            font-size: 18px;
            color: var(--basketball-orange);
        }
        
        .team-details {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .team-name {
            font-weight: 600;
            font-size: 16px;
            color: var(--primary-color);
        }
        
        .drafter-info {
            color: var(--secondary-color);
            font-size: 0.9em;
        }
        
        .commissioner-pick {
            color: var(--basketball-orange);
            font-style: italic;
        }
        
        .team-record {
            font-weight: 600;
            font-size: 16px;
            text-align: right;
            min-width: 80px;
        }
        
        .wins {
            color: var(--success-color);
        }
        
        .losses {
            color: #f44336;
        }

        /* Menu styling */
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

        @media (max-width: 600px) {
            .container {
                padding: 15px;
            }
            
            h1 {
                font-size: 24px;
            }
            
            .header-controls {
                flex-direction: column;
                gap: 15px;
                padding: 15px;
            }

            .toggle-switch {
                width: 180px;
            }

            .toggle-option {
                padding: 8px 12px;
                font-size: 14px;
            }
            
            .team-card {
                padding: 12px 15px;
            }
            
            .team-logo {
                width: 40px;
                height: 40px;
            }
            
            .team-info {
                gap: 12px;
            }
            
            .rank-number {
                font-size: 16px;
            }
            
            .team-name {
                font-size: 15px;
            }
            
            .drafter-info {
                font-size: 0.8em;
            }
            
            .team-record {
                font-size: 14px;
                min-width: 70px;
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
            <h1>Draft Summary</h1>
            <div class="draft-info">
                <strong><?= htmlspecialchars($league['display_name']) ?></strong><br>
                Completed on <?= date('F j, Y \a\t g:i A', strtotime($draft_info['completed_at'])) ?><br>
                <?= $draft_info['total_picks'] ?> picks across <?= $draft_info['total_rounds'] ?> rounds
            </div>
        </header>

        <div class="header-controls">
            <select name="round" class="round-selector" onchange="changeRound(this.value)">
                <?php for ($i = 1; $i <= $draft_info['total_rounds']; $i++): ?>
                    <option value="<?php echo $i; ?>" <?php echo $i === $selected_round ? 'selected' : ''; ?>>
                        Round <?php echo $i; ?>
                    </option>
                <?php endfor; ?>
            </select>

            <div class="toggle-switch" onclick="toggleView()">
                <div class="toggle-slider <?php echo $view_mode === 'order' ? 'order' : ''; ?>"></div>
                <div class="toggle-option <?php echo $view_mode === 'rank' ? 'active' : ''; ?>">By Record</div>
                <div class="toggle-option <?php echo $view_mode === 'order' ? 'active' : ''; ?>">By Pick</div>
            </div>
        </div>

        <?php if (empty($round_picks)): ?>
            <div class="team-card" style="text-align: center; font-style: italic; opacity: 0.7;">
                No picks found for Round <?= $selected_round ?>
            </div>
        <?php else: ?>
            <?php foreach ($round_picks as $index => $pick): ?>
                <div class="team-card">
                    <div class="team-info">
                        <span class="rank-number">
                            <?php echo $view_mode === 'rank' ? ($index + 1) . '.' : $pick['position_in_round'] . '.'; ?>
                        </span>
                        <img src="<?php echo getTeamLogoPath($pick); ?>" 
                             alt="<?php echo htmlspecialchars($pick['team_name']); ?> logo" 
                             class="team-logo"
                             onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNTAiIGhlaWdodD0iNTAiIHZpZXdCb3g9IjAgMCA1MCA1MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjUiIGN5PSIyNSIgcj0iMjIiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjI1IiB5PSIzMiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIyNSIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                        <div class="team-details">
                            <div class="team-name"><?php echo htmlspecialchars($pick['team_name']); ?></div>
                            <div class="drafter-info">
                                Pick #<?php echo $pick['pick_number']; ?> by <?php echo htmlspecialchars($pick['display_name']); ?>
                                <?php if ($pick['picked_by_commissioner']): ?>
                                    <span class="commissioner-pick">(Commissioner Pick)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="team-record">
                        <span class="wins"><?php echo $pick['wins']; ?>W</span> - 
                        <span class="losses"><?php echo $pick['losses']; ?>L</span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>



    <script>
        function toggleView() {
            const currentMode = new URLSearchParams(window.location.search).get('mode') || 'rank';
            const newMode = currentMode === 'rank' ? 'order' : 'rank';
            updateURL(newMode);
        }

        function changeRound(round) {
            // Preserve current mode when changing rounds, default to 'order' if not set
            const currentMode = new URLSearchParams(window.location.search).get('mode') || 'order';
            updateURL(currentMode, round);
        }

        function updateURL(mode, round = null) {
            const params = new URLSearchParams(window.location.search);
            params.set('mode', mode);
            if (round) {
                params.set('round', round);
            }
            window.location.href = `${window.location.pathname}?${params.toString()}`;
        }
    </script>
</body>
</html>