<?php
// /data/www/default/nba-wins-platform/api/draft_preferences_api.php
// API endpoint for managing user draft preferences (league-specific + global)

header('Content-Type: application/json');
session_start();

require_once '/data/www/default/nba-wins-platform/config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$user_id = $_SESSION['user_id'];
$request_method = $_SERVER['REQUEST_METHOD'];

if ($request_method === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit;
    }
    $action = $data['action'] ?? '';
} elseif ($request_method === 'GET') {
    $action = $_GET['action'] ?? '';
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    switch ($action) {
        case 'save_preferences':
            handleSavePreferences($pdo, $user_id, $data);
            break;
        case 'get_preferences':
            $league_id = $_GET['league_id'] ?? null;
            if ($league_id === '' || $league_id === 'global') $league_id = null;
            handleGetPreferences($pdo, $user_id, $league_id);
            break;
        case 'apply_globally':
            handleApplyGlobally($pdo, $user_id, $data);
            break;
        case 'delete_preferences':
            $league_id = $data['league_id'] ?? null;
            if ($league_id === '' || $league_id === 'global') $league_id = null;
            handleDeletePreferences($pdo, $user_id, $league_id);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    error_log("Draft Preferences API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

function handleSavePreferences($pdo, $user_id, $data) {
    if (!isset($data['preferences']) || !is_array($data['preferences'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing preferences data']);
        return;
    }

    $preferences = $data['preferences'];
    $league_id = $data['league_id'] ?? null;
    if ($league_id === '' || $league_id === 'global') $league_id = null;

    if (count($preferences) !== 30) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'All 30 teams must be ranked']);
        return;
    }

    // Validate ranks 1-30
    $ranks = array_column($preferences, 'priority_rank');
    sort($ranks);
    if ($ranks !== range(1, 30)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ranks must be 1-30 with no duplicates']);
        return;
    }

    // Validate team IDs
    $team_ids = array_column($preferences, 'team_id');
    $placeholders = str_repeat('?,', count($team_ids) - 1) . '?';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM nba_teams WHERE id IN ($placeholders)");
    $stmt->execute($team_ids);
    if ($stmt->fetchColumn() != 30) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid team IDs']);
        return;
    }

    // If league_id provided, verify user is a member
    if ($league_id !== null) {
        $stmt = $pdo->prepare("SELECT id FROM league_participants WHERE user_id = ? AND league_id = ? AND status = 'active'");
        $stmt->execute([$user_id, $league_id]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Not a member of this league']);
            return;
        }
    }

    $pdo->beginTransaction();
    try {
        // Delete existing preferences for this user + league scope
        if ($league_id === null) {
            $stmt = $pdo->prepare("DELETE FROM user_draft_preferences WHERE user_id = ? AND league_id IS NULL");
            $stmt->execute([$user_id]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM user_draft_preferences WHERE user_id = ? AND league_id = ?");
            $stmt->execute([$user_id, $league_id]);
        }

        // Insert new preferences
        $stmt = $pdo->prepare("INSERT INTO user_draft_preferences (user_id, league_id, team_id, priority_rank) VALUES (?, ?, ?, ?)");
        foreach ($preferences as $pref) {
            $stmt->execute([$user_id, $league_id, $pref['team_id'], $pref['priority_rank']]);
        }

        $pdo->commit();

        $scope = $league_id ? "league $league_id" : "global";
        error_log("User $user_id saved draft preferences ($scope): 30 teams ranked");

        echo json_encode([
            'success' => true,
            'message' => 'Draft preferences saved successfully',
            'scope' => $league_id ? 'league' : 'global'
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function handleGetPreferences($pdo, $user_id, $league_id) {
    // Try league-specific first, fall back to global
    if ($league_id !== null) {
        $stmt = $pdo->prepare("
            SELECT udp.team_id, udp.priority_rank, nt.name, nt.abbreviation, nt.conference
            FROM user_draft_preferences udp
            JOIN nba_teams nt ON udp.team_id = nt.id
            WHERE udp.user_id = ? AND udp.league_id = ?
            ORDER BY udp.priority_rank ASC
        ");
        $stmt->execute([$user_id, $league_id]);
        $prefs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($prefs)) {
            echo json_encode(['success' => true, 'preferences' => $prefs, 'scope' => 'league', 'total_ranked' => count($prefs)]);
            return;
        }
    }

    // Fall back to global
    $stmt = $pdo->prepare("
        SELECT udp.team_id, udp.priority_rank, nt.name, nt.abbreviation, nt.conference
        FROM user_draft_preferences udp
        JOIN nba_teams nt ON udp.team_id = nt.id
        WHERE udp.user_id = ? AND udp.league_id IS NULL
        ORDER BY udp.priority_rank ASC
    ");
    $stmt->execute([$user_id]);
    $prefs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'preferences' => $prefs,
        'scope' => 'global',
        'total_ranked' => count($prefs),
        'is_fallback' => ($league_id !== null && !empty($prefs))
    ]);
}

function handleApplyGlobally($pdo, $user_id, $data) {
    if (!isset($data['preferences']) || count($data['preferences']) !== 30) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'All 30 teams required']);
        return;
    }

    $preferences = $data['preferences'];

    // Get all user's leagues
    $stmt = $pdo->prepare("SELECT league_id FROM league_participants WHERE user_id = ? AND status = 'active'");
    $stmt->execute([$user_id]);
    $leagues = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $pdo->beginTransaction();
    try {
        // Save as global (NULL)
        $stmt = $pdo->prepare("DELETE FROM user_draft_preferences WHERE user_id = ? AND league_id IS NULL");
        $stmt->execute([$user_id]);

        $insert = $pdo->prepare("INSERT INTO user_draft_preferences (user_id, league_id, team_id, priority_rank) VALUES (?, ?, ?, ?)");
        foreach ($preferences as $pref) {
            $insert->execute([$user_id, null, $pref['team_id'], $pref['priority_rank']]);
        }

        // Also save to each league
        foreach ($leagues as $lid) {
            $stmt = $pdo->prepare("DELETE FROM user_draft_preferences WHERE user_id = ? AND league_id = ?");
            $stmt->execute([$user_id, $lid]);

            foreach ($preferences as $pref) {
                $insert->execute([$user_id, $lid, $pref['team_id'], $pref['priority_rank']]);
            }
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Preferences applied to all ' . count($leagues) . ' leagues + global default',
            'leagues_updated' => count($leagues)
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function handleDeletePreferences($pdo, $user_id, $league_id) {
    if ($league_id === null) {
        $stmt = $pdo->prepare("DELETE FROM user_draft_preferences WHERE user_id = ? AND league_id IS NULL");
        $stmt->execute([$user_id]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM user_draft_preferences WHERE user_id = ? AND league_id = ?");
        $stmt->execute([$user_id, $league_id]);
    }

    echo json_encode(['success' => true, 'message' => 'Preferences deleted', 'deleted_count' => $stmt->rowCount()]);
}
?>