<?php
// NBA API Score Integration System
// Save as: nba-wins-platform/core/game_scores_helper.php

function getAPIScores() {
    // Try multiple sources with fallback
    $scores = [];
    
    // Try ESPN API first (most reliable)
    $scores = getESPNScores();
    if (!empty($scores)) {
        return $scores;
    }
    
    // Fallback to NBA Official API
    $scores = getNBAOfficialScores();
    if (!empty($scores)) {
        return $scores;
    }
    
    // Final fallback to cached data
    return getCachedScores();
}

function getESPNScores() {
    $today = date('Ymd');
    $url = "https://site.api.espn.com/apis/site/v2/sports/basketball/nba/scoreboard?dates={$today}";
    
    // Check cache first (5 minute cache)
    $cache_key = "espn_scores_{$today}";
    $cache_file = "/tmp/{$cache_key}.json";
    
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 300) {
        $cached_data = file_get_contents($cache_file);
        if ($cached_data !== false) {
            return json_decode($cached_data, true);
        }
    }
    
    try {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Mozilla/5.0 (compatible; NBA-Wins-Pool/1.0)\r\n",
                'timeout' => 15
            ]
        ]);
        
        $response = file_get_contents($url, false, $context);
        if ($response !== false) {
            $data = json_decode($response, true);
            $parsed_scores = parseESPNData($data);
            
            // Cache the result
            file_put_contents($cache_file, json_encode($parsed_scores));
            
            return $parsed_scores;
        }
    } catch (Exception $e) {
        error_log("ESPN API Error: " . $e->getMessage());
    }
    
    return [];
}

function getNBAOfficialScores() {
    $today = date('m/d/Y');
    $url = "https://stats.nba.com/stats/scoreboardV2?GameDate={$today}&LeagueID=00&DayOffset=0";
    
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Referer: https://www.nba.com/',
        'Host: stats.nba.com',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.9',
        'Connection: keep-alive'
    ];
    
    try {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 10
            ]
        ]);
        
        $response = file_get_contents($url, false, $context);
        if ($response !== false) {
            $data = json_decode($response, true);
            return parseNBAOfficialData($data);
        }
    } catch (Exception $e) {
        error_log("NBA Official API Error: " . $e->getMessage());
    }
    
    return [];
}

function parseESPNData($data) {
    $games = [];
    
    if (!isset($data['events']) || !is_array($data['events'])) {
        return $games;
    }
    
    foreach ($data['events'] as $event) {
        $game_data = [
            'game_id' => $event['id'] ?? null,
            'status' => $event['status']['type']['id'] ?? 1, // 1=scheduled, 2=live, 3=finished
            'status_long' => $event['status']['type']['description'] ?? 'Scheduled',
            'game_clock' => $event['status']['displayClock'] ?? null,
            'period' => $event['status']['period'] ?? null,
            'source' => 'espn_api'
        ];
        
        if (isset($event['competitions'][0]['competitors'])) {
            $competitors = $event['competitions'][0]['competitors'];
            
            foreach ($competitors as $competitor) {
                $team_name = $competitor['team']['displayName'] ?? '';
                $score = (int)($competitor['score'] ?? 0);
                $is_home = ($competitor['homeAway'] ?? '') === 'home';
                
                if ($is_home) {
                    $game_data['home_team'] = $team_name;
                    $game_data['home_points'] = $score;
                    $game_data['home_team_code'] = $competitor['team']['abbreviation'] ?? '';
                } else {
                    $game_data['away_team'] = $team_name;
                    $game_data['away_points'] = $score;
                    $game_data['away_team_code'] = $competitor['team']['abbreviation'] ?? '';
                }
            }
        }
        
        if (isset($game_data['home_team']) && isset($game_data['away_team'])) {
            $games[] = $game_data;
        }
    }
    
    return $games;
}

function parseNBAOfficialData($data) {
    $games = [];
    
    if (!isset($data['resultSets'])) {
        return $games;
    }
    
    foreach ($data['resultSets'] as $resultSet) {
        if ($resultSet['name'] === 'GameHeader') {
            $headers = $resultSet['headers'];
            $rows = $resultSet['rowSet'];
            
            foreach ($rows as $row) {
                $game_data = array_combine($headers, $row);
                
                $games[] = [
                    'game_id' => $game_data['GAME_ID'] ?? null,
                    'home_team' => $game_data['HOME_TEAM_NAME'] ?? '',
                    'away_team' => $game_data['VISITOR_TEAM_NAME'] ?? '',
                    'home_points' => (int)($game_data['PTS_HOME'] ?? 0),
                    'away_points' => (int)($game_data['PTS_AWAY'] ?? 0),
                    'status' => $game_data['GAME_STATUS_ID'] ?? 1,
                    'status_long' => $game_data['GAME_STATUS_TEXT'] ?? 'Scheduled',
                    'source' => 'nba_official_api'
                ];
            }
        }
    }
    
    return $games;
}

