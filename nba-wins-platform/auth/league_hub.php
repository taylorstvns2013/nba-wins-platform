<?php
// nba-wins-platform/auth/league_hub.php
// League Hub - Central management page for user's fantasy leagues
// Accessible after login/registration when user has no leagues, or via navigation
require_once '../config/db_connection.php';
require_once '../core/UserAuthentication.php';
require_once '../core/LeagueManager.php';
require_once '../core/ProfilePhotoHandler.php';

$auth = new UserAuthentication($pdo);
$leagueManager = new LeagueManager($pdo);
$photoHandler = new ProfilePhotoHandler($pdo);

// Must be logged in
if (!$auth->isAuthenticated()) {
    header('Location: login.php');
    exit;
}

// Don't allow guests
if ($auth->isGuest()) {
    header('Location: /index.php');
    exit;
}

$userId = $_SESSION['user_id'];
$activeTab = $_GET['tab'] ?? 'overview';

// =====================================================================
// Flash messages (Post-Redirect-GET pattern)
// =====================================================================
$message = '';
$messageType = '';
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// Retrieve stashed form data for repopulating forms after PRG redirect
$formData = $_SESSION['flash_form_data'] ?? [];
unset($_SESSION['flash_form_data']);

// =====================================================================
// Fetch user data for profile photo + settings
// =====================================================================
$stmt = $pdo->prepare("SELECT id, display_name, profile_photo, theme_preference, default_league_id FROM users WHERE id = ?");
$stmt->execute([$userId]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
$profile_photo_url = $photoHandler->getPhotoUrl($userId, $currentUser['profile_photo']);
$user_default_league_id = $currentUser['default_league_id'] ?: null;
$current_theme = $currentUser['theme_preference'] ?? $_SESSION['theme_preference'] ?? 'dark';
$_SESSION['theme_preference'] = $current_theme;

// =====================================================================
// POST HANDLING - All actions redirect back (PRG pattern)
// =====================================================================
if ($_POST) {
    $action = $_POST['action'] ?? '';
    $redirectTab = $activeTab;

    // --- Go to League (switch + redirect to index) ---
    if ($action === 'go_to_league') {
        $targetLeagueId = (int)($_POST['league_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id FROM league_participants WHERE user_id = ? AND league_id = ? AND status = 'active'");
        $stmt->execute([$userId, $targetLeagueId]);
        if ($stmt->fetch()) {
            $_SESSION['current_league_id'] = $targetLeagueId;
            $stmt = $pdo->prepare("UPDATE user_sessions SET current_league_id = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$targetLeagueId, $_SESSION['session_id'], $userId]);
            header('Location: /index.php');
            exit;
        }
    }

    // --- Profile Photo Upload ---
    if ($action === 'upload_photo') {
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $result = $photoHandler->uploadPhoto($userId, $_FILES['profile_photo']);
            $_SESSION['flash_message'] = $result['success'] ? $result['message'] : $result['error'];
            $_SESSION['flash_type'] = $result['success'] ? 'success' : 'error';
        } else {
            $_SESSION['flash_message'] = "Please select a valid image file.";
            $_SESSION['flash_type'] = 'error';
        }
    }

    // --- Delete Profile Photo ---
    if ($action === 'delete_photo') {
        $result = $photoHandler->deletePhoto($userId);
        $_SESSION['flash_message'] = $result['success'] ? ($result['message'] ?? 'Photo deleted.') : ($result['error'] ?? 'Failed to delete photo.');
        $_SESSION['flash_type'] = $result['success'] ? 'success' : 'error';
    }

    // --- Toggle Theme ---
    if ($action === 'toggle_theme') {
        $new_theme = $_POST['theme'] ?? 'dark';
        if (in_array($new_theme, ['dark', 'classic'])) {
            $stmt = $pdo->prepare("UPDATE users SET theme_preference = ? WHERE id = ?");
            $stmt->execute([$new_theme, $userId]);
            $_SESSION['theme_preference'] = $new_theme;
        }
    }

    // --- Set Default League ---
    if ($action === 'set_default_league') {
        $default_league_val = $_POST['default_league_id'] ?? '';
        if ($default_league_val === '' || $default_league_val === 'none') {
            $stmt = $pdo->prepare("UPDATE users SET default_league_id = NULL WHERE id = ?");
            $stmt->execute([$userId]);
            $_SESSION['flash_message'] = "Default league cleared.";
            $_SESSION['flash_type'] = 'success';
        } else {
            $default_league_val = intval($default_league_val);
            $stmt = $pdo->prepare("SELECT id FROM league_participants WHERE user_id = ? AND league_id = ? AND status = 'active'");
            $stmt->execute([$userId, $default_league_val]);
            if ($stmt->fetch()) {
                $stmt = $pdo->prepare("UPDATE users SET default_league_id = ? WHERE id = ?");
                $stmt->execute([$default_league_val, $userId]);
                $_SESSION['flash_message'] = "Default league updated.";
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = "You're not a member of that league.";
                $_SESSION['flash_type'] = 'error';
            }
        }
    }

    // --- Update Display Name ---
    if ($action === 'update_display_name') {
        $new_display_name = trim($_POST['display_name'] ?? '');
        if (!empty($new_display_name) && strlen($new_display_name) <= 20) {
            $stmt = $pdo->prepare("UPDATE users SET display_name = ? WHERE id = ?");
            $stmt->execute([$new_display_name, $userId]);
            $_SESSION['display_name'] = $new_display_name;
            $_SESSION['flash_message'] = "Display name updated!";
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = "Display name must be 1-20 characters.";
            $_SESSION['flash_type'] = 'error';
        }
    }

    // --- Join League ---
    if ($action === 'join_league') {
        $pinCode = trim($_POST['pin_code'] ?? '');
        $result = $leagueManager->joinLeague($userId, $pinCode);
        $_SESSION['flash_message'] = $result['message'];
        $_SESSION['flash_type'] = $result['success'] ? 'success' : 'error';
        if ($result['success']) {
            if (empty($_SESSION['current_league_id'])) {
                $_SESSION['current_league_id'] = $result['league_id'];
                $stmt = $pdo->prepare("UPDATE user_sessions SET current_league_id = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$result['league_id'], $_SESSION['session_id'], $userId]);
            }
            $redirectTab = 'overview';
        } else {
            $redirectTab = 'join';
        }
    }

    // --- Create League ---
    if ($action === 'create_league') {
        $leagueName = trim($_POST['league_name'] ?? '');
        $leagueSize = (int)($_POST['league_size'] ?? 6);
        $draftDate = trim($_POST['draft_date'] ?? '');
        $draftTime = trim($_POST['draft_time'] ?? '');

        $fullDraftDate = null;
        if (!empty($draftDate) && !empty($draftTime)) {
            $fullDraftDate = $draftDate . ' ' . $draftTime . ':00';
        } elseif (!empty($draftDate)) {
            $fullDraftDate = $draftDate . ' 20:00:00';
        }

        $result = $leagueManager->createLeague($userId, $leagueName, $leagueSize, $fullDraftDate);
        $_SESSION['flash_message'] = $result['message'];
        $_SESSION['flash_type'] = $result['success'] ? 'success' : 'error';
        if ($result['success']) {
            if (empty($_SESSION['current_league_id'])) {
                $_SESSION['current_league_id'] = $result['league_id'];
                $stmt = $pdo->prepare("UPDATE user_sessions SET current_league_id = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$result['league_id'], $_SESSION['session_id'], $userId]);
            }
            $redirectTab = 'overview';
        } else {
            $redirectTab = 'create';
        }
    }

    // --- Update Draft Date ---
    if ($action === 'update_draft_date') {
        $leagueId = (int)($_POST['league_id'] ?? 0);
        $draftDate = trim($_POST['draft_date'] ?? '');
        $draftTime = trim($_POST['draft_time'] ?? '');

        $fullDraftDate = $draftDate . ' ' . ($draftTime ?: '20:00') . ':00';
        $result = $leagueManager->updateDraftDate($userId, $leagueId, $fullDraftDate);
        $_SESSION['flash_message'] = $result['message'];
        $_SESSION['flash_type'] = $result['success'] ? 'success' : 'error';
    }

    // --- Toggle Auto-Draft ---
    if ($action === 'toggle_auto_draft') {
        $leagueId = (int)($_POST['league_id'] ?? 0);
        $enabled = (int)($_POST['auto_draft_enabled'] ?? 0);
        try {
            $stmt = $pdo->prepare("
                UPDATE league_participants 
                SET auto_draft_enabled = ? 
                WHERE user_id = ? AND league_id = ? AND status = 'active'
            ");
            $stmt->execute([$enabled, $userId, $leagueId]);
            $_SESSION['flash_message'] = $enabled ? 'Auto-draft enabled' : 'Auto-draft disabled';
            $_SESSION['flash_type'] = 'success';
        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Error toggling auto-draft';
            $_SESSION['flash_type'] = 'error';
        }
    }

    // Stash form data for repopulation on error
    if (!empty($redirectTab) && $redirectTab !== 'overview') {
        $_SESSION['flash_form_data'] = $_POST;
    }

    // PRG redirect
    header('Location: league_hub.php?tab=' . urlencode($redirectTab));
    exit;
}

// Get user's leagues
$userLeagues = $leagueManager->getUserLeaguesWithDetails($userId);
$commissionerLeagues = $leagueManager->getCommissionerLeagues($userId);

// Compute aggregate stats per league for collapsed summary
$leagueStats = [];
foreach ($userLeagues as $league) {
    $members = $leagueManager->getLeagueMembers($league['id']);
    $leagueStats[$league['id']] = [
        'members' => $members,
        'member_count' => count($members),
    ];
}

// Upcoming drafts: leagues with future draft dates that haven't completed
$upcomingDrafts = [];
$now = time();
foreach ($userLeagues as $league) {
    if (!empty($league['draft_date']) && !$league['draft_completed']) {
        $draftTime = strtotime($league['draft_date']);
        if ($draftTime && $draftTime > $now) {
            $upcomingDrafts[] = [
                'league_id' => $league['id'],
                'league_name' => $league['display_name'],
                'draft_date' => $league['draft_date'],
                'draft_timestamp' => $draftTime,
                'is_commissioner' => $league['is_commissioner'],
                'participants' => $league['current_participants'],
                'max_participants' => $league['user_limit'],
            ];
        }
    }
}
// Sort by soonest first
usort($upcomingDrafts, fn($a, $b) => $a['draft_timestamp'] - $b['draft_timestamp']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="theme-color" content="<?= $current_theme === 'classic' ? '#f5f5f5' : '#121a23' ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>League Hub - NBA Wins Pool</title>
    <link rel="apple-touch-icon" type="image/png" href="../public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="../public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           CSS VARIABLES - DARK THEME (default)
           ============================================================ */
        :root {
            --bg-primary: #0f1419;
            --bg-secondary: #161c24;
            --bg-card: #1a2230;
            --bg-card-hover: #1f2a3a;
            --bg-elevated: #243044;
            --bg-surface: #1e2736;
            --border-color: rgba(255, 255, 255, 0.07);
            --border-subtle: rgba(255, 255, 255, 0.04);
            --text-primary: #e6edf3;
            --text-secondary: #8b949e;
            --text-muted: #484f58;
            --accent-blue: #388bfd;
            --accent-blue-dim: rgba(56, 139, 253, 0.12);
            --accent-green: #3fb950;
            --accent-green-dim: rgba(63, 185, 80, 0.12);
            --accent-red: #f85149;
            --accent-orange: #d29922;
            --accent-purple: #a371f7;
            --accent-purple-dim: rgba(163, 113, 247, 0.12);
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --radius-xl: 18px;
            --shadow-card: 0 1px 3px rgba(0, 0, 0, 0.5), 0 0 0 1px var(--border-color);
            --shadow-elevated: 0 8px 30px rgba(0, 0, 0, 0.6);
            --shadow-glow-blue: 0 0 20px rgba(56, 139, 253, 0.15);
            --transition-fast: 0.15s ease;
            --transition-normal: 0.25s ease;
        }

        <?php if ($current_theme === 'classic'): ?>
        /* ============================================================
           CLASSIC (LIGHT) THEME OVERRIDES
           ============================================================ */
        :root {
            --bg-primary: #f3f4f6;
            --bg-secondary: #ebedf0;
            --bg-card: #ffffff;
            --bg-card-hover: #f8f9fb;
            --bg-elevated: #f0f1f4;
            --bg-surface: #f7f8fa;
            --border-color: rgba(0, 0, 0, 0.08);
            --border-subtle: rgba(0, 0, 0, 0.04);
            --text-primary: #1a1d23;
            --text-secondary: #5a6370;
            --text-muted: #9ca3af;
            --accent-blue: #2563eb;
            --accent-blue-dim: rgba(37, 99, 235, 0.08);
            --accent-green: #16a34a;
            --accent-green-dim: rgba(22, 163, 74, 0.08);
            --accent-red: #dc2626;
            --accent-orange: #ca8a04;
            --accent-purple: #7c3aed;
            --accent-purple-dim: rgba(124, 58, 237, 0.08);
            --shadow-card: 0 1px 3px rgba(0, 0, 0, 0.06), 0 0 0 1px rgba(0, 0, 0, 0.04);
            --shadow-elevated: 0 8px 24px rgba(0, 0, 0, 0.08);
            --shadow-glow-blue: 0 0 20px rgba(37, 99, 235, 0.08);
        }
        <?php endif; ?>

        /* ============================================================
           RESET & BASE
           ============================================================ */
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { background: var(--bg-primary); }

        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            margin: 0;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            line-height: 1.5;
        }

        /* ============================================================
           LAYOUT
           ============================================================ */
        .hub-container {
            max-width: 880px;
            margin: 0 auto;
            padding: 20px 16px 100px;
        }

        /* ============================================================
           PROFILE HEADER CARD
           ============================================================ */
        .profile-header-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: 28px 28px 24px;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-card);
        }

        .profile-header-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--accent-blue), var(--accent-purple), var(--accent-blue));
            background-size: 200% 100%;
            animation: shimmer 4s ease infinite;
        }

        @keyframes shimmer {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        .profile-row {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        /* Profile Photo */
        .hub-avatar-wrap {
            position: relative;
            flex-shrink: 0;
        }

        .hub-avatar {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--bg-elevated);
            transition: transform 0.2s, border-color 0.2s;
            cursor: pointer;
            display: block;
        }

        .hub-avatar:hover {
            border-color: var(--accent-blue);
            transform: scale(1.04);
        }

        .avatar-edit-badge {
            position: absolute;
            bottom: -1px;
            right: -1px;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: var(--accent-blue);
            color: #fff;
            border: 2px solid var(--bg-card);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3);
            opacity: 0;
            pointer-events: none;
        }

        .hub-avatar-wrap:hover .avatar-edit-badge {
            opacity: 1;
            pointer-events: auto;
        }

        .avatar-edit-badge:hover {
            background: #2a7ae4;
            transform: scale(1.12);
        }

        /* Profile Info */
        .profile-info {
            flex: 1;
            min-width: 0;
        }

        .profile-greeting {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-muted);
            margin-bottom: 2px;
        }

        .profile-name-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .profile-display-name {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.02em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .edit-name-icon {
            color: var(--text-muted);
            font-size: 13px;
            cursor: pointer;
            transition: color 0.15s;
            flex-shrink: 0;
            padding: 4px;
        }

        .edit-name-icon:hover {
            color: var(--accent-blue);
        }

        .profile-league-count {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 2px;
        }

        .profile-league-count span {
            color: var(--accent-blue);
            font-weight: 600;
        }

        /* ============================================================
           INLINE EDIT NAME FORM
           ============================================================ */
        .edit-name-form {
            display: none;
            margin-top: 12px;
            padding-top: 14px;
            border-top: 1px solid var(--border-color);
        }

        .edit-name-form.visible {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .edit-name-form input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-family: 'Outfit', sans-serif;
            font-size: 14px;
            background: var(--bg-elevated);
            color: var(--text-primary);
            outline: none;
            transition: border-color 0.2s;
        }

        .edit-name-form input:focus {
            border-color: var(--accent-blue);
        }

        .edit-name-form button {
            padding: 8px 14px;
            border: none;
            border-radius: var(--radius-sm);
            font-family: 'Outfit', sans-serif;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }

        .edit-name-form .btn-save {
            background: var(--accent-blue);
            color: #fff;
        }

        .edit-name-form .btn-save:hover {
            background: #2a7ae4;
        }

        .edit-name-form .btn-cancel {
            background: var(--bg-elevated);
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }

        /* ============================================================
           SETTINGS PANEL (collapsible below profile card)
           ============================================================ */
        .settings-panel {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            margin-bottom: 24px;
            overflow: hidden;
            box-shadow: var(--shadow-card);
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .settings-panel-header {
            padding: 16px 22px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
        }

        .setting-cell {
            padding: 16px 22px;
            border-bottom: 1px solid var(--border-color);
        }

        .setting-cell:nth-child(odd) {
            border-right: 1px solid var(--border-color);
        }

        .setting-cell:nth-last-child(-n+2) {
            border-bottom: none;
        }

        .setting-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Theme toggle */
        .theme-toggle-group {
            display: inline-flex;
            gap: 3px;
            background: var(--bg-elevated);
            border-radius: var(--radius-sm);
            padding: 3px;
            border: 1px solid var(--border-color);
        }

        .theme-btn {
            padding: 6px 14px;
            border: none;
            border-radius: 5px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Outfit', sans-serif;
            background: transparent;
            color: var(--text-muted);
            transition: all 0.2s;
        }

        .theme-btn:hover { color: var(--text-primary); }

        .theme-btn.active {
            background: var(--accent-blue);
            color: #fff;
            box-shadow: 0 1px 4px rgba(56, 139, 253, 0.3);
        }

        /* Default league select */
        .default-league-select {
            width: 100%;
            padding: 8px 32px 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-family: 'Outfit', sans-serif;
            font-size: 13px;
            background-color: var(--bg-elevated);
            color: var(--text-primary);
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%238b949e' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            outline: none;
            transition: border-color 0.2s;
        }

        .default-league-select:focus {
            border-color: var(--accent-blue);
        }

        .default-league-select option {
            background: var(--bg-card);
            color: var(--text-primary);
        }

        .setting-hint {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 6px;
            line-height: 1.4;
        }

        /* ============================================================
           MESSAGE BANNER
           ============================================================ */
        .message-banner {
            padding: 12px 18px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.3s ease;
        }

        .message-banner.success {
            background: var(--accent-green-dim);
            color: var(--accent-green);
            border: 1px solid rgba(63, 185, 80, 0.2);
        }

        .message-banner.error {
            background: rgba(248, 81, 73, 0.1);
            color: var(--accent-red);
            border: 1px solid rgba(248, 81, 73, 0.2);
        }

        /* ============================================================
           TAB NAVIGATION
           ============================================================ */
        .tab-nav {
            display: flex;
            gap: 4px;
            margin-bottom: 24px;
            background: var(--bg-card);
            padding: 4px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-card);
        }

        .tab-btn {
            flex: 1;
            padding: 10px 16px;
            border: none;
            background: transparent;
            color: var(--text-secondary);
            font-family: 'Outfit', sans-serif;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.15s ease;
            text-align: center;
        }

        .tab-btn:hover {
            color: var(--text-primary);
            background: rgba(255, 255, 255, 0.03);
        }

        .tab-btn.active {
            background: var(--accent-blue);
            color: #fff;
        }

        .tab-btn i { margin-right: 6px; }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* ============================================================
           LEAGUE CARDS (Collapsible)
           ============================================================ */
        .league-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            margin-bottom: 14px;
            overflow: hidden;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-shadow: var(--shadow-card);
            animation: cardFadeIn 0.4s ease both;
        }

        .league-card:nth-child(2) { animation-delay: 0.06s; }
        .league-card:nth-child(3) { animation-delay: 0.12s; }

        @keyframes cardFadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .league-card:hover {
            border-color: rgba(56, 139, 253, 0.2);
        }

        .league-card.commissioner-card {
            border-left: 3px solid var(--accent-purple);
        }

        /* Summary row (always visible) */
        .league-summary {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px 20px;
            cursor: pointer;
            user-select: none;
            transition: background 0.15s;
        }

        .league-summary:hover {
            background: var(--bg-card-hover);
        }

        .league-summary-info {
            flex: 1;
            min-width: 0;
        }

        .league-summary-top {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .league-name {
            font-size: 16px;
            font-weight: 700;
            letter-spacing: -0.01em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .league-badge {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            flex-shrink: 0;
        }

        .badge-commissioner {
            background: var(--accent-purple-dim);
            color: var(--accent-purple);
            border: 1px solid rgba(163, 113, 247, 0.2);
        }

        .badge-member {
            background: var(--accent-blue-dim);
            color: var(--accent-blue);
            border: 1px solid rgba(56, 139, 253, 0.2);
        }

        .badge-default {
            background: var(--accent-green-dim);
            color: var(--accent-green);
            border: 1px solid rgba(63, 185, 80, 0.2);
            font-size: 9px;
        }

        .league-summary-meta {
            display: flex;
            gap: 14px;
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 4px;
        }

        .league-summary-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .league-summary-meta i {
            font-size: 10px;
            color: var(--text-muted);
        }

        /* Go to League button */
        .go-to-league-btn {
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 6px 14px;
            border: 1px solid rgba(56, 139, 253, 0.25);
            border-radius: var(--radius-sm);
            background: var(--accent-blue-dim);
            color: var(--accent-blue);
            font-family: 'Outfit', sans-serif;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .go-to-league-btn:hover {
            background: var(--accent-blue);
            color: #fff;
            border-color: var(--accent-blue);
            box-shadow: 0 2px 8px rgba(56, 139, 253, 0.25);
        }

        .go-to-league-btn i {
            font-size: 11px;
        }

        .expand-icon {
            color: var(--text-muted);
            font-size: 14px;
            transition: transform 0.25s ease;
            flex-shrink: 0;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .league-card.expanded .expand-icon {
            transform: rotate(180deg);
        }

        /* Expanded details */
        .league-details {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.35s ease;
        }

        .league-card.expanded .league-details {
            max-height: 800px;
        }

        /* Draft actions row — always visible when draft not complete */
        .draft-actions-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 20px;
            border-top: 1px solid var(--border-color);
            flex-wrap: wrap;
        }
        .draft-action-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: var(--radius-sm);
            font-family: 'Outfit', sans-serif;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s;
            border: 1px solid;
        }
        .draft-action-btn.prefs {
            background: var(--accent-blue-dim);
            color: var(--accent-blue);
            border-color: rgba(56,139,253,0.25);
        }
        .draft-action-btn.prefs:hover { box-shadow: 0 2px 8px rgba(56,139,253,0.15); }
        .auto-toggle {
            background: var(--bg-elevated);
            color: var(--text-secondary);
            border-color: var(--border-color);
        }
        .auto-toggle.on {
            background: rgba(63,185,80,0.12);
            color: var(--accent-green);
            border-color: rgba(63,185,80,0.3);
        }

        .league-details-inner {
            padding: 0 20px 20px;
            border-top: 1px solid var(--border-color);
        }

        /* Commissioner sections inside expanded */
        .commissioner-section {
            margin-top: 16px;
        }

        .section-label {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 600;
            margin-bottom: 10px;
        }

        /* PIN display */
        .pin-display {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, rgba(63, 185, 80, 0.08) 0%, rgba(63, 185, 80, 0.02) 100%);
            padding: 10px 18px;
            border-radius: var(--radius-md);
            font-family: 'Courier New', monospace;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.18em;
            color: var(--accent-green);
            border: 1px solid rgba(63, 185, 80, 0.15);
        }

        .pin-display .copy-btn {
            background: rgba(63, 185, 80, 0.1);
            border: 1px solid rgba(63, 185, 80, 0.12);
            color: var(--accent-green);
            cursor: pointer;
            font-size: 13px;
            padding: 4px 8px;
            border-radius: 6px;
            transition: all 0.15s ease;
        }

        .pin-display .copy-btn:hover {
            background: rgba(63, 185, 80, 0.2);
        }

        /* Members list */
        .members-container {
            background: var(--bg-elevated);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            overflow: hidden;
        }

        .members-list {
            list-style: none;
        }

        .members-list li {
            padding: 9px 14px;
            border-bottom: 1px solid var(--border-color);
            font-size: 13px;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 10px;
            transition: background 0.12s;
        }

        .members-list li:last-child { border-bottom: none; }

        .members-list li:hover {
            background: rgba(255, 255, 255, 0.02);
        }

        .member-name { font-weight: 500; color: var(--text-primary); }
        .member-username { color: var(--text-muted); font-size: 11px; }

        .member-icon {
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 10px;
            flex-shrink: 0;
        }

        .member-icon.commissioner { background: var(--accent-purple-dim); color: var(--accent-purple); }
        .member-icon.regular { background: rgba(255, 255, 255, 0.04); color: var(--text-muted); }
        .member-icon.open-slot { background: transparent; border: 1px dashed var(--border-color); color: var(--text-muted); }

        /* Commissioner details grid */
        .commissioner-details-grid {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 16px;
            align-items: start;
        }

        /* Draft date section */
        .draft-date-section {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--border-color);
        }

        /* ============================================================
           FORMS (Join / Create / Draft Date)
           ============================================================ */
        .card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            padding: 24px;
            margin-bottom: 16px;
            box-shadow: var(--shadow-card);
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-title i { color: var(--accent-blue); }

        .form-group { margin-bottom: 18px; }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: 14px;
        }

        input[type="text"],
        input[type="date"],
        input[type="time"],
        select {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 15px;
            font-family: 'Outfit', sans-serif;
            transition: border-color 0.2s, box-shadow 0.2s;
            background-color: var(--bg-elevated);
            color: var(--text-primary);
            outline: none;
        }

        select {
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%238b949e' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 36px;
        }

        select option { background: var(--bg-card); color: var(--text-primary); }

        input:focus, select:focus {
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px var(--accent-blue-dim);
        }

        input[type="date"]::-webkit-calendar-picker-indicator,
        input[type="time"]::-webkit-calendar-picker-indicator {
            filter: invert(0.7);
        }

        <?php if ($current_theme === 'classic'): ?>
        input[type="date"]::-webkit-calendar-picker-indicator,
        input[type="time"]::-webkit-calendar-picker-indicator {
            filter: none;
        }
        <?php endif; ?>

        .help-text {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
        }

        .form-row {
            display: flex;
            gap: 10px;
        }

        .form-row .form-group { flex: 1; }

        .submit-btn {
            width: 100%;
            padding: 13px 20px;
            border: none;
            border-radius: var(--radius-md);
            font-size: 15px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .submit-btn:hover { transform: translateY(-1px); }

        .btn-blue {
            background: linear-gradient(135deg, var(--accent-blue), #1a6ddb);
            color: #fff;
        }

        .btn-blue:hover { box-shadow: 0 4px 16px rgba(56, 139, 253, 0.3); }

        .btn-green {
            background: linear-gradient(135deg, var(--accent-green), #2ea043);
            color: #fff;
        }

        .btn-green:hover { box-shadow: 0 4px 16px rgba(63, 185, 80, 0.3); }

        /* ============================================================
           EMPTY STATE
           ============================================================ */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 48px;
            color: var(--text-muted);
            margin-bottom: 16px;
            display: block;
        }

        .empty-state h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .empty-state p { font-size: 14px; margin-bottom: 20px; }

        .empty-state-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .empty-state-actions button {
            padding: 10px 20px;
            border-radius: var(--radius-md);
            font-family: 'Outfit', sans-serif;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.15s;
        }

        .empty-state-actions .btn-primary { background: var(--accent-blue); color: #fff; }

        .empty-state-actions .btn-secondary {
            background: var(--bg-elevated);
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }

        /* ============================================================
           PHOTO OPTIONS MODAL
           ============================================================ */
        .photo-modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .photo-modal-overlay.visible {
            display: flex;
        }

        .photo-modal {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 28px;
            max-width: 360px;
            width: 90%;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
            text-align: center;
            border: 1px solid var(--border-color);
        }

        .photo-modal h3 {
            margin: 0 0 16px;
            color: var(--text-primary);
            font-size: 17px;
        }

        .photo-modal-preview {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 18px;
            border: 3px solid var(--bg-elevated);
            display: block;
        }

        .photo-modal-buttons {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .photo-modal-btn {
            padding: 10px 18px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-elevated);
            color: var(--text-primary);
            cursor: pointer;
            transition: all 0.2s;
            font-size: 13px;
            font-family: 'Outfit', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            font-weight: 500;
        }

        .photo-modal-btn:hover { background: var(--bg-card-hover); }

        .photo-modal-btn.primary {
            background: var(--accent-blue);
            color: #fff;
            border-color: var(--accent-blue);
        }

        .photo-modal-btn.danger {
            background: var(--accent-red);
            color: #fff;
            border-color: var(--accent-red);
        }

        .photo-modal-hint {
            margin-top: 12px;
            font-size: 11px;
            color: var(--text-muted);
        }

        /* ============================================================
           UPCOMING DRAFTS
           ============================================================ */
        .upcoming-drafts-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            overflow: hidden;
            margin-top: 20px;
            box-shadow: var(--shadow-card);
        }

        .upcoming-drafts-header {
            padding: 14px 20px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--accent-orange);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .upcoming-draft-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            border-bottom: 1px solid var(--border-color);
            gap: 12px;
        }

        .upcoming-draft-row:last-child {
            border-bottom: none;
        }

        .upcoming-draft-info {
            flex: 1;
            min-width: 0;
        }

        .upcoming-draft-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .upcoming-draft-meta {
            display: flex;
            gap: 12px;
            font-size: 11px;
            color: var(--text-secondary);
            margin-top: 3px;
        }

        .upcoming-draft-meta span {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .upcoming-draft-meta i {
            font-size: 9px;
        }

        .upcoming-draft-date {
            text-align: right;
            flex-shrink: 0;
        }

        .upcoming-draft-day {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .upcoming-draft-time {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .upcoming-draft-countdown {
            font-size: 10px;
            font-weight: 700;
            color: var(--accent-orange);
            margin-top: 2px;
        }

        /* ============================================================
           MOBILE TAB HEADER + ACTION DROPDOWN
           ============================================================ */
        .mobile-tab-header {
            display: none; /* Hidden on desktop by default */
        }

        .mobile-tab-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .mobile-tab-title i {
            color: var(--accent-blue);
            font-size: 14px;
        }

        .mobile-action-select {
            padding: 7px 30px 7px 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-family: 'Outfit', sans-serif;
            font-size: 13px;
            font-weight: 600;
            background-color: var(--accent-blue-dim);
            color: var(--accent-blue);
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 12 12'%3E%3Cpath fill='%23388bfd' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            outline: none;
        }

        .mobile-action-select option {
            background: var(--bg-card);
            color: var(--text-primary);
            font-weight: 400;
        }

        /* Mobile back button (only visible on mobile) */
        .mobile-back-btn {
            display: none; /* Hidden on desktop */
            padding: 8px 14px;
            margin-bottom: 16px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            background: var(--bg-elevated);
            color: var(--text-secondary);
            font-family: 'Outfit', sans-serif;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
            align-items: center;
            gap: 6px;
        }

        .mobile-back-btn:hover {
            color: var(--text-primary);
            border-color: var(--accent-blue);
        }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 600px) {
            .hub-container { padding: 12px 10px 100px; }

            .profile-header-card { padding: 20px 16px 18px; }

            .hub-avatar { width: 54px; height: 54px; }

            .profile-display-name { font-size: 18px; }

            .profile-row { gap: 12px; }

            /* Hide desktop tabs, show mobile header */
            .tab-nav-desktop { display: none !important; }

            .mobile-tab-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 18px;
                gap: 10px;
            }

            .mobile-back-btn {
                display: flex;
            }

            .form-row { flex-direction: column; gap: 0; }

            .settings-grid {
                grid-template-columns: 1fr;
            }

            .settings-grid .setting-cell:nth-child(odd) {
                border-right: none;
            }

            .commissioner-details-grid {
                grid-template-columns: 1fr;
            }

            .league-summary { padding: 14px 14px; gap: 10px; }

            .league-details-inner { padding: 0 14px 16px; }

            .league-summary-meta {
                flex-direction: column;
                gap: 4px;
            }

            .go-to-league-btn span { display: none; }
            .go-to-league-btn { padding: 6px 10px; }

            .upcoming-draft-row { padding: 12px 14px; }
        }

        @media (min-width: 601px) {
            .hub-container {
                max-width: 880px;
                padding: 32px 20px 100px;
            }

            .profile-header-card {
                padding: 32px 32px 28px;
            }

            .hub-avatar { width: 72px; height: 72px; }

            .profile-display-name { font-size: 24px; }

            .tab-btn { padding: 12px 20px; font-size: 15px; }

            .league-summary { padding: 18px 24px; }

            .league-details-inner { padding: 0 24px 24px; }

            .card { padding: 32px; }

        }

        <?php if ($current_theme === 'classic'): ?>
        /* Light-mode tweaks */
        .league-summary:hover { background: rgba(0, 0, 0, 0.02); }
        .members-list li:hover { background: rgba(0, 0, 0, 0.02); }
        .member-icon.regular { background: rgba(0, 0, 0, 0.04); }
        .member-icon.commissioner { background: rgba(124, 58, 237, 0.08); }
        .member-icon.open-slot { border-color: #ddd; }
        .pin-display { background: linear-gradient(135deg, rgba(22, 163, 74, 0.06) 0%, rgba(22, 163, 74, 0.02) 100%); border-color: rgba(22, 163, 74, 0.2); }
        .pin-display .copy-btn { background: rgba(22, 163, 74, 0.06); border-color: rgba(22, 163, 74, 0.15); }
        .pin-display .copy-btn:hover { background: rgba(22, 163, 74, 0.12); }
        .members-container { background: #fafafa; border-color: #e5e7eb; }
        .default-league-select { background-color: #fff; border-color: #ddd; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23666666' d='M6 8L1 3h10z'/%3E%3C/svg%3E"); }
        .theme-toggle-group { background: #f0f0f0; border-color: #ddd; }
        .mobile-action-select { background-color: rgba(37, 99, 235, 0.06); background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 12 12'%3E%3Cpath fill='%232563eb' d='M6 8L1 3h10z'/%3E%3C/svg%3E"); }
        .mobile-back-btn { background: #fff; border-color: #ddd; }
        .mobile-back-btn:hover { background: #f5f5f5; }
        input[type="date"]::-webkit-calendar-picker-indicator,
        input[type="time"]::-webkit-calendar-picker-indicator { filter: none; }
        <?php endif; ?>
    </style>
</head>
<body>
    <div class="hub-container">

        <!-- ================================================================
             PROFILE HEADER CARD
             ================================================================ -->
        <div class="profile-header-card">
            <div class="profile-row">
                <!-- Avatar with edit overlay -->
                <div class="hub-avatar-wrap">
                    <img src="<?= htmlspecialchars($profile_photo_url) ?>" alt=""
                         class="hub-avatar"
                         onerror="this.src='../public/assets/profile_photos/default.png'"
                         onclick="showPhotoModal()">
                    <div class="avatar-edit-badge" onclick="showPhotoModal()" title="Edit photo">
                        <i class="fas fa-camera"></i>
                    </div>
                </div>

                <!-- Name + League Count -->
                <div class="profile-info">
                    <div class="profile-greeting">League Hub</div>
                    <div class="profile-name-row">
                        <span class="profile-display-name"><?= htmlspecialchars($currentUser['display_name']) ?></span>
                        <i class="fas fa-pen edit-name-icon" onclick="toggleEditName()" title="Edit name"></i>
                    </div>
                    <div class="profile-league-count">
                        <?php if (count($userLeagues) > 0): ?>
                            <span><?= count($userLeagues) ?></span> league<?= count($userLeagues) !== 1 ? 's' : '' ?>
                            <?php
                            $commishCount = count(array_filter($userLeagues, fn($l) => $l['is_commissioner']));
                            if ($commishCount > 0): ?>
                                &middot; Commissioner of <?= $commishCount ?>
                            <?php endif; ?>
                        <?php else: ?>
                            No leagues yet
                        <?php endif; ?>
                    </div>
                </div>


            </div>

            <!-- Inline Edit Name Form -->
            <form method="POST" class="edit-name-form" id="editNameForm">
                <input type="hidden" name="action" value="update_display_name">
                <input type="text" name="display_name" value="<?= htmlspecialchars($currentUser['display_name']) ?>"
                       maxlength="20" placeholder="Display name" required>
                <button type="submit" class="btn-save">Save</button>
                <button type="button" class="btn-cancel" onclick="toggleEditName()">Cancel</button>
            </form>
        </div>

        <!-- ================================================================
             SETTINGS
             ================================================================ -->
        <div class="settings-panel" id="settingsPanel">
            <div class="settings-panel-header">
                <i class="fas fa-sliders"></i> Settings
            </div>
            <div class="settings-grid">
                <!-- Theme -->
                <div class="setting-cell">
                    <div class="setting-label"><i class="fas fa-palette"></i> Theme</div>
                    <div class="theme-toggle-group">
                        <button type="button" class="theme-btn <?= $current_theme === 'dark' ? 'active' : '' ?>"
                                onclick="setTheme('dark')">
                            <i class="fas fa-moon" style="margin-right: 3px"></i> Dark
                        </button>
                        <button type="button" class="theme-btn <?= $current_theme === 'classic' ? 'active' : '' ?>"
                                onclick="setTheme('classic')">
                            <i class="fas fa-sun" style="margin-right: 3px"></i> Light
                        </button>
                    </div>
                    <form method="POST" id="themeForm" style="display: none">
                        <input type="hidden" name="action" value="toggle_theme">
                        <input type="hidden" name="theme" id="themeInput" value="">
                    </form>
                </div>

                <!-- Default League -->
                <div class="setting-cell">
                    <div class="setting-label"><i class="fas fa-house-flag"></i> Default League</div>
                    <?php if (!empty($userLeagues)): ?>
                        <form method="POST" id="defaultLeagueForm">
                            <input type="hidden" name="action" value="set_default_league">
                            <select name="default_league_id" class="default-league-select"
                                    onchange="document.getElementById('defaultLeagueForm').submit()">
                                <option value="none"<?= $user_default_league_id === null ? ' selected' : '' ?>>No default</option>
                                <?php foreach ($userLeagues as $ul): ?>
                                    <option value="<?= $ul['id'] ?>"<?= $user_default_league_id == $ul['id'] ? ' selected' : '' ?>>
                                        <?= htmlspecialchars($ul['display_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <div class="setting-hint">Opens to this league on login</div>
                    <?php else: ?>
                        <div class="setting-hint">Join a league first</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ================================================================
             MESSAGES
             ================================================================ -->
        <?php if ($message): ?>
            <div class="message-banner <?= $messageType ?>">
                <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- ================================================================
             TABS - Desktop: full tabs / Mobile: header + action dropdown
             ================================================================ -->
        <!-- Desktop tabs (hidden on mobile) -->
        <div class="tab-nav tab-nav-desktop">
            <button class="tab-btn <?= $activeTab === 'overview' ? 'active' : '' ?>" onclick="switchTab('overview')">
                My Leagues
            </button>
            <button class="tab-btn <?= $activeTab === 'join' ? 'active' : '' ?>" onclick="switchTab('join')">
                <i class="fas fa-sign-in-alt"></i> Join
            </button>
            <button class="tab-btn <?= $activeTab === 'create' ? 'active' : '' ?>" onclick="switchTab('create')">
                <i class="fas fa-plus-circle"></i> Create
            </button>
        </div>

        <!-- Mobile header + action dropdown (hidden on desktop) -->
        <div class="mobile-tab-header">
            <div class="mobile-tab-title">
             My Leagues
            </div>
            <div class="mobile-action-wrap">
                <select class="mobile-action-select" onchange="handleMobileAction(this)">
                    <option value="" selected disabled>
                        + Actions
                    </option>
                    <option value="join">Join a League</option>
                    <option value="create">Create a League</option>
                </select>
            </div>
        </div>

        <!-- ================================================================
             TAB: MY LEAGUES (Collapsible Cards)
             ================================================================ -->
        <div id="tab-overview" class="tab-content <?= $activeTab === 'overview' ? 'active' : '' ?>">
            <?php if (empty($userLeagues)): ?>
                <div class="card">
                    <div class="empty-state">
                        <i class="fas fa-basketball-ball"></i>
                        <h3>No Leagues Yet</h3>
                        <p>Join an existing league with a PIN code, or create your own and invite friends.</p>
                        <div class="empty-state-actions">
                            <button class="btn-primary" onclick="switchTab('join')"><i class="fas fa-sign-in-alt"></i> Join a League</button>
                            <button class="btn-secondary" onclick="switchTab('create')"><i class="fas fa-plus"></i> Create a League</button>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($userLeagues as $league):
                    $isCommish = $league['is_commissioner'];
                    $members = $leagueStats[$league['id']]['members'];
                    $isDefault = ($user_default_league_id == $league['id']);
                ?>
                    <div class="league-card <?= $isCommish ? 'commissioner-card' : '' ?>" id="league-<?= $league['id'] ?>">
                        <!-- Collapsed Summary (always visible) -->
                        <div class="league-summary" onclick="toggleLeague(<?= $league['id'] ?>)">
                            <div class="league-summary-info">
                                <div class="league-summary-top">
                                    <span class="league-name"><?= htmlspecialchars($league['display_name']) ?></span>
                                    <span class="league-badge <?= $isCommish ? 'badge-commissioner' : 'badge-member' ?>">
                                        <?= $isCommish ? 'Commissioner' : 'Member' ?>
                                    </span>
                                    <?php if ($isDefault): ?>
                                        <span class="league-badge badge-default"><i class="fas fa-star" style="margin-right: 2px"></i> Default</span>
                                    <?php endif; ?>
                                </div>
                                <div class="league-summary-meta">
                                    <span><i class="fas fa-users"></i> <?= $league['current_participants'] ?>/<?= $league['user_limit'] ?></span>
                                    <span>
                                        <i class="fas fa-calendar-alt"></i>
                                        <?php if ($league['draft_date']): ?>
                                            <?= date('M j, Y', strtotime($league['draft_date'])) ?>
                                        <?php else: ?>
                                            No draft date
                                        <?php endif; ?>
                                    </span>
                                    <?php if ($league['draft_completed']): ?>
                                        <span style="color: var(--accent-green);"><i class="fas fa-check-circle"></i> Drafted</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <form method="POST" style="margin: 0; flex-shrink: 0;" onclick="event.stopPropagation()">
                                <input type="hidden" name="action" value="go_to_league">
                                <input type="hidden" name="league_id" value="<?= $league['id'] ?>">
                                <button type="submit" class="go-to-league-btn" title="Open this league">
                                    <i class="fas fa-arrow-right"></i>
                                    <span>Go</span>
                                </button>
                            </form>
                            <div class="expand-icon">
                                <i class="fas fa-chevron-down"></i>
                            </div>
                        </div>

                        <?php if (!$league['draft_completed']): ?>
                        <?php
                        $adStmt = $pdo->prepare("SELECT auto_draft_enabled FROM league_participants WHERE user_id = ? AND league_id = ? AND status = 'active'");
                        $adStmt->execute([$userId, $league['id']]);
                        $autoDraftEnabled = (bool)$adStmt->fetchColumn();
                        ?>
                        <div class="draft-actions-row">
                            <a href="/nba-wins-platform/profiles/draft_preferences.php?league_id=<?= $league['id'] ?>"
                               class="draft-action-btn prefs">
                                <i class="fas fa-list-ol"></i> Draft Preferences
                            </a>
                            <form method="POST" style="display:inline; margin:0;">
                                <input type="hidden" name="action" value="toggle_auto_draft">
                                <input type="hidden" name="league_id" value="<?= $league['id'] ?>">
                                <input type="hidden" name="auto_draft_enabled" value="<?= $autoDraftEnabled ? 0 : 1 ?>">
                                <button type="submit" class="draft-action-btn auto-toggle <?= $autoDraftEnabled ? 'on' : '' ?>">
                                    <i class="fas <?= $autoDraftEnabled ? 'fa-toggle-on' : 'fa-toggle-off' ?>"></i>
                                    Auto-Draft <?= $autoDraftEnabled ? 'ON' : 'OFF' ?>
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>

                        <!-- Expanded Details -->
                        <div class="league-details">
                            <div class="league-details-inner">
                                <?php if ($isCommish): ?>
                                    <!-- Commissioner: PIN + Members -->
                                    <div class="commissioner-section">
                                        <div class="commissioner-details-grid">
                                            <div>
                                                <div class="section-label">League PIN</div>
                                                <div class="pin-display">
                                                    <span id="pin-<?= $league['id'] ?>"><?= htmlspecialchars($league['pin_code']) ?></span>
                                                    <button class="copy-btn" onclick="event.stopPropagation(); copyPIN('pin-<?= $league['id'] ?>')" title="Copy PIN">
                                                        <i class="fas fa-copy"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <div>
                                                <div class="section-label">Members</div>
                                                <div class="members-container">
                                                    <ul class="members-list">
                                                        <?php foreach ($members as $member): ?>
                                                            <li>
                                                                <div class="member-icon <?= $member['is_commissioner'] ? 'commissioner' : 'regular' ?>">
                                                                    <i class="fas <?= $member['is_commissioner'] ? 'fa-crown' : 'fa-user' ?>"></i>
                                                                </div>
                                                                <span class="member-name"><?= htmlspecialchars($member['display_name']) ?></span>
                                                                <span class="member-username">@<?= htmlspecialchars($member['username']) ?></span>
                                                            </li>
                                                        <?php endforeach; ?>
                                                        <?php
                                                        $emptySlots = $league['user_limit'] - count($members);
                                                        for ($i = 0; $i < $emptySlots; $i++): ?>
                                                            <li>
                                                                <div class="member-icon open-slot">
                                                                    <i class="fas fa-user-plus"></i>
                                                                </div>
                                                                <span style="color: var(--text-muted); font-style: italic;">Open slot</span>
                                                            </li>
                                                        <?php endfor; ?>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Update draft date (commissioner only, if draft not complete) -->
                                    <?php if (!$league['draft_completed']): ?>
                                        <div class="draft-date-section">
                                            <div class="section-label">
                                                <?= $league['draft_date'] ? 'Update Draft Date' : 'Set Draft Date' ?>
                                            </div>
                                            <form method="POST" onclick="event.stopPropagation()">
                                                <input type="hidden" name="action" value="update_draft_date">
                                                <input type="hidden" name="league_id" value="<?= $league['id'] ?>">
                                                <div class="form-row">
                                                    <div class="form-group" style="margin-bottom: 0;">
                                                        <input type="date" name="draft_date"
                                                               value="<?= $league['draft_date'] ? date('Y-m-d', strtotime($league['draft_date'])) : '' ?>"
                                                               min="<?= date('Y-m-d') ?>" required>
                                                    </div>
                                                    <div class="form-group" style="margin-bottom: 0;">
                                                        <input type="time" name="draft_time"
                                                               value="<?= $league['draft_date'] ? date('H:i', strtotime($league['draft_date'])) : '20:00' ?>">
                                                    </div>
                                                    <div style="flex: 0;">
                                                        <button type="submit" class="submit-btn btn-blue" style="width: auto; padding: 11px 16px;">
                                                            <i class="fas fa-save"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <!-- Regular member: just show members list -->
                                    <div class="commissioner-section">
                                        <div class="section-label">Members</div>
                                        <div class="members-container">
                                            <ul class="members-list">
                                                <?php foreach ($members as $member): ?>
                                                    <li>
                                                        <div class="member-icon <?= $member['is_commissioner'] ? 'commissioner' : 'regular' ?>">
                                                            <i class="fas <?= $member['is_commissioner'] ? 'fa-crown' : 'fa-user' ?>"></i>
                                                        </div>
                                                        <span class="member-name"><?= htmlspecialchars($member['display_name']) ?></span>
                                                        <span class="member-username">@<?= htmlspecialchars($member['username']) ?></span>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Upcoming Drafts Section -->
            <?php if (!empty($upcomingDrafts)): ?>
                <div class="upcoming-drafts-card">
                    <div class="upcoming-drafts-header">
                        <i class="fas fa-calendar-check"></i> Upcoming Drafts
                    </div>
                    <?php foreach ($upcomingDrafts as $draft): ?>
                        <div class="upcoming-draft-row">
                            <div class="upcoming-draft-info">
                                <div class="upcoming-draft-name"><?= htmlspecialchars($draft['league_name']) ?></div>
                                <div class="upcoming-draft-meta">
                                    <span><i class="fas fa-users"></i> <?= $draft['participants'] ?>/<?= $draft['max_participants'] ?></span>
                                    <?php if ($draft['is_commissioner']): ?>
                                        <span style="color: var(--accent-purple);"><i class="fas fa-crown"></i> Commissioner</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="upcoming-draft-date">
                                <div class="upcoming-draft-day"><?= date('M j', $draft['draft_timestamp']) ?></div>
                                <div class="upcoming-draft-time"><?= date('g:i A', $draft['draft_timestamp']) ?></div>
                                <?php
                                $draftDay = date('Y-m-d', $draft['draft_timestamp']);
                                $todayDate = date('Y-m-d');
                                $tomorrowDate = date('Y-m-d', strtotime('+1 day'));
                                $daysUntil = (int)floor(($draft['draft_timestamp'] - $now) / 86400);
                                if ($draftDay === $todayDate || $daysUntil <= 7): ?>
                                    <div class="upcoming-draft-countdown">
                                        <?php
                                        if ($draftDay === $todayDate) echo 'Today';
                                        elseif ($draftDay === $tomorrowDate) echo 'Tomorrow';
                                        elseif ($daysUntil <= 7) echo "In {$daysUntil}d";
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ================================================================
             TAB: JOIN A LEAGUE
             ================================================================ -->
        <div id="tab-join" class="tab-content <?= $activeTab === 'join' ? 'active' : '' ?>">
            <button class="mobile-back-btn" onclick="switchTab('overview')">
                <i class="fas fa-arrow-left"></i> Back to Leagues
            </button>
            <div class="card">
                <div class="card-title">
                    <i class="fas fa-sign-in-alt"></i> Join a League
                </div>
                <?php if (!LEAGUE_JOINING_ENABLED): ?>
                    <div class="empty-state" style="padding: 30px 20px;">
                        <i class="fas fa-lock" style="font-size: 36px;"></i>
                        <h3>Joining Closed</h3>
                        <p><?= LEAGUE_JOINING_DISABLED_MESSAGE ?></p>
                    </div>
                <?php else: ?>
                <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 20px;">
                    Enter the league PIN code provided by your commissioner to join.
                </p>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="join_league">
                    <div class="form-group">
                        <label for="pin_code">League PIN Code</label>
                        <input type="text" id="pin_code" name="pin_code"
                               placeholder="e.g. X7K9M2"
                               maxlength="10"
                               style="text-transform: uppercase; letter-spacing: 0.1em; font-size: 18px; text-align: center;"
                               required>
                        <div class="help-text">6-character PIN from the league commissioner</div>
                    </div>
                    <button type="submit" class="submit-btn btn-blue">
                        <i class="fas fa-sign-in-alt"></i> Join League
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- ================================================================
             TAB: CREATE A LEAGUE
             ================================================================ -->
        <div id="tab-create" class="tab-content <?= $activeTab === 'create' ? 'active' : '' ?>">
            <button class="mobile-back-btn" onclick="switchTab('overview')">
                <i class="fas fa-arrow-left"></i> Back to Leagues
            </button>
            <div class="card">
                <div class="card-title">
                    <i class="fas fa-plus-circle"></i> Create a New League
                </div>
                <?php if (!LEAGUE_CREATION_ENABLED): ?>
                    <div class="empty-state" style="padding: 30px 20px;">
                        <i class="fas fa-lock" style="font-size: 36px;"></i>
                        <h3>Creation Closed</h3>
                        <p><?= LEAGUE_CREATION_DISABLED_MESSAGE ?></p>
                    </div>
                <?php else: ?>
                <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 20px;">
                    Start your own league as commissioner. You'll get a unique PIN to share with players.
                </p>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="create_league">

                    <div class="form-group">
                        <label for="league_name">League Name</label>
                        <input type="text" id="league_name" name="league_name"
                               placeholder="e.g. The Ballers League"
                               maxlength="50"
                               value="<?= htmlspecialchars($formData['league_name'] ?? '') ?>"
                               required>
                        <div class="help-text">3-50 characters</div>
                    </div>

                    <div class="form-group">
                        <label for="league_size">League Size</label>
                        <select id="league_size" name="league_size" required>
                            <option value="5" <?= (($formData['league_size'] ?? '') == '5') ? 'selected' : '' ?>>5 Participants (6 teams each)</option>
                            <option value="6" <?= (($formData['league_size'] ?? '6') == '6') ? 'selected' : '' ?>>6 Participants (5 teams each)</option>
                        </select>
                        <div class="help-text">30 NBA teams split among participants</div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="draft_date">Draft Date</label>
                            <input type="date" id="draft_date" name="draft_date"
                                   min="<?= date('Y-m-d') ?>"
                                   value="<?= htmlspecialchars($formData['draft_date'] ?? '') ?>">
                            <div class="help-text">Optional - can set later</div>
                        </div>
                        <div class="form-group">
                            <label for="draft_time">Draft Time</label>
                            <input type="time" id="draft_time" name="draft_time"
                                   value="<?= htmlspecialchars($formData['draft_time'] ?? '20:00') ?>">
                        </div>
                    </div>

                    <button type="submit" class="submit-btn btn-green">
                        <i class="fas fa-trophy"></i> Create League
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ================================================================
         PHOTO OPTIONS MODAL
         ================================================================ -->
    <div class="photo-modal-overlay" id="photoModal">
        <div class="photo-modal">
            <h3>Profile Photo</h3>
            <img src="<?= htmlspecialchars($profile_photo_url) ?>" alt=""
                 class="photo-modal-preview"
                 onerror="this.src='../public/assets/profile_photos/default.png'">
            <div class="photo-modal-buttons">
                <button type="button" class="photo-modal-btn primary" onclick="triggerPhotoUpload()">
                    <i class="fas fa-camera"></i> Upload New Photo
                </button>
                <?php if ($currentUser['profile_photo']): ?>
                    <button type="button" class="photo-modal-btn danger" onclick="deletePhoto()">
                        <i class="fas fa-trash"></i> Delete Photo
                    </button>
                <?php endif; ?>
                <button type="button" class="photo-modal-btn" onclick="closePhotoModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
            <div class="photo-modal-hint">JPEG, PNG, GIF, WebP – Max 5 MB</div>
        </div>
    </div>

    <!-- Hidden photo forms -->
    <form method="POST" enctype="multipart/form-data" id="photoUploadForm" style="display: none">
        <input type="hidden" name="action" value="upload_photo">
        <input type="file" id="profile_photo_input" name="profile_photo"
               accept="image/jpeg,image/png,image/gif,image/webp"
               onchange="previewAndUpload(this)">
    </form>
    <form method="POST" id="deletePhotoForm" style="display: none">
        <input type="hidden" name="action" value="delete_photo">
    </form>

    <!-- ================================================================
         JAVASCRIPT
         ================================================================ -->
    <script>
    // --- Tab Switching ---
    function switchTab(tabName) {
        // Update desktop tab buttons
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(btn => {
            const text = btn.textContent.toLowerCase();
            if ((tabName === 'overview' && text.includes('leagues')) ||
                (tabName === 'join' && text.includes('join')) ||
                (tabName === 'create' && text.includes('create'))) {
                btn.classList.add('active');
            }
        });

        // Switch tab content
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        document.getElementById('tab-' + tabName)?.classList.add('active');

        // Reset mobile dropdown
        const mobileSelect = document.querySelector('.mobile-action-select');
        if (mobileSelect) mobileSelect.selectedIndex = 0;

        history.replaceState(null, '', '?tab=' + tabName);
    }

    // --- Mobile Action Dropdown ---
    function handleMobileAction(select) {
        const val = select.value;
        if (val === 'join' || val === 'create') {
            switchTab(val);
        }
        // Reset to placeholder
        select.selectedIndex = 0;
    }

    // --- League Card Collapse/Expand ---
    function toggleLeague(leagueId) {
        const card = document.getElementById('league-' + leagueId);
        if (!card) return;
        card.classList.toggle('expanded');
    }

    // --- Theme ---
    function setTheme(theme) {
        document.getElementById('themeInput').value = theme;
        document.getElementById('themeForm').submit();
    }

    // --- Edit Display Name ---
    function toggleEditName() {
        const form = document.getElementById('editNameForm');
        form.classList.toggle('visible');
        if (form.classList.contains('visible')) {
            const input = form.querySelector('input[name="display_name"]');
            if (input) { input.focus(); input.select(); }
        }
    }

    // --- Photo Modal ---
    function showPhotoModal() {
        const m = document.getElementById('photoModal');
        if (m) { m.classList.add('visible'); document.body.style.overflow = 'hidden'; }
    }

    function closePhotoModal() {
        const m = document.getElementById('photoModal');
        if (m) { m.classList.remove('visible'); document.body.style.overflow = 'auto'; }
    }

    function triggerPhotoUpload() {
        document.getElementById('profile_photo_input')?.click();
    }

    function deletePhoto() {
        if (confirm('Delete your profile photo?')) {
            document.getElementById('deletePhotoForm')?.submit();
        }
    }

    function previewAndUpload(input) {
        if (input.files && input.files[0]) {
            const f = input.files[0];
            if (f.size > 5 * 1024 * 1024) {
                alert('File too large. Max 5 MB.');
                input.value = '';
                return;
            }
            if (!['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(f.type)) {
                alert('Invalid file type.');
                input.value = '';
                return;
            }
            closePhotoModal();
            document.getElementById('photoUploadForm').submit();
        }
    }

    // Close photo modal on overlay click
    document.getElementById('photoModal')?.addEventListener('click', function(e) {
        if (e.target === this) closePhotoModal();
    });

    // --- Copy PIN ---
    function copyPIN(elementId) {
        const pin = document.getElementById(elementId).textContent;
        navigator.clipboard.writeText(pin).then(() => {
            const btn = document.querySelector('#' + elementId + ' + .copy-btn');
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check" style="color: var(--accent-green);"></i>';
            setTimeout(() => { btn.innerHTML = orig; }, 1500);
        }).catch(() => {
            const ta = document.createElement('textarea');
            ta.value = pin;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        });
    }

    // Auto-uppercase PIN input
    document.getElementById('pin_code')?.addEventListener('input', function() {
        this.value = this.value.toUpperCase();
    });
    </script>

    <?php $currentPage = 'league_hub'; include '/data/www/default/nba-wins-platform/components/pill_nav.php'; ?>
</body>
</html>