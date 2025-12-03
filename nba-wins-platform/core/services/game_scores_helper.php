<?php
function getAPIScores() {
    putenv("PYTHONPATH=/usr/local/lib/python3.9/site-packages:/usr/lib/python3.9/site-packages");
    $command = "python3 /data/www/default/tasks_nba.api/get_games.py";
    
    $output = shell_exec($command . " 2>&1");
    
    if ($output === null || empty($output)) {
        return [];
    }
    
    $decoded = json_decode($output, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [];
    }
    
    return $decoded;
}

function formatGameClock($clock) {
    $clock = trim(str_replace(['PT', 'S'], '', $clock));
    
    if (strpos($clock, 'M') !== false) {
        list($minutes, $seconds) = explode('M', $clock);
        $seconds = floatval($seconds);
        return sprintf("%d:%02d", intval($minutes), intval($seconds));
    }
    
    if (strpos($clock, ':') !== false) {
        return trim($clock);
    }
    
    return $clock;
}

function getLatestGameScores($db_games, $api_scores) {
    $latest_scores = [];
    $api_games = [];
    
    if (isset($api_scores['scoreboard']['games'])) {
        foreach ($api_scores['scoreboard']['games'] as $game) {
            $keys = [
                $game['homeTeam']['teamName'] . ' vs ' . $game['awayTeam']['teamName'],
                $game['homeTeam']['teamCity'] . ' vs ' . $game['awayTeam']['teamCity'],
                $game['homeTeam']['teamCity'] . ' ' . $game['homeTeam']['teamName'] . ' vs ' . 
                    $game['awayTeam']['teamCity'] . ' ' . $game['awayTeam']['teamName']
            ];
            
            $home_score = intval($game['homeTeam']['score']);
            $away_score = intval($game['awayTeam']['score']);
            $game_clock = formatGameClock($game['gameClock']);
            
            $game_data = [
                'home_team' => $game['homeTeam']['teamName'],
                'away_team' => $game['awayTeam']['teamName'],
                'home_score' => $home_score,
                'away_score' => $away_score,
                'total_points' => $home_score + $away_score,
                'status' => $game['gameStatus'],
                'game_clock' => $game_clock,
                'period' => $game['period'],
                'source' => 'api'
            ];
            
            foreach ($keys as $key) {
                $api_games[$key] = $game_data;
            }
        }
    }
    
    foreach ($db_games as $db_game) {
        $game_key = $db_game['home_team'] . ' vs ' . $db_game['away_team'];
        $db_total = intval($db_game['home_points']) + intval($db_game['away_points']);
        
        $api_match = null;
        foreach ($api_games as $key => $game) {
            if (stripos($key, $db_game['home_team']) !== false && 
                stripos($key, $db_game['away_team']) !== false) {
                $api_match = $game;
                break;
            }
        }
        
        if ($api_match && $api_match['status'] == 2) {
            $latest_scores[$game_key] = [
                'home_points' => $api_match['home_score'],
                'away_points' => $api_match['away_score'],
                'status' => $api_match['status'],
                'game_clock' => $api_match['game_clock'],
                'period' => $api_match['period'],
                'source' => 'api'
            ];
        } else {
            $latest_scores[$game_key] = [
                'home_points' => $db_game['home_points'],
                'away_points' => $db_game['away_points'],
                'status' => $db_game['status_long'],
                'game_clock' => null,
                'period' => null,
                'source' => 'db'
            ];
        }
    }
    
    return $latest_scores;
}
?>