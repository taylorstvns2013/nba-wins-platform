<?php
/**
 * Smart Team Stats Updater via RapidAPI
 * 
 * Strategy: Only update teams that played yesterday
 * Saves ~80-90% of API calls during the season
 * 
 * Falls back to full refresh once per week on Sundays
 * 
 * FIXED: Now uses rapidapi_id column for correct API team IDs
 * IMPROVED: Advanced rate limit handling with retry logic and exponential backoff
 */

require_once '/data/www/default/nba-wins-platform/config/db_connection_cli.php';
require_once(__DIR__ . '/../config/season_config.php');
require_once(__DIR__ . '/../config/secrets.php');
$seasonConfig = getSeasonConfig();

// RapidAPI Configuration
$rapidapi_key = RAPIDAPI_KEY;
$rapidapi_host = 'api-nba-v1.p.rapidapi.com';
$season = $seasonConfig['api_season_rapid'];

// Rate Limiting Configuration (BASIC plan: ~10 requests/minute)
$base_delay = 7; // Base delay between requests in seconds (safer than 6)
$max_retries = 3; // Maximum retry attempts for rate limit errors
$retry_base_delay = 30; // Base delay for exponential backoff on 429 errors (seconds)
$calls_per_minute_limit = 9; // Conservative limit (plan allows ~10)
$minute_window_calls = []; // Track API calls in current minute

// Determine update strategy
$yesterday = date('Y-m-d', strtotime('-1 day'));
$today_day = date('w'); // 0 = Sunday

// Sunday = Full refresh, otherwise smart update
$smart_update = ($today_day != 0);

echo "=== NBA Team Stats Updater ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Mode: " . ($smart_update ? "Smart (teams that played)" : "Full refresh") . "\n";
echo "Rate Limit: {$calls_per_minute_limit} calls/minute ({$base_delay}s delay)\n\n";

