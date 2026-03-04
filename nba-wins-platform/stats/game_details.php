<?php
/**
 * game_details.php - Game Box Score Page
 * 
 * Displays a single game's details including:
 *   - Matchup header with team logos, scores, participant owners
 *   - Quarter-by-quarter scoring (with overtime support)
 *   - Player statistics table per team
 *   - Inactive players list
 *   - Live score updates via NBA API for today's games
 * 
 * Path: /data/www/default/nba-wins-platform/stats/game_details.php
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('America/New_York');


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
        return '/nba-wins-platform/public/assets/team_logos/' . $logoMap[$teamName];
    }

    return '/nba-wins-platform/public/assets/team_logos/' . strtolower(str_replace(' ', '_', $teamName)) . '.png';
}

/**
 * Format NBA API gameClock (ISO 8601 duration) to readable time
 * Converts PT05M30.00S → 5:30, PT00M45.60S → 0:45, already formatted → pass through
 */
function formatGameClock($clock) {
    if (empty($clock)) return '';
    
    // Already formatted (e.g., "5:30" or "0:45") — pass through
    if (preg_match('/^\d{1,2}:\d{2}$/', trim($clock))) {
        return trim($clock);
    }
    
    // ISO 8601 duration: PT05M30.00S or PT5M30.0S
    if (preg_match('/^PT(\d+)M([\d.]+)S$/i', trim($clock), $m)) {
        $minutes = intval($m[1]);
        $seconds = intval(floor(floatval($m[2])));
        return $minutes . ':' . str_pad($seconds, 2, '0', STR_PAD_LEFT);
    }
    
    // Fallback: strip PT prefix and S suffix for any other format
    $cleaned = preg_replace('/^PT/i', '', trim($clock));
    $cleaned = preg_replace('/S$/i', '', $cleaned);
    if (preg_match('/(\d+)M([\d.]+)/', $cleaned, $m)) {
        return intval($m[1]) . ':' . str_pad(intval(floor(floatval($m[2]))), 2, '0', STR_PAD_LEFT);
    }
    
    return trim($clock); // Return as-is if no pattern matches
}

/**
 * Fetch live scores from the NBA API via Python script
 */
function getAPIScores() {
    try {
        $command = "python3 /data/www/default/nba-wins-platform/tasks/get_games.py 2>&1";
        $output  = shell_exec($command);

        if (!$output) return ['scoreboard' => ['games' => []]];

        $data = json_decode($output, true);
        if (!$data || !isset($data['scoreboard'])) {
            return ['scoreboard' => ['games' => []]];
        }

        return $data;
    } catch (Exception $e) {
        return ['scoreboard' => ['games' => []]];
    }
}

/**
 * Merge live API scores with database game data
 */
function getLatestGameScores($games, $api_scores) {
    $latest_scores = [];

    if (!isset($api_scores['scoreboard']['games'])) return $latest_scores;

    foreach ($games as $game) {
        $game_key = $game['home_team'] . ' vs ' . $game['away_team'];

        // Default: use database values
        $latest_scores[$game_key] = [
            'home_points' => $game['home_points'] ?? 0,
            'away_points' => $game['away_points'] ?? 0,
            'status'      => $game['status_long'] ?? 'Scheduled',
            'source'      => 'database'
        ];

        // Override with live API data if available
        foreach ($api_scores['scoreboard']['games'] as $api_game) {
            $api_home = $api_game['homeTeam']['teamCity'] . ' ' . $api_game['homeTeam']['teamName'];
            $api_away = $api_game['awayTeam']['teamCity'] . ' ' . $api_game['awayTeam']['teamName'];

            if ($api_home === $game['home_team'] && $api_away === $game['away_team']) {
                $status = 'Scheduled';
                $formattedClock = formatGameClock($api_game['gameClock'] ?? '');
                
                if ($api_game['gameStatus'] == 1) {
                    $status = 'Scheduled';
                } elseif ($api_game['gameStatus'] == 2) {
                    // Match index page format: "5:30 Q2"
                    if (!empty($formattedClock)) {
                        $status = $formattedClock . ' Q' . $api_game['period'];
                    } else {
                        $status = 'Q' . $api_game['period'];
                    }
                } elseif ($api_game['gameStatus'] == 3) {
                    $status = 'Final';
                }

                $latest_scores[$game_key] = [
                    'home_points' => $api_game['homeTeam']['score'] ?? 0,
                    'away_points' => $api_game['awayTeam']['score'] ?? 0,
                    'status'      => $status,
                    'source'      => 'api',
                    'game_status' => $api_game['gameStatus'],
                    'period'      => $api_game['period'] ?? 0,
                    'clock'       => $formattedClock
                ];
                break;
            }
        }
    }

    return $latest_scores;
}

/**
 * Extract quarter-by-quarter scores from the API response
 */
function getQuarterScores($home_team, $away_team, $api_scores) {
    if (!isset($api_scores['scoreboard']['games'])) return [];

    foreach ($api_scores['scoreboard']['games'] as $api_game) {
        $api_home = $api_game['homeTeam']['teamCity'] . ' ' . $api_game['homeTeam']['teamName'];
        $api_away = $api_game['awayTeam']['teamCity'] . ' ' . $api_game['awayTeam']['teamName'];

        if ($api_home === $home_team && $api_away === $away_team) {
            $homeQuarters  = [];
            $awayQuarters  = [];
            $homeOvertimes = [];
            $awayOvertimes = [];

            foreach ($api_game['homeTeam']['periods'] as $p) {
                if ($p['periodType'] === 'REGULAR') {
                    $homeQuarters[] = $p['score'];
                } elseif ($p['periodType'] === 'OVERTIME') {
                    $homeOvertimes[] = $p['score'];
                }
            }
            foreach ($api_game['awayTeam']['periods'] as $p) {
                if ($p['periodType'] === 'REGULAR') {
                    $awayQuarters[] = $p['score'];
                } elseif ($p['periodType'] === 'OVERTIME') {
                    $awayOvertimes[] = $p['score'];
                }
            }

            return [
                'home'            => $homeQuarters,
                'away'            => $awayQuarters,
                'home_overtimes'  => $homeOvertimes,
                'away_overtimes'  => $awayOvertimes,
                'home_total'      => $api_game['homeTeam']['score'] ?? 0,
                'away_total'      => $api_game['awayTeam']['score'] ?? 0
            ];
        }
    }

    return [];
}

/**
 * Format minutes string (e.g. "34:22" → "34:22")
 */
