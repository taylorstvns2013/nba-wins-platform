<?php
// /data/www/default/nba-wins-platform/api/draft_api.php
// Draft API with timer expiry auto-pick, auto-draft toggle, and pre-draft countdown support

header('Content-Type: application/json');
session_start();

require_once '/data/www/default/nba-wins-platform/config/db_connection.php';
require_once '/data/www/default/nba-wins-platform/core/DraftManager.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Get current league from session
if (!isset($_SESSION['current_league_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No league selected']);
    exit;
}

$user_id = $_SESSION['user_id'];
$league_id = $_SESSION['current_league_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

$draftManager = new DraftManager($pdo);

try {
    switch ($action) {
        case 'start_draft':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST request required');
            }
            
            $draft_session_id = $draftManager->startDraft($league_id, $user_id);
            
            // Set initial timer
            forceSetPickTimer($pdo, $draft_session_id);
            
            echo json_encode([
                'success' => true,
                'message' => 'Draft started successfully!',
                'draft_session_id' => $draft_session_id
            ]);
            break;
            
        case 'get_draft_status':
            // Get basic draft status
            $status = $draftManager->getDraftStatus($league_id);
            
            // Always add pick count for accurate display
            $pick_count = getPickCount($pdo, $league_id);
            $status['pick_count'] = $pick_count;
            
            // Add league info (draft_date for pre-draft countdown, pick_time_limit)
            $leagueInfo = getLeagueInfo($pdo, $league_id);
            $status['draft_date'] = $leagueInfo['draft_date'];
            $status['league_name'] = $leagueInfo['display_name'];
            $status['draft_enabled'] = (bool)$leagueInfo['draft_enabled'];
            $status['server_time'] = date('Y-m-d H:i:s');
            
            // Add pick_time_limit from draft session if active
            $status['pick_time_limit'] = 120; // default
            if ($status['status'] !== 'not_started') {
                try {
                    $dsId = getDraftSessionId($pdo, $league_id);
                    $dsStmt = $pdo->prepare("SELECT pick_time_limit FROM draft_sessions WHERE id = ?");
                    $dsStmt->execute([$dsId]);
                    $dsRow = $dsStmt->fetch();
                    if ($dsRow && $dsRow['pick_time_limit']) {
                        $status['pick_time_limit'] = (int)$dsRow['pick_time_limit'];
                    }
                } catch (Exception $e) {
                    // Use default
                }
            }
            
            // Add auto_draft_enabled for the current user
            try {
                $participantId = getUserParticipantId($pdo, $user_id, $league_id);
                $status['user_auto_draft_enabled'] = getAutoDraftEnabled($pdo, $participantId);
                $status['user_participant_id'] = $participantId;
            } catch (Exception $e) {
                $status['user_auto_draft_enabled'] = false;
                $status['user_participant_id'] = null;
            }
            
            // Simple completion check: if 30 picks, mark as completed
            if ($pick_count >= 30) {
                $status['status'] = 'completed';
                markDraftCompleted($pdo, $league_id);
            }
            
            // Add timer info for active drafts
            $pickTimeLimit = $status['pick_time_limit'] ?? 120;
            if ($status['status'] === 'active' && !empty($status['current_participant'])) {
                try {
                    $draft_session_id = getDraftSessionId($pdo, $league_id);
                    $timer_info = checkPickTimer($pdo, $draft_session_id);
                    
                    // Fix timer if needed
                    if ($timer_info['seconds_remaining'] <= 0 && !$timer_info['has_valid_start_time']) {
                        forceSetPickTimer($pdo, $draft_session_id);
                        $timer_info = checkPickTimer($pdo, $draft_session_id);
                    }
                    
                    // SERVER-SIDE AUTO-PICK LOOP
                    // Handles: expired timers, auto-draft-enabled participants, and chains.
                    // Processes all in one request so the auto-draft flag takes effect immediately.
                    $autoPicksMade = 0;
                    $maxLoop = 30;
                    
                    while ($autoPicksMade < $maxLoop) {
                        $timer_info = checkPickTimer($pdo, $draft_session_id);
                        $currentPid = $timer_info['current_participant_id'];
                        if (!$currentPid) break;
                        
                        $isExpired = $timer_info['expired'];
                        $isAutoDraft = getAutoDraftEnabled($pdo, $currentPid);
                        
                        // Normal state: timer running, no auto-draft → stop
                        if (!$isExpired && !$isAutoDraft) break;
                        
                        // Timeout on non-auto-draft participant: track and enable after 2
                        if ($isExpired && !$isAutoDraft) {
                            $autoPickCount = getParticipantAutoPickCount($pdo, $draft_session_id, $currentPid);
                            if (($autoPickCount + 1) >= 2) {
                                enableAutoDraft($pdo, $currentPid);
                                error_log("SERVER TIMEOUT: Enabled auto-draft for participant $currentPid after " . ($autoPickCount + 1) . " auto-picks");
                            }
                        }
                        
                        try {
                            $draftManager->autoPickTeam($draft_session_id, $currentPid);
                            $reason = $isExpired ? 'timeout' : 'auto-draft';
                            error_log("SERVER AUTO-PICK: Picked for participant $currentPid (reason: $reason, count: $autoPicksMade)");
                            $autoPicksMade++;
                        } catch (Exception $autoErr) {
                            error_log("SERVER AUTO-PICK ERROR: " . $autoErr->getMessage());
                            break;
                        }
                        
                        $pick_count = getPickCount($pdo, $league_id);
                        if ($pick_count >= 30) { markDraftCompleted($pdo, $league_id); break; }
                        
                        forceSetPickTimer($pdo, $draft_session_id);
                    }
                    
                    // Re-fetch status after any auto-picks
                    if ($autoPicksMade > 0) {
                        $pick_count = getPickCount($pdo, $league_id);
                        $status = $draftManager->getDraftStatus($league_id);
                        $status['pick_count'] = $pick_count;
                        $status['draft_date'] = $leagueInfo['draft_date'];
                        $status['league_name'] = $leagueInfo['display_name'];
                        $status['draft_enabled'] = (bool)$leagueInfo['draft_enabled'];
                        $status['server_time'] = date('Y-m-d H:i:s');
                        $status['pick_time_limit'] = $pickTimeLimit;
                        $status['auto_picks_made'] = $autoPicksMade;
                        try {
                            $pid = getUserParticipantId($pdo, $user_id, $league_id);
                            $status['user_auto_draft_enabled'] = getAutoDraftEnabled($pdo, $pid);
                            $status['user_participant_id'] = $pid;
                        } catch (Exception $e) {}
                        if ($pick_count >= 30) { $status['status'] = 'completed'; }
                    }
                    
                    // Final timer info
                    $timer_info = checkPickTimer($pdo, $draft_session_id);
                    $status['timer_seconds_remaining'] = max(0, $timer_info['seconds_remaining']);
                    $status['timer_expired'] = $timer_info['expired'];
                    
                } catch (Exception $e) {
                    $status['timer_seconds_remaining'] = 0;
                    $status['timer_expired'] = false;
                }
            }
            
            echo json_encode(['success' => true, 'data' => $status]);
            break;
            
        case 'make_pick':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST request required');
            }
            
            $team_id = (int)$_POST['team_id'];
            $participant_id = (int)($_POST['participant_id'] ?? 0);
            
            $picked_by_commissioner = 0;
            if (isset($_POST['commissioner_pick'])) {
                $commissioner_pick = $_POST['commissioner_pick'];
                if ($commissioner_pick === 'true' || $commissioner_pick === '1' || $commissioner_pick === 1) {
                    $picked_by_commissioner = 1;
                }
            }
            
            if (!$team_id) {
                throw new Exception('Team ID is required');
            }
            
            if (!$participant_id) {
                $participant_id = getUserParticipantId($pdo, $user_id, $league_id);
            }
            
            $status = $draftManager->getDraftStatus($league_id);
            if ($status['status'] !== 'active') {
                throw new Exception('Draft is not currently active');
            }
            
            $draft_session_id = getDraftSessionId($pdo, $league_id);
            
            // Make the pick
            $draftManager->makePick($draft_session_id, $participant_id, $team_id, $picked_by_commissioner);
            
            // Reset consecutive timeout counter for this participant (they picked manually)
            resetConsecutiveTimeouts($pdo, $participant_id);
            
            // Get updated pick count
            $pick_count = getPickCount($pdo, $league_id);
            
            // Simple response with pick count
            if ($pick_count >= 30) {
                markDraftCompleted($pdo, $league_id);
                echo json_encode([
                    'success' => true,
                    'message' => 'Draft completed!',
                    'pick_count' => $pick_count,
                    'is_completed' => true
                ]);
            } else {
                // Set timer for next pick
                forceSetPickTimer($pdo, $draft_session_id);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Pick made successfully!',
                    'pick_count' => $pick_count,
                    'is_completed' => false
                ]);
            }
            break;
            
        case 'timer_expired':
            // Called by the client when the pick timer runs out
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST request required');
            }
            
            $status = $draftManager->getDraftStatus($league_id);
            if ($status['status'] !== 'active') {
                throw new Exception('Draft is not currently active');
            }
            
            $draft_session_id = getDraftSessionId($pdo, $league_id);
            
            // Verify the timer has actually expired server-side to prevent abuse
            $timer_info = checkPickTimer($pdo, $draft_session_id);
            if (!$timer_info['expired']) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Timer has not expired yet',
                    'seconds_remaining' => $timer_info['seconds_remaining']
                ]);
                break;
            }
            
            $current_participant_id = $timer_info['current_participant_id'];
            if (!$current_participant_id) {
                throw new Exception('No current participant found');
            }
            
            // Increment consecutive timeout counter
            $timeouts = incrementConsecutiveTimeouts($pdo, $current_participant_id);
            
            // Auto-pick for the timed-out participant
            $auto_picked_team = $draftManager->autoPickTeam($draft_session_id, $current_participant_id);
            
            // If 2+ consecutive timeouts, enable auto-draft for this participant
            $auto_draft_forced = false;
            if ($timeouts >= 2) {
                enableAutoDraft($pdo, $current_participant_id);
                $auto_draft_forced = true;
                error_log("AUTO-DRAFT FORCED: Participant $current_participant_id hit $timeouts consecutive timeouts");
            }
            
            // Get updated pick count
            $pick_count = getPickCount($pdo, $league_id);
            
            if ($pick_count >= 30) {
                markDraftCompleted($pdo, $league_id);
                echo json_encode([
                    'success' => true,
                    'message' => 'Draft completed!',
                    'pick_count' => $pick_count,
                    'is_completed' => true,
                    'auto_picked_team' => $auto_picked_team['team_name'] ?? null,
                    'auto_draft_forced' => $auto_draft_forced,
                    'consecutive_timeouts' => $timeouts
                ]);
            } else {
                forceSetPickTimer($pdo, $draft_session_id);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Auto-picked due to timeout',
                    'pick_count' => $pick_count,
                    'is_completed' => false,
                    'auto_picked_team' => $auto_picked_team['team_name'] ?? null,
                    'auto_draft_forced' => $auto_draft_forced,
                    'consecutive_timeouts' => $timeouts
                ]);
            }
            break;
            
        case 'toggle_auto_draft':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST request required');
            }
            
            $participant_id = getUserParticipantId($pdo, $user_id, $league_id);
            $enabled = isset($_POST['enabled']) && ($_POST['enabled'] === 'true' || $_POST['enabled'] === '1');
            
            $stmt = $pdo->prepare("
                UPDATE league_participants 
                SET auto_draft_enabled = ? 
                WHERE id = ?
            ");
            $stmt->execute([$enabled ? 1 : 0, $participant_id]);
            
            // If disabling auto-draft, reset the consecutive timeout counter
            if (!$enabled) {
                resetConsecutiveTimeouts($pdo, $participant_id);
            }
            
            error_log("AUTO-DRAFT TOGGLE: Participant $participant_id set auto_draft_enabled = " . ($enabled ? 'TRUE' : 'FALSE'));
            
            echo json_encode([
                'success' => true,
                'auto_draft_enabled' => $enabled,
                'message' => $enabled ? 'Auto-draft enabled' : 'Auto-draft disabled'
            ]);
            break;
            
        case 'pause_draft':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST request required');
            }
            
            $draft_session_id = getDraftSessionId($pdo, $league_id);
            $draftManager->pauseResumeDraft($draft_session_id, $user_id, 'pause');
            
            echo json_encode([
                'success' => true,
                'message' => 'Draft paused'
            ]);
            break;
            
        case 'resume_draft':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST request required');
            }
            
            $draft_session_id = getDraftSessionId($pdo, $league_id);
            $draftManager->pauseResumeDraft($draft_session_id, $user_id, 'resume');
            
            // Set timer when resuming
            forceSetPickTimer($pdo, $draft_session_id);
            
            echo json_encode([
                'success' => true,
                'message' => 'Draft resumed'
            ]);
            break;
            
        case 'get_user_info':
            $participant_id = getUserParticipantId($pdo, $user_id, $league_id);
            $is_commissioner = isCommissioner($pdo, $user_id, $league_id);
            $auto_draft_enabled = getAutoDraftEnabled($pdo, $participant_id);
            
            // Get display name
            $nameStmt = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
            $nameStmt->execute([$user_id]);
            $display_name = $nameStmt->fetchColumn() ?: '';
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'user_id' => $user_id,
                    'participant_id' => $participant_id,
                    'is_commissioner' => $is_commissioner,
                    'auto_draft_enabled' => $auto_draft_enabled,
                    'display_name' => $display_name
                ]
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    
    error_log("Draft API Error - Action: $action, User: $user_id, League: $league_id, Error: " . $e->getMessage());
}

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

