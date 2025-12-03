<?php
// /data/www/default/nba-wins-platform/api/get_profile_photo.php
// API endpoint to get profile photo URL

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

try {
    require_once '../config/db_connection.php';
    require_once '../core/ProfilePhotoHandler.php';
    
    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    
    if (!$user_id) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid or missing user_id parameter'
        ]);
        exit;
    }
    
    $photoHandler = new ProfilePhotoHandler($pdo);
    $photoUrl = $photoHandler->getPhotoUrl($user_id);
    
    // Get additional user info if needed
    $stmt = $pdo->prepare("SELECT display_name FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo json_encode([
            'success' => false,
            'error' => 'User not found'
        ]);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'photoUrl' => $photoUrl,
        'user_id' => $user_id,
        'display_name' => $user['display_name']
    ]);
    
} catch (Exception $e) {
    error_log("Profile photo API error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve profile photo'
    ]);
}
?>