<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
// draft.php - Simplified draft interface with 30-pick completion check
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['current_league_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once '/data/www/default/nba-wins-platform/config/db_connection.php';
require_once '/data/www/default/nba-wins-platform/core/DraftManager.php';

$user_id = $_SESSION['user_id'];
$league_id = $_SESSION['current_league_id'];

$user_id = $_SESSION['user_id'];
$league_id = $_SESSION['current_league_id'];
$currentLeagueId = $league_id; // Define for navigation menu

// Logo path helper function (matching index.php exactly)
function fixLogoPath($logoPath) {
    // If it's already a local path, make it use the new structure
    if (strpos($logoPath, '/media/') === 0) {
        return 'nba-wins-platform/public/assets/team_logos/' . basename($logoPath);
    }
    if (strpos($logoPath, 'nba-wins-platform/public/assets/') === 0) {
        return $logoPath;
    }
    // If it's just a filename, prepend the assets path
    return 'nba-wins-platform/public/assets/team_logos/' . basename($logoPath);
}

// Get league info
$stmt = $pdo->prepare("SELECT * FROM leagues WHERE id = ?");
$stmt->execute([$league_id]);
$league = $stmt->fetch();

if (!$league) {
    die("League not found");
}

// SIMPLE COMPLETION CHECK - Just check if 30 picks have been made
$stmt = $pdo->prepare("
    SELECT COUNT(dp.id) as total_picks
    FROM draft_sessions ds
    LEFT JOIN draft_picks dp ON ds.id = dp.draft_session_id
    WHERE ds.league_id = ?
    ORDER BY ds.created_at DESC
    LIMIT 1
");
$stmt->execute([$league_id]);
$pick_count_result = $stmt->fetch();
$total_picks = $pick_count_result ? $pick_count_result['total_picks'] : 0;

// Simple redirect: If 30 picks made, go to summary
if ($total_picks >= 30) {
    error_log("Draft completed: $total_picks picks made, redirecting to summary");
    header('Location: draft_summary_new.php');
    exit;
}

$draftManager = new DraftManager($pdo);
$draft_status = $draftManager->getDraftStatus($league_id);

// Get user info
$stmt = $pdo->prepare("
    SELECT lp.id as participant_id, lp.participant_name, 
           l.commissioner_user_id, u.display_name
    FROM league_participants lp
    JOIN leagues l ON lp.league_id = l.id
    JOIN users u ON lp.user_id = u.id
    WHERE lp.user_id = ? AND lp.league_id = ?
");
$stmt->execute([$user_id, $league_id]);
$user_info = $stmt->fetch();

$is_commissioner = $league['commissioner_user_id'] == $user_id || $league['commissioner_user_id'] === null;

// Get draft log if draft exists
$draft_log = [];
if ($draft_status['status'] !== 'not_started') {
    try {
        $stmt = $pdo->prepare("
            SELECT ds.id FROM draft_sessions ds 
            WHERE ds.league_id = ? 
            ORDER BY ds.created_at DESC LIMIT 1
        ");
        $stmt->execute([$league_id]);
        $session = $stmt->fetch();
        
        if ($session) {
            $stmt = $pdo->prepare("
                SELECT dl.*, u.display_name as participant_name, t.team_name
                FROM draft_log dl
                LEFT JOIN league_participants lp ON dl.league_participant_id = lp.id
                LEFT JOIN users u ON lp.user_id = u.id
                LEFT JOIN draft_picks dp ON dl.draft_session_id = dp.draft_session_id AND dl.league_participant_id = dp.league_participant_id
                LEFT JOIN teams t ON dp.team_id = t.id
                WHERE dl.draft_session_id = ?
                ORDER BY dl.created_at DESC
                LIMIT 50
            ");
            $stmt->execute([$session['id']]);
            $draft_log = $stmt->fetchAll();
        }
    } catch (Exception $e) {
        error_log("Error fetching draft log: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="theme-color" content="<?= ($_SESSION['theme_preference'] ?? 'dark') === 'classic' ? '#f5f5f5' : '#0d1117' ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <title>Live Draft - <?= htmlspecialchars($league['display_name']) ?></title>
    <link rel="apple-touch-icon" type="image/png" href="nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- React and Babel for Navigation Component -->
    <script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
    <style>
        :root {
            /* Core backgrounds */
            --bg-primary: #0d1117;
            --bg-secondary: #121a23;
            --bg-card: #161e28;
            --bg-elevated: #1c2634;
            --bg-card-hover: #1e2a3a;

            /* Text */
            --text-primary: #e6edf3;
            --text-secondary: #8b949e;
            --text-muted: #546070;

            /* Borders */
            --border-color: rgba(255, 255, 255, 0.08);
            --border-subtle: rgba(255, 255, 255, 0.04);

            /* Accents */
            --accent-blue: #388bfd;
            --accent-blue-dim: rgba(56, 139, 253, 0.10);
            --accent-green: #3fb950;
            --accent-green-dim: rgba(63, 185, 80, 0.10);
            --accent-red: #f85149;
            --accent-red-dim: rgba(248, 81, 73, 0.10);
            --accent-orange: #d29922;
            --accent-orange-dim: rgba(210, 153, 34, 0.12);
            --accent-gold: #f0c644;
            --accent-silver: #a0aec0;
            --accent-bronze: #cd7f32;

            /* Shadows */
            --shadow-card: 0 1px 3px rgba(0,0,0,0.4), 0 0 0 1px var(--border-color);
            --shadow-elevated: 0 4px 12px rgba(0,0,0,0.5);

            /* Radius & Transitions */
            --radius-sm: 6px;
            --radius-md: 8px;
            --radius-lg: 10px;
            --transition-fast: 0.15s ease;
            --transition-normal: 0.25s ease;
        }

        <?php if (($_SESSION['theme_preference'] ?? 'dark') === 'classic'): ?>
        :root {
            --bg-primary: #f5f5f5;
            --bg-secondary: rgba(245, 245, 245, 0.95);
            --bg-card: #ffffff;
            --bg-elevated: #f0f0f2;
            --bg-card-hover: #f8f9fa;
            --text-primary: #333333;
            --text-secondary: #666666;
            --text-muted: #999999;
            --border-color: #e0e0e0;
            --border-subtle: rgba(0, 0, 0, 0.06);
            --accent-blue: #0066ff;
            --accent-blue-dim: rgba(0, 102, 255, 0.08);
            --accent-green: #28a745;
            --accent-green-dim: rgba(40, 167, 69, 0.08);
            --accent-red: #dc3545;
            --accent-red-dim: rgba(220, 53, 69, 0.08);
            --accent-orange: #d4a017;
            --accent-orange-dim: rgba(212, 160, 23, 0.08);
            --accent-gold: #d4a017;
            --accent-silver: #8a8a8a;
            --accent-bronze: #b5651d;
            --shadow-card: 0 1px 4px rgba(0, 0, 0, 0.08), 0 0 0 1px rgba(0, 0, 0, 0.04);
            --shadow-elevated: 0 4px 16px rgba(0, 0, 0, 0.1), 0 0 0 1px rgba(0, 0, 0, 0.06);
        }
        body {
            background-image: url('nba-wins-platform/public/assets/background/geometric_white.png');
            background-repeat: repeat;
            background-attachment: fixed;
        }
        <?php endif; ?>

        * { box-sizing: border-box; }

        html {
            height: -webkit-fill-available;
            background-color: var(--bg-primary);
        }
        
        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 10px;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            min-height: -webkit-fill-available;
            -webkit-font-smoothing: antialiased;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            margin-bottom: 16px;
            background: var(--bg-card);
            padding: 20px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
        }
        
        .basketball-logo {
            max-width: 56px;
            margin-bottom: 10px;
        }
        
        h1 {
            margin: 8px 0;
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-primary);
            letter-spacing: -0.02em;
        }
        
        h2 {
            margin: 4px 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .header p {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin: 4px 0 0;
        }
        
        /* Draft Controls */
        .draft-controls {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius-sm);
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-fast);
            text-decoration: none;
            display: inline-block;
            font-size: 0.9rem;
            font-family: 'Outfit', sans-serif;
        }
        
        .btn-primary { background: var(--accent-green); color: #fff; }
        .btn-danger  { background: var(--accent-red); color: #fff; }
        .btn-warning { background: var(--accent-orange); color: #fff; }
        .btn-secondary { background: var(--bg-elevated); color: var(--text-primary); border: 1px solid var(--border-color); }
        .btn-success { background: var(--accent-green); color: #fff; }
        
        .btn:hover { 
            transform: translateY(-1px); 
            box-shadow: 0 4px 12px rgba(0,0,0,0.4); 
            filter: brightness(1.1);
        }
        .btn:disabled { 
            opacity: 0.5; 
            cursor: not-allowed; 
            transform: none; 
            filter: none;
        }
        
        /* Draft Status Bar */
        .draft-status {
            background: var(--bg-card);
            padding: 14px;
            border-radius: var(--radius-lg);
            margin-bottom: 16px;
            text-align: center;
            box-shadow: var(--shadow-card);
        }
        
        .draft-status h2 {
            font-size: 1.1rem;
            margin: 4px 0;
            color: var(--text-secondary);
        }

        .draft-status h3 {
            font-size: 1.05rem;
            margin: 4px 0;
            color: var(--text-primary);
            font-weight: 700;
        }
        
        .current-pick {
            font-size: 1.15rem;
            margin-bottom: 8px;
            color: var(--accent-orange);
            font-weight: 700;
        }
        
        /* Draft Board Grid */
        .draft-board {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }
        
        @media (max-width: 768px) {
            .draft-board { grid-template-columns: 1fr; }
        }
        
        .available-teams, .draft-order, .recent-picks {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 16px;
            box-shadow: var(--shadow-card);
        }
        
        .available-teams { max-height: 600px; overflow-y: auto; }
        
        .available-teams h3, .draft-order h3, .recent-picks h3 {
            color: var(--text-primary);
            margin: 0 0 14px;
            font-size: 1rem;
            font-weight: 700;
        }

        /* Custom scrollbar for dark theme */
        .available-teams::-webkit-scrollbar,
        .order-list::-webkit-scrollbar,
        .picks-list::-webkit-scrollbar {
            width: 6px;
        }
        .available-teams::-webkit-scrollbar-track,
        .order-list::-webkit-scrollbar-track,
        .picks-list::-webkit-scrollbar-track {
            background: transparent;
        }
        .available-teams::-webkit-scrollbar-thumb,
        .order-list::-webkit-scrollbar-thumb,
        .picks-list::-webkit-scrollbar-thumb {
            background: var(--text-muted);
            border-radius: 3px;
        }
        
        /* Team Grid */
        .team-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 10px;
            margin-top: 12px;
        }
        
        .team-card {
            background: var(--bg-elevated);
            padding: 12px;
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all var(--transition-fast);
            text-align: center;
            border: 2px solid var(--border-color);
            position: relative;
            min-height: 115px;
        }
        
        .team-card:hover {
            background: var(--bg-card-hover);
            transform: translateY(-1px);
            box-shadow: var(--shadow-elevated);
            border-color: rgba(56, 139, 253, 0.25);
        }
        
        .team-card.selected {
            border-color: var(--accent-green);
            background: var(--accent-green-dim);
            padding-bottom: 55px;
        }
        
        .team-card.disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
        .team-card.disabled:hover {
            transform: none;
            box-shadow: var(--shadow-card);
            border-color: var(--border-color);
        }
        
        .team-logo {
            width: 40px;
            height: 40px;
            margin: 0 auto 8px;
            border-radius: 50%;
            display: block;
        }
        
        .team-name { 
            font-weight: 600; 
            margin-bottom: 3px;
            color: var(--text-primary);
            font-size: 0.85rem;
        }
        .team-abbr { 
            font-size: 0.8rem; 
            color: var(--text-muted);
        }
        
        /* Selection buttons on card */
        .team-selection-buttons {
            position: absolute;
            bottom: 8px;
            left: 8px;
            right: 8px;
            display: none;
            gap: 5px;
            flex-direction: column;
        }
        
        .team-card.selected .team-selection-buttons {
            display: flex;
        }
        
        .team-selection-btn {
            padding: 6px 12px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-fast);
            font-family: 'Outfit', sans-serif;
        }
        
        .team-selection-btn.confirm {
            background: var(--accent-green);
            color: #fff;
        }
        
        .team-selection-btn.clear {
            background: var(--bg-elevated);
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }
        
        .team-selection-btn:hover {
            filter: brightness(1.15);
            transform: translateY(-1px);
        }
        
        /* Order & Picks Lists */
        .order-list, .picks-list {
            list-style: none;
            padding: 0;
            margin: 0;
            max-height: 600px;
            overflow-y: auto;
        }
        
        .recent-picks {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 16px;
            box-shadow: var(--shadow-card);
        }
        
        .recent-picks h3 {
            color: var(--accent-orange) !important;
            font-size: 1.05rem !important;
            margin-bottom: 14px !important;
            text-align: center;
            font-weight: 700;
        }
        
        .order-item, .pick-item {
            padding: 10px 12px;
            margin: 6px 0;
            background: var(--bg-elevated);
            border-radius: var(--radius-sm);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid var(--border-color);
            font-size: 0.9rem;
            color: var(--text-primary);
        }
        
        .order-item.current {
            background: var(--accent-orange-dim);
            border: 2px solid var(--accent-orange);
            animation: currentGlow 2s infinite;
        }
        
        @keyframes currentGlow {
            0%, 100% { box-shadow: 0 0 5px rgba(210, 153, 34, 0.2); }
            50% { box-shadow: 0 0 14px rgba(210, 153, 34, 0.4); }
        }
        
        .pick-number {
            background: var(--accent-blue);
            color: #fff;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        
        .pick-team-info {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .pick-team-logo {
            width: 28px;
            height: 28px;
            border-radius: 50%;
        }

        .pick-team-info strong {
            color: var(--text-primary);
            font-size: 0.85rem;
        }

        .pick-team-info small {
            color: var(--text-muted);
            font-size: 0.75rem;
        }
        
        /* Last Pick Banner */
        .last-pick-display {
            background: var(--bg-card);
            border: 2px solid var(--accent-orange);
            border-radius: var(--radius-lg);
            padding: 16px;
            margin-bottom: 16px;
            text-align: center;
            box-shadow: var(--shadow-card);
            display: none;
        }
        
        .last-pick-display.show {
            display: block;
            animation: fadeInSlide 0.5s ease-out;
        }
        
        @keyframes fadeInSlide {
            from { opacity: 0; transform: translateY(-16px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .last-pick-content {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
        }
        
        .last-pick-logo {
            width: 56px;
            height: 56px;
            border-radius: 50%;
        }
        
        .last-pick-info h4 {
            margin: 0 0 4px;
            color: var(--accent-orange);
            font-size: 1rem;
            font-weight: 700;
        }
        
        .last-pick-info .team-name {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 2px;
        }
        
        .last-pick-info .participant-name {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        
        /* Notification toasts */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: var(--radius-md);
            color: #fff;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            z-index: 1000;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            box-shadow: var(--shadow-elevated);
        }
        
        .notification.show { transform: translateX(0); }
        .notification.success { background: var(--accent-green); }
        .notification.error   { background: var(--accent-red); }
        .notification.warning { background: var(--accent-orange); }
        .notification.info    { background: var(--accent-blue); }
        
        /* Loading state */
        .loading {
            text-align: center;
            padding: 40px;
            font-size: 1rem;
            color: var(--text-muted);
        }
        
        .spinner {
            border: 3px solid var(--border-color);
            border-top: 3px solid var(--accent-orange);
            border-radius: 50%;
            width: 36px;
            height: 36px;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Commissioner Controls */
        .commissioner-controls {
            background: var(--accent-orange-dim);
            border: 1px solid rgba(210, 153, 34, 0.3);
            border-radius: var(--radius-lg);
            padding: 16px;
            margin-top: 30px;
        }
        
        .commissioner-controls h3 {
            color: var(--accent-orange);
            margin: 0 0 14px;
            font-size: 1rem;
            font-weight: 700;
        }
        
        /* Navigation overrides for dark theme */
        .menu-container {
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
        }
        
        .menu-button {
            position: fixed;
            top: 5.5rem;
            left: 1rem;
            background: var(--bg-elevated);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0.5rem;
            cursor: pointer;
            z-index: 1002;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-card);
        }
        
        .menu-button:hover { background: var(--bg-card-hover); }
        
        .menu-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1001;
        }
        
        .menu-panel {
            position: fixed;
            top: 0;
            left: -300px;
            width: 300px;
            height: 100vh;
            background: var(--bg-card);
            box-shadow: 4px 0 20px rgba(0,0,0,0.5);
            transition: left 0.3s ease;
            z-index: 1002;
        }
        
        .menu-panel.menu-open { left: 0; }
        
        .menu-header {
            padding: 1rem;
            display: flex;
            justify-content: flex-end;
            border-bottom: 1px solid var(--border-color);
        }
        
        .close-button {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 0.5rem;
        }
        .close-button:hover { color: var(--text-primary); }
        
        .menu-content { padding: 1rem; }
        .menu-list { list-style: none; padding: 0; margin: 0; }
        
        .menu-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.85rem 1rem;
            color: var(--text-secondary);
            text-decoration: none;
            transition: all var(--transition-fast);
            border-radius: var(--radius-sm);
        }
        
        .menu-link:hover {
            background: var(--bg-elevated);
            color: var(--text-primary);
        }
        
        .menu-link i { width: 20px; }
        
        /* Mobile adjustments */
        @media (max-width: 600px) {
            .container { padding: 12px; }
            
            h1 { font-size: 1.35rem; }
            
            .current-pick { font-size: 1.1rem; }
            
            .team-grid {
                grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
                gap: 8px;
            }
            
            .team-card {
                padding: 10px;
                min-height: 105px;
            }
            
            .team-card.selected { padding-bottom: 50px; }
            
            .team-name { font-size: 0.78rem; }
            .team-abbr { font-size: 0.72rem; }
            
            .order-item, .pick-item {
                padding: 8px 10px;
                font-size: 0.82rem;
            }
            
            .btn { padding: 8px 16px; font-size: 0.82rem; }
            .team-selection-btn { padding: 5px 10px; font-size: 0.7rem; }
            
            .last-pick-content { flex-direction: column; gap: 8px; }
            .last-pick-logo { width: 48px; height: 48px; }
        }
        
        @media (min-width: 601px) {
            .container { max-width: 1200px; padding: 24px; }
        }
    </style>
</head>
<body>
    <?php 
    // Include the navigation menu component (dark theme version)
    $navFile = $_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/components/navigation_menu_new.php';
    if (file_exists($navFile)) {
        include $navFile;
    } else {
        include $_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/components/navigation_menu.php';
    }
    ?>
    
    <div class="container">
        <div class="header">
            <img src="nba-wins-platform/public/assets/team_logos/Logo.png" alt="NBA Logo" class="basketball-logo">
            <h1>Live Draft</h1>
            <h2><?= htmlspecialchars($league['display_name']) ?></h2>
            <p>Welcome, <?= htmlspecialchars($user_info['display_name']) ?></p>
        </div>
        
        <div class="last-pick-display" id="lastPickDisplay">
            <h4 style="color: var(--accent-orange); margin-bottom: 8px; font-weight: 700;">Latest Pick</h4>
            <div class="last-pick-content" id="lastPickContent">
                <!-- Last pick info will be populated here -->
            </div>
        </div>
        
        <div class="draft-status" id="draftStatus">
            <div class="loading">
                <div class="spinner"></div>
                Loading draft status...
            </div>
        </div>
        
        <div class="draft-board" id="draftBoard" style="display: none;">
            <div class="available-teams">
                <h3><i class="fas fa-basketball-ball" style="color: var(--accent-orange); margin-right: 6px;"></i>Available Teams</h3>
                <div class="team-grid" id="teamGrid">
                    <!-- Teams loaded via JavaScript -->
                </div>
            </div>
            
            <div class="draft-order">
                <h3><i class="fas fa-list-ol" style="color: var(--accent-blue); margin-right: 6px;"></i>Draft Order</h3>
                <ul class="order-list" id="draftOrderList">
                    <!-- Order loaded via JavaScript -->
                </ul>
            </div>
            
            <div class="recent-picks">
                <h3><i class="fas fa-check-circle" style="color: var(--accent-orange); margin-right: 6px;"></i>Draft Picks</h3>
                <ul class="picks-list" id="recentPicksList">
                    <!-- Picks loaded via JavaScript -->
                </ul>
            </div>
        </div>
        
        <?php if ($is_commissioner): ?>
        <div class="commissioner-controls">
            <h3><i class="fas fa-shield-alt" style="margin-right: 6px;"></i>Commissioner Controls</h3>
            <div class="draft-controls">
                <button id="startDraftBtn" class="btn btn-primary">Start Draft</button>
                <button id="pauseDraftBtn" class="btn btn-warning" style="display: none;">Pause Draft</button>
                <button id="resumeDraftBtn" class="btn btn-primary" style="display: none;">Resume Draft</button>
                <button id="commissionerPickBtn" class="btn btn-secondary" style="display: none;">Make Pick for Current Player</button>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Global variables
        let selectedTeamId = null;
        let selectedTeamData = null;
        let userInfo = {};
        let pollInterval = null;
        let currentDraftStatus = null;
        let pollFrequency = 5000; // 5 seconds
        
        // Caching variables to prevent unnecessary DOM updates
        let lastTeamsData = null;
        let lastOrderData = null;
        let lastPicksData = null;
        
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Initializing simplified draft interface...');
            getUserInfo();
            pollDraftStatus();
            startPolling();
            
            // Event listeners
            document.getElementById('startDraftBtn')?.addEventListener('click', startDraft);
            document.getElementById('pauseDraftBtn')?.addEventListener('click', pauseDraft);
            document.getElementById('resumeDraftBtn')?.addEventListener('click', resumeDraft);
            document.getElementById('commissionerPickBtn')?.addEventListener('click', commissionerPick);
        });
        
        // Get current user info
        function getUserInfo() {
            fetch('nba-wins-platform/api/draft_api.php?action=get_user_info')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        userInfo = data.data;
                        console.log('User info loaded:', userInfo);
                    }
                })
                .catch(error => {
                    console.error('Error getting user info:', error);
                });
        }
        
        // Start polling for draft updates
        function startPolling() {
            if (pollInterval) {
                clearInterval(pollInterval);
            }
            pollInterval = setInterval(pollDraftStatus, pollFrequency);
            console.log(`Started polling every ${pollFrequency/1000} seconds`);
        }
        
        // Stop polling
        function stopPolling() {
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
                console.log('Stopped polling');
            }
        }
        
        // Data comparison functions to prevent unnecessary updates
        function hasTeamsChanged(newTeams) {
            if (!lastTeamsData || !newTeams) return true;
            if (lastTeamsData.length !== newTeams.length) return true;
            return false;
        }
        
        function hasOrderChanged(newOrder) {
            if (!lastOrderData || !newOrder) return true;
            if (lastOrderData.length !== newOrder.length) return true;
            
            try {
                const lastCurrent = lastOrderData.find(p => p.is_current);
                const newCurrent = newOrder.find(p => p.is_current);
                
                const currentChanged = (!lastCurrent && newCurrent) || 
                                     (lastCurrent && !newCurrent) ||
                                     (lastCurrent && newCurrent && lastCurrent.participant_id !== newCurrent.participant_id);
                
                return currentChanged;
            } catch (e) {
                return true;
            }
        }
        
        function hasPicksChanged(newPicks) {
            if (!lastPicksData || !newPicks) return true;
            if (lastPicksData.length !== newPicks.length) return true;
            
            if (newPicks.length > 0 && lastPicksData.length > 0) {
                const lastFirstPick = lastPicksData[0];
                const newFirstPick = newPicks[0];
                
                if (!lastFirstPick || !newFirstPick) return true;
                
                return (lastFirstPick.pick_number !== newFirstPick.pick_number ||
                       lastFirstPick.team_name !== newFirstPick.team_name ||
                       lastFirstPick.participant_name !== newFirstPick.participant_name);
            }
            
            return false;
        }
        
        // Check for completion
        function checkFor30PicksAndRedirect(status) {
            const pickCount = status.recent_picks ? status.recent_picks.length : 0;
            const apiPickCount = status.pick_count || 0;
            const isCompleted = status.status === 'completed';
            
            console.log('Completion check:', { pickCount, apiPickCount, isCompleted, status: status.status });
            
            if (pickCount >= 30 || apiPickCount >= 30 || isCompleted) {
                console.log('Draft completed detected, redirecting to summary...');
                stopPolling();
                window.location.href = 'draft_summary_new.php';
                return true;
            }
            return false;
        }
        
        // Poll for draft status updates
        function pollDraftStatus() {
            fetch('nba-wins-platform/api/draft_api.php?action=get_draft_status')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const status = data.data;
                        
                        if (checkFor30PicksAndRedirect(status)) return;
                        
                        updateDraftDisplay(status);
                        currentDraftStatus = status;
                    } else {
                        console.error('Draft status error:', data.error);
                    }
                })
                .catch(error => {
                    console.error('Error polling draft status:', error);
                    showNotification('Connection error. Retrying...', 'warning');
                });
        }
        
        // Update the draft display
        function updateDraftDisplay(status) {
            const statusDiv = document.getElementById('draftStatus');
            const boardDiv = document.getElementById('draftBoard');
            
            console.log('Updating draft display:', status.status);
            
            if (status.status === 'not_started') {
                statusDiv.innerHTML = `
                    <h2>Draft Not Started</h2>
                    <p style="color: var(--text-muted);">Waiting for commissioner to start the draft...</p>
                    ${userInfo.is_commissioner ? '<p style="color: var(--accent-green); font-weight: 600;">You can start the draft when ready!</p>' : ''}
                `;
                boardDiv.style.display = 'none';
                updateCommissionerControls('not_started');
            }
            else if (status.status === 'active' || status.status === 'paused') {
                const isPaused = status.status === 'paused';
                
                const pickCount = status.pick_count || (status.recent_picks ? status.recent_picks.length : 0);
                const currentPickNumber = pickCount + 1;
                
                statusDiv.innerHTML = `
                    <div class="current-pick">
                        Pick ${currentPickNumber} of 30
                    </div>
                    ${status.current_participant ? `
                        <h2>${isPaused ? 'DRAFT PAUSED' : 'Now Picking:'}</h2>
                        <h3>${status.current_participant.display_name}</h3>
                    ` : ''}
                `;
                
                boardDiv.style.display = 'block';
                
                updateTeamGrid(status.available_teams, status.current_participant);
                
                if (status.draft_order && hasOrderChanged(status.draft_order)) {
                    updateDraftOrder(status.draft_order, status.current_participant);
                }
                if (status.recent_picks && hasPicksChanged(status.recent_picks)) {
                    updateDraftPicks(status.recent_picks);
                }
                
                updateCommissionerControls(status.status);
            }
        }
        
        function getTeamLogoPath(team) {
            if (team.logo && team.logo !== null && team.logo !== '') {
                return fixLogoPath(team.logo);
            }
            
            if (team.team_name) {
                const teamName = team.team_name.toLowerCase().replace(/\s+/g, '_');
                return fixLogoPath(`${teamName}.png`);
            }
            
            return 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMTgiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjIwIiB5PSIyNSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIyMCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo=';
        }
        
        function fixLogoPath(logoPath) {
            if (!logoPath || logoPath === null || logoPath === undefined || logoPath === '') {
                return 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMTgiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjIwIiB5PSIyNSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIyMCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo=';
            }
            
            if (logoPath.indexOf('/media/') === 0) {
                return 'nba-wins-platform/public/assets/team_logos/' + logoPath.split('/').pop();
            }
            if (logoPath.indexOf('nba-wins-platform/public/assets/') === 0) {
                return logoPath;
            }
            return 'nba-wins-platform/public/assets/team_logos/' + logoPath.split('/').pop();
        }
        
        // Updated updateTeamGrid function with buttons directly on cards
        function updateTeamGrid(teams, currentParticipant = null) {
            const grid = document.getElementById('teamGrid');
            const isMyTurn = currentParticipant && currentParticipant.participant_id == userInfo.participant_id;
            const canPick = isMyTurn || userInfo.is_commissioner;
            
            console.log('Updating team grid - refreshing DOM');
            lastTeamsData = teams;
            
            grid.innerHTML = teams.map(team => {
                const teamDataStr = JSON.stringify(team).replace(/"/g, '&quot;');
                const onClickHandler = canPick ? `onclick="selectTeam(${teamDataStr})"` : '';
                const isSelected = selectedTeamId === team.id;
                
                return `
                <div class="team-card ${!canPick ? 'disabled' : ''} ${isSelected ? 'selected' : ''}" 
                     data-team-id="${team.id}" 
                     ${onClickHandler}
                     style="cursor: ${canPick ? 'pointer' : 'not-allowed'};">
                    <img src="${getTeamLogoPath(team)}" 
                         alt="${team.team_name} logo" 
                         class="team-logo"
                         onerror="this.style.opacity='0.3'">
                    <div class="team-name">${team.team_name}</div>
                    <div class="team-abbr">${team.abbreviation}</div>
                    ${canPick ? `
                    <div class="team-selection-buttons">
                        <button class="team-selection-btn confirm" onclick="event.stopPropagation(); confirmPick();">Confirm Pick</button>
                        <button class="team-selection-btn clear" onclick="event.stopPropagation(); clearSelection();">Clear</button>
                    </div>
                    ` : ''}
                </div>
            `;
            }).join('');
        }
        
        function updateDraftOrder(order, currentParticipant = null) {
            const list = document.getElementById('draftOrderList');
            
            const orderWithCurrent = order.map(participant => ({
                ...participant,
                is_current: currentParticipant && currentParticipant.participant_id == participant.participant_id
            }));
            
            console.log('Updating draft order - order changed');
            lastOrderData = orderWithCurrent;
            
            list.innerHTML = orderWithCurrent.map(participant => `
                <li class="order-item ${participant.is_current ? 'current' : ''}">
                    <span>${participant.display_name}</span>
                    <span class="pick-number">${participant.draft_position}</span>
                </li>
            `).join('');
        }
        
        function updateDraftPicks(picks) {
            const list = document.getElementById('recentPicksList');
            
            if (picks.length > 0) {
                list.innerHTML = picks.map(pick => {
                    const teamObj = {
                        team_name: pick.team_name,
                        abbreviation: pick.team_abbreviation,
                        logo: pick.team_logo
                    };
                    
                    return `
                    <li class="pick-item">
                        <span>${pick.participant_name}</span>
                        <div class="pick-team-info">
                            <img src="${getTeamLogoPath(teamObj)}" 
                                 alt="${pick.team_name} logo" 
                                 class="pick-team-logo"
                                 onerror="this.style.opacity='0.3'">
                            <div>
                                <strong>${pick.team_name}</strong><br>
                                <small>Pick #${pick.pick_number}</small>
                            </div>
                        </div>
                    </li>
                `;
                }).join('');
                
                updateLastPickDisplay(picks[0]);
            } else {
                list.innerHTML = '<li style="text-align: center; color: var(--text-muted); padding: 30px; font-style: italic;">No picks made yet...</li>';
            }
            
            lastPicksData = picks;
        }
        
        function updateLastPickDisplay(pick) {
            const lastPickDisplay = document.getElementById('lastPickDisplay');
            const lastPickContent = document.getElementById('lastPickContent');
            
            const teamObj = {
                team_name: pick.team_name,
                abbreviation: pick.team_abbreviation,
                logo: pick.team_logo
            };
            
            lastPickContent.innerHTML = `
                <img src="${getTeamLogoPath(teamObj)}" 
                     alt="${pick.team_name} logo" 
                     class="last-pick-logo"
                     onerror="this.style.opacity='0.3'">
                <div class="last-pick-info">
                    <h4>Pick #${pick.pick_number}</h4>
                    <div class="team-name">${pick.team_name}</div>
                    <div class="participant-name">Selected by ${pick.participant_name}</div>
                </div>
            `;
            
            lastPickDisplay.classList.add('show');
        }
        
        function updateCommissionerControls(status) {
            if (!userInfo.is_commissioner) return;
            
            const startBtn = document.getElementById('startDraftBtn');
            const pauseBtn = document.getElementById('pauseDraftBtn');
            const resumeBtn = document.getElementById('resumeDraftBtn');
            const commPickBtn = document.getElementById('commissionerPickBtn');
            
            [startBtn, pauseBtn, resumeBtn, commPickBtn].forEach(btn => {
                if (btn) btn.style.display = 'none';
            });
            
            switch (status) {
                case 'not_started':
                    if (startBtn) startBtn.style.display = 'inline-block';
                    break;
                case 'active':
                    if (pauseBtn) pauseBtn.style.display = 'inline-block';
                    if (commPickBtn) commPickBtn.style.display = 'inline-block';
                    break;
                case 'paused':
                    if (resumeBtn) resumeBtn.style.display = 'inline-block';
                    if (commPickBtn) commPickBtn.style.display = 'inline-block';
                    break;
            }
        }
        
        // Select / clear team
        function selectTeam(team) {
            console.log('Selecting team:', team);
            
            document.querySelectorAll('.team-card.selected').forEach(card => {
                card.classList.remove('selected');
            });
            
            const teamCard = document.querySelector(`[data-team-id="${team.id}"]`);
            if (teamCard) teamCard.classList.add('selected');
            
            selectedTeamId = team.id;
            selectedTeamData = team;
            
            if (currentDraftStatus && currentDraftStatus.available_teams) {
                updateTeamGrid(currentDraftStatus.available_teams, currentDraftStatus.current_participant);
            }
        }
        
        function clearSelection() {
            document.querySelectorAll('.team-card.selected').forEach(card => card.classList.remove('selected'));
            selectedTeamId = null;
            selectedTeamData = null;
            
            if (currentDraftStatus && currentDraftStatus.available_teams) {
                updateTeamGrid(currentDraftStatus.available_teams, currentDraftStatus.current_participant);
            }
        }
        
        // Draft actions
        function startDraft() {
            if (!confirm('Are you sure you want to start the draft? This cannot be undone.')) return;
            
            fetch('nba-wins-platform/api/draft_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=start_draft'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Draft started!', 'success');
                    setTimeout(() => pollDraftStatus(), 1000);
                } else {
                    showNotification('Error: ' + data.error, 'error');
                }
            })
            .catch(error => {
                showNotification('Error starting draft', 'error');
                console.error('Error:', error);
            });
        }
        
        function pauseDraft() {
            fetch('nba-wins-platform/api/draft_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=pause_draft'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Draft paused', 'info');
                    pollDraftStatus();
                } else {
                    showNotification('Error: ' + data.error, 'error');
                }
            });
        }
        
        function resumeDraft() {
            fetch('nba-wins-platform/api/draft_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=resume_draft'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Draft resumed', 'success');
                    pollDraftStatus();
                } else {
                    showNotification('Error: ' + data.error, 'error');
                }
            });
        }
        
        function confirmPick() {
            if (!selectedTeamId) {
                showNotification('Please select a team first', 'warning');
                return;
            }
            
            fetch('nba-wins-platform/api/draft_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=make_pick&team_id=${selectedTeamId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Pick confirmed!', 'success');
                    clearSelection();
                    
                    lastTeamsData = null;
                    lastOrderData = null;
                    lastPicksData = null;
                    
                    if (data.pick_count >= 30) {
                        console.log('30 picks reached, redirecting...');
                        setTimeout(() => {
                            window.location.href = 'draft_summary_new.php';
                        }, 1500);
                    } else {
                        setTimeout(() => pollDraftStatus(), 1000);
                    }
                } else {
                    showNotification('Error: ' + data.error, 'error');
                }
            })
            .catch(error => {
                showNotification('Error making pick', 'error');
                console.error('Error:', error);
            });
        }
        
        function commissionerPick() {
            if (!currentDraftStatus || !currentDraftStatus.current_participant) return;
            
            if (!selectedTeamId) {
                showNotification('Please select a team for the current participant', 'warning');
                return;
            }
            
            const currentParticipant = currentDraftStatus.current_participant;
            const confirmMsg = `Make pick for ${currentParticipant.display_name}?`;
            
            if (!confirm(confirmMsg)) return;
            
            fetch('nba-wins-platform/api/draft_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=make_pick&team_id=${selectedTeamId}&participant_id=${currentParticipant.participant_id}&commissioner_pick=1`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Commissioner pick made!', 'success');
                    clearSelection();
                    
                    lastTeamsData = null;
                    lastOrderData = null;
                    lastPicksData = null;
                    
                    if (data.pick_count >= 30) {
                        console.log('30 picks reached, redirecting...');
                        setTimeout(() => {
                            window.location.href = 'draft_summary_new.php';
                        }, 1500);
                    } else {
                        setTimeout(() => pollDraftStatus(), 1000);
                    }
                } else {
                    showNotification('Error: ' + data.error, 'error');
                }
            });
        }
        
        // Show notification
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => notification.classList.add('show'), 100);
            
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (notification.parentNode) {
                        document.body.removeChild(notification);
                    }
                }, 300);
            }, 4000);
        }
        
        // Clean up on page unload
        window.addEventListener('beforeunload', function() {
            stopPolling();
        });
        
        console.log('Draft interface initialized (dark theme)');
    </script>
</body>
</html>