function formatMinutes($minutes) {
    if (!$minutes || $minutes === '-') return '-';

    $parts = explode(':', $minutes);
    if (count($parts) === 2) {
        $m = floatval($parts[0]);
        $s = intval($parts[1]);
        return sprintf("%02d:%02d", floor($m), $s);
    }

    return '-';
}

/**
 * Normalize team name (Clippers variant)
 */
function normalizeTeamName($teamName) {
    return $teamName === 'Los Angeles Clippers' ? 'LA Clippers' : $teamName;
}


// ==========================================================================
// DEPENDENCIES & VALIDATION
// ==========================================================================
require_once '/data/www/default/nba-wins-platform/config/db_connection.php';
require_once '/data/www/default/nba-wins-platform/config/season_config.php';
$season = getSeasonConfig();

$league_id = $_SESSION['current_league_id'] ?? null;
if (!$league_id) die("No league selected.");

$home_team = $_GET['home_team'] ?? null;
$away_team = $_GET['away_team'] ?? null;
$date      = $_GET['date'] ?? null;

if (!$home_team || !$away_team || !$date) die("Game information not provided");
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) die("Invalid date format");
if (!preg_match('/^[A-Z]{3}$/', $home_team) || !preg_match('/^[A-Z]{3}$/', $away_team)) die("Invalid team code");


// ==========================================================================
// DATA QUERIES
// ==========================================================================

// ------ Game Info + Participant Owners ------
$stmt = $pdo->prepare("
    SELECT 
        g.*,
        nt1.logo_filename AS home_logo,
        nt2.logo_filename AS away_logo,
        (SELECT COALESCE(u1.display_name, lp1.participant_name)
         FROM league_participant_teams lpt1
         JOIN league_participants lp1 ON lpt1.league_participant_id = lp1.id
         LEFT JOIN users u1 ON lp1.user_id = u1.id
         WHERE lpt1.team_name = g.home_team AND lp1.league_id = ?
         LIMIT 1) AS home_participant,
        (SELECT u1b.profile_photo
         FROM league_participant_teams lpt1b
         JOIN league_participants lp1b ON lpt1b.league_participant_id = lp1b.id
         LEFT JOIN users u1b ON lp1b.user_id = u1b.id
         WHERE lpt1b.team_name = g.home_team AND lp1b.league_id = ?
         LIMIT 1) AS home_participant_photo,
        (SELECT COALESCE(u2.display_name, lp2.participant_name)
         FROM league_participant_teams lpt2
         JOIN league_participants lp2 ON lpt2.league_participant_id = lp2.id
         LEFT JOIN users u2 ON lp2.user_id = u2.id
         WHERE lpt2.team_name = g.away_team AND lp2.league_id = ?
         LIMIT 1) AS away_participant,
        (SELECT u2b.profile_photo
         FROM league_participant_teams lpt2b
         JOIN league_participants lp2b ON lpt2b.league_participant_id = lp2b.id
         LEFT JOIN users u2b ON lp2b.user_id = u2b.id
         WHERE lpt2b.team_name = g.away_team AND lp2b.league_id = ?
         LIMIT 1) AS away_participant_photo
    FROM games g
    LEFT JOIN nba_teams nt1 ON g.home_team = nt1.name
    LEFT JOIN nba_teams nt2 ON g.away_team = nt2.name
    WHERE g.date = ? AND g.home_team_code = ? AND g.away_team_code = ?
    ORDER BY g.home_points DESC
    LIMIT 1
");
$stmt->execute([$league_id, $league_id, $league_id, $league_id, $date, $home_team, $away_team]);
$game = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$game) die("Game not found");

// Build owner profile photo URLs
$photoBase = '/nba-wins-platform/public/assets/profile_photos/';
$game['home_photo_url'] = !empty($game['home_participant_photo']) 
    ? $photoBase . $game['home_participant_photo'] 
    : $photoBase . 'default.png';
$game['away_photo_url'] = !empty($game['away_participant_photo']) 
    ? $photoBase . $game['away_participant_photo'] 
    : $photoBase . 'default.png';


// ------ Live API Scores (today's games only) ------
$today      = date('Y-m-d');
$api_scores = ($date === $today) ? getAPIScores() : ['scoreboard' => ['games' => []]];

$games_for_api = [$game];
$latest_scores = getLatestGameScores($games_for_api, $api_scores);

$game_key       = $game['home_team'] . ' vs ' . $game['away_team'];
$current_scores = $latest_scores[$game_key] ?? null;

if ($current_scores) {
    $game['home_points'] = $current_scores['home_points'];
    $game['away_points'] = $current_scores['away_points'];
    if (isset($current_scores['status'])) {
        $game['status_long'] = $current_scores['status'];
    }
    if (isset($current_scores['game_status'])) {
        $game['game_status_code'] = $current_scores['game_status'];
    }
}
$isLiveGame = ($game['game_status_code'] ?? 0) == 2;

// ------ Latest Play-by-Play (live games only) ------
$latestPlay = null;
if ($isLiveGame) {
    // Find game ID from the scoreboard data we already fetched
    $nba_game_id = null;
    if (isset($api_scores['scoreboard']['games'])) {
        foreach ($api_scores['scoreboard']['games'] as $ag) {
            $agHome = $ag['homeTeam']['teamCity'] . ' ' . $ag['homeTeam']['teamName'];
            $agAway = $ag['awayTeam']['teamCity'] . ' ' . $ag['awayTeam']['teamName'];
            if ($agHome === $game['home_team'] && $agAway === $game['away_team']) {
                $nba_game_id = $ag['gameId'] ?? null;
                break;
            }
        }
    }
    if ($nba_game_id) {
        try {
            $pbp_script = '/data/www/default/nba-wins-platform/core/get_playbyplay.py';
            $pbp_cmd = "timeout 8 python3 " . $pbp_script . " " . escapeshellarg($nba_game_id) . " 2>&1";
            $pbp_output = shell_exec($pbp_cmd);
            if ($pbp_output) {
                $pbp_data = json_decode($pbp_output, true);
                if ($pbp_data && !isset($pbp_data['error']) && !empty($pbp_data['play'])) {
                    $latestPlay = $pbp_data['play'];
                }
            }
        } catch (Exception $e) {
            error_log("Play-by-play fetch error: " . $e->getMessage());
        }
    }
}

