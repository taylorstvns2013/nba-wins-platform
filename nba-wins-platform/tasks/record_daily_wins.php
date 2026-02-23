<?php
// record_daily_wins.php - Record daily wins for participants in multi-league system
// Location: /nba-wins-platform/tasks/
//
// SMART TIMING LOGIC:
// This script is designed to run via cron every 5 minutes between midnight and 3 AM.
// It will only record wins once all of yesterday's games are complete.
// - If games finish before midnight → this runs at midnight (first cron hit)
// - If games finish at 12:45 AM → this runs on the next 5-min check after completion
// - Hard cutoff at 3 AM → runs regardless to prevent getting stuck
// - Duplicate protection: won't re-record if already done for today
//
// CRON SCHEDULE (replace the old 1:15 AM entries):
// */5 0,1,2 * * * /usr/bin/php /data/www/default/nba-wins-platform/tasks/record_daily_wins.php
// 0 3 * * * /usr/bin/php /data/www/default/nba-wins-platform/tasks/record_daily_wins.php --force

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

// =============================================================================
// SMART TIMING GATE
// Only proceed if games are done or we've hit the hard cutoff
// =============================================================================
$forceRun = in_array('--force', $argv ?? []);
$currentHour = (int) date('G');
$todayDate = date('Y-m-d');
$yesterdayDate = date('Y-m-d', strtotime('-1 day'));

// Check if we already recorded today's wins (duplicate protection)
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count 
    FROM update_log 
    WHERE DATE(update_time) = ? 
    AND script_name = 'record_daily_wins.php'
    AND details NOT LIKE 'ERROR%'
");
$stmt->execute([$todayDate]);
$alreadyRecorded = $stmt->fetch()['count'] > 0;

if ($alreadyRecorded && !$forceRun) {
    echo date('Y-m-d H:i:s') . " - Daily wins already recorded for today. Skipping.\n";
    exit(0);
}

// Check if yesterday's games are all complete
// "Active" games = NOT Final/Finished AND NOT Scheduled/Postponed/Cancelled
// (games that are actually being played right now)
$stmt = $pdo->prepare("
    SELECT COUNT(*) as active_count
    FROM games 
    WHERE date = ?
    AND status_long NOT IN ('Final', 'Finished')
    AND status_long NOT IN ('Scheduled', 'Not Started', 'Postponed', 'Cancelled', 'Canceled')
");
$stmt->execute([$yesterdayDate]);
$activeGamesYesterday = $stmt->fetch()['active_count'];

// Also check: did yesterday have ANY games at all?
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM games WHERE date = ?");
$stmt->execute([$yesterdayDate]);
$totalGamesYesterday = $stmt->fetch()['total'];

if ($activeGamesYesterday > 0 && !$forceRun) {
    // Games still in progress - wait (output goes to cron log for debugging)
    echo date('Y-m-d H:i:s') . " - $activeGamesYesterday game(s) still in progress from yesterday. Waiting...\n";
    exit(0);
}

// If we get here, either:
// 1. All yesterday's games are Final/Finished (or Scheduled/Postponed which don't count)
// 2. Yesterday had no games
// 3. --force flag was used (3 AM hard cutoff)
if ($forceRun) {
    echo date('Y-m-d H:i:s') . " - Force run triggered (3 AM hard cutoff)\n";
} elseif ($totalGamesYesterday == 0) {
    echo date('Y-m-d H:i:s') . " - No games yesterday. Recording current standings.\n";
} else {
    echo date('Y-m-d H:i:s') . " - All $totalGamesYesterday game(s) from yesterday are complete. Recording wins.\n";
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