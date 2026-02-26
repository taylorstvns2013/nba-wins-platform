<?php
header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Add session management
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['current_league_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

// Load database connection and NBA API integration
require_once '/data/www/default/nba-wins-platform/config/db_connection.php';
require_once '/data/www/default/nba-wins-platform/core/nba_api_integration.php';

$user_id = $_SESSION['user_id'];
$league_id = $_SESSION['current_league_id'];
$currentLeagueId = $league_id;

// Get team name and validate
$team_name = $_GET['team'] ?? null;
if (!$team_name) {
    die("No team specified");
}
$team_name = str_replace('+', ' ', $team_name);

// Initialize NBA API integration with short timeout
// NOTE: Currently not being used - API calls are disabled below to prevent 30+ second page loads
$nbaApi = new NBAApiIntegration([
    'python_path' => '/usr/bin/python3',
    'scripts_path' => '/data/www/default/nba-wins-platform/tasks',
    'cache_enabled' => true,
    'cache_timeout' => 300, // 5 minutes
    'api_timeout' => 3 // 3 second timeout for fast page loads
]);

// ESPN Team ID mapping
function getEspnTeamId($teamName) {
    $espnMap = [
        'Atlanta Hawks' => 1, 'Boston Celtics' => 2, 'Brooklyn Nets' => 17,
        'Charlotte Hornets' => 30, 'Chicago Bulls' => 4, 'Cleveland Cavaliers' => 5,
        'Dallas Mavericks' => 6, 'Denver Nuggets' => 7, 'Detroit Pistons' => 8,
        'Golden State Warriors' => 9, 'Houston Rockets' => 10, 'Indiana Pacers' => 11,
        'Los Angeles Clippers' => 12, 'LA Clippers' => 12, 'Los Angeles Lakers' => 13,
        'Memphis Grizzlies' => 29, 'Miami Heat' => 14, 'Milwaukee Bucks' => 15,
        'Minnesota Timberwolves' => 16, 'New Orleans Pelicans' => 3, 'New York Knicks' => 18,
        'Oklahoma City Thunder' => 25, 'Orlando Magic' => 19, 'Philadelphia 76ers' => 20,
        'Phoenix Suns' => 21, 'Portland Trail Blazers' => 22, 'Sacramento Kings' => 23,
        'San Antonio Spurs' => 24, 'Toronto Raptors' => 28, 'Utah Jazz' => 26,
        'Washington Wizards' => 27
    ];
    return $espnMap[$teamName] ?? null;
}

// Normalize player names for matching (strips diacritics: ć→c, ñ→n, etc.)
function normalizeForMatch($name) {
    if (function_exists('transliterator_transliterate')) {
        $name = transliterator_transliterate('Any-Latin; Latin-ASCII', $name);
    } elseif (function_exists('iconv')) {
        $name = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    }
    return strtolower(trim(preg_replace('/[^a-z ]/', '', strtolower($name))));
}

// Fetch roster from ESPN API (cached for 1 hour)
function fetchEspnRoster($teamName) {
    $espnId = getEspnTeamId($teamName);
    if (!$espnId) return null;
    
    // Check file cache (1 hour)
    $cacheDir = '/tmp/espn_cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $cacheFile = $cacheDir . '/roster_' . $espnId . '.json';
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached) return $cached;
    }
    
    $url = "https://site.api.espn.com/apis/site/v2/sports/basketball/nba/teams/{$espnId}/roster";
    
    // Use curl (more reliable than file_get_contents for external HTTPS)
    $response = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            error_log("ESPN roster fetch failed: HTTP $httpCode, curl error: $curlError");
            $response = null;
        }
    } else {
        // Fallback to file_get_contents
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => "User-Agent: Mozilla/5.0\r\nAccept: application/json\r\n"
            ]
        ]);
        $response = @file_get_contents($url, false, $ctx);
    }
    
    if (!$response) return null;
    
    $data = json_decode($response, true);
    if (!$data) return null;
    
    // Parse athletes - ESPN returns a flat array of player objects
    $players = [];
    $athleteList = $data['athletes'] ?? [];
    
    foreach ($athleteList as $p) {
        // Skip if not a valid player object
        if (!isset($p['displayName']) && !isset($p['fullName'])) continue;
        
        $pos = '';
        if (isset($p['position']) && is_array($p['position'])) {
            $pos = $p['position']['abbreviation'] ?? '';
        } elseif (isset($p['position']) && is_string($p['position'])) {
            $pos = $p['position'];
        }
        
        // Experience: check both array and integer formats
        $exp = 'R';
        if (isset($p['experience'])) {
            if (is_array($p['experience'])) {
                $exp = $p['experience']['years'] ?? 'R';
            } elseif (is_numeric($p['experience'])) {
                $exp = $p['experience'] > 0 ? $p['experience'] : 'R';
            }
        }
        
        // Headshot: check multiple possible locations
        $headshot = '';
        if (isset($p['headshot']['href'])) {
            $headshot = $p['headshot']['href'];
        } elseif (isset($p['headshot']) && is_string($p['headshot'])) {
            $headshot = $p['headshot'];
        }
        
        // College: check multiple possible locations  
        $college = '';
        if (isset($p['college']['name'])) {
            $college = $p['college']['name'];
        } elseif (isset($p['college']) && is_string($p['college'])) {
            $college = $p['college'];
        }
        
        $players[] = [
            'espn_id' => $p['id'] ?? '',
            'name' => $p['displayName'] ?? $p['fullName'] ?? 'Unknown',
            'jersey' => $p['jersey'] ?? '',
            'position' => $pos,
            'age' => $p['age'] ?? '',
            'height' => $p['displayHeight'] ?? '',
            'weight' => $p['displayWeight'] ?? '',
            'experience' => $exp,
            'headshot' => $headshot,
            'college' => $college
        ];
    }
    
    // Cache result
    if (!empty($players)) {
        @file_put_contents($cacheFile, json_encode($players));
    }
    
    return $players;
}

// Team logo mapping function
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
    
    if (isset($logoMap[$teamName])) {
        return '/nba-wins-platform/public/assets/team_logos/' . $logoMap[$teamName];
    }
    
    $filename = strtolower(str_replace(' ', '_', $teamName)) . '.png';
    return '/nba-wins-platform/public/assets/team_logos/' . $filename;
}