// ------ Score Fallback: Quarter Scores Table ------
if (empty($game['home_points']) && empty($game['away_points'])) {
    $qsFallback = $pdo->prepare("
        SELECT team_abbrev,
               COALESCE(q1_points, 0) + COALESCE(q2_points, 0) + 
               COALESCE(q3_points, 0) + COALESCE(q4_points, 0) AS total
        FROM game_quarter_scores
        WHERE game_date = ? AND team_abbrev IN (?, ?)
    ");
    $qsFallback->execute([$date, $home_team, $away_team]);

    foreach ($qsFallback->fetchAll(PDO::FETCH_ASSOC) as $qs) {
        if ($qs['team_abbrev'] === $home_team && $qs['total'] > 0) {
            $game['home_points'] = $qs['total'];
        }
        if ($qs['team_abbrev'] === $away_team && $qs['total'] > 0) {
            $game['away_points'] = $qs['total'];
        }
    }
}

// ------ Score Fallback: Aggregate Player Stats ------
if (empty($game['home_points']) && empty($game['away_points'])) {
    $psFallback = $pdo->prepare("
        SELECT team_name, SUM(points) AS total_pts
        FROM game_player_stats
        WHERE game_date = ? AND team_name IN (?, ?)
        GROUP BY team_name
    ");
    $psFallback->execute([$date, $game['home_team'], $game['away_team']]);

    foreach ($psFallback->fetchAll(PDO::FETCH_ASSOC) as $ps) {
        if ($ps['team_name'] === $game['home_team'] && $ps['total_pts'] > 0) {
            $game['home_points'] = $ps['total_pts'];
        }
        if ($ps['team_name'] === $game['away_team'] && $ps['total_pts'] > 0) {
            $game['away_points'] = $ps['total_pts'];
        }
    }
}


// ------ Quarter Scores ------
$quarter_data  = getQuarterScores($game['home_team'], $game['away_team'], $api_scores);
$quarterScores = [];
$numOvertimes  = 0;

if (!empty($quarter_data)) {
    // Build from live API data
    $numOvertimes = count($quarter_data['home_overtimes'] ?? []);

    $homeRow = [
        'team_abbrev'  => $home_team,
        'q1_points'    => $quarter_data['home'][0] ?? null,
        'q2_points'    => $quarter_data['home'][1] ?? null,
        'q3_points'    => $quarter_data['home'][2] ?? null,
        'q4_points'    => $quarter_data['home'][3] ?? null,
        'total_points' => $quarter_data['home_total']
    ];
    $awayRow = [
        'team_abbrev'  => $away_team,
        'q1_points'    => $quarter_data['away'][0] ?? null,
        'q2_points'    => $quarter_data['away'][1] ?? null,
        'q3_points'    => $quarter_data['away'][2] ?? null,
        'q4_points'    => $quarter_data['away'][3] ?? null,
        'total_points' => $quarter_data['away_total']
    ];

    for ($i = 0; $i < $numOvertimes; $i++) {
        $homeRow['ot' . ($i + 1) . '_points'] = $quarter_data['home_overtimes'][$i] ?? null;
        $awayRow['ot' . ($i + 1) . '_points'] = $quarter_data['away_overtimes'][$i] ?? null;
    }

    $quarterScores[] = $homeRow;
    $quarterScores[] = $awayRow;

} else {
    // Fallback: load from database
    $stmt = $pdo->prepare("
        SELECT * FROM game_quarter_scores
        WHERE game_date = ? AND team_abbrev IN (?, ?)
        ORDER BY CASE WHEN team_abbrev = ? THEN 0 ELSE 1 END
    ");
    $stmt->execute([$date, $home_team, $away_team, $home_team]);
    $quarterScores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($quarterScores)) {
        $fr = $quarterScores[0];
        for ($i = 1; $i <= 10; $i++) {
            $ok = 'ot' . $i . '_points';
            if (isset($fr[$ok]) && $fr[$ok] !== null && $fr[$ok] !== '') {
                $numOvertimes = $i;
            } else {
                break;
            }
        }
    }
}


// ------ Player Stats ------
$home_team_variants = [$game['home_team']];
$away_team_variants = [$game['away_team']];

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
$gd_ph = implode(',', array_fill(0, count($all_team_variants), '?'));

$stmt = $pdo->prepare("
    SELECT gps.*
    FROM game_player_stats gps
    WHERE gps.game_date = ?
      AND gps.team_name IN ($gd_ph)
      AND gps.player_name IS NOT NULL
      AND gps.player_name != ''
    ORDER BY 
        gps.team_name,
        CASE WHEN gps.minutes IS NULL OR gps.minutes = '-' THEN 1 ELSE 0 END,
        CASE WHEN gps.points IS NULL THEN 0 ELSE gps.points END DESC
");
$stmt->execute(array_merge([$date], $all_team_variants));
$playerStats = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ------ Inactive Players ------
$stmt = $pdo->prepare("
    SELECT * FROM game_inactive_players
    WHERE game_date = ?
      AND team_city IN (
          SELECT CASE
              WHEN home_team = 'Atlanta Hawks'          THEN 'Atlanta'
              WHEN home_team = 'Boston Celtics'         THEN 'Boston'
              WHEN home_team = 'Brooklyn Nets'          THEN 'Brooklyn'
              WHEN home_team = 'Charlotte Hornets'      THEN 'Charlotte'
              WHEN home_team = 'Chicago Bulls'          THEN 'Chicago'
              WHEN home_team = 'Cleveland Cavaliers'    THEN 'Cleveland'
              WHEN home_team = 'Dallas Mavericks'       THEN 'Dallas'
              WHEN home_team = 'Denver Nuggets'         THEN 'Denver'
              WHEN home_team = 'Detroit Pistons'        THEN 'Detroit'
              WHEN home_team = 'Golden State Warriors'  THEN 'Golden State'
              WHEN home_team = 'Houston Rockets'        THEN 'Houston'
              WHEN home_team = 'Indiana Pacers'         THEN 'Indiana'
              WHEN home_team = 'LA Clippers'            THEN 'LA'
              WHEN home_team = 'Los Angeles Clippers'   THEN 'LA'
              WHEN home_team = 'Los Angeles Lakers'     THEN 'Los Angeles'
              WHEN home_team = 'Memphis Grizzlies'      THEN 'Memphis'
              WHEN home_team = 'Miami Heat'             THEN 'Miami'
              WHEN home_team = 'Milwaukee Bucks'        THEN 'Milwaukee'
              WHEN home_team = 'Minnesota Timberwolves' THEN 'Minnesota'
              WHEN home_team = 'New Orleans Pelicans'   THEN 'New Orleans'
              WHEN home_team = 'New York Knicks'        THEN 'New York'
              WHEN home_team = 'Oklahoma City Thunder'  THEN 'Oklahoma City'
              WHEN home_team = 'Orlando Magic'          THEN 'Orlando'
              WHEN home_team = 'Philadelphia 76ers'     THEN 'Philadelphia'
              WHEN home_team = 'Phoenix Suns'           THEN 'Phoenix'
              WHEN home_team = 'Portland Trail Blazers' THEN 'Portland'
              WHEN home_team = 'Sacramento Kings'       THEN 'Sacramento'
              WHEN home_team = 'San Antonio Spurs'      THEN 'San Antonio'
              WHEN home_team = 'Toronto Raptors'        THEN 'Toronto'
              WHEN home_team = 'Utah Jazz'              THEN 'Utah'
              WHEN home_team = 'Washington Wizards'     THEN 'Washington'
          END
          FROM games
          WHERE date = ? AND (home_team = ? OR away_team = ?)

          UNION

          SELECT CASE
              WHEN away_team = 'Atlanta Hawks'          THEN 'Atlanta'
              WHEN away_team = 'Boston Celtics'         THEN 'Boston'
              WHEN away_team = 'Brooklyn Nets'          THEN 'Brooklyn'
              WHEN away_team = 'Charlotte Hornets'      THEN 'Charlotte'
              WHEN away_team = 'Chicago Bulls'          THEN 'Chicago'
              WHEN away_team = 'Cleveland Cavaliers'    THEN 'Cleveland'
              WHEN away_team = 'Dallas Mavericks'       THEN 'Dallas'
              WHEN away_team = 'Denver Nuggets'         THEN 'Denver'
              WHEN away_team = 'Detroit Pistons'        THEN 'Detroit'
              WHEN away_team = 'Golden State Warriors'  THEN 'Golden State'
              WHEN away_team = 'Houston Rockets'        THEN 'Houston'
              WHEN away_team = 'Indiana Pacers'         THEN 'Indiana'
              WHEN away_team = 'LA Clippers'            THEN 'LA'
              WHEN away_team = 'Los Angeles Clippers'   THEN 'LA'
              WHEN away_team = 'Los Angeles Lakers'     THEN 'Los Angeles'
              WHEN away_team = 'Memphis Grizzlies'      THEN 'Memphis'
              WHEN away_team = 'Miami Heat'             THEN 'Miami'
              WHEN away_team = 'Milwaukee Bucks'        THEN 'Milwaukee'
              WHEN away_team = 'Minnesota Timberwolves' THEN 'Minnesota'
              WHEN away_team = 'New Orleans Pelicans'   THEN 'New Orleans'
              WHEN away_team = 'New York Knicks'        THEN 'New York'
              WHEN away_team = 'Oklahoma City Thunder'  THEN 'Oklahoma City'
              WHEN away_team = 'Orlando Magic'          THEN 'Orlando'
              WHEN away_team = 'Philadelphia 76ers'     THEN 'Philadelphia'
              WHEN away_team = 'Phoenix Suns'           THEN 'Phoenix'
              WHEN away_team = 'Portland Trail Blazers' THEN 'Portland'
              WHEN away_team = 'Sacramento Kings'       THEN 'Sacramento'
              WHEN away_team = 'San Antonio Spurs'      THEN 'San Antonio'
              WHEN away_team = 'Toronto Raptors'        THEN 'Toronto'
              WHEN away_team = 'Utah Jazz'              THEN 'Utah'
              WHEN away_team = 'Washington Wizards'     THEN 'Washington'
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="theme-color" content="<?= ($_SESSION['theme_preference'] ?? 'dark') === 'classic' ? '#f5f5f5' : '#121a23' ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Box Score - <?= htmlspecialchars($game['home_team']) ?> vs <?= htmlspecialchars($game['away_team']) ?></title>
    <link rel="apple-touch-icon" type="image/png" href="/nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="/nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
/* ==========================================================================
   CSS VARIABLES
   ========================================================================== */
:root {
    --bg-primary: #151d28;
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
    --accent-teal: #76a5af;
    --radius-md: 10px;
    --radius-lg: 14px;
    --shadow-card: 0 1px 3px rgba(0, 0, 0, 0.4), 0 0 0 1px var(--border-color);
    --transition-fast: 0.15s ease;
    --team-row-gradient: rgba(48, 62, 80, 0.7);
    --team-row-gradient-end: rgba(32, 42, 56, 0);
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
    --accent-blue-glow: rgba(0, 102, 255, 0.15);
    --accent-green: #28a745;
    --accent-green-dim: rgba(40, 167, 69, 0.08);
    --accent-red: #dc3545;
    --accent-red-dim: rgba(220, 53, 69, 0.08);
    --accent-gold: #d4a017;
    --accent-silver: #8a8a8a;
    --accent-bronze: #b5651d;
    --shadow-card: 0 1px 4px rgba(0, 0, 0, 0.08), 0 0 0 1px rgba(0, 0, 0, 0.04);
    --shadow-elevated: 0 4px 16px rgba(0, 0, 0, 0.1), 0 0 0 1px rgba(0, 0, 0, 0.06);
    --team-row-gradient: rgba(0, 0, 0, 0.04);
    --team-row-gradient-end: rgba(0, 0, 0, 0);
}
body {
    background-image: url('nba-wins-platform/public/assets/background/geometric_white.png');
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
    padding: 0;
}

/* ==========================================================================
   LAYOUT
   ========================================================================== */
.app-container { max-width: 900px; margin: 0 auto; padding: 12px 12px 2rem; }

/* Desktop two-column layout */
@media (min-width: 1100px) {
    .app-container { max-width: 1280px; }
    .gd-two-col {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
        align-items: start;
    }
    .gd-col-right {
        position: sticky;
        top: 12px;
        max-height: calc(100vh - 24px);
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: var(--bg-elevated) transparent;
    }
    .gd-col-right::-webkit-scrollbar { width: 5px; }
    .gd-col-right::-webkit-scrollbar-track { background: transparent; }
    .gd-col-right::-webkit-scrollbar-thumb {
        background: var(--bg-elevated);
        border-radius: 4px;
    }
    .gd-col-right::-webkit-scrollbar-thumb:hover {
        background: var(--text-muted);
    }
}
@media (max-width: 1099px) {
    .gd-two-col { display: block; }
}

/* Game status badge */
.gd-status-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 12px; border-radius: 20px;
    font-size: 0.78rem; font-weight: 600;
    margin-top: 6px;
}
.gd-status-badge.live {
    background: rgba(248, 81, 73, 0.15); color: var(--accent-red);
    animation: gd-live-pulse 2s ease-in-out infinite;
}
.gd-status-badge.final {
    background: rgba(63, 185, 80, 0.15); color: var(--accent-green);
}
.gd-status-badge.scheduled {
    background: rgba(56, 139, 253, 0.15); color: var(--accent-blue);
}
.gd-status-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: currentColor;
}
@keyframes gd-live-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
}

/* Latest play ticker */
.gd-latest-play {
    text-align: center; margin-top: 8px;
    padding: 6px 14px; border-radius: 8px;
    background: rgba(56, 139, 253, 0.08);
    border: 1px solid rgba(56, 139, 253, 0.15);
    font-size: 0.78rem; line-height: 1.4;
    max-width: 420px; margin-left: auto; margin-right: auto;
    animation: gd-play-fade-in 0.4s ease;
}
.gd-play-label {
    display: inline-block;
    background: rgba(56, 139, 253, 0.18); color: var(--accent-blue);
    font-size: 0.65rem; font-weight: 700; letter-spacing: 0.05em;
    padding: 1px 6px; border-radius: 4px; margin-right: 6px;
    vertical-align: middle;
}
.gd-play-text {
    color: var(--text-secondary);
    vertical-align: middle;
}
@keyframes gd-play-fade-in {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}

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
   MATCHUP HEADER
   ========================================================================== */
.matchup-header {
    background: var(--bg-card); padding: 1.5rem;
    border-radius: var(--radius-lg); margin-bottom: 14px;
    box-shadow: var(--shadow-card);
}

.team-row {
    position: relative; padding: 1.5rem;
    margin-bottom: 0.75rem; border-radius: var(--radius-md);
    overflow: hidden; min-height: 90px;
    display: flex; align-items: center;
    background: transparent;
}
.team-row:last-of-type { margin-bottom: 0; }

/* Fading background from logo side */
.team-row::before {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    z-index: 0;
}
.team-row.home-team::before {
    background: linear-gradient(to right, var(--team-row-gradient) 0%, var(--team-row-gradient-end) 75%);
}
.team-row.away-team::before {
    background: linear-gradient(to left, var(--team-row-gradient) 0%, var(--team-row-gradient-end) 75%);
}

.team-info-left {
    position: relative; z-index: 2; flex: 1;
    display: flex; align-items: center; gap: 1.25rem;
}
.team-info-right {
    position: relative; z-index: 2; flex: 1;
    text-align: right; display: flex; align-items: center;
    gap: 1.25rem; flex-direction: row-reverse;
}

.team-logo-background {
    position: absolute; width: 160px; height: 160px;
    object-fit: contain; opacity: 0.16;
    z-index: 1; pointer-events: none;
}
.team-row.home-team .team-logo-background { left: 1rem; top: 50%; transform: translateY(-50%); }
.team-row.away-team .team-logo-background { right: 1rem; top: 50%; transform: translateY(-50%); }

.team-logo-visible {
    width: 64px; height: 64px; object-fit: contain;
    filter: drop-shadow(0 2px 6px rgba(0, 0, 0, 0.3));
    flex-shrink: 0;
}

/* Logo flip animation */
.logo-flip-container {
    width: 64px; height: 64px;
    perspective: 600px;
    flex-shrink: 0;
}
.logo-flip-inner {
    width: 100%; height: 100%;
    position: relative;
    transform-style: preserve-3d;
    transition: transform 0.6s ease-in-out;
}
.logo-flip-inner.flipped { transform: rotateY(180deg); }
.logo-flip-front, .logo-flip-back {
    position: absolute; inset: 0;
    backface-visibility: hidden;
    display: flex; align-items: center; justify-content: center;
}
.logo-flip-back { transform: rotateY(180deg); }
.owner-photo-flip {
    width: 56px; height: 56px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--accent-blue);
    box-shadow: 0 2px 8px rgba(56, 139, 253, 0.3);
}
.logo-flip-container .team-logo-visible {
    filter: drop-shadow(0 2px 6px rgba(0, 0, 0, 0.3));
}
.team-name { font-size: 1.15rem; font-weight: 700; color: var(--text-primary); margin-bottom: 2px; line-height: 1.2; }
.team-score {
    font-size: 1.5rem; font-weight: 800; color: var(--text-primary);
    letter-spacing: -0.5px; margin-bottom: 2px;
    font-variant-numeric: tabular-nums;
}
.team-owner { font-size: 0.82rem; color: var(--text-muted); font-style: italic; margin-top: 2px; font-weight: 500; }

.vs-divider {
    text-align: center; font-size: 0.9rem; font-weight: 700;
    color: var(--text-muted); padding: 0.4rem 0; letter-spacing: 2px;
}

.venue-info {
    display: flex; align-items: center; justify-content: center;
    gap: 0.5rem; padding: 0.4rem 0.75rem;
    background: rgba(118, 165, 175, 0.15); color: var(--text-muted);
    border-radius: var(--radius-md); font-weight: 500; font-size: 0.78rem;
    margin-top: 0.5rem;
    flex-wrap: wrap;
}
.venue-info .divider { color: rgba(255, 255, 255, 0.25); }

/* ==========================================================================
   SECTION CARDS
   ========================================================================== */
.section {
    background: var(--bg-card); border-radius: var(--radius-lg);
    box-shadow: var(--shadow-card); padding: 1.25rem;
    margin-bottom: 14px; overflow: hidden;
}
.section h3 {
    margin: 0 0 1rem; padding-bottom: 0.6rem;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-primary); font-size: 1.1rem; font-weight: 700;
    display: flex; align-items: center; gap: 8px;
}
.section h4 {
    margin: 1.25rem 0 0.75rem;
    color: var(--text-secondary); font-size: 0.95rem; font-weight: 600;
}

