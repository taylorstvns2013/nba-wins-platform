<?php
// fetch_games.php - Fetch and store NBA games data with Final game protection
// Location: /data/www/default/nba-wins-platform/tasks/

// Include CLI-specific database connection (no authentication system)
require_once '/data/www/default/nba-wins-platform/config/db_connection_cli.php';

// API details
$api_host = 'api-nba-v1.p.rapidapi.com';
$api_key = 'RAPIDAPI_KEY_REMOVED';

// Function to check if a column exists
function columnExists($pdo, $table, $column) {
    $sql = "SHOW COLUMNS FROM $table LIKE '$column'";
    $result = $pdo->query($sql);
    return $result->rowCount() > 0;
}

// Add columns if they don't exist
try {
    if (!columnExists($pdo, 'games', 'home_team_code')) {
        $pdo->exec("ALTER TABLE games ADD COLUMN home_team_code VARCHAR(3) AFTER home_team");
        echo "Added home_team_code column\n";
    }
    if (!columnExists($pdo, 'games', 'away_team_code')) {
        $pdo->exec("ALTER TABLE games ADD COLUMN away_team_code VARCHAR(3) AFTER away_team");
        echo "Added away_team_code column\n";
    }
} catch(PDOException $e) {
    echo "Error adding columns: " . $e->getMessage() . "\n";
    error_log("Error adding columns: " . $e->getMessage());
}

// Set timezone to EST
date_default_timezone_set('America/New_York');

