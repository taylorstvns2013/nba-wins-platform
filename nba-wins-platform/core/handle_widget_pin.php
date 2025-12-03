<?php
// /data/www/default/nba-wins-platform/core/handle_widget_pin.php
// Handles pinning and unpinning widgets to user's homepage

session_start();

// Require authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => 'Not authenticated']));
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Method not allowed']));
}

// Get POST data
$action = $_POST['action'] ?? '';
$widget_type = $_POST['widget_type'] ?? '';
$user_id = $_SESSION['user_id'];

// Validate inputs
if (empty($action) || empty($widget_type)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Missing required parameters']));
}

// Validate widget_type
$valid_widget_types = [
    'upcoming_games', 
    'last_10_games', 
    'league_stats',
    'exceeding_expectations',
    'falling_short',
    'platform_leaderboard',
    'draft_steals',
    'weekly_rankings',
    'strength_of_schedule'
];
if (!in_array($widget_type, $valid_widget_types)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Invalid widget type']));
}

// Connect to database
require_once '../config/db_connection.php';

try {
    if ($action === 'pin') {
        // Check if widget already pinned
        $stmt = $pdo->prepare("
            SELECT id FROM user_dashboard_widgets 
            WHERE user_id = ? AND widget_type = ?
        ");
        $stmt->execute([$user_id, $widget_type]);
        
        if ($stmt->fetch()) {
            die(json_encode(['success' => false, 'error' => 'Widget already pinned']));
        }
        
        // Get the highest display_order for this user
        $stmt = $pdo->prepare("
            SELECT COALESCE(MAX(display_order), 0) as max_order 
            FROM user_dashboard_widgets 
            WHERE user_id = ?
        ");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $next_order = $result['max_order'] + 1;
        
        // Insert new widget
        $stmt = $pdo->prepare("
            INSERT INTO user_dashboard_widgets (user_id, widget_type, display_order, is_active)
            VALUES (?, ?, ?, 1)
        ");
        $stmt->execute([$user_id, $widget_type, $next_order]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Widget pinned successfully',
            'widget_type' => $widget_type
        ]);
        
    } elseif ($action === 'unpin') {
        // Delete the widget
        $stmt = $pdo->prepare("
            DELETE FROM user_dashboard_widgets 
            WHERE user_id = ? AND widget_type = ?
        ");
        $stmt->execute([$user_id, $widget_type]);
        
        if ($stmt->rowCount() > 0) {
            // Reorder remaining widgets to fill the gap
            $stmt = $pdo->prepare("
                SELECT id FROM user_dashboard_widgets 
                WHERE user_id = ? 
                ORDER BY display_order ASC
            ");
            $stmt->execute([$user_id]);
            $widgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $order = 1;
            foreach ($widgets as $widget) {
                $updateStmt = $pdo->prepare("
                    UPDATE user_dashboard_widgets 
                    SET display_order = ? 
                    WHERE id = ?
                ");
                $updateStmt->execute([$order, $widget['id']]);
                $order++;
            }
            
            echo json_encode([
                'success' => true, 
                'message' => 'Widget unpinned successfully',
                'widget_type' => $widget_type
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'error' => 'Widget not found'
            ]);
        }
        
    } elseif ($action === 'reorder') {
        // Get direction parameter
        $direction = $_POST['direction'] ?? '';
        
        if (!in_array($direction, ['up', 'down'])) {
            http_response_code(400);
            die(json_encode(['success' => false, 'error' => 'Invalid direction']));
        }
        
        // Get current widget
        $stmt = $pdo->prepare("
            SELECT id, display_order FROM user_dashboard_widgets 
            WHERE user_id = ? AND widget_type = ?
        ");
        $stmt->execute([$user_id, $widget_type]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$current) {
            http_response_code(404);
            die(json_encode(['success' => false, 'error' => 'Widget not found']));
        }
        
        // Get adjacent widget
        if ($direction === 'up') {
            $stmt = $pdo->prepare("
                SELECT id, display_order FROM user_dashboard_widgets 
                WHERE user_id = ? AND display_order < ? 
                ORDER BY display_order DESC LIMIT 1
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT id, display_order FROM user_dashboard_widgets 
                WHERE user_id = ? AND display_order > ? 
                ORDER BY display_order ASC LIMIT 1
            ");
        }
        $stmt->execute([$user_id, $current['display_order']]);
        $adjacent = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$adjacent) {
            die(json_encode([
                'success' => false, 
                'error' => 'Already at ' . ($direction === 'up' ? 'top' : 'bottom')
            ]));
        }
        
        // Swap display_order values
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            UPDATE user_dashboard_widgets 
            SET display_order = ? 
            WHERE id = ?
        ");
        $stmt->execute([$adjacent['display_order'], $current['id']]);
        
        $stmt = $pdo->prepare("
            UPDATE user_dashboard_widgets 
            SET display_order = ? 
            WHERE id = ?
        ");
        $stmt->execute([$current['display_order'], $adjacent['id']]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Widget reordered successfully'
        ]);
        
    } else {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => 'Invalid action']));
    }
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Widget pin error: " . $e->getMessage());
    http_response_code(500);
    die(json_encode([
        'success' => false, 
        'error' => 'Database error occurred'
    ]));
}
?>
