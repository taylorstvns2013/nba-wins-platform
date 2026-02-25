<?php
/**
 * team_data_new.php - Team Data Page
 * 
 * Displays comprehensive team information with tabs:
 *   - Home: Season record, team stats, last 5 games, upcoming 5 games
 *   - Roster: Full roster with ESPN data + DB stats merged
 *   - Schedule: Full season schedule with game links and top scorers
 * 
 * Path: /data/www/default/nba-wins-platform/stats/team_data_new.php
 */

header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// =====================================================================
// SESSION & AUTH CHECK
// =====================================================================
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['current_league_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

// =====================================================================
// DEPENDENCIES
// =====================================================================
require_once '/data/www/default/nba-wins-platform/config/db_connection.php';
require_once '/data/www/default/nba-wins-platform/core/nba_api_integration.php';

// =====================================================================
// REQUEST PARAMETERS
// =====================================================================
$user_id        = $_SESSION['user_id'];
$league_id      = $_SESSION['current_league_id'];
$currentLeagueId = $league_id;

$team_name = $_GET['team'] ?? null;
if (!$team_name) {
    die("No team specified");
}
$team_name = str_replace('+', ' ', $team_name);

// =====================================================================
// NBA API INTEGRATION
// =====================================================================
$nbaApi = new NBAApiIntegration([
    'python_path'   => '/usr/bin/python3',
    'scripts_path'  => '/data/www/default/nba-wins-platform/tasks',
    'cache_enabled' => true,
    'cache_timeout' => 300,
    'api_timeout'   => 3
]);


// ==========================================================================
// HELPER FUNCTIONS
// ==========================================================================

/**
 * Map team name to ESPN team ID
 */
function getEspnTeamId($teamName) {
    $espnMap = [
        'Atlanta Hawks'          => 1,
        'Boston Celtics'         => 2,
        'Brooklyn Nets'          => 17,
        'Charlotte Hornets'      => 30,
        'Chicago Bulls'          => 4,
        'Cleveland Cavaliers'    => 5,
        'Dallas Mavericks'       => 6,
        'Denver Nuggets'         => 7,
        'Detroit Pistons'        => 8,
        'Golden State Warriors'  => 9,
        'Houston Rockets'        => 10,
        'Indiana Pacers'         => 11,
        'Los Angeles Clippers'   => 12,
        'LA Clippers'            => 12,
        'Los Angeles Lakers'     => 13,
        'Memphis Grizzlies'      => 29,
        'Miami Heat'             => 14,
        'Milwaukee Bucks'        => 15,
        'Minnesota Timberwolves' => 16,
        'New Orleans Pelicans'   => 3,
        'New York Knicks'        => 18,
        'Oklahoma City Thunder'  => 25,
        'Orlando Magic'          => 19,
        'Philadelphia 76ers'     => 20,
        'Phoenix Suns'           => 21,
        'Portland Trail Blazers' => 22,
        'Sacramento Kings'       => 23,
        'San Antonio Spurs'      => 24,
        'Toronto Raptors'        => 28,
        'Utah Jazz'              => 26,
        'Washington Wizards'     => 27
    ];
    return $espnMap[$teamName] ?? null;
}

/**
 * Normalize player name for fuzzy matching (strips accents, lowercases, removes non-alpha)
 */
function normalizeForMatch($name) {
    if (function_exists('transliterator_transliterate')) {
        $name = transliterator_transliterate('Any-Latin; Latin-ASCII', $name);
    } elseif (function_exists('iconv')) {
        $name = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    }
    return strtolower(trim(preg_replace('/[^a-z ]/', '', strtolower($name))));
}

/**
 * Fetch roster from ESPN API with 1-hour cache
 */
function fetchEspnRoster($teamName) {
    $espnId = getEspnTeamId($teamName);
    if (!$espnId) return null;

    // Cache setup
    $cacheDir  = '/tmp/espn_cache';
    $cacheFile = $cacheDir . '/roster_' . $espnId . '.json';

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    // Return cached data if fresh (< 1 hour)
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached) return $cached;
    }

    // Fetch from ESPN API
    $response = null;
    if (function_exists('curl_init')) {
        $url = "https://site.api.espn.com/apis/site/v2/sports/basketball/nba/teams/{$espnId}/roster";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $response = null;
        }
    }

    if (!$response) return null;

    $data = json_decode($response, true);
    if (!$data) return null;

    // Parse player data
    $players = [];
    foreach ($data['athletes'] ?? [] as $p) {
        if (!isset($p['displayName']) && !isset($p['fullName'])) continue;

        // Position
        $pos = '';
        if (isset($p['position']) && is_array($p['position'])) {
            $pos = $p['position']['abbreviation'] ?? '';
        } elseif (isset($p['position']) && is_string($p['position'])) {
            $pos = $p['position'];
        }

        // Experience
        $exp = 'R';
        if (isset($p['experience'])) {
            if (is_array($p['experience'])) {
                $exp = $p['experience']['years'] ?? 'R';
            } elseif (is_numeric($p['experience'])) {
                $exp = $p['experience'] > 0 ? $p['experience'] : 'R';
            }
        }

        // Headshot
        $headshot = '';
        if (isset($p['headshot']['href'])) {
            $headshot = $p['headshot']['href'];
        } elseif (isset($p['headshot']) && is_string($p['headshot'])) {
            $headshot = $p['headshot'];
        }

        // College
        $college = '';
        if (isset($p['college']['name'])) {
            $college = $p['college']['name'];
        } elseif (isset($p['college']) && is_string($p['college'])) {
            $college = $p['college'];
        }

        $players[] = [
            'espn_id'    => $p['id'] ?? '',
            'name'       => $p['displayName'] ?? $p['fullName'] ?? 'Unknown',
            'jersey'     => $p['jersey'] ?? '',
            'position'   => $pos,
            'age'        => $p['age'] ?? '',
            'height'     => $p['displayHeight'] ?? '',
            'weight'     => $p['displayWeight'] ?? '',
            'experience' => $exp,
            'headshot'   => $headshot,
            'college'    => $college
        ];
    }

    // Cache the result
    if (!empty($players)) {
        @file_put_contents($cacheFile, json_encode($players));
    }

    return $players;
}

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


// ==========================================================================
// DATA QUERIES
// ==========================================================================

