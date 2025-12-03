<?php
// Complete participant profile - updated to use user_id instead of participant_name
// Now includes profile photo upload functionality and auto-draft settings

// Set timezone to EST
date_default_timezone_set('America/New_York');

// Start session and get current league/user context
session_start();
$current_league_id = isset($_SESSION['current_league_id']) ? $_SESSION['current_league_id'] : '';
$current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : '';

// Get parameters from URL
$league_id = isset($_GET['league_id']) ? intval($_GET['league_id']) : null;
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;

if (!$league_id || !$user_id) {
    die("Missing required parameters: league_id and user_id");
}

// Database connection
require_once '../config/db_connection.php';
require_once '../core/ProfilePhotoHandler.php';

// Initialize photo handler
$photoHandler = new ProfilePhotoHandler($pdo);

// Handle form submissions
$success_message = '';
$error_message = '';

if ($_POST) {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_display_name':
                if (isset($_SESSION['user_id'])) {
                    $new_display_name = trim($_POST['display_name']);
                    if (!empty($new_display_name) && strlen($new_display_name) <= 20) {
                        try {
                            $stmt = $pdo->prepare("UPDATE users SET display_name = ? WHERE id = ?");
                            $stmt->execute([$new_display_name, $_SESSION['user_id']]);
                            $success_message = "Display name updated successfully!";
                        } catch (Exception $e) {
                            $error_message = "Error updating display name: " . $e->getMessage();
                        }
                    } else {
                        $error_message = "Display name must be between 1 and 20 characters.";
                    }
                }
                break;
                
            case 'upload_photo':
                if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id) {
                    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                        $result = $photoHandler->uploadPhoto($_SESSION['user_id'], $_FILES['profile_photo']);
                        if ($result['success']) {
                            $success_message = $result['message'];
                        } else {
                            $error_message = $result['error'];
                        }
                    } else {
                        $error_message = "Please select a valid image file.";
                    }
                }
                break;
                
            case 'delete_photo':
                if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id) {
                    $result = $photoHandler->deletePhoto($_SESSION['user_id']);
                    if ($result['success']) {
                        $success_message = $result['message'];
                    } else {
                        $error_message = $result['error'];
                    }
                }
                break;
                
            case 'toggle_auto_draft':
                if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id) {
                    $auto_draft_value = isset($_POST['auto_draft_enabled']) ? 1 : 0;
                    try {
                        $stmt = $pdo->prepare("
                            UPDATE league_participants 
                            SET auto_draft_enabled = ? 
                            WHERE user_id = ? AND league_id = ?
                        ");
                        $stmt->execute([$auto_draft_value, $_SESSION['user_id'], $league_id]);
                        $success_message = "Auto-draft setting updated successfully!";
                        
                        // Refresh participant data
                        $stmt = $pdo->prepare("
                            SELECT lp.*, u.display_name, u.id as user_id, u.profile_photo
                            FROM league_participants lp
                            JOIN users u ON lp.user_id = u.id
                            WHERE lp.user_id = ? AND lp.league_id = ?
                        ");
                        $stmt->execute([$user_id, $league_id]);
                        $participant = $stmt->fetch(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {
                        $error_message = "Error updating auto-draft setting: " . $e->getMessage();
                    }
                }
                break;
        }
    }
}

// Get league info
$stmt = $pdo->prepare("SELECT * FROM leagues WHERE id = ?");
$stmt->execute([$league_id]);
$league = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$league) {
    die("League not found");
}

// Check if draft is completed
$draft_completed = $league['draft_completed'] == 1;

// UPDATED: Fetch participant details using user_id and league_id
$stmt = $pdo->prepare("
    SELECT lp.*, u.display_name, u.id as user_id, u.profile_photo
    FROM league_participants lp
    JOIN users u ON lp.user_id = u.id
    WHERE lp.user_id = ? AND lp.league_id = ?
");
$stmt->execute([$user_id, $league_id]);
$participant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$participant) {
    die("Participant not found in this league.");
}

// Check if this is the current user's profile
$is_own_profile = isset($_SESSION['user_id']) && ($participant['user_id'] == $_SESSION['user_id']);

