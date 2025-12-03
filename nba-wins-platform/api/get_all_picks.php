<?php
// get_all_picks.php - Simple API to get ALL draft picks for current league
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['current_league_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

require_once '/data/www/default/nba-wins-platform/config/db_connection.php';

$league_id = $_SESSION['current_league_id'];

try {
    // Get the current draft session
    $stmt = $pdo->prepare("
        SELECT id FROM draft_sessions 
        WHERE league_id = ? 
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$league_id]);
    $draft_session = $stmt->fetch();
    
    if (!$draft_session) {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }
    
    // Get ALL draft picks for this session - no limit
    $stmt = $pdo->prepare("
        SELECT dp.pick_number, dp.round_number, dp.position_in_round, dp.picked_at, dp.picked_by_commissioner,
               t.team_name, t.abbreviation, nt.logo_filename as team_logo,
               lp.participant_name, u.display_name as participant_name
        FROM draft_picks dp
        JOIN teams t ON dp.team_id = t.id
        LEFT JOIN nba_teams nt ON t.name = nt.name
        JOIN league_participants lp ON dp.league_participant_id = lp.id
        JOIN users u ON lp.user_id = u.id
        WHERE dp.draft_session_id = ?
        ORDER BY dp.pick_number DESC
    ");
    $stmt->execute([$draft_session['id']]);
    $all_picks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true, 
        'data' => $all_picks,
        'total_picks' => count($all_picks)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>