// ------ Basic Team Record ------
try {
    $stmt = $pdo->prepare("SELECT name, win, loss, streak, winstreak FROM 2025_2026 WHERE name = ?");
    $stmt->execute([$team_name]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$team) {
        die("Team not found: " . htmlspecialchars($team_name));
    }

    $team['logo']    = getTeamLogo($team['name']);
    $win_percentage  = ($team['win'] + $team['loss'] > 0)
                       ? ($team['win'] / ($team['win'] + $team['loss'])) * 100
                       : 0;
    $games_played    = $team['win'] + $team['loss'];
    $games_remaining = 82 - $games_played;
    $current_pace    = $games_played > 0 ? ($team['win'] / $games_played) * 82 : 0;

} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

// ------ Team Statistics (via TeamStatsCalculator) ------
require_once '/data/www/default/nba-wins-platform/core/TeamStatsCalculator.php';

$statsCalculator = new TeamStatsCalculator($pdo);
$liveStats       = $statsCalculator->getTeamStats($team_name);
$statsError      = null;

if (!$liveStats || $liveStats['GP'] == 0) {
    $liveStats  = null;
    $statsError = "Stats will be available after the first regular season game";
} else {
    // Calculate shooting percentages from raw totals
    if (isset($liveStats['FGM'], $liveStats['FGA']) && $liveStats['FGA'] > 0) {
        $liveStats['FG_PCT'] = $liveStats['FGM'] / $liveStats['FGA'];
    }
    if (isset($liveStats['FG3M'], $liveStats['FG3A']) && $liveStats['FG3A'] > 0) {
        $liveStats['FG3_PCT'] = $liveStats['FG3M'] / $liveStats['FG3A'];
    }
    if (isset($liveStats['FTM'], $liveStats['FTA']) && $liveStats['FTA'] > 0) {
        $liveStats['FT_PCT'] = $liveStats['FTM'] / $liveStats['FTA'];
    }
}

// ------ Last 5 Games ------
$lastGames = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM (
            SELECT 
                g.date AS game_date,
                g.home_team,
                g.away_team,
                g.home_team_code,
                g.away_team_code,
                g.home_points,
                g.away_points,
                CASE 
                    WHEN g.home_team = ? THEN 'home' 
                    WHEN g.away_team = ? THEN 'away' 
                END AS team_location,
                CASE 
                    WHEN g.home_team = ? THEN g.away_team 
                    WHEN g.away_team = ? THEN g.home_team 
                END AS opponent,
                CASE 
                    WHEN (g.home_team = ? AND g.home_points > g.away_points) 
                      OR (g.away_team = ? AND g.away_points > g.home_points) THEN 'W'
                    WHEN g.home_points IS NOT NULL THEN 'L'
                    ELSE NULL 
                END AS result
            FROM games g
            WHERE (g.home_team = ? OR g.away_team = ?)
              AND g.status_long IN ('Final', 'Finished')
              AND g.date >= '2025-10-21'
            ORDER BY g.date DESC
            LIMIT 5
        ) AS recent_games
        ORDER BY game_date ASC
    ");
    $stmt->execute([
        $team_name, $team_name, $team_name, $team_name,
        $team_name, $team_name, $team_name, $team_name
    ]);
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

} catch (Exception $e) {
    error_log("Error fetching last games: " . $e->getMessage());
}

// ------ Upcoming 5 Games ------
$upcomingGames = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            g.date AS game_date,
            g.home_team,
            g.away_team,
            g.home_team_code,
            g.away_team_code,
            CASE 
                WHEN g.home_team = ? THEN 'home' 
                WHEN g.away_team = ? THEN 'away' 
            END AS team_location,
            CASE 
                WHEN g.home_team = ? THEN g.away_team 
                WHEN g.away_team = ? THEN g.home_team 
            END AS opponent
        FROM games g
        WHERE (g.home_team = ? OR g.away_team = ?)
          AND g.status_long = 'Scheduled'
          AND g.date >= '2025-10-21'
        ORDER BY g.date ASC
        LIMIT 5
    ");
    $stmt->execute([
        $team_name, $team_name, $team_name, $team_name,
        $team_name, $team_name
    ]);
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

} catch (Exception $e) {
    error_log("Error fetching upcoming games: " . $e->getMessage());
}