// Function to fetch and store/update games for the 2025 season
function fetchAndStoreGames($pdo, $api_host, $api_key) {
    echo "Starting to fetch 2025 season games...\n";
    
    // Fetch games from API
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://$api_host/games?season=2025",  // Updated to 2025
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,  // Add timeout
        CURLOPT_HTTPHEADER => [
            "X-RapidAPI-Host: $api_host",
            "X-RapidAPI-Key: $api_key"
        ],
    ]);

    $response = curl_exec($curl);
    $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $err = curl_error($curl);
    curl_close($curl);

    echo "API HTTP Code: $httpcode\n";

    if ($err) {
        echo "cURL Error: $err\n";
        error_log("cURL Error: $err");
        return false;
    }

    if ($httpcode != 200) {
        echo "API returned HTTP $httpcode\n";
        echo "Response: " . substr($response, 0, 500) . "\n";
        error_log("API returned HTTP $httpcode: " . $response);
        return false;
    }

    $games = json_decode($response, true);
    
    if (!$games) {
        echo "Failed to decode JSON response\n";
        echo "Raw response (first 500 chars): " . substr($response, 0, 500) . "\n";
        error_log("Failed to decode JSON response: " . $response);
        return false;
    }
    
    if (!isset($games['response'])) {
        echo "No 'response' key in API data\n";
        echo "Available keys: " . implode(', ', array_keys($games)) . "\n";
        if (isset($games['error'])) {
            echo "API Error: " . $games['error'] . "\n";
        }
        error_log("Invalid API response format: " . json_encode($games));
        return false;
    }
    
    if (!is_array($games['response'])) {
        echo "Response is not an array\n";
        error_log("API response is not an array");
        return false;
    }
    
    echo "Found " . count($games['response']) . " games from API\n";

    if (count($games['response']) == 0) {
        echo "No games returned from API for 2025 season\n";
        return true; // Not an error, just no games yet
    }

    // Prepare SQL statements
    $check_stmt = $pdo->prepare("SELECT id FROM games WHERE date = ? AND home_team = ? AND away_team = ?");
    $insert_stmt = $pdo->prepare("INSERT INTO games (date, start_time, home_team, home_team_code, away_team, away_team_code, home_points, away_points, arena, status_long, home_logo, away_logo) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $update_stmt = $pdo->prepare("UPDATE games SET start_time = ?, home_team_code = ?, away_team_code = ?, home_points = ?, away_points = ?, arena = ?, status_long = ?, home_logo = ?, away_logo = ? 
                                  WHERE id = ?");

    $processed_count = 0;
    $updated_count = 0;
    $inserted_count = 0;
    $skipped_final_count = 0;
    $error_count = 0;

    // Insert or update games in database
    foreach ($games['response'] as $game) {
        try {
            // Debug first few games
            if ($processed_count < 3) {
                echo "Processing game: " . json_encode($game['teams']['home']['name'] ?? 'unknown') . " vs " . json_encode($game['teams']['visitors']['name'] ?? 'unknown') . "\n";
            }
            
            $utc_date = new DateTime($game['date']['start'], new DateTimeZone('UTC'));
            $utc_date->setTimezone(new DateTimeZone('America/New_York'));
            
            $game_date = $utc_date->format('Y-m-d');
            $start_time = $utc_date->format('Y-m-d H:i:s');
            
            $home_team = isset($game['teams']['home']['name']) ? $game['teams']['home']['name'] : 'Unknown';
            $home_team_code = isset($game['teams']['home']['code']) ? $game['teams']['home']['code'] : '';
            $away_team = isset($game['teams']['visitors']['name']) ? $game['teams']['visitors']['name'] : 'Unknown';
            $away_team_code = isset($game['teams']['visitors']['code']) ? $game['teams']['visitors']['code'] : '';
            
            $home_points = isset($game['scores']['home']['points']) ? $game['scores']['home']['points'] : 0;
            $away_points = isset($game['scores']['visitors']['points']) ? $game['scores']['visitors']['points'] : 0;
            
            $arena = isset($game['arena']['name']) ? $game['arena']['name'] : 'Unknown';
            $status_long = isset($game['status']['long']) ? $game['status']['long'] : 'Unknown';
            
            $home_logo = isset($game['teams']['home']['logo']) ? $game['teams']['home']['logo'] : '';
            $away_logo = isset($game['teams']['visitors']['logo']) ? $game['teams']['visitors']['logo'] : '';

            // Check if the game already exists
            $check_stmt->execute([$game_date, $home_team, $away_team]);
            $existing_game = $check_stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing_game) {
                // PROTECTION: Check if game is already marked as Final
                $check_final_stmt = $pdo->prepare("SELECT status_long FROM games WHERE id = ?");
                $check_final_stmt->execute([$existing_game['id']]);
                $game_status = $check_final_stmt->fetch(PDO::FETCH_ASSOC);
                
                $is_final = false;
                if ($game_status && $game_status['status_long']) {
                    $status_lower = strtolower($game_status['status_long']);
                    // Check for various "final" status indicators
                    $is_final = (
                        strpos($status_lower, 'final') !== false || 
                        strpos($status_lower, 'finished') !== false ||
                        $status_lower === 'completed'
                    );
                }
                
                if ($is_final) {
                    // Skip updating final games to preserve data integrity
                    $skipped_final_count++;
                    if ($skipped_final_count <= 5) {
                        echo "✓ Skipped final game: $home_team vs $away_team on $game_date (Status: " . $game_status['status_long'] . ")\n";
                    }
                } else {
                    // Update existing game that's not final
                    $update_stmt->execute([
                        $start_time,
                        $home_team_code,
                        $away_team_code,
                        $home_points,
                        $away_points,
                        $arena,
                        $status_long,
                        $home_logo,
                        $away_logo,
                        $existing_game['id']
                    ]);
                    $updated_count++;
                    if ($updated_count <= 5) {
                        echo "↻ Updated game: $home_team vs $away_team on $game_date (Status: $status_long)\n";
                    }
                }
            } else {
                // Insert new game
                $insert_stmt->execute([
                    $game_date,
                    $start_time,
                    $home_team,
                    $home_team_code,
                    $away_team,
                    $away_team_code,
                    $home_points,
                    $away_points,
                    $arena,
                    $status_long,
                    $home_logo,
                    $away_logo
                ]);
                $inserted_count++;
                if ($inserted_count <= 5) {
                    echo "✚ Inserted new game: $home_team vs $away_team on $game_date\n";
                }
            }
            $processed_count++;
            
        } catch (PDOException $e) {
            $error_count++;
            echo "✗ Error processing game #$processed_count: " . $e->getMessage() . "\n";
            error_log("Error processing game for $game_date: " . $e->getMessage());
            continue;
        }
    }

    // Log the update
    try {
        $update_time = date('Y-m-d H:i:s');
        $details = "Processed: $processed_count, Updated: $updated_count, Inserted: $inserted_count, Skipped (Final): $skipped_final_count, Errors: $error_count";
        
        $log_stmt = $pdo->prepare("INSERT INTO update_log (update_time, script_name, details) VALUES (?, ?, ?)");
        $log_stmt->execute([$update_time, 'fetch_games.php', $details]);
        
        echo "\n====================================\n";
        echo "Games processing completed:\n";
        echo "- Processed: $processed_count games\n";
        echo "- Updated: $updated_count games\n";
        echo "- Inserted: $inserted_count new games\n";
        echo "- Skipped: $skipped_final_count final games (protected)\n";
        echo "- Errors: $error_count\n";
        echo "Last updated: $update_time\n";
        echo "====================================\n";
        
        return true;
    } catch (PDOException $e) {
        echo "Error logging update: " . $e->getMessage() . "\n";
        error_log("Error logging update: " . $e->getMessage());
        return false;
    }
}

// Test database connection first
echo "Testing database connection...\n";
try {
    $test_stmt = $pdo->query("SELECT COUNT(*) as count FROM games");
    $result = $test_stmt->fetch();
    echo "Database connection successful. Found " . $result['count'] . " existing games.\n";
} catch (PDOException $e) {
    echo "Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Main execution
try {
    if (fetchAndStoreGames($pdo, $api_host, $api_key)) {
        echo "fetch_games.php completed successfully\n";
    } else {
        echo "fetch_games.php failed\n";
    }
} catch (Exception $e) {
    echo "fetch_games.php error: " . $e->getMessage() . "\n";
    error_log("fetch_games.php error: " . $e->getMessage());
}
?>