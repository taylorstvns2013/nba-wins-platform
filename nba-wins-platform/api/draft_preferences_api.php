<?php
// /data/www/default/nba-wins-platform/api/draft_preferences_api.php
// API endpoint for managing user draft preferences

header('Content-Type: application/json');
session_start();

// Require database connection
require_once '/data/www/default/nba-wins-platform/config/db_connection.php';

// Authentication check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Authentication required'
    ]);
    exit;
}

$user_id = $_SESSION['user_id'];

// Get request data
$request_method = $_SERVER['REQUEST_METHOD'];

if ($request_method === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid JSON data'
        ]);
        exit;
    }
    
    $action = $data['action'] ?? '';
} else if ($request_method === 'GET') {
    $action = $_GET['action'] ?? '';
} else {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
    exit;
}

// Process the action
try {
    switch ($action) {
        case 'save_preferences':
            handleSavePreferences($pdo, $user_id, $data);
            break;
        
        case 'get_preferences':
            handleGetPreferences($pdo, $user_id);
            break;
        
        case 'delete_preferences':
            handleDeletePreferences($pdo, $user_id);
            break;
        
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid action specified'
            ]);
            break;
    }
} catch (Exception $e) {
    error_log("Draft Preferences API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}

/**
 * Save or update user draft preferences
 */
function handleSavePreferences($pdo, $user_id, $data) {
    // Validate preferences data exists
    if (!isset($data['preferences']) || !is_array($data['preferences'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Missing or invalid preferences data'
        ]);
        return;
    }
    
    $preferences = $data['preferences'];
    
    // Validate all 30 teams are ranked
    if (count($preferences) !== 30) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'All 30 NBA teams must be ranked. Received: ' . count($preferences)
        ]);
        return;
    }
    
    // Validate ranks are 1-30 with no duplicates
    $ranks = array_column($preferences, 'priority_rank');
    $expected_ranks = range(1, 30);
    sort($ranks);
    
    if ($ranks !== $expected_ranks) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Ranks must be consecutive numbers from 1 to 30 with no duplicates'
        ]);
        return;
    }
    
    // Validate all team IDs exist and normalize team names for Clippers
    $team_ids = array_column($preferences, 'team_id');
    $placeholders = str_repeat('?,', count($team_ids) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT id, name 
        FROM nba_teams 
        WHERE id IN ($placeholders)
    ");
    $stmt->execute($team_ids);
    $valid_teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($valid_teams) !== 30) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'One or more invalid team IDs provided'
        ]);
        return;
    }
    
    // Begin transaction for atomic save
    $pdo->beginTransaction();
    
    try {
        // Delete existing preferences for this user
        $stmt = $pdo->prepare("DELETE FROM user_draft_preferences WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        // Insert new preferences
        $stmt = $pdo->prepare("
            INSERT INTO user_draft_preferences (user_id, team_id, priority_rank)
            VALUES (?, ?, ?)
        ");
        
        foreach ($preferences as $pref) {
            $team_id = $pref['team_id'];
            $priority_rank = $pref['priority_rank'];
            
            $stmt->execute([$user_id, $team_id, $priority_rank]);
        }
        
        // Commit the transaction
        $pdo->commit();
        
        // Log the save
        error_log("User $user_id saved draft preferences: " . count($preferences) . " teams ranked");
        
        echo json_encode([
            'success' => true,
            'message' => 'Draft preferences saved successfully',
            'preferences_count' => count($preferences)
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Get user draft preferences
 */
function handleGetPreferences($pdo, $user_id) {
    try {
        // Get user's preferences with team details
        // Handle both "LA Clippers" and "Los Angeles Clippers" variations
        $stmt = $pdo->prepare("
            SELECT 
                udp.id,
                udp.team_id,
                udp.priority_rank,
                nt.name,
                nt.abbreviation,
                nt.city,
                nt.conference,
                nt.division,
                nt.logo_filename,
                CASE 
                    WHEN nt.name = 'LA Clippers' THEN 'Los Angeles Clippers'
                    WHEN nt.name = 'Los Angeles Clippers' THEN 'Los Angeles Clippers'
                    ELSE nt.name 
                END as normalized_name
            FROM user_draft_preferences udp
            JOIN nba_teams nt ON udp.team_id = nt.id
            WHERE udp.user_id = ?
            ORDER BY udp.priority_rank ASC
        ");
        $stmt->execute([$user_id]);
        $preferences = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get count of unranked teams
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as unranked_count
            FROM nba_teams nt
            LEFT JOIN user_draft_preferences udp ON nt.id = udp.team_id AND udp.user_id = ?
            WHERE udp.id IS NULL
        ");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $unranked_count = $result['unranked_count'];
        
        echo json_encode([
            'success' => true,
            'preferences' => $preferences,
            'total_ranked' => count($preferences),
            'unranked_count' => $unranked_count,
            'is_complete' => count($preferences) === 30
        ]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * Delete all user draft preferences
 */
function handleDeletePreferences($pdo, $user_id) {
    try {
        // Delete all preferences for this user
        $stmt = $pdo->prepare("DELETE FROM user_draft_preferences WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        $deleted_count = $stmt->rowCount();
        
        // Log the deletion
        error_log("User $user_id deleted all draft preferences: $deleted_count teams removed");
        
        echo json_encode([
            'success' => true,
            'message' => 'Draft preferences deleted successfully',
            'deleted_count' => $deleted_count
        ]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * Helper function to normalize team names (especially for Clippers)
 */
function normalizeTeamName($team_name) {
    // Handle Clippers variations
    if ($team_name === 'LA Clippers' || $team_name === 'Los Angeles Clippers') {
        return 'Los Angeles Clippers';
    }
    
    return $team_name;
}
?>