<?php
/**
 * participant_profile_new.php - Participant Profile Page
 * 
 * Displays a league participant's profile including:
 *   - Profile photo, display name, total record
 *   - Drafted teams with records
 *   - League stats (total games, avg record, best team)
 *   - Rivals (most wins against, nemesis)
 *   - Last 10 games and upcoming 5 games
 *   - Own-profile editing: display name, photo upload, auto-draft toggle
 *   - Widget pinning to homepage dashboard
 * 
 * Path: /data/www/default/nba-wins-platform/profiles/participant_profile_new.php
 */

date_default_timezone_set('America/New_York');
session_start();

// =====================================================================
// SESSION CONTEXT
// =====================================================================
$current_league_id = isset($_SESSION['current_league_id']) ? $_SESSION['current_league_id'] : '';
$current_user_id   = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : '';

// =====================================================================
// REQUEST PARAMETERS
// =====================================================================
$league_id = isset($_GET['league_id']) ? intval($_GET['league_id']) : null;
$user_id   = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;

if (!$league_id || !$user_id) {
    die("Missing required parameters: league_id and user_id");
}

// =====================================================================
// DEPENDENCIES
// =====================================================================
require_once '../config/db_connection.php';
require_once '../core/ProfilePhotoHandler.php';

$photoHandler = new ProfilePhotoHandler($pdo);
$is_guest     = isset($_SESSION['is_guest']) && $_SESSION['is_guest'] === true;

$success_message = '';
$error_message   = '';


// =====================================================================
// POST ACTION HANDLING
// =====================================================================
if ($_POST && $is_guest) {
    $error_message = "Guest users cannot modify profiles.";
    $_POST = [];
}

if ($_POST) {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {

            // --- Update Display Name ---
            case 'update_display_name':
                if (isset($_SESSION['user_id'])) {
                    $new_display_name = trim($_POST['display_name']);
                    if (!empty($new_display_name) && strlen($new_display_name) <= 20) {
                        try {
                            $stmt = $pdo->prepare("UPDATE users SET display_name = ? WHERE id = ?");
                            $stmt->execute([$new_display_name, $_SESSION['user_id']]);
                            $success_message = "Display name updated!";
                        } catch (Exception $e) {
                            $error_message = "Error: " . $e->getMessage();
                        }
                    } else {
                        $error_message = "Display name must be 1-20 characters.";
                    }
                }
                break;

            // --- Upload Profile Photo ---
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

            // --- Delete Profile Photo ---
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

            // --- Toggle Theme ---
            case 'toggle_theme':
                if (isset($_SESSION['user_id'])) {
                    $new_theme = ($_POST['theme'] === 'classic') ? 'classic' : 'dark';
                    try {
                        $stmt = $pdo->prepare("UPDATE users SET theme_preference = ? WHERE id = ?");
                        $stmt->execute([$new_theme, $_SESSION['user_id']]);
                        $_SESSION['theme_preference'] = $new_theme;
                        $success_message = "Theme updated!";
                    } catch (Exception $e) {
                        $error_message = "Error: " . $e->getMessage();
                    }
                }
                break;

            // --- Toggle Auto-Draft ---
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
                        $success_message = "Auto-draft setting updated!";

                        // Refresh participant data after update
                        $stmt = $pdo->prepare("
                            SELECT lp.*, u.display_name, u.id AS user_id, u.profile_photo, u.theme_preference
                            FROM league_participants lp
                            JOIN users u ON lp.user_id = u.id
                            WHERE lp.user_id = ? AND lp.league_id = ?
                        ");
                        $stmt->execute([$user_id, $league_id]);
                        $participant = $stmt->fetch(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {
                        $error_message = "Error: " . $e->getMessage();
                    }
                }
                break;
        }
    }
}


// ==========================================================================
// DATA QUERIES
// ==========================================================================

// ------ League Info ------
$stmt = $pdo->prepare("SELECT * FROM leagues WHERE id = ?");
$stmt->execute([$league_id]);
$pp_league = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pp_league) die("League not found");

$draft_completed = $pp_league['draft_completed'] == 1;

// ------ Participant Info ------
$stmt = $pdo->prepare("
    SELECT lp.*, u.display_name, u.id AS user_id, u.profile_photo, u.theme_preference
    FROM league_participants lp
    JOIN users u ON lp.user_id = u.id
    WHERE lp.user_id = ? AND lp.league_id = ?
");
$stmt->execute([$user_id, $league_id]);
$participant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$participant) {
    // Guest fallback: redirect to first active participant
    if ($is_guest) {
        $stmt = $pdo->prepare("
            SELECT u.id AS user_id
            FROM league_participants lp
            JOIN users u ON lp.user_id = u.id
            WHERE lp.league_id = ? AND lp.status = 'active'
            ORDER BY u.display_name ASC
            LIMIT 1
        ");
        $stmt->execute([$league_id]);
        $fallback = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fallback) {
            header("Location: ?league_id=$league_id&user_id=" . $fallback['user_id']);
            exit;
        }
    }
    die("Participant not found in this league.");
}

// ------ Own Profile Check ------
$is_own_profile = isset($_SESSION['user_id']) && ($participant['user_id'] == $_SESSION['user_id']);