/* ==========================================================================
   TABLES
   ========================================================================== */
table {
    width: 100%; border-collapse: collapse;
    margin: 0.75rem 0; font-variant-numeric: tabular-nums;
}
th, td {
    padding: 10px 12px; text-align: left;
    border-bottom: 1px solid var(--border-color);
}
th {
    background: var(--bg-elevated); color: var(--text-secondary);
    font-weight: 600; text-transform: uppercase;
    font-size: 0.75rem; letter-spacing: 0.5px;
}
tbody tr { background: var(--bg-card); transition: background var(--transition-fast); }
tbody tr:hover { background: var(--bg-card-hover); }
tbody tr:last-child td { border-bottom: none; }
td.font-bold { font-weight: 700; color: var(--text-primary); }

/* ==========================================================================
   INACTIVE PLAYERS
   ========================================================================== */
.inactive-icon { margin-right: 6px; color: var(--accent-red); }
.inactive-list { list-style: none; padding-left: 0; margin: 0.5rem 0; }
.inactive-list li {
    padding: 0.5rem 0.75rem; margin: 0.25rem 0;
    background: var(--bg-elevated);
    border-left: 3px solid var(--accent-red);
    border-radius: 4px; color: var(--text-secondary);
    font-size: 0.9rem; transition: all 0.2s;
}
.inactive-list li:hover {
    background: var(--bg-card-hover);
    transform: translateX(4px);
}