function getLeagueInfo($pdo, $league_id) {
    $stmt = $pdo->prepare("SELECT display_name, draft_date, draft_enabled, draft_completed FROM leagues WHERE id = ?");
    $stmt->execute([$league_id]);
    return $stmt->fetch() ?: ['display_name' => '', 'draft_date' => null, 'draft_enabled' => 0, 'draft_completed' => 0];
}

function getPickCount($pdo, $league_id) {
    $stmt = $pdo->prepare("
        SELECT COUNT(dp.id) as pick_count
        FROM draft_sessions ds
        LEFT JOIN draft_picks dp ON ds.id = dp.draft_session_id
        WHERE ds.league_id = ?
        ORDER BY ds.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$league_id]);
    $result = $stmt->fetch();
    return $result ? (int)$result['pick_count'] : 0;
}

function markDraftCompleted($pdo, $league_id) {
    $stmt = $pdo->prepare("UPDATE leagues SET draft_completed = 1 WHERE id = ?");
    $stmt->execute([$league_id]);
    
    $stmt = $pdo->prepare("
        UPDATE draft_sessions 
        SET status = 'completed', 
            completed_at = COALESCE(completed_at, NOW()),
            current_participant_id = NULL,
            current_pick_started_at = NULL
        WHERE league_id = ? AND status IN ('active', 'paused')
    ");
    $stmt->execute([$league_id]);
}

function getUserParticipantId($pdo, $user_id, $league_id) {
    $stmt = $pdo->prepare("
        SELECT id FROM league_participants 
        WHERE user_id = ? AND league_id = ? AND status = 'active'
    ");
    $stmt->execute([$user_id, $league_id]);
    $result = $stmt->fetch();
    
    if (!$result) {
        throw new Exception("You are not a participant in this league");
    }
    
    return $result['id'];
}

function isCommissioner($pdo, $user_id, $league_id) {
    $stmt = $pdo->prepare("SELECT commissioner_user_id FROM leagues WHERE id = ?");
    $stmt->execute([$league_id]);
    $result = $stmt->fetch();
    return $result && ($result['commissioner_user_id'] == $user_id || $result['commissioner_user_id'] === null);
}

function getDraftSessionId($pdo, $league_id) {
    $stmt = $pdo->prepare("
        SELECT id FROM draft_sessions 
        WHERE league_id = ? AND status IN ('pending', 'active', 'paused', 'completed')
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$league_id]);
    $result = $stmt->fetch();
    
    if (!$result) {
        throw new Exception("No draft session found");
    }
    
    return $result['id'];
}

function getAutoDraftEnabled($pdo, $participant_id) {
    $stmt = $pdo->prepare("SELECT auto_draft_enabled FROM league_participants WHERE id = ?");
    $stmt->execute([$participant_id]);
    $result = $stmt->fetch();
    return $result ? (bool)$result['auto_draft_enabled'] : false;
}

function enableAutoDraft($pdo, $participant_id) {
    $stmt = $pdo->prepare("UPDATE league_participants SET auto_draft_enabled = 1 WHERE id = ?");
    $stmt->execute([$participant_id]);
}

/**
 * Count how many auto-picks a participant has in a draft session.
 * Simpler than tracking consecutive — if you've been auto-picked 2+ times, auto-draft kicks in.
 */
function getParticipantAutoPickCount($pdo, $draft_session_id, $participant_id) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM draft_picks 
        WHERE draft_session_id = ? AND league_participant_id = ? AND auto_picked = 1
    ");
    $stmt->execute([$draft_session_id, $participant_id]);
    return (int)$stmt->fetchColumn();
}

