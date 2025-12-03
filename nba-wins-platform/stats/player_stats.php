<?php
// Start session for multi-league support
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone to EST
date_default_timezone_set('America/New_York');

// Get player name from URL
$player_name = $_GET['name'] ?? null;

if (!$player_name) {
    die("No player name provided");
}

// Sanitize player name for display
$display_name = htmlspecialchars($player_name);

// Fetch player stats from Python script
$command = "python3 /data/www/default/nba-wins-platform/tasks/get_player_info.py " . escapeshellarg($player_name) . " 2>&1";
$output = shell_exec($command);

if (!$output) {
    die("Error fetching player data");
}

$player_data = json_decode($output, true);

if (!$player_data || !$player_data['success']) {
    $error_message = $player_data['error'] ?? 'Unknown error occurred';
    die("Error: " . htmlspecialchars($error_message));
}

$player = $player_data['player'];
$current_season = $player_data['current_season'];
$career = $player_data['career_totals'];
$seasons = $player_data['season_by_season'];

// Calculate career averages
$career_gp = $career['games_played'] ?: 1;
$career_averages = [
    'ppg' => round($career['points'] / $career_gp, 1),
    'rpg' => round($career['rebounds'] / $career_gp, 1),
    'apg' => round($career['assists'] / $career_gp, 1),
    'mpg' => round($career['minutes'] / $career_gp, 1),
    'fg_pct' => round($career['fg_pct'] * 100, 1),
    'fg3_pct' => round($career['fg3_pct'] * 100, 1),
    'ft_pct' => round($career['ft_pct'] * 100, 1),
];