// Check which widgets are already pinned (only for own profile)
$pinned_widgets = [];
if ($is_own_profile) {
    $stmt = $pdo->prepare("
        SELECT widget_type FROM user_dashboard_widgets 
        WHERE user_id = ? AND is_active = 1
    ");
    $stmt->execute([$current_user_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pinned_widgets[] = $row['widget_type'];
    }
}

// Get profile photo URL
$profile_photo_url = $photoHandler->getPhotoUrl($participant['user_id'], $participant['profile_photo']);

// Fetch participant's teams from draft picks
$stmt = $pdo->prepare("
    SELECT dp.*, nt.name as team_name, nt.abbreviation, nt.logo_filename as logo, 
           COALESCE(s.win, 0) as wins, COALESCE(s.loss, 0) as losses,
           (COALESCE(s.win, 0) + COALESCE(s.loss, 0)) as games_played,
           CASE 
               WHEN (COALESCE(s.win, 0) + COALESCE(s.loss, 0)) > 0 
               THEN ROUND((COALESCE(s.win, 0) / (COALESCE(s.win, 0) + COALESCE(s.loss, 0))) * 100, 1)
               ELSE 0 
           END as win_percentage,
           s.logo as standings_logo
    FROM draft_picks dp
    JOIN league_participants lp ON dp.league_participant_id = lp.id
    JOIN nba_teams nt ON dp.team_id = nt.id
    LEFT JOIN 2025_2026 s ON nt.name = s.name
    WHERE dp.league_participant_id = ? AND lp.league_id = ?
    ORDER BY dp.pick_number ASC
");
$stmt->execute([$participant['id'], $league_id]);
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate total wins and losses
$total_wins = 0;
$total_losses = 0;
foreach ($teams as $team) {
    $total_wins += $team['wins'];
    $total_losses += $team['losses'];
}

// Get all participants in this league for navigation
$stmt = $pdo->prepare("
    SELECT lp.id, u.display_name, lp.participant_name, u.id as user_id
    FROM league_participants lp
    JOIN users u ON lp.user_id = u.id
    WHERE lp.league_id = ?
    ORDER BY u.display_name
");
$stmt->execute([$league_id]);
$all_participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =====================================================================
// RIVALS SECTION - Get biggest rival and nemesis
// =====================================================================

// Get BIGGEST RIVAL (most wins against) - only count games from Oct 21st onwards
$stmt = $pdo->prepare("
    SELECT 
        opponent_user.id as opponent_user_id,
        opponent_user.display_name as opponent_name,
        SUM(CASE 
            WHEN (
                (g.home_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers') 
                 OR g.home_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                AND g.home_points > g.away_points
            ) THEN 1
            WHEN (
                (g.away_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers')
                 OR g.away_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                AND g.away_points > g.home_points
            ) THEN 1
            ELSE 0 
        END) as wins_against_opponent,
        SUM(CASE 
            WHEN (
                (g.home_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers')
                 OR g.home_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                AND g.home_points < g.away_points
            ) THEN 1
            WHEN (
                (g.away_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers')
                 OR g.away_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                AND g.away_points < g.home_points
            ) THEN 1
            ELSE 0 
        END) as losses_against_opponent
    FROM league_participant_teams my_team
    JOIN league_participants my_participant ON my_team.league_participant_id = my_participant.id
    JOIN games g ON (
        g.home_team IN (
            REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
            REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
        ) OR 
        g.away_team IN (
            REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
            REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
        )
    ) 
    AND g.status_long IN ('Final', 'Finished')
    AND DATE(g.start_time) >= '2025-10-21'
    JOIN league_participant_teams opponent_team ON (
        (g.home_team IN (
            REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
            REPLACE(REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
        ) AND g.away_team IN (
            REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
            REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
        )) OR
        (g.away_team IN (
            REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
            REPLACE(REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
        ) AND g.home_team IN (
            REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
            REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
        ))
    )
    JOIN league_participants opponent_participant ON opponent_team.league_participant_id = opponent_participant.id
        AND opponent_participant.league_id = my_participant.league_id
        AND opponent_participant.id != my_participant.id
    JOIN users opponent_user ON opponent_participant.user_id = opponent_user.id
    WHERE my_participant.id = ?
    GROUP BY opponent_user.id, opponent_user.display_name
    HAVING wins_against_opponent > 0
    ORDER BY wins_against_opponent DESC, losses_against_opponent ASC, opponent_user.display_name
    LIMIT 1
");
$stmt->execute([$participant['id']]);
$biggest_rival = $stmt->fetch(PDO::FETCH_ASSOC);

// Get NEMESIS (most losses against) - only count games from Oct 21st onwards
$stmt = $pdo->prepare("
    SELECT 
        opponent_user.id as opponent_user_id,
        opponent_user.display_name as opponent_name,
        SUM(CASE 
            WHEN (
                (g.home_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers')
                 OR g.home_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                AND g.home_points < g.away_points
            ) THEN 1
            WHEN (
                (g.away_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers')
                 OR g.away_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                AND g.away_points < g.home_points
            ) THEN 1
            ELSE 0 
        END) as losses_against_opponent,
        SUM(CASE 
            WHEN (
                (g.home_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers')
                 OR g.home_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                AND g.home_points > g.away_points
            ) THEN 1
            WHEN (
                (g.away_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers')
                 OR g.away_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                AND g.away_points > g.home_points
            ) THEN 1
            ELSE 0 
        END) as wins_against_opponent
    FROM league_participant_teams my_team
    JOIN league_participants my_participant ON my_team.league_participant_id = my_participant.id
    JOIN games g ON (
        g.home_team IN (
            REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
            REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
        ) OR 
        g.away_team IN (
            REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
            REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
        )
    ) 
    AND g.status_long IN ('Final', 'Finished')
    AND DATE(g.start_time) >= '2025-10-21'
    JOIN league_participant_teams opponent_team ON (
        (g.home_team IN (
            REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
            REPLACE(REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
        ) AND g.away_team IN (
            REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
            REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
        )) OR
        (g.away_team IN (
            REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
            REPLACE(REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
        ) AND g.home_team IN (
            REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
            REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
        ))
    )
    JOIN league_participants opponent_participant ON opponent_team.league_participant_id = opponent_participant.id
        AND opponent_participant.league_id = my_participant.league_id
        AND opponent_participant.id != my_participant.id
    JOIN users opponent_user ON opponent_participant.user_id = opponent_user.id
    WHERE my_participant.id = ?
    GROUP BY opponent_user.id, opponent_user.display_name
    HAVING losses_against_opponent > 0
    ORDER BY losses_against_opponent DESC, wins_against_opponent ASC, opponent_user.display_name
    LIMIT 1
");
$stmt->execute([$participant['id']]);
$nemesis = $stmt->fetch(PDO::FETCH_ASSOC);

// Get last 10 games for all participant's teams
$lastGames = [];
try {
    // Get all team names for this participant
    $teamNamesQuery = $pdo->prepare("
        SELECT nt.name 
        FROM draft_picks dp
        JOIN nba_teams nt ON dp.team_id = nt.id
        WHERE dp.league_participant_id = ?
    ");
    $teamNamesQuery->execute([$participant['id']]);
    $participantTeams = $teamNamesQuery->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($participantTeams)) {
        $placeholders = str_repeat('?,', count($participantTeams) - 1) . '?';
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                g.date as game_date,
                g.start_time,
                g.home_team,
                g.away_team,
                g.home_team_code,
                g.away_team_code,
                g.home_points,
                g.away_points,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN 'home'
                    WHEN g.away_team IN ($placeholders) THEN 'away'
                END as team_location,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN g.home_team
                    WHEN g.away_team IN ($placeholders) THEN g.away_team
                END as my_team,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN g.away_team
                    WHEN g.away_team IN ($placeholders) THEN g.home_team
                END as opponent,
                CASE 
                    WHEN (g.home_team IN ($placeholders) AND g.home_points > g.away_points) OR 
                         (g.away_team IN ($placeholders) AND g.away_points > g.home_points) THEN 'W'
                    WHEN g.home_points IS NOT NULL THEN 'L'
                    ELSE NULL
                END as result
            FROM games g
            WHERE (g.home_team IN ($placeholders) OR g.away_team IN ($placeholders))
            AND g.status_long IN ('Final', 'Finished')
            AND g.date >= '2025-10-21'
            ORDER BY g.date DESC, g.start_time DESC
            LIMIT 10
        ");
        
        // Execute with team names repeated for each placeholder
        $params = array_merge(
            $participantTeams, $participantTeams, $participantTeams, $participantTeams,
            $participantTeams, $participantTeams, $participantTeams, $participantTeams,
            $participantTeams, $participantTeams
        );
        $stmt->execute($params);
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
    }
} catch (Exception $e) {
    error_log("Error fetching last games: " . $e->getMessage());
}

// Get upcoming 5 games for all participant's teams
$upcomingGames = [];
try {
    if (!empty($participantTeams)) {
        $placeholders = str_repeat('?,', count($participantTeams) - 1) . '?';
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                g.date as game_date,
                g.home_team,
                g.away_team,
                g.home_team_code,
                g.away_team_code,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN 'home'
                    WHEN g.away_team IN ($placeholders) THEN 'away'
                END as team_location,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN g.home_team
                    WHEN g.away_team IN ($placeholders) THEN g.away_team
                END as my_team,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN g.away_team
                    WHEN g.away_team IN ($placeholders) THEN g.home_team
                END as opponent
            FROM games g
            WHERE (g.home_team IN ($placeholders) OR g.away_team IN ($placeholders))
            AND g.status_long = 'Scheduled'
            AND g.date >= '2025-10-21'
            ORDER BY g.date ASC
            LIMIT 5
        ");
        
        // Execute with team names repeated for each placeholder
        $params = array_merge(
            $participantTeams, $participantTeams, $participantTeams, $participantTeams,
            $participantTeams, $participantTeams, $participantTeams, $participantTeams
        );
        $stmt->execute($params);
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
    }
} catch (Exception $e) {
    error_log("Error fetching upcoming games: " . $e->getMessage());
}

// Simple team logo mapping - maps team names to actual logo filenames
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
    
    // Return mapped logo or fallback
    if (isset($logoMap[$teamName])) {
        return '../public/assets/team_logos/' . $logoMap[$teamName];
    }
    
    // Fallback: try lowercase with underscores
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
    <title><?php echo htmlspecialchars($participant['display_name']); ?>'s Profile - <?php echo htmlspecialchars($league['display_name']); ?></title>
    <link rel="apple-touch-icon" type="image/svg+xml" href="../public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="../public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <!-- React and Babel for Navigation Component -->
    <script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
    <style>
        :root {
            --primary-color: #212121;
            --secondary-color: #616161;
            --background-color: rgba(245, 245, 245, 0.8);
            --text-color: #333333;
            --border-color: #e0e0e0;
            --hover-color: #757575;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 10px;
            background-image: url('../public/assets/background/geometric_white.png');
            background-repeat: repeat;
            background-attachment: fixed;
            color: #333333;
        }
    
        .container {
            position: relative; 
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background-color: var(--background-color);
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .alert {
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .edit-section {
            background-color: rgba(255, 255, 255, 0.95);
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .profile-photo-section {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .profile-photo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #ffffff;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 15px;
            transition: transform 0.3s ease;
        }
        
        .profile-photo:hover {
            transform: scale(1.05);
        }
        
        .photo-controls {
            display: flex;
            gap: 10px;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .upload-btn {
            position: relative;
            overflow: hidden;
            display: inline-block;
        }
        
        .upload-btn input[type=file] {
            position: absolute;
            left: -9999px;
        }
        
        .edit-form {
            max-width: 500px;
        }
        
        .edit-form h3 {
            margin: 0 0 15px 0;
            font-size: 1.1rem;
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .form-row {
            display: flex;
            align-items: flex-end;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .form-group {
            flex: 1;
            min-width: 200px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--secondary-color);
            font-size: 0.9rem;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.1);
        }
        
        .form-buttons {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .btn {
            padding: 10px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            white-space: nowrap;
        }
        
        .btn-primary {
            background-color: #007bff;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #0056b3;
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background-color: #f8f9fa;
            color: #6c757d;
            border: 1px solid #dee2e6;
        }
        
        .btn-secondary:hover {
            background-color: #e9ecef;
            color: #495057;
        }
        
        .btn-danger {
            background-color: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #c82333;
        }
        
        .btn-success {
            background-color: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background-color: #218838;
        }
        
        .edit-toggle {
            position: absolute;
            top: 1rem;
            right: 1rem;  /* Position on the right */
            z-index: 100;  /* Below pin icons but above content */
            background-color: #212121;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 10px 16px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);  /* Add shadow like hamburger menu */
        }
        
        .edit-toggle:hover {
            background-color: #616161;
            transform: translateY(-1px);
        }
        
        .edit-toggle.cancel {
            background-color: #dc3545;
        }
        
        .edit-toggle.cancel:hover {
            background-color: #c82333;
        }
        
        .edit-form.hidden {
            display: none;
        }
        
        .participant-select {
            width: 200px;
            padding: 8px 12px;
            font-size: 16px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin: 1rem 0;
            display: block;
            background-color: white;
        }
    
        .profile-header {
            background: linear-gradient(135deg, #616161 0%, #616161 100%);
            padding: 2rem;
            color: white;
            text-align: center;
            border-radius: 8px;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
            min-height: 200px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    
        .logo-background {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-around;
            align-items: center;
            opacity: 0.2;
        }
    
        .header-logo {
            width: 100px;
            height: 100px;
            object-fit: contain;
            margin: 5px;
        }
    
        .profile-content {
            position: relative;
            z-index: 2;
        }
    
        .stats-grid {
            display: grid;
            gap: 20px;
            grid-template-columns: 1fr;
        }
        
        @media (min-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    
        .stats-card {
        background-color: rgba(245, 245, 245, 0.8);
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            position: relative; /* Make positioning context for pin icons */
    }
    
        .section-title {
            font-size: 1.25rem;
            font-weight: bold;
            margin: 0 0 1rem 0;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #eee;
        }
    
        .team-logo {
            width: 30px;
            height: 30px;
            object-fit: contain;
            vertical-align: middle;
            margin-right: 10px;
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

        /* Profile photo container and edit overlay */
        .profile-photo-container {
            position: relative;
            display: inline-block;
        }
        
        .header-profile-photo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
            transition: transform 0.2s ease;
        }
        
        .photo-edit-overlay {
            position: absolute;
            bottom: -2px;
            right: -2px;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        
        .profile-photo-container:hover .photo-edit-overlay {
            opacity: 1;
        }
        
        .photo-edit-btn {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #007bff;
            color: white;
            border: 2px solid white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            transition: all 0.2s ease;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }
        
        .photo-edit-btn:hover {
            background: #0056b3;
            transform: scale(1.1);
        }
        
        /* Photo options modal */
        .photo-options-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        
        .photo-options-content {
            background: white;
            border-radius: 12px;
            padding: 25px;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            text-align: center;
        }
        
        .photo-options-content h3 {
            margin: 0 0 20px 0;
            color: #333;
        }
        
        .photo-options-buttons {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .photo-option-btn {
            padding: 12px 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #f8f9fa;
            color: #333;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .photo-option-btn:hover {
            background: #e9ecef;
            border-color: #adb5bd;
            transform: translateY(-1px);
        }
        
        .photo-option-btn.primary {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        .photo-option-btn.primary:hover {
            background: #0056b3;
            border-color: #0056b3;
        }
        
        .photo-option-btn.danger {
            background: #dc3545;
            color: white;
            border-color: #dc3545;
        }
        
        .photo-option-btn.danger:hover {
            background: #c82333;
            border-color: #c82333;
        }
        
        .photo-preview {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 15px;
            border: 3px solid #f0f0f0;
        }
        
        /* Auto-Draft Settings Styles */
        .auto-draft-section {
            background-color: rgba(255, 255, 255, 0.95);
            border: 2px solid #007bff;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .auto-draft-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .auto-draft-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            color: var(--primary-color);
            font-size: 1.2rem;
        }
        
        .auto-draft-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .auto-draft-status.enabled {
            background-color: #d4edda;
            color: #155724;
        }
        
        .auto-draft-status.disabled {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .auto-draft-description {
            color: var(--secondary-color);
            margin: 10px 0;
            line-height: 1.5;
        }
        
        .auto-draft-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: 0.4s;
            border-radius: 34px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: 0.4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: #28a745;
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }
        
        .preferences-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .preferences-link:hover {
            background-color: #0056b3;
            transform: translateY(-1px);
            color: white;
        }
        
        /* Profile tabs */
        .profile-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
            flex-wrap: wrap;
        }
        
        .profile-tab {
            padding: 12px 20px;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            color: var(--secondary-color);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .profile-tab:hover {
            color: var(--primary-color);
            border-bottom-color: var(--hover-color);
        }
        
        .profile-tab.active {
            color: #007bff;
            border-bottom-color: #007bff;
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
        }
        
        .game-list-outcome.win {
            color: var(--success-color, #4CAF50);
        }
        
        .game-list-outcome.loss {
            color: var(--error-color, #F44336);
        }

        /* Mobile Responsiveness */
        /* Widget Pin Icon Styles */
        .widget-pin-icon {
            position: absolute;
            top: 12px;
            right: 12px;
            background: transparent;
            color: #999;
            border: none;
            border-radius: 4px;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s ease;
            z-index: 500;
            opacity: 0.6;
        }
        
        .widget-pin-icon:hover {
            opacity: 1;
            color: #007bff;
            background: rgba(0, 123, 255, 0.08);
        }
        
        .widget-pin-icon.pinned {
            color: #28a745;
            opacity: 0.8;
        }
        
        .widget-pin-icon.pinned:hover {
            opacity: 1;
            background: rgba(40, 167, 69, 0.08);
        }
        
        @media (max-width: 768px) {
            .container {
                padding-top: 70px; /* Space for hamburger menu and edit button */
            }
            
            /* Player Card Enhanced - Mobile */
            .player-card-enhanced {
                padding: 15px 10px !important;
            }
            
            .player-stats-grid .stat-item {
                padding: 6px 4px !important;
            }
            
            .player-stats-grid .stat-value {
                font-size: 1.1rem !important;
            }
            
            .player-stats-grid .stat-label {
                font-size: 0.7rem !important;
            }
            
            .form-row {
                flex-direction: column;
            }
            
            .form-buttons, .photo-controls {
                justify-content: flex-start;
                margin-top: 10px;
            }
            
            .edit-section {
                margin-left: 0;
                margin-right: 0;
                padding: 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .profile-header {
                padding: 1.5rem;
                min-height: 150px;
            }
            
            .participant-select {
                width: 100%;
                max-width: 300px;
                margin-top: 0;
            }
            
            .profile-photo {
                width: 100px;
                height: 100px;
            }
            
            .profile-tabs {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .profile-tab {
                white-space: nowrap;
                flex-shrink: 0;
            }
            
            /* Mobile-optimized game list */
            .game-list-item {
                padding: 0.75rem 0.5rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .game-list-info {
                width: 100%;
            }
            
            .game-list-date {
                font-size: 0.75rem;
                margin-bottom: 0.5rem;
            }
            
            .game-list-matchup {
                font-size: 0.9rem;
                line-height: 1.4;
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 4px;
            }
            
            .game-list-matchup img {
                width: 18px !important;
                height: 18px !important;
                margin: 0 3px !important;
            }
            
            .game-list-matchup span {
                font-size: 0.75rem !important;
                display: block;
                width: 100%;
                margin-top: 4px;
            }
            
            .game-list-result {
                width: 100%;
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding-top: 0.5rem;
                border-top: 1px solid rgba(0,0,0,0.1);
            }
            
            .game-list-score {
                font-size: 1rem;
                margin-bottom: 0;
            }
            
            .game-list-outcome {
                font-size: 1rem;
                font-weight: bold;
            }
            
            /* Mobile styles for League Stats and Rivals */
            .stats-card .team-row {
                padding: 10px 8px !important;
            }
            
            .stats-card .team-row span {
                font-size: 0.8rem !important;
            }
            
            .stats-card .team-row a {
                font-size: 0.8rem !important;
            }
            
            .stats-card .team-row .team-info {
                font-size: 0.8rem !important;
            }
            
            .stats-card .team-row .team-record {
                font-size: 0.9rem !important;
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
            
            .section-title {
                font-size: 1rem !important;
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
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <?php if ($is_own_profile): ?>
        <!-- Compact Edit Section - Hidden by default -->
        <div class="edit-form edit-section hidden" id="editForm">
            <h3>Edit Your Profile</h3>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_display_name">
                <div class="form-row">
                    <div class="form-group">
                        <label for="display_name">Display Name:</label>
                        <input type="text" id="display_name" name="display_name" 
                               value="<?php echo htmlspecialchars($participant['display_name']); ?>" 
                               class="form-control" maxlength="20" required>
                        <small id="charCount" style="color: #666; font-size: 0.8em;">0/20 characters</small>
                    </div>
                    <div class="form-buttons">
                        <button type="submit" class="btn btn-primary">Update</button>
                        <button type="button" class="btn btn-secondary" onclick="toggleEditForm()">Cancel</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Hidden photo upload forms -->
        <form method="POST" enctype="multipart/form-data" id="photoUploadForm" style="display: none;">
            <input type="hidden" name="action" value="upload_photo">
            <input type="file" id="profile_photo" name="profile_photo" 
                   accept="image/jpeg,image/png,image/gif,image/webp" 
                   onchange="previewAndUpload(this)">
        </form>
        
        <form method="POST" id="deletePhotoForm" style="display: none;">
            <input type="hidden" name="action" value="delete_photo">
        </form>

        <!-- Photo upload progress -->
        <div id="uploadProgress" class="upload-progress" style="display: none; position: fixed; top: 20px; right: 20px; z-index: 1000; background: white; padding: 15px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
            <div>Uploading photo...</div>
            <progress value="0" max="100" style="width: 200px; margin-top: 10px;"></progress>
        </div>
        
        <!-- Compact Auto-Draft Toggle - only show if draft not completed -->
        <?php if (!$draft_completed): ?>
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 15px 20px; background: rgba(255,255,255,0.7); border-radius: 6px; margin-top: 60px; margin-bottom: 20px; gap: 15px; flex-wrap: wrap;">
            <form method="POST" action="" id="autoDraftForm" style="display: flex; align-items: center; gap: 12px; margin: 0;">
                <input type="hidden" name="action" value="toggle_auto_draft">
                <label class="toggle-switch" style="margin: 0;">
                    <input type="checkbox" 
                           name="auto_draft_enabled" 
                           id="autoDraftToggle"
                           <?php echo $participant['auto_draft_enabled'] ? 'checked' : ''; ?>
                           onchange="confirmAutoDraftToggle(this)">
                    <span class="toggle-slider"></span>
                </label>
                <label for="autoDraftToggle" style="cursor: pointer; user-select: none; margin: 0; font-size: 0.95rem; color: #333;">
                    <strong>Auto-Draft:</strong> 
                    <span style="color: <?php echo $participant['auto_draft_enabled'] ? '#28a745' : '#6c757d'; ?>;">
                        <?php echo $participant['auto_draft_enabled'] ? 'On' : 'Off'; ?>
                    </span>
                </label>
            </form>
            
            <a href="/nba-wins-platform/profiles/draft_preferences.php?league_id=<?php echo $league_id; ?>&user_id=<?php echo $user_id; ?>" 
               style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; font-size: 0.9rem; white-space: nowrap;">
                <i class="fas fa-list-ol"></i>
                Team Rankings
                <?php
                // Check if user has draft preferences set
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM user_draft_preferences WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $pref_count = $stmt->fetch()['count'];
                
                if ($pref_count == 30) {
                    echo ' <span style="background: rgba(255,255,255,0.3); padding: 2px 6px; border-radius: 10px; font-size: 0.85rem;">✓</span>';
                } elseif ($pref_count > 0) {
                    echo ' <span style="background: rgba(255,255,255,0.3); padding: 2px 6px; border-radius: 10px; font-size: 0.85rem;">' . $pref_count . '/30</span>';
                }
                ?>
            </a>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- UPDATED: Dropdown now uses user_id instead of participant_name -->
        <select class="participant-select" onchange="window.location.href='?league_id=<?php echo $league_id; ?>&user_id=' + this.value">
            <?php foreach ($all_participants as $p): ?>
                <option value="<?php echo $p['user_id']; ?>" 
                        <?php echo $p['user_id'] == $user_id ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($p['display_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div class="profile-header">
            <div class="logo-background">
                <?php foreach ($teams as $team): ?>
                    <img src="<?php echo htmlspecialchars(getTeamLogo($team['team_name'])); ?>" 
                         alt="<?php echo htmlspecialchars($team['team_name']); ?>" 
                         class="header-logo"
                         onerror="this.style.display='none'">
                <?php endforeach; ?>
            </div>
            <div class="profile-content">
                <div style="display: flex; align-items: center; justify-content: center; gap: 20px; flex-wrap: wrap;">
                    <div class="profile-photo-container">
                        <img src="<?php echo htmlspecialchars($profile_photo_url); ?>" 
                             alt="<?php echo htmlspecialchars($participant['display_name']); ?>'s Profile" 
                             class="header-profile-photo"
                             onerror="this.src='../public/assets/profile_photos/default.png'">
                        <?php if ($is_own_profile): ?>
                        <div class="photo-edit-overlay">
                            <button type="button" class="photo-edit-btn" onclick="showPhotoOptions()" title="Edit Profile Photo">
                                <i class="fas fa-camera"></i>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h1 style="font-size: 2.5rem; margin: 0; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: center;">
                            <?php echo htmlspecialchars($participant['display_name']); ?>
                            <?php if ($is_own_profile): ?>
                            <button type="button" 
                                    onclick="toggleEditForm()" 
                                    style="background: transparent; border: none; color: #bbb; cursor: pointer; padding: 4px; font-size: 1.2rem; transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center;"
                                    onmouseover="this.style.color='#888'" 
                                    onmouseout="this.style.color='#bbb'"
                                    title="Edit display name">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php endif; ?>
                        </h1>
                        <?php if (!empty($participant['participant_name']) && $participant['participant_name'] !== $participant['display_name']): ?>
                        <div style="font-size: 1rem; margin: 2px 0 8px 0; opacity: 0.8; font-style: italic;">
                            <?php echo htmlspecialchars($participant['participant_name']); ?>
                        </div>
                        <?php endif; ?>
                        <div style="font-size: 1.5rem; margin: 5px 0;">
                            <?php echo $total_wins; ?>-<?php echo $total_losses; ?>
                            <?php if ($total_wins + $total_losses > 0): ?>
                                <span style="font-size: 1rem; opacity: 0.8;">
                                    (<?php echo number_format(($total_wins / ($total_wins + $total_losses)) * 100, 1); ?>%)
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <p style="margin: 10px 0 0 0; font-size: 1.1rem;"><?php echo htmlspecialchars($league['display_name']); ?></p>
            </div>
        </div>
        
        <!-- Stats Grid Container -->
        <div class="stats-grid">
            <!-- Teams Card -->
            <div class="stats-card">
                <h2 class="section-title">Teams (<?php echo count($teams); ?>)</h2>
                <div>
                    <?php if (empty($teams)): ?>
                        <div class="no-data">No teams drafted yet</div>
                    <?php else: ?>
                        <?php foreach ($teams as $team): ?>
                            <div class="team-row">
                                <div class="team-info">
                                    <img src="<?php echo htmlspecialchars(getTeamLogo($team['team_name'])); ?>" 
                                         alt="<?php echo htmlspecialchars($team['team_name']); ?>" 
                                         class="team-logo"
                                         onerror="this.src='../public/assets/team_logos/default.png'">
                                    <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($team['team_name']); ?>" 
                                       style="text-decoration: none; color: inherit; transition: color 0.2s;"
                                       onmouseover="this.style.color='#007bff'" 
                                       onmouseout="this.style.color='inherit'">
                                        <?php echo htmlspecialchars($team['team_name']); ?>
                                    </a>
                                </div>
                                <div class="team-record">
                                    <?php echo $team['wins']; ?>-<?php echo $team['losses']; ?>
                                    <?php if ($team['games_played'] > 0): ?>
                                        <div style="font-size: 0.8em; color: #666;">
                                            <?php echo $team['win_percentage']; ?>%
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Stats Card -->
            <div class="stats-card" data-widget-type="league_stats">
                <?php if ($is_own_profile): ?>
                <button class="widget-pin-icon <?php echo in_array('league_stats', $pinned_widgets) ? 'pinned' : ''; ?>" 
                        onclick="toggleWidgetPin('league_stats', this)"
                        title="<?php echo in_array('league_stats', $pinned_widgets) ? 'Unpin from homepage' : 'Pin to homepage'; ?>">
                    <i class="fas fa-<?php echo in_array('league_stats', $pinned_widgets) ? 'check' : 'thumbtack'; ?>"></i>
                </button>
                <?php endif; ?>
                <h2 class="section-title">League Stats</h2>
                <div>
                    <div class="team-row">
                        <div class="team-info">
                            <span>Total Games Played</span>
                        </div>
                        <div class="team-record">
                            <?php echo array_sum(array_column($teams, 'games_played')); ?>
                        </div>
                    </div>
                    <div class="team-row">
                        <div class="team-info">
                            <span>Average Team Record</span>
                        </div>
                        <div class="team-record">
                            <?php 
                            $avg_wins = count($teams) > 0 ? round($total_wins / count($teams), 1) : 0;
                            $avg_losses = count($teams) > 0 ? round($total_losses / count($teams), 1) : 0;
                            echo $avg_wins . '-' . $avg_losses; 
                            ?>
                        </div>
                    </div>
                    <div class="team-row">
                        <div class="team-info">
                            <span>Best Team</span>
                        </div>
                        <div class="team-record">
                            <?php 
                            $best_team = null;
                            $best_wins = -1;
                            $best_win_pct = -1;
                            
                            foreach ($teams as $team) {
                                // If this team has more wins, it's the new best team
                                if ($team['wins'] > $best_wins) {
                                    $best_wins = $team['wins'];
                                    $best_win_pct = $team['win_percentage'];
                                    $best_team = $team;
                                }
                                // If wins are tied, use win percentage as tiebreaker
                                elseif ($team['wins'] == $best_wins && $team['win_percentage'] > $best_win_pct) {
                                    $best_win_pct = $team['win_percentage'];
                                    $best_team = $team;
                                }
                            }
                            
                            if ($best_team) {
                                echo htmlspecialchars($best_team['team_name']) . ' (' . $best_team['wins'] . '-' . $best_team['losses'] . ')';
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <!-- Rivals Section Inside League Stats -->
                    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
                        <h3 style="margin: 0 0 15px 0; font-size: 1.1rem; color: var(--primary-color); display: flex; align-items: center;">
                            <i class="fas fa-trophy" style="margin-right: 8px;"></i>Rivals
                        </h3>
                        <?php if ($biggest_rival): ?>
                        <div class="team-row">
                            <div class="team-info">
                                <i class="fas fa-fire" style="color: #ff4444; margin-right: 8px;"></i>
                                <span>Most Wins Against</span>
                            </div>
                            <div class="team-record">
                                <a href="?league_id=<?php echo $league_id; ?>&user_id=<?php echo $biggest_rival['opponent_user_id']; ?>" 
                                   style="text-decoration: none; color: #007bff; font-weight: 600;">
                                    <?php echo htmlspecialchars($biggest_rival['opponent_name']); ?>
                                </a>
                                <div style="font-size: 0.9em; color: #28a745; margin-top: 2px;">
                                    <?php echo $biggest_rival['wins_against_opponent']; ?>-<?php echo $biggest_rival['losses_against_opponent']; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($nemesis): ?>
                        <div class="team-row">
                            <div class="team-info">
                                <i class="fas fa-skull-crossbones" style="color: #721c24; margin-right: 8px;"></i>
                                <span>Most Losses Against</span>
                            </div>
                            <div class="team-record">
                                <a href="?league_id=<?php echo $league_id; ?>&user_id=<?php echo $nemesis['opponent_user_id']; ?>" 
                                   style="text-decoration: none; color: #007bff; font-weight: 600;">
                                    <?php echo htmlspecialchars($nemesis['opponent_name']); ?>
                                </a>
                                <div style="font-size: 0.9em; color: #dc3545; margin-top: 2px;">
                                    <?php echo $nemesis['wins_against_opponent']; ?>-<?php echo $nemesis['losses_against_opponent']; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!$biggest_rival && !$nemesis): ?>
                        <div class="no-data">
                            <i class="fas fa-handshake" style="margin-right: 8px;"></i>
                            No head-to-head games yet
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Last 10 Games Section -->
        <div class="stats-card" data-widget-type="last_10_games">
            <?php if ($is_own_profile): ?>
            <button class="widget-pin-icon <?php echo in_array('last_10_games', $pinned_widgets) ? 'pinned' : ''; ?>" 
                    onclick="toggleWidgetPin('last_10_games', this)"
                    title="<?php echo in_array('last_10_games', $pinned_widgets) ? 'Unpin from homepage' : 'Pin to homepage'; ?>">
                <i class="fas fa-<?php echo in_array('last_10_games', $pinned_widgets) ? 'check' : 'thumbtack'; ?>"></i>
            </button>
            <?php endif; ?>
            <?php 
            // Calculate W-L record from last 10 games
            $last10_wins = 0;
            $last10_losses = 0;
            foreach ($lastGames as $game) {
                if ($game['result'] === 'W') {
                    $last10_wins++;
                } else if ($game['result'] === 'L') {
                    $last10_losses++;
                }
            }
            ?>
            <h2 class="section-title">
                <i class="fas fa-history"></i>
                Last 10 Games
                <?php if (!empty($lastGames)): ?>
                    <span style="font-size: 0.9rem; color: #666; margin-left: 10px;">
                        (<?php echo $last10_wins; ?>-<?php echo $last10_losses; ?>)
                    </span>
                <?php endif; ?>
            </h2>
            
            <?php if (!empty($lastGames)): ?>
            <div class="games-list">
                <?php foreach (array_reverse($lastGames) as $game): 
                    $teamScore = ($game['team_location'] === 'home') ? $game['home_points'] : $game['away_points'];
                    $oppScore = ($game['team_location'] === 'home') ? $game['away_points'] : $game['home_points'];
                    $gameUrl = "/nba-wins-platform/stats/game_details.php?home_team=" . urlencode($game['home_team_code']) . "&away_team=" . urlencode($game['away_team_code']) . "&date=" . urlencode($game['game_date']);
                ?>
                <a href="<?php echo $gameUrl; ?>" class="game-list-item clickable <?php echo strtolower($game['result']); ?>" style="display: flex;">
                    <div class="game-list-info">
                        <div class="game-list-date">
                            <?php echo date('M j, Y', strtotime($game['game_date'])); ?>
                        </div>
                        <div class="game-list-matchup">
                            <img src="<?php echo htmlspecialchars(getTeamLogo($game['my_team'])); ?>" 
                                 alt="<?php echo htmlspecialchars($game['my_team']); ?>" 
                                 style="width: 20px; height: 20px; vertical-align: middle; margin-right: 5px;"
                                 onerror="this.style.display='none'">
                            <?php echo htmlspecialchars($game['my_team']); ?>
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
                        <div class="game-list-outcome" style="color: <?php echo $game['result'] === 'W' ? '#4CAF50' : '#F44336'; ?>; font-weight: bold;">
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
        </div>
        
        <!-- Upcoming 5 Games Section -->
        <div class="stats-card" data-widget-type="upcoming_games">
            <?php if ($is_own_profile): ?>
            <button class="widget-pin-icon <?php echo in_array('upcoming_games', $pinned_widgets) ? 'pinned' : ''; ?>" 
                    onclick="toggleWidgetPin('upcoming_games', this)"
                    title="<?php echo in_array('upcoming_games', $pinned_widgets) ? 'Unpin from homepage' : 'Pin to homepage'; ?>">
                <i class="fas fa-<?php echo in_array('upcoming_games', $pinned_widgets) ? 'check' : 'thumbtack'; ?>"></i>
            </button>
            <?php endif; ?>
            <h2 class="section-title">
                <i class="fas fa-calendar-alt"></i>
                Next 5 Games
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
                            <img src="<?php echo htmlspecialchars(getTeamLogo($game['my_team'])); ?>" 
                                 alt="<?php echo htmlspecialchars($game['my_team']); ?>" 
                                 style="width: 20px; height: 20px; vertical-align: middle; margin-right: 5px;"
                                 onerror="this.style.display='none'">
                            <?php echo htmlspecialchars($game['my_team']); ?>
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
        </div>
    </div>

    <!-- Photo Options Modal -->
    <?php if ($is_own_profile): ?>
    <div id="photoOptionsModal" class="photo-options-modal">
        <div class="photo-options-content">
            <h3>Profile Photo</h3>
            <img src="<?php echo htmlspecialchars($profile_photo_url); ?>" 
                 alt="Current Profile Photo" 
                 class="photo-preview"
                 onerror="this.src='../public/assets/profile_photos/default.png'">
            
            <div class="photo-options-buttons">
                <button type="button" class="photo-option-btn primary" onclick="triggerPhotoUpload()">
                    <i class="fas fa-camera"></i>
                    Upload New Photo
                </button>
                
                <?php if ($participant['profile_photo']): ?>
                <button type="button" class="photo-option-btn danger" onclick="deletePhoto()">
                    <i class="fas fa-trash"></i>
                    Delete Photo
                </button>
                <?php endif; ?>
                
                <button type="button" class="photo-option-btn" onclick="closePhotoOptions()">
                    <i class="fas fa-times"></i>
                    Cancel
                </button>
            </div>
            
            <div style="margin-top: 15px; font-size: 0.8em; color: #666; text-align: center;">
                JPEG, PNG, GIF, WebP - Max 5MB<br>
                Automatically resized to 800x800
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function toggleEditForm() {
            const editForm = document.getElementById('editForm');
            
            if (editForm.classList.contains('hidden')) {
                editForm.classList.remove('hidden');
                // Scroll to the edit form
                editForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
                // Focus on the input field
                setTimeout(() => {
                    const input = document.getElementById('display_name');
                    if (input) {
                        input.focus();
                        input.select();
                    }
                }, 300);
            } else {
                editForm.classList.add('hidden');
            }
        }

        // Photo modal functions
        function showPhotoOptions() {
            const modal = document.getElementById('photoOptionsModal');
            if (modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }
        }
        
        function closePhotoOptions() {
            const modal = document.getElementById('photoOptionsModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }
        
        function triggerPhotoUpload() {
            const fileInput = document.getElementById('profile_photo');
            if (fileInput) {
                fileInput.click();
            }
        }
        
        function deletePhoto() {
            if (confirm('Are you sure you want to delete your profile photo?')) {
                const deleteForm = document.getElementById('deletePhotoForm');
                if (deleteForm) {
                    deleteForm.submit();
                }
            }
        }

        function previewAndUpload(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const maxSize = 5 * 1024 * 1024;
                
                if (file.size > maxSize) {
                    alert('File is too large. Maximum size is 5MB.');
                    input.value = '';
                    return;
                }
                
                const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Invalid file type. Please select a JPEG, PNG, GIF, or WebP image.');
                    input.value = '';
                    return;
                }
                
                closePhotoOptions();
                
                const progressDiv = document.getElementById('uploadProgress');
                if (progressDiv) {
                    progressDiv.style.display = 'block';
                }
                
                document.getElementById('photoUploadForm').submit();
            }
        }

        // Auto-draft toggle confirmation
        function confirmAutoDraftToggle(checkbox) {
            const form = document.getElementById('autoDraftForm');
            const isEnabled = checkbox.checked;
            
            if (isEnabled) {
                const hasPreferences = <?php 
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM user_draft_preferences WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    echo $stmt->fetch()['count'] == 30 ? 'true' : 'false';
                ?>;
                
                if (!hasPreferences) {
                    if (confirm('Set your team rankings first?\n\nWithout rankings, random teams will be selected.')) {
                        window.location.href = '/nba-wins-platform/profiles/draft_preferences.php?league_id=<?php echo $league_id; ?>&user_id=<?php echo $user_id; ?>';
                        return;
                    }
                }
                form.submit();
            } else {
                form.submit();
            }
        }

        // Character count for display name
        document.addEventListener('DOMContentLoaded', function() {
            const displayNameInput = document.getElementById('display_name');
            const charCount = document.getElementById('charCount');
            
            if (displayNameInput && charCount) {
                function updateCharCount() {
                    const current = displayNameInput.value.length;
                    charCount.textContent = `${current}/20 characters`;
                    if (current > 20) {
                        charCount.style.color = '#dc3545';
                    } else {
                        charCount.style.color = '#666';
                    }
                }
                
                displayNameInput.addEventListener('input', updateCharCount);
                updateCharCount();
            }
            
            const modal = document.getElementById('photoOptionsModal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closePhotoOptions();
                    }
                });
            }
        });

        // Auto-hide edit form after successful update
        <?php if (!empty($success_message)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const editForm = document.getElementById('editForm');
            if (editForm) {
                editForm.classList.add('hidden');
            }
            
            const progressDiv = document.getElementById('uploadProgress');
            if (progressDiv) {
                progressDiv.style.display = 'none';
            }
        });
        <?php endif; ?>
    </script>

    

    <script>
    // Widget pin/unpin functionality
    function toggleWidgetPin(widgetType, button) {
        const isPinned = button.classList.contains('pinned');
        const action = isPinned ? 'unpin' : 'pin';
        
        // Show confirmation for pinning
        if (!isPinned) {
            if (!confirm('Pin this section to your homepage?')) {
                return;
            }
        }
        
        // Make AJAX request to pin/unpin
        const formData = new FormData();
        formData.append('action', action);
        formData.append('widget_type', widgetType);
        
        fetch('/nba-wins-platform/core/handle_widget_pin.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success message
                alert(data.message);
                // Reload page to update pin status
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
    </script>
</body>
</html>