// Get basic team data from database including streak information
try {
    $stmt = $pdo->prepare("SELECT name, win, loss, streak, winstreak FROM 2025_2026 WHERE name = ?");
    $stmt->execute([$team_name]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$team) {
        die("Team not found: " . htmlspecialchars($team_name));
    }
    
    $team['logo'] = getTeamLogo($team['name']);
    
    // Calculate basic stats
    $win_percentage = ($team['win'] + $team['loss'] > 0) ? ($team['win'] / ($team['win'] + $team['loss'])) * 100 : 0;
    $games_played = $team['win'] + $team['loss'];
    $games_remaining = 82 - $games_played;
    $current_pace = $games_played > 0 ? ($team['win'] / $games_played) * 82 : 0;
    
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

// Include TeamStatsCalculator for database-driven stats
require_once '/data/www/default/nba-wins-platform/core/TeamStatsCalculator.php';

// Initialize stats calculator (no API timeouts!)
$statsCalculator = new TeamStatsCalculator($pdo);

// Get comprehensive stats from database (instant response)
$liveStats = $statsCalculator->getTeamStats($team_name);
$statsError = null;

// Check if stats are available
if (!$liveStats || $liveStats['GP'] == 0) {
    // No games yet
    $liveStats = null;
    $statsError = "Stats will be available after the first regular season game";
} else {
    // MANUAL FG% CALCULATION - API's fg_pct is not accurate
    // Calculate FG% from FGM (field goals made) and FGA (field goals attempted)
    if (isset($liveStats['FGM']) && isset($liveStats['FGA']) && $liveStats['FGA'] > 0) {
        $liveStats['FG_PCT'] = $liveStats['FGM'] / $liveStats['FGA'];
    }
    
    // Also recalculate 3PT% if needed
    if (isset($liveStats['FG3M']) && isset($liveStats['FG3A']) && $liveStats['FG3A'] > 0) {
        $liveStats['FG3_PCT'] = $liveStats['FG3M'] / $liveStats['FG3A'];
    }
    
    // Recalculate FT% if needed
    if (isset($liveStats['FTM']) && isset($liveStats['FTA']) && $liveStats['FTA'] > 0) {
        $liveStats['FT_PCT'] = $liveStats['FTM'] / $liveStats['FTA'];
    }
}

// Get last 5 games with results
$lastGames = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM (
            SELECT 
                g.date as game_date,
                g.home_team,
                g.away_team,
                g.home_team_code,
                g.away_team_code,
                g.home_points,
                g.away_points,
                CASE 
                    WHEN g.home_team = ? THEN 'home'
                    WHEN g.away_team = ? THEN 'away'
                END as team_location,
                CASE 
                    WHEN g.home_team = ? THEN g.away_team
                    WHEN g.away_team = ? THEN g.home_team
                END as opponent,
                CASE 
                    WHEN (g.home_team = ? AND g.home_points > g.away_points) OR 
                         (g.away_team = ? AND g.away_points > g.home_points) THEN 'W'
                    WHEN g.home_points IS NOT NULL THEN 'L'
                    ELSE NULL
                END as result
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
} catch (Exception $e) {
    error_log("Error fetching last games: " . $e->getMessage());
}

// Get upcoming 5 games
$upcomingGames = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            g.date as game_date,
            g.home_team,
            g.away_team,
            g.home_team_code,
            g.away_team_code,
            CASE 
                WHEN g.home_team = ? THEN 'home'
                WHEN g.away_team = ? THEN 'away'
            END as team_location,
            CASE 
                WHEN g.home_team = ? THEN g.away_team
                WHEN g.away_team = ? THEN g.home_team
            END as opponent
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
} catch (Exception $e) {
    error_log("Error fetching upcoming games: " . $e->getMessage());
}

