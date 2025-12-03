<?php
// api/draft_api.php - Simplified Draft API with 30-pick completion check

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
            
            error_log("Draft status API - League: $league_id, Pick count: $pick_count, Status: " . $status['status']);
            
            // Simple completion check: if 30 picks, mark as completed
            if ($pick_count >= 30) {
                $status['status'] = 'completed';
                markDraftCompleted($pdo, $league_id);
                error_log("Draft marked as completed - 30 picks reached");
            }
            
            // Add timer info for active drafts
            if ($status['status'] === 'active' && isset($status['current_participant_id'])) {
                try {
                    $draft_session_id = getDraftSessionId($pdo, $league_id);
                    $timer_info = checkPickTimer($pdo, $draft_session_id);
                    
                    // Fix timer if needed
                    if ($timer_info['seconds_remaining'] <= 0 && !$timer_info['has_valid_start_time']) {
                        forceSetPickTimer($pdo, $draft_session_id);
                        $timer_info = checkPickTimer($pdo, $draft_session_id);
                    }
                    
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
            $participant_id = (int)$_POST['participant_id'] ?? null;
            
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
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'user_id' => $user_id,
                    'participant_id' => $participant_id,
                    'is_commissioner' => $is_commissioner
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

// SIMPLE HELPER FUNCTIONS

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
    // Mark league as completed
    $stmt = $pdo->prepare("UPDATE leagues SET draft_completed = 1 WHERE id = ?");
    $stmt->execute([$league_id]);
    
    // Mark draft session as completed
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
    $stmt = $pdo->prepare("
        SELECT commissioner_user_id FROM leagues WHERE id = ?
    ");
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
        $result = $stmt->execute([$draft_session_id]);
        
        return $stmt->rowCount() > 0;
        
    } catch (Exception $e) {
        error_log("Failed to set timer for draft session $draft_session_id: " . $e->getMessage());
        return false;
    }
}
?>