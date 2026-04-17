<?php
/**
 * participant_profile.php - Participant Profile Page
 * 
 * Displays a league participant's profile including:
 *   - Profile photo, display name, total record
 *   - Drafted teams with records
 *   - League stats (total games, avg record, best team)
 *   - Rivals (most wins against, nemesis)
 *   - Last 10 games and upcoming 5 games
 *   - Own-profile editing: display name, photo upload, auto-draft toggle
 *   - Widget pinning to homepage dashboard
 * 
 * Path: /data/www/default/nba-wins-platform/profiles/participant_profile.php
 */

date_default_timezone_set('America/New_York');
session_start();

// =====================================================================
// SESSION CONTEXT
// =====================================================================
$current_league_id = isset($_SESSION['current_league_id']) ? $_SESSION['current_league_id'] : '';
$current_user_id   = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : '';

// =====================================================================
// REQUEST PARAMETERS
// =====================================================================
$league_id = isset($_GET['league_id']) ? intval($_GET['league_id']) : null;
$user_id   = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;

if (!$league_id || !$user_id) {
    die("Missing required parameters: league_id and user_id");
}

// =====================================================================
// DEPENDENCIES
// =====================================================================
require_once '../config/db_connection.php';
require_once __DIR__ . '/../config/season_config.php';
$season = getSeasonConfig();
require_once '../core/ProfilePhotoHandler.php';
require_once '../core/BadgeCalculator.php';

$photoHandler = new ProfilePhotoHandler($pdo);
$is_guest     = isset($_SESSION['is_guest']) && $_SESSION['is_guest'] === true;

$success_message = '';
$error_message   = '';


// =====================================================================
// POST ACTION HANDLING
// =====================================================================
if ($_POST && $is_guest) {
    $error_message = "Guest users cannot modify profiles.";
    $_POST = [];
}