if ($smart_update) {
    // Get teams that played yesterday - USING RAPIDAPI_ID
    $stmt = $pdo->prepare("
        SELECT DISTINCT t.id, t.rapidapi_id, t.name 
        FROM nba_teams t
        JOIN games g ON (g.home_team = t.name OR g.away_team = t.name)
        WHERE g.date = ?
        AND g.status_long IN ('Final', 'Finished')
        AND t.rapidapi_id IS NOT NULL
    ");
    $stmt->execute([$yesterday]);
    $teams_to_update = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($teams_to_update) . " teams that played yesterday\n";
    
} else {
    // Full refresh - get all NBA teams - USING RAPIDAPI_ID
    $stmt = $pdo->query("
        SELECT id, rapidapi_id, name 
        FROM nba_teams 
        WHERE nbaFranchise = true 
        AND rapidapi_id IS NOT NULL
        ORDER BY name
    ");
    $teams_to_update = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Sunday full refresh: " . count($teams_to_update) . " teams\n";
}

if (empty($teams_to_update)) {
    echo "No teams to update today\n";
    exit(0);
}

// Function to manage rate limiting per minute
function canMakeRequest(&$minute_window_calls, $calls_per_minute_limit) {
    $current_time = time();
    
    // Remove calls older than 60 seconds
    $minute_window_calls = array_filter($minute_window_calls, function($timestamp) use ($current_time) {
        return ($current_time - $timestamp) < 60;
    });
    
    return count($minute_window_calls) < $calls_per_minute_limit;
}

// Function to wait until we can make another request
function waitForRateLimit(&$minute_window_calls, $calls_per_minute_limit) {
    while (!canMakeRequest($minute_window_calls, $calls_per_minute_limit)) {
        $oldest_call = min($minute_window_calls);
        $wait_time = 60 - (time() - $oldest_call) + 1; // +1 for safety
        
        if ($wait_time > 0) {
            echo "Rate limit reached, waiting {$wait_time}s...\n";
            sleep($wait_time);
        }
        
        // Clean up old calls
        $current_time = time();
        $minute_window_calls = array_filter($minute_window_calls, function($timestamp) use ($current_time) {
            return ($current_time - $timestamp) < 60;
        });
    }
}

// Function to call RapidAPI with retry logic
function fetchTeamStatsWithRetry($rapidapi_team_id, $season, $rapidapi_key, $rapidapi_host, $max_retries, $retry_base_delay, &$minute_window_calls, $calls_per_minute_limit) {
    $attempt = 0;
    
    while ($attempt < $max_retries) {
        // Wait if we've hit the rate limit
        waitForRateLimit($minute_window_calls, $calls_per_minute_limit);
        
        // Make the API call
        $result = fetchTeamStats($rapidapi_team_id, $season, $rapidapi_key, $rapidapi_host);
        
        // Record this API call
        $minute_window_calls[] = time();
        
        // If successful or non-429 error, return result
        if ($result !== false) {
            return $result;
        }
        
        // If we got a 429 error, wait with exponential backoff
        $attempt++;
        if ($attempt < $max_retries) {
            $wait_time = $retry_base_delay * pow(2, $attempt - 1); // Exponential backoff
            echo "(Attempt {$attempt}/{$max_retries}) Rate limited, waiting {$wait_time}s... ";
            sleep($wait_time);
        }
    }
    
    return null; // All retries failed
}

// Function to call RapidAPI
function fetchTeamStats($rapidapi_team_id, $season, $rapidapi_key, $rapidapi_host) {
    // CRITICAL: Using stage=2 for regular season only (no preseason)
    $url = "https://$rapidapi_host/teams/statistics?id=$rapidapi_team_id&season=$season&stage=2";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => [
            'x-rapidapi-host: ' . $rapidapi_host,
            'x-rapidapi-key: ' . $rapidapi_key,
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    // Return false for 429 errors (to trigger retry)
    if ($http_code == 429) {
        return false;
    }
    
    if ($http_code != 200) {
        error_log("API Error for team $rapidapi_team_id: HTTP $http_code" . ($curl_error ? " - $curl_error" : ""));
        error_log("Response: " . substr($response, 0, 200));
        return null;
    }
    
    $data = json_decode($response, true);
    
    if (isset($data['response'][0])) {
        return $data['response'][0];
    }
    
    error_log("No response data for team $rapidapi_team_id: " . substr($response, 0, 200));
    return null;
}

// Update each team
$success_count = 0;
$error_count = 0;
$retry_count = 0;
$total_api_calls = 0;
$start_time = time();

foreach ($teams_to_update as $index => $team) {
    $team_num = $index + 1;
    $total_teams = count($teams_to_update);
    
    echo "[{$team_num}/{$total_teams}] Updating {$team['name']} (API ID: {$team['rapidapi_id']})... ";
    
    // Fetch stats with retry logic
    $stats = fetchTeamStatsWithRetry(
        $team['rapidapi_id'], 
        $season, 
        $rapidapi_key, 
        $rapidapi_host,
        $max_retries,
        $retry_base_delay,
        $minute_window_calls,
        $calls_per_minute_limit
    );
    
    $total_api_calls = count($minute_window_calls);
    
    if ($stats) {
        try {
            // Upsert into database
            $stmt = $pdo->prepare("
                INSERT INTO nba_team_api_stats (
                    team_id, team_name, season, games_played,
                    points, fgm, fga, fg_pct,
                    ftm, fta, ft_pct,
                    tpm, tpa, tp_pct,
                    off_reb, def_reb, tot_reb,
                    assists, steals, blocks, turnovers, fouls, plus_minus,
                    fast_break_points, points_in_paint, second_chance_points,
                    points_off_turnovers, biggest_lead, longest_run,
                    api_call_date
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    CURDATE()
                ) ON DUPLICATE KEY UPDATE
                    games_played = VALUES(games_played),
                    points = VALUES(points),
                    fgm = VALUES(fgm),
                    fga = VALUES(fga),
                    fg_pct = VALUES(fg_pct),
                    ftm = VALUES(ftm),
                    fta = VALUES(fta),
                    ft_pct = VALUES(ft_pct),
                    tpm = VALUES(tpm),
                    tpa = VALUES(tpa),
                    tp_pct = VALUES(tp_pct),
                    off_reb = VALUES(off_reb),
                    def_reb = VALUES(def_reb),
                    tot_reb = VALUES(tot_reb),
                    assists = VALUES(assists),
                    steals = VALUES(steals),
                    blocks = VALUES(blocks),
                    turnovers = VALUES(turnovers),
                    fouls = VALUES(fouls),
                    plus_minus = VALUES(plus_minus),
                    fast_break_points = VALUES(fast_break_points),
                    points_in_paint = VALUES(points_in_paint),
                    second_chance_points = VALUES(second_chance_points),
                    points_off_turnovers = VALUES(points_off_turnovers),
                    biggest_lead = VALUES(biggest_lead),
                    longest_run = VALUES(longest_run),
                    api_call_date = VALUES(api_call_date),
                    last_updated = CURRENT_TIMESTAMP
            ");
            
            $stmt->execute([
                $team['id'],  // Your database ID (not RapidAPI ID)
                $team['name'],
                $season,
                $stats['games'] ?? 0,
                $stats['points'] ?? 0,
                $stats['fgm'] ?? 0,
                $stats['fga'] ?? 0,
                $stats['fgp'] ?? 0,
                $stats['ftm'] ?? 0,
                $stats['fta'] ?? 0,
                $stats['ftp'] ?? 0,
                $stats['tpm'] ?? 0,
                $stats['tpa'] ?? 0,
                $stats['tpp'] ?? 0,
                $stats['offReb'] ?? 0,
                $stats['defReb'] ?? 0,
                $stats['totReb'] ?? 0,
                $stats['assists'] ?? 0,
                $stats['steals'] ?? 0,
                $stats['blocks'] ?? 0,
                $stats['turnovers'] ?? 0,
                $stats['pFouls'] ?? 0,
                $stats['plusMinus'] ?? 0,
                $stats['fastBreakPoints'] ?? 0,
                $stats['pointsInPaint'] ?? 0,
                $stats['secondChancePoints'] ?? 0,
                $stats['pointsOffTurnovers'] ?? 0,
                $stats['biggestLead'] ?? 0,
                $stats['longestRun'] ?? 0
            ]);
            
            echo "✓ ({$stats['games']} games)\n";
            $success_count++;
            
        } catch (Exception $e) {
            echo "✗ DB Error: " . $e->getMessage() . "\n";
            $error_count++;
        }
        
    } else {
        echo "✗ Failed after {$max_retries} retries\n";
        $error_count++;
    }
    
    // Rate limiting: Base delay between requests
    if ($index < $total_teams - 1) { // Don't sleep after last team
        sleep($base_delay);
    }
}

$elapsed_time = time() - $start_time;
$avg_time_per_team = $elapsed_time / count($teams_to_update);

echo "\n=== Summary ===\n";
echo "Success: $success_count\n";
echo "Errors: $error_count\n";
echo "API calls made: $total_api_calls\n";
echo "Time elapsed: {$elapsed_time}s (" . round($avg_time_per_team, 1) . "s per team)\n";
echo "Completed: " . date('Y-m-d H:i:s') . "\n";
?>