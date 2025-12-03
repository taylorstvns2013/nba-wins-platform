<?php
// record_daily_wins.php - Record daily wins for participants in multi-league system
// Location: /nba-wins-platform/tasks/

// Include database connection
require_once(__DIR__ . '/../config/db_connection_cli.php');

// Set timezone to EST
date_default_timezone_set('America/New_York');

function recordDailyWins($pdo) {
    $today = date('Y-m-d');
    $recordsInserted = 0;
    $recordsUpdated = 0;
    $errors = 0;
    
    try {
        // Get all active league participants with their teams
        $stmt = $pdo->query("
            SELECT 
                lp.id as league_participant_id,
                lp.league_id,
                lp.participant_name,
                u.display_name,
                lp.user_id
            FROM league_participants lp
            LEFT JOIN users u ON lp.user_id = u.id
            WHERE lp.status = 'active'
        ");
        $participants = $stmt->fetchAll();
        
        foreach ($participants as $participant) {
            // Calculate total wins for this participant
            $stmt = $pdo->prepare("
                SELECT SUM(COALESCE(t.win, 0)) as total_wins
                FROM league_participant_teams lpt
                LEFT JOIN 2025_2026 t ON lpt.team_name = t.name
                WHERE lpt.league_participant_id = ?
            ");
            $stmt->execute([$participant['league_participant_id']]);
            $result = $stmt->fetch();
            $totalWins = $result['total_wins'] ?? 0;
            
            // Check if record already exists for today
            $stmt = $pdo->prepare("
                SELECT id FROM league_participant_daily_wins 
                WHERE league_participant_id = ? AND date = ?
            ");
            $stmt->execute([$participant['league_participant_id'], $today]);
            $existingRecord = $stmt->fetch();
            
            if ($existingRecord) {
                // Update existing record
                $stmt = $pdo->prepare("
                    UPDATE league_participant_daily_wins 
                    SET total_wins = ?
                    WHERE id = ?
                ");
                $stmt->execute([$totalWins, $existingRecord['id']]);
                $recordsUpdated++;
                
                $participantName = $participant['display_name'] ?? $participant['participant_name'];
                echo "Updated: {$participantName} (League {$participant['league_id']}) - {$totalWins} wins\n";
            } else {
                // Insert new record
                $stmt = $pdo->prepare("
                    INSERT INTO league_participant_daily_wins 
                    (league_participant_id, date, total_wins) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$participant['league_participant_id'], $today, $totalWins]);
                $recordsInserted++;
                
                $participantName = $participant['display_name'] ?? $participant['participant_name'];
                echo "Inserted: {$participantName} (League {$participant['league_id']}) - {$totalWins} wins\n";
            }
        }
        
        return [
            'inserted' => $recordsInserted,
            'updated' => $recordsUpdated,
            'errors' => $errors,
            'total_participants' => count($participants)
        ];
        
    } catch (PDOException $e) {
        throw new Exception("Database error: " . $e->getMessage());
    }
}

try {
    echo "Starting daily wins recording for " . date('Y-m-d H:i:s') . "\n";
    echo "Using table: 2025_2026 for standings data\n\n";
    
    // Record daily wins for all participants
    $results = recordDailyWins($pdo);
    
    $update_time = date('Y-m-d H:i:s');
    $details = "Participants: {$results['total_participants']}, Inserted: {$results['inserted']}, Updated: {$results['updated']}, Errors: {$results['errors']}";
    
    // Log the update
    $log_stmt = $pdo->prepare("INSERT INTO update_log (update_time, script_name, details) VALUES (?, ?, ?)");
    $log_stmt->execute([$update_time, 'record_daily_wins.php', $details]);
    
    echo "\n=== SUMMARY ===\n";
    echo "Daily wins recorded successfully at $update_time\n";
    echo "$details\n";
    
} catch (Exception $e) {
    $error_msg = "Error recording daily wins: " . $e->getMessage();
    error_log($error_msg);
    
    // Log the error
    try {
        $update_time = date('Y-m-d H:i:s');
        $log_stmt = $pdo->prepare("INSERT INTO update_log (update_time, script_name, details) VALUES (?, ?, ?)");
        $log_stmt->execute([$update_time, 'record_daily_wins.php', 'ERROR: ' . $e->getMessage()]);
    } catch (Exception $log_error) {
        error_log("Could not log error: " . $log_error->getMessage());
    }
    
    echo $error_msg . "\n";
    exit(1);
}
?>