if ($_POST) {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {

            // --- Update Display Name ---
            case 'update_display_name':
                if (isset($_SESSION['user_id'])) {
                    $new_display_name = trim($_POST['display_name']);
                    if (!empty($new_display_name) && strlen($new_display_name) <= 20) {
                        try {
                            $stmt = $pdo->prepare("UPDATE users SET display_name = ? WHERE id = ?");
                            $stmt->execute([$new_display_name, $_SESSION['user_id']]);
                            $success_message = "Display name updated!";
                        } catch (Exception $e) {
                            $error_message = "Error: " . $e->getMessage();
                        }
                    } else {
                        $error_message = "Display name must be 1-20 characters.";
                    }
                }
                break;

            // --- Upload Profile Photo ---
            case 'upload_photo':
                if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id) {
                    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                        $result = $photoHandler->uploadPhoto($_SESSION['user_id'], $_FILES['profile_photo']);
                        if ($result['success']) {
                            $success_message = $result['message'];
                        } else {
                            $error_message = $result['error'];
                        }
                    } else {
                        $error_message = "Please select a valid image file.";
                    }
                }
                break;

            // --- Delete Profile Photo ---
            case 'delete_photo':
                if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id) {
                    $result = $photoHandler->deletePhoto($_SESSION['user_id']);
                    if ($result['success']) {
                        $success_message = $result['message'];
                    } else {
                        $error_message = $result['error'];
                    }
                }
                break;

            // --- Toggle Theme ---
            case 'toggle_theme':
                if (isset($_SESSION['user_id'])) {
                    $new_theme = ($_POST['theme'] === 'classic') ? 'classic' : 'dark';
                    try {
                        $stmt = $pdo->prepare("UPDATE users SET theme_preference = ? WHERE id = ?");
                        $stmt->execute([$new_theme, $_SESSION['user_id']]);
                        $_SESSION['theme_preference'] = $new_theme;
                        $success_message = "Theme updated!";
                    } catch (Exception $e) {
                        $error_message = "Error: " . $e->getMessage();
                    }
                }
                break;

            // --- Toggle Auto-Draft ---
            case 'toggle_auto_draft':
                if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id) {
                    $auto_draft_value = isset($_POST['auto_draft_enabled']) ? 1 : 0;
                    try {
                        $stmt = $pdo->prepare("
                            UPDATE league_participants 
                            SET auto_draft_enabled = ? 
                            WHERE user_id = ? AND league_id = ?
                        ");
                        $stmt->execute([$auto_draft_value, $_SESSION['user_id'], $league_id]);
                        $success_message = "Auto-draft setting updated!";

                        // Refresh participant data after update
                        $stmt = $pdo->prepare("
                            SELECT lp.*, u.display_name, u.id AS user_id, u.profile_photo, u.theme_preference
                            FROM league_participants lp
                            JOIN users u ON lp.user_id = u.id
                            WHERE lp.user_id = ? AND lp.league_id = ?
                        ");
                        $stmt->execute([$user_id, $league_id]);
                        $participant = $stmt->fetch(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {
                        $error_message = "Error: " . $e->getMessage();
                    }
                }
                break;

            // --- Set Default League ---
            case 'set_default_league':
                if (isset($_SESSION['user_id'])) {
                    $default_league_val = $_POST['default_league_id'] ?? '';
                    try {
                        if ($default_league_val === '' || $default_league_val === 'none') {
                            // Clear default
                            $stmt = $pdo->prepare("UPDATE users SET default_league_id = NULL WHERE id = ?");
                            $stmt->execute([$_SESSION['user_id']]);
                            $success_message = "Default league cleared.";
                        } else {
                            $default_league_val = intval($default_league_val);
                            // Verify user is an active participant in this league
                            $stmt = $pdo->prepare("
                                SELECT 1 FROM league_participants 
                                WHERE user_id = ? AND league_id = ? AND status = 'active'
                            ");
                            $stmt->execute([$_SESSION['user_id'], $default_league_val]);
                            if ($stmt->fetch()) {
                                $stmt = $pdo->prepare("UPDATE users SET default_league_id = ? WHERE id = ?");
                                $stmt->execute([$default_league_val, $_SESSION['user_id']]);
                                $success_message = "Default league updated!";
                            }
                        }
                    } catch (Exception $e) {
                        $error_message = "Error: " . $e->getMessage();
                    }
                }
                break;
        }
    }
}


// ==========================================================================
// DATA QUERIES
// ==========================================================================

// ------ League Info ------
$stmt = $pdo->prepare("SELECT * FROM leagues WHERE id = ?");
$stmt->execute([$league_id]);
$pp_league = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pp_league) die("League not found");

$draft_completed = $pp_league['draft_completed'] == 1;

// ------ Participant Info ------
$stmt = $pdo->prepare("
    SELECT lp.*, u.display_name, u.id AS user_id, u.profile_photo, u.theme_preference
    FROM league_participants lp
    JOIN users u ON lp.user_id = u.id
    WHERE lp.user_id = ? AND lp.league_id = ?
");
$stmt->execute([$user_id, $league_id]);
$participant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$participant) {
    // Guest fallback: redirect to first active participant
    if ($is_guest) {
        $stmt = $pdo->prepare("
            SELECT u.id AS user_id
            FROM league_participants lp
            JOIN users u ON lp.user_id = u.id
            WHERE lp.league_id = ? AND lp.status = 'active'
            ORDER BY u.display_name ASC
            LIMIT 1
        ");
        $stmt->execute([$league_id]);
        $fallback = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fallback) {
            header("Location: ?league_id=$league_id&user_id=" . $fallback['user_id']);
            exit;
        }
    }
    die("Participant not found in this league.");
}

// ------ Own Profile Check ------
$is_own_profile = isset($_SESSION['user_id']) && ($participant['user_id'] == $_SESSION['user_id']);

// ------ User's Leagues (for default league setting) ------
$user_leagues = [];
$user_default_league_id = null;
if ($is_own_profile) {
    $stmt = $pdo->prepare("
        SELECT l.id, l.display_name
        FROM league_participants lp
        JOIN leagues l ON l.id = lp.league_id
        WHERE lp.user_id = ? AND lp.status = 'active'
        ORDER BY l.display_name ASC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user_leagues = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT default_league_id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_default_league_id = $stmt->fetchColumn() ?: null;
}

// ------ Pinned Dashboard Widgets ------
$pinned_widgets = [];
if ($is_own_profile) {
    $stmt = $pdo->prepare("
        SELECT widget_type 
        FROM user_dashboard_widgets 
        WHERE user_id = ? AND is_active = 1
    ");
    $stmt->execute([$current_user_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pinned_widgets[] = $row['widget_type'];
    }
}

// ------ Profile Photo URL ------
$profile_photo_url = $photoHandler->getPhotoUrl($participant['user_id'], $participant['profile_photo']);

// ------ Theme Preference (set session from DB if own profile) ------
if ($is_own_profile && isset($participant['theme_preference'])) {
    $_SESSION['theme_preference'] = $participant['theme_preference'];
}
$current_theme = $_SESSION['theme_preference'] ?? 'dark';

// ------ Check if draft has been completed ------
$stmt = $pdo->prepare("SELECT draft_completed, display_name FROM leagues WHERE id = ?");
$stmt->execute([$league_id]);
$league_info = $stmt->fetch(PDO::FETCH_ASSOC);
$draft_completed = $league_info ? (bool)$league_info['draft_completed'] : false;

// If draft not complete, show a pre-draft profile
if (!$draft_completed) {
    // Get all participants for the selector
    $stmt = $pdo->prepare("
        SELECT lp.id, u.display_name, u.id AS user_id
        FROM league_participants lp
        JOIN users u ON lp.user_id = u.id
        WHERE lp.league_id = ? AND lp.status = 'active'
        ORDER BY u.display_name
    ");
    $stmt->execute([$league_id]);
    $all_participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $currentLeagueId = $league_id;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="theme-color" content="<?= $current_theme === 'classic' ? '#f5f5f5' : '#0f1419' ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($participant['display_name']) ?> - NBA Wins Pool</title>
        <link rel="icon" type="image/png" href="/nba-wins-platform/public/assets/favicon/favicon.png">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            :root {
                --bg-primary: #0f1419; --bg-card: #1a2230; --bg-elevated: #243044;
                --border-color: rgba(255,255,255,0.07); --text-primary: #e6edf3;
                --text-secondary: #8b949e; --text-muted: #484f58; --accent-blue: #388bfd;
                --accent-blue-dim: rgba(56,139,253,0.12); --accent-green: #3fb950;
                --radius-md: 10px; --radius-lg: 14px;
                --shadow-card: 0 1px 3px rgba(0,0,0,0.5), 0 0 0 1px var(--border-color);
            }
            <?php if ($current_theme === 'classic'): ?>
            :root {
                --bg-primary: #f3f4f6; --bg-card: #ffffff; --bg-elevated: #f0f1f4;
                --border-color: rgba(0,0,0,0.08); --text-primary: #1a1d23;
                --text-secondary: #5a6370; --text-muted: #9ca3af; --accent-blue: #2563eb;
                --accent-blue-dim: rgba(37,99,235,0.08); --accent-green: #16a34a;
                --shadow-card: 0 1px 3px rgba(0,0,0,0.06), 0 0 0 1px rgba(0,0,0,0.04);
            }
            <?php endif; ?>
            *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
            body {
                font-family: 'Outfit', -apple-system, sans-serif;
                background: var(--bg-primary); color: var(--text-primary);
                min-height: 100vh; padding: 20px 16px 100px;
                -webkit-font-smoothing: antialiased;
            }
            .container { max-width: 640px; margin: 0 auto; }
            .profile-card {
                background: var(--bg-card); border: 1px solid var(--border-color);
                border-radius: var(--radius-lg); padding: 32px; text-align: center;
                box-shadow: var(--shadow-card); margin-bottom: 16px;
            }
            .avatar {
                width: 80px; height: 80px; border-radius: 50%; object-fit: cover;
                border: 3px solid var(--bg-elevated); margin: 0 auto 16px; display: block;
            }
            .display-name { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
            .league-name { font-size: 14px; color: var(--text-secondary); margin-bottom: 24px; }
            .predraft-msg {
                background: var(--accent-blue-dim); border: 1px solid rgba(56,139,253,0.2);
                border-radius: var(--radius-md); padding: 20px; margin-top: 8px;
            }
            .predraft-msg i { font-size: 28px; color: var(--accent-blue); display: block; margin-bottom: 10px; }
            .predraft-msg h3 { font-size: 16px; font-weight: 600; margin-bottom: 6px; }
            .predraft-msg p { font-size: 13px; color: var(--text-secondary); }
            .participant-select {
                width: 100%; padding: 10px 14px; border: 1px solid var(--border-color);
                border-radius: var(--radius-md); background: var(--bg-card); color: var(--text-primary);
                font-family: 'Outfit', sans-serif; font-size: 14px; cursor: pointer;
                appearance: none; box-shadow: var(--shadow-card);
            }
            .back-link {
                display: inline-flex; align-items: center; gap: 6px;
                color: var(--accent-blue); text-decoration: none; font-size: 13px;
                font-weight: 500; margin-bottom: 16px;
            }
            .back-link:hover { text-decoration: underline; }
            .avatar-wrap { position: relative; display: inline-block; margin: 0 auto 16px; }
            .avatar-wrap .avatar { display: block; cursor: default; }
            .avatar-edit {
                position: absolute; bottom: 0; right: 0; width: 26px; height: 26px;
                border-radius: 50%; background: var(--accent-blue); color: #fff;
                border: 2px solid var(--bg-card); display: none; align-items: center;
                justify-content: center; font-size: 10px; cursor: pointer;
            }
            .avatar-wrap:hover .avatar-edit { display: flex; }
            .display-name-row { display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 4px; }
            .edit-icon { color: var(--text-muted); font-size: 13px; cursor: pointer; }
            .edit-icon:hover { color: var(--accent-blue); }
            .edit-form {
                display: none; margin-top: 14px; padding-top: 14px;
                border-top: 1px solid var(--border-color); gap: 8px; align-items: center;
            }
            .edit-form.visible { display: flex; }
            .edit-form input {
                flex: 1; padding: 8px 12px; border: 1px solid var(--border-color);
                border-radius: 6px; font-family: 'Outfit', sans-serif; font-size: 14px;
                background: var(--bg-elevated); color: var(--text-primary); outline: none;
            }
            .edit-form input:focus { border-color: var(--accent-blue); }
            .edit-form button {
                padding: 8px 14px; border: none; border-radius: 6px;
                font-family: 'Outfit', sans-serif; font-size: 13px; font-weight: 600; cursor: pointer;
            }
            .btn-save { background: var(--accent-blue); color: #fff; }
            .btn-cancel { background: var(--bg-elevated); color: var(--text-secondary); border: 1px solid var(--border-color) !important; }
            .flash-msg {
                padding: 10px 16px; border-radius: var(--radius-md); margin-bottom: 14px;
                font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 8px;
            }
            .flash-msg.success { background: rgba(63,185,80,0.12); color: var(--accent-green); border: 1px solid rgba(63,185,80,0.2); }
            .flash-msg.error { background: rgba(248,81,73,0.1); color: #f85149; border: 1px solid rgba(248,81,73,0.2); }
            .photo-overlay {
                position: fixed; top:0; left:0; right:0; bottom:0;
                background: rgba(0,0,0,0.7); z-index: 2000; display: none;
                align-items: center; justify-content: center;
            }
            .photo-overlay.visible { display: flex; }
            .photo-modal {
                background: var(--bg-card); border: 1px solid var(--border-color);
                border-radius: var(--radius-lg); padding: 28px; max-width: 340px; width: 90%;
                text-align: center; box-shadow: 0 10px 40px rgba(0,0,0,0.5);
            }
            .photo-modal h3 { margin-bottom: 16px; font-size: 17px; }
            .photo-modal-preview {
                width: 80px; height: 80px; border-radius: 50%; object-fit: cover;
                margin: 0 auto 16px; display: block; border: 3px solid var(--bg-elevated);
            }
            .photo-modal-btn {
                display: flex; align-items: center; justify-content: center; gap: 8px;
                width: 100%; padding: 10px; border: 1px solid var(--border-color);
                border-radius: var(--radius-md); background: var(--bg-elevated); color: var(--text-primary);
                cursor: pointer; font-family: 'Outfit', sans-serif; font-size: 13px; font-weight: 500;
                margin-bottom: 8px; transition: all 0.15s;
            }
            .photo-modal-btn:hover { background: var(--bg-card); }
            .photo-modal-btn.primary { background: var(--accent-blue); color: #fff; border-color: var(--accent-blue); }
            .photo-modal-btn.danger { background: #f85149; color: #fff; border-color: #f85149; }
            .photo-hint { font-size: 11px; color: var(--text-muted); margin-top: 10px; }
        </style>
    </head>
    <body>
        <div class="container">
            <a href="/nba-wins-platform/auth/league_hub.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to League Hub
            </a>

            <?php if ($success_message): ?>
                <div class="flash-msg success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?></div>
            <?php elseif ($error_message): ?>
                <div class="flash-msg error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <div class="profile-card">
                <?php if ($is_own_profile): ?>
                <div class="avatar-wrap">
                    <img src="<?= htmlspecialchars($profile_photo_url) ?>" alt="" class="avatar"
                         onerror="this.src='/nba-wins-platform/public/assets/profile_photos/default.png'"
                         onclick="document.getElementById('photoOverlay').classList.add('visible')">
                    <div class="avatar-edit" onclick="document.getElementById('photoOverlay').classList.add('visible')">
                        <i class="fas fa-camera"></i>
                    </div>
                </div>
                <?php else: ?>
                <img src="<?= htmlspecialchars($profile_photo_url) ?>" alt="" class="avatar"
                     onerror="this.src='/nba-wins-platform/public/assets/profile_photos/default.png'">
                <?php endif; ?>

                <div class="display-name-row">
                    <span class="display-name"><?= htmlspecialchars($participant['display_name']) ?></span>
                    <?php if ($is_own_profile): ?>
                    <i class="fas fa-pen edit-icon" onclick="var f=document.getElementById('editNameForm');f.classList.toggle('visible');if(f.classList.contains('visible')){f.querySelector('input').focus();}"></i>
                    <?php endif; ?>
                </div>
                <div class="league-name"><?= htmlspecialchars($league_info['display_name'] ?? 'League') ?></div>

                <?php if ($is_own_profile): ?>
                <form method="POST" class="edit-form" id="editNameForm">
                    <input type="hidden" name="action" value="update_display_name">
                    <input type="text" name="display_name" value="<?= htmlspecialchars($participant['display_name']) ?>" maxlength="20" required>
                    <button type="submit" class="btn-save">Save</button>
                    <button type="button" class="btn-cancel" onclick="document.getElementById('editNameForm').classList.remove('visible')">Cancel</button>
                </form>
                <?php endif; ?>

                <div class="predraft-msg">
                    <h3>Waiting for Draft</h3>
                    <p>Team stats and records will appear here once the draft is complete and the season is underway.</p>
                </div>
            </div>

            <?php if (count($all_participants) > 1): ?>
            <select class="participant-select" onchange="window.location.href='?league_id=<?= $league_id ?>&user_id=' + this.value">
                <?php foreach ($all_participants as $p): ?>
                <option value="<?= $p['user_id'] ?>" <?= $p['user_id'] == $user_id ? 'selected' : '' ?>>
                    <?= htmlspecialchars($p['display_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
        </div>

        <?php if ($is_own_profile): ?>
        <!-- Photo Modal -->
        <div class="photo-overlay" id="photoOverlay" onclick="if(event.target===this)this.classList.remove('visible')">
            <div class="photo-modal">
                <h3>Profile Photo</h3>
                <img src="<?= htmlspecialchars($profile_photo_url) ?>" alt="" class="photo-modal-preview"
                     onerror="this.src='/nba-wins-platform/public/assets/profile_photos/default.png'">
                <button class="photo-modal-btn primary" onclick="document.getElementById('photoInput').click()">
                    <i class="fas fa-camera"></i> Upload New Photo
                </button>
                <?php if ($participant['profile_photo']): ?>
                <button class="photo-modal-btn danger" onclick="if(confirm('Delete photo?'))document.getElementById('deletePhotoForm').submit()">
                    <i class="fas fa-trash"></i> Delete Photo
                </button>
                <?php endif; ?>
                <button class="photo-modal-btn" onclick="document.getElementById('photoOverlay').classList.remove('visible')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <div class="photo-hint">JPEG, PNG, GIF, WebP — Max 5 MB</div>
            </div>
        </div>
        <form method="POST" enctype="multipart/form-data" id="photoUploadForm" style="display:none">
            <input type="hidden" name="action" value="upload_photo">
            <input type="file" id="photoInput" name="profile_photo" accept="image/jpeg,image/png,image/gif,image/webp"
                   onchange="if(this.files[0]&&this.files[0].size>5242880){alert('Max 5MB');this.value='';return;}document.getElementById('photoUploadForm').submit()">
        </form>
        <form method="POST" id="deletePhotoForm" style="display:none">
            <input type="hidden" name="action" value="delete_photo">
        </form>
        <?php endif; ?>

        <?php $currentPage = 'profile'; include '/data/www/default/nba-wins-platform/components/pill_nav.php'; ?>
    </body>
    </html>
    <?php
    exit; // Stop here — don't run team/rival/badge queries
}

// ------ Drafted Teams + Records ------
$stmt = $pdo->prepare("
    SELECT 
        dp.*,
        nt.name AS team_name,
        nt.abbreviation,
        nt.logo_filename AS logo,
        COALESCE(s.win, 0) AS wins,
        COALESCE(s.loss, 0) AS losses,
        (COALESCE(s.win, 0) + COALESCE(s.loss, 0)) AS games_played,
        CASE 
            WHEN (COALESCE(s.win, 0) + COALESCE(s.loss, 0)) > 0 
            THEN ROUND((COALESCE(s.win, 0) / (COALESCE(s.win, 0) + COALESCE(s.loss, 0))) * 100, 1)
            ELSE 0 
        END AS win_percentage,
        s.logo AS standings_logo
    FROM draft_picks dp
    JOIN league_participants lp ON dp.league_participant_id = lp.id
    JOIN nba_teams nt ON dp.team_id = nt.id
    LEFT JOIN {$season['standings_table']} s ON nt.name = s.name
    WHERE dp.league_participant_id = ? AND lp.league_id = ?
    ORDER BY dp.pick_number ASC
");
$stmt->execute([$participant['id'], $league_id]);
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_wins   = 0;
$total_losses = 0;
foreach ($teams as $team) {
    $total_wins   += $team['wins'];
    $total_losses += $team['losses'];
}

// ------ All League Participants (for dropdown selector) ------
$stmt = $pdo->prepare("
    SELECT lp.id, u.display_name, lp.participant_name, u.id AS user_id
    FROM league_participants lp
    JOIN users u ON lp.user_id = u.id
    WHERE lp.league_id = ?
    ORDER BY u.display_name
");
$stmt->execute([$league_id]);
$all_participants = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ==========================================================================
// RIVALS QUERIES
// ==========================================================================

// ------ Biggest Rival (most wins against) ------
$stmt = $pdo->prepare("
    SELECT 
        opponent_user.id AS opponent_user_id,
        opponent_user.display_name AS opponent_name,
        SUM(CASE 
            WHEN ((g.home_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers') 
                   OR g.home_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                  AND g.home_points > g.away_points) THEN 1
            WHEN ((g.away_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers') 
                   OR g.away_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                  AND g.away_points > g.home_points) THEN 1
            ELSE 0 
        END) AS wins_against_opponent,
        SUM(CASE 
            WHEN ((g.home_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers') 
                   OR g.home_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                  AND g.home_points < g.away_points) THEN 1
            WHEN ((g.away_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers') 
                   OR g.away_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                  AND g.away_points < g.home_points) THEN 1
            ELSE 0 
        END) AS losses_against_opponent
    FROM league_participant_teams my_team
    JOIN league_participants my_participant 
        ON my_team.league_participant_id = my_participant.id
    JOIN games g 
        ON (g.home_team IN (
                REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            ) 
            OR g.away_team IN (
                REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            ))
        AND g.status_long IN ('Final', 'Finished')
        AND DATE(g.start_time) >= '{$season['season_start_date']}'
    JOIN league_participant_teams opponent_team
        ON ((g.home_team IN (
                REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            )
            AND g.away_team IN (
                REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            ))
            OR (g.away_team IN (
                REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            )
            AND g.home_team IN (
                REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            )))
    JOIN league_participants opponent_participant
        ON opponent_team.league_participant_id = opponent_participant.id
        AND opponent_participant.league_id = my_participant.league_id
        AND opponent_participant.id != my_participant.id
    JOIN users opponent_user
        ON opponent_participant.user_id = opponent_user.id
    WHERE my_participant.id = ?
    GROUP BY opponent_user.id, opponent_user.display_name
    HAVING wins_against_opponent > 0
    ORDER BY wins_against_opponent DESC, losses_against_opponent ASC
    LIMIT 1
");
$stmt->execute([$participant['id']]);
$biggest_rival = $stmt->fetch(PDO::FETCH_ASSOC);

// ------ Nemesis (most losses against) ------
$stmt = $pdo->prepare("
    SELECT 
        opponent_user.id AS opponent_user_id,
        opponent_user.display_name AS opponent_name,
        SUM(CASE 
            WHEN ((g.home_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers') 
                   OR g.home_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                  AND g.home_points < g.away_points) THEN 1
            WHEN ((g.away_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers') 
                   OR g.away_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                  AND g.away_points < g.home_points) THEN 1
            ELSE 0 
        END) AS losses_against_opponent,
        SUM(CASE 
            WHEN ((g.home_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers') 
                   OR g.home_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                  AND g.home_points > g.away_points) THEN 1
            WHEN ((g.away_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers') 
                   OR g.away_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                  AND g.away_points > g.home_points) THEN 1
            ELSE 0 
        END) AS wins_against_opponent
    FROM league_participant_teams my_team
    JOIN league_participants my_participant 
        ON my_team.league_participant_id = my_participant.id
    JOIN games g 
        ON (g.home_team IN (
                REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            ) 
            OR g.away_team IN (
                REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            ))
        AND g.status_long IN ('Final', 'Finished')
        AND DATE(g.start_time) >= '{$season['season_start_date']}'
    JOIN league_participant_teams opponent_team
        ON ((g.home_team IN (
                REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            )
            AND g.away_team IN (
                REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            ))
            OR (g.away_team IN (
                REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            )
            AND g.home_team IN (
                REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
            )))
    JOIN league_participants opponent_participant
        ON opponent_team.league_participant_id = opponent_participant.id
        AND opponent_participant.league_id = my_participant.league_id
        AND opponent_participant.id != my_participant.id
    JOIN users opponent_user
        ON opponent_participant.user_id = opponent_user.id
    WHERE my_participant.id = ?
    GROUP BY opponent_user.id, opponent_user.display_name
    HAVING losses_against_opponent > 0
    ORDER BY losses_against_opponent DESC, wins_against_opponent ASC
    LIMIT 1
");
$stmt->execute([$participant['id']]);
$nemesis = $stmt->fetch(PDO::FETCH_ASSOC);


// ==========================================================================
// LAST 10 GAMES
// ==========================================================================
$lastGames = [];
try {
    $teamNamesQuery = $pdo->prepare("
        SELECT nt.name 
        FROM draft_picks dp 
        JOIN nba_teams nt ON dp.team_id = nt.id 
        WHERE dp.league_participant_id = ?
    ");
    $teamNamesQuery->execute([$participant['id']]);
    $participantTeams = $teamNamesQuery->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($participantTeams)) {
        $placeholders = str_repeat('?,', count($participantTeams) - 1) . '?';

        $stmt = $pdo->prepare("
            SELECT DISTINCT
                g.date AS game_date,
                g.start_time,
                g.home_team,
                g.away_team,
                g.home_team_code,
                g.away_team_code,
                g.home_points,
                g.away_points,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN 'home'
                    WHEN g.away_team IN ($placeholders) THEN 'away'
                END AS team_location,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN g.home_team
                    WHEN g.away_team IN ($placeholders) THEN g.away_team
                END AS my_team,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN g.away_team
                    WHEN g.away_team IN ($placeholders) THEN g.home_team
                END AS opponent,
                CASE 
                    WHEN (g.home_team IN ($placeholders) AND g.home_points > g.away_points)
                      OR (g.away_team IN ($placeholders) AND g.away_points > g.home_points) THEN 'W'
                    WHEN g.home_points IS NOT NULL THEN 'L'
                    ELSE NULL 
                END AS result
            FROM games g
            WHERE (g.home_team IN ($placeholders) OR g.away_team IN ($placeholders))
              AND g.status_long IN ('Final', 'Finished')
              AND g.date >= '{$season['season_start_date']}'
            ORDER BY g.date DESC, g.start_time DESC
            LIMIT 10
        ");

        // 10 placeholder groups in the query
        $params = array_merge(
            $participantTeams, $participantTeams,  // team_location
            $participantTeams, $participantTeams,  // my_team
            $participantTeams, $participantTeams,  // opponent
            $participantTeams, $participantTeams,  // result
            $participantTeams, $participantTeams   // WHERE clause
        );
        $stmt->execute($params);
        $lastGames = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Attach opponent owner info
        foreach ($lastGames as &$game) {
            $ownerStmt = $pdo->prepare("
                SELECT u.display_name 
                FROM draft_picks dp
                JOIN nba_teams nt ON dp.team_id = nt.id
                JOIN league_participants lp ON dp.league_participant_id = lp.id
                JOIN users u ON lp.user_id = u.id
                WHERE nt.name = ? AND lp.league_id = ?
                LIMIT 1
            ");
            $ownerStmt->execute([$game['opponent'], $league_id]);
            $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
            $game['opponent_owner'] = $owner ? $owner['display_name'] : null;
        }
        unset($game);
    }
} catch (Exception $e) {
    error_log("Error fetching last games: " . $e->getMessage());
}

// ==========================================================================
// PARTICIPANT WIN/LOSS STREAK
// ==========================================================================
$participantWinStreak = 0;
$participantLossStreak = 0;
try {
    if (!empty($participantTeams)) {
        $placeholders = str_repeat('?,', count($participantTeams) - 1) . '?';

        $streakStmt = $pdo->prepare("
            SELECT 
                CASE 
                    WHEN (g.home_team IN ($placeholders) AND g.away_team IN ($placeholders)) THEN 'W'
                    WHEN (g.home_team IN ($placeholders) AND g.home_points > g.away_points) THEN 'W'
                    WHEN (g.away_team IN ($placeholders) AND g.away_points > g.home_points) THEN 'W'
                    ELSE 'L'
                END AS result
            FROM games g
            WHERE (g.home_team IN ($placeholders) OR g.away_team IN ($placeholders))
              AND g.status_long IN ('Final', 'Finished')
              AND g.date >= '{$season['season_start_date']}'
            ORDER BY g.date DESC, g.start_time DESC
        ");

        $params = array_merge(
            $participantTeams, $participantTeams,  // both-teams check
            $participantTeams,                     // home win check
            $participantTeams,                     // away win check
            $participantTeams, $participantTeams   // WHERE clause
        );
        $streakStmt->execute($params);
        $streakResults = $streakStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($streakResults)) {
            $streakType = $streakResults[0]['result'];
            $streak = 0;
            foreach ($streakResults as $game) {
                if ($game['result'] === $streakType) {
                    $streak++;
                } else {
                    break;
                }
            }
            if ($streakType === 'W') {
                $participantWinStreak = $streak;
            } else {
                $participantLossStreak = $streak;
            }
        }
    }
} catch (Exception $e) {
    error_log("Error fetching participant streak: " . $e->getMessage());
}


// ==========================================================================
// UPCOMING 5 GAMES
// ==========================================================================
$upcomingGames = [];
try {
    if (!empty($participantTeams)) {
        $placeholders = str_repeat('?,', count($participantTeams) - 1) . '?';

        $stmt = $pdo->prepare("
            SELECT DISTINCT
                g.date AS game_date,
                g.home_team,
                g.away_team,
                g.home_team_code,
                g.away_team_code,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN 'home'
                    WHEN g.away_team IN ($placeholders) THEN 'away'
                END AS team_location,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN g.home_team
                    WHEN g.away_team IN ($placeholders) THEN g.away_team
                END AS my_team,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN g.away_team
                    WHEN g.away_team IN ($placeholders) THEN g.home_team
                END AS opponent
            FROM games g
            WHERE (g.home_team IN ($placeholders) OR g.away_team IN ($placeholders))
              AND g.status_long = 'Scheduled'
              AND g.date >= '{$season['season_start_date']}'
            ORDER BY g.date ASC
            LIMIT 5
        ");

        // 8 placeholder groups in the query
        $params = array_merge(
            $participantTeams, $participantTeams,  // team_location
            $participantTeams, $participantTeams,  // my_team
            $participantTeams, $participantTeams,  // opponent
            $participantTeams, $participantTeams   // WHERE clause
        );
        $stmt->execute($params);
        $upcomingGames = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Attach opponent owner info
        foreach ($upcomingGames as &$game) {
            $ownerStmt = $pdo->prepare("
                SELECT u.display_name 
                FROM draft_picks dp
                JOIN nba_teams nt ON dp.team_id = nt.id
                JOIN league_participants lp ON dp.league_participant_id = lp.id
                JOIN users u ON lp.user_id = u.id
                WHERE nt.name = ? AND lp.league_id = ?
                LIMIT 1
            ");
            $ownerStmt->execute([$game['opponent'], $league_id]);
            $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
            $game['opponent_owner'] = $owner ? $owner['display_name'] : null;
        }
        unset($game);
    }
} catch (Exception $e) {
    error_log("Error fetching upcoming games: " . $e->getMessage());
}


// ==========================================================================
// HELPER FUNCTIONS
// ==========================================================================

/**
 * Get team logo path from team name
 */
function getTeamLogo($teamName) {
    $logoMap = [
        'Atlanta Hawks'          => 'atlanta_hawks.png',
        'Boston Celtics'         => 'boston_celtics.png',
        'Brooklyn Nets'          => 'brooklyn_nets.png',
        'Charlotte Hornets'      => 'charlotte_hornets.png',
        'Chicago Bulls'          => 'chicago_bulls.png',
        'Cleveland Cavaliers'    => 'cleveland_cavaliers.png',
        'Dallas Mavericks'       => 'dallas_mavericks.png',
        'Denver Nuggets'         => 'denver_nuggets.png',
        'Detroit Pistons'        => 'detroit_pistons.png',
        'Golden State Warriors'  => 'golden_state_warriors.png',
        'Houston Rockets'        => 'houston_rockets.png',
        'Indiana Pacers'         => 'indiana_pacers.png',
        'LA Clippers'            => 'la_clippers.png',
        'Los Angeles Clippers'   => 'la_clippers.png',
        'Los Angeles Lakers'     => 'los_angeles_lakers.png',
        'Memphis Grizzlies'      => 'memphis_grizzlies.png',
        'Miami Heat'             => 'miami_heat.png',
        'Milwaukee Bucks'        => 'milwaukee_bucks.png',
        'Minnesota Timberwolves' => 'minnesota_timberwolves.png',
        'New Orleans Pelicans'   => 'new_orleans_pelicans.png',
        'New York Knicks'        => 'new_york_knicks.png',
        'Oklahoma City Thunder'  => 'oklahoma_city_thunder.png',
        'Orlando Magic'          => 'orlando_magic.png',
        'Philadelphia 76ers'     => 'philadelphia_76ers.png',
        'Phoenix Suns'           => 'phoenix_suns.png',
        'Portland Trail Blazers' => 'portland_trail_blazers.png',
        'Sacramento Kings'       => 'sacramento_kings.png',
        'San Antonio Spurs'      => 'san_antonio_spurs.png',
        'Toronto Raptors'        => 'toronto_raptors.png',
        'Utah Jazz'              => 'utah_jazz.png',
        'Washington Wizards'     => 'washington_wizards.png'
    ];

    if (isset($logoMap[$teamName])) {
        return '../public/assets/team_logos/' . $logoMap[$teamName];
    }

    return '../public/assets/team_logos/' . strtolower(str_replace(' ', '_', $teamName)) . '.png';
}

$currentLeagueId = $league_id;

// ==========================================================================
// BADGES
// ==========================================================================
$badgeCalc   = new BadgeCalculator($pdo, $season);
$badgeCalc->calculateAndStoreBadges(
    $participant['user_id'],
    $league_id,
    $participant['id'],
    $participant['profile_photo'],
    $total_wins,
    $total_losses
);
$earnedBadges  = $badgeCalc->getBadges($participant['user_id'], $league_id);
$badgeProgress = $badgeCalc->getProgress($participant['id'], $league_id, $earnedBadges, $total_wins, $total_losses);
$badgeDefs     = BadgeCalculator::BADGE_DEFINITIONS;

// ---- Drafted the Champion badge (image-based) ----
$badgeDefs['drafted_champion'] = [
    'name'       => 'Drafted the Champ',
    'desc'       => 'One of your drafted teams won the NBA Championship',
    'icon'       => 'img:nba_champ.png',
    'color'      => '#FFD700',
    'glow'       => 'rgba(255,215,0,0.5)',
    'repeatable' => true
];

$badgeDefs['drafted_cup'] = [
    'name'       => 'Drafted the Cup Winner',
    'desc'       => 'One of your drafted teams won the NBA Cup',
    'icon'       => 'img:nba_cup.png',
    'color'      => '#C0C0C0',
    'glow'       => 'rgba(192,192,192,0.5)',
    'repeatable' => true
];

// ---- Past Champions (Hall of Fame / Trophy Case) ----
$pastChampionships = [];
$leagueChampCount = 0;
try {
    $stmt = $pdo->prepare("
        SELECT * FROM past_champions 
        WHERE user_id = ? 
        ORDER BY season DESC
    ");
    $stmt->execute([$user_id]);
    $pastChampionships = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // League-specific count for trophy ribbon on header
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM past_champions WHERE user_id = ? AND league_id = ?");
    $stmt->execute([$user_id, $league_id]);
    $leagueChampCount = (int)$stmt->fetch()['cnt'];
} catch (Exception $e) {
    error_log("Past champions query: " . $e->getMessage());
}

// ---- Drafted the NBA Champion Badge (standalone table) ----
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt FROM nba_champ_drafters
        WHERE user_id = ? AND league_id = ?
    ");
    $stmt->execute([$user_id, $league_id]);
    $champBadgeCount = (int)$stmt->fetch()['cnt'];

    if ($champBadgeCount > 0) {
        $stmt = $pdo->prepare("
            SELECT season, champ_team, awarded_at
            FROM nba_champ_drafters
            WHERE user_id = ? AND league_id = ?
            ORDER BY season DESC LIMIT 1
        ");
        $stmt->execute([$user_id, $league_id]);
        $latestChampBadge = $stmt->fetch();

        $earnedBadges['drafted_champion'] = [
            'earned_at'    => $latestChampBadge['awarded_at'],
            'times_earned' => $champBadgeCount,
            'metadata'     => [
                'achieved_date' => $latestChampBadge['awarded_at'],
                'team'          => $latestChampBadge['champ_team'],
                'season'        => $latestChampBadge['season']
            ]
        ];
    }
} catch (Exception $e) {
    error_log("NBA champ drafters query: " . $e->getMessage());
}

// ---- NBA Cup Winners Badge ----
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt FROM nba_cup_winners
        WHERE user_id = ? AND league_id = ?
    ");
    $stmt->execute([$user_id, $league_id]);
    $cupBadgeCount = (int)$stmt->fetch()['cnt'];

    if ($cupBadgeCount > 0) {
        $stmt = $pdo->prepare("
            SELECT season, cup_champion_team, awarded_at
            FROM nba_cup_winners
            WHERE user_id = ? AND league_id = ?
            ORDER BY season DESC LIMIT 1
        ");
        $stmt->execute([$user_id, $league_id]);
        $latestCup = $stmt->fetch();

        $earnedBadges['drafted_cup'] = [
            'earned_at'    => $latestCup['awarded_at'],
            'times_earned' => $cupBadgeCount,
            'metadata'     => [
                'achieved_date' => $latestCup['awarded_at'],
                'team'          => $latestCup['cup_champion_team'],
                'season'        => $latestCup['season']
            ]
        ];
    }
} catch (Exception $e) {
    error_log("NBA Cup winners query: " . $e->getMessage());
}

// Precompute the single next unearned badge per streak direction
// Only this badge will show the active progress bar
// Determine which streak badge to show the progress bar on based on current streak VALUE
// (e.g. streak of 3 → Heating Up, streak of 7 → On Fire, streak of 13 → Unstoppable)
// This is independent of earned status — show progress toward whatever threshold you're approaching
$_curWinStreak  = $badgeProgress['win_streak_5']['current']  ?? 0;
$_curLossStreak = $badgeProgress['loss_streak_5']['current'] ?? 0;
$_curBully      = $badgeProgress['bully_5']['current']       ?? 0;

$winStreakTarget = null;
if ($_curWinStreak >= 10)      $winStreakTarget = 'win_streak_15';
elseif ($_curWinStreak >= 5)   $winStreakTarget = 'win_streak_10';
elseif ($_curWinStreak >= 1)   $winStreakTarget = 'win_streak_5';

$lossStreakTarget = null;
if ($_curLossStreak >= 10)     $lossStreakTarget = 'loss_streak_15';
elseif ($_curLossStreak >= 5)  $lossStreakTarget = 'loss_streak_10';
elseif ($_curLossStreak >= 1)  $lossStreakTarget = 'loss_streak_5';

$bullyStreakTarget = null;
if ($_curBully >= 10)          $bullyStreakTarget = 'bully_15';
elseif ($_curBully >= 5)       $bullyStreakTarget = 'bully_10';
elseif ($_curBully >= 1)       $bullyStreakTarget = 'bully_5';

// Group badges by category for display
$badgeCategories = [
    'streaks'     => ['label' => 'Streaks',       'keys' => ['win_streak_5','win_streak_10','win_streak_15','loss_streak_5','loss_streak_10','loss_streak_15']],
    'weekly'      => ['label' => 'Weekly',         'keys' => ['week_dominator','perfect_week']],
    'milestones'  => ['label' => 'Milestones',     'keys' => ['wins_100','wins_200','wins_300','weeks_lead_10','weeks_lead_15','weeks_lead_20']],
    'performance' => ['label' => 'Performance',    'keys' => ['elite_roster','hot_hand','comeback_20']],
    'rivals'      => ['label' => 'Rivals',         'keys' => ['bully_5','bully_10','bully_15','rivalmaster']],
    'draft'       => ['label' => 'Draft & Teams',  'keys' => ['sleeper_pick','clean_sweep','drafted_champion','drafted_cup']],
    'profile'     => ['label' => 'Profile',        'keys' => ['loyal_fan']],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="theme-color" content="<?= ($_SESSION['theme_preference'] ?? 'dark') === 'classic' ? '#f5f5f5' : '#121a23' ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($participant['display_name']) ?>'s Profile</title>
    <link rel="apple-touch-icon" type="image/png" href="../public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="../public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
/* ==========================================================================
   CSS VARIABLES
   ========================================================================== */
:root {
    --bg-primary: #151d28;
    --bg-secondary: #1a222c;
    --bg-card: #202a38;
    --bg-card-hover: #273140;
    --bg-elevated: #2a3446;
    --border-color: rgba(255, 255, 255, 0.08);
    --text-primary: #e6edf3;
    --text-secondary: #8b949e;
    --text-muted: #545d68;
    --accent-blue: #388bfd;
    --accent-blue-dim: rgba(56, 139, 253, 0.15);
    --accent-green: #3fb950;
    --accent-red: #f85149;
    --accent-orange: #d29922;
    --radius-md: 10px;
    --radius-lg: 14px;
    --shadow-card: 0 1px 3px rgba(0, 0, 0, 0.4), 0 0 0 1px var(--border-color);
    --transition-fast: 0.15s ease;
}

<?php if (($_SESSION['theme_preference'] ?? 'dark') === 'classic'): ?>
:root {
    --bg-primary: #f5f5f5;
    --bg-secondary: rgba(245, 245, 245, 0.95);
    --bg-card: #ffffff;
    --bg-card-hover: #f8f9fa;
    --bg-elevated: #f0f0f2;
    --border-color: #e0e0e0;
    --border-subtle: rgba(0, 0, 0, 0.06);
    --text-primary: #333333;
    --text-secondary: #666666;
    --text-muted: #999999;
    --accent-blue: #0066ff;
    --accent-blue-dim: rgba(0, 102, 255, 0.08);
    --accent-green: #28a745;
    --accent-red: #dc3545;
    --accent-orange: #d4a017;
    --shadow-card: 0 1px 4px rgba(0, 0, 0, 0.08), 0 0 0 1px rgba(0, 0, 0, 0.04);
}
body {
    background-image: url('../public/assets/background/geometric_white.png');
    background-repeat: repeat;
    background-attachment: fixed;
}
.header-controls .participant-select {
    background-color: rgba(255, 255, 255, 0.6);
    border-color: rgba(0, 0, 0, 0.12);
    color: #333;
}
.header-controls .participant-select option { background: #fff; color: #333; }
.header-controls .gear-btn {
    background: rgba(255, 255, 255, 0.6);
    border-color: rgba(0, 0, 0, 0.12);
    color: #666;
}
<?php endif; ?>

/* ==========================================================================
   BASE / RESET
   ========================================================================== */
* { margin: 0; padding: 0; box-sizing: border-box; }
html { background: var(--bg-primary); }
body {
    font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
    line-height: 1.5;
    color: var(--text-primary);
    background: var(--bg-primary);
    background-image: radial-gradient(ellipse at 50% 0%, rgba(56, 139, 253, 0.04) 0%, transparent 60%);
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
}

/* ==========================================================================
   LAYOUT
   ========================================================================== */
.app-container { max-width: 1000px; margin: 0 auto; padding: 0 12px 2rem; }

/* Desktop two-column layout */
@media (min-width: 1100px) {
    .app-container { max-width: 1340px; }
    .pp-two-col {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
        align-items: start;
    }
    .pp-col-right {
        position: sticky;
        top: 12px;
        padding-top: 14px;
        max-height: calc(100vh - 24px);
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: var(--bg-elevated) transparent;
    }
    .pp-col-right::-webkit-scrollbar { width: 5px; }
    .pp-col-right::-webkit-scrollbar-track { background: transparent; }
    .pp-col-right::-webkit-scrollbar-thumb {
        background: var(--bg-elevated);
        border-radius: 4px;
    }
    .pp-col-right::-webkit-scrollbar-thumb:hover {
        background: var(--text-muted);
    }
    /* Stats-grid single column when inside two-col (not enough width for 2) */
    .pp-col-left .stats-grid {
        grid-template-columns: 1fr;
    }
    /* Remove double-spacing from stats-card margin + grid gap */
    .pp-col-left .stats-card,
    .pp-col-right .stats-card {
        margin-bottom: 0;
    }
    /* Right column content spacing */
    .pp-col-right .stats-card + .stats-card {
        margin-top: 14px;
    }
}

.app-header {
    display: flex; align-items: center; justify-content: center;
    gap: 10px; padding: 16px 16px 12px; position: relative;
}
.app-header-logo { width: 36px; height: 36px; }
.app-header-title { font-size: 1.35rem; font-weight: 700; letter-spacing: -0.02em; }

.nav-toggle-btn {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    width: 38px; height: 38px;
    display: flex; align-items: center; justify-content: center;
    background: var(--bg-card); border: 1px solid var(--border-color);
    border-radius: var(--radius-md); color: var(--text-secondary);
    font-size: 16px; cursor: pointer; transition: all var(--transition-fast);
}
.nav-toggle-btn:hover {
    color: var(--text-primary);
    border-color: rgba(56, 139, 253, 0.3);
    background: var(--accent-blue-dim);
}

/* ==========================================================================
   ALERTS
   ========================================================================== */
.alert {
    padding: 10px 14px; border-radius: var(--radius-md);
    margin-bottom: 12px; font-size: 14px; font-weight: 500;
}
.alert-success {
    background: rgba(63, 185, 80, 0.15);
    color: var(--accent-green);
    border: 1px solid rgba(63, 185, 80, 0.2);
}
.alert-error {
    background: rgba(248, 81, 73, 0.15);
    color: var(--accent-red);
    border: 1px solid rgba(248, 81, 73, 0.2);
}

/* ==========================================================================
   PARTICIPANT SELECTOR
   ========================================================================== */
.participant-select {
    width: 100%; max-width: 250px;
    padding: 8px 30px 8px 12px;
    font-family: 'Outfit', sans-serif; font-size: 14px; font-weight: 500;
    background: var(--bg-card); color: var(--text-primary);
    border: 1px solid var(--border-color); border-radius: var(--radius-md);
    cursor: pointer; appearance: none; -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238b949e' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    margin-bottom: 14px; transition: all var(--transition-fast);
}
.participant-select:hover { border-color: rgba(56, 139, 253, 0.3); }
.participant-select option { background: var(--bg-card); color: var(--text-primary); }

/* ==========================================================================
   PROFILE HEADER
   ========================================================================== */
.profile-header {
    background: var(--bg-card); padding: 2rem;
    padding-top: 3rem;
    color: var(--text-primary); text-align: center;
    border-radius: var(--radius-lg); margin-bottom: 14px; margin-top: 14px;
    position: relative; overflow: visible; min-height: 180px; z-index: 10;
    display: flex; align-items: center; justify-content: center;
    box-shadow: var(--shadow-card);
}
.logo-background {
    position: absolute; top: 0; left: 0; right: 0; bottom: 0;
    display: flex; flex-wrap: wrap; justify-content: space-around;
    align-items: center; opacity: 0.08;
    overflow: hidden; border-radius: var(--radius-lg);
}
.header-logo { width: 90px; height: 90px; object-fit: contain; margin: 5px; }
.profile-content { position: relative; z-index: 2; }

.header-profile-photo {
    width: 80px; height: 80px; border-radius: 50%;
    object-fit: cover; border: 3px solid rgba(255, 255, 255, 0.2);
    transition: transform 0.2s;
}
.profile-photo-container { position: relative; display: inline-block; }
.photo-edit-overlay {
    position: absolute; bottom: -2px; right: -2px;
    opacity: 0; transition: opacity 0.2s;
}
.profile-photo-container:hover .photo-edit-overlay { opacity: 1; }
.photo-edit-btn {
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--accent-blue); color: white;
    border: 2px solid var(--bg-card); cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; transition: all 0.2s;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3);
}
.photo-edit-btn:hover { background: #2a7ae4; transform: scale(1.1); }

.profile-name {
    font-size: 2rem; font-weight: 800;
    margin: 0; letter-spacing: -0.02em;
}
.profile-record {
    font-size: 1.4rem; font-weight: 600; margin: 5px 0;
}
.profile-record .pct {
    font-size: 0.9rem; opacity: 0.6; font-weight: 400;
}
.profile-league {
    font-size: 0.95rem; color: var(--text-secondary); margin-top: 6px;
}
.edit-name-btn {
    background: transparent; border: none; color: var(--text-muted);
    cursor: pointer; padding: 4px; font-size: 1rem;
    transition: color 0.2s; display: inline-flex;
}
.edit-name-btn:hover { color: var(--accent-blue); }

/* ==========================================================================
   EDIT FORM
   ========================================================================== */
.edit-section {
    background: var(--bg-card); border: 1px solid var(--border-color);
    border-radius: var(--radius-md); padding: 16px;
    margin-bottom: 14px; box-shadow: var(--shadow-card);
}
.edit-section h3 { font-size: 1rem; color: var(--text-primary); margin-bottom: 12px; }
.edit-section.hidden { display: none; }

.form-row { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.form-group { flex: 1; min-width: 200px; }
.form-group label {
    display: block; margin-bottom: 4px;
    font-weight: 500; color: var(--text-secondary); font-size: 0.85rem;
}
.form-control {
    width: 100%; padding: 9px 12px;
    border: 1px solid var(--border-color); border-radius: var(--radius-md);
    font-size: 14px; font-family: 'Outfit', sans-serif;
    background: var(--bg-elevated); color: var(--text-primary);
    transition: border-color 0.2s;
}
.form-control:focus {
    outline: none; border-color: var(--accent-blue);
    box-shadow: 0 0 0 2px var(--accent-blue-dim);
}

.btn {
    padding: 9px 16px; border: none; border-radius: var(--radius-md);
    cursor: pointer; font-size: 13px; font-weight: 600;
    font-family: 'Outfit', sans-serif; transition: all 0.2s;
}
.btn-primary { background: var(--accent-blue); color: white; }
.btn-primary:hover { background: #2a7ae4; }
.btn-secondary {
    background: var(--bg-elevated); color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.btn-secondary:hover { color: var(--text-primary); }

/* ==========================================================================
   AUTO-DRAFT BAR
   ========================================================================== */
/* ==========================================================================
   GEAR SETTINGS PANEL
   ========================================================================== */
/* Header controls: selector left, gear right */
.header-controls {
    position: absolute; top: 12px; right: 12px;
    display: flex; align-items: center;
    z-index: 5;
}
.header-controls .gear-btn {
    width: 32px; height: 32px; font-size: 13px;
    background: rgba(0, 0, 0, 0.25);
    border-color: rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
    box-shadow: none;
}

.gear-settings-wrapper {
    position: relative; flex-shrink: 0;
}
.gear-btn {
    width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;
    background: var(--bg-card); border: 1px solid var(--border-color);
    border-radius: var(--radius-md); color: var(--text-secondary);
    font-size: 16px; cursor: pointer; transition: all 0.2s;
    box-shadow: var(--shadow-card);
}
.gear-btn:hover { color: var(--text-primary); border-color: var(--accent-blue); }
.gear-btn.open { color: var(--accent-blue); border-color: var(--accent-blue); background: var(--accent-blue-dim); }
.gear-btn .fa-gear { transition: transform 0.3s ease; }
.gear-btn.open .fa-gear { transform: rotate(90deg); }

.gear-panel {
    display: none; position: absolute; top: 100%; right: 0;
    margin-top: 8px; min-width: 300px; z-index: 100;
    background: var(--bg-card); border: 1px solid var(--border-color);
    border-radius: var(--radius-md); box-shadow: var(--shadow-elevated);
}
.gear-panel.open { display: block; }

.gear-section {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border-color);
}
.gear-section:first-child { border-radius: var(--radius-md) var(--radius-md) 0 0; }
.gear-section:last-child { border-bottom: none; border-radius: 0 0 var(--radius-md) var(--radius-md); }
.gear-section.disabled {
    opacity: 0.4; pointer-events: none;
}

.gear-section-label {
    display: flex; align-items: center; gap: 8px;
    font-size: 0.82rem; font-weight: 600; text-transform: uppercase;
    letter-spacing: 0.04em; color: var(--text-muted);
    margin-bottom: 10px;
}

.gear-section-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; flex-wrap: wrap;
}

/* Theme toggle */
.theme-toggle-group {
    display: flex; gap: 4px;
    background: var(--bg-elevated); border-radius: var(--radius-md);
    padding: 3px; border: 1px solid var(--border-color);
}
.theme-btn {
    padding: 6px 14px; border: none; border-radius: 6px;
    font-size: 0.82rem; font-weight: 600; cursor: pointer;
    font-family: 'Outfit', sans-serif;
    background: transparent; color: var(--text-muted);
    transition: all 0.2s;
}
.theme-btn:hover { color: var(--text-primary); }
.theme-btn.active {
    background: var(--accent-blue); color: white;
    box-shadow: 0 1px 4px rgba(56, 139, 253, 0.3);
}

/* Auto-draft toggle */
.toggle-switch {
    position: relative; display: inline-block;
    width: 48px; height: 26px;
}
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider {
    position: absolute; cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background: #444; transition: 0.3s; border-radius: 26px;
}
.toggle-slider:before {
    position: absolute; content: "";
    height: 20px; width: 20px; left: 3px; bottom: 3px;
    background: white; transition: 0.3s; border-radius: 50%;
}
input:checked + .toggle-slider { background: var(--accent-green); }
input:checked + .toggle-slider:before { transform: translateX(22px); }

.preferences-link {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; background: var(--accent-blue); color: white;
    text-decoration: none; border-radius: var(--radius-md);
    font-size: 0.85rem; font-weight: 500;
    transition: all 0.2s; white-space: nowrap;
}
.preferences-link:hover { background: #2a7ae4; color: white; }

.draft-disabled-note {
    font-size: 0.8rem; color: var(--text-muted); font-style: italic;
}

/* Default league dropdown */
.default-league-select {
    flex: 1;
    padding: 7px 12px;
    background: var(--bg-elevated);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    font-family: 'Outfit', sans-serif;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    transition: border-color 0.2s;
    -webkit-appearance: none;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238b949e' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
    padding-right: 30px;
}
.default-league-select:focus {
    outline: none;
    border-color: var(--accent-blue);
    box-shadow: 0 0 0 2px rgba(56, 139, 253, 0.15);
}
.default-league-select option {
    background: var(--bg-card);
    color: var(--text-primary);
}

/* ==========================================================================
   STATS GRID & CARDS
   ========================================================================== */
.stats-grid {
    display: grid; gap: 14px; grid-template-columns: 1fr;
}

/* Cascade load animation */
@keyframes cascadeIn {
    from { opacity: 0; transform: translateY(18px); }
    to   { opacity: 1; transform: translateY(0); }
}
.cascade-item {
    opacity: 0;
    animation: cascadeIn 0.8s ease-out forwards;
}

.stats-card {
    background: var(--bg-card); border-radius: var(--radius-lg);
    padding: 18px; box-shadow: var(--shadow-card);
    margin-bottom: 14px; position: relative;
}

.section-title {
    font-size: 1.1rem; font-weight: 700;
    margin: 0 0 12px; padding-bottom: 8px;
    border-bottom: 1px solid var(--border-color);
    display: flex; align-items: center; gap: 8px;
}

.team-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 10px 12px; border-bottom: 1px solid var(--border-color);
    background: var(--bg-elevated); border-radius: var(--radius-md);
    margin-bottom: 6px; transition: background var(--transition-fast);
}
.team-row:hover { background: var(--bg-card-hover); }
.team-row:last-child { margin-bottom: 0; }

.team-info {
    display: flex; align-items: center; flex: 1;
    min-width: 0; gap: 8px;
}
.team-info span { color: var(--text-secondary); font-size: 0.9rem; }
.team-logo { width: 28px; height: 28px; object-fit: contain; flex-shrink: 0; }

.team-record {
    font-weight: 600; min-width: 70px; text-align: right;
    font-variant-numeric: tabular-nums;
    flex-shrink: 0;
    max-width: 50%;
    word-break: break-word;
}
.team-record div { font-size: 0.78rem; color: var(--text-muted); }

.no-data {
    color: var(--text-muted); font-style: italic;
    padding: 20px; text-align: center;
}

/* ==========================================================================
   GAMES LIST
   ========================================================================== */
.games-list { display: flex; flex-direction: column; gap: 6px; }

.game-list-item {
    padding: 10px 12px;
    background: var(--bg-elevated);
    border-radius: var(--radius-md);
    border: 1px solid var(--border-color);
    display: flex; justify-content: space-between; align-items: center;
    text-decoration: none; color: inherit;
    transition: all var(--transition-fast);
    gap: 10px;
}
.game-list-item.clickable:hover { background: var(--bg-card-hover); border-color: rgba(56, 139, 253, 0.15); }
.game-list-item.win { border-left: 3px solid var(--accent-green); }
.game-list-item.loss { border-left: 3px solid var(--accent-red); }

.game-list-info { flex: 1; min-width: 0; }
.game-list-date { font-size: 0.75rem; color: var(--text-muted); margin-bottom: 2px; }
.game-list-matchup {
    font-weight: 600; font-size: 0.88rem; color: var(--text-primary);
    display: flex; align-items: center; gap: 4px;
}
.game-list-matchup img { width: 18px; height: 18px; vertical-align: middle; flex-shrink: 0; }
.game-list-owner {
    font-size: 0.75rem; color: var(--text-muted); font-weight: 400;
    font-style: italic; display: block; margin-top: 1px;
}

.game-list-result {
    text-align: right; flex-shrink: 0; white-space: nowrap;
    display: flex; flex-direction: column; align-items: flex-end; gap: 1px;
}
.game-list-score-line {
    display: flex; align-items: center; gap: 4px; white-space: nowrap;
}
.game-list-score {
    font-size: 1rem; font-weight: 700; font-variant-numeric: tabular-nums;
}
.game-list-outcome { font-size: 0.85rem; font-weight: 700; }

/* ==========================================================================
   WIDGET PIN ICON
   ========================================================================== */
.widget-pin-icon {
    position: absolute; top: 12px; right: 12px;
    background: transparent; color: var(--text-muted);
    border: none; border-radius: 4px;
    width: 28px; height: 28px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 13px;
    transition: all 0.2s; z-index: 10; opacity: 0.5;
}
.widget-pin-icon:hover {
    opacity: 1; color: var(--accent-blue);
    background: var(--accent-blue-dim);
}
.widget-pin-icon.pinned { color: var(--accent-green); opacity: 0.7; }
.widget-pin-icon.pinned:hover { opacity: 1; background: rgba(63, 185, 80, 0.1); }

/* ==========================================================================
   RIVALS SECTION
   ========================================================================== */
.rivals-section {
    margin-top: 16px; padding-top: 14px;
    border-top: 1px solid var(--border-color);
}
.rivals-title {
    margin: 0 0 10px; font-size: 1rem; font-weight: 600;
    color: var(--text-primary);
    display: flex; align-items: center; gap: 8px;
}
.rival-link {
    text-decoration: none; color: var(--accent-blue);
    font-weight: 600; transition: color 0.2s;
    word-break: break-word;
    display: inline-block;
    max-width: 100%;
    line-height: 1.3;
}
.rival-link:hover { color: #5ba3fd; }

/* ==========================================================================
   PHOTO OPTIONS MODAL
   ========================================================================== */
.photo-options-modal {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.7); z-index: 2000;
    display: none; align-items: center; justify-content: center;
}
.photo-options-content {
    background: var(--bg-card); border-radius: var(--radius-lg);
    padding: 25px; max-width: 380px; width: 90%;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
    text-align: center; border: 1px solid var(--border-color);
}
.photo-options-content h3 { margin: 0 0 15px; color: var(--text-primary); }
.photo-preview {
    width: 80px; height: 80px; border-radius: 50%;
    object-fit: cover; margin: 0 auto 15px;
    border: 3px solid var(--bg-elevated);
}
.photo-option-btn {
    padding: 10px 18px; border: 1px solid var(--border-color);
    border-radius: var(--radius-md); background: var(--bg-elevated);
    color: var(--text-primary); cursor: pointer;
    transition: all 0.2s; font-size: 13px;
    font-family: 'Outfit', sans-serif;
    display: flex; align-items: center; justify-content: center;
    gap: 8px; width: 100%;
}
.photo-option-btn:hover { background: var(--bg-card-hover); }
.photo-option-btn.primary {
    background: var(--accent-blue); color: white;
    border-color: var(--accent-blue);
}
.photo-option-btn.danger {
    background: var(--accent-red); color: white;
    border-color: var(--accent-red);
}
.photo-options-buttons { display: flex; flex-direction: column; gap: 10px; }

/* ==========================================================================
   MOBILE RESPONSIVE
   ========================================================================== */
@media (max-width: 600px) {
    .app-container { padding: 0 8px 2rem; }
    .profile-header { padding: 1.5rem 0.75rem; padding-top: 2.8rem; min-height: 150px; }
    .header-controls { top: 8px; right: 8px; }
    .header-controls .gear-btn { width: 28px; height: 28px; font-size: 12px; }
    .profile-name { font-size: 1.5rem; }
    .profile-record { font-size: 1.2rem; }
    .stats-grid { grid-template-columns: 1fr; }
    .team-row { padding: 8px 10px; }
    .team-row .team-info span,
    .team-row .team-info a { font-size: 0.82rem; }
    .team-record { font-size: 0.9rem; min-width: 55px; }

    .game-list-item { padding: 8px 10px; }
    .game-list-matchup { font-size: 0.82rem; }
    .game-list-matchup img { width: 16px; height: 16px; }
    .game-list-score { font-size: 0.88rem; }
    .game-list-outcome { font-size: 0.78rem; }
    .form-row { flex-direction: column; }
}

@media (min-width: 601px) {
    .app-container { padding: 0 20px 2rem; }
}

@media (min-width: 768px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}
/* Override: inside desktop two-col, stats-grid stays single column */
@media (min-width: 1100px) {
    .pp-col-left .stats-grid { grid-template-columns: 1fr !important; }
}
/* ==========================================================================
   PROFILE TABS
   ========================================================================== */
.profile-tabs {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 0 16px;
    margin-top: 10px;
    border-bottom: 1px solid var(--border-color);
}
.tab-bar-spacer { flex: 1; }
.tab-participant-select {
    font-family: 'Outfit', sans-serif;
    font-size: 0.82rem;
    font-weight: 500;
    color: var(--text-primary);
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 5px 28px 5px 10px;
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23888'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 9px center;
    outline: none;
    transition: border-color 0.15s;
    max-width: 160px;
}
.tab-participant-select:hover { border-color: rgba(56,139,253,0.4); }
.tab-participant-select option { background: var(--bg-card); color: var(--text-primary); }
.profile-tab {
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    color: var(--text-muted);
    font-family: 'Outfit', sans-serif;
    font-size: 0.9rem;
    font-weight: 500;
    padding: 10px 16px 9px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 7px;
    margin-bottom: -1px;
    transition: color 0.15s, border-color 0.15s;
}
.profile-tab:hover { color: var(--text-primary); }
.profile-tab.active {
    color: var(--accent-blue);
    border-bottom-color: var(--accent-blue);
}
.tab-badge-count {
    font-size: 0.72rem;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 20px;
    background: rgba(255,255,255,0.07);
    color: var(--text-muted);
}
.profile-tab.active .tab-badge-count {
    background: rgba(59,130,246,0.15);
    color: var(--accent-blue);
}
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* ==========================================================================
   BADGES TAB
   ========================================================================== */
.badges-tab-grid {
    padding: 20px 16px;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
}
@media (max-width: 1100px) {
    .badges-tab-grid { grid-template-columns: repeat(2, 1fr); gap: 14px; }
}
@media (max-width: 600px) {
    .badges-tab-grid { grid-template-columns: 1fr; padding: 14px 12px; gap: 12px; }
}
/* ==========================================================================
   BADGES SECTION
   ========================================================================== */
.badges-card { position: relative; }


/* Desktop: larger badges in tab view */
@media (min-width: 1100px) {
    .badges-tab-grid .badge-grid {
        grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
        gap: 12px;
    }
    .badges-tab-grid .badge-tile {
        padding: 14px 6px 10px;
        gap: 7px;
    }
    .badges-tab-grid .badge-icon-wrap {
        width: 52px;
        height: 52px;
        font-size: 22px;
    }
    .badges-tab-grid .badge-name {
        font-size: 0.74rem;
    }
    .badges-tab-grid .badge-count {
        font-size: 0.7rem;
        padding: 2px 8px;
    }
    .badges-tab-grid .badge-category-label {
        font-size: 0.78rem;
        margin-bottom: 12px;
    }
}

/* Desktop: add gap between stats-card elements in two-col layout */
@media (min-width: 1100px) {
    .pp-col-left .stats-card + .stats-card {
        margin-top: 14px;
    }
}

.badge-category {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 16px 16px 14px;
    box-shadow: var(--shadow-card);
}

.badge-category-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--text-muted);
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 8px;
}
.badge-category-label::before {
    content: '';
    display: inline-block;
    width: 3px;
    height: 12px;
    border-radius: 2px;
    background: var(--accent-blue, #3b82f6);
    flex-shrink: 0;
}

.badge-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(76px, 1fr));
    gap: 10px;
}

.badge-tile {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 5px;
    padding: 10px 4px 8px;
    border-radius: var(--radius-md);
    cursor: default;
    position: relative;
    transition: transform 0.15s ease;
    text-align: center;
}

.badge-tile:hover { transform: translateY(-2px); }

/* EARNED state */
.badge-tile.earned {
    background: var(--bg-elevated);
    border: 1px solid rgba(255,255,255,0.06);
}

/* LOCKED state */
.badge-tile.locked {
    background: rgba(255,255,255,0.02);
    border: 1px dashed rgba(255,255,255,0.06);
    opacity: 0.42;
    filter: grayscale(1);
}

.badge-icon-wrap {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    transition: box-shadow 0.2s;
}

.badge-tile.earned .badge-icon-wrap {
    box-shadow: 0 0 12px var(--badge-glow, rgba(255,255,255,0.2));
}

.badge-name {
    font-size: 0.68rem;
    font-weight: 600;
    color: var(--text-secondary);
    line-height: 1.2;
    word-break: break-word;
}

.badge-tile.earned .badge-name { color: var(--text-primary); }

.badge-count {
    font-size: 0.65rem;
    font-weight: 700;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 1px 6px;
    color: var(--text-muted);
    line-height: 1.4;
}

.badge-active-streak {
    font-size: 0.6rem;
    font-weight: 700;
    border-radius: 8px;
    padding: 2px 6px;
    line-height: 1.4;
    background: rgba(245, 158, 11, 0.18);
    color: #f59e0b;
    border: 1px solid rgba(245, 158, 11, 0.3);
    white-space: nowrap;
}

.badge-progress-bar-wrap {
    width: 80%;
    height: 4px;
    background: rgba(255,255,255,0.08);
    border-radius: 2px;
    margin-top: 5px;
    overflow: hidden;
}
.badge-progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #4b82f8, #818cf8);
    border-radius: 2px;
    transition: width 0.4s ease;
}
.badge-progress-label {
    font-size: 0.6rem;
    color: #6b7280;
    margin-top: 3px;
    line-height: 1.2;
    text-align: center;
}

/* ==========================================================================
   TROPHY RIBBON (profile header)
   ========================================================================== */
.trophy-ribbon {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: linear-gradient(135deg, rgba(255,215,0,0.18), rgba(255,180,0,0.10));
    border: 1px solid rgba(255,215,0,0.3);
    border-radius: 20px;
    padding: 3px 10px 3px 7px;
    font-size: 0.72rem;
    font-weight: 700;
    color: #FFD700;
    vertical-align: middle;
    margin-left: 6px;
    white-space: nowrap;
    line-height: 1;
}
.trophy-ribbon .trophy-icon {
    font-size: 0.75rem;
    filter: drop-shadow(0 0 3px rgba(255,215,0,0.5));
}
.trophy-ribbon .trophy-count {
    font-size: 0.68rem;
    opacity: 0.85;
}

/* ==========================================================================
   TROPHY CASE (compact card in badge grid)
   ========================================================================== */
.badge-cat-trophies {
    border-color: rgba(255,215,0,0.15) !important;
    background: var(--bg-card) !important;
}
.badge-cat-trophies .badge-category-label {
    color: #d4a017;
    border-bottom-color: rgba(255,215,0,0.15);
}
.badge-cat-trophies .badge-category-label::before { background: #FFD700; }

.trophy-entry {
    padding: 10px 12px;
    background: var(--bg-elevated);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    margin-bottom: 8px;
}
.trophy-entry:last-child { margin-bottom: 0; }
.trophy-entry-top {
    display: flex;
    align-items: center;
    gap: 8px;
}
.trophy-entry-season {
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--text-primary);
    flex: 1;
}
.trophy-entry-wins {
    font-size: 0.82rem;
    font-weight: 800;
    color: #FFD700;
}
.trophy-entry-champ {
    font-size: 0.68rem;
    font-weight: 600;
    color: #FFD700;
    margin-top: 4px;
    padding-left: 22px;
}
.trophy-entry-logos {
    display: flex;
    gap: 3px;
    margin-top: 6px;
    padding-left: 22px;
    flex-wrap: wrap;
}
.trophy-team-logo {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    object-fit: contain;
}

/* ==========================================================================
   IMAGE-BASED BADGES
   ========================================================================== */
.badge-icon-wrap img.badge-icon-img {
    width: 26px;
    height: 26px;
    object-fit: contain;
}
.badges-tab-grid .badge-icon-wrap img.badge-icon-img {
    width: 32px;
    height: 32px;
}
#badge-modal-icon-wrap img.badge-modal-img {
    width: 48px;
    height: 48px;
    object-fit: contain;
}

/* Badge Popup Modal */
.badge-tile { cursor: pointer; }

#badge-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.55);
    z-index: 9000;
    align-items: center;
    justify-content: center;
    perspective: 800px;
}
#badge-modal-overlay.open { display: flex; }

#badge-modal {
    background: var(--bg-elevated);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 28px 28px 24px;
    max-width: 320px;
    width: 88%;
    text-align: center;
    position: relative;
    box-shadow: 0 12px 40px rgba(0,0,0,0.5);
    animation: badgeModalIn 0.2s ease both;
}
@keyframes badgeModalIn {
    from { transform: scale(0.94); opacity: 0; }
    to   { transform: scale(1);    opacity: 1; }
}
#badge-modal-close {
    position: absolute;
    top: 12px; right: 14px;
    background: none; border: none;
    color: var(--text-muted);
    font-size: 18px; cursor: pointer;
    line-height: 1;
}
#badge-modal-icon-wrap {
    width: 64px; height: 64px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 14px;
    font-size: 26px;
    animation: coinFlip 0.95s cubic-bezier(0.23, 1, 0.32, 1) both;
}
@keyframes coinFlip {
    0%   { transform: rotateY(0deg)    scale(1); }
    20%  { transform: rotateY(90deg)   scale(0.85); }
    40%  { transform: rotateY(180deg)  scale(1.1); }
    60%  { transform: rotateY(270deg)  scale(0.9); }
    78%  { transform: rotateY(360deg)  scale(1.12); }
    88%  { transform: rotateY(700deg)  scale(0.96); }
    100% { transform: rotateY(720deg)  scale(1); }
}
#badge-modal-name {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 8px;
}
#badge-modal-desc {
    font-size: 0.85rem;
    color: var(--text-muted);
    line-height: 1.5;
    margin-bottom: 12px;
}
#badge-modal-date {
    font-size: 0.8rem;
    font-weight: 600;
    padding: 5px 12px;
    border-radius: 20px;
    display: inline-block;
}
#badge-modal-date.earned-date {
    background: rgba(255,255,255,0.08);
    color: var(--text-primary);
}
#badge-modal-date.not-earned {
    background: rgba(255,255,255,0.04);
    color: #555;
}


</style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/components/navigation_menu.php'; ?>

<div class="app-container">

    <!-- ================================================================
         HEADER
         ================================================================ -->


    <!-- ================================================================
         ALERTS
         ================================================================ -->
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <!-- ================================================================
         OWN PROFILE: EDIT FORMS & AUTO-DRAFT
         ================================================================ -->
    <?php if ($is_own_profile): ?>

        <!-- Edit Display Name (hidden by default) -->
        <div class="edit-section hidden" id="editForm">
            <h3>Edit Display Name</h3>
            <form method="POST">
                <input type="hidden" name="action" value="update_display_name">
                <div class="form-row">
                    <div class="form-group">
                        <label for="display_name">Display Name</label>
                        <input type="text" id="display_name" name="display_name"
                               value="<?= htmlspecialchars($participant['display_name']) ?>"
                               class="form-control" maxlength="20" required>
                        <small id="charCount" style="color: var(--text-muted); font-size: 0.8em">0/20</small>
                    </div>
                    <div style="display: flex; gap: 8px">
                        <button type="submit" class="btn btn-primary">Update</button>
                        <button type="button" class="btn btn-secondary" onclick="toggleEditForm()">Cancel</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Hidden photo forms -->
        <form method="POST" enctype="multipart/form-data" id="photoUploadForm" style="display: none">
            <input type="hidden" name="action" value="upload_photo">
            <input type="file" id="profile_photo" name="profile_photo"
                   accept="image/jpeg,image/png,image/gif,image/webp"
                   onchange="previewAndUpload(this)">
        </form>
        <form method="POST" id="deletePhotoForm" style="display: none">
            <input type="hidden" name="action" value="delete_photo">
        </form>

    <?php endif; ?>

    <!-- ================================================================
         PROFILE TABS
         ================================================================ -->
    <div class="profile-tabs">
        <button class="profile-tab active" onclick="switchTab('main', this)">Main</button>
        <button class="profile-tab" onclick="switchTab('badges', this)">
            Badges
            <span class="tab-badge-count"><?= count($earnedBadges) ?>/<?= count($badgeDefs) ?></span>
        </button>
        <div class="tab-bar-spacer"></div>
        <select class="tab-participant-select" onchange="window.location.href='?league_id=<?= $league_id ?>&user_id='+this.value+'&tab='+getActiveTab()">
            <?php foreach ($all_participants as $p): ?>
                <option value="<?= $p['user_id'] ?>" <?= $p['user_id'] == $user_id ? 'selected' : '' ?>>
                    <?= htmlspecialchars($p['display_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- TAB: MAIN -->
    <div id="tab-main" class="tab-panel active">
    <!-- ================================================================
         TWO-COLUMN LAYOUT (desktop) / STACKED (mobile)
         ================================================================ -->
    <div class="pp-two-col">

    <!-- LEFT COLUMN -->
    <div class="pp-col-left">

    <!-- ================================================================
         PROFILE HEADER CARD (with selector + gear)
         ================================================================ -->
    <div class="profile-header">
        <!-- Controls: gear only (selector moved to tab bar) -->
        <div class="header-controls">
            <?php if ($is_own_profile): ?>
            <div class="gear-settings-wrapper">
                <button type="button" class="gear-btn" id="gearBtn" onclick="toggleGearPanel()">
                    <i class="fas fa-gear"></i>
                </button>

                <div class="gear-panel" id="gearPanel">
                    <!-- Theme Section -->
                    <div class="gear-section">
                        <div class="gear-section-label">
                            <i class="fas fa-palette"></i> Theme
                        </div>
                        <div class="gear-section-row">
                            <div class="theme-toggle-group">
                                <button type="button" class="theme-btn <?= $current_theme === 'dark' ? 'active' : '' ?>"
                                        onclick="setTheme('dark')">
                                    <i class="fas fa-moon" style="margin-right: 4px"></i> Dark
                                </button>
                                <button type="button" class="theme-btn <?= $current_theme === 'classic' ? 'active' : '' ?>"
                                        onclick="setTheme('classic')">
                                    <i class="fas fa-sun" style="margin-right: 4px"></i> Light
                                </button>
                            </div>
                        </div>
                        <form method="POST" id="themeForm" style="display:none">
                            <input type="hidden" name="action" value="toggle_theme">
                            <input type="hidden" name="theme" id="themeInput" value="">
                        </form>
                    </div>

                    <!-- Draft Preferences Section -->
                    <div class="gear-section<?= $draft_completed ? ' disabled' : '' ?>">
                        <div class="gear-section-label">
                            <i class="fas fa-basketball"></i> Draft
                        </div>
                        <?php if (!$draft_completed): ?>
                            <div class="gear-section-row">
                                <form method="POST" id="autoDraftForm" style="display: flex; align-items: center; gap: 10px; margin: 0">
                                    <input type="hidden" name="action" value="toggle_auto_draft">
                                    <label class="toggle-switch" style="margin: 0">
                                        <input type="checkbox" name="auto_draft_enabled" id="autoDraftToggle"
                                               <?= $participant['auto_draft_enabled'] ? 'checked' : '' ?>
                                               onchange="confirmAutoDraftToggle(this)">
                                        <span class="toggle-slider"></span>
                                    </label>
                                    <label for="autoDraftToggle" style="cursor: pointer; user-select: none; font-size: 0.9rem; color: var(--text-secondary)">
                                        <strong style="color: var(--text-primary)">Auto-Draft:</strong>
                                        <span style="color: <?= $participant['auto_draft_enabled'] ? 'var(--accent-green)' : 'var(--text-muted)' ?>">
                                            <?= $participant['auto_draft_enabled'] ? 'On' : 'Off' ?>
                                        </span>
                                    </label>
                                </form>

                                <a href="/nba-wins-platform/profiles/draft_preferences.php?league_id=<?= $league_id ?>&user_id=<?= $user_id ?>"
                                   class="preferences-link">
                                    <i class="fas fa-list-ol"></i> Team Rankings
                                    <?php
                                    $stmt = $pdo->prepare("SELECT COUNT(*) AS count FROM user_draft_preferences WHERE user_id = ?");
                                    $stmt->execute([$user_id]);
                                    $pc = $stmt->fetch()['count'];
                                    if ($pc == 30) {
                                        echo '<span style="background:rgba(0,0,0,0.15);padding:1px 6px;border-radius:10px;font-size:0.8rem">✓</span>';
                                    } elseif ($pc > 0) {
                                        echo '<span style="background:rgba(0,0,0,0.15);padding:1px 6px;border-radius:10px;font-size:0.8rem">' . $pc . '/30</span>';
                                    }
                                    ?>
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="gear-section-row">
                                <span class="draft-disabled-note"><i class="fas fa-check-circle" style="margin-right: 4px"></i>Draft complete</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (count($user_leagues) > 1): ?>
                    <!-- Default League Section -->
                    <div class="gear-section">
                        <div class="gear-section-label">
                            <i class="fas fa-house-flag"></i> Default League
                        </div>
                        <div class="gear-section-row">
                            <form method="POST" id="defaultLeagueForm" style="display: flex; align-items: center; gap: 10px; margin: 0; flex: 1">
                                <input type="hidden" name="action" value="set_default_league">
                                <select name="default_league_id" id="defaultLeagueSelect" class="default-league-select" onchange="document.getElementById('defaultLeagueForm').submit()">
                                    <option value="none"<?= $user_default_league_id === null ? ' selected' : '' ?>>No default</option>
                                    <?php foreach ($user_leagues as $ul): ?>
                                    <option value="<?= $ul['id'] ?>"<?= $user_default_league_id == $ul['id'] ? ' selected' : '' ?>>
                                        <?= htmlspecialchars($ul['display_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                        <div style="margin-top: 6px; font-size: 0.75rem; color: var(--text-muted); line-height: 1.4">
                            Opens to this league on login
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Background team logos -->
        <div class="logo-background">
            <?php foreach ($teams as $team): ?>
                <img src="<?= htmlspecialchars(getTeamLogo($team['team_name'])) ?>" alt=""
                     class="header-logo" onerror="this.style.display='none'">
            <?php endforeach; ?>
        </div>

        <div class="profile-content">
            <div style="display: flex; align-items: center; justify-content: center; gap: 18px; flex-wrap: wrap">
                <!-- Profile photo -->
                <div class="profile-photo-container">
                    <img src="<?= htmlspecialchars($profile_photo_url) ?>" alt=""
                         class="header-profile-photo"
                         onerror="this.src='../public/assets/profile_photos/default.png'">
                    <?php if ($is_own_profile): ?>
                        <div class="photo-edit-overlay">
                            <button type="button" class="photo-edit-btn" onclick="showPhotoOptions()" title="Edit Photo">
                                <i class="fas fa-camera"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Name & record -->
                <div>
                    <h1 class="profile-name">
                        <?= htmlspecialchars($participant['display_name']) ?>
                        <?php if ($is_own_profile): ?>
                            <button type="button" class="edit-name-btn" onclick="toggleEditForm()" title="Edit name">
                                <i class="fas fa-edit"></i>
                            </button>
                        <?php endif; ?>
                        <?php if ($leagueChampCount > 0): ?>
                            <span class="trophy-ribbon" title="<?= $leagueChampCount ?>x League Champion">
                                <i class="fas fa-trophy trophy-icon"></i>
                                <span class="trophy-count">&times;<?= $leagueChampCount ?></span>
                            </span>
                        <?php endif; ?>
                    </h1>

                    <?php if (!empty($participant['participant_name']) && $participant['participant_name'] !== $participant['display_name']): ?>
                        <div style="font-size: 0.9rem; opacity: 0.5; font-style: italic; margin-top: 2px">
                            <?= htmlspecialchars($participant['participant_name']) ?>
                        </div>
                    <?php endif; ?>

                    <div class="profile-record">
                        <?= $total_wins ?>-<?= $total_losses ?>
                        <?php if ($total_wins + $total_losses > 0): ?>
                            <span class="pct">
                                (<?= number_format(($total_wins / ($total_wins + $total_losses)) * 100, 1) ?>%)
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <p class="profile-league"><?= htmlspecialchars($pp_league['display_name']) ?></p>
        </div>
    </div>

    <!-- ================================================================
         TWO-COLUMN STATS GRID
         ================================================================ -->
    <div class="stats-grid">

        <!-- Teams Card -->
        <div class="stats-card">
            <h2 class="section-title">Teams</h2>
            <?php if (empty($teams)): ?>
                <div class="no-data">No teams drafted yet</div>
            <?php else: ?>
                <?php foreach ($teams as $team): ?>
                    <div class="team-row">
                        <div class="team-info">
                            <img src="<?= htmlspecialchars(getTeamLogo($team['team_name'])) ?>" alt=""
                                 class="team-logo" onerror="this.style.opacity='0.3'">
                            <a href="/nba-wins-platform/stats/team_data.php?team=<?= urlencode($team['team_name']) ?>"
                               style="text-decoration: none; color: var(--text-primary); font-weight: 500; transition: color 0.2s"
                               onmouseover="this.style.color='var(--accent-blue)'"
                               onmouseout="this.style.color='var(--text-primary)'">
                                <?= htmlspecialchars($team['team_name']) ?>
                            </a>
                        </div>
                        <div class="team-record">
                            <?= $team['wins'] ?>-<?= $team['losses'] ?>
                            <?php if ($team['games_played'] > 0): ?>
                                <div><?= $team['win_percentage'] ?>%</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- League Stats Card -->
        <div class="stats-card" data-widget-type="league_stats">
            <?php if ($is_own_profile): ?>
                <button class="widget-pin-icon <?= in_array('league_stats', $pinned_widgets) ? 'pinned' : '' ?>"
                        onclick="toggleWidgetPin('league_stats', this)"
                        title="<?= in_array('league_stats', $pinned_widgets) ? 'Unpin' : 'Pin to homepage' ?>">
                    <i class="fas fa-<?= in_array('league_stats', $pinned_widgets) ? 'check' : 'thumbtack' ?>"></i>
                </button>
            <?php endif; ?>

            <h2 class="section-title">League Stats</h2>

            <div class="team-row">
                <div class="team-info"><span>Total Games</span></div>
                <div class="team-record"><?= array_sum(array_column($teams, 'games_played')) ?></div>
            </div>

            <div class="team-row">
                <div class="team-info"><span>Avg Record</span></div>
                <div class="team-record">
                    <?php
                    $ac = count($teams);
                    echo $ac > 0
                        ? round($total_wins / $ac, 1) . '-' . round($total_losses / $ac, 1)
                        : '0-0';
                    ?>
                </div>
            </div>

            <?php if ($participantWinStreak > 0 || $participantLossStreak > 0): ?>
            <div class="team-row">
                <div class="team-info">
                    <?php if ($participantWinStreak > 0): ?>
                        <i class="fas fa-fire" style="color: #f59e0b; margin-right: 4px; font-size: 0.85rem"></i>
                        <span>Win Streak</span>
                    <?php else: ?>
                        <i class="fa-solid fa-snowflake" style="color: #60a5fa; margin-right: 4px; font-size: 0.85rem"></i>
                        <span>Loss Streak</span>
                    <?php endif; ?>
                </div>
                <div class="team-record" style="color: <?= $participantWinStreak > 0 ? 'var(--accent-green)' : 'var(--accent-red)' ?>">
                    <?= $participantWinStreak > 0 ? $participantWinStreak . 'W' : $participantLossStreak . 'L' ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="team-row">
                <div class="team-info"><span>Best Team</span></div>
                <div class="team-record">
                    <?php
                    $bt = null;
                    $bw = -1;
                    $bp = -1;
                    foreach ($teams as $t) {
                        if ($t['wins'] > $bw) {
                            $bw = $t['wins'];
                            $bp = $t['win_percentage'];
                            $bt = $t;
                        } elseif ($t['wins'] == $bw && $t['win_percentage'] > $bp) {
                            $bp = $t['win_percentage'];
                            $bt = $t;
                        }
                    }
                    echo $bt
                        ? htmlspecialchars($bt['team_name']) . ' (' . $bt['wins'] . '-' . $bt['losses'] . ')'
                        : 'N/A';
                    ?>
                </div>
            </div>

            <!-- Rivals sub-section -->
            <div class="rivals-section">
                <h3 class="rivals-title">
                    Rivals
                </h3>

                <?php if ($biggest_rival): ?>
                    <div class="team-row">
                        <div class="team-info">
                            <i class="fas fa-fire" style="color: var(--accent-red); margin-right: 4px; font-size: 0.85rem"></i>
                            <span>Most Wins vs</span>
                        </div>
                        <div class="team-record">
                            <a href="?league_id=<?= $league_id ?>&user_id=<?= $biggest_rival['opponent_user_id'] ?>"
                               class="rival-link"><?= htmlspecialchars($biggest_rival['opponent_name']) ?></a>
                            <div style="color: var(--accent-green)">
                                <?= $biggest_rival['wins_against_opponent'] ?>-<?= $biggest_rival['losses_against_opponent'] ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($nemesis): ?>
                    <div class="team-row">
                        <div class="team-info">
                            <i class="fas fa-skull-crossbones" style="color: var(--accent-red); margin-right: 4px; font-size: 0.85rem"></i>
                            <span>Most Losses vs</span>
                        </div>
                        <div class="team-record">
                            <a href="?league_id=<?= $league_id ?>&user_id=<?= $nemesis['opponent_user_id'] ?>"
                               class="rival-link"><?= htmlspecialchars($nemesis['opponent_name']) ?></a>
                            <div style="color: var(--accent-red)">
                                <?= $nemesis['wins_against_opponent'] ?>-<?= $nemesis['losses_against_opponent'] ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!$biggest_rival && !$nemesis): ?>
                    <div class="no-data">
                        <i class="fas fa-handshake"></i> No head-to-head games yet
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /.stats-grid -->

    </div><!-- /.pp-col-left -->

    <!-- RIGHT COLUMN -->
    <div class="pp-col-right">

    <!-- ================================================================
         LAST 10 GAMES
         ================================================================ -->
    <div class="stats-card" data-widget-type="last_10_games">
        <?php if ($is_own_profile): ?>
            <button class="widget-pin-icon <?= in_array('last_10_games', $pinned_widgets) ? 'pinned' : '' ?>"
                    onclick="toggleWidgetPin('last_10_games', this)">
                <i class="fas fa-<?= in_array('last_10_games', $pinned_widgets) ? 'check' : 'thumbtack' ?>"></i>
            </button>
        <?php endif; ?>

        <?php
        $l10w = 0;
        $l10l = 0;
        foreach ($lastGames as $g) {
            if ($g['result'] === 'W') $l10w++;
            elseif ($g['result'] === 'L') $l10l++;
        }
        ?>
        <h2 class="section-title">
            Recent Games
            <?php if (!empty($lastGames)): ?>
                <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: 400; margin-left: 6px">
                    (<?= $l10w ?>-<?= $l10l ?>)
                </span>
            <?php endif; ?>
        </h2>

        <?php if (!empty($lastGames)): ?>
            <div class="games-list">
                <?php foreach (array_reverse($lastGames) as $game):
                    $ts = ($game['team_location'] === 'home') ? $game['home_points'] : $game['away_points'];
                    $os = ($game['team_location'] === 'home') ? $game['away_points'] : $game['home_points'];
                    $gu = "/nba-wins-platform/stats/game_details.php"
                        . "?home_team=" . urlencode($game['home_team_code'])
                        . "&away_team=" . urlencode($game['away_team_code'])
                        . "&date=" . urlencode($game['game_date']);
                ?>
                    <a href="<?= $gu ?>" class="game-list-item clickable <?= strtolower($game['result']) ?>">
                        <div class="game-list-info">
                            <div class="game-list-date"><?= date('M j, Y', strtotime($game['game_date'])) ?></div>
                            <?php
                                $myCode = ($game['team_location'] === 'home') ? $game['home_team_code'] : $game['away_team_code'];
                                $oppCode = ($game['team_location'] === 'home') ? $game['away_team_code'] : $game['home_team_code'];
                            ?>
                            <div class="game-list-matchup">
                                <img src="<?= htmlspecialchars(getTeamLogo($game['my_team'])) ?>" alt=""
                                     onerror="this.style.display='none'">
                                <?= htmlspecialchars($myCode) ?>
                                <?= $game['team_location'] === 'home' ? 'vs' : '@' ?>
                                <img src="<?= htmlspecialchars(getTeamLogo($game['opponent'])) ?>" alt=""
                                     onerror="this.style.display='none'">
                                <?= htmlspecialchars($oppCode) ?>
                            </div>
                            <?php if (!empty($game['opponent_owner'])): ?>
                                <span class="game-list-owner">(<?= htmlspecialchars($game['opponent_owner']) ?>)</span>
                            <?php endif; ?>
                        </div>
                        <div class="game-list-result">
                            <div class="game-list-score-line">
                                <span class="game-list-score"><?= $ts ?>-<?= $os ?></span>
                                <span class="game-list-outcome"
                                      style="color: <?= $game['result'] === 'W' ? 'var(--accent-green)' : 'var(--accent-red)' ?>">
                                    <?= $game['result'] ?>
                                </span>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="no-data">No recent games</div>
        <?php endif; ?>
    </div>

    <!-- ================================================================
         UPCOMING 5 GAMES
         ================================================================ -->
    <div class="stats-card" data-widget-type="upcoming_games">
        <?php if ($is_own_profile): ?>
            <button class="widget-pin-icon <?= in_array('upcoming_games', $pinned_widgets) ? 'pinned' : '' ?>"
                    onclick="toggleWidgetPin('upcoming_games', this)">
                <i class="fas fa-<?= in_array('upcoming_games', $pinned_widgets) ? 'check' : 'thumbtack' ?>"></i>
            </button>
        <?php endif; ?>

        <h2 class="section-title">Upcoming Games</h2>

        <?php if (!empty($upcomingGames)): ?>
            <div class="games-list">
                <?php foreach ($upcomingGames as $game):
                    $cu = "/nba-wins-platform/stats/team_comparison.php"
                        . "?home_team=" . urlencode($game['home_team_code'])
                        . "&away_team=" . urlencode($game['away_team_code'])
                        . "&date=" . urlencode($game['game_date']);
                ?>
                    <a href="<?= $cu ?>" class="game-list-item clickable">
                        <div class="game-list-info">
                            <div class="game-list-date"><?= date('M j, Y', strtotime($game['game_date'])) ?></div>
                            <?php
                                $myCode = ($game['team_location'] === 'home') ? $game['home_team_code'] : $game['away_team_code'];
                                $oppCode = ($game['team_location'] === 'home') ? $game['away_team_code'] : $game['home_team_code'];
                            ?>
                            <div class="game-list-matchup">
                                <img src="<?= htmlspecialchars(getTeamLogo($game['my_team'])) ?>" alt=""
                                     onerror="this.style.display='none'">
                                <?= htmlspecialchars($myCode) ?>
                                <?= $game['team_location'] === 'home' ? 'vs' : '@' ?>
                                <img src="<?= htmlspecialchars(getTeamLogo($game['opponent'])) ?>" alt=""
                                     onerror="this.style.display='none'">
                                <?= htmlspecialchars($oppCode) ?>
                            </div>
                            <?php if (!empty($game['opponent_owner'])): ?>
                                <span class="game-list-owner">(<?= htmlspecialchars($game['opponent_owner']) ?>)</span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="no-data">No upcoming games</div>
        <?php endif; ?>
    </div>

    </div><!-- /.pp-col-right -->
    </div><!-- /.pp-two-col -->
    </div><!-- /#tab-main -->

    <!-- TAB: BADGES -->
    <div id="tab-badges" class="tab-panel">
    <div class="badges-tab-grid">

        <?php foreach ($badgeCategories as $catKey => $cat): ?>
        <div class="badge-category badge-cat-<?= $catKey ?>">
            <div class="badge-category-label"><?= $cat['label'] ?></div>
            <div class="badge-grid">
                <?php foreach ($cat['keys'] as $key):
                    $def      = $badgeDefs[$key];
                    $earned   = isset($earnedBadges[$key]);
                    $data     = $earned ? $earnedBadges[$key] : null;
                    $isEarned = $earned ? 'earned' : 'locked';

                    $popupDate = '';
                    if ($earned) {
                        if (isset($data['metadata']['achieved_date'])) {
                            $popupDate = 'Achieved ' . date('M j, Y', strtotime($data['metadata']['achieved_date']));
                        } elseif (isset($data['metadata']['instances']) && count($data['metadata']['instances']) > 0) {
                            $instances = $data['metadata']['instances'];
                            $latest    = end($instances);
                            $dateStr   = isset($latest['date']) ? date('M j, Y', strtotime($latest['date'])) :
                                        (isset($latest['week']) ? 'Week of ' . date('M j', strtotime($latest['week'])) : '');
                            if ($dateStr) $popupDate = 'Last: ' . $dateStr;
                        } elseif ($data['earned_at']) {
                            $popupDate = date('M j, Y', strtotime($data['earned_at']));
                        }
                        if ($key === 'elite_roster' && isset($data['metadata']['win_pct'])) {
                            $popupDate .= ($popupDate ? ' · ' : '') . $data['metadata']['win_pct'] . '% win rate (' . $data['metadata']['games'] . ' games)';
                        }
                        if (in_array($key, ['weeks_lead_10','weeks_lead_15','weeks_lead_20']) && isset($data['metadata']['weeks_led'])) {
                            $popupDate .= ($popupDate ? ' · ' : '') . $data['metadata']['weeks_led'] . ' weeks (' . ($data['metadata']['days_led'] ?? '?') . ' days in first)';
                        }
                        if ($key === 'comeback_20' && isset($data['metadata']['max_deficit'])) {
                            $popupDate .= ($popupDate ? ' · ' : '') . 'Max deficit: ' . $data['metadata']['max_deficit'] . ' wins';
                        }
                        if ($def['repeatable'] && $data['times_earned'] > 1) {
                            $popupDate .= ($popupDate ? ' · ' : '') . 'Earned ' . $data['times_earned'] . 'x';
                        }
                    }

                    $iconBg = $earned
                        ? 'background: ' . $def['color'] . '22; color: ' . $def['color'] . ';'
                        : 'background: rgba(255,255,255,0.04); color: #555;';
                ?>
                <div class="badge-tile <?= $isEarned ?>"
                     onclick="openBadgePopup(this)"
                     data-badge-name="<?= htmlspecialchars($def['name']) ?>"
                     data-badge-desc="<?= htmlspecialchars($def['desc']) ?>"
                     data-badge-date="<?= htmlspecialchars($popupDate) ?>"
                     data-badge-icon="<?= htmlspecialchars($def['icon']) ?>"
                     data-badge-color="<?= htmlspecialchars($def['color']) ?>"
                     data-badge-glow="<?= htmlspecialchars($def['glow']) ?>"
                     data-badge-earned="<?= $earned ? '1' : '0' ?>"
                     data-badge-key="<?= htmlspecialchars($key) ?>"
                     data-badge-streak="<?= ($earned && isset($data['metadata']['current_streak'])) ? (int)$data['metadata']['current_streak'] : 0 ?>"
                     data-badge-streak-type="<?= ($earned && isset($data['metadata']['current_type'])) ? htmlspecialchars($data['metadata']['current_type']) : '' ?>"
                     data-badge-streak-opp="<?= ($earned && isset($data['metadata']['current_opp_name'])) ? htmlspecialchars($data['metadata']['current_opp_name']) : '' ?>"
                     <?php if (!$earned && isset($badgeProgress[$key])): ?>
                     data-badge-progress="<?= htmlspecialchars(json_encode($badgeProgress[$key])) ?>"
                     <?php endif; ?>
                     style="<?= $earned ? '--badge-glow: ' . $def['glow'] . ';' : '' ?>">
                    <div class="badge-icon-wrap" style="<?= $iconBg ?>">
                        <?php if (str_starts_with($def['icon'], 'img:')): ?>
                            <img src="../public/assets/league_logos/<?= htmlspecialchars(substr($def['icon'], 4)) ?>" 
                                 alt="" class="badge-icon-img">
                        <?php else: ?>
                            <i class="fas <?= $def['icon'] ?>"></i>
                        <?php endif; ?>
                    </div>
                    <span class="badge-name"><?= htmlspecialchars($def['name']) ?></span>
                    <?php if ($earned && $def['repeatable'] && $data['times_earned'] > 1): ?>
                        <span class="badge-count">×<?= $data['times_earned'] ?></span>
                    <?php endif; ?>
                    <?php
                        // Show progress bar only on the single next unearned streak/bully badge.
                        // Show progress bar on the badge matching the current streak range.
                        // Works on earned AND unearned — streak of 7 shows 7/10 on On Fire even if earned.
                        $isWinStreak  = in_array($key, ['win_streak_5','win_streak_10','win_streak_15']);
                        $isLossStreak = in_array($key, ['loss_streak_5','loss_streak_10','loss_streak_15']);
                        $isBully      = in_array($key, ['bully_5','bully_10','bully_15']);

                        $showStreakProgress = isset($badgeProgress[$key]) && (
                            ($isWinStreak  && $key === $winStreakTarget)  ||
                            ($isLossStreak && $key === $lossStreakTarget) ||
                            ($isBully      && $key === $bullyStreakTarget)
                        );
                        $showNormalProgress = !$earned && !$isWinStreak && !$isLossStreak && !$isBully && isset($badgeProgress[$key]);

                        if ($showStreakProgress):
                            $prog = $badgeProgress[$key];
                            $pct  = min(100, round(($prog['current'] / max(1, $prog['target'])) * 100));
                            $streakProgressLabel = $prog['current'] . ' / ' . $prog['target'];
                            if ($isBully && !empty($prog['opp_name'])) {
                                $streakProgressLabel .= ' vs ' . $prog['opp_name'];
                            }
                    ?>
                        <div class="badge-progress-bar-wrap">
                            <div class="badge-progress-bar-fill" style="width:<?= $pct ?>%"></div>
                        </div>
                        <span class="badge-progress-label"><?= htmlspecialchars($streakProgressLabel) ?></span>
                    <?php elseif ($showNormalProgress):
                            $prog = $badgeProgress[$key];
                            $pct  = min(100, round(($prog['current'] / max(1, $prog['target'])) * 100));
                    ?>
                        <div class="badge-progress-bar-wrap">
                            <div class="badge-progress-bar-fill" style="width:<?= $pct ?>%"></div>
                        </div>
                        <span class="badge-progress-label"><?= htmlspecialchars($prog['label']) ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Trophy Case: compact card matching badge category style -->
        <div class="badge-category badge-cat-trophies">
            <div class="badge-category-label">
                Trophy Case
            </div>
            <?php if (empty($pastChampionships)): ?>
                <div style="text-align:center; padding:18px 8px; color:var(--text-muted); font-size:0.78rem; font-style:italic;">
                    <i class="fas fa-trophy" style="font-size:1.4rem; opacity:0.15; display:block; margin-bottom:8px;"></i>
                    No titles yet
                </div>
            <?php else: ?>
                <?php foreach ($pastChampionships as $champ):
                    $teamsData = json_decode($champ['teams_json'], true) ?: [];
                    $combinedWins = $champ['total_regular_season_wins'] + ($champ['total_playoff_wins'] ?? 0);
                ?>
                <div class="trophy-entry">
                    <div class="trophy-entry-top">
                        <i class="fas fa-trophy" style="color:#FFD700; font-size:0.9rem; filter:drop-shadow(0 0 4px rgba(255,215,0,0.35));"></i>
                        <div class="trophy-entry-season"><?= htmlspecialchars($champ['season']) ?></div>
                        <div class="trophy-entry-wins"><?= $combinedWins ?>W</div>
                    </div>
                    <?php if ($champ['nba_champion_team']): ?>
                        <div class="trophy-entry-champ">
                            <i class="fas fa-crown" style="font-size:0.55rem; margin-right:3px;"></i><?= htmlspecialchars($champ['nba_champion_team']) ?>
                        </div>
                    <?php endif; ?>
                    <div class="trophy-entry-logos">
                        <?php foreach ($teamsData as $ct): ?>
                            <img src="<?= htmlspecialchars(getTeamLogo($ct['team_name'])) ?>"
                                 alt="" class="trophy-team-logo"
                                 title="<?= htmlspecialchars($ct['team_name']) ?> (<?= $ct['wins'] ?>-<?= $ct['losses'] ?>)"
                                 onerror="this.style.display='none'">
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div><!-- /.badges-tab-grid -->
    </div><!-- /#tab-badges -->

</div><!-- /.app-container -->


<!-- ====================================================================
     PHOTO OPTIONS MODAL
     ==================================================================== -->
<?php if ($is_own_profile): ?>
    <div id="photoOptionsModal" class="photo-options-modal">
        <div class="photo-options-content">
            <h3>Profile Photo</h3>
            <img src="<?= htmlspecialchars($profile_photo_url) ?>" alt=""
                 class="photo-preview"
                 onerror="this.src='../public/assets/profile_photos/default.png'">
            <div class="photo-options-buttons">
                <button type="button" class="photo-option-btn primary" onclick="triggerPhotoUpload()">
                    <i class="fas fa-camera"></i> Upload New Photo
                </button>
                <?php if ($participant['profile_photo']): ?>
                    <button type="button" class="photo-option-btn danger" onclick="deletePhoto()">
                        <i class="fas fa-trash"></i> Delete Photo
                    </button>
                <?php endif; ?>
                <button type="button" class="photo-option-btn" onclick="closePhotoOptions()">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
            <div style="margin-top: 12px; font-size: 0.75rem; color: var(--text-muted)">
                JPEG, PNG, GIF, WebP – Max 5MB
            </div>
        </div>
    </div>
<?php endif; ?>


<!-- ====================================================================
     JAVASCRIPT
     ==================================================================== -->
<script>
// --- Edit Name Form ---
function toggleEditForm() {
    const f = document.getElementById('editForm');
    if (f.classList.contains('hidden')) {
        f.classList.remove('hidden');
        f.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => {
            const i = document.getElementById('display_name');
            if (i) { i.focus(); i.select(); }
        }, 300);
    } else {
        f.classList.add('hidden');
    }
}

// --- Photo Options Modal ---
function showPhotoOptions() {
    const m = document.getElementById('photoOptionsModal');
    if (m) { m.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
}

function closePhotoOptions() {
    const m = document.getElementById('photoOptionsModal');
    if (m) { m.style.display = 'none'; document.body.style.overflow = 'auto'; }
}

function triggerPhotoUpload() {
    document.getElementById('profile_photo')?.click();
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
            alert('File too large. Max 5MB.');
            input.value = '';
            return;
        }
        if (!['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(f.type)) {
            alert('Invalid file type.');
            input.value = '';
            return;
        }
        closePhotoOptions();
        document.getElementById('photoUploadForm').submit();
    }
}

// --- Auto-Draft Toggle ---
function confirmAutoDraftToggle(cb) {
    const form = document.getElementById('autoDraftForm');
    if (cb.checked) {
        const hasPreferences = <?php
            $stmt = $pdo->prepare("SELECT COUNT(*) AS count FROM user_draft_preferences WHERE user_id = ?");
            $stmt->execute([$user_id]);
            echo $stmt->fetch()['count'] == 30 ? 'true' : 'false';
        ?>;
        if (!hasPreferences) {
            if (confirm('Set team rankings first?\n\nWithout rankings, random teams will be selected.')) {
                window.location.href = '/nba-wins-platform/profiles/draft_preferences.php?league_id=<?= $league_id ?>&user_id=<?= $user_id ?>';
                return;
            }
        }
        form.submit();
    } else {
        form.submit();
    }
}

// --- Gear Panel Toggle ---
function toggleGearPanel() {
    const btn = document.getElementById('gearBtn');
    const panel = document.getElementById('gearPanel');
    btn.classList.toggle('open');
    panel.classList.toggle('open');
}
// Close gear panel on click outside
document.addEventListener('click', function(e) {
    const wrapper = document.querySelector('.gear-settings-wrapper');
    if (wrapper && !wrapper.contains(e.target)) {
        document.getElementById('gearBtn')?.classList.remove('open');
        document.getElementById('gearPanel')?.classList.remove('open');
    }
});

// --- Theme Toggle ---
function setTheme(theme) {
    document.getElementById('themeInput').value = theme;
    document.getElementById('themeForm').submit();
}

// --- Character Counter & Modal Click-Outside ---
document.addEventListener('DOMContentLoaded', function () {
    const di = document.getElementById('display_name');
    const cc = document.getElementById('charCount');

    if (di && cc) {
        function updateCount() {
            cc.textContent = di.value.length + '/20';
            cc.style.color = di.value.length > 20 ? 'var(--accent-red)' : 'var(--text-muted)';
        }
        di.addEventListener('input', updateCount);
        updateCount();
    }

    // Close photo modal on background click
    const m = document.getElementById('photoOptionsModal');
    if (m) {
        m.addEventListener('click', function (e) {
            if (e.target === m) closePhotoOptions();
        });
    }
});

// Hide edit form after successful update
<?php if (!empty($success_message)): ?>
document.addEventListener('DOMContentLoaded', function () {
    const f = document.getElementById('editForm');
    if (f) f.classList.add('hidden');
});
<?php endif; ?>

// --- Widget Pin Toggle ---
function toggleWidgetPin(w, b) {
    const p = b.classList.contains('pinned');
    if (!p && !confirm('Pin to homepage?')) return;

    const fd = new FormData();
    fd.append('action', p ? 'unpin' : 'pin');
    fd.append('widget_type', w);

    fetch('/nba-wins-platform/core/handle_widget_pin.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert(d.message);
            window.location.reload();
        } else {
            alert('Error: ' + d.error);
        }
    })
    .catch(() => alert('Error. Try again.'));
}

// Cascade-in animation for all boxes
document.addEventListener('DOMContentLoaded', function() {
    const items = document.querySelectorAll('.profile-header, .stats-card');
    items.forEach(function(el, i) {
        el.classList.add('cascade-item');
        el.style.animationDelay = (i * 80) + 'ms';
    });
});
</script>
    <script>
    function getActiveTab() {
        var active = document.querySelector('.tab-panel.active');
        return active ? active.id.replace('tab-', '') : 'main';
    }
    function switchTab(tabName, btn) {
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.profile-tab').forEach(b => b.classList.remove('active'));
        document.getElementById('tab-' + tabName).classList.add('active');
        btn.classList.add('active');
    }
    // Restore tab from URL ?tab= param
    (function() {
        var params = new URLSearchParams(window.location.search);
        var tab = params.get('tab');
        if (tab && tab !== 'main') {
            var btn = document.querySelector('.profile-tab[onclick*="' + tab + '"]');
            if (btn) switchTab(tab, btn);
        }
    })();
    </script>


    <?php $currentPage = 'profile'; include '/data/www/default/nba-wins-platform/components/pill_nav.php'; ?>

<!-- Badge Modal -->
<div id="badge-modal-overlay" onclick="closeBadgePopup(event)">
    <div id="badge-modal">
        <button id="badge-modal-close" onclick="closeBadgePopup(null)">&times;</button>
        <div id="badge-modal-icon-wrap">
            <i id="badge-modal-icon-i" class="fas"></i>
        </div>
        <div id="badge-modal-name"></div>
        <div id="badge-modal-desc"></div>
        <div id="badge-modal-date"></div>
        <div id="badge-modal-streak" style="display:none; font-size:0.75rem; color:#9ca3af; margin-top:6px; text-align:center;"></div>
        <div id="badge-modal-progress" style="display:none; width:100%; margin-top:10px;">
            <div style="width:100%; height:6px; background:rgba(255,255,255,0.08); border-radius:3px; overflow:hidden;">
                <div id="badge-modal-progress-fill" style="height:100%; border-radius:3px; background:linear-gradient(90deg,#4b82f8,#818cf8); transition:width 0.4s ease;"></div>
            </div>
            <div id="badge-modal-progress-label" style="font-size:0.75rem; color:#6b7280; margin-top:6px; text-align:center;"></div>
        </div>
    </div>
</div>
<script>
function openBadgePopup(el) {
    var earned   = el.dataset.badgeEarned === '1';
    var color    = el.dataset.badgeColor;
    var glow     = el.dataset.badgeGlow;
    var icon     = el.dataset.badgeIcon;
    var name     = el.dataset.badgeName;
    var desc     = el.dataset.badgeDesc;
    var date     = el.dataset.badgeDate;
    var progData = el.dataset.badgeProgress ? JSON.parse(el.dataset.badgeProgress) : null;

    var wrap = document.getElementById('badge-modal-icon-wrap');
    var iconEl = document.getElementById('badge-modal-icon-i');
    var isImgBadge = icon && icon.indexOf('img:') === 0;

    // Remove any previous image badge
    var prevImg = wrap.querySelector('.badge-modal-img');
    if (prevImg) prevImg.remove();

    if (isImgBadge) {
        // Image-based badge
        iconEl.style.display = 'none';
        var imgEl = document.createElement('img');
        imgEl.src = '../public/assets/league_logos/' + icon.substring(4);
        imgEl.className = 'badge-modal-img';
        imgEl.alt = '';
        wrap.appendChild(imgEl);
        wrap.style.background = earned ? color + '22' : 'rgba(255,255,255,0.05)';
        wrap.style.boxShadow = earned ? '0 0 18px ' + glow : 'none';
        wrap.style.color = '';
    } else if (earned) {
        iconEl.style.display = '';
        wrap.style.background = color + '22';
        wrap.style.color = color;
        wrap.style.boxShadow = '0 0 18px ' + glow;
        iconEl.className = 'fas ' + icon;
    } else {
        iconEl.style.display = '';
        wrap.style.background = 'rgba(255,255,255,0.05)';
        wrap.style.color = '#555';
        wrap.style.boxShadow = 'none';
        iconEl.className = 'fas ' + icon;
    }

    document.getElementById('badge-modal-name').textContent = name;
    document.getElementById('badge-modal-desc').textContent = desc;

    var dateEl = document.getElementById('badge-modal-date');
    var streakKeys = ['win_streak_5','win_streak_10','win_streak_15','loss_streak_5','loss_streak_10','loss_streak_15','bully_5','bully_10','bully_15'];
    var bullyKeys  = ['bully_5','bully_10','bully_15'];
    var badgeKey   = el.dataset.badgeKey || '';
    var curStreak  = parseInt(el.dataset.badgeStreak) || 0;
    var curType    = el.dataset.badgeStreakType || '';
    var curOpp     = el.dataset.badgeStreakOpp || '';
    var progData   = el.dataset.badgeProgress ? JSON.parse(el.dataset.badgeProgress) : null;

    if (earned && date) {
        dateEl.textContent = date;
        dateEl.className = 'earned-date';
        dateEl.style.color = color;
        dateEl.style.background = color + '18';
    } else if (!earned) {
        dateEl.textContent = 'Not yet earned';
        dateEl.className = 'not-earned';
        dateEl.style.color = '';
        dateEl.style.background = '';
    } else {
        dateEl.textContent = '';
        dateEl.className = '';
    }

    // Active streak — own line, no emoji
    var streakEl = document.getElementById('badge-modal-streak');
    if (earned && curStreak > 0 && streakKeys.indexOf(badgeKey) !== -1) {
        var isBullyBadge = bullyKeys.indexOf(badgeKey) !== -1;
        var streakType   = isBullyBadge ? 'H2H' : (curType === 'W' ? 'win' : 'loss');
        var streakText   = curStreak + ' game active ' + streakType + ' streak';
        if (isBullyBadge && curOpp) {
            streakText += ' vs ' + curOpp;
        }
        streakEl.textContent = streakText;
        streakEl.style.display = 'block';
    } else {
        streakEl.style.display = 'none';
    }

    // Progress bar
    var progWrap = document.getElementById('badge-modal-progress');
    if (!earned && progData) {
        var pct = Math.min(100, Math.round((progData.current / Math.max(1, progData.target)) * 100));
        document.getElementById('badge-modal-progress-fill').style.width = pct + '%';
        document.getElementById('badge-modal-progress-label').textContent = progData.label;
        progWrap.style.display = 'block';
    } else {
        progWrap.style.display = 'none';
    }

    document.getElementById('badge-modal-overlay').classList.add('open');

    // Re-trigger coin flip on every open
    var wrap = document.getElementById('badge-modal-icon-wrap');
    wrap.style.animation = 'none';
    wrap.offsetHeight; // force reflow
    wrap.style.animation = '';
}
function closeBadgePopup(e) {
    if (e && e.target !== document.getElementById('badge-modal-overlay')) return;
    document.getElementById('badge-modal-overlay').classList.remove('open');
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.getElementById('badge-modal-overlay').classList.remove('open');
});
</script>
</body>
</html>