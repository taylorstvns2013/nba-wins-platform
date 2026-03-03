<?php
/**
 * Game Scores Helper - DEBUG VERSION
 * Functions to fetch and process live NBA game scores from the API
 */

/**
 * Parse ISO 8601 duration format to readable time (MM:SS)
 */
function parseGameClock($duration) {
    if (empty($duration) || $duration === 'PT00M00.00S') {
        return '0:00';
    }
    
    if (preg_match('/PT(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?/', $duration, $matches)) {
        $minutes = isset($matches[1]) ? intval($matches[1]) : 0;
        $seconds = isset($matches[2]) ? intval($matches[2]) : 0;
        
        return sprintf('%d:%02d', $minutes, $seconds);
    }
    
    return $duration;
}

/**
 * Fetch current game scores from NBA API - WITH DEBUG LOGGING
 */
function getAPIScores() {
    $debug_log = "/tmp/game_scores_debug.log";
    $timestamp = date('Y-m-d H:i:s');
    
    try {
        // Log the attempt
        file_put_contents($debug_log, "\n[$timestamp] === API FETCH ATTEMPT ===\n", FILE_APPEND);
        
        // Check if python3 exists
        $python_check = shell_exec("which python3 2>&1");
        file_put_contents($debug_log, "[$timestamp] Python3 path: $python_check\n", FILE_APPEND);
        
        // Check if script exists
        $script_path = "/data/www/default/nba-wins-platform/tasks/get_games.py";
        $script_exists = file_exists($script_path) ? "YES" : "NO";
        file_put_contents($debug_log, "[$timestamp] Script exists: $script_exists\n", FILE_APPEND);
        
        // Try to execute with timeout and capture all output
        $command = "timeout 10 python3 $script_path 2>&1";
        file_put_contents($debug_log, "[$timestamp] Executing: $command\n", FILE_APPEND);
        
        $start_time = microtime(true);
        $output = shell_exec($command);
        $execution_time = microtime(true) - $start_time;
        
        file_put_contents($debug_log, "[$timestamp] Execution time: {$execution_time}s\n", FILE_APPEND);
        file_put_contents($debug_log, "[$timestamp] Output length: " . strlen($output) . " bytes\n", FILE_APPEND);
        
        if (!$output) {
            $error_msg = "No output from get_games.py";
            error_log("getAPIScores: $error_msg");
            file_put_contents($debug_log, "[$timestamp] ERROR: $error_msg\n", FILE_APPEND);
            file_put_contents($debug_log, "[$timestamp] PHP error_get_last: " . print_r(error_get_last(), true) . "\n", FILE_APPEND);
            return ['scoreboard' => ['games' => []]];
        }
        
        // Log first 500 chars of output
        file_put_contents($debug_log, "[$timestamp] Output preview: " . substr($output, 0, 500) . "\n", FILE_APPEND);
        
        $data = json_decode($output, true);
        
        if (!$data || !isset($data['scoreboard'])) {
            $error_msg = "Invalid JSON or missing scoreboard";
            error_log("getAPIScores: $error_msg: " . substr($output, 0, 200));
            file_put_contents($debug_log, "[$timestamp] ERROR: $error_msg\n", FILE_APPEND);
            file_put_contents($debug_log, "[$timestamp] JSON decode error: " . json_last_error_msg() . "\n", FILE_APPEND);
            return ['scoreboard' => ['games' => []]];
        }
        
        $game_count = count($data['scoreboard']['games']);
        file_put_contents($debug_log, "[$timestamp] SUCCESS: Retrieved $game_count games\n", FILE_APPEND);
        
        // Log first game details if available
        if ($game_count > 0) {
            $first_game = $data['scoreboard']['games'][0];
            $game_info = sprintf(
                "%s vs %s - Status: %s, Home: %d, Away: %d",
                $first_game['awayTeam']['teamTricode'],
                $first_game['homeTeam']['teamTricode'],
                $first_game['gameStatusText'],
                $first_game['homeTeam']['score'],
                $first_game['awayTeam']['score']
            );
            file_put_contents($debug_log, "[$timestamp] First game: $game_info\n", FILE_APPEND);
        }
        
        return $data;
        
    } catch (Exception $e) {
        $error_msg = "Exception: " . $e->getMessage();
        error_log("getAPIScores error: " . $error_msg);
        file_put_contents($debug_log, "[$timestamp] EXCEPTION: $error_msg\n", FILE_APPEND);
        file_put_contents($debug_log, "[$timestamp] Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
        return ['scoreboard' => ['games' => []]];
    }
}

/**
 * Get latest scores for games, merging database and API data
 */
function getLatestGameScores($games, $api_scores) {
    $latest_scores = [];
    
    if (!isset($api_scores['scoreboard']['games'])) {
        return $latest_scores;
    }
    
    foreach ($games as $game) {
        // FIXED: Include date in game key to prevent mixing scores from different dates
        $game_key = $game['date'] . '_' . $game['home_team'] . ' vs ' . $game['away_team'];
        
        // Default to database scores
        $latest_scores[$game_key] = [
            'home_points' => $game['home_points'] ?? 0,
            'away_points' => $game['away_points'] ?? 0,
            'status' => $game['status_long'] ?? 'Scheduled',
            'source' => 'database'
        ];
        
        // Try to find matching game in API data
        foreach ($api_scores['scoreboard']['games'] as $api_game) {
            $api_home_team = $api_game['homeTeam']['teamCity'] . ' ' . $api_game['homeTeam']['teamName'];
            $api_away_team = $api_game['awayTeam']['teamCity'] . ' ' . $api_game['awayTeam']['teamName'];
            
            // FIXED: Match by team names AND date to prevent mixing games from different dates
            // Convert UTC time to EST date to match database dates
            $utc_datetime = new DateTime($api_game['gameTimeUTC']);
            $utc_datetime->setTimezone(new DateTimeZone('America/New_York'));
            $api_game_date = $utc_datetime->format('Y-m-d');
            
            if ($api_home_team === $game['home_team'] && 
                $api_away_team === $game['away_team'] && 
                $api_game_date === $game['date']) {
                // Get status text with parsed game clock
                $status = 'Scheduled';
                if ($api_game['gameStatus'] == 1) {
                    $status = 'Scheduled';
                } elseif ($api_game['gameStatus'] == 2) {
                    $status = 'Q' . $api_game['period'];
                    if ($api_game['gameClock']) {
                        $status .= ' - ' . parseGameClock($api_game['gameClock']);
                    }
                } elseif ($api_game['gameStatus'] == 3) {
                    $status = 'Final';
                }
                
                $latest_scores[$game_key] = [
                    'home_points' => $api_game['homeTeam']['score'] ?? 0,
                    'away_points' => $api_game['awayTeam']['score'] ?? 0,
                    'status' => $status,
                    'source' => 'api',
                    'game_status' => $api_game['gameStatus'],
                    'period' => $api_game['period'] ?? 0,
                    'clock' => parseGameClock($api_game['gameClock'] ?? '')
                ];
                break;
            }
        }
    }
    
    return $latest_scores;
}

/**
 * Get quarter-by-quarter scores from API data
 */
function getQuarterScores($home_team, $away_team, $api_scores) {
    if (!isset($api_scores['scoreboard']['games'])) {
        return [];
    }
    
    foreach ($api_scores['scoreboard']['games'] as $api_game) {
        $api_home_team = $api_game['homeTeam']['teamCity'] . ' ' . $api_game['homeTeam']['teamName'];
        $api_away_team = $api_game['awayTeam']['teamCity'] . ' ' . $api_game['awayTeam']['teamName'];
        
        if ($api_home_team === $home_team && $api_away_team === $away_team) {
            $home_quarters = [];
            $away_quarters = [];
            
            foreach ($api_game['homeTeam']['periods'] as $period) {
                if ($period['periodType'] === 'REGULAR') {
                    $home_quarters[] = $period['score'];
                }
            }
            
            foreach ($api_game['awayTeam']['periods'] as $period) {
                if ($period['periodType'] === 'REGULAR') {
                    $away_quarters[] = $period['score'];
                }
            }
            
            return [
                'home' => $home_quarters,
                'away' => $away_quarters,
                'home_total' => $api_game['homeTeam']['score'] ?? 0,
                'away_total' => $api_game['awayTeam']['score'] ?? 0
            ];
        }
    }
    
    return [];
}

/**
 * Sync final games from API to database BEFORE standings are read.
 * This fires the MySQL trigger immediately so the leaderboard is current.
 * Returns the API scores for reuse (avoids calling the API twice).
 */
function syncFinalGames($pdo, $effectiveDate) {
    $debug_log = "/tmp/game_scores_debug.log";
    $timestamp = date('Y-m-d H:i:s');
    
    // Get API scores (this is the same call getAPIScores makes)
    $api_scores = getAPIScores();
    
    if (empty($api_scores['scoreboard']['games'])) {
        return $api_scores; // No games from API, nothing to sync
    }
    
    // Get today's non-final games from the database
    $stmt = $pdo->prepare("
        SELECT id, home_team, away_team, home_points, away_points, 
               status_long, date, home_team_code, away_team_code
        FROM games 
        WHERE date = ? 
        AND status_long NOT IN ('Final', 'Finished')
    ");
    $stmt->execute([$effectiveDate]);
    $db_games = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($db_games)) {
        return $api_scores; // All games already final in DB
    }
    
    $updated_count = 0;
    
    foreach ($api_scores['scoreboard']['games'] as $api_game) {
        // Only care about games the API says are Final (gameStatus == 3)
        if ($api_game['gameStatus'] != 3) {
            continue;
        }
        
        $api_home = $api_game['homeTeam']['teamCity'] . ' ' . $api_game['homeTeam']['teamName'];
        $api_away = $api_game['awayTeam']['teamCity'] . ' ' . $api_game['awayTeam']['teamName'];
        $api_home_score = $api_game['homeTeam']['score'] ?? 0;
        $api_away_score = $api_game['awayTeam']['score'] ?? 0;
        
        // Convert API UTC time to EST date for matching
        $utc_dt = new DateTime($api_game['gameTimeUTC']);
        $utc_dt->setTimezone(new DateTimeZone('America/New_York'));
        $api_date = $utc_dt->format('Y-m-d');
        
        // Find matching non-final game in DB
        foreach ($db_games as $db_game) {
            if ($db_game['home_team'] === $api_home && 
                $db_game['away_team'] === $api_away &&
                $db_game['date'] === $api_date) {
                
                // This game is Final in API but not in DB — update it
                // This UPDATE fires the MySQL trigger which updates 2025_2026 standings
                $update_stmt = $pdo->prepare("
                    UPDATE games 
                    SET status_long = 'Final', 
                        home_points = ?, 
                        away_points = ?
                    WHERE id = ?
                ");
                $update_stmt->execute([$api_home_score, $api_away_score, $db_game['id']]);
                
                $updated_count++;
                file_put_contents($debug_log, 
                    "[$timestamp] SYNC FINAL: {$api_away} @ {$api_home} — " .
                    "{$api_away_score}-{$api_home_score} (game_id: {$db_game['id']})\n", 
                    FILE_APPEND
                );
                
                break; // Found the match, move to next API game
            }
        }
    }
    
    if ($updated_count > 0) {
        file_put_contents($debug_log, 
            "[$timestamp] SYNC COMPLETE: Updated $updated_count game(s) to Final\n", 
            FILE_APPEND
        );
    }
    
    return $api_scores; // Return for reuse in game display
}

/**
 * Format team name from abbreviation to full name
 */
function getFullTeamName($abbr) {
    $teams = [
        'ATL' => 'Atlanta Hawks',
        'BOS' => 'Boston Celtics',
        'BKN' => 'Brooklyn Nets',
        'CHA' => 'Charlotte Hornets',
        'CHI' => 'Chicago Bulls',
        'CLE' => 'Cleveland Cavaliers',
        'DAL' => 'Dallas Mavericks',
        'DEN' => 'Denver Nuggets',
        'DET' => 'Detroit Pistons',
        'GSW' => 'Golden State Warriors',
        'HOU' => 'Houston Rockets',
        'IND' => 'Indiana Pacers',
        'LAC' => 'LA Clippers',
        'LAL' => 'Los Angeles Lakers',
        'MEM' => 'Memphis Grizzlies',
        'MIA' => 'Miami Heat',
        'MIL' => 'Milwaukee Bucks',
        'MIN' => 'Minnesota Timberwolves',
        'NOP' => 'New Orleans Pelicans',
        'NYK' => 'New York Knicks',
        'OKC' => 'Oklahoma City Thunder',
        'ORL' => 'Orlando Magic',
        'PHI' => 'Philadelphia 76ers',
        'PHX' => 'Phoenix Suns',
        'POR' => 'Portland Trail Blazers',
        'SAC' => 'Sacramento Kings',
        'SAS' => 'San Antonio Spurs',
        'TOR' => 'Toronto Raptors',
        'UTA' => 'Utah Jazz',
        'WAS' => 'Washington Wizards'
    ];
    
    return $teams[$abbr] ?? $abbr;
}

/**
 * Extract NBA CDN game ID from scoreboard data by matching team names
 */
function getGameIdFromScoreboard($home_team, $away_team, $api_scores) {
    if (!isset($api_scores['scoreboard']['games'])) {
        return null;
    }
    
    foreach ($api_scores['scoreboard']['games'] as $api_game) {
        $api_home = $api_game['homeTeam']['teamCity'] . ' ' . $api_game['homeTeam']['teamName'];
        $api_away = $api_game['awayTeam']['teamCity'] . ' ' . $api_game['awayTeam']['teamName'];
        
        if ($api_home === $home_team && $api_away === $away_team) {
            return $api_game['gameId'] ?? null;
        }
    }
    
    return null;
}

/**
 * Fetch the latest play-by-play action for a live game
 * Returns array with play data or null if unavailable
 */
function getLatestPlay($game_id) {
    if (empty($game_id)) return null;
    
    try {
        $script_path = '/data/www/default/nba-wins-platform/core/get_playbyplay.py';
        $escaped_id = escapeshellarg($game_id);
        $command = "timeout 8 python3 $script_path $escaped_id 2>&1";
        
        $output = shell_exec($command);
        if (!$output) return null;
        
        $data = json_decode($output, true);
        if (!$data || isset($data['error'])) {
            error_log("getLatestPlay error: " . ($data['error'] ?? 'Unknown'));
            return null;
        }
        
        return $data['play'] ?? null;
        
    } catch (Exception $e) {
        error_log("getLatestPlay exception: " . $e->getMessage());
        return null;
    }
}