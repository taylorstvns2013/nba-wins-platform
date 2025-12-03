<?php
// update_standings.php - Update NBA standings from API
// Location: /nba-wins-platform/tasks/

// Include database connection
require_once(__DIR__ . '/../config/db_connection_cli.php');

// RapidAPI configuration
$rapidapi_key = 'RAPIDAPI_KEY_REMOVED';
$rapidapi_host = 'api-nba-v1.p.rapidapi.com';

// Set timezone to EST
date_default_timezone_set('America/New_York');

// Function to fetch data from the API
function fetchStandings($rapidapi_key, $rapidapi_host) {
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api-nba-v1.p.rapidapi.com/standings?league=standard&season=2025",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "X-RapidAPI-Host: " . $rapidapi_host,
            "X-RapidAPI-Key: " . $rapidapi_key
        ]
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);

    curl_close($curl);

    if ($err) {
        throw new Exception("cURL Error: " . $err);
    }

    $data = json_decode($response, true);
    
    if (!isset($data['response']) || !is_array($data['response'])) {
        throw new Exception("Invalid API response format. Response: " . print_r($data, true));
    }

    return $data;
}

// Function to update standings while preserving existing data
function updateStandings($pdo, $standings) {
    // First, get all existing teams from the backup database
    $existingTeams = [];
    $stmt = $pdo->query("SELECT name FROM 2025_2026_backup");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existingTeams[$row['name']] = true;
    }

    // Prepare update statement for existing teams in backup table
    $updateStmt = $pdo->prepare("UPDATE 2025_2026_backup SET 
                                conference = :conference,
                                win = :win,
                                loss = :loss,
                                streak = :streak,
                                logo = :logo,
                                percentage = :percentage,
                                winstreak = :winstreak
                                WHERE name = :name");

    // Prepare insert statement for new teams in backup table
    $insertStmt = $pdo->prepare("INSERT INTO 2025_2026_backup 
                                (name, conference, win, loss, streak, logo, percentage, winstreak)
                                VALUES 
                                (:name, :conference, :win, :loss, :streak, :logo, :percentage, :winstreak)");

    $updated_count = 0;
    $inserted_count = 0;
    $error_count = 0;

    foreach ($standings['response'] as $team) {
        // Skip if team data is not valid
        if (!isset($team['team']['name'])) {
            $error_count++;
            continue;
        }

        $teamName = $team['team']['name'];
        
        // Calculate percentage
        $wins = $team['win']['total'] ?? 0;
        $losses = $team['loss']['total'] ?? 0;
        $totalGames = $wins + $losses;
        $percentage = $totalGames > 0 ? ($wins / $totalGames) : 0;

        // Prepare data array
        $data = [
            ':name' => $teamName,
            ':conference' => $team['conference']['name'] ?? '',
            ':win' => $wins,
            ':loss' => $losses,
            ':streak' => $team['streak'] ?? 0,
            ':logo' => $team['team']['logo'] ?? '',
            ':percentage' => $percentage,
            ':winstreak' => isset($team['winStreak']) && $team['winStreak'] ? 1 : 0
        ];

        try {
            if (isset($existingTeams[$teamName])) {
                // Update existing team
                $updateStmt->execute($data);
                $updated_count++;
                echo "Updated team: $teamName\n";
            } else {
                // Insert new team
                $insertStmt->execute($data);
                $inserted_count++;
                echo "Inserted new team: $teamName\n";
            }
        } catch (PDOException $e) {
            error_log("Error updating/inserting team $teamName: " . $e->getMessage());
            $error_count++;
            continue;
        }
    }

    return [
        'updated' => $updated_count,
        'inserted' => $inserted_count,
        'errors' => $error_count
    ];
}

// Main execution
try {
    // Fetch data from API
    $standings_data = fetchStandings($rapidapi_key, $rapidapi_host);
    echo "Successfully fetched standings data from API\n";

    // Update standings in backup table
    $results = updateStandings($pdo, $standings_data);
    
    $update_time = date('Y-m-d H:i:s');
    $details = "BACKUP TABLE - Updated: {$results['updated']}, Inserted: {$results['inserted']}, Errors: {$results['errors']} teams";
    
    // Log the update in update_log table
    $log_stmt = $pdo->prepare("INSERT INTO update_log (update_time, script_name, details) VALUES (?, ?, ?)");
    $log_stmt->execute([$update_time, 'update_standings_backup.php', $details]);

    echo "Backup table standings update completed successfully\n";
    echo "$details\n";
    echo "Last update: $update_time EST\n";
    
} catch (Exception $e) {
    $error_msg = "Error updating backup standings: " . $e->getMessage();
    error_log($error_msg);
    
    // Log the error
    try {
        $update_time = date('Y-m-d H:i:s');
        $log_stmt = $pdo->prepare("INSERT INTO update_log (update_time, script_name, details) VALUES (?, ?, ?)");
        $log_stmt->execute([$update_time, 'update_standings_backup.php', 'ERROR: ' . $e->getMessage()]);
    } catch (Exception $log_error) {
        error_log("Could not log error: " . $log_error->getMessage());
    }
    
    echo $error_msg . "\n";
    exit(1);
}
?>