// ------ Full Schedule (only loaded on schedule tab) ------
$fullSchedule = null;
if (isset($_GET['tab']) && $_GET['tab'] === 'schedule') {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                g.date AS game_date,
                g.home_team,
                g.away_team,
                g.home_team_code,
                g.away_team_code,
                g.home_points,
                g.away_points,
                g.status_long,
                CASE 
                    WHEN g.home_team = ? THEN 'home' 
                    WHEN g.away_team = ? THEN 'away' 
                END AS team_location,
                CASE 
                    WHEN g.home_team = ? THEN g.away_team 
                    WHEN g.away_team = ? THEN g.home_team 
                END AS opponent,
                CASE 
                    WHEN (g.home_team = ? AND g.home_points > g.away_points) 
                      OR (g.away_team = ? AND g.away_points > g.home_points) THEN 'W'
                    WHEN g.home_points IS NOT NULL THEN 'L'
                    ELSE NULL 
                END AS result
            FROM games g
            WHERE (g.home_team = ? OR g.away_team = ?)
              AND g.date >= '2025-10-21'
            ORDER BY g.date ASC
        ");
        $stmt->execute([
            $team_name, $team_name, $team_name, $team_name,
            $team_name, $team_name, $team_name, $team_name
        ]);
        $fullSchedule = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Enrich each game with owner info, running record, and top scorers
        foreach ($fullSchedule as $index => &$game) {
            // Opponent owner
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

            // For completed games: running record + top scorers
            if (in_array($game['status_long'], ['Final', 'Finished'])) {

                // Calculate running record
                $wins   = 0;
                $losses = 0;
                for ($i = 0; $i <= $index; $i++) {
                    if (in_array($fullSchedule[$i]['status_long'], ['Final', 'Finished'])) {
                        if ($fullSchedule[$i]['result'] === 'W') $wins++;
                        elseif ($fullSchedule[$i]['result'] === 'L') $losses++;
                    }
                }
                $game['record'] = $wins . '-' . $losses;

                // Handle Clippers name variants for stats lookup
                $userTeam     = ($game['team_location'] === 'home') ? $game['home_team'] : $game['away_team'];
                $opponentTeam = $game['opponent'];

                $userTeamVariants = [$userTeam];
                $oppTeamVariants  = [$opponentTeam];

                if (strpos($userTeam, 'Clippers') !== false) {
                    $userTeamVariants = ['LA Clippers', 'Los Angeles Clippers'];
                }
                if (strpos($opponentTeam, 'Clippers') !== false) {
                    $oppTeamVariants = ['LA Clippers', 'Los Angeles Clippers'];
                }

                // User team top scorer
                $placeholders = implode(',', array_fill(0, count($userTeamVariants), '?'));
                $ts = $pdo->prepare("
                    SELECT player_name, points 
                    FROM game_player_stats 
                    WHERE team_name IN ($placeholders) AND game_date = ? 
                    ORDER BY points DESC 
                    LIMIT 1
                ");
                $ts->execute(array_merge($userTeamVariants, [$game['game_date']]));
                $game['user_top_scorer'] = $ts->fetch(PDO::FETCH_ASSOC);

                // Opponent top scorer
                $placeholders = implode(',', array_fill(0, count($oppTeamVariants), '?'));
                $os = $pdo->prepare("
                    SELECT player_name, points 
                    FROM game_player_stats 
                    WHERE team_name IN ($placeholders) AND game_date = ? 
                    ORDER BY points DESC 
                    LIMIT 1
                ");
                $os->execute(array_merge($oppTeamVariants, [$game['game_date']]));
                $game['opp_top_scorer'] = $os->fetch(PDO::FETCH_ASSOC);
            }
        }
        unset($game);

    } catch (Exception $e) {
        error_log("Error fetching full schedule: " . $e->getMessage());
    }
}

// ------ Roster (only loaded on roster tab) ------
$roster = null;
if (isset($_GET['tab']) && $_GET['tab'] === 'roster') {

    // 1. Fetch ESPN roster
    $espnRoster = fetchEspnRoster($team_name);

    // 2. Get DB stats from team_roster_stats
    $dbStats = [];
    try {
        if (strpos($team_name, 'Clippers') !== false) {
            $stmt = $pdo->prepare("
                SELECT trs.player_name, trs.games_played, trs.avg_minutes, trs.avg_points,
                       trs.avg_rebounds, trs.avg_assists, trs.avg_fg_made, trs.avg_fg_attempts,
                       trs.fg_percentage
                FROM team_roster_stats trs
                WHERE trs.current_team_name = 'LA Clippers' 
                   OR trs.current_team_name = 'Los Angeles Clippers'
            ");
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("
                SELECT trs.player_name, trs.games_played, trs.avg_minutes, trs.avg_points,
                       trs.avg_rebounds, trs.avg_assists, trs.avg_fg_made, trs.avg_fg_attempts,
                       trs.fg_percentage
                FROM team_roster_stats trs
                WHERE trs.current_team_name = ?
            ");
            $stmt->execute([$team_name]);
        }

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dbStats[strtolower(trim($row['player_name']))] = $row;
        }
    } catch (Exception $e) {
        error_log("DB roster stats error: " . $e->getMessage());
    }

    // Build normalized lookup for accent-insensitive matching
    $dbStatsNormalized = [];
    foreach ($dbStats as $key => $row) {
        $nk = normalizeForMatch($key);
        if (!isset($dbStatsNormalized[$nk])) {
            $dbStatsNormalized[$nk] = $row;
        }
    }

    // 3. Fallback: aggregate from game_player_stats
    $gpsFallback = [];
    try {
        $teamVariants = [$team_name];
        if (strpos($team_name, 'Clippers') !== false) {
            $teamVariants = ['LA Clippers', 'Los Angeles Clippers'];
        }

        $placeholders = implode(',', array_fill(0, count($teamVariants), '?'));
        $gs = $pdo->prepare("
            SELECT 
                player_name,
                COUNT(*) AS games_played,
                ROUND(AVG(minutes), 1) AS avg_minutes,
                ROUND(AVG(points), 1) AS avg_points,
                ROUND(AVG(rebounds), 1) AS avg_rebounds,
                ROUND(AVG(assists), 1) AS avg_assists,
                ROUND(AVG(fg_made), 1) AS avg_fg_made,
                ROUND(AVG(fg_attempts), 1) AS avg_fg_attempts,
                CASE WHEN SUM(fg_attempts) > 0 
                     THEN ROUND(SUM(fg_made) / SUM(fg_attempts) * 100, 1) 
                     ELSE 0 
                END AS fg_percentage
            FROM game_player_stats
            WHERE team_name IN ($placeholders) AND game_date >= '2025-10-20'
            GROUP BY player_name
        ");
        $gs->execute($teamVariants);

        foreach ($gs->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $gpsFallback[normalizeForMatch($row['player_name'])] = $row;
        }
    } catch (Exception $e) {
        error_log("game_player_stats fallback error: " . $e->getMessage());
    }

    // 4. Merge ESPN roster with DB stats
    if ($espnRoster) {
        $mergedRoster = [];

        foreach ($espnRoster as $ep) {
            $key   = strtolower(trim($ep['name']));
            $stats = $dbStats[$key] ?? null;

            // Try alpha-only match
            if (!$stats) {
                $cleanKey = strtolower(preg_replace('/[^a-z ]/', '', strtolower($ep['name'])));
                foreach ($dbStats as $dk => $dr) {
                    $cleanDbKey = strtolower(preg_replace('/[^a-z ]/', '', $dk));
                    if ($cleanDbKey === $cleanKey) {
                        $stats = $dr;
                        break;
                    }
                }
            }

            // Try normalized (accent-insensitive) match
            if (!$stats) {
                $nk = normalizeForMatch($ep['name']);
                if (isset($dbStatsNormalized[$nk])) {
                    $stats = $dbStatsNormalized[$nk];
                }
            }

            // Try direct DB lookup by exact name
            if (!$stats) {
                try {
                    $fs = $pdo->prepare("
                        SELECT player_name, games_played, avg_minutes, avg_points,
                               avg_rebounds, avg_assists, avg_fg_made, avg_fg_attempts, fg_percentage
                        FROM team_roster_stats
                        WHERE player_name = ?
                        LIMIT 1
                    ");
                    $fs->execute([$ep['name']]);
                    $fr = $fs->fetch(PDO::FETCH_ASSOC);
                    if ($fr) $stats = $fr;
                } catch (Exception $e) {
                    // Silently continue
                }
            }

            // Try game_player_stats fallback
            if (!$stats) {
                $nk = normalizeForMatch($ep['name']);
                if (isset($gpsFallback[$nk])) {
                    $stats = $gpsFallback[$nk];
                }
            }

            $mergedRoster[] = [
                'name'          => $ep['name'],
                'espn_id'       => $ep['espn_id'],
                'jersey'        => $ep['jersey'],
                'position'      => $ep['position'],
                'age'           => $ep['age'],
                'height'        => $ep['height'],
                'weight'        => $ep['weight'],
                'experience'    => $ep['experience'],
                'headshot'      => $ep['headshot'],
                'college'       => $ep['college'],
                'games_played'  => $stats['games_played'] ?? 0,
                'avg_minutes'   => $stats['avg_minutes'] ?? 0,
                'avg_points'    => $stats['avg_points'] ?? 0,
                'avg_rebounds'  => $stats['avg_rebounds'] ?? 0,
                'avg_assists'   => $stats['avg_assists'] ?? 0,
                'fg_percentage' => $stats['fg_percentage'] ?? 0,
                'has_stats'     => $stats !== null
            ];
        }

        // Sort by PPG descending
        usort($mergedRoster, function ($a, $b) {
            return $b['avg_points'] <=> $a['avg_points'];
        });

        $roster = ['success' => true, 'source' => 'espn', 'data' => $mergedRoster];

    } elseif (!empty($dbStats)) {
        // ESPN unavailable — use DB-only data
        $dbOnly = array_values($dbStats);
        usort($dbOnly, function ($a, $b) {
            return $b['avg_points'] <=> $a['avg_points'];
        });
        $roster = ['success' => true, 'source' => 'database', 'data' => $dbOnly];

    } else {
        $roster = ['error' => 'No roster data available'];
    }
}

// ------ Draft Info ------
$draft_info = null;
try {
    $stmt = $pdo->prepare("
        SELECT ds.id, ds.status, ds.completed_at 
        FROM draft_sessions ds 
        WHERE ds.league_id = ? AND ds.status = 'completed' 
        ORDER BY ds.created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$league_id]);
    $draft_session = $stmt->fetch();

    if ($draft_session) {
        $stmt = $pdo->prepare("
            SELECT 
                dp.pick_number,
                dp.round_number,
                dp.picked_at,
                dp.picked_by_commissioner,
                lp.participant_name,
                u.display_name,
                (dp.pick_number - 1) % (
                    SELECT COUNT(DISTINCT league_participant_id) 
                    FROM draft_picks 
                    WHERE draft_session_id = ?
                ) + 1 AS position_in_round
            FROM draft_picks dp
            JOIN nba_teams nt ON dp.team_id = nt.id
            JOIN league_participants lp ON dp.league_participant_id = lp.id
            JOIN users u ON lp.user_id = u.id
            WHERE dp.draft_session_id = ? AND nt.name = ?
            LIMIT 1
        ");
        $stmt->execute([$draft_session['id'], $draft_session['id'], $team_name]);
        $draft_info = $stmt->fetch();
    }
} catch (Exception $e) {
    error_log("Error fetching draft info: " . $e->getMessage());
}

// Active tab
$activeTab = $_GET['tab'] ?? 'home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="theme-color" content="<?= ($_SESSION['theme_preference'] ?? 'dark') === 'classic' ? '#f5f5f5' : '#121a23' ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($team['name']) ?> - Team Data</title>
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
    --accent-green-dim: rgba(63, 185, 80, 0.12);
    --accent-red: #f85149;
    --accent-red-dim: rgba(248, 81, 73, 0.12);
    --accent-orange: #d29922;
    --accent-teal: #76a5af;
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
.app-container { max-width: 900px; margin: 0 auto; padding: 0 12px 2rem; }

.app-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 16px 16px 12px;
    position: relative;
}
.app-header-logo { width: 36px; height: 36px; }
.app-header-title { font-size: 1.35rem; font-weight: 700; letter-spacing: -0.02em; }

.nav-toggle-btn {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    width: 38px; height: 38px;
    display: flex; align-items: center; justify-content: center;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    color: var(--text-secondary);
    font-size: 16px;
    cursor: pointer;
    transition: all var(--transition-fast);
}
.nav-toggle-btn:hover {
    color: var(--text-primary);
    border-color: rgba(56, 139, 253, 0.3);
    background: var(--accent-blue-dim);
}

/* ==========================================================================
   TEAM HERO
   ========================================================================== */
.team-hero {
    position: relative;
    padding: 1.5rem;
    background: var(--bg-card);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-card);
    margin-bottom: 14px;
    overflow: hidden;
    min-height: 130px;
    display: flex;
    align-items: center;
}
.team-hero-bg {
    position: absolute;
    width: 200px; height: 200px;
    object-fit: contain;
    opacity: 0.08;
    z-index: 1;
    pointer-events: none;
    left: 50%; top: 50%;
    transform: translate(-50%, -50%);
}
.team-hero-content {
    position: relative; z-index: 2;
    display: flex; align-items: center;
    gap: 1.25rem; width: 100%;
}
.team-hero-logo {
    width: 80px; height: 80px;
    object-fit: contain;
    filter: drop-shadow(0 2px 6px rgba(0, 0, 0, 0.3));
    flex-shrink: 0;
}
.team-hero-info { flex: 1; }
.team-hero-name { font-size: 1.6rem; font-weight: 800; margin-bottom: 2px; line-height: 1.2; }
.team-hero-draft { font-size: 0.85rem; color: var(--text-secondary); line-height: 1.4; }
.team-hero-draft .commissioner { color: var(--accent-orange); }

/* ==========================================================================
   TABS
   ========================================================================== */
.tabs {
    display: flex; gap: 6px;
    margin-bottom: 14px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 2px;
}
.tab {
    padding: 8px 18px;
    border-radius: var(--radius-md);
    color: var(--text-secondary);
    text-decoration: none;
    font-weight: 600;
    font-size: 0.88rem;
    transition: all 0.2s;
    white-space: nowrap;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    display: flex; align-items: center; gap: 6px;
}
.tab:hover {
    color: var(--text-primary);
    border-color: rgba(56, 139, 253, 0.2);
    background: var(--bg-elevated);
}
.tab.active {
    background: var(--accent-blue);
    color: white;
    border-color: var(--accent-blue);
}
.tab i { font-size: 0.8rem; }

/* ==========================================================================
   SECTIONS
   ========================================================================== */
.section {
    background: var(--bg-card);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-card);
    padding: 1.25rem;
    margin-bottom: 14px;
    overflow: hidden;
}
.section-title {
    font-size: 1.1rem; font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 1rem;
    padding-bottom: 0.6rem;
    border-bottom: 1px solid var(--border-color);
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.section-title a {
    margin-left: auto;
    font-size: 0.82rem;
    color: var(--accent-blue);
    text-decoration: none;
    font-weight: 500;
}
.section-title .source {
    font-size: 0.72rem;
    color: var(--text-muted);
    font-weight: 400;
    margin-left: auto;
}

.sub-heading {
    font-size: 0.95rem; font-weight: 700;
    color: var(--text-secondary);
    margin: 16px 0 10px;
    display: flex; align-items: center; gap: 8px;
}
.sub-heading i { color: var(--text-muted); }

/* ==========================================================================
   STATS GRID
   ========================================================================== */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 10px;
    margin-bottom: 16px;
}
.stat-card {
    background: var(--bg-elevated);
    border-radius: var(--radius-md);
    padding: 14px 10px;
    text-align: center;
    border: 1px solid var(--border-color);
    transition: all 0.2s;
}
.stat-card:hover { border-color: rgba(255, 255, 255, 0.1); }
.stat-card.green { border-left: 3px solid var(--accent-green); background: var(--accent-green-dim); }
.stat-card.red   { border-left: 3px solid var(--accent-red);   background: var(--accent-red-dim); }
.stat-card.blue  { border-left: 3px solid var(--accent-blue);  background: var(--accent-blue-dim); }

.stat-value {
    font-size: 1.5rem; font-weight: 800;
    color: var(--text-primary);
    line-height: 1;
    font-variant-numeric: tabular-nums;
    margin-bottom: 4px;
}
.stat-label {
    font-size: 0.72rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-weight: 600;
    display: flex; align-items: center; justify-content: center; gap: 5px;
}
.stat-sub { font-size: 0.68rem; color: var(--text-muted); margin-top: 3px; }

/* ==========================================================================
   GAME LIST
   ========================================================================== */
.game-list-item {
    display: flex; flex-direction: column;
    padding: 0.85rem;
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s;
    text-decoration: none;
    color: inherit;
}
.game-list-item:last-child { border-bottom: none; }
.game-list-item:hover { background: var(--bg-card-hover); }
.game-list-row { display: flex; justify-content: space-between; align-items: center; }
.game-list-date { font-size: 0.78rem; color: var(--text-muted); margin-bottom: 2px; }
.game-list-matchup {
    font-weight: 600; font-size: 0.92rem;
    display: flex; align-items: center; gap: 5px; flex-wrap: wrap;
}
.game-list-matchup img { width: 18px; height: 18px; object-fit: contain; }
.game-list-owner { font-size: 0.78rem; color: var(--text-muted); font-weight: 400; font-style: italic; }
.game-list-score { font-size: 1rem; font-weight: 700; font-variant-numeric: tabular-nums; }
.game-list-outcome { font-size: 0.85rem; font-weight: 700; margin-left: 6px; }
.game-list-outcome.w { color: var(--accent-green); }
.game-list-outcome.l { color: var(--accent-red); }
.game-list-record { font-size: 0.78rem; color: var(--text-muted); font-weight: 400; margin-left: 6px; }

.top-scorers {
    font-size: 0.75rem; color: var(--text-muted);
    margin-top: 6px; padding-top: 6px;
    border-top: 1px solid var(--border-color);
    display: flex; gap: 1.25rem; flex-wrap: wrap;
}
.scorer-pts { font-weight: 700; color: var(--text-secondary); }

/* ==========================================================================
   ROSTER TABLE
   ========================================================================== */
.roster-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    margin-top: 0.75rem;
}
table.roster {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88rem;
    min-width: 560px;
    font-variant-numeric: tabular-nums;
}
table.roster thead {
    background: var(--bg-elevated);
    position: sticky; top: 0; z-index: 1;
}
table.roster th {
    padding: 9px 10px;
    text-align: left;
    font-weight: 600;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-muted);
    white-space: nowrap;
}
table.roster th.center { text-align: center; }
table.roster td {
    padding: 9px 10px;
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}
table.roster tbody tr { transition: background 0.15s; }
table.roster tbody tr:hover { background: var(--bg-card-hover); }
table.roster tbody tr:last-child td { border-bottom: none; }
td.center { text-align: center; }