// Get full schedule if on schedule tab
$fullSchedule = null;
if (isset($_GET['tab']) && $_GET['tab'] === 'schedule') {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                g.date as game_date,
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
                END as team_location,
                CASE 
                    WHEN g.home_team = ? THEN g.away_team
                    WHEN g.away_team = ? THEN g.home_team
                END as opponent,
                CASE 
                    WHEN (g.home_team = ? AND g.home_points > g.away_points) OR 
                         (g.away_team = ? AND g.away_points > g.home_points) THEN 'W'
                    WHEN g.home_points IS NOT NULL THEN 'L'
                    ELSE NULL
                END as result
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
        
        // Look up owner and top scorers for each team
        foreach ($fullSchedule as $index => &$game) {
            // Get owner
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
            
            // Calculate record up to this game (for completed games)
            if (in_array($game['status_long'], ['Final', 'Finished'])) {
                $wins = 0;
                $losses = 0;
                
                // Count all completed games up to and including this one
                for ($i = 0; $i <= $index; $i++) {
                    if (in_array($fullSchedule[$i]['status_long'], ['Final', 'Finished'])) {
                        if ($fullSchedule[$i]['result'] === 'W') {
                            $wins++;
                        } elseif ($fullSchedule[$i]['result'] === 'L') {
                            $losses++;
                        }
                    }
                }
                
                $game['record'] = $wins . '-' . $losses;
            }
            
            // Get top scorers for completed games
            if (in_array($game['status_long'], ['Final', 'Finished'])) {
                // Normalize team names for LA Clippers
                $userTeam = ($game['team_location'] === 'home') ? $game['home_team'] : $game['away_team'];
                $opponentTeam = $game['opponent'];
                
                // Check for LA Clippers variations
                $userTeamVariations = [$userTeam];
                $opponentTeamVariations = [$opponentTeam];
                
                if (strpos($userTeam, 'Clippers') !== false) {
                    $userTeamVariations = ['LA Clippers', 'Los Angeles Clippers'];
                }
                if (strpos($opponentTeam, 'Clippers') !== false) {
                    $opponentTeamVariations = ['LA Clippers', 'Los Angeles Clippers'];
                }
                
                // Get top scorer for user's team
                $placeholders = implode(',', array_fill(0, count($userTeamVariations), '?'));
                $topScorerStmt = $pdo->prepare("
                    SELECT player_name, points
                    FROM game_player_stats
                    WHERE team_name IN ($placeholders) AND game_date = ?
                    ORDER BY points DESC
                    LIMIT 1
                ");
                $params = array_merge($userTeamVariations, [$game['game_date']]);
                $topScorerStmt->execute($params);
                $game['user_top_scorer'] = $topScorerStmt->fetch(PDO::FETCH_ASSOC);
                
                // Get top scorer for opponent team
                $placeholders = implode(',', array_fill(0, count($opponentTeamVariations), '?'));
                $oppScorerStmt = $pdo->prepare("
                    SELECT player_name, points
                    FROM game_player_stats
                    WHERE team_name IN ($placeholders) AND game_date = ?
                    ORDER BY points DESC
                    LIMIT 1
                ");
                $params = array_merge($opponentTeamVariations, [$game['game_date']]);
                $oppScorerStmt->execute($params);
                $game['opp_top_scorer'] = $oppScorerStmt->fetch(PDO::FETCH_ASSOC);
            }
        }
        unset($game); // Break reference
    } catch (Exception $e) {
        error_log("Error fetching full schedule: " . $e->getMessage());
    }
}

// Get roster if on roster tab - ESPN API + DB stats merge
$roster = null;
if (isset($_GET['tab']) && $_GET['tab'] === 'roster') {
    // Step 1: Fetch ESPN roster for bio data
    $espnRoster = fetchEspnRoster($team_name);
    
    // Step 2: Fetch DB stats for per-game averages
    $dbStats = [];
    try {
        if (strpos($team_name, 'Clippers') !== false) {
            $stmt = $pdo->prepare("
                SELECT trs.player_name, trs.games_played, trs.avg_minutes,
                       trs.avg_points, trs.avg_rebounds, trs.avg_assists,
                       trs.avg_fg_made, trs.avg_fg_attempts, trs.fg_percentage
                FROM team_roster_stats trs
                WHERE (trs.current_team_name = 'LA Clippers' OR trs.current_team_name = 'Los Angeles Clippers')
            ");
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("
                SELECT trs.player_name, trs.games_played, trs.avg_minutes,
                       trs.avg_points, trs.avg_rebounds, trs.avg_assists,
                       trs.avg_fg_made, trs.avg_fg_attempts, trs.fg_percentage
                FROM team_roster_stats trs
                WHERE trs.current_team_name = ?
            ");
            $stmt->execute([$team_name]);
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // Index by lowercase name for fuzzy matching
            $dbStats[strtolower(trim($row['player_name']))] = $row;
        }
    } catch (Exception $e) {
        error_log("DB roster stats error: " . $e->getMessage());
    }
    
    // Build normalized name index for diacritic handling (Topić → topic, etc.)
    $dbStatsNormalized = [];
    foreach ($dbStats as $key => $row) {
        $normKey = normalizeForMatch($key);
        if (!isset($dbStatsNormalized[$normKey])) {
            $dbStatsNormalized[$normKey] = $row;
        }
    }
    
    // Step 2b: Build game_player_stats fallback (handles diacritics + filters preseason)
    $gpsFallback = [];
    try {
        $gpsTeamVariations = [$team_name];
        if (strpos($team_name, 'Clippers') !== false) {
            $gpsTeamVariations = ['LA Clippers', 'Los Angeles Clippers'];
        }
        $gpsPlaceholders = implode(',', array_fill(0, count($gpsTeamVariations), '?'));
        $gpsStmt = $pdo->prepare("
            SELECT player_name,
                   COUNT(*) as games_played,
                   ROUND(AVG(minutes), 1) as avg_minutes,
                   ROUND(AVG(points), 1) as avg_points,
                   ROUND(AVG(rebounds), 1) as avg_rebounds,
                   ROUND(AVG(assists), 1) as avg_assists,
                   ROUND(AVG(fg_made), 1) as avg_fg_made,
                   ROUND(AVG(fg_attempts), 1) as avg_fg_attempts,
                   CASE WHEN SUM(fg_attempts) > 0 
                       THEN ROUND(SUM(fg_made)/SUM(fg_attempts)*100, 1) 
                       ELSE 0 END as fg_percentage
            FROM game_player_stats
            WHERE team_name IN ($gpsPlaceholders) AND game_date >= '2025-10-20'
            GROUP BY player_name
        ");
        $gpsStmt->execute($gpsTeamVariations);
        foreach ($gpsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $gpsFallback[normalizeForMatch($row['player_name'])] = $row;
        }
    } catch (Exception $e) {
        error_log("game_player_stats fallback error: " . $e->getMessage());
    }
    
    // Step 3: Merge ESPN bio + DB stats
    if ($espnRoster) {
        $mergedRoster = [];
        foreach ($espnRoster as $ep) {
            $key = strtolower(trim($ep['name']));
            $stats = $dbStats[$key] ?? null;
            
            // Try partial match if exact fails (e.g. "P.J. Washington" vs "PJ Washington")
            if (!$stats) {
                $cleanKey = strtolower(preg_replace('/[^a-z ]/', '', $ep['name']));
                foreach ($dbStats as $dbKey => $dbRow) {
                    $cleanDb = strtolower(preg_replace('/[^a-z ]/', '', $dbKey));
                    if ($cleanDb === $cleanKey || 
                        strpos($cleanDb, explode(' ', $cleanKey)[count(explode(' ', $cleanKey))-1]) !== false && 
                        strpos($cleanDb, substr($cleanKey, 0, 2)) !== false) {
                        $stats = $dbRow;
                        break;
                    }
                }
            }
            
            // Normalized diacritic match (Topić → topic, etc.)
            if (!$stats) {
                $normKey = normalizeForMatch($ep['name']);
                if (isset($dbStatsNormalized[$normKey])) {
                    $stats = $dbStatsNormalized[$normKey];
                }
            }
            
            // Fallback: Query by player name alone regardless of team (handles mid-season trades)
            if (!$stats) {
                try {
                    $fallbackStmt = $pdo->prepare("
                        SELECT player_name, games_played, avg_minutes, avg_points, 
                               avg_rebounds, avg_assists, avg_fg_made, avg_fg_attempts, fg_percentage
                        FROM team_roster_stats
                        WHERE player_name = ?
                        LIMIT 1
                    ");
                    $fallbackStmt->execute([$ep['name']]);
                    $fallbackRow = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
                    if ($fallbackRow) {
                        $stats = $fallbackRow;
                    }
                } catch (Exception $e) {
                    // Silently continue
                }
            }
            
            // Last resort: game_player_stats calculation (diacritics + date filtered >= Oct 20)
            if (!$stats) {
                $normKey = normalizeForMatch($ep['name']);
                if (isset($gpsFallback[$normKey])) {
                    $stats = $gpsFallback[$normKey];
                }
            }
            
            $mergedRoster[] = [
                'name' => $ep['name'],
                'espn_id' => $ep['espn_id'],
                'jersey' => $ep['jersey'],
                'position' => $ep['position'],
                'age' => $ep['age'],
                'height' => $ep['height'],
                'weight' => $ep['weight'],
                'experience' => $ep['experience'],
                'headshot' => $ep['headshot'],
                'college' => $ep['college'],
                'games_played' => $stats['games_played'] ?? 0,
                'avg_minutes' => $stats['avg_minutes'] ?? 0,
                'avg_points' => $stats['avg_points'] ?? 0,
                'avg_rebounds' => $stats['avg_rebounds'] ?? 0,
                'avg_assists' => $stats['avg_assists'] ?? 0,
                'fg_percentage' => $stats['fg_percentage'] ?? 0,
                'has_stats' => $stats !== null
            ];
        }
        
        // Sort by PPG descending
        usort($mergedRoster, function($a, $b) {
            return $b['avg_points'] <=> $a['avg_points'];
        });
        
        $roster = ['success' => true, 'source' => 'espn', 'data' => $mergedRoster];
    } elseif (!empty($dbStats)) {
        // Fallback: DB only
        $dbOnly = array_values($dbStats);
        usort($dbOnly, function($a, $b) {
            return $b['avg_points'] <=> $a['avg_points'];
        });
        $roster = ['success' => true, 'source' => 'database', 'data' => $dbOnly];
    } else {
        $roster = ['error' => 'No roster data available'];
    }
}

// Get draft information
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
                (dp.pick_number - 1) % (SELECT COUNT(DISTINCT league_participant_id) FROM draft_picks WHERE draft_session_id = ?) + 1 as position_in_round
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

// Check if API dependencies are available
$apiStatus = $nbaApi->checkDependencies();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($team['name']); ?> - Team Data</title>
    <link rel="apple-touch-icon" type="image/png" href="/nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="/nba-wins-platform/public/assets/favicon/favicon.png">
    <link href="https://cdn.tailwindcss.com" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
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
        --success-color: #4CAF50;
        --warning-color: #FF9800;
        --error-color: #F44336;
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        line-height: 1.6;
        margin: 0;
        padding: 20px;
        background-image: url('/nba-wins-platform/public/assets/background/geometric_white.png');
        background-repeat: repeat;
        background-attachment: fixed;
        background-color: #f5f5f5;
    }
    
    .container {
        max-width: 1400px;
        margin: 0 auto;
        background-color: white;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        position: relative;
    }

    .team-header {
        position: relative;
        padding: 2rem;
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 2rem;
        min-height: 150px;
        margin-bottom: 2rem;
        border-radius: 12px;
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        overflow: hidden;
    }
    
    .team-header-logo-bg {
        position: absolute;
        width: 250px;
        height: 250px;
        object-fit: contain;
        opacity: 0.2;
        z-index: 1;
        pointer-events: none;
        left: 50%;
        top: 50%;
        transform: translate(-50%, -50%);
    }
    
    .team-header-content {
        position: relative;
        z-index: 2;
        display: flex;
        align-items: center;
        gap: 2rem;
    }
    
    .team-header-logo {
        width: 100px;
        height: 100px;
        object-fit: contain;
        filter: drop-shadow(0 2px 4px rgba(0,0,0,0.15));
    }
    
    .team-info {
        text-align: left;
    }
    
    .team-info h1 {
        color: #333;
    }
    
    .team-info p {
        color: #555;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }

    .stat-card {
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 1rem;
        text-align: center;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        border-left: 4px solid var(--primary-color);
    }

    .stat-card.live {
        border-left-color: var(--success-color);
        background-color: #f1f8e9;
    }

    .stat-card.error {
        border-left-color: var(--error-color);
        background-color: #ffebee;
    }

    .projection-value {
        font-size: 1.8rem;
        font-weight: bold;
        color: #333;
        margin-bottom: 0.5rem;
    }

    .projection-label {
        color: #666;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .live-indicator {
        color: var(--success-color);
        font-size: 0.8rem;
    }

    .error-indicator {
        color: var(--error-color);
        font-size: 0.8rem;
    }
    
    .section-title {
        font-size: 1.5rem;
        font-weight: bold;
        margin: 2rem 0 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #eee;
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .tabs {
        display: flex;
        gap: 1rem;
        border-bottom: 2px solid #eee;
        padding-bottom: 1rem;
        margin-bottom: 2rem;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .tab {
        padding: 0.5rem 2rem;
        border-radius: 0.5rem;
        color: #666;
        text-decoration: none;
        transition: all 0.2s;
    }
    
    .tab.active {
        background-color: var(--primary-color);
        color: white;
    }
    
    .tab:hover {
        background-color: var(--hover-color);
        color: white;
    }
    
    .no-data {
        text-align: center;
        padding: 2rem;
        color: #666;
        background-color: #f8f9fa;
        border-radius: 8px;
        margin: 1rem 0;
    }

    .roster-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 1rem;
        margin-top: 1rem;
    }

    .player-card {
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 1rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        transition: transform 0.2s;
    }

    .player-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }

    .player-name {
        font-weight: bold;
        font-size: 1.1rem;
        margin-bottom: 0.5rem;
    }

    .player-position {
        color: #666;
        font-size: 0.9rem;
        margin-bottom: 0.5rem;
    }

    .player-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.5rem;
        font-size: 0.85rem;
    }

    .status-banner {
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 2rem;
        text-align: center;
    }

    .status-banner.warning {
        background-color: #fff3cd;
        border: 1px solid #ffeaa7;
        color: #856404;
    }

    .status-banner.error {
        background-color: #f8d7da;
        border: 1px solid #f5c6cb;
        color: #721c24;
    }

    .status-banner.success {
        background-color: #d4edda;
        border: 1px solid #c3e6cb;
        color: #155724;
    }

    .refresh-button {
        background-color: var(--primary-color);
        color: white;
        border: none;
        padding: 0.5rem 1rem;
        border-radius: 4px;
        cursor: pointer;
        margin-left: 1rem;
    }

    .refresh-button:hover {
        background-color: var(--secondary-color);
    }

    /* Menu styles - matching other files */
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

    @media (max-width: 768px) {
        body {
            padding: 10px;
        }
        
        .container {
            padding: 15px;
        }
        
        .team-header {
            padding: 1.5rem;
        }
        
        .team-header-content {
            flex-direction: column;
            gap: 1rem;
        }
        
        .team-header-logo {
            width: 80px;
            height: 80px;
        }
        
        .team-header-logo-bg {
            width: 180px;
            height: 180px;
        }
        
        .team-info {
            text-align: center;
        }

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }

        .projection-value {
            font-size: 1.5rem;
        }

        .roster-grid {
            grid-template-columns: 1fr;
        }
        
        /* Tabs - Mobile */
        .tabs {
            gap: 0.75rem;
            padding-bottom: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .tab {
            padding: 0.4rem 1.25rem;
            font-size: 0.85rem;
            white-space: nowrap;
        }
        
        .tab i {
            font-size: 0.8rem;
        }
        
        /* Roster Grid Enhanced - Mobile */
        .roster-grid-enhanced {
            grid-template-columns: 1fr;
            gap: 15px;
        }
        
        .player-header {
            gap: 10px;
        }
        
        .player-icon-enhanced {
            font-size: 2rem !important;
            min-width: 50px !important;
        }
        
        /* Schedule Mobile Optimization */
        .games-list {
            padding: 0.75rem;
        }
        
        .game-list-item {
            padding: 0.75rem 0.5rem;
            flex-direction: column;
            align-items: stretch !important;
        }
        
        .game-list-info {
            margin-bottom: 0.5rem;
        }
        
        .game-list-date {
            font-size: 0.75rem;
            margin-bottom: 0.25rem;
        }
        
        .game-list-matchup {
            font-size: 0.85rem;
            flex-wrap: wrap;
            line-height: 1.4;
        }
        
        .game-list-matchup img {
            width: 16px !important;
            height: 16px !important;
        }
        
        .game-list-result {
            text-align: left;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.5rem;
        }
        
        .game-list-score {
            font-size: 0.95rem;
        }
        
        .game-list-outcome {
            font-size: 0.8rem;
            margin-left: 0.25rem;
        }
        
        .top-scorers {
            flex-direction: column;
            gap: 0.4rem;
            font-size: 0.7rem;
            margin-top: 0.5rem;
            padding-top: 0.5rem;
        }
        
        .scorer-line {
            gap: 0.25rem;
        }
        
        .section-title {
            font-size: 1.1rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .section-title a {
            font-size: 0.8rem !important;
            margin-left: 0 !important;
        }
    }
    /* Game Card Styles */
    .games-container {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 1rem;
        margin-top: 1rem;
    }
    
    .game-card {
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 1.5rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        transition: transform 0.2s;
        border-left: 4px solid var(--border-color);
    }
    
    .game-card.win {
        border-left-color: var(--success-color);
        background-color: #f1f8e9;
    }
    
    .game-card.loss {
        border-left-color: var(--error-color);
        background-color: #ffebee;
    }
    
    .game-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }
    
    .game-date {
        font-size: 0.9rem;
        color: #666;
        margin-bottom: 0.5rem;
    }
    
    .game-matchup {
        font-size: 1.1rem;
        font-weight: bold;
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .game-location {
        font-size: 0.8rem;
        color: #888;
        text-transform: uppercase;
    }
    
    .game-result {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid var(--border-color);
    }
    
    .game-score {
        font-size: 1.5rem;
        font-weight: bold;
    }
    
    .game-outcome {
        font-size: 1.5rem;
        font-weight: bold;
    }
    
    .game-outcome.win {
        color: var(--success-color);
    }
    
    .game-outcome.loss {
        color: var(--error-color);
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
    
    .game-list-item:hover {
        background-color: rgba(0, 0, 0, 0.03);
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
        margin-left: 0.5rem;
    }
    
    .game-list-outcome.w {
        color: var(--success-color);
    }
    
    .game-list-outcome.l {
        color: var(--error-color);
    }
    
    /* Top Scorers Display */
    .top-scorers {
        font-size: 0.8rem;
        color: #666;
        margin-top: 0.5rem;
        padding-top: 0.5rem;
        border-top: 1px solid #e0e0e0;
        display: flex;
        gap: 1.5rem;
    }
    
    .scorer-line {
        display: flex;
        align-items: center;
        gap: 0.3rem;
    }
    
    .scorer-points {
        font-weight: bold;
        color: var(--primary-color);
    }
    
    /* Enhanced Roster Styles with Stats */
        .roster-grid-enhanced {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .player-card-enhanced {
            background: #f8f9fa;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 20px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .player-card-enhanced:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
            border-color: var(--primary-color);
        }
        
        .player-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
        }
        
        .player-icon-enhanced {
            font-size: 2.5rem;
            color: var(--primary-color);
            min-width: 60px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .player-info {
            flex: 1;
        }
        
        .player-name-enhanced {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .player-games {
            font-size: 0.9rem;
            color: #666;
        }
        
        .player-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }
        
        .stat-item {
            text-align: center;
            padding: 8px;
            background: white;
            border-radius: 5px;
        }
        
        .stat-value {
            font-size: 1.3rem;
            font-weight: bold;
            color: var(--primary-color);
        }
        
        .stat-label {
            font-size: 0.75rem;
            color: #666;
            text-transform: uppercase;
            margin-top: 2px;
        }
        
        /* Roster Table List Styles */
        .roster-table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin-top: 1rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        
        .roster-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            min-width: 600px;
        }
        
        .roster-table thead {
            background-color: var(--primary-color);
            color: white;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        
        .roster-table thead th {
            padding: 10px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            white-space: nowrap;
        }
        
        .roster-table thead th.col-stat {
            text-align: center;
        }
        
        .roster-table thead th.col-meta {
            text-align: center;
        }
        
        .roster-table thead th.col-photo {
            width: 44px;
            padding: 6px;
        }
        
        .roster-row td {
            padding: 10px 12px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .roster-row.alt {
            background-color: #fafafa;
        }
        
        .roster-row:hover {
            background-color: #f0f4ff;
        }
        
        .col-stat {
            text-align: center;
            font-variant-numeric: tabular-nums;
            min-width: 48px;
        }
        
        .col-meta {
            text-align: center;
            color: #666;
            font-size: 0.85rem;
            white-space: nowrap;
        }
        
        .stat-ppg {
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .player-headshot {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            background-color: #eee;
        }
        
        .headshot-fallback {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: #e8e8e8;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #aaa;
            font-size: 0.85rem;
        }
        
        .player-name-row {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        
        .jersey-num {
            font-weight: 600;
            color: #888;
            font-size: 0.85rem;
            min-width: 28px;
        }
        
        .player-name-text {
            font-weight: 600;
        }
        
        .pos-badge {
            display: inline-block;
            background-color: #e8e8e8;
            color: #555;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.02em;
        }
        
        .player-college {
            font-size: 0.75rem;
            color: #999;
            margin-top: 2px;
        }
        
        @media (max-width: 768px) {
            .hide-mobile {
                display: none;
            }
            
            .roster-table {
                font-size: 0.75rem;
                min-width: 520px;
            }
            
            .roster-table thead th {
                padding: 6px 4px;
                font-size: 0.65rem;
            }
            
            .roster-row td {
                padding: 5px 4px;
            }
            
            .player-headshot,
            .headshot-fallback {
                width: 26px;
                height: 26px;
            }
            
            .player-college {
                display: none;
            }
            
            .col-stat {
                min-width: 30px;
                font-size: 0.75rem;
            }
            
            /* Compact player name: single line, no wrap */
            .player-name-row {
                gap: 3px;
                flex-wrap: nowrap;
            }
            
            .jersey-num {
                font-size: 0.7rem;
                min-width: auto;
            }
            
            .player-name-text {
                font-size: 0.78rem;
                white-space: nowrap;
            }
            
            .pos-badge {
                font-size: 0.55rem;
                padding: 0px 3px;
                white-space: nowrap;
            }
            
            .col-photo {
                padding: 3px !important;
                width: 32px !important;
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
        <!-- API Status Banner -->
        <?php if ($statsError): ?>
        <div class="status-banner warning">
            <i class="fas fa-info-circle"></i>
            Live statistics: <?php echo htmlspecialchars($statsError); ?>
        </div>
        <?php elseif (isset($liveStats['success']) && $liveStats['success']): ?>
        <div class="status-banner success">
            <i class="fas fa-check-circle"></i>
            <strong>Live Stats Active:</strong> Data updated <?php echo date('g:i A', $liveStats['timestamp']); ?>
        </div>
        <?php endif; ?>
        
        <!-- DEBUG: Team Name Matching (only when ?debug in URL) -->
        <?php if (isset($_GET['debug'])): ?>
        <div class="status-banner warning">
            <strong>DEBUG - Team Name Matching:</strong><br>
            <strong>Searching for:</strong> "<?php echo htmlspecialchars($team_name); ?>"<br>
            <?php
            // Show all unique team names in games table
            $debugStmt = $pdo->prepare("
                SELECT DISTINCT home_team FROM games 
                UNION 
                SELECT DISTINCT away_team FROM games 
                ORDER BY home_team
            ");
            $debugStmt->execute();
            $allTeamsInGames = $debugStmt->fetchAll(PDO::FETCH_COLUMN);
            echo "<strong>Teams in games table:</strong> " . htmlspecialchars(implode(', ', $allTeamsInGames));
            
            // Debug: Show the actual column names in games table
            $debugColumnsStmt = $pdo->query("SHOW COLUMNS FROM games");
            $columns = $debugColumnsStmt->fetchAll(PDO::FETCH_COLUMN);
            echo "<br><strong>Columns in games table:</strong> " . htmlspecialchars(implode(', ', $columns));
            
            // Show a sample game
            $sampleStmt = $pdo->prepare("SELECT * FROM games WHERE home_team = ? OR away_team = ? LIMIT 1");
            $sampleStmt->execute([$team_name, $team_name]);
            $sampleGame = $sampleStmt->fetch(PDO::FETCH_ASSOC);
            if ($sampleGame) {
                echo "<br><strong>Sample game data:</strong> " . htmlspecialchars(json_encode($sampleGame));
            }
            ?>
        </div>
        <?php endif; ?>
        
        <!-- Tabs -->
        <div class="tabs">
            <a href="?team=<?php echo urlencode($team['name']); ?>&tab=home" 
               class="tab <?php echo (!isset($_GET['tab']) || $_GET['tab'] === 'home') ? 'active' : ''; ?>">
                <i class="fas fa-home"></i> Home
            </a>
            <a href="?team=<?php echo urlencode($team['name']); ?>&tab=roster" 
               class="tab <?php echo (isset($_GET['tab']) && $_GET['tab'] === 'roster') ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> Roster
            </a>
            <a href="?team=<?php echo urlencode($team['name']); ?>&tab=schedule" 
               class="tab <?php echo (isset($_GET['tab']) && $_GET['tab'] === 'schedule') ? 'active' : ''; ?>">
                <i class="fas fa-calendar"></i> Schedule
            </a>
        </div>

        <!-- Home Tab -->
        <?php if (!isset($_GET['tab']) || $_GET['tab'] === 'home'): ?>
        
        <div class="team-header">
            <img src="<?php echo htmlspecialchars($team['logo']); ?>" 
                 alt="" class="team-header-logo-bg"
                 onerror="this.style.display='none'">
            <div class="team-header-content">
            <img src="<?php echo htmlspecialchars($team['logo']); ?>" 
                 alt="<?php echo htmlspecialchars($team['name']); ?>"
                 class="team-header-logo"
                 onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwIiBoZWlnaHQ9IjEwMCIgdmlld0JveD0iMCAwIDEwMCAxMDAiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PGNpcmNsZSBjeD0iNTAiIGN5PSI1MCIgcj0iNDAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSI0Ii8+PHRleHQgeD0iNTAiIHk9IjU1IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmb250LXNpemU9IjE2IiBmaWxsPSIjMzMzMzMzIj4/PC90ZXh0Pjwvc3ZnPgo='">
            <div class="team-info">
                <h1 class="text-4xl font-bold mb-2"><?php echo htmlspecialchars($team['name']); ?></h1>
                <?php if ($draft_info): ?>
                    <p class="text-xl">
                        Round <?php echo $draft_info['round_number']; ?>, Pick <?php echo $draft_info['position_in_round']; ?> 
                        (Overall #<?php echo $draft_info['pick_number']; ?>)
                        <br>
                        <span class="opacity-75">
                            Drafted by <?php echo htmlspecialchars($draft_info['display_name']); ?>
                            <?php if ($draft_info['picked_by_commissioner']): ?>
                                <span style="color: #FF7F00;">(Commissioner Pick)</span>
                            <?php endif; ?>
                        </span>
                    </p>
                <?php else: ?>
                    <p class="text-xl opacity-75">Draft information not available</p>
                <?php endif; ?>
            </div>
            </div><!-- /team-header-content -->
        </div>

        <!-- Team Record Stats -->
        <h2 class="section-title">
            <i class="fas fa-trophy"></i>
            Season Record
        </h2>
        <div class="stats-grid mb-6">
            <div class="stat-card">
                <div class="projection-label">Record</div>
                <div class="projection-value"><?php echo $team['win']; ?>-<?php echo $team['loss']; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="projection-label">Win Percentage</div>
                <div class="projection-value"><?php echo number_format($win_percentage, 1); ?>%</div>
            </div>
            
            <div class="stat-card">
                <div class="projection-label">Games Remaining</div>
                <div class="projection-value"><?php echo $games_remaining; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="projection-label">Current Pace</div>
                <div class="projection-value"><?php echo number_format($current_pace, 1); ?> wins</div>
            </div>
            
            <?php 
            // Display streak information
            $streak = $team['streak'] ?? 0;
            $winstreak = $team['winstreak'] ?? 0;
            
            if ($streak != 0 || $winstreak != 0): 
                $isWinStreak = ($winstreak == 1);
                $streakLength = abs($streak);
                $streakLabel = $isWinStreak ? 'Win Streak' : 'Loss Streak';
                
                // Color logic: Green for win streak, Blue for loss streak, Red for 10+ game loss streak
                if ($isWinStreak) {
                    $streakColor = 'var(--success-color)'; // Green
                    $streakBgColor = '#f1f8e9'; // Light green background
                } else {
                    // Loss streak - check if it's 10+ games
                    if ($streakLength >= 10) {
                        $streakColor = 'var(--error-color)'; // Red for major loss streaks
                        $streakBgColor = '#ffebee'; // Light red background
                    } else {
                        $streakColor = '#2196F3'; // Blue for normal loss streaks
                        $streakBgColor = '#e3f2fd'; // Light blue background
                    }
                }
                
                $streakIcon = $isWinStreak ? 'fa-fire' : 'fa-snowflake';
            ?>
            <div class="stat-card" style="border-left-color: <?php echo $streakColor; ?>; background-color: <?php echo $streakBgColor; ?>">
                <div class="projection-label">
                    <i class="fa-solid <?php echo $streakIcon; ?>" style="color: <?php echo $streakColor; ?>;"></i>
                    <?php echo $streakLabel; ?>
                </div>
                <div class="projection-value" style="color: <?php echo $streakColor; ?>;">
                    <?php echo $streakLength; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

<!-- Live Team Statistics - ENHANCED & FIXED -->
        <?php if ($liveStats && $liveStats['GP'] > 0): ?>
        <h2 class="section-title">
            <i class="fas fa-basketball-ball"></i>
            Live Team Statistics
            <?php if (isset($liveStats['data_source']) && $liveStats['data_source'] === 'api_enhanced'): ?>
                <span class="live-indicator"><i class="fas fa-circle"></i> LIVE</span>
            <?php endif; ?>
        </h2>
        
        <!-- Shooting Stats -->
        <h3 style="font-size: 1.3rem; margin: 1.5rem 0 1rem 0; color: var(--primary-color);">
            <i class="fas fa-bullseye"></i> Shooting
        </h3>
        <div class="stats-grid mb-6">
            <div class="stat-card live">
                <div class="projection-label">Points Per Game</div>
                <div class="projection-value"><?php echo number_format($liveStats['PTS'], 1); ?></div>
            </div>
            
            <div class="stat-card live">
                <div class="projection-label">Field Goal %</div>
                <div class="projection-value"><?php echo number_format($liveStats['FG_PCT'] * 100, 1); ?>%</div>
            </div>
            
            <div class="stat-card live">
                <div class="projection-label">3-Point %</div>
                <div class="projection-value"><?php echo number_format($liveStats['FG3_PCT'] * 100, 1); ?>%</div>
            </div>
            
            <div class="stat-card live">
                <div class="projection-label">Free Throw %</div>
                <div class="projection-value"><?php echo number_format($liveStats['FT_PCT'] * 100, 1); ?>%</div>
            </div>
            
            <div class="stat-card live">
                <div class="projection-label">3-Pointers Made</div>
                <div class="projection-value"><?php echo number_format($liveStats['FG3M'], 1); ?></div>
            </div>
            
            <div class="stat-card live">
                <div class="projection-label">3-Point Attempts</div>
                <div class="projection-value"><?php echo number_format($liveStats['FG3A'], 1); ?></div>
            </div>
        </div>
        
        <!-- Core Stats -->
        <h3 style="font-size: 1.3rem; margin: 1.5rem 0 1rem 0; color: var(--primary-color);">
            <i class="fas fa-chart-line"></i> Core Stats
        </h3>
        <div class="stats-grid mb-6">
            <div class="stat-card live">
                <div class="projection-label">Rebounds Per Game</div>
                <div class="projection-value"><?php echo number_format($liveStats['REB'], 1); ?></div>
            </div>
            
            <div class="stat-card live">
                <div class="projection-label">Assists Per Game</div>
                <div class="projection-value"><?php echo number_format($liveStats['AST'], 1); ?></div>
            </div>
            
            <div class="stat-card live">
                <div class="projection-label">Steals Per Game</div>
                <div class="projection-value"><?php echo number_format($liveStats['STL'], 1); ?></div>
            </div>
            
            <div class="stat-card live">
                <div class="projection-label">Blocks Per Game</div>
                <div class="projection-value"><?php echo number_format($liveStats['BLK'], 1); ?></div>
            </div>
            
            <div class="stat-card live">
                <div class="projection-label">Turnovers Per Game</div>
                <div class="projection-value"><?php echo number_format($liveStats['TOV'], 1); ?></div>
            </div>
            
            <div class="stat-card live">
                <div class="projection-label">Plus/Minus Per Game</div>
                <div class="projection-value"><?php 
                    $pm = $liveStats['PLUS_MINUS'];
                    $color = $pm >= 0 ? '#10b981' : '#ef4444';
                    echo '<span style="color: ' . $color . ';">' . ($pm >= 0 ? '+' : '') . number_format($pm, 1) . '</span>';
                ?></div>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Show "Data coming soon" when stats aren't available -->
        <h2 class="section-title">
            <i class="fas fa-basketball-ball"></i>
            Live Team Statistics
        </h2>
        <div class="stats-grid mb-6">
            <div class="stat-card">
                <div class="projection-label">Points Per Game</div>
                <div class="projection-value" style="font-size: 1.2rem; color: #666;">Data coming soon</div>
            </div>
            
            <div class="stat-card">
                <div class="projection-label">Field Goal %</div>
                <div class="projection-value" style="font-size: 1.2rem; color: #666;">Data coming soon</div>
            </div>
            
            <div class="stat-card">
                <div class="projection-label">3-Point %</div>
                <div class="projection-value" style="font-size: 1.2rem; color: #666;">Data coming soon</div>
            </div>
            
            <div class="stat-card">
                <div class="projection-label">Rebounds Per Game</div>
                <div class="projection-value" style="font-size: 1.2rem; color: #666;">Data coming soon</div>
            </div>
            
            <div class="stat-card">
                <div class="projection-label">Assists Per Game</div>
                <div class="projection-value" style="font-size: 1.2rem; color: #666;">Data coming soon</div>
            </div>
            
            <div class="stat-card">
                <div class="projection-label">Plus/Minus</div>
                <div class="projection-value" style="font-size: 1.2rem; color: #666;">Data coming soon</div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Last 5 Games Section -->
        <h2 class="section-title">
            <i class="fas fa-history"></i>
            Last 5 Games
            <a href="?team=<?php echo urlencode($team['name']); ?>&tab=schedule" 
               style="margin-left: auto; font-size: 0.9rem; color: var(--primary-color); text-decoration: none; font-weight: normal;">
                View Full Schedule →
            </a>
        </h2>
        
        <?php if (!empty($lastGames)): ?>
        <div class="games-list">
            <?php foreach ($lastGames as $game): 
                $teamScore = ($game['team_location'] === 'home') ? $game['home_points'] : $game['away_points'];
                $oppScore = ($game['team_location'] === 'home') ? $game['away_points'] : $game['home_points'];
                $gameUrl = "/nba-wins-platform/stats/game_details.php?home_team=" . urlencode($game['home_team_code']) . "&away_team=" . urlencode($game['away_team_code']) . "&date=" . urlencode($game['game_date']);
            ?>
            <a href="<?php echo $gameUrl; ?>" class="game-list-item clickable" style="display: flex;">
                <div class="game-list-info">
                    <div class="game-list-date">
                        <?php echo date('M j, Y', strtotime($game['game_date'])); ?>
                    </div>
                    <div class="game-list-matchup">
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
                    <div class="game-list-outcome <?php echo strtolower($game['result']); ?>">
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
        
        <!-- Upcoming 5 Games Section -->
        <h2 class="section-title">
            <i class="fas fa-calendar-alt"></i>
            Upcoming 5 Games
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
        
        <!-- Roster Tab - LIST DISPLAY with ESPN Bio + DB Stats -->
        <?php elseif (isset($_GET['tab']) && $_GET['tab'] === 'roster'): ?>
        
        <h2 class="section-title">
            <i class="fas fa-users"></i>
            Team Roster
            <?php if (isset($roster['source']) && $roster['source'] === 'espn'): ?>
                <span style="font-size: 0.75rem; color: #888; font-weight: normal; margin-left: auto;">via ESPN</span>
            <?php endif; ?>
        </h2>
        
        <?php if (isset($roster['error'])): ?>
        <div class="no-data">
            <p style="font-size: 1.2rem; color: #666;">
                <?php echo htmlspecialchars($roster['error']); ?>
            </p>
        </div>
        <?php elseif (isset($roster['success']) && $roster['success'] && !empty($roster['data'])): ?>
        
        <?php $isEspn = ($roster['source'] ?? '') === 'espn'; ?>
        
        <!-- Desktop Table -->
        <div class="roster-table-wrap">
            <table class="roster-table">
                <thead>
                    <tr>
                        <?php if ($isEspn): ?><th class="col-photo"></th><?php endif; ?>
                        <th class="col-player">Player</th>
                        <th class="col-stat">GP</th>
                        <th class="col-stat">MPG</th>
                        <th class="col-stat">PPG</th>
                        <th class="col-stat">RPG</th>
                        <th class="col-stat">APG</th>
                        <th class="col-stat">FG%</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roster['data'] as $idx => $player): ?>
                    <tr class="roster-row<?php echo $idx % 2 === 0 ? '' : ' alt'; ?>">
                        <?php if ($isEspn): ?>
                        <td class="col-photo">
                            <?php if (!empty($player['headshot'])): ?>
                                <img src="<?php echo htmlspecialchars($player['headshot']); ?>" 
                                     alt="" class="player-headshot"
                                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                                <div class="headshot-fallback" style="display:none"><i class="fas fa-user"></i></div>
                            <?php else: ?>
                                <div class="headshot-fallback"><i class="fas fa-user"></i></div>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        
                        <td class="col-player">
                            <div class="player-name-row">
                                <?php if ($isEspn && !empty($player['jersey'])): ?>
                                    <span class="jersey-num">#<?php echo htmlspecialchars($player['jersey']); ?></span>
                                <?php endif; ?>
                                <?php 
                                    $playerName = $player['name'] ?? $player['player_name'] ?? '';
                                    $playerEspnId = $player['espn_id'] ?? '';
                                    $playerUrl = '/nba-wins-platform/stats/player_profile.php?team=' . urlencode($team_name) . '&player=' . urlencode($playerName) . ($playerEspnId ? '&espn_id=' . urlencode($playerEspnId) : '');
                                ?>
                                <a href="<?php echo $playerUrl; ?>" class="player-name-text" style="color: inherit; text-decoration: none; border-bottom: 1px dotted #ccc;" onmouseover="this.style.color='#1a73e8';this.style.borderBottomColor='#1a73e8'" onmouseout="this.style.color='inherit';this.style.borderBottomColor='#ccc'"><?php echo htmlspecialchars($playerName); ?></a>
                                <?php if ($isEspn && !empty($player['position'])): ?>
                                    <span class="pos-badge"><?php echo htmlspecialchars($player['position']); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($isEspn && !empty($player['college'])): ?>
                                <div class="player-college"><?php echo htmlspecialchars($player['college']); ?></div>
                            <?php endif; ?>
                        </td>
                        
                        <td class="col-stat"><?php echo $player['games_played'] ?? '-'; ?></td>
                        <td class="col-stat"><?php echo ($player['avg_minutes'] ?? 0) > 0 ? number_format($player['avg_minutes'], 1) : '-'; ?></td>
                        <td class="col-stat stat-ppg"><?php echo ($player['avg_points'] ?? 0) > 0 ? number_format($player['avg_points'], 1) : '-'; ?></td>
                        <td class="col-stat"><?php echo ($player['avg_rebounds'] ?? 0) > 0 ? number_format($player['avg_rebounds'], 1) : '-'; ?></td>
                        <td class="col-stat"><?php echo ($player['avg_assists'] ?? 0) > 0 ? number_format($player['avg_assists'], 1) : '-'; ?></td>
                        <td class="col-stat"><?php echo ($player['fg_percentage'] ?? 0) > 0 ? number_format($player['fg_percentage'], 1) . '%' : '-'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php else: ?>
        <div class="no-data">
            <p style="font-size: 1.2rem; color: #666;">Roster data coming soon</p>
        </div>
        <?php endif; ?>
        
        <!-- Schedule Tab -->
        <?php elseif (isset($_GET['tab']) && $_GET['tab'] === 'schedule'): ?>
        
        <h2 class="section-title">
            <i class="fas fa-calendar"></i>
            Full Schedule
        </h2>
        
        <?php if (!empty($fullSchedule)): ?>
        <div class="games-list">
            <?php foreach ($fullSchedule as $game): 
                $isCompleted = in_array($game['status_long'], ['Final', 'Finished']);
                $teamScore = ($game['team_location'] === 'home') ? $game['home_points'] : $game['away_points'];
                $oppScore = ($game['team_location'] === 'home') ? $game['away_points'] : $game['home_points'];
                
                // Different URLs for completed vs upcoming games
                if ($isCompleted) {
                    $gameUrl = "/nba-wins-platform/stats/game_details.php?home_team=" . urlencode($game['home_team_code']) . "&away_team=" . urlencode($game['away_team_code']) . "&date=" . urlencode($game['game_date']);
                } else {
                    $gameUrl = "/nba-wins-platform/stats/team_comparison.php?home_team=" . urlencode($game['home_team_code']) . "&away_team=" . urlencode($game['away_team_code']) . "&date=" . urlencode($game['game_date']);
                }
            ?>
            <a href="<?php echo $gameUrl; ?>" class="game-list-item clickable" style="display: flex; flex-direction: column; align-items: stretch;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div class="game-list-info">
                        <div class="game-list-date">
                            <?php echo date('M j, Y', strtotime($game['game_date'])); ?>
                        </div>
                        <div class="game-list-matchup">
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
                    <?php if ($isCompleted): ?>
                    <div class="game-list-result">
                        <div class="game-list-score"><?php echo $teamScore . '-' . $oppScore; ?></div>
                        <div class="game-list-outcome <?php echo strtolower($game['result']); ?>">
                            <?php echo $game['result']; ?>
                            <?php if (!empty($game['record'])): ?>
                                <span style="font-size: 0.85rem; color: #888; font-weight: normal; margin-left: 0.5rem;">
                                    (<?php echo $game['record']; ?>)
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($isCompleted && (!empty($game['user_top_scorer']) || !empty($game['opp_top_scorer']))): ?>
                <div class="top-scorers">
                    <?php if (!empty($game['user_top_scorer'])): ?>
                    <div class="scorer-line">
                        <span><?php echo htmlspecialchars($game['user_top_scorer']['player_name']); ?>:</span>
                        <span class="scorer-points"><?php echo $game['user_top_scorer']['points']; ?> pts</span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($game['opp_top_scorer'])): ?>
                    <div class="scorer-line">
                        <span style="color: #999;"><?php echo htmlspecialchars($game['opp_top_scorer']['player_name']); ?>:</span>
                        <span class="scorer-points" style="color: #666;"><?php echo $game['opp_top_scorer']['points']; ?> pts</span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="no-data">
            <p>No games found in the schedule</p>
        </div>
        <?php endif; ?>
        
        <?php endif; ?>
        
        <!-- Debug Information (only in development) -->
        <?php if (isset($_GET['debug'])): ?>
        <div class="section-title">Debug Information</div>
        <div style="background: #f8f9fa; padding: 1rem; border-radius: 8px; font-family: monospace; font-size: 0.8rem; margin: 1rem 0;">
            <strong>API Status:</strong><br>
            Python: <?php echo $apiStatus['python'] ? '✓' : '✗'; ?><br>
            NBA API: <?php echo $apiStatus['nba_api'] ? '✓' : '✗'; ?><br>
            Scripts: <?php echo json_encode($apiStatus['scripts']); ?><br>
            <br>
            <strong>Team Data:</strong><br>
            <?php echo htmlspecialchars(json_encode($liveStats, JSON_PRETTY_PRINT)); ?>
        </div>
        <?php endif; ?>
    </div>

</body>
</html>