<?php
// /data/www/default/nba-wins-platform/profiles/draft_preferences.php
// Draft Preferences - Simplified Team Ranking Interface

session_start();

// Require authentication
require_once '/data/www/default/nba-wins-platform/config/db_connection.php';

$user_id = $_SESSION['user_id'] ?? null;
$current_league_id = $_SESSION['current_league_id'] ?? '';

if (!$user_id) {
    header('Location: /nba-wins-platform/auth/login.php');
    exit;
}

// Get user info
$stmt = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: /nba-wins-platform/auth/login.php');
    exit;
}

// Get all NBA teams with over/under projections
$stmt = $pdo->prepare("
    SELECT nt.id, nt.name, nt.abbreviation, nt.conference, 
           COALESCE(ou.over_under_number, 41) as projected_wins
    FROM nba_teams nt
    LEFT JOIN over_under ou ON nt.name = ou.team_name
    ORDER BY projected_wins DESC, nt.name ASC
");
$stmt->execute();
$all_teams = $stmt->fetchAll();

// Get user's existing preferences
$stmt = $pdo->prepare("
    SELECT team_id, priority_rank
    FROM user_draft_preferences
    WHERE user_id = ?
    ORDER BY priority_rank ASC
");
$stmt->execute([$user_id]);
$existing_preferences = $stmt->fetchAll();

// Create lookup array
$preferences_by_team = [];
foreach ($existing_preferences as $pref) {
    $preferences_by_team[$pref['team_id']] = $pref['priority_rank'];
}

// Organize teams: ranked teams in order, then unranked teams by projection
$ranked_teams = [];
$unranked_teams = [];

foreach ($all_teams as $team) {
    if (isset($preferences_by_team[$team['id']])) {
        $ranked_teams[$preferences_by_team[$team['id']]] = $team;
    } else {
        $unranked_teams[] = $team;
    }
}

// Sort ranked teams by priority
ksort($ranked_teams);

// If no preferences exist, auto-rank by projections
if (empty($ranked_teams)) {
    $display_teams = $all_teams;
} else {
    // Combine for display: ranked first, then unranked
    $display_teams = array_merge(array_values($ranked_teams), $unranked_teams);
}

// Logo path helper - matches participant_profile.php
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
        return '../public/assets/team_logos/' . $logoMap[$teamName];
    }
    
    $filename = strtolower(str_replace(' ', '_', $teamName)) . '.png';
    return '../public/assets/team_logos/' . $filename;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="theme-color" content="#f5f5f5">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Draft Preferences - NBA Wins Platform</title>
    <link rel="apple-touch-icon" type="image/png" href="/nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="/nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary-color: #212121;
            --secondary-color: #616161;
            --background-color: rgba(245, 245, 245, 0.8);
            --text-color: #333333;
            --border-color: #e0e0e0;
            --hover-color: #757575;
            --success-color: #28a745;
            --warning-color: #ff9800;
            --error-color: #f44336;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 10px;
            background-image: url('../public/assets/background/geometric_white.png');
            background-repeat: repeat;
            background-attachment: fixed;
            color: var(--text-color);
            min-height: 100vh;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: var(--background-color);
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .header h1 {
            color: var(--primary-color);
            margin: 0 0 10px 0;
            font-size: 2em;
        }

        .header p {
            color: var(--secondary-color);
            margin: 5px 0;
        }

        .instructions {
            background: rgba(255, 243, 205, 0.8);
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .instructions h3 {
            color: #e65100;
            margin: 0 0 15px 0;
        }

        .instructions ul {
            margin: 10px 0;
            padding-left: 25px;
        }

        .instructions li {
            margin: 8px 0;
        }

        .controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 8px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 14px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary { 
            background-color: var(--success-color); 
            color: white; 
        }
        
        .btn-secondary { 
            background-color: var(--secondary-color); 
            color: white; 
        }
        
        .btn-warning { 
            background-color: var(--warning-color); 
            color: white; 
        }

        .btn:hover { 
            transform: translateY(-1px); 
            box-shadow: 0 2px 8px rgba(0,0,0,0.2); 
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .team-list {
            list-style: none;
            padding: 0;
            margin: 0;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 8px;
            overflow: hidden;
        }

        .team-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.2s;
            gap: 15px;
        }

        .team-item:last-child {
            border-bottom: none;
        }

        .team-item:hover {
            background-color: rgba(245, 245, 245, 0.5);
        }

        .rank-number {
            background: var(--primary-color);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            flex-shrink: 0;
        }

        .team-logo {
            width: 40px;
            height: 40px;
            object-fit: contain;
            flex-shrink: 0;
        }

        .team-info {
            flex: 1;
            min-width: 0;
        }

        .team-name {
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 2px;
        }

        .team-details {
            font-size: 0.85em;
            color: var(--secondary-color);
        }

        .projected-wins {
            font-size: 0.9em;
            color: var(--secondary-color);
            padding: 4px 10px;
            background: rgba(0, 0, 0, 0.05);
            border-radius: 12px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .move-buttons {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .move-btn {
            width: 32px;
            height: 32px;
            border: 1px solid var(--border-color);
            background: white;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--secondary-color);
        }

        .move-btn:hover:not(:disabled) {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .move-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }

        .progress-section {
            text-align: center;
            padding: 20px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 8px;
            margin-top: 20px;
        }

        .progress-text {
            font-size: 1.1em;
            font-weight: 500;
            margin-bottom: 15px;
            color: var(--secondary-color);
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--border-color);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success-color), #20c997);
            transition: width 0.3s ease;
            border-radius: 4px;
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 1000;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .notification.show { transform: translateX(0); }
        .notification.success { background: var(--success-color); }
        .notification.error { background: var(--error-color); }
        .notification.warning { background: var(--warning-color); }

        .back-button {
            position: absolute;
            top: 20px;
            left: 20px;
            background: var(--secondary-color);
            color: white;
            text-decoration: none;
            padding: 10px 15px;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            z-index: 100;
        }

        .back-button:hover {
            background: var(--primary-color);
            transform: translateY(-1px);
            color: white;
        }

        .conference-eastern { border-left: 4px solid #dc3545; }
        .conference-western { border-left: 4px solid #007bff; }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }

            .team-item {
                padding: 10px;
                gap: 10px;
            }

            .rank-number {
                width: 35px;
                height: 35px;
                font-size: 0.9em;
            }

            .team-logo {
                width: 35px;
                height: 35px;
            }

            .team-name {
                font-size: 0.95em;
            }

            .team-details {
                font-size: 0.8em;
            }

            .projected-wins {
                font-size: 0.85em;
                padding: 3px 8px;
            }

            .move-btn {
                width: 28px;
                height: 28px;
                font-size: 0.85em;
            }

            .controls {
                flex-direction: column;
                align-items: stretch;
            }

            .controls > div {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
            }

            .btn {
                flex: 1;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?= $current_league_id ?>&user_id=<?= $user_id ?>" class="back-button">
        <i class="fas fa-arrow-left"></i>
        Back to Profile
    </a>

    <div class="container">
        <div class="header">
            <h1><i class="fas fa-list-ol"></i> Draft Preferences</h1>
            <p>Set your team rankings for auto-draft</p>
            <p>Welcome, <?= htmlspecialchars($user['display_name']) ?></p>
        </div>

        <div class="instructions">
            <h3><i class="fas fa-info-circle"></i> How It Works</h3>
            <ul>
                <li><strong>Initial Rankings:</strong> Teams are automatically ranked by projected wins (over/under)</li>
                <li><strong>Reorder Teams:</strong> Use ↑ ↓ arrows to move teams up or down</li>
                <li><strong>Auto-Draft:</strong> During live drafts, your highest available team will be selected</li>
                <li><strong>Save Often:</strong> Click "Save Preferences" to keep your rankings</li>
            </ul>
        </div>

        <div class="controls">
            <div>
                <button class="btn btn-warning" onclick="resetToProjections()">
                    <i class="fas fa-undo"></i> Reset to Projections
                </button>
                <button class="btn btn-secondary" onclick="reverseOrder()">
                    <i class="fas fa-exchange-alt"></i> Reverse Order
                </button>
            </div>
            <button class="btn btn-primary" onclick="savePreferences()">
                <i class="fas fa-save"></i> Save Preferences
            </button>
        </div>

        <ul class="team-list" id="teamList">
            <?php foreach ($display_teams as $index => $team): ?>
            <li class="team-item conference-<?= strtolower($team['conference']) ?>" data-team-id="<?= $team['id'] ?>">
                <div class="rank-number"><?= $index + 1 ?></div>
                <img src="<?= htmlspecialchars(getTeamLogo($team['name'])) ?>" 
                     alt="<?= htmlspecialchars($team['name']) ?>" 
                     class="team-logo"
                     onerror="this.src='../public/assets/team_logos/default.png'">
                <div class="team-info">
                    <div class="team-name"><?= htmlspecialchars($team['name']) ?></div>
                    <div class="team-details"><?= htmlspecialchars($team['abbreviation']) ?> • <?= htmlspecialchars($team['conference']) ?></div>
                </div>
                <div class="projected-wins">O/U: <?= number_format($team['projected_wins'], 1) ?></div>
                <div class="move-buttons">
                    <button class="move-btn move-up">
                        <i class="fas fa-chevron-up"></i>
                    </button>
                    <button class="move-btn move-down">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>

        <div class="progress-section">
            <div class="progress-text">All 30 Teams Ranked</div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: 100%;"></div>
            </div>
            <button class="btn btn-primary" onclick="savePreferences()" style="font-size: 1.1em; padding: 12px 30px;">
                <i class="fas fa-save"></i> Save All Preferences
            </button>
        </div>
    </div>

    <script>
        let hasUnsavedChanges = false;

        // Initialize event listeners for move buttons
        document.addEventListener('DOMContentLoaded', function() {
            const teamList = document.getElementById('teamList');
            
            // Use event delegation for move buttons
            teamList.addEventListener('click', function(e) {
                const btn = e.target.closest('.move-btn');
                if (!btn || btn.disabled) return;
                
                const teamItem = btn.closest('.team-item');
                const items = Array.from(teamList.children);
                const currentIndex = items.indexOf(teamItem);
                
                if (btn.classList.contains('move-up') && currentIndex > 0) {
                    // Move up
                    teamList.insertBefore(teamItem, items[currentIndex - 1]);
                    hasUnsavedChanges = true;
                    updateRankNumbers();
                } else if (btn.classList.contains('move-down') && currentIndex < items.length - 1) {
                    // Move down
                    teamList.insertBefore(items[currentIndex + 1], teamItem);
                    hasUnsavedChanges = true;
                    updateRankNumbers();
                }
            });
            
            // Initial setup
            updateRankNumbers();
        });

        // Update rank numbers and button states
        function updateRankNumbers() {
            const teamList = document.getElementById('teamList');
            const items = Array.from(teamList.children);
            
            items.forEach((item, index) => {
                // Update rank number
                const rankNumber = item.querySelector('.rank-number');
                if (rankNumber) {
                    rankNumber.textContent = index + 1;
                }
                
                // Update button states
                const upBtn = item.querySelector('.move-up');
                const downBtn = item.querySelector('.move-down');
                
                if (upBtn) upBtn.disabled = (index === 0);
                if (downBtn) downBtn.disabled = (index === items.length - 1);
            });
        }

        // Reset to original projections
        function resetToProjections() {
            if (!confirm('Reset rankings to projected wins order? This will undo all your changes.')) {
                return;
            }
            
            const teamList = document.getElementById('teamList');
            const items = Array.from(teamList.children);
            
            // Get projected wins for each team
            const teamsWithProjections = items.map(item => {
                const projectedText = item.querySelector('.projected-wins').textContent;
                const projection = parseFloat(projectedText.replace('O/U: ', ''));
                return { item, projection };
            });
            
            // Sort by projection (highest first)
            teamsWithProjections.sort((a, b) => b.projection - a.projection);
            
            // Clear and re-add in sorted order
            teamList.innerHTML = '';
            teamsWithProjections.forEach(({ item }) => {
                teamList.appendChild(item);
            });
            
            hasUnsavedChanges = true;
            updateRankNumbers();
            showNotification('Rankings reset to projections!', 'success');
        }

        // Reverse current order
        function reverseOrder() {
            const teamList = document.getElementById('teamList');
            const items = Array.from(teamList.children);
            
            items.reverse().forEach(item => {
                teamList.appendChild(item);
            });
            
            hasUnsavedChanges = true;
            updateRankNumbers();
            showNotification('Order reversed!', 'success');
        }

        // Save preferences to database
        function savePreferences() {
            const saveButtons = document.querySelectorAll('.btn-primary');
            
            // Disable buttons during save
            saveButtons.forEach(btn => {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            });
            
            // Get current team order
            const teamList = document.getElementById('teamList');
            const items = Array.from(teamList.children);
            const preferences = [];
            
            items.forEach((item, index) => {
                preferences.push({
                    team_id: parseInt(item.dataset.teamId),
                    priority_rank: index + 1
                });
            });
            
            // Send to API
            fetch('/nba-wins-platform/api/draft_preferences_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'save_preferences',
                    preferences: preferences
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    hasUnsavedChanges = false;
                    showNotification('Draft preferences saved successfully!', 'success');
                } else {
                    throw new Error(data.error || 'Unknown error occurred');
                }
            })
            .catch(error => {
                console.error('Error saving preferences:', error);
                showNotification('Error saving preferences: ' + error.message, 'error');
            })
            .finally(() => {
                // Re-enable buttons
                saveButtons.forEach(btn => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-save"></i> Save Preferences';
                });
            });
        }

        // Show notification
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => notification.classList.add('show'), 100);
            
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (notification.parentNode) {
                        document.body.removeChild(notification);
                    }
                }, 300);
            }, 4000);
        }

        // Warn user about unsaved changes
        window.addEventListener('beforeunload', function(e) {
            if (hasUnsavedChanges) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes to your draft preferences. Are you sure you want to leave?';
            }
        });

        console.log('Draft Preferences page initialized successfully!');
    </script>
</body>
</html>