/**
 * Track consecutive timeouts per participant using a simple approach:
 * Count the trailing auto_picked picks for this participant in the current draft.
 */
function incrementConsecutiveTimeouts($pdo, $participant_id) {
    // Get the draft session for this participant
    $stmt = $pdo->prepare("
        SELECT ds.id as draft_session_id
        FROM league_participants lp
        JOIN draft_sessions ds ON lp.league_id = ds.league_id
        WHERE lp.id = ? AND ds.status IN ('active', 'paused')
        ORDER BY ds.created_at DESC LIMIT 1
    ");
    $stmt->execute([$participant_id]);
    $row = $stmt->fetch();
    
    if (!$row) return 1;
    
    // Count consecutive auto_picked picks from the end for this participant
    $stmt = $pdo->prepare("
        SELECT auto_picked 
        FROM draft_picks 
        WHERE draft_session_id = ? AND league_participant_id = ?
        ORDER BY pick_number DESC
        LIMIT 5
    ");
    $stmt->execute([$row['draft_session_id'], $participant_id]);
    $picks = $stmt->fetchAll();
    
    // The current timeout will be the next pick, so count existing consecutive + 1
    $consecutive = 0;
    foreach ($picks as $pick) {
        if ($pick['auto_picked']) {
            $consecutive++;
        } else {
            break;
        }
    }
    
    // Add 1 for the current timeout that's about to happen
    return $consecutive + 1;
}

function resetConsecutiveTimeouts($pdo, $participant_id) {
    // Nothing to reset in DB - consecutive count is derived from draft_picks
    // This function exists for clarity; manual picks naturally break the streak
}

function checkPickTimer($pdo, $draft_session_id) {
    $stmt = $pdo->prepare("
        SELECT 
            current_participant_id,
            current_pick_started_at,
            pick_time_limit,
            CASE 
                WHEN current_pick_started_at IS NULL THEN 0
                ELSE GREATEST(0, (UNIX_TIMESTAMP(current_pick_started_at) + pick_time_limit) - UNIX_TIMESTAMP())
            END as seconds_remaining,
            status
        FROM draft_sessions 
        WHERE id = ?
    ");
    $stmt->execute([$draft_session_id]);
    $result = $stmt->fetch();
    
    if (!$result) {
        return [
            'expired' => false, 
            'seconds_remaining' => 0,
            'current_participant_id' => null,
            'has_valid_start_time' => false
        ];
    }
    
    $has_valid_start_time = !empty($result['current_pick_started_at']);
    $seconds_remaining = max(0, (int)$result['seconds_remaining']);
    
    return [
        'expired' => $seconds_remaining <= 0 && $has_valid_start_time,
        'seconds_remaining' => $seconds_remaining,
        'current_participant_id' => $result['current_participant_id'],
        'has_valid_start_time' => $has_valid_start_time
    ];
}

function forceSetPickTimer($pdo, $draft_session_id) {
    try {
        $stmt = $pdo->prepare("
            UPDATE draft_sessions 
            SET current_pick_started_at = NOW(),
                pick_time_limit = COALESCE(pick_time_limit, 120)
            WHERE id = ? 
            AND status = 'active' 
            AND current_participant_id IS NOT NULL
        ");
        $stmt->execute([$draft_session_id]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log("Failed to set timer for draft session $draft_session_id: " . $e->getMessage());
        return false;
    }
}
?>