// Calculate current season averages
$current_gp = $current_season['games_played'] ?: 1;
$current_averages = [
    'ppg' => round($current_season['points'] / $current_gp, 1),
    'rpg' => round($current_season['rebounds'] / $current_gp, 1),
    'apg' => round($current_season['assists'] / $current_gp, 1),
    'mpg' => round($current_season['minutes'] / $current_gp, 1),
    'fg_pct' => round($current_season['fg_pct'] * 100, 1),
    'fg3_pct' => round($current_season['fg3_pct'] * 100, 1),
    'ft_pct' => round($current_season['ft_pct'] * 100, 1),
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $player['full_name']; ?> - Player Stats</title>
    <link rel="apple-touch-icon" type="image/svg+xml" href="/media/favicon.png">
    <link rel="icon" type="image/svg+xml" href="/media/favicon.png">
    <link href="https://cdn.tailwindcss.com" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        line-height: 1.6;
        margin: 0;
        padding: 20px;
        background-image: url('/media/geometric_white.png');
        background-repeat: repeat;
        background-attachment: fixed;
    }
    
    .container {
        max-width: 1200px;
        margin: 0 auto;
        background-color: white;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .player-header {
        display: flex;
        align-items: center;
        gap: 2rem;
        margin: 2rem 0;
        padding: 2rem;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 8px;
        color: white;
    }

    .player-info {
        flex: 1;
    }

    .player-name {
        font-size: 2.5rem;
        font-weight: bold;
        margin-bottom: 0.5rem;
    }

    .player-details {
        font-size: 1.1rem;
        opacity: 0.9;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 1.5rem;
        margin: 2rem 0;
    }

    .stat-card {
        background-color: #f8f9fa;
        padding: 1.5rem;
        border-radius: 8px;
        border-left: 4px solid #667eea;
    }

    .stat-card h3 {
        font-size: 1.2rem;
        margin-bottom: 1rem;
        color: #333;
    }

    .stat-row {
        display: flex;
        justify-content: space-between;
        padding: 0.5rem 0;
        border-bottom: 1px solid #dee2e6;
    }

    .stat-row:last-child {
        border-bottom: none;
    }

    .stat-label {
        font-weight: 600;
        color: #666;
    }

    .stat-value {
        font-weight: bold;
        color: #333;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin: 1rem 0;
    }

    th, td {
        padding: 12px;
        border: 1px solid #ddd;
        text-align: left;
    }

    th {
        background-color: #667eea;
        color: white;
        font-weight: 600;
    }

    tr:nth-child(even) {
        background-color: #f8f9fa;
    }

    tr:hover {
        background-color: #e9ecef;
    }

    .section {
        margin: 2rem 0;
    }

    .back-button {
        display: inline-block;
        padding: 10px 20px;
        background-color: #667eea;
        color: white;
        text-decoration: none;
        border-radius: 4px;
        margin-bottom: 1rem;
        transition: background-color 0.3s;
    }

    .back-button:hover {
        background-color: #764ba2;
    }

    @media (max-width: 768px) {
        .container {
            padding: 10px;
        }

        .player-header {
            flex-direction: column;
            padding: 1rem;
        }

        .player-name {
            font-size: 1.8rem;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }

        table {
            font-size: 0.875rem;
        }

        th, td {
            padding: 8px 4px;
        }

        /* Hide some columns on mobile */
        .hide-mobile {
            display: none;
        }
    }
</style>
</head>
<body>
    <div class="container">
        <a href="javascript:history.back()" class="back-button">
            <i class="fa-solid fa-arrow-left"></i> Back
        </a>

        <!-- Player Header -->
        <div class="player-header">
            <div class="player-info">
                <div class="player-name"><?php echo htmlspecialchars($player['full_name']); ?></div>
                <div class="player-details">
                    <?php if ($player['team_name']): ?>
                        <strong><?php echo htmlspecialchars($player['team_name']); ?></strong>
                        <?php if ($player['jersey']): ?>
                            • #<?php echo htmlspecialchars($player['jersey']); ?>
                        <?php endif; ?>
                    <?php endif; ?>
                    <br>
                    <?php if ($player['position']): ?>
                        <?php echo htmlspecialchars($player['position']); ?>
                    <?php endif; ?>
                    <?php if ($player['height'] && $player['weight']): ?>
                        • <?php echo htmlspecialchars($player['height']); ?> • <?php echo htmlspecialchars($player['weight']); ?> lbs
                    <?php endif; ?>
                    <?php if ($player['birthdate']): ?>
                        <br>Born: <?php echo date('F j, Y', strtotime($player['birthdate'])); ?>
                    <?php endif; ?>
                    <?php if ($player['draft_year'] && $player['draft_year'] != 'Undrafted'): ?>
                        <br>Drafted: <?php echo htmlspecialchars($player['draft_year']); ?>
                        <?php if ($player['draft_round']): ?>
                            Round <?php echo htmlspecialchars($player['draft_round']); ?>, Pick <?php echo htmlspecialchars($player['draft_number']); ?>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($player['school']): ?>
                        <br>College: <?php echo htmlspecialchars($player['school']); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Current Season & Career Averages -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3><i class="fa-solid fa-calendar"></i> Current Season (<?php echo htmlspecialchars($current_season['season']); ?>)</h3>
                <div class="stat-row">
                    <span class="stat-label">Games Played</span>
                    <span class="stat-value"><?php echo $current_season['games_played']; ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Points Per Game</span>
                    <span class="stat-value"><?php echo $current_averages['ppg']; ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Rebounds Per Game</span>
                    <span class="stat-value"><?php echo $current_averages['rpg']; ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Assists Per Game</span>
                    <span class="stat-value"><?php echo $current_averages['apg']; ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">FG%</span>
                    <span class="stat-value"><?php echo $current_averages['fg_pct']; ?>%</span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">3P%</span>
                    <span class="stat-value"><?php echo $current_averages['fg3_pct']; ?>%</span>
                </div>
            </div>

            <div class="stat-card">
                <h3><i class="fa-solid fa-trophy"></i> Career Averages</h3>
                <div class="stat-row">
                    <span class="stat-label">Games Played</span>
                    <span class="stat-value"><?php echo number_format($career['games_played']); ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Points Per Game</span>
                    <span class="stat-value"><?php echo $career_averages['ppg']; ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Rebounds Per Game</span>
                    <span class="stat-value"><?php echo $career_averages['rpg']; ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Assists Per Game</span>
                    <span class="stat-value"><?php echo $career_averages['apg']; ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">FG%</span>
                    <span class="stat-value"><?php echo $career_averages['fg_pct']; ?>%</span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">3P%</span>
                    <span class="stat-value"><?php echo $career_averages['fg3_pct']; ?>%</span>
                </div>
            </div>
        </div>

        <!-- Career Totals -->
        <section class="section">
            <h3 class="text-2xl font-bold mb-4"><i class="fa-solid fa-chart-line"></i> Career Totals</h3>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-row">
                        <span class="stat-label">Total Points</span>
                        <span class="stat-value"><?php echo number_format($career['points']); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Total Rebounds</span>
                        <span class="stat-value"><?php echo number_format($career['rebounds']); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Total Assists</span>
                        <span class="stat-value"><?php echo number_format($career['assists']); ?></span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-row">
                        <span class="stat-label">Total Steals</span>
                        <span class="stat-value"><?php echo number_format($career['steals']); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Total Blocks</span>
                        <span class="stat-value"><?php echo number_format($career['blocks']); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Total Minutes</span>
                        <span class="stat-value"><?php echo number_format($career['minutes']); ?></span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Season by Season Stats -->
        <section class="section">
            <h3 class="text-2xl font-bold mb-4"><i class="fa-solid fa-table"></i> Season by Season</h3>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Season</th>
                            <th>Team</th>
                            <th class="hide-mobile">Age</th>
                            <th>GP</th>
                            <th class="hide-mobile">GS</th>
                            <th>MPG</th>
                            <th>PPG</th>
                            <th>RPG</th>
                            <th>APG</th>
                            <th class="hide-mobile">FG%</th>
                            <th class="hide-mobile">3P%</th>
                            <th class="hide-mobile">FT%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($seasons as $season): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($season['season']); ?></td>
                            <td><?php echo htmlspecialchars($season['team']); ?></td>
                            <td class="hide-mobile"><?php echo $season['age']; ?></td>
                            <td><?php echo $season['games_played']; ?></td>
                            <td class="hide-mobile"><?php echo $season['games_started']; ?></td>
                            <td><?php echo $season['minutes_per_game']; ?></td>
                            <td><strong><?php echo $season['points_per_game']; ?></strong></td>
                            <td><?php echo $season['rebounds_per_game']; ?></td>
                            <td><?php echo $season['assists_per_game']; ?></td>
                            <td class="hide-mobile"><?php echo $season['fg_pct']; ?>%</td>
                            <td class="hide-mobile"><?php echo $season['fg3_pct']; ?>%</td>
                            <td class="hide-mobile"><?php echo $season['ft_pct']; ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div style="text-align: center; margin-top: 2rem; padding: 1rem; color: #666; font-size: 0.9rem;">
            <p>Statistics provided by NBA.com • Updated in real-time</p>
        </div>
    </div>
</body>
</html>