function getLatestGameScores($database_games, $api_scores) {
    $latest_scores = [];
    
    foreach ($database_games as $db_game) {
        $game_key = $db_game['home_team'] . ' vs ' . $db_game['away_team'];
        
        // Find matching API game
        $api_game = null;
        foreach ($api_scores as $api_score) {
            if (matchTeams($db_game['home_team'], $api_score['home_team']) && 
                matchTeams($db_game['away_team'], $api_score['away_team'])) {
                $api_game = $api_score;
                break;
            }
        }
        
        if ($api_game) {
            // Use API data if available and more recent
            if ($api_game['status'] == 2 || // Live game
                ($api_game['status'] == 3 && $api_game['home_points'] > $db_game['home_points'])) {
                
                $latest_scores[$game_key] = [
                    'home_points' => $api_game['home_points'],
                    'away_points' => $api_game['away_points'],
                    'status' => $api_game['status'],
                    'status_long' => $api_game['status_long'],
                    'game_clock' => $api_game['game_clock'] ?? null,
                    'period' => $api_game['period'] ?? null,
                    'source' => $api_game['source']
                ];
            }
        }
    }
    
    return $latest_scores;
}

function matchTeams($db_team, $api_team) {
    // Handle team name variations
    $team_mappings = [
        'Los Angeles Lakers' => ['Lakers', 'LAL', 'L.A. Lakers'],
        'Golden State Warriors' => ['Warriors', 'GSW', 'GS Warriors'],
        'Boston Celtics' => ['Celtics', 'BOS'],
        'Miami Heat' => ['Heat', 'MIA'],
        'Brooklyn Nets' => ['Nets', 'BKN', 'BRK'],
        'New York Knicks' => ['Knicks', 'NYK', 'NY Knicks'],
        'Philadelphia 76ers' => ['76ers', 'PHI', 'Sixers'],
        'Los Angeles Clippers' => ['Clippers', 'LAC', 'L.A. Clippers'],
        'Phoenix Suns' => ['Suns', 'PHX'],
        'Milwaukee Bucks' => ['Bucks', 'MIL'],
        'Chicago Bulls' => ['Bulls', 'CHI'],
        'Denver Nuggets' => ['Nuggets', 'DEN'],
        'Memphis Grizzlies' => ['Grizzlies', 'MEM'],
        'New Orleans Pelicans' => ['Pelicans', 'NOP', 'NO Pelicans'],
        'Oklahoma City Thunder' => ['Thunder', 'OKC', 'OKC Thunder'],
        'Indiana Pacers' => ['Pacers', 'IND'],
        'Cleveland Cavaliers' => ['Cavaliers', 'CLE', 'Cavs'],
        'Toronto Raptors' => ['Raptors', 'TOR'],
        'Charlotte Hornets' => ['Hornets', 'CHA'],
        'Atlanta Hawks' => ['Hawks', 'ATL'],
        'Washington Wizards' => ['Wizards', 'WAS'],
        'Orlando Magic' => ['Magic', 'ORL'],
        'Detroit Pistons' => ['Pistons', 'DET'],
        'San Antonio Spurs' => ['Spurs', 'SAS', 'SA Spurs'],
        'Houston Rockets' => ['Rockets', 'HOU'],
        'Dallas Mavericks' => ['Mavericks', 'DAL', 'Mavs'],
        'Utah Jazz' => ['Jazz', 'UTA'],
        'Minnesota Timberwolves' => ['Timberwolves', 'MIN', 'Wolves'],
        'Portland Trail Blazers' => ['Trail Blazers', 'POR', 'Blazers'],
        'Sacramento Kings' => ['Kings', 'SAC']
    ];
    
    // Direct match
    if ($db_team === $api_team) {
        return true;
    }
    
    // Check mappings
    foreach ($team_mappings as $full_name => $variations) {
        if ($db_team === $full_name && in_array($api_team, $variations)) {
            return true;
        }
        if ($api_team === $full_name && in_array($db_team, $variations)) {
            return true;
        }
        if (in_array($db_team, $variations) && in_array($api_team, $variations)) {
            return true;
        }
    }
    
    return false;
}

function getCachedScores() {
    // Return cached scores from previous API calls
    $cache_file = "/tmp/last_known_scores.json";
    
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 3600) { // 1 hour cache
        $cached_data = file_get_contents($cache_file);
        if ($cached_data !== false) {
            return json_decode($cached_data, true);
        }
    }
    
    return [];
}

// Rate limiting helper
function isRateLimited($api_source) {
    $rate_limit_file = "/tmp/rate_limit_{$api_source}.txt";
    
    if (file_exists($rate_limit_file)) {
        $last_call = (int)file_get_contents($rate_limit_file);
        $time_diff = time() - $last_call;
        
        // ESPN: 600 requests per hour (1 per 6 seconds)
        // NBA Official: 200 requests per hour (1 per 18 seconds)
        $min_interval = ($api_source === 'espn') ? 6 : 18;
        
        if ($time_diff < $min_interval) {
            return true;
        }
    }
    
    file_put_contents($rate_limit_file, time());
    return false;
}

// Error handling and logging
function logAPIError($source, $error, $context = []) {
    $log_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'source' => $source,
        'error' => $error,
        'context' => $context
    ];
    
    error_log("NBA API Error: " . json_encode($log_entry));
    
    // Store in database for monitoring
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO api_error_log (source, error_message, context, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$source, $error, json_encode($context)]);
    } catch (Exception $e) {
        error_log("Failed to log API error to database: " . $e->getMessage());
    }
}
?>