.ppg-val { font-weight: 700; color: var(--accent-teal); }

.headshot {
    width: 32px; height: 32px;
    border-radius: 50%;
    object-fit: cover;
    background: var(--bg-elevated);
}
.headshot-fb {
    width: 32px; height: 32px;
    border-radius: 50%;
    background: var(--bg-elevated);
    display: flex; align-items: center; justify-content: center;
    color: var(--text-muted);
    font-size: 0.75rem;
}

.player-name-cell { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; }
.jersey-num { font-weight: 600; color: var(--text-muted); font-size: 0.82rem; min-width: 24px; }
.player-link {
    color: var(--text-primary);
    text-decoration: none;
    font-weight: 600;
    border-bottom: 1px dotted var(--text-muted);
    transition: color 0.2s;
}
.player-link:hover { color: var(--accent-blue); border-bottom-color: var(--accent-blue); }

.pos-badge {
    display: inline-block;
    background: var(--accent-blue-dim);
    color: var(--accent-blue);
    padding: 1px 6px;
    border-radius: 3px;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.02em;
}
.player-college { font-size: 0.7rem; color: var(--text-muted); margin-top: 1px; }

/* ==========================================================================
   UTILITIES
   ========================================================================== */
.no-data {
    text-align: center;
    padding: 2rem 1rem;
    color: var(--text-muted);
    font-size: 0.95rem;
}

