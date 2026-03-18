<?php
// nba-wins-platform/api/league_api.php
// API endpoints for league management (create, join, list)
require_once '../config/db_connection.php';
require_once '../core/UserAuthentication.php';
require_once '../core/LeagueManager.php';

header('Content-Type: application/json');

$auth = new UserAuthentication($pdo);
$leagueManager = new LeagueManager($pdo);

// Must be authenticated (non-guest)
if (!$auth->isAuthenticated() || $auth->isGuest()) {
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    case 'create_league':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'POST method required.']);
            exit;
        }

        $leagueName = trim($_POST['league_name'] ?? '');
        $leagueSize = (int)($_POST['league_size'] ?? 6);
        $draftDate = trim($_POST['draft_date'] ?? '');

        $result = $leagueManager->createLeague($userId, $leagueName, $leagueSize, $draftDate ?: null);
        echo json_encode($result);
        break;

    case 'join_league':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'POST method required.']);
            exit;
        }

        $pinCode = trim($_POST['pin_code'] ?? '');
        $result = $leagueManager->joinLeague($userId, $pinCode);
        echo json_encode($result);
        break;

    case 'my_leagues':
        $leagues = $leagueManager->getUserLeaguesWithDetails($userId);
        echo json_encode(['success' => true, 'leagues' => $leagues]);
        break;

    case 'league_members':
        $leagueId = (int)($_GET['league_id'] ?? 0);
        if (!$leagueId) {
            echo json_encode(['success' => false, 'message' => 'League ID required.']);
            exit;
        }
        $members = $leagueManager->getLeagueMembers($leagueId);
        echo json_encode(['success' => true, 'members' => $members]);
        break;

    case 'update_draft_date':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'POST method required.']);
            exit;
        }

        $leagueId = (int)($_POST['league_id'] ?? 0);
        $draftDate = trim($_POST['draft_date'] ?? '');

        if (!$leagueId || !$draftDate) {
            echo json_encode(['success' => false, 'message' => 'League ID and draft date are required.']);
            exit;
        }

        $result = $leagueManager->updateDraftDate($userId, $leagueId, $draftDate);
        echo json_encode($result);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action. Valid actions: create_league, join_league, my_leagues, league_members, update_draft_date']);
        break;
}
?>