/* ==========================================================================
   PLAYER LINKS
   ========================================================================== */
.player-link {
    color: var(--text-primary); text-decoration: none;
    border-bottom: 1px dotted var(--text-muted);
    transition: color 0.2s;
}
.player-link:hover {
    color: var(--accent-blue);
    border-bottom-color: var(--accent-blue);
}

/* ==========================================================================
   MOBILE RESPONSIVE
   ========================================================================== */
@media (max-width: 600px) {
    .app-container { padding: 0 8px 2rem; }
    .matchup-header { padding: 1rem; border-radius: var(--radius-md); }
    .team-row { padding: 1rem 0.75rem; min-height: 70px; }
    .team-logo-visible { width: 48px; height: 48px; }
    .logo-flip-container { width: 48px; height: 48px; }
    .owner-photo-flip { width: 40px; height: 40px; }
    .team-logo-background { width: 120px; height: 120px; }
    .team-name { font-size: 0.95rem; }
    .team-score { font-size: 1.25rem; }
    .section { padding: 1rem; }

    table { font-size: 0.78rem; }
    th, td { padding: 7px 5px; }
    th { font-size: 0.7rem; }
    .player-stats-table th:last-child,
    .player-stats-table td:last-child { display: none; }
}
    
/* ===== FLOATING PILL NAV ===== */
    .floating-pill {
        position: fixed;
        bottom: 18px;
        left: 50%;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        align-items: center;
        background: rgba(24, 33, 47, 0.82);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 999px;
        padding: 6px;
        box-shadow: 0 4px 24px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.03);
        -webkit-backdrop-filter: blur(20px);
        backdrop-filter: blur(20px);
        -webkit-transform: translateX(-50%) translateZ(0);
        transform: translateX(-50%) translateZ(0);
        will-change: transform;
        transition: border-radius 0.35s ease, padding 0.35s ease;
    }

    .floating-pill.expanded {
        border-radius: 22px;
        padding: 8px;
    }

    /* Main row (always visible) */
    .pill-main-row {
        display: flex;
        align-items: center;
        gap: 2px;
    }

    /* Expanded row (hidden by default) */
    .pill-expanded-row {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        max-height: 0;
        opacity: 0;
        overflow: hidden;
        transition: max-height 0.35s ease, opacity 0.25s ease, margin 0.35s ease, padding 0.35s ease;
        margin-bottom: 0;
        padding: 0 4px;
    }
    .floating-pill.expanded .pill-expanded-row {
        max-height: 60px;
        opacity: 1;
        margin-bottom: 6px;
        padding: 0 4px 6px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    }

    .pill-expanded-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 2px;
        width: 52px;
        height: 44px;
        border-radius: 12px;
        text-decoration: none;
        color: var(--text-muted);
        font-size: 14px;
        transition: all var(--transition-fast);
        cursor: pointer;
        border: none;
        background: none;
        -webkit-tap-highlight-color: transparent;
    }
    .pill-expanded-item span {
        font-size: 9px;
        font-weight: 600;
        font-family: 'Outfit', sans-serif;
        letter-spacing: 0.02em;
        line-height: 1;
        white-space: nowrap;
    }
    .pill-expanded-item:hover {
        color: var(--text-primary);
        background: rgba(255, 255, 255, 0.08);
    }
    .pill-expanded-item.logout-item:hover {
        color: var(--accent-red);
    }

    /* Hamburger to X morph */
    .pill-menu-btn .fa-bars,
    .pill-menu-btn .fa-xmark { transition: transform 0.3s ease, opacity 0.2s ease; }
    .pill-menu-btn .fa-xmark { position: absolute; opacity: 0; transform: rotate(-90deg); }
    .floating-pill.expanded .pill-menu-btn .fa-bars { opacity: 0; transform: rotate(90deg); }
    .floating-pill.expanded .pill-menu-btn .fa-xmark { opacity: 1; transform: rotate(0deg); }

    /* Space at the bottom so content doesn't hide behind pill */
    body { padding-bottom: 84px; }

    @media (max-width: 600px) {
        .floating-pill {
            bottom: calc(14px + env(safe-area-inset-bottom, 0px));
        }
    }

    .pill-item {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 46px;
        height: 46px;
        border-radius: 999px;
        text-decoration: none;
        color: var(--text-muted);
        font-size: 17px;
        transition: all var(--transition-fast);
        cursor: pointer;
        border: none;
        background: none;
        -webkit-tap-highlight-color: transparent;
        position: relative;
    }

    .pill-item:hover {
        color: var(--text-primary);
        background: var(--bg-elevated);
    }

    .pill-item.active {
        color: white;
        background: var(--accent-blue);
    }

    .pill-item:active {
        transform: scale(0.92);
    }

    .pill-divider {
        width: 1px;
        height: 26px;
        background: var(--border-color);
        flex-shrink: 0;
    }

    /* Tooltip on hover (desktop only) */
    @media (min-width: 601px) {
        .pill-item::after {
            content: attr(data-label);
            position: absolute;
            bottom: calc(100% + 8px);
            left: 50%;
            transform: translateX(-50%) scale(0.9);
            background: var(--bg-elevated);
            color: var(--text-primary);
            font-size: 11px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            padding: 4px 10px;
            border-radius: var(--radius-sm);
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: all 0.15s ease;
            border: 1px solid var(--border-color);
        }

        .pill-item:hover::after {
            opacity: 1;
            transform: translateX(-50%) scale(1);
        }

        /* Hide tooltips when expanded (items have labels) */
        .floating-pill.expanded .pill-item:hover::after { opacity: 0; }
    }

</style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/components/navigation_menu.php'; ?>

<div class="app-container">

    <!-- ================================================================
         TWO-COLUMN GRID (desktop) / STACKED (mobile)
         ================================================================ -->
    <div class="gd-two-col">

        <!-- LEFT COLUMN: Matchup + Box Score -->
        <div class="gd-col-left">
            <div class="matchup-header" id="gd-matchup">

        <!-- Home Team -->
        <div class="team-row home-team">
            <img src="<?= htmlspecialchars(getTeamLogo($game['home_team'])) ?>" alt=""
                 class="team-logo-background" onerror="this.style.display='none'">
            <div class="team-info-left">
                <a href="/nba-wins-platform/stats/team_data.php?team=<?= urlencode($game['home_team']) ?>">
                    <div class="logo-flip-container">
                        <div class="logo-flip-inner">
                            <div class="logo-flip-front">
                                <img src="<?= htmlspecialchars(getTeamLogo($game['home_team'])) ?>" alt=""
                                     class="team-logo-visible" onerror="this.style.opacity='0.3'">
                            </div>
                            <div class="logo-flip-back">
                                <img src="<?= htmlspecialchars($game['home_photo_url']) ?>" alt=""
                                     class="owner-photo-flip"
                                     onerror="this.src='<?= $photoBase ?>default.png'">
                            </div>
                        </div>
                    </div>
                </a>
                <div>
                    <div class="team-name"><?= htmlspecialchars($game['home_team']) ?></div>
                    <div class="team-score" id="gd-home-score"><?= $game['home_points'] ?></div>
                    <?php if ($game['home_participant']): ?>
                        <div class="team-owner">(<?= htmlspecialchars($game['home_participant']) ?>)</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="vs-divider">VS</div>

        <!-- Away Team -->
        <div class="team-row away-team">
            <img src="<?= htmlspecialchars(getTeamLogo($game['away_team'])) ?>" alt=""
                 class="team-logo-background" onerror="this.style.display='none'">
            <div class="team-info-right">
                <a href="/nba-wins-platform/stats/team_data.php?team=<?= urlencode($game['away_team']) ?>">
                    <div class="logo-flip-container">
                        <div class="logo-flip-inner">
                            <div class="logo-flip-front">
                                <img src="<?= htmlspecialchars(getTeamLogo($game['away_team'])) ?>" alt=""
                                     class="team-logo-visible" onerror="this.style.opacity='0.3'">
                            </div>
                            <div class="logo-flip-back">
                                <img src="<?= htmlspecialchars($game['away_photo_url']) ?>" alt=""
                                     class="owner-photo-flip"
                                     onerror="this.src='<?= $photoBase ?>default.png'">
                            </div>
                        </div>
                    </div>
                </a>
                <div>
                    <div class="team-name"><?= htmlspecialchars($game['away_team']) ?></div>
                    <div class="team-score" id="gd-away-score"><?= $game['away_points'] ?></div>
                    <?php if ($game['away_participant']): ?>
                        <div class="team-owner">(<?= htmlspecialchars($game['away_participant']) ?>)</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Venue, Date & Status -->
        <div class="venue-info">
            <span><i class="fas fa-building"></i> <?= htmlspecialchars($game['arena']) ?></span>
            <span class="divider">•</span>
            <span><i class="far fa-calendar"></i> <?= date('F j, Y', strtotime($game['date'])) ?></span>
        </div>
        <?php
        $statusClass = 'scheduled';
        $statusText = $game['status_long'] ?? 'Scheduled';
        if ($isLiveGame) {
            $statusClass = 'live';
        } elseif (in_array($game['status_long'], ['Final', 'Finished'])) {
            $statusClass = 'final';
            $statusText = 'Final';
        }
        ?>
        <div style="text-align: center;">
            <span class="gd-status-badge <?= $statusClass ?>" id="gd-status-badge">
                <span class="gd-status-dot"></span>
                <span id="gd-status-text"><?= htmlspecialchars($statusText) ?></span>
            </span>
        </div>
        <?php if ($latestPlay): ?>
        <div class="gd-latest-play" id="gd-latest-play">
            <span class="gd-play-label">LATEST</span>
            <span class="gd-play-text"><?= htmlspecialchars($latestPlay['description']) ?></span>
        </div>
        <?php else: ?>
        <div class="gd-latest-play" id="gd-latest-play" style="display:none;"></div>
        <?php endif; ?>
    </div>

            <div class="section" id="gd-box-score">
                <table>
                    <thead>
                        <tr>
                            <th>Team</th>
                            <th>Q1</th>
                            <th>Q2</th>
                            <th>Q3</th>
                            <th>Q4</th>
                            <?php for ($ot = 1; $ot <= $numOvertimes; $ot++): ?>
                                <th><?= $numOvertimes === 1 ? 'OT' : 'OT' . $ot ?></th>
                            <?php endfor; ?>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quarterScores as $score): ?>
                            <tr>
                                <td class="font-bold"><?= htmlspecialchars($score['team_abbrev']) ?></td>
                                <td><?= $score['q1_points'] ?? '-' ?></td>
                                <td><?= $score['q2_points'] ?? '-' ?></td>
                                <td><?= $score['q3_points'] ?? '-' ?></td>
                                <td><?= $score['q4_points'] ?? '-' ?></td>
                                <?php for ($ot = 1; $ot <= $numOvertimes; $ot++): ?>
                                    <td><?= $score['ot' . $ot . '_points'] ?? '-' ?></td>
                                <?php endfor; ?>
                                <td class="font-bold"><?= $score['total_points'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Inactive Players (below box score on desktop) -->
            <?php if (!empty($inactivePlayers)): ?>
                <div class="section">
                    <h3><i class="fa-solid fa-notes-medical inactive-icon"></i> Inactive Players</h3>
                    <?php
                    $gd_inactive_teams = array_unique(array_column($inactivePlayers, 'team_city'));
                    foreach ($gd_inactive_teams as $gd_it):
                        $teamInactives = array_filter($inactivePlayers, function ($p) use ($gd_it) {
                            return $p['team_city'] === $gd_it;
                        });
                    ?>
                        <h4><?= htmlspecialchars($gd_it) ?></h4>
                        <ul class="inactive-list">
                            <?php foreach ($teamInactives as $p): ?>
                                <li><?= htmlspecialchars($p['player_name']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT COLUMN: Player Statistics -->
        <div class="gd-col-right">
            <div class="section" id="gd-player-stats">
                <?php
                $gd_teams = array_unique(array_column($playerStats, 'team_name'));
                foreach ($gd_teams as $gd_team):
                    $teamPlayers = array_filter($playerStats, function ($p) use ($gd_team) {
                        return $p['team_name'] === $gd_team;
                    });
                ?>
                    <h4><?= htmlspecialchars(normalizeTeamName($gd_team)) ?></h4>
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
                            <?php foreach ($teamPlayers as $player):
                                $pUrl = '/nba-wins-platform/stats/player_profile.php'
                                      . '?team=' . urlencode(normalizeTeamName($gd_team))
                                      . '&player=' . urlencode($player['player_name']);
                            ?>
                                <tr>
                                    <td>
                                        <a href="<?= $pUrl ?>" class="player-link">
                                            <?= htmlspecialchars($player['player_name']) ?>
                                        </a>
                                    </td>
                                    <td><?= formatMinutes($player['minutes']) ?></td>
                                    <td><?= $player['points'] ?? '-' ?></td>
                                    <td><?= $player['rebounds'] ?? '-' ?></td>
                                    <td><?= $player['assists'] ?? '-' ?></td>
                                    <td><?= ($player['fg_made'] ?? '-') . '-' . ($player['fg_attempts'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            </div>
        </div>

    </div><!-- /.gd-two-col -->

</div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var flippers = document.querySelectorAll('.logo-flip-inner');
        if (flippers.length === 0) return;

        // Flip to owner photo after delay
        setTimeout(function() {
            flippers.forEach(function(el, i) {
                setTimeout(function() { el.classList.add('flipped'); }, i * 200);
            });

            // Hold, then flip back to logo
            setTimeout(function() {
                flippers.forEach(function(el, i) {
                    setTimeout(function() { el.classList.remove('flipped'); }, i * 200);
                });
            }, 2000);
        }, 600);
    });
    </script>

    <!-- Live Box Score Auto-Refresh -->
    <script>
    (function() {
        var isLive = <?= $isLiveGame ? 'true' : 'false' ?>;
        var gameStatus = '<?= addslashes($game['status_long'] ?? 'Scheduled') ?>';
        var refreshInFlight = false;

        function refreshBoxScore() {
            if (refreshInFlight) return;
            refreshInFlight = true;

            fetch(window.location.href)
                .then(function(r) { return r.text(); })
                .then(function(html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');

                    // Surgically update scores only (don't touch flip containers)
                    var newHome = doc.getElementById('gd-home-score');
                    var newAway = doc.getElementById('gd-away-score');
                    if (newHome) document.getElementById('gd-home-score').textContent = newHome.textContent;
                    if (newAway) document.getElementById('gd-away-score').textContent = newAway.textContent;

                    // Update status badge only
                    var newBadge = doc.getElementById('gd-status-badge');
                    var oldBadge = document.getElementById('gd-status-badge');
                    if (newBadge && oldBadge) {
                        oldBadge.className = newBadge.className;
                        oldBadge.innerHTML = newBadge.innerHTML;
                    }

                    // Update latest play ticker
                    var newPlay = doc.getElementById('gd-latest-play');
                    var oldPlay = document.getElementById('gd-latest-play');
                    if (newPlay && oldPlay) {
                        if (newPlay.innerHTML !== oldPlay.innerHTML) {
                            oldPlay.innerHTML = newPlay.innerHTML;
                            oldPlay.style.display = newPlay.style.display;
                            oldPlay.style.animation = 'none';
                            oldPlay.offsetHeight;
                            oldPlay.style.animation = '';
                        }
                    }

                    // Update box score table
                    var newBox = doc.querySelector('#gd-box-score');
                    var oldBox = document.querySelector('#gd-box-score');
                    if (newBox && oldBox) {
                        oldBox.innerHTML = newBox.innerHTML;
                    }

                    // Update player stats (preserve scroll position)
                    var newStats = doc.querySelector('#gd-player-stats');
                    var oldStats = document.querySelector('#gd-player-stats');
                    if (newStats && oldStats) {
                        var scrollPos = oldStats.closest('.gd-col-right');
                        var savedScroll = scrollPos ? scrollPos.scrollTop : 0;
                        oldStats.innerHTML = newStats.innerHTML;
                        if (scrollPos) scrollPos.scrollTop = savedScroll;
                    }

                    // Check if game went final — stop refreshing
                    var newStatusText = doc.getElementById('gd-status-text');
                    if (newStatusText) {
                        var st = newStatusText.textContent.trim();
                        if (st === 'Final' || st === 'Finished') {
                            isLive = false;
                        } else if (st !== 'Scheduled') {
                            isLive = true;
                        }
                    }
                })
                .catch(function(err) { console.error('Box score refresh error:', err); })
                .finally(function() { refreshInFlight = false; });
        }

        // Poll every 15 seconds while game is live
        setInterval(function() {
            if (isLive) refreshBoxScore();
        }, 15000);
    })();
    </script>

    <!-- Floating Pill Navigation -->
    <nav class="floating-pill" id="floatingPill">
        <!-- Expanded row (hidden until menu tap) -->
        <div class="pill-expanded-row" id="pillExpandedRow">
            <a href="/nba_standings.php" class="pill-expanded-item">
                <i class="fas fa-basketball-ball"></i>
                <span>Standings</span>
            </a>
            <a href="/draft_summary.php" class="pill-expanded-item">
                <i class="fas fa-file-alt"></i>
                <span>Draft</span>
            </a>
            <a href="https://buymeacoffee.com/taylorstvns" target="_blank" class="pill-expanded-item">
                <i class="fas fa-mug-hot"></i>
                <span>Tip Jar</span>
            </a>
            <?php if (empty($isGuest)): ?>
            <a href="/nba-wins-platform/auth/logout.php" class="pill-expanded-item logout-item">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
            <?php endif; ?>
        </div>
        <!-- Main row -->
        <div class="pill-main-row">
            <a href="/index.php" class="pill-item" data-label="Home">
                <i class="fas fa-home"></i>
            </a>
            <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?php echo $currentLeagueId ?? ($_SESSION['current_league_id'] ?? 0); ?>&user_id=<?php echo $profileUserId ?? ($_SESSION['user_id'] ?? 0); ?>" class="pill-item" data-label="Profile">
                <i class="fas fa-user"></i>
            </a>
            <a href="/analytics.php" class="pill-item" data-label="Analytics">
                <i class="fas fa-chart-line"></i>
            </a>
            <a href="/claudes-column.php" class="pill-item" data-label="Column" style="position:relative">
                <i class="fa-solid fa-newspaper"></i>
                <?php if ($hasNewArticles): ?><span style="position:absolute;top:2px;right:2px;width:7px;height:7px;background:#f85149;border-radius:50%;box-shadow:0 0 4px rgba(248,81,73,0.5)"></span><?php endif; ?>
            </a>
            <div class="pill-divider"></div>
            <button class="pill-item pill-menu-btn" data-label="Menu" onclick="togglePillMenu()">
                <i class="fas fa-bars"></i>
                <i class="fas fa-xmark"></i>
            </button>
        </div>
    </nav>
    <script>
    function togglePillMenu() {
        document.getElementById('floatingPill').classList.toggle('expanded');
    }
    // Close expanded pill when clicking outside
    document.addEventListener('click', function(e) {
        var pill = document.getElementById('floatingPill');
        if (pill.classList.contains('expanded') && !pill.contains(e.target)) {
            pill.classList.remove('expanded');
        }
    });
    </script>

</body>
</html>