/* ==========================================================================
   MOBILE RESPONSIVE
   ========================================================================== */
@media (max-width: 600px) {
    .app-container { padding: 0 8px 2rem; }

    .team-hero { padding: 1rem; }
    .team-hero-bg { width: 150px; height: 150px; }
    .team-hero-logo { width: 56px; height: 56px; }
    .team-hero-name { font-size: 1.2rem; }
    .team-hero-draft { font-size: 0.78rem; }
    .team-hero-content { gap: 0.75rem; }

    .tabs { gap: 4px; }
    .tab { padding: 6px 12px; font-size: 0.8rem; }

    .section { padding: 1rem; }

    .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 8px; }
    .stat-value { font-size: 1.2rem; }
    .stat-label { font-size: 0.65rem; }

    .game-list-item { padding: 0.65rem 0.5rem; }
    .game-list-matchup { font-size: 0.82rem; }
    .game-list-matchup img { width: 16px; height: 16px; }
    .game-list-score { font-size: 0.9rem; }
    .top-scorers { flex-direction: column; gap: 4px; font-size: 0.7rem; }

    table.roster { font-size: 0.75rem; min-width: 480px; }
    table.roster th { padding: 6px 4px; font-size: 0.62rem; }
    table.roster td { padding: 6px 4px; }
    .headshot, .headshot-fb { width: 26px; height: 26px; }
    .player-college { display: none; }
    .jersey-num { font-size: 0.7rem; min-width: auto; }
    .player-link { font-size: 0.78rem; }
    .pos-badge { font-size: 0.55rem; padding: 0 3px; }

    .section-title { font-size: 0.95rem; }
    .section-title a { font-size: 0.75rem; }
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
         TEAM HERO
         ================================================================ -->
    <div class="team-hero">
        <img src="<?= htmlspecialchars($team['logo']) ?>" alt="" class="team-hero-bg" onerror="this.style.display='none'">
        <div class="team-hero-content">
            <img src="<?= htmlspecialchars($team['logo']) ?>" alt="<?= htmlspecialchars($team['name']) ?>"
                 class="team-hero-logo" onerror="this.style.opacity='0.3'">
            <div class="team-hero-info">
                <div class="team-hero-name"><?= htmlspecialchars($team['name']) ?></div>
                <?php if ($draft_info): ?>
                    <div class="team-hero-draft">
                        Round <?= $draft_info['round_number'] ?>, Pick <?= $draft_info['position_in_round'] ?>
                        (Overall #<?= $draft_info['pick_number'] ?>)<br>
                        Drafted by <?= htmlspecialchars($draft_info['display_name']) ?>
                        <?php if ($draft_info['picked_by_commissioner']): ?>
                            <span class="commissioner">(Commissioner Pick)</span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="team-hero-draft">Draft information not available</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ================================================================
         TABS
         ================================================================ -->
    <div class="tabs">
        <a href="?team=<?= urlencode($team['name']) ?>&tab=home"
           class="tab <?= $activeTab === 'home' ? 'active' : '' ?>">
            <i class="fas fa-home"></i> Home
        </a>
        <a href="?team=<?= urlencode($team['name']) ?>&tab=roster"
           class="tab <?= $activeTab === 'roster' ? 'active' : '' ?>">
            <i class="fas fa-users"></i> Roster
        </a>
        <a href="?team=<?= urlencode($team['name']) ?>&tab=schedule"
           class="tab <?= $activeTab === 'schedule' ? 'active' : '' ?>">
            <i class="fas fa-calendar"></i> Schedule
        </a>
    </div>


    <!-- ================================================================
         HOME TAB
         ================================================================ -->
    <?php if ($activeTab === 'home'): ?>

        <!-- Season Record -->
        <div class="section">
            <h2 class="section-title"><i class="fas fa-trophy"></i> Season Record</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= $team['win'] ?>-<?= $team['loss'] ?></div>
                    <div class="stat-label">Record</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($win_percentage, 1) ?>%</div>
                    <div class="stat-label">Win %</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $games_remaining ?></div>
                    <div class="stat-label">Games Left</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($current_pace, 1) ?></div>
                    <div class="stat-label">Win Pace</div>
                </div>
                <?php
                $streak    = $team['streak'] ?? 0;
                $winstreak = $team['winstreak'] ?? 0;
                if ($streak != 0 || $winstreak != 0):
                    $isWin     = ($winstreak == 1);
                    $streakLen = abs($streak);
                    $cardClass = $isWin ? 'green' : ($streakLen >= 10 ? 'red' : 'blue');
                    $icon      = $isWin ? 'fa-fire' : 'fa-snowflake';
                ?>
                    <div class="stat-card <?= $cardClass ?>">
                        <div class="stat-value"><?= $streakLen ?></div>
                        <div class="stat-label">
                            <i class="fa-solid <?= $icon ?>"></i>
                            <?= $isWin ? 'Win Streak' : 'Loss Streak' ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Team Statistics -->
        <?php if ($liveStats && $liveStats['GP'] > 0): ?>
            <div class="section">
                <h2 class="section-title"><i class="fas fa-basketball-ball"></i> Team Statistics</h2>

                <h3 class="sub-heading"><i class="fas fa-bullseye"></i> Shooting</h3>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?= number_format($liveStats['PTS'], 1) ?></div>
                        <div class="stat-label">PPG</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= number_format($liveStats['FG_PCT'] * 100, 1) ?>%</div>
                        <div class="stat-label">FG%</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= number_format($liveStats['FG3_PCT'] * 100, 1) ?>%</div>
                        <div class="stat-label">3PT%</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= number_format($liveStats['FT_PCT'] * 100, 1) ?>%</div>
                        <div class="stat-label">FT%</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= number_format($liveStats['FG3M'], 1) ?></div>
                        <div class="stat-label">3PM</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= number_format($liveStats['FG3A'], 1) ?></div>
                        <div class="stat-label">3PA</div>
                    </div>
                </div>

                <h3 class="sub-heading"><i class="fas fa-chart-line"></i> Core Stats</h3>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?= number_format($liveStats['REB'], 1) ?></div>
                        <div class="stat-label">RPG</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= number_format($liveStats['AST'], 1) ?></div>
                        <div class="stat-label">APG</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= number_format($liveStats['STL'], 1) ?></div>
                        <div class="stat-label">SPG</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= number_format($liveStats['BLK'], 1) ?></div>
                        <div class="stat-label">BPG</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= number_format($liveStats['TOV'], 1) ?></div>
                        <div class="stat-label">TOV</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value" style="color: <?= $liveStats['PLUS_MINUS'] >= 0 ? 'var(--accent-green)' : 'var(--accent-red)' ?>">
                            <?= ($liveStats['PLUS_MINUS'] >= 0 ? '+' : '') . number_format($liveStats['PLUS_MINUS'], 1) ?>
                        </div>
                        <div class="stat-label">+/-</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Last 5 Games -->
        <div class="section">
            <h2 class="section-title">
                <i class="fas fa-history"></i> Last 5 Games
                <a href="?team=<?= urlencode($team['name']) ?>&tab=schedule">Full Schedule →</a>
            </h2>
            <?php if (!empty($lastGames)): ?>
                <?php foreach ($lastGames as $gd_game):
                    $teamScore = ($gd_game['team_location'] === 'home') ? $gd_game['home_points'] : $gd_game['away_points'];
                    $oppScore  = ($gd_game['team_location'] === 'home') ? $gd_game['away_points'] : $gd_game['home_points'];
                    $gameUrl   = "/nba-wins-platform/stats/game_details_new.php"
                               . "?home_team=" . urlencode($gd_game['home_team_code'])
                               . "&away_team=" . urlencode($gd_game['away_team_code'])
                               . "&date=" . urlencode($gd_game['game_date']);
                ?>
                    <a href="<?= $gameUrl ?>" class="game-list-item">
                        <div class="game-list-row">
                            <div>
                                <div class="game-list-date"><?= date('M j, Y', strtotime($gd_game['game_date'])) ?></div>
                                <div class="game-list-matchup">
                                    <?= $gd_game['team_location'] === 'home' ? 'vs' : '@' ?>
                                    <img src="<?= htmlspecialchars(getTeamLogo($gd_game['opponent'])) ?>" alt=""
                                         onerror="this.style.display='none'">
                                    <?= htmlspecialchars($gd_game['opponent']) ?>
                                    <?php if (!empty($gd_game['opponent_owner'])): ?>
                                        <span class="game-list-owner">(<?= htmlspecialchars($gd_game['opponent_owner']) ?>)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="text-align: right">
                                <span class="game-list-score"><?= $teamScore . '-' . $oppScore ?></span>
                                <span class="game-list-outcome <?= strtolower($gd_game['result']) ?>">
                                    <?= $gd_game['result'] ?>
                                </span>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-data">No recent games to display</div>
            <?php endif; ?>
        </div>

        <!-- Upcoming 5 Games -->
        <div class="section">
            <h2 class="section-title"><i class="fas fa-calendar-alt"></i> Upcoming 5 Games</h2>
            <?php if (!empty($upcomingGames)): ?>
                <?php foreach ($upcomingGames as $gd_game):
                    $compUrl = "/nba-wins-platform/stats/team_comparison_new.php"
                             . "?home_team=" . urlencode($gd_game['home_team_code'])
                             . "&away_team=" . urlencode($gd_game['away_team_code'])
                             . "&date=" . urlencode($gd_game['game_date']);
                ?>
                    <a href="<?= $compUrl ?>" class="game-list-item">
                        <div class="game-list-row">
                            <div>
                                <div class="game-list-date"><?= date('M j, Y', strtotime($gd_game['game_date'])) ?></div>
                                <div class="game-list-matchup">
                                    <?= $gd_game['team_location'] === 'home' ? 'vs' : '@' ?>
                                    <img src="<?= htmlspecialchars(getTeamLogo($gd_game['opponent'])) ?>" alt=""
                                         onerror="this.style.display='none'">
                                    <?= htmlspecialchars($gd_game['opponent']) ?>
                                    <?php if (!empty($gd_game['opponent_owner'])): ?>
                                        <span class="game-list-owner">(<?= htmlspecialchars($gd_game['opponent_owner']) ?>)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-data">No upcoming games scheduled</div>
            <?php endif; ?>
        </div>


    <!-- ================================================================
         ROSTER TAB
         ================================================================ -->
    <?php elseif ($activeTab === 'roster'): ?>

        <div class="section">
            <h2 class="section-title">
                <i class="fas fa-users"></i> Team Roster
                <?php if (isset($roster['source']) && $roster['source'] === 'espn'): ?>
                    <span class="source">via ESPN</span>
                <?php endif; ?>
            </h2>

            <?php if (isset($roster['error'])): ?>
                <div class="no-data"><?= htmlspecialchars($roster['error']) ?></div>

            <?php elseif (isset($roster['success']) && $roster['success'] && !empty($roster['data'])): ?>
                <?php $isEspn = ($roster['source'] ?? '') === 'espn'; ?>
                <div class="roster-wrap">
                    <table class="roster">
                        <thead>
                            <tr>
                                <?php if ($isEspn): ?><th style="width: 38px"></th><?php endif; ?>
                                <th>Player</th>
                                <th class="center">GP</th>
                                <th class="center">MPG</th>
                                <th class="center">PPG</th>
                                <th class="center">RPG</th>
                                <th class="center">APG</th>
                                <th class="center">FG%</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($roster['data'] as $gd_player): ?>
                            <tr>
                                <?php if ($isEspn): ?>
                                    <td style="width: 38px; padding: 6px">
                                        <?php if (!empty($gd_player['headshot'])): ?>
                                            <img src="<?= htmlspecialchars($gd_player['headshot']) ?>" alt=""
                                                 class="headshot"
                                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                                            <div class="headshot-fb" style="display: none">
                                                <i class="fas fa-user"></i>
                                            </div>
                                        <?php else: ?>
                                            <div class="headshot-fb"><i class="fas fa-user"></i></div>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>

                                <td>
                                    <div class="player-name-cell">
                                        <?php if ($isEspn && !empty($gd_player['jersey'])): ?>
                                            <span class="jersey-num">#<?= htmlspecialchars($gd_player['jersey']) ?></span>
                                        <?php endif; ?>

                                        <?php
                                        $pName = $gd_player['name'] ?? $gd_player['player_name'] ?? '';
                                        $pEid  = $gd_player['espn_id'] ?? '';
                                        $pUrl  = '/nba-wins-platform/stats/player_profile_new.php'
                                               . '?team=' . urlencode($team_name)
                                               . '&player=' . urlencode($pName)
                                               . ($pEid ? '&espn_id=' . urlencode($pEid) : '');
                                        ?>
                                        <a href="<?= $pUrl ?>" class="player-link">
                                            <?= htmlspecialchars($pName) ?>
                                        </a>

                                        <?php if ($isEspn && !empty($gd_player['position'])): ?>
                                            <span class="pos-badge"><?= htmlspecialchars($gd_player['position']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($isEspn && !empty($gd_player['college'])): ?>
                                        <div class="player-college"><?= htmlspecialchars($gd_player['college']) ?></div>
                                    <?php endif; ?>
                                </td>

                                <td class="center"><?= $gd_player['games_played'] ?? '-' ?></td>
                                <td class="center"><?= ($gd_player['avg_minutes'] ?? 0) > 0 ? number_format($gd_player['avg_minutes'], 1) : '-' ?></td>
                                <td class="center ppg-val"><?= ($gd_player['avg_points'] ?? 0) > 0 ? number_format($gd_player['avg_points'], 1) : '-' ?></td>
                                <td class="center"><?= ($gd_player['avg_rebounds'] ?? 0) > 0 ? number_format($gd_player['avg_rebounds'], 1) : '-' ?></td>
                                <td class="center"><?= ($gd_player['avg_assists'] ?? 0) > 0 ? number_format($gd_player['avg_assists'], 1) : '-' ?></td>
                                <td class="center"><?= ($gd_player['fg_percentage'] ?? 0) > 0 ? number_format($gd_player['fg_percentage'], 1) . '%' : '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-data">Roster data coming soon</div>
            <?php endif; ?>
        </div>


    <!-- ================================================================
         SCHEDULE TAB
         ================================================================ -->
    <?php elseif ($activeTab === 'schedule'): ?>

        <div class="section">
            <h2 class="section-title"><i class="fas fa-calendar"></i> Full Schedule</h2>

            <?php if (!empty($fullSchedule)): ?>
                <?php foreach ($fullSchedule as $gd_game):
                    $isCompleted = in_array($gd_game['status_long'], ['Final', 'Finished']);
                    $teamScore   = ($gd_game['team_location'] === 'home') ? $gd_game['home_points'] : $gd_game['away_points'];
                    $oppScore    = ($gd_game['team_location'] === 'home') ? $gd_game['away_points'] : $gd_game['home_points'];

                    $gameUrl = $isCompleted
                        ? "/nba-wins-platform/stats/game_details_new.php"
                          . "?home_team=" . urlencode($gd_game['home_team_code'])
                          . "&away_team=" . urlencode($gd_game['away_team_code'])
                          . "&date=" . urlencode($gd_game['game_date'])
                        : "/nba-wins-platform/stats/team_comparison_new.php"
                          . "?home_team=" . urlencode($gd_game['home_team_code'])
                          . "&away_team=" . urlencode($gd_game['away_team_code'])
                          . "&date=" . urlencode($gd_game['game_date']);
                ?>
                    <a href="<?= $gameUrl ?>" class="game-list-item">
                        <div class="game-list-row">
                            <div>
                                <div class="game-list-date"><?= date('M j, Y', strtotime($gd_game['game_date'])) ?></div>
                                <div class="game-list-matchup">
                                    <?= $gd_game['team_location'] === 'home' ? 'vs' : '@' ?>
                                    <img src="<?= htmlspecialchars(getTeamLogo($gd_game['opponent'])) ?>" alt=""
                                         onerror="this.style.display='none'">
                                    <?= htmlspecialchars($gd_game['opponent']) ?>
                                    <?php if (!empty($gd_game['opponent_owner'])): ?>
                                        <span class="game-list-owner">(<?= htmlspecialchars($gd_game['opponent_owner']) ?>)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($isCompleted): ?>
                                <div style="text-align: right">
                                    <span class="game-list-score"><?= $teamScore . '-' . $oppScore ?></span>
                                    <span class="game-list-outcome <?= strtolower($gd_game['result']) ?>">
                                        <?= $gd_game['result'] ?>
                                    </span>
                                    <?php if (!empty($gd_game['record'])): ?>
                                        <span class="game-list-record">(<?= $gd_game['record'] ?>)</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($isCompleted && (!empty($gd_game['user_top_scorer']) || !empty($gd_game['opp_top_scorer']))): ?>
                            <div class="top-scorers">
                                <?php if (!empty($gd_game['user_top_scorer'])): ?>
                                    <span>
                                        <?= htmlspecialchars($gd_game['user_top_scorer']['player_name']) ?>:
                                        <span class="scorer-pts"><?= $gd_game['user_top_scorer']['points'] ?> pts</span>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($gd_game['opp_top_scorer'])): ?>
                                    <span>
                                        <?= htmlspecialchars($gd_game['opp_top_scorer']['player_name']) ?>:
                                        <span class="scorer-pts"><?= $gd_game['opp_top_scorer']['points'] ?> pts</span>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-data">No games found in the schedule</div>
            <?php endif; ?>
        </div>

    <?php endif; ?>

</div>
    <nav class="floating-pill">
        <a href="/index_new.php" class="pill-item" data-label="Home"><i class="fas fa-home"></i></a>
        <a href="/nba-wins-platform/profiles/participant_profile_new.php?league_id=<?php echo $currentLeagueId ?? ($_SESSION['current_league_id'] ?? 0); ?>&user_id=<?php echo $profileUserId ?? ($_SESSION['user_id'] ?? 0); ?>" class="pill-item" data-label="Profile"><i class="fas fa-user"></i></a>
        <a href="/analytics_new.php" class="pill-item" data-label="Analytics"><i class="fas fa-chart-line"></i></a>
        <a href="/claudes-column_new.php" class="pill-item" data-label="Column" style="position:relative"><i class="fa-solid fa-newspaper"></i><?php if ($hasNewArticles): ?><span style="position:absolute;top:2px;right:2px;width:7px;height:7px;background:#f85149;border-radius:50%;box-shadow:0 0 4px rgba(248,81,73,0.5)"></span><?php endif; ?></a>
        <div class="pill-divider"></div>
        <button class="pill-item" data-label="Menu" onclick="toggleDarkNav()"><i class="fas fa-bars"></i></button>
    </nav>
</body>
</html>