// ------ Pinned Dashboard Widgets ------
$pinned_widgets = [];
if ($is_own_profile) {
    $stmt = $pdo->prepare("
        SELECT widget_type 
        FROM user_dashboard_widgets 
        WHERE user_id = ? AND is_active = 1
    ");
    $stmt->execute([$current_user_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pinned_widgets[] = $row['widget_type'];
    }
}

// ------ Profile Photo URL ------
$profile_photo_url = $photoHandler->getPhotoUrl($participant['user_id'], $participant['profile_photo']);

// ------ Theme Preference (set session from DB if own profile) ------
if ($is_own_profile && isset($participant['theme_preference'])) {
    $_SESSION['theme_preference'] = $participant['theme_preference'];
}
$current_theme = $_SESSION['theme_preference'] ?? 'dark';

// ------ Drafted Teams + Records ------
$stmt = $pdo->prepare("
    SELECT 
        dp.*,
        nt.name AS team_name,
        nt.abbreviation,
        nt.logo_filename AS logo,
        COALESCE(s.win, 0) AS wins,
        COALESCE(s.loss, 0) AS losses,
        (COALESCE(s.win, 0) + COALESCE(s.loss, 0)) AS games_played,
        CASE 
            WHEN (COALESCE(s.win, 0) + COALESCE(s.loss, 0)) > 0 
            THEN ROUND((COALESCE(s.win, 0) / (COALESCE(s.win, 0) + COALESCE(s.loss, 0))) * 100, 1)
            ELSE 0 
        END AS win_percentage,
        s.logo AS standings_logo
    FROM draft_picks dp
    JOIN league_participants lp ON dp.league_participant_id = lp.id
    JOIN nba_teams nt ON dp.team_id = nt.id
    LEFT JOIN 2025_2026 s ON nt.name = s.name
    WHERE dp.league_participant_id = ? AND lp.league_id = ?
    ORDER BY dp.pick_number ASC
");
$stmt->execute([$participant['id'], $league_id]);
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_wins   = 0;
$total_losses = 0;
foreach ($teams as $team) {
    $total_wins   += $team['wins'];
    $total_losses += $team['losses'];
}

// ------ All League Participants (for dropdown selector) ------
$stmt = $pdo->prepare("
    SELECT lp.id, u.display_name, lp.participant_name, u.id AS user_id
    FROM league_participants lp
    JOIN users u ON lp.user_id = u.id
    WHERE lp.league_id = ?
    ORDER BY u.display_name
");
$stmt->execute([$league_id]);
$all_participants = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ==========================================================================
// RIVALS QUERIES
// ==========================================================================

// ------ Biggest Rival (most wins against) ------
$stmt = $pdo->prepare("
    SELECT 
        opponent_user.id AS opponent_user_id,
        opponent_user.display_name AS opponent_name,
        SUM(CASE 
            WHEN ((g.home_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers') 
                   OR g.home_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                  AND g.home_points > g.away_points) THEN 1
            WHEN ((g.away_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers') 
                   OR g.away_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                  AND g.away_points > g.home_points) THEN 1
            ELSE 0 
        END) AS wins_against_opponent,
        SUM(CASE 
            WHEN ((g.home_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers') 
                   OR g.home_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                  AND g.home_points < g.away_points) THEN 1
            WHEN ((g.away_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers') 
                   OR g.away_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                  AND g.away_points < g.home_points) THEN 1
            ELSE 0 
        END) AS losses_against_opponent
    FROM league_participant_teams my_team
    JOIN league_participants my_participant 
        ON my_team.league_participant_id = my_participant.id
    JOIN games g 
        ON (g.home_team IN (
                REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            ) 
            OR g.away_team IN (
                REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            ))
        AND g.status_long IN ('Final', 'Finished')
        AND DATE(g.start_time) >= '2025-10-21'
    JOIN league_participant_teams opponent_team 
        ON ((g.home_team IN (
                REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            ) 
            AND g.away_team IN (
                REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            ))
            OR (g.away_team IN (
                REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            ) 
            AND g.home_team IN (
                REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            )))
    JOIN league_participants opponent_participant 
        ON opponent_team.league_participant_id = opponent_participant.id
        AND opponent_participant.league_id = my_participant.league_id
        AND opponent_participant.id != my_participant.id
    JOIN users opponent_user 
        ON opponent_participant.user_id = opponent_user.id
    WHERE my_participant.id = ?
    GROUP BY opponent_user.id, opponent_user.display_name
    HAVING wins_against_opponent > 0
    ORDER BY wins_against_opponent DESC, losses_against_opponent ASC
    LIMIT 1
");
$stmt->execute([$participant['id']]);
$biggest_rival = $stmt->fetch(PDO::FETCH_ASSOC);

// ------ Nemesis (most losses against) ------
$stmt = $pdo->prepare("
    SELECT 
        opponent_user.id AS opponent_user_id,
        opponent_user.display_name AS opponent_name,
        SUM(CASE 
            WHEN ((g.home_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers') 
                   OR g.home_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                  AND g.home_points < g.away_points) THEN 1
            WHEN ((g.away_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers') 
                   OR g.away_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                  AND g.away_points < g.home_points) THEN 1
            ELSE 0 
        END) AS losses_against_opponent,
        SUM(CASE 
            WHEN ((g.home_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers') 
                   OR g.home_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                  AND g.home_points > g.away_points) THEN 1
            WHEN ((g.away_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers') 
                   OR g.away_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                  AND g.away_points > g.home_points) THEN 1
            ELSE 0 
        END) AS wins_against_opponent
    FROM league_participant_teams my_team
    JOIN league_participants my_participant 
        ON my_team.league_participant_id = my_participant.id
    JOIN games g 
        ON (g.home_team IN (
                REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            ) 
            OR g.away_team IN (
                REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            ))
        AND g.status_long IN ('Final', 'Finished')
        AND DATE(g.start_time) >= '2025-10-21'
    JOIN league_participant_teams opponent_team 
        ON ((g.home_team IN (
                REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            ) 
            AND g.away_team IN (
                REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            ))
            OR (g.away_team IN (
                REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            ) 
            AND g.home_team IN (
                REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            )))
    JOIN league_participants opponent_participant 
        ON opponent_team.league_participant_id = opponent_participant.id
        AND opponent_participant.league_id = my_participant.league_id
        AND opponent_participant.id != my_participant.id
    JOIN users opponent_user 
        ON opponent_participant.user_id = opponent_user.id
    WHERE my_participant.id = ?
    GROUP BY opponent_user.id, opponent_user.display_name
    HAVING losses_against_opponent > 0
    ORDER BY losses_against_opponent DESC, wins_against_opponent ASC
    LIMIT 1
");
$stmt->execute([$participant['id']]);
$nemesis = $stmt->fetch(PDO::FETCH_ASSOC);


// ==========================================================================
// LAST 10 GAMES
// ==========================================================================
$lastGames = [];
try {
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
                g.date AS game_date,
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
                END AS team_location,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN g.home_team
                    WHEN g.away_team IN ($placeholders) THEN g.away_team
                END AS my_team,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN g.away_team
                    WHEN g.away_team IN ($placeholders) THEN g.home_team
                END AS opponent,
                CASE 
                    WHEN (g.home_team IN ($placeholders) AND g.home_points > g.away_points)
                      OR (g.away_team IN ($placeholders) AND g.away_points > g.home_points) THEN 'W'
                    WHEN g.home_points IS NOT NULL THEN 'L'
                    ELSE NULL 
                END AS result
            FROM games g
            WHERE (g.home_team IN ($placeholders) OR g.away_team IN ($placeholders))
              AND g.status_long IN ('Final', 'Finished')
              AND g.date >= '2025-10-21'
            ORDER BY g.date DESC, g.start_time DESC
            LIMIT 10
        ");

        // 10 placeholder groups in the query
        $params = array_merge(
            $participantTeams, $participantTeams,  // team_location
            $participantTeams, $participantTeams,  // my_team
            $participantTeams, $participantTeams,  // opponent
            $participantTeams, $participantTeams,  // result
            $participantTeams, $participantTeams   // WHERE clause
        );
        $stmt->execute($params);
        $lastGames = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Attach opponent owner info
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
        unset($game);
    }
} catch (Exception $e) {
    error_log("Error fetching last games: " . $e->getMessage());
}


// ==========================================================================
// UPCOMING 5 GAMES
// ==========================================================================
$upcomingGames = [];
try {
    if (!empty($participantTeams)) {
        $placeholders = str_repeat('?,', count($participantTeams) - 1) . '?';

        $stmt = $pdo->prepare("
            SELECT DISTINCT
                g.date AS game_date,
                g.home_team,
                g.away_team,
                g.home_team_code,
                g.away_team_code,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN 'home'
                    WHEN g.away_team IN ($placeholders) THEN 'away'
                END AS team_location,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN g.home_team
                    WHEN g.away_team IN ($placeholders) THEN g.away_team
                END AS my_team,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN g.away_team
                    WHEN g.away_team IN ($placeholders) THEN g.home_team
                END AS opponent
            FROM games g
            WHERE (g.home_team IN ($placeholders) OR g.away_team IN ($placeholders))
              AND g.status_long = 'Scheduled'
              AND g.date >= '2025-10-21'
            ORDER BY g.date ASC
            LIMIT 5
        ");

        // 8 placeholder groups in the query
        $params = array_merge(
            $participantTeams, $participantTeams,  // team_location
            $participantTeams, $participantTeams,  // my_team
            $participantTeams, $participantTeams,  // opponent
            $participantTeams, $participantTeams   // WHERE clause
        );
        $stmt->execute($params);
        $upcomingGames = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Attach opponent owner info
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
        unset($game);
    }
} catch (Exception $e) {
    error_log("Error fetching upcoming games: " . $e->getMessage());
}


// ==========================================================================
// HELPER FUNCTIONS
// ==========================================================================

/**
 * Get team logo path from team name
 */
function getTeamLogo($teamName) {
    $logoMap = [
        'Atlanta Hawks'          => 'atlanta_hawks.png',
        'Boston Celtics'         => 'boston_celtics.png',
        'Brooklyn Nets'          => 'brooklyn_nets.png',
        'Charlotte Hornets'      => 'charlotte_hornets.png',
        'Chicago Bulls'          => 'chicago_bulls.png',
        'Cleveland Cavaliers'    => 'cleveland_cavaliers.png',
        'Dallas Mavericks'       => 'dallas_mavericks.png',
        'Denver Nuggets'         => 'denver_nuggets.png',
        'Detroit Pistons'        => 'detroit_pistons.png',
        'Golden State Warriors'  => 'golden_state_warriors.png',
        'Houston Rockets'        => 'houston_rockets.png',
        'Indiana Pacers'         => 'indiana_pacers.png',
        'LA Clippers'            => 'la_clippers.png',
        'Los Angeles Clippers'   => 'la_clippers.png',
        'Los Angeles Lakers'     => 'los_angeles_lakers.png',
        'Memphis Grizzlies'      => 'memphis_grizzlies.png',
        'Miami Heat'             => 'miami_heat.png',
        'Milwaukee Bucks'        => 'milwaukee_bucks.png',
        'Minnesota Timberwolves' => 'minnesota_timberwolves.png',
        'New Orleans Pelicans'   => 'new_orleans_pelicans.png',
        'New York Knicks'        => 'new_york_knicks.png',
        'Oklahoma City Thunder'  => 'oklahoma_city_thunder.png',
        'Orlando Magic'          => 'orlando_magic.png',
        'Philadelphia 76ers'     => 'philadelphia_76ers.png',
        'Phoenix Suns'           => 'phoenix_suns.png',
        'Portland Trail Blazers' => 'portland_trail_blazers.png',
        'Sacramento Kings'       => 'sacramento_kings.png',
        'San Antonio Spurs'      => 'san_antonio_spurs.png',
        'Toronto Raptors'        => 'toronto_raptors.png',
        'Utah Jazz'              => 'utah_jazz.png',
        'Washington Wizards'     => 'washington_wizards.png'
    ];

    if (isset($logoMap[$teamName])) {
        return '../public/assets/team_logos/' . $logoMap[$teamName];
    }

    return '../public/assets/team_logos/' . strtolower(str_replace(' ', '_', $teamName)) . '.png';
}

$currentLeagueId = $league_id;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="theme-color" content="<?= ($_SESSION['theme_preference'] ?? 'dark') === 'classic' ? '#f5f5f5' : '#121a23' ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($participant['display_name']) ?>'s Profile</title>
    <link rel="apple-touch-icon" type="image/png" href="../public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="../public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
/* ==========================================================================
   CSS VARIABLES
   ========================================================================== */
:root {
    --bg-primary: #121a23;
    --bg-secondary: #1a222c;
    --bg-card: #202a38;
    --bg-card-hover: #273140;
    --bg-elevated: #2a3446;
    --border-color: rgba(255, 255, 255, 0.08);
    --text-primary: #e6edf3;
    --text-secondary: #8b949e;
    --text-muted: #545d68;
    --accent-blue: #388bfd;
    --accent-blue-dim: rgba(56, 139, 253, 0.15);
    --accent-green: #3fb950;
    --accent-red: #f85149;
    --accent-orange: #d29922;
    --radius-md: 10px;
    --radius-lg: 14px;
    --shadow-card: 0 1px 3px rgba(0, 0, 0, 0.4), 0 0 0 1px var(--border-color);
    --transition-fast: 0.15s ease;
}

<?php if (($_SESSION['theme_preference'] ?? 'dark') === 'classic'): ?>
:root {
    --bg-primary: #f5f5f5;
    --bg-secondary: rgba(245, 245, 245, 0.95);
    --bg-card: #ffffff;
    --bg-card-hover: #f8f9fa;
    --bg-elevated: #f0f0f2;
    --border-color: #e0e0e0;
    --border-subtle: rgba(0, 0, 0, 0.06);
    --text-primary: #333333;
    --text-secondary: #666666;
    --text-muted: #999999;
    --accent-blue: #0066ff;
    --accent-blue-dim: rgba(0, 102, 255, 0.08);
    --accent-green: #28a745;
    --accent-red: #dc3545;
    --accent-orange: #d4a017;
    --shadow-card: 0 1px 4px rgba(0, 0, 0, 0.08), 0 0 0 1px rgba(0, 0, 0, 0.04);
}
body {
    background-image: url('../public/assets/background/geometric_white.png');
    background-repeat: repeat;
    background-attachment: fixed;
}
<?php endif; ?>

/* ==========================================================================
   BASE / RESET
   ========================================================================== */
* { margin: 0; padding: 0; box-sizing: border-box; }
html { background: var(--bg-primary); }
body {
    font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
    line-height: 1.5;
    color: var(--text-primary);
    background: var(--bg-primary);
    background-image: radial-gradient(ellipse at 50% 0%, rgba(56, 139, 253, 0.04) 0%, transparent 60%);
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
}

/* ==========================================================================
   LAYOUT
   ========================================================================== */
.app-container { max-width: 1000px; margin: 0 auto; padding: 0 12px 2rem; }

.app-header {
    display: flex; align-items: center; justify-content: center;
    gap: 10px; padding: 16px 16px 12px; position: relative;
}
.app-header-logo { width: 36px; height: 36px; }
.app-header-title { font-size: 1.35rem; font-weight: 700; letter-spacing: -0.02em; }

.nav-toggle-btn {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    width: 38px; height: 38px;
    display: flex; align-items: center; justify-content: center;
    background: var(--bg-card); border: 1px solid var(--border-color);
    border-radius: var(--radius-md); color: var(--text-secondary);
    font-size: 16px; cursor: pointer; transition: all var(--transition-fast);
}
.nav-toggle-btn:hover {
    color: var(--text-primary);
    border-color: rgba(56, 139, 253, 0.3);
    background: var(--accent-blue-dim);
}

/* ==========================================================================
   ALERTS
   ========================================================================== */
.alert {
    padding: 10px 14px; border-radius: var(--radius-md);
    margin-bottom: 12px; font-size: 14px; font-weight: 500;
}
.alert-success {
    background: rgba(63, 185, 80, 0.15);
    color: var(--accent-green);
    border: 1px solid rgba(63, 185, 80, 0.2);
}
.alert-error {
    background: rgba(248, 81, 73, 0.15);
    color: var(--accent-red);
    border: 1px solid rgba(248, 81, 73, 0.2);
}

/* ==========================================================================
   PARTICIPANT SELECTOR
   ========================================================================== */
.participant-select {
    width: 100%; max-width: 250px;
    padding: 8px 30px 8px 12px;
    font-family: 'Outfit', sans-serif; font-size: 14px; font-weight: 500;
    background: var(--bg-card); color: var(--text-primary);
    border: 1px solid var(--border-color); border-radius: var(--radius-md);
    cursor: pointer; appearance: none; -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238b949e' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    margin-bottom: 14px; transition: all var(--transition-fast);
}
.participant-select:hover { border-color: rgba(56, 139, 253, 0.3); }
.participant-select option { background: var(--bg-card); color: var(--text-primary); }
.profile-topbar .participant-select { margin-bottom: 0; }

/* ==========================================================================
   PROFILE HEADER
   ========================================================================== */
.profile-header {
    background: var(--bg-card); padding: 2rem;
    color: var(--text-primary); text-align: center;
    border-radius: var(--radius-lg); margin-bottom: 14px;
    position: relative; overflow: hidden; min-height: 180px;
    display: flex; align-items: center; justify-content: center;
    box-shadow: var(--shadow-card);
}
.logo-background {
    position: absolute; top: 0; left: 0; right: 0; bottom: 0;
    display: flex; flex-wrap: wrap; justify-content: space-around;
    align-items: center; opacity: 0.08;
}
.header-logo { width: 90px; height: 90px; object-fit: contain; margin: 5px; }
.profile-content { position: relative; z-index: 2; }

.header-profile-photo {
    width: 80px; height: 80px; border-radius: 50%;
    object-fit: cover; border: 3px solid rgba(255, 255, 255, 0.2);
    transition: transform 0.2s;
}
.profile-photo-container { position: relative; display: inline-block; }
.photo-edit-overlay {
    position: absolute; bottom: -2px; right: -2px;
    opacity: 0; transition: opacity 0.2s;
}
.profile-photo-container:hover .photo-edit-overlay { opacity: 1; }
.photo-edit-btn {
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--accent-blue); color: white;
    border: 2px solid var(--bg-card); cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; transition: all 0.2s;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3);
}
.photo-edit-btn:hover { background: #2a7ae4; transform: scale(1.1); }

.profile-name {
    font-size: 2rem; font-weight: 800;
    margin: 0; letter-spacing: -0.02em;
}
.profile-record {
    font-size: 1.4rem; font-weight: 600; margin: 5px 0;
}
.profile-record .pct {
    font-size: 0.9rem; opacity: 0.6; font-weight: 400;
}
.profile-league {
    font-size: 0.95rem; color: var(--text-secondary); margin-top: 6px;
}
.edit-name-btn {
    background: transparent; border: none; color: var(--text-muted);
    cursor: pointer; padding: 4px; font-size: 1rem;
    transition: color 0.2s; display: inline-flex;
}
.edit-name-btn:hover { color: var(--accent-blue); }

/* ==========================================================================
   EDIT FORM
   ========================================================================== */
.edit-section {
    background: var(--bg-card); border: 1px solid var(--border-color);
    border-radius: var(--radius-md); padding: 16px;
    margin-bottom: 14px; box-shadow: var(--shadow-card);
}
.edit-section h3 { font-size: 1rem; color: var(--text-primary); margin-bottom: 12px; }
.edit-section.hidden { display: none; }

.form-row { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.form-group { flex: 1; min-width: 200px; }
.form-group label {
    display: block; margin-bottom: 4px;
    font-weight: 500; color: var(--text-secondary); font-size: 0.85rem;
}
.form-control {
    width: 100%; padding: 9px 12px;
    border: 1px solid var(--border-color); border-radius: var(--radius-md);
    font-size: 14px; font-family: 'Outfit', sans-serif;
    background: var(--bg-elevated); color: var(--text-primary);
    transition: border-color 0.2s;
}
.form-control:focus {
    outline: none; border-color: var(--accent-blue);
    box-shadow: 0 0 0 2px var(--accent-blue-dim);
}

.btn {
    padding: 9px 16px; border: none; border-radius: var(--radius-md);
    cursor: pointer; font-size: 13px; font-weight: 600;
    font-family: 'Outfit', sans-serif; transition: all 0.2s;
}
.btn-primary { background: var(--accent-blue); color: white; }
.btn-primary:hover { background: #2a7ae4; }
.btn-secondary {
    background: var(--bg-elevated); color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.btn-secondary:hover { color: var(--text-primary); }

/* ==========================================================================
   AUTO-DRAFT BAR
   ========================================================================== */
/* ==========================================================================
   GEAR SETTINGS PANEL
   ========================================================================== */
/* Top bar: selector + gear */
.profile-topbar {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; padding-top: 14px; margin-bottom: 14px;
}

.gear-settings-wrapper {
    position: relative; flex-shrink: 0;
}
.gear-btn {
    width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;
    background: var(--bg-card); border: 1px solid var(--border-color);
    border-radius: var(--radius-md); color: var(--text-secondary);
    font-size: 16px; cursor: pointer; transition: all 0.2s;
    box-shadow: var(--shadow-card);
}
.gear-btn:hover { color: var(--text-primary); border-color: var(--accent-blue); }
.gear-btn.open { color: var(--accent-blue); border-color: var(--accent-blue); background: var(--accent-blue-dim); }
.gear-btn .fa-gear { transition: transform 0.3s ease; }
.gear-btn.open .fa-gear { transform: rotate(90deg); }

.gear-panel {
    display: none; position: absolute; top: 100%; right: 0;
    margin-top: 8px; min-width: 300px; z-index: 50;
    background: var(--bg-card); border: 1px solid var(--border-color);
    border-radius: var(--radius-md); box-shadow: var(--shadow-elevated);
    overflow: hidden;
}
.gear-panel.open { display: block; }

.gear-section {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border-color);
}
.gear-section:last-child { border-bottom: none; }
.gear-section.disabled {
    opacity: 0.4; pointer-events: none;
}

.gear-section-label {
    display: flex; align-items: center; gap: 8px;
    font-size: 0.82rem; font-weight: 600; text-transform: uppercase;
    letter-spacing: 0.04em; color: var(--text-muted);
    margin-bottom: 10px;
}

.gear-section-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; flex-wrap: wrap;
}

/* Theme toggle */
.theme-toggle-group {
    display: flex; gap: 4px;
    background: var(--bg-elevated); border-radius: var(--radius-md);
    padding: 3px; border: 1px solid var(--border-color);
}
.theme-btn {
    padding: 6px 14px; border: none; border-radius: 6px;
    font-size: 0.82rem; font-weight: 600; cursor: pointer;
    font-family: 'Outfit', sans-serif;
    background: transparent; color: var(--text-muted);
    transition: all 0.2s;
}
.theme-btn:hover { color: var(--text-primary); }
.theme-btn.active {
    background: var(--accent-blue); color: white;
    box-shadow: 0 1px 4px rgba(56, 139, 253, 0.3);
}

/* Auto-draft toggle */
.toggle-switch {
    position: relative; display: inline-block;
    width: 48px; height: 26px;
}
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider {
    position: absolute; cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background: #444; transition: 0.3s; border-radius: 26px;
}
.toggle-slider:before {
    position: absolute; content: "";
    height: 20px; width: 20px; left: 3px; bottom: 3px;
    background: white; transition: 0.3s; border-radius: 50%;
}
input:checked + .toggle-slider { background: var(--accent-green); }
input:checked + .toggle-slider:before { transform: translateX(22px); }

.preferences-link {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; background: var(--accent-blue); color: white;
    text-decoration: none; border-radius: var(--radius-md);
    font-size: 0.85rem; font-weight: 500;
    transition: all 0.2s; white-space: nowrap;
}
.preferences-link:hover { background: #2a7ae4; color: white; }

.draft-disabled-note {
    font-size: 0.8rem; color: var(--text-muted); font-style: italic;
}

/* ==========================================================================
   STATS GRID & CARDS
   ========================================================================== */
.stats-grid {
    display: grid; gap: 14px; grid-template-columns: 1fr;
}

.stats-card {
    background: var(--bg-card); border-radius: var(--radius-lg);
    padding: 18px; box-shadow: var(--shadow-card);
    margin-bottom: 14px; position: relative;
}

.section-title {
    font-size: 1.1rem; font-weight: 700;
    margin: 0 0 12px; padding-bottom: 8px;
    border-bottom: 1px solid var(--border-color);
    display: flex; align-items: center; gap: 8px;
}

.team-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 10px 12px; border-bottom: 1px solid var(--border-color);
    background: var(--bg-elevated); border-radius: var(--radius-md);
    margin-bottom: 6px; transition: background var(--transition-fast);
}
.team-row:hover { background: var(--bg-card-hover); }
.team-row:last-child { margin-bottom: 0; }

.team-info {
    display: flex; align-items: center; flex: 1;
    min-width: 0; gap: 8px;
}
.team-info span { color: var(--text-secondary); font-size: 0.9rem; }
.team-logo { width: 28px; height: 28px; object-fit: contain; flex-shrink: 0; }

.team-record {
    font-weight: 600; min-width: 70px; text-align: right;
    font-variant-numeric: tabular-nums;
}
.team-record div { font-size: 0.78rem; color: var(--text-muted); }

.no-data {
    color: var(--text-muted); font-style: italic;
    padding: 20px; text-align: center;
}

/* ==========================================================================
   GAMES LIST
   ========================================================================== */
.games-list { border-radius: var(--radius-md); overflow: hidden; }

.game-list-item {
    padding: 10px 12px; border-bottom: 1px solid var(--border-color);
    display: flex; justify-content: space-between; align-items: center;
    text-decoration: none; color: inherit;
    transition: background var(--transition-fast);
}
.game-list-item.clickable:hover { background: var(--bg-card-hover); }
.game-list-item:last-child { border-bottom: none; }
.game-list-item.win { background: rgba(63, 185, 80, 0.06); }
.game-list-item.loss { background: rgba(248, 81, 73, 0.06); }

.game-list-info { flex: 1; min-width: 0; }
.game-list-date { font-size: 0.8rem; color: var(--text-muted); margin-bottom: 2px; }
.game-list-matchup {
    font-weight: 600; font-size: 0.9rem; color: var(--text-primary);
    display: flex; align-items: center; flex-wrap: wrap; gap: 4px;
}
.game-list-matchup img { width: 18px; height: 18px; vertical-align: middle; }
.game-list-matchup .owner-tag {
    font-size: 0.78rem; color: var(--text-muted); font-weight: 400;
}

.game-list-result { text-align: right; min-width: 65px; }
.game-list-score {
    font-size: 1rem; font-weight: 600; font-variant-numeric: tabular-nums;
}
.game-list-outcome { font-size: 0.85rem; font-weight: 700; }

/* ==========================================================================
   WIDGET PIN ICON
   ========================================================================== */
.widget-pin-icon {
    position: absolute; top: 12px; right: 12px;
    background: transparent; color: var(--text-muted);
    border: none; border-radius: 4px;
    width: 28px; height: 28px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 13px;
    transition: all 0.2s; z-index: 10; opacity: 0.5;
}
.widget-pin-icon:hover {
    opacity: 1; color: var(--accent-blue);
    background: var(--accent-blue-dim);
}
.widget-pin-icon.pinned { color: var(--accent-green); opacity: 0.7; }
.widget-pin-icon.pinned:hover { opacity: 1; background: rgba(63, 185, 80, 0.1); }

/* ==========================================================================
   RIVALS SECTION
   ========================================================================== */
.rivals-section {
    margin-top: 16px; padding-top: 14px;
    border-top: 1px solid var(--border-color);
}
.rivals-title {
    margin: 0 0 10px; font-size: 1rem; font-weight: 600;
    color: var(--text-primary);
    display: flex; align-items: center; gap: 8px;
}
.rival-link {
    text-decoration: none; color: var(--accent-blue);
    font-weight: 600; transition: color 0.2s;
}
.rival-link:hover { color: #5ba3fd; }

/* ==========================================================================
   PHOTO OPTIONS MODAL
   ========================================================================== */
.photo-options-modal {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.7); z-index: 2000;
    display: none; align-items: center; justify-content: center;
}
.photo-options-content {
    background: var(--bg-card); border-radius: var(--radius-lg);
    padding: 25px; max-width: 380px; width: 90%;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
    text-align: center; border: 1px solid var(--border-color);
}
.photo-options-content h3 { margin: 0 0 15px; color: var(--text-primary); }
.photo-preview {
    width: 80px; height: 80px; border-radius: 50%;
    object-fit: cover; margin: 0 auto 15px;
    border: 3px solid var(--bg-elevated);
}
.photo-option-btn {
    padding: 10px 18px; border: 1px solid var(--border-color);
    border-radius: var(--radius-md); background: var(--bg-elevated);
    color: var(--text-primary); cursor: pointer;
    transition: all 0.2s; font-size: 13px;
    font-family: 'Outfit', sans-serif;
    display: flex; align-items: center; justify-content: center;
    gap: 8px; width: 100%;
}
.photo-option-btn:hover { background: var(--bg-card-hover); }
.photo-option-btn.primary {
    background: var(--accent-blue); color: white;
    border-color: var(--accent-blue);
}
.photo-option-btn.danger {
    background: var(--accent-red); color: white;
    border-color: var(--accent-red);
}
.photo-options-buttons { display: flex; flex-direction: column; gap: 10px; }

/* ==========================================================================
   MOBILE RESPONSIVE
   ========================================================================== */
@media (max-width: 600px) {
    .app-container { padding: 0 8px 2rem; }
    .profile-header { padding: 1.5rem 1rem; min-height: 150px; }
    .profile-name { font-size: 1.5rem; }
    .profile-record { font-size: 1.2rem; }
    .stats-grid { grid-template-columns: 1fr; }
    .team-row { padding: 8px 10px; }
    .team-row .team-info span,
    .team-row .team-info a { font-size: 0.82rem; }
    .team-record { font-size: 0.9rem; min-width: 55px; }

    .game-list-item {
        flex-direction: column; align-items: flex-start; gap: 6px;
    }
    .game-list-result {
        width: 100%; display: flex; justify-content: space-between;
        padding-top: 6px; border-top: 1px solid var(--border-color);
    }
    .form-row { flex-direction: column; }
}

@media (min-width: 601px) {
    .app-container { padding: 0 20px 2rem; }
}

@media (min-width: 768px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}
    /* ===== FLOATING PILL NAV ===== */
    .floating-pill { position: fixed; bottom: 12px; left: 50%; z-index: 9999; display: flex; align-items: center; gap: 2px; background: rgba(32, 42, 56, 0.95); border: 1px solid var(--border-color); border-radius: 999px; padding: 5px; box-shadow: 0 4px 24px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.04); -webkit-backdrop-filter: blur(16px); backdrop-filter: blur(16px); -webkit-transform: translateX(-50%) translateZ(0); transform: translateX(-50%) translateZ(0); will-change: transform; }
    body { padding-bottom: 76px; }
    @media (max-width: 600px) { .floating-pill { bottom: calc(8px + env(safe-area-inset-bottom, 0px)); } }
    .pill-item { display: flex; align-items: center; justify-content: center; width: 42px; height: 42px; border-radius: 999px; text-decoration: none; color: var(--text-muted); font-size: 16px; transition: all 0.15s ease; cursor: pointer; border: none; background: none; -webkit-tap-highlight-color: transparent; position: relative; }
    .pill-item:hover { color: var(--text-primary); background: var(--bg-elevated); }
    .pill-item.active { color: white; background: var(--accent-blue); }
    .pill-item:active { transform: scale(0.92); }
    .pill-divider { width: 1px; height: 24px; background: var(--border-color); flex-shrink: 0; }
    @media (min-width: 601px) { .pill-item::after { content: attr(data-label); position: absolute; bottom: calc(100% + 8px); left: 50%; transform: translateX(-50%) scale(0.9); background: var(--bg-elevated); color: var(--text-primary); font-size: 11px; font-weight: 600; font-family: 'Outfit', sans-serif; padding: 4px 10px; border-radius: 6px; white-space: nowrap; opacity: 0; pointer-events: none; transition: all 0.15s ease; border: 1px solid var(--border-color); } .pill-item:hover::after { opacity: 1; transform: translateX(-50%) scale(1); } }
</style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/components/navigation_menu_new.php'; ?>

<div class="app-container">

    <!-- ================================================================
         HEADER
         ================================================================ -->


    <!-- ================================================================
         ALERTS
         ================================================================ -->
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <!-- ================================================================
         OWN PROFILE: EDIT FORMS & AUTO-DRAFT
         ================================================================ -->
    <?php if ($is_own_profile): ?>

        <!-- Edit Display Name (hidden by default) -->
        <div class="edit-section hidden" id="editForm">
            <h3>Edit Display Name</h3>
            <form method="POST">
                <input type="hidden" name="action" value="update_display_name">
                <div class="form-row">
                    <div class="form-group">
                        <label for="display_name">Display Name</label>
                        <input type="text" id="display_name" name="display_name"
                               value="<?= htmlspecialchars($participant['display_name']) ?>"
                               class="form-control" maxlength="20" required>
                        <small id="charCount" style="color: var(--text-muted); font-size: 0.8em">0/20</small>
                    </div>
                    <div style="display: flex; gap: 8px">
                        <button type="submit" class="btn btn-primary">Update</button>
                        <button type="button" class="btn btn-secondary" onclick="toggleEditForm()">Cancel</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Hidden photo forms -->
        <form method="POST" enctype="multipart/form-data" id="photoUploadForm" style="display: none">
            <input type="hidden" name="action" value="upload_photo">
            <input type="file" id="profile_photo" name="profile_photo"
                   accept="image/jpeg,image/png,image/gif,image/webp"
                   onchange="previewAndUpload(this)">
        </form>
        <form method="POST" id="deletePhotoForm" style="display: none">
            <input type="hidden" name="action" value="delete_photo">
        </form>

    <?php endif; ?>

    <!-- ================================================================
         TOP BAR: SELECTOR + GEAR
         ================================================================ -->
    <div class="profile-topbar">
        <select class="participant-select" onchange="window.location.href='?league_id=<?= $league_id ?>&user_id='+this.value">
            <?php foreach ($all_participants as $p): ?>
                <option value="<?= $p['user_id'] ?>" <?= $p['user_id'] == $user_id ? 'selected' : '' ?>>
                    <?= htmlspecialchars($p['display_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if ($is_own_profile): ?>
        <div class="gear-settings-wrapper">
            <button type="button" class="gear-btn" id="gearBtn" onclick="toggleGearPanel()">
                <i class="fas fa-gear"></i>
            </button>

            <div class="gear-panel" id="gearPanel">

                <!-- Theme Section -->
                <div class="gear-section">
                    <div class="gear-section-label">
                        <i class="fas fa-palette"></i> Theme
                    </div>
                    <div class="gear-section-row">
                        <div class="theme-toggle-group">
                            <button type="button" class="theme-btn <?= $current_theme === 'dark' ? 'active' : '' ?>"
                                    onclick="setTheme('dark')">
                                <i class="fas fa-moon" style="margin-right: 4px"></i> Dark
                            </button>
                            <button type="button" class="theme-btn <?= $current_theme === 'classic' ? 'active' : '' ?>"
                                    onclick="setTheme('classic')">
                                <i class="fas fa-sun" style="margin-right: 4px"></i> Light
                            </button>
                        </div>
                    </div>
                    <form method="POST" id="themeForm" style="display:none">
                        <input type="hidden" name="action" value="toggle_theme">
                        <input type="hidden" name="theme" id="themeInput" value="">
                    </form>
                </div>

                <!-- Draft Preferences Section -->
                <div class="gear-section<?= $draft_completed ? ' disabled' : '' ?>">
                    <div class="gear-section-label">
                        <i class="fas fa-basketball"></i> Draft
                    </div>
                    <?php if (!$draft_completed): ?>
                        <div class="gear-section-row">
                            <form method="POST" id="autoDraftForm" style="display: flex; align-items: center; gap: 10px; margin: 0">
                                <input type="hidden" name="action" value="toggle_auto_draft">
                                <label class="toggle-switch" style="margin: 0">
                                    <input type="checkbox" name="auto_draft_enabled" id="autoDraftToggle"
                                           <?= $participant['auto_draft_enabled'] ? 'checked' : '' ?>
                                           onchange="confirmAutoDraftToggle(this)">
                                    <span class="toggle-slider"></span>
                                </label>
                                <label for="autoDraftToggle" style="cursor: pointer; user-select: none; font-size: 0.9rem; color: var(--text-secondary)">
                                    <strong style="color: var(--text-primary)">Auto-Draft:</strong>
                                    <span style="color: <?= $participant['auto_draft_enabled'] ? 'var(--accent-green)' : 'var(--text-muted)' ?>">
                                        <?= $participant['auto_draft_enabled'] ? 'On' : 'Off' ?>
                                    </span>
                                </label>
                            </form>

                            <a href="/nba-wins-platform/profiles/draft_preferences.php?league_id=<?= $league_id ?>&user_id=<?= $user_id ?>"
                               class="preferences-link">
                                <i class="fas fa-list-ol"></i> Team Rankings
                                <?php
                                $stmt = $pdo->prepare("SELECT COUNT(*) AS count FROM user_draft_preferences WHERE user_id = ?");
                                $stmt->execute([$user_id]);
                                $pc = $stmt->fetch()['count'];
                                if ($pc == 30) {
                                    echo '<span style="background:rgba(0,0,0,0.15);padding:1px 6px;border-radius:10px;font-size:0.8rem">✓</span>';
                                } elseif ($pc > 0) {
                                    echo '<span style="background:rgba(0,0,0,0.15);padding:1px 6px;border-radius:10px;font-size:0.8rem">' . $pc . '/30</span>';
                                }
                                ?>
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="gear-section-row">
                            <span class="draft-disabled-note"><i class="fas fa-check-circle" style="margin-right: 4px"></i>Draft complete</span>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ================================================================
         PROFILE HEADER CARD
         ================================================================ -->
    <div class="profile-header">
        <!-- Background team logos -->
        <div class="logo-background">
            <?php foreach ($teams as $team): ?>
                <img src="<?= htmlspecialchars(getTeamLogo($team['team_name'])) ?>" alt=""
                     class="header-logo" onerror="this.style.display='none'">
            <?php endforeach; ?>
        </div>

        <div class="profile-content">
            <div style="display: flex; align-items: center; justify-content: center; gap: 18px; flex-wrap: wrap">
                <!-- Profile photo -->
                <div class="profile-photo-container">
                    <img src="<?= htmlspecialchars($profile_photo_url) ?>" alt=""
                         class="header-profile-photo"
                         onerror="this.src='../public/assets/profile_photos/default.png'">
                    <?php if ($is_own_profile): ?>
                        <div class="photo-edit-overlay">
                            <button type="button" class="photo-edit-btn" onclick="showPhotoOptions()" title="Edit Photo">
                                <i class="fas fa-camera"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Name & record -->
                <div>
                    <h1 class="profile-name">
                        <?= htmlspecialchars($participant['display_name']) ?>
                        <?php if ($is_own_profile): ?>
                            <button type="button" class="edit-name-btn" onclick="toggleEditForm()" title="Edit name">
                                <i class="fas fa-edit"></i>
                            </button>
                        <?php endif; ?>
                    </h1>

                    <?php if (!empty($participant['participant_name']) && $participant['participant_name'] !== $participant['display_name']): ?>
                        <div style="font-size: 0.9rem; opacity: 0.5; font-style: italic; margin-top: 2px">
                            <?= htmlspecialchars($participant['participant_name']) ?>
                        </div>
                    <?php endif; ?>

                    <div class="profile-record">
                        <?= $total_wins ?>-<?= $total_losses ?>
                        <?php if ($total_wins + $total_losses > 0): ?>
                            <span class="pct">
                                (<?= number_format(($total_wins / ($total_wins + $total_losses)) * 100, 1) ?>%)
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <p class="profile-league"><?= htmlspecialchars($pp_league['display_name']) ?></p>
        </div>
    </div>

    <!-- ================================================================
         TWO-COLUMN STATS GRID
         ================================================================ -->
    <div class="stats-grid">

        <!-- Teams Card -->
        <div class="stats-card">
            <h2 class="section-title">Teams (<?= count($teams) ?>)</h2>
            <?php if (empty($teams)): ?>
                <div class="no-data">No teams drafted yet</div>
            <?php else: ?>
                <?php foreach ($teams as $team): ?>
                    <div class="team-row">
                        <div class="team-info">
                            <img src="<?= htmlspecialchars(getTeamLogo($team['team_name'])) ?>" alt=""
                                 class="team-logo" onerror="this.style.opacity='0.3'">
                            <a href="/nba-wins-platform/stats/team_data_new.php?team=<?= urlencode($team['team_name']) ?>"
                               style="text-decoration: none; color: var(--text-primary); font-weight: 500; transition: color 0.2s"
                               onmouseover="this.style.color='var(--accent-blue)'"
                               onmouseout="this.style.color='var(--text-primary)'">
                                <?= htmlspecialchars($team['team_name']) ?>
                            </a>
                        </div>
                        <div class="team-record">
                            <?= $team['wins'] ?>-<?= $team['losses'] ?>
                            <?php if ($team['games_played'] > 0): ?>
                                <div><?= $team['win_percentage'] ?>%</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- League Stats Card -->
        <div class="stats-card" data-widget-type="league_stats">
            <?php if ($is_own_profile): ?>
                <button class="widget-pin-icon <?= in_array('league_stats', $pinned_widgets) ? 'pinned' : '' ?>"
                        onclick="toggleWidgetPin('league_stats', this)"
                        title="<?= in_array('league_stats', $pinned_widgets) ? 'Unpin' : 'Pin to homepage' ?>">
                    <i class="fas fa-<?= in_array('league_stats', $pinned_widgets) ? 'check' : 'thumbtack' ?>"></i>
                </button>
            <?php endif; ?>

            <h2 class="section-title">League Stats</h2>

            <div class="team-row">
                <div class="team-info"><span>Total Games</span></div>
                <div class="team-record"><?= array_sum(array_column($teams, 'games_played')) ?></div>
            </div>

            <div class="team-row">
                <div class="team-info"><span>Avg Record</span></div>
                <div class="team-record">
                    <?php
                    $ac = count($teams);
                    echo $ac > 0
                        ? round($total_wins / $ac, 1) . '-' . round($total_losses / $ac, 1)
                        : '0-0';
                    ?>
                </div>
            </div>

            <div class="team-row">
                <div class="team-info"><span>Best Team</span></div>
                <div class="team-record">
                    <?php
                    $bt = null;
                    $bw = -1;
                    $bp = -1;
                    foreach ($teams as $t) {
                        if ($t['wins'] > $bw) {
                            $bw = $t['wins'];
                            $bp = $t['win_percentage'];
                            $bt = $t;
                        } elseif ($t['wins'] == $bw && $t['win_percentage'] > $bp) {
                            $bp = $t['win_percentage'];
                            $bt = $t;
                        }
                    }
                    echo $bt
                        ? htmlspecialchars($bt['team_name']) . ' (' . $bt['wins'] . '-' . $bt['losses'] . ')'
                        : 'N/A';
                    ?>
                </div>
            </div>

            <!-- Rivals sub-section -->
            <div class="rivals-section">
                <h3 class="rivals-title">
                    <i class="fas fa-trophy" style="color: var(--accent-orange)"></i> Rivals
                </h3>

                <?php if ($biggest_rival): ?>
                    <div class="team-row">
                        <div class="team-info">
                            <i class="fas fa-fire" style="color: var(--accent-red); margin-right: 4px; font-size: 0.85rem"></i>
                            <span>Most Wins vs</span>
                        </div>
                        <div class="team-record">
                            <a href="?league_id=<?= $league_id ?>&user_id=<?= $biggest_rival['opponent_user_id'] ?>"
                               class="rival-link"><?= htmlspecialchars($biggest_rival['opponent_name']) ?></a>
                            <div style="color: var(--accent-green)">
                                <?= $biggest_rival['wins_against_opponent'] ?>-<?= $biggest_rival['losses_against_opponent'] ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($nemesis): ?>
                    <div class="team-row">
                        <div class="team-info">
                            <i class="fas fa-skull-crossbones" style="color: var(--accent-red); margin-right: 4px; font-size: 0.85rem"></i>
                            <span>Most Losses vs</span>
                        </div>
                        <div class="team-record">
                            <a href="?league_id=<?= $league_id ?>&user_id=<?= $nemesis['opponent_user_id'] ?>"
                               class="rival-link"><?= htmlspecialchars($nemesis['opponent_name']) ?></a>
                            <div style="color: var(--accent-red)">
                                <?= $nemesis['wins_against_opponent'] ?>-<?= $nemesis['losses_against_opponent'] ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!$biggest_rival && !$nemesis): ?>
                    <div class="no-data">
                        <i class="fas fa-handshake"></i> No head-to-head games yet
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /.stats-grid -->

    <!-- ================================================================
         LAST 10 GAMES
         ================================================================ -->
    <div class="stats-card" data-widget-type="last_10_games">
        <?php if ($is_own_profile): ?>
            <button class="widget-pin-icon <?= in_array('last_10_games', $pinned_widgets) ? 'pinned' : '' ?>"
                    onclick="toggleWidgetPin('last_10_games', this)">
                <i class="fas fa-<?= in_array('last_10_games', $pinned_widgets) ? 'check' : 'thumbtack' ?>"></i>
            </button>
        <?php endif; ?>

        <?php
        $l10w = 0;
        $l10l = 0;
        foreach ($lastGames as $g) {
            if ($g['result'] === 'W') $l10w++;
            elseif ($g['result'] === 'L') $l10l++;
        }
        ?>
        <h2 class="section-title">
            <i class="fas fa-history"></i> Last 10 Games
            <?php if (!empty($lastGames)): ?>
                <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: 400; margin-left: 6px">
                    (<?= $l10w ?>-<?= $l10l ?>)
                </span>
            <?php endif; ?>
        </h2>

        <?php if (!empty($lastGames)): ?>
            <div class="games-list">
                <?php foreach (array_reverse($lastGames) as $game):
                    $ts = ($game['team_location'] === 'home') ? $game['home_points'] : $game['away_points'];
                    $os = ($game['team_location'] === 'home') ? $game['away_points'] : $game['home_points'];
                    $gu = "/nba-wins-platform/stats/game_details_new.php"
                        . "?home_team=" . urlencode($game['home_team_code'])
                        . "&away_team=" . urlencode($game['away_team_code'])
                        . "&date=" . urlencode($game['game_date']);
                ?>
                    <a href="<?= $gu ?>" class="game-list-item clickable <?= strtolower($game['result']) ?>">
                        <div class="game-list-info">
                            <div class="game-list-date"><?= date('M j, Y', strtotime($game['game_date'])) ?></div>
                            <div class="game-list-matchup">
                                <img src="<?= htmlspecialchars(getTeamLogo($game['my_team'])) ?>" alt=""
                                     onerror="this.style.display='none'">
                                <?= htmlspecialchars($game['my_team']) ?>
                                <?= $game['team_location'] === 'home' ? 'vs' : '@' ?>
                                <img src="<?= htmlspecialchars(getTeamLogo($game['opponent'])) ?>" alt=""
                                     onerror="this.style.display='none'">
                                <?= htmlspecialchars($game['opponent']) ?>
                                <?php if (!empty($game['opponent_owner'])): ?>
                                    <span class="owner-tag">(<?= htmlspecialchars($game['opponent_owner']) ?>)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="game-list-result">
                            <div class="game-list-score"><?= $ts . '-' . $os ?></div>
                            <div class="game-list-outcome"
                                 style="color: <?= $game['result'] === 'W' ? 'var(--accent-green)' : 'var(--accent-red)' ?>">
                                <?= $game['result'] ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="no-data">No recent games</div>
        <?php endif; ?>
    </div>

    <!-- ================================================================
         UPCOMING 5 GAMES
         ================================================================ -->
    <div class="stats-card" data-widget-type="upcoming_games">
        <?php if ($is_own_profile): ?>
            <button class="widget-pin-icon <?= in_array('upcoming_games', $pinned_widgets) ? 'pinned' : '' ?>"
                    onclick="toggleWidgetPin('upcoming_games', this)">
                <i class="fas fa-<?= in_array('upcoming_games', $pinned_widgets) ? 'check' : 'thumbtack' ?>"></i>
            </button>
        <?php endif; ?>

        <h2 class="section-title"><i class="fas fa-calendar-alt"></i> Next 5 Games</h2>

        <?php if (!empty($upcomingGames)): ?>
            <div class="games-list">
                <?php foreach ($upcomingGames as $game):
                    $cu = "/nba-wins-platform/stats/team_comparison_new.php"
                        . "?home_team=" . urlencode($game['home_team_code'])
                        . "&away_team=" . urlencode($game['away_team_code'])
                        . "&date=" . urlencode($game['game_date']);
                ?>
                    <a href="<?= $cu ?>" class="game-list-item clickable">
                        <div class="game-list-info">
                            <div class="game-list-date"><?= date('M j, Y', strtotime($game['game_date'])) ?></div>
                            <div class="game-list-matchup">
                                <img src="<?= htmlspecialchars(getTeamLogo($game['my_team'])) ?>" alt=""
                                     onerror="this.style.display='none'">
                                <?= htmlspecialchars($game['my_team']) ?>
                                <?= $game['team_location'] === 'home' ? 'vs' : '@' ?>
                                <img src="<?= htmlspecialchars(getTeamLogo($game['opponent'])) ?>" alt=""
                                     onerror="this.style.display='none'">
                                <?= htmlspecialchars($game['opponent']) ?>
                                <?php if (!empty($game['opponent_owner'])): ?>
                                    <span class="owner-tag">(<?= htmlspecialchars($game['opponent_owner']) ?>)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="no-data">No upcoming games</div>
        <?php endif; ?>
    </div>

</div><!-- /.app-container -->


<!-- ====================================================================
     PHOTO OPTIONS MODAL
     ==================================================================== -->
<?php if ($is_own_profile): ?>
    <div id="photoOptionsModal" class="photo-options-modal">
        <div class="photo-options-content">
            <h3>Profile Photo</h3>
            <img src="<?= htmlspecialchars($profile_photo_url) ?>" alt=""
                 class="photo-preview"
                 onerror="this.src='../public/assets/profile_photos/default.png'">
            <div class="photo-options-buttons">
                <button type="button" class="photo-option-btn primary" onclick="triggerPhotoUpload()">
                    <i class="fas fa-camera"></i> Upload New Photo
                </button>
                <?php if ($participant['profile_photo']): ?>
                    <button type="button" class="photo-option-btn danger" onclick="deletePhoto()">
                        <i class="fas fa-trash"></i> Delete Photo
                    </button>
                <?php endif; ?>
                <button type="button" class="photo-option-btn" onclick="closePhotoOptions()">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
            <div style="margin-top: 12px; font-size: 0.75rem; color: var(--text-muted)">
                JPEG, PNG, GIF, WebP – Max 5MB
            </div>
        </div>
    </div>
<?php endif; ?>


<!-- ====================================================================
     JAVASCRIPT
     ==================================================================== -->
<script>
// --- Edit Name Form ---
function toggleEditForm() {
    const f = document.getElementById('editForm');
    if (f.classList.contains('hidden')) {
        f.classList.remove('hidden');
        f.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => {
            const i = document.getElementById('display_name');
            if (i) { i.focus(); i.select(); }
        }, 300);
    } else {
        f.classList.add('hidden');
    }
}

// --- Photo Options Modal ---
function showPhotoOptions() {
    const m = document.getElementById('photoOptionsModal');
    if (m) { m.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
}

function closePhotoOptions() {
    const m = document.getElementById('photoOptionsModal');
    if (m) { m.style.display = 'none'; document.body.style.overflow = 'auto'; }
}

function triggerPhotoUpload() {
    document.getElementById('profile_photo')?.click();
}

function deletePhoto() {
    if (confirm('Delete your profile photo?')) {
        document.getElementById('deletePhotoForm')?.submit();
    }
}

function previewAndUpload(input) {
    if (input.files && input.files[0]) {
        const f = input.files[0];
        if (f.size > 5 * 1024 * 1024) {
            alert('File too large. Max 5MB.');
            input.value = '';
            return;
        }
        if (!['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(f.type)) {
            alert('Invalid file type.');
            input.value = '';
            return;
        }
        closePhotoOptions();
        document.getElementById('photoUploadForm').submit();
    }
}

// --- Auto-Draft Toggle ---
function confirmAutoDraftToggle(cb) {
    const form = document.getElementById('autoDraftForm');
    if (cb.checked) {
        const hasPreferences = <?php
            $stmt = $pdo->prepare("SELECT COUNT(*) AS count FROM user_draft_preferences WHERE user_id = ?");
            $stmt->execute([$user_id]);
            echo $stmt->fetch()['count'] == 30 ? 'true' : 'false';
        ?>;
        if (!hasPreferences) {
            if (confirm('Set team rankings first?\n\nWithout rankings, random teams will be selected.')) {
                window.location.href = '/nba-wins-platform/profiles/draft_preferences.php?league_id=<?= $league_id ?>&user_id=<?= $user_id ?>';
                return;
            }
        }
        form.submit();
    } else {
        form.submit();
    }
}

// --- Gear Panel Toggle ---
function toggleGearPanel() {
    const btn = document.getElementById('gearBtn');
    const panel = document.getElementById('gearPanel');
    btn.classList.toggle('open');
    panel.classList.toggle('open');
}
// Close gear panel on click outside
document.addEventListener('click', function(e) {
    const wrapper = document.querySelector('.gear-settings-wrapper');
    if (wrapper && !wrapper.contains(e.target)) {
        document.getElementById('gearBtn')?.classList.remove('open');
        document.getElementById('gearPanel')?.classList.remove('open');
    }
});

// --- Theme Toggle ---
function setTheme(theme) {
    document.getElementById('themeInput').value = theme;
    document.getElementById('themeForm').submit();
}

// --- Character Counter & Modal Click-Outside ---
document.addEventListener('DOMContentLoaded', function () {
    const di = document.getElementById('display_name');
    const cc = document.getElementById('charCount');

    if (di && cc) {
        function updateCount() {
            cc.textContent = di.value.length + '/20';
            cc.style.color = di.value.length > 20 ? 'var(--accent-red)' : 'var(--text-muted)';
        }
        di.addEventListener('input', updateCount);
        updateCount();
    }

    // Close photo modal on background click
    const m = document.getElementById('photoOptionsModal');
    if (m) {
        m.addEventListener('click', function (e) {
            if (e.target === m) closePhotoOptions();
        });
    }
});

// Hide edit form after successful update
<?php if (!empty($success_message)): ?>
document.addEventListener('DOMContentLoaded', function () {
    const f = document.getElementById('editForm');
    if (f) f.classList.add('hidden');
});
<?php endif; ?>

// --- Widget Pin Toggle ---
function toggleWidgetPin(w, b) {
    const p = b.classList.contains('pinned');
    if (!p && !confirm('Pin to homepage?')) return;

    const fd = new FormData();
    fd.append('action', p ? 'unpin' : 'pin');
    fd.append('widget_type', w);

    fetch('/nba-wins-platform/core/handle_widget_pin.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert(d.message);
            window.location.reload();
        } else {
            alert('Error: ' + d.error);
        }
    })
    .catch(() => alert('Error. Try again.'));
}
</script>
    <nav class="floating-pill">
        <a href="/index_new.php" class="pill-item" data-label="Home"><i class="fas fa-home"></i></a>
        <a href="/nba-wins-platform/profiles/participant_profile_new.php?league_id=<?php echo $currentLeagueId ?? ($_SESSION['current_league_id'] ?? 0); ?>&user_id=<?php echo $profileUserId ?? ($_SESSION['user_id'] ?? 0); ?>" class="pill-item active" data-label="Profile"><i class="fas fa-user"></i></a>
        <a href="/analytics_new.php" class="pill-item" data-label="Analytics"><i class="fas fa-chart-line"></i></a>
        <a href="/claudes-column_new.php" class="pill-item" data-label="Column" style="position:relative"><i class="fa-solid fa-newspaper"></i><?php if ($hasNewArticles): ?><span style="position:absolute;top:2px;right:2px;width:7px;height:7px;background:#f85149;border-radius:50%;box-shadow:0 0 4px rgba(248,81,73,0.5)"></span><?php endif; ?></a>
        <div class="pill-divider"></div>
        <button class="pill-item" data-label="Menu" onclick="toggleDarkNav()"><i class="fas fa-bars"></i></button>
    </nav>
</body>
</html>