<?php
// nba-wins-platform/auth/switch_league.php
require_once '../config/db_connection.php';

header('Content-Type: application/json');

// Check if user is authenticated
if (!$auth->isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Check if league_id is provided
if (!isset($_POST['league_id']) || empty($_POST['league_id'])) {
    echo json_encode(['success' => false, 'message' => 'League ID is required']);
    exit;
}

$leagueId = (int)$_POST['league_id'];

// Attempt to switch league
if ($auth->switchLeague($leagueId)) {
    echo json_encode(['success' => true, 'message' => 'League switched successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Unable to switch league. You may not have access to this league.']);
}
?>