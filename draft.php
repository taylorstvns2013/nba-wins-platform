<?php
// /data/www/default/draft.php - Live Draft Interface
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['current_league_id'])) {
    header('Location: /nba-wins-platform/auth/login.php');
    exit;
}

require_once '/data/www/default/nba-wins-platform/config/db_connection.php';
require_once '/data/www/default/nba-wins-platform/core/DraftManager.php';

$user_id = $_SESSION['user_id'];
$league_id = $_SESSION['current_league_id'];

// Logo path helper
function fixLogoPath($logoPath) {
    if (strpos($logoPath, '/media/') === 0) {
        return 'nba-wins-platform/public/assets/team_logos/' . basename($logoPath);
    }
    if (strpos($logoPath, 'nba-wins-platform/public/assets/') === 0) {
        return $logoPath;
    }
    return 'nba-wins-platform/public/assets/team_logos/' . basename($logoPath);
}

// Get league info
$stmt = $pdo->prepare("SELECT * FROM leagues WHERE id = ?");
$stmt->execute([$league_id]);
$league = $stmt->fetch();
if (!$league) { die("League not found"); }

// Check 30-pick completion → redirect
$stmt = $pdo->prepare("
    SELECT COUNT(dp.id) as total_picks
    FROM draft_sessions ds
    LEFT JOIN draft_picks dp ON ds.id = dp.draft_session_id
    WHERE ds.league_id = ?
    ORDER BY ds.created_at DESC LIMIT 1
");
$stmt->execute([$league_id]);
$pickResult = $stmt->fetch();
$totalPicks = $pickResult ? $pickResult['total_picks'] : 0;
if ($totalPicks >= 30) {
    header('Location: draft_summary.php');
    exit;
}

$draftManager = new DraftManager($pdo);
$draft_status = $draftManager->getDraftStatus($league_id);

// Get user info
$stmt = $pdo->prepare("
    SELECT lp.id as participant_id, lp.participant_name, lp.auto_draft_enabled,
           l.commissioner_user_id, u.display_name
    FROM league_participants lp
    JOIN leagues l ON lp.league_id = l.id
    JOIN users u ON lp.user_id = u.id
    WHERE lp.user_id = ? AND lp.league_id = ?
");
$stmt->execute([$user_id, $league_id]);
$user_info = $stmt->fetch();

$is_commissioner = $league['commissioner_user_id'] == $user_id || $league['commissioner_user_id'] === null;
$theme = $_SESSION['theme_preference'] ?? 'dark';
$isDark = $theme !== 'classic';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="<?= $isDark ? '#0d1117' : '#f5f5f5' ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Live Draft - <?= htmlspecialchars($league['display_name']) ?></title>
    <link rel="apple-touch-icon" type="image/png" href="nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           CSS VARIABLES — DARK THEME (default)
           ============================================================ */
        :root {
            --bg-primary: #151d28;
            --bg-secondary: #1a222c;
            --bg-card: #161e28;
            --bg-elevated: #1c2634;
            --bg-card-hover: #1e2a3a;
            --text-primary: #e6edf3;
            --text-secondary: #8b949e;
            --text-muted: #546070;
            --border-color: rgba(255,255,255,0.08);
            --border-subtle: rgba(255,255,255,0.04);
            --accent-blue: #388bfd;
            --accent-blue-dim: rgba(56,139,253,0.10);
            --accent-green: #3fb950;
            --accent-green-dim: rgba(63,185,80,0.10);
            --accent-red: #f85149;
            --accent-red-dim: rgba(248,81,73,0.10);
            --accent-orange: #d29922;
            --accent-orange-dim: rgba(210,153,34,0.12);
            --shadow-card: 0 1px 3px rgba(0,0,0,0.4), 0 0 0 1px var(--border-color);
            --shadow-elevated: 0 4px 12px rgba(0,0,0,0.5);
            --radius-sm: 6px;
            --radius-md: 8px;
            --radius-lg: 10px;
            --transition-fast: 0.15s ease;
            --transition-normal: 0.25s ease;
        }

        /* ============================================================
           CLASSIC / LIGHT THEME OVERRIDES
           ============================================================ */
        <?php if (!$isDark): ?>
        :root {
            --bg-primary: #f5f5f5;
            --bg-secondary: rgba(245,245,245,0.95);
            --bg-card: #ffffff;
            --bg-elevated: #f0f0f2;
            --bg-card-hover: #f8f9fa;
            --text-primary: #333333;
            --text-secondary: #666666;
            --text-muted: #999999;
            --border-color: #e0e0e0;
            --border-subtle: rgba(0,0,0,0.06);
            --accent-blue: #0066ff;
            --accent-blue-dim: rgba(0,102,255,0.08);
            --accent-green: #28a745;
            --accent-green-dim: rgba(40,167,69,0.08);
            --accent-red: #dc3545;
            --accent-red-dim: rgba(220,53,69,0.08);
            --accent-orange: #d4a017;
            --accent-orange-dim: rgba(212,160,23,0.08);
            --shadow-card: 0 1px 4px rgba(0,0,0,0.08), 0 0 0 1px rgba(0,0,0,0.04);
            --shadow-elevated: 0 4px 16px rgba(0,0,0,0.1), 0 0 0 1px rgba(0,0,0,0.06);
        }
        body { background-image: url('nba-wins-platform/public/assets/background/geometric_white.png'); background-repeat: repeat; background-attachment: fixed; }
        <?php endif; ?>

        /* ============================================================
           BASE
           ============================================================ */
        *, *::before, *::after { box-sizing: border-box; }
        html { background: var(--bg-primary); }
        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            line-height: 1.5;
            margin: 0;
            padding: 0;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }
        .container { max-width: 1280px; margin: 0 auto; padding: 16px; }

        /* ============================================================
           HEADER
           ============================================================ */
        .draft-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            margin-bottom: 14px;
            gap: 12px;
            flex-wrap: wrap;
        }
        .draft-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }
        .draft-header-left img { width: 44px; height: 44px; flex-shrink: 0; }
        .draft-header-left h1 {
            font-size: 1.35rem;
            font-weight: 800;
            margin: 0;
            letter-spacing: -0.02em;
            white-space: nowrap;
        }
        .draft-header-left .league-label {
            font-size: 0.82rem;
            color: var(--text-secondary);
            font-weight: 500;
        }
        .draft-header-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }
        .user-badge {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.82rem;
            color: var(--text-secondary);
            background: var(--bg-elevated);
            padding: 6px 12px;
            border-radius: 999px;
            border: 1px solid var(--border-color);
        }
        .user-badge i { color: var(--accent-blue); font-size: 0.75rem; }

        /* ============================================================
           PRE-DRAFT COUNTDOWN
           ============================================================ */
        .pre-draft-panel {
            text-align: center;
            padding: 60px 24px;
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            margin-bottom: 14px;
        }
        .pre-draft-panel h2 {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-secondary);
            margin: 0 0 24px;
        }
        .countdown-grid {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }
        .countdown-unit {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 72px;
        }
        .countdown-value {
            font-size: 2.6rem;
            font-weight: 800;
            color: var(--accent-orange);
            line-height: 1;
            letter-spacing: -0.03em;
        }
        .countdown-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-top: 4px;
        }
        .pre-draft-note {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        /* ============================================================
           COMBINED DRAFT INFO CARD
           ============================================================ */
        .draft-info-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            margin-bottom: 14px;
            overflow: hidden;
        }
        .di-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 18px;
            gap: 12px;
        }
        .di-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }
        .di-right {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-shrink: 0;
        }
        .di-auto-toggle {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
        }
        .di-auto-label { white-space: nowrap; }
        .status-pick-badge {
            background: var(--accent-blue);
            color: #fff;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .status-now-picking { font-size: 0.85rem; color: var(--text-secondary); }
        .status-now-picking strong { color: var(--text-primary); font-weight: 700; }
        .status-your-turn { font-size: 0.78rem; font-weight: 600; color: var(--accent-green); margin-top: 2px; }

        /* Timer ring */
        .timer-ring-wrap { position: relative; width: 50px; height: 50px; flex-shrink: 0; }
        .timer-ring-svg { width: 50px; height: 50px; transform: rotate(-90deg); }
        .timer-ring-bg { fill: none; stroke: var(--border-color); stroke-width: 4; }
        .timer-ring-fg {
            fill: none; stroke: var(--accent-green); stroke-width: 4;
            stroke-linecap: round; transition: stroke-dashoffset 1s linear, stroke 0.3s ease;
        }
        .timer-ring-fg.warning { stroke: var(--accent-orange); }
        .timer-ring-fg.danger  { stroke: var(--accent-red); }
        .timer-seconds {
            position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
            font-size: 0.95rem; font-weight: 700; color: var(--text-primary);
        }
        .timer-seconds.warning { color: var(--accent-orange); }
        .timer-seconds.danger  { color: var(--accent-red); }
        .status-paused-badge {
            display: flex; align-items: center; gap: 6px;
            background: var(--accent-orange-dim); color: var(--accent-orange);
            padding: 6px 14px; border-radius: 999px; font-size: 0.8rem; font-weight: 700;
        }

        /* Last pick row inside the card */
        .di-last-pick {
            display: none;
            align-items: center;
            gap: 14px;
            padding: 12px 18px;
            border-top: 1px solid var(--border-subtle);
            border-left: 3px solid var(--accent-orange);
            transition: all 0.3s ease;
        }
        .di-last-pick.show { display: flex; }
        .di-last-pick img { width: 44px; height: 44px; border-radius: 50%; flex-shrink: 0; }
        .di-last-pick .lp-text { font-size: 0.95rem; color: var(--text-secondary); }
        .di-last-pick .lp-text strong { color: var(--text-primary); font-size: 1rem; }
        .di-last-pick .lp-pick-num { font-size: 0.78rem; font-weight: 700; color: var(--accent-orange); margin-top: 1px; }
        .di-last-pick .lp-auto-badge {
            display: inline-block; font-size: 0.65rem; font-weight: 700;
            color: var(--accent-orange); background: var(--accent-orange-dim);
            padding: 2px 7px; border-radius: 999px; margin-left: 6px; vertical-align: middle;
        }
        .di-last-pick.auto-highlight {
            border-left-color: var(--accent-red);
            background: var(--accent-red-dim);
        }
        .di-last-pick.auto-highlight .lp-pick-num { color: var(--accent-red); }
        @keyframes slideIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
        .di-last-pick.flash { animation: bannerFlash 1.5s ease-out; }
        @keyframes bannerFlash {
            0%   { background: var(--accent-blue-dim); border-left-color: var(--accent-blue); }
            30%  { background: var(--accent-blue-dim); border-left-color: var(--accent-blue); }
            100% { background: transparent; border-left-color: var(--accent-orange); }
        }

        /* ============================================================
           DRAFT BOARD — 3-column layout
           ============================================================ */
        .draft-board {
            display: grid;
            grid-template-columns: 1fr 260px;
            grid-template-rows: auto 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }
        .panel {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            overflow: hidden;
        }
        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 16px 10px;
            border-bottom: 1px solid var(--border-subtle);
        }
        .panel-title {
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .panel-title i { font-size: 0.82rem; color: var(--text-muted); }
        .panel-count {
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--text-muted);
            background: var(--bg-elevated);
            padding: 2px 8px;
            border-radius: 999px;
        }
        .panel-body { padding: 12px 16px 16px; }

        /* Teams panel spans full width */
        .teams-panel { grid-column: 1 / -1; }

        /* Sidebar: order + picks stacked */
        .sidebar-stack { grid-column: 2; grid-row: 1 / 3; display: flex; flex-direction: column; gap: 14px; }

        /* Actually let's do: teams top-left, sidebar right, picks below teams */
        .draft-board {
            grid-template-columns: 1fr 280px;
            grid-template-rows: auto;
        }
        .teams-panel { grid-column: 1; grid-row: 1; }
        .sidebar-stack { grid-column: 2; grid-row: 1; }

        /* ============================================================
           TEAM GRID
           ============================================================ */
        .team-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 8px;
        }
        .team-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 14px 8px 12px;
            border-radius: var(--radius-md);
            background: var(--bg-elevated);
            border: 2px solid var(--border-color);
            cursor: pointer;
            transition: all var(--transition-fast);
            text-align: center;
            position: relative;
        }
        .team-card:hover {
            border-color: rgba(56,139,253,0.3);
            background: var(--bg-card-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow-elevated);
        }
        .team-card.selected {
            border-color: var(--accent-green);
            background: var(--accent-green-dim);
            padding-bottom: 52px;
        }
        .team-card.disabled {
            opacity: 0.35;
            cursor: not-allowed;
            pointer-events: none;
        }
        .team-card img {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            margin-bottom: 6px;
            object-fit: contain;
        }
        .tc-name {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-primary);
            line-height: 1.2;
        }
        .tc-abbr {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .tc-check {
            position: absolute;
            top: 6px;
            right: 6px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--accent-green);
            color: #fff;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 10px;
        }
        .team-card.selected .tc-check { display: flex; }

        /* Inline action buttons inside selected team card */
        .tc-actions {
            position: absolute;
            bottom: 6px;
            left: 6px;
            right: 6px;
            display: none;
            gap: 4px;
        }
        .team-card.selected .tc-actions { display: flex; }
        .tc-actions .btn {
            flex: 1;
            justify-content: center;
            padding: 5px 8px;
            font-size: 0.72rem;
            border-radius: 4px;
        }

        /* ============================================================
           SIDEBAR LISTS (order + picks)
           ============================================================ */
        .order-list, .picks-list {
            list-style: none;
            padding: 0;
            margin: 0;
            overflow-y: auto;
        }
        .order-list { max-height: 280px; }
        .picks-list { max-height: 500px; }
        .order-list::-webkit-scrollbar, .picks-list::-webkit-scrollbar { width: 4px; }
        .order-list::-webkit-scrollbar-thumb, .picks-list::-webkit-scrollbar-thumb {
            background: var(--text-muted);
            border-radius: 2px;
        }
        .order-list::-webkit-scrollbar-track, .picks-list::-webkit-scrollbar-track { background: transparent; }

        .order-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 9px 12px;
            border-radius: var(--radius-sm);
            font-size: 0.82rem;
            color: var(--text-primary);
            transition: background var(--transition-fast);
        }
        .order-item + .order-item { margin-top: 2px; }
        .order-item .oi-pos {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--bg-elevated);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--text-muted);
            flex-shrink: 0;
        }
        .order-item .oi-name { flex: 1; margin-left: 10px; font-weight: 500; }
        .order-item.current {
            background: var(--accent-orange-dim);
            border: 1px solid rgba(210,153,34,0.3);
        }
        .order-item.current .oi-pos {
            background: var(--accent-orange);
            color: #fff;
        }
        .order-item.is-me .oi-name { color: var(--accent-blue); }

        .pick-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            font-size: 0.82rem;
            transition: background var(--transition-fast);
            position: relative;
            overflow: hidden;
        }
        .pick-item + .pick-item { margin-top: 2px; }
        .pick-item:first-child { background: var(--bg-elevated); }

        /* New pick sweep animation */
        .pick-item.new-pick::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, var(--accent-blue-dim) 0%, var(--accent-blue-dim) 50%, transparent 100%);
            animation: pickSweep 1.2s ease-out forwards;
            pointer-events: none;
            border-radius: inherit;
        }
        @keyframes pickSweep {
            0%   { transform: translateX(-100%); opacity: 1; }
            60%  { transform: translateX(0%); opacity: 1; }
            100% { transform: translateX(0%); opacity: 0; }
        }
        .pick-item .pi-num {
            background: var(--accent-blue);
            color: #fff;
            font-size: 0.68rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 999px;
            flex-shrink: 0;
        }
        .pick-item img { width: 24px; height: 24px; border-radius: 50%; flex-shrink: 0; }
        .pick-item .pi-details { min-width: 0; }
        .pick-item .pi-team { font-weight: 600; color: var(--text-primary); font-size: 0.8rem; }
        .pick-item .pi-by { font-size: 0.72rem; color: var(--text-muted); }
        .pick-item .pi-auto {
            font-size: 0.65rem;
            color: var(--accent-orange);
            font-weight: 600;
        }

        .empty-state {
            text-align: center;
            padding: 28px 12px;
            color: var(--text-muted);
            font-size: 0.82rem;
            font-style: italic;
        }

        /* ============================================================
           TOGGLE SWITCH (shared)
           ============================================================ */
        .toggle-switch {
            position: relative;
            width: 40px;
            height: 22px;
            flex-shrink: 0;
        }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-track {
            position: absolute;
            inset: 0;
            background: var(--bg-elevated);
            border-radius: 999px;
            border: 1px solid var(--border-color);
            transition: background 0.2s ease, border-color 0.2s ease;
            cursor: pointer;
        }
        .toggle-track::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--text-muted);
            transition: transform 0.2s ease, background 0.2s ease;
        }
        .toggle-switch input:checked + .toggle-track {
            background: var(--accent-green-dim);
            border-color: var(--accent-green);
        }
        .toggle-switch input:checked + .toggle-track::after {
            transform: translateX(18px);
            background: var(--accent-green);
        }
        .auto-draft-hint {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-left: auto;
        }

        /* ============================================================
           COMMISSIONER CONTROLS
           ============================================================ */
        .commissioner-panel {
            padding: 14px 16px;
            background: var(--bg-card);
            border: 1px solid rgba(210,153,34,0.2);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            margin-bottom: 14px;
        }
        .commissioner-panel .cp-title {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--accent-orange);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .commissioner-panel .cp-title i { font-size: 0.78rem; }
        .cp-actions { display: flex; gap: 8px; flex-wrap: wrap; }

        /* ============================================================
           BUTTONS
           ============================================================ */
        .btn {
            padding: 8px 18px;
            border: none;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.82rem;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: all var(--transition-fast);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .btn:hover { filter: brightness(1.1); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
        .btn:disabled { opacity: 0.45; cursor: not-allowed; transform: none; filter: none; box-shadow: none; }
        .btn-green  { background: var(--accent-green); color: #fff; }
        .btn-orange { background: var(--accent-orange); color: #fff; }
        .btn-red    { background: var(--accent-red); color: #fff; }
        .btn-ghost  { background: var(--bg-elevated); color: var(--text-secondary); border: 1px solid var(--border-color); }
        .btn-ghost:hover { color: var(--text-primary); }
        .btn-sm { padding: 6px 12px; font-size: 0.78rem; }

        /* ============================================================
           NOTIFICATIONS
           ============================================================ */
        .toast-stack { position: fixed; top: 16px; right: 16px; z-index: 9998; display: flex; flex-direction: column; gap: 8px; }
        .toast {
            padding: 10px 18px;
            border-radius: var(--radius-md);
            color: #fff;
            font-weight: 600;
            font-size: 0.82rem;
            font-family: 'Outfit', sans-serif;
            box-shadow: var(--shadow-elevated);
            transform: translateX(120%);
            transition: transform 0.3s ease, opacity 0.3s ease;
            max-width: 340px;
        }
        .toast.show { transform: translateX(0); }
        .toast.success { background: var(--accent-green); }
        .toast.error   { background: var(--accent-red); }
        .toast.warning { background: var(--accent-orange); }
        .toast.info    { background: var(--accent-blue); }

        /* ============================================================
           LOADING
           ============================================================ */
        .loading-state {
            text-align: center;
            padding: 48px 20px;
            color: var(--text-muted);
        }
        .spinner {
            width: 32px; height: 32px;
            border: 3px solid var(--border-color);
            border-top-color: var(--accent-orange);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 14px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 860px) {
            .draft-board {
                grid-template-columns: 1fr;
            }
            .sidebar-stack { grid-column: 1; grid-row: auto; }
            .teams-panel { grid-column: 1; grid-row: auto; }
        }
        @media (max-width: 600px) {
            .container { padding: 10px; }
            .draft-header { padding: 12px 14px; }
            .draft-header-left h1 { font-size: 1.15rem; }
            .draft-header-left img { width: 36px; height: 36px; }
            .status-bar { padding: 12px 14px; gap: 10px; }
            .team-grid { grid-template-columns: repeat(auto-fill, minmax(105px, 1fr)); gap: 6px; }
            .team-card { padding: 10px 6px 8px; }
            .team-card img { width: 34px; height: 34px; }
            .tc-name { font-size: 0.72rem; }
            .tc-actions .btn { font-size: 0.65rem; padding: 4px 6px; }
            .tc-actions { flex-direction: column; }
            .team-card.selected { padding-bottom: 62px; }
            .di-last-pick { padding: 10px 14px; gap: 10px; }
            .di-last-pick img { width: 36px; height: 36px; }
            .di-last-pick .lp-text { font-size: 0.85rem; }
            .di-top { padding: 10px 14px; gap: 8px; }
            .di-auto-label { display: none; }
            .countdown-value { font-size: 2rem; }
            .countdown-unit { min-width: 58px; }
            .timer-ring-wrap { width: 48px; height: 48px; }
            .timer-ring-svg { width: 48px; height: 48px; }
            .timer-seconds { font-size: 0.92rem; }
        }
    </style>
</head>
<body>
    <div class="toast-stack" id="toastStack"></div>

    <div class="container">

        <!-- HEADER -->
        <div class="draft-header">
            <div class="draft-header-left">
                <img src="nba-wins-platform/public/assets/team_logos/Logo.png" alt="NBA">
                <div>
                    <h1>Live Draft</h1>
                    <div class="league-label"><?= htmlspecialchars($league['display_name']) ?></div>
                </div>
            </div>
            <div class="draft-header-right">
                <div class="user-badge">
                    <i class="fas fa-user"></i>
                    <?= htmlspecialchars($user_info['display_name'] ?? 'Unknown') ?>
                </div>
            </div>
        </div>

        <!-- LOADING STATE -->
        <div id="loadingState" class="loading-state">
            <div class="spinner"></div>
            Loading draft...
        </div>

        <!-- PRE-DRAFT COUNTDOWN (hidden by default) -->
        <div id="preDraftPanel" class="pre-draft-panel" style="display:none;">
            <h2>Draft Starts In</h2>
            <div class="countdown-grid" id="countdownGrid">
                <div class="countdown-unit"><span class="countdown-value" id="cdDays">--</span><span class="countdown-label">Days</span></div>
                <div class="countdown-unit"><span class="countdown-value" id="cdHours">--</span><span class="countdown-label">Hours</span></div>
                <div class="countdown-unit"><span class="countdown-value" id="cdMins">--</span><span class="countdown-label">Min</span></div>
                <div class="countdown-unit"><span class="countdown-value" id="cdSecs">--</span><span class="countdown-label">Sec</span></div>
            </div>
            <div class="pre-draft-note" id="preDraftNote">Waiting for the draft to begin...</div>
        </div>

        <!-- COMBINED DRAFT INFO CARD (status + last pick + auto-draft) -->
        <div id="draftInfoCard" class="draft-info-card" style="display:none;">
            <!-- Top row: pick info + auto-draft toggle + timer -->
            <div class="di-top">
                <div class="di-left">
                    <span class="status-pick-badge" id="pickBadge">Pick 1 of 30</span>
                    <div>
                        <div class="status-now-picking" id="nowPicking">Now Picking: <strong>---</strong></div>
                        <div class="status-your-turn" id="yourTurnLabel" style="display:none;">It's your turn!</div>
                    </div>
                </div>
                <div class="di-right">
                    <label class="di-auto-toggle" id="autoDraftToggle" style="display:none;">
                        <span class="toggle-switch">
                            <input type="checkbox" id="autoDraftCheckbox" onchange="toggleAutoDraft(this.checked)">
                            <span class="toggle-track"></span>
                        </span>
                        <span class="di-auto-label">Auto</span>
                    </label>
                    <div id="timerArea">
                        <div class="timer-ring-wrap" id="timerRing">
                            <svg class="timer-ring-svg" viewBox="0 0 56 56">
                                <circle class="timer-ring-bg" cx="28" cy="28" r="24"></circle>
                                <circle class="timer-ring-fg" id="timerArc" cx="28" cy="28" r="24"
                                        stroke-dasharray="150.8" stroke-dashoffset="0"></circle>
                            </svg>
                            <div class="timer-seconds" id="timerText">--</div>
                        </div>
                    </div>
                    <div class="status-paused-badge" id="pausedBadge" style="display:none;">
                        <i class="fas fa-pause"></i> Paused
                    </div>
                </div>
            </div>
            <!-- Bottom row: last pick (hidden until a pick is made) -->
            <div class="di-last-pick" id="lastPickBanner">
                <img id="lpLogo" src="" alt="" onerror="this.style.opacity='0.3'">
                <div>
                    <div class="lp-text" id="lpText"></div>
                    <div class="lp-pick-num" id="lpPickNum"></div>
                </div>
            </div>
        </div>

        <!-- DRAFT BOARD -->
        <div class="draft-board" id="draftBoard" style="display:none;">
            <!-- Teams Panel -->
            <div class="panel teams-panel">
                <div class="panel-header">
                    <span class="panel-title">Available Teams</span>
                    <span class="panel-count" id="teamsCount">30</span>
                </div>
                <div class="panel-body">
                    <div class="team-grid" id="teamGrid"></div>
                </div>
            </div>

            <!-- Sidebar: Order + Picks -->
            <div class="sidebar-stack">
                <div class="panel">
                    <div class="panel-header">
                        <span class="panel-title"><i class="fas fa-list-ol"></i> Draft Order</span>
                    </div>
                    <div class="panel-body">
                        <ul class="order-list" id="draftOrderList"></ul>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-header">
                        <span class="panel-title"><i class="fas fa-history"></i> Pick History</span>
                        <span class="panel-count" id="picksCount">0</span>
                    </div>
                    <div class="panel-body">
                        <ul class="picks-list" id="picksList"></ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- COMMISSIONER CONTROLS -->
        <?php if ($is_commissioner): ?>
        <div class="commissioner-panel" id="commPanel" style="display:none;">
            <div class="cp-title"><i class="fas fa-shield-alt"></i> Commissioner Controls</div>
            <div class="cp-actions">
                <button class="btn btn-green btn-sm" id="btnStart" onclick="startDraft()" style="display:none;" disabled><i class="fas fa-play"></i> Start Draft</button>
                <button class="btn btn-ghost btn-sm" id="btnStartEarly" onclick="startDraftEarly()" style="display:none;"><i class="fas fa-forward"></i> Start Early</button>
                <button class="btn btn-orange btn-sm" id="btnPause" onclick="pauseDraft()" style="display:none;"><i class="fas fa-pause"></i> Pause</button>
                <button class="btn btn-green btn-sm" id="btnResume" onclick="resumeDraft()" style="display:none;"><i class="fas fa-play"></i> Resume</button>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /.container -->

    <!-- ================================================================
         JAVASCRIPT
         ================================================================ -->
    <script>
    (function() {
        'use strict';

        // ── State ──────────────────────────────────────────────────
        let userInfo = {};
        let currentStatus = null;
        let selectedTeamId = null;
        let pollInterval = null;
        let timerInterval = null;
        let localTimer = 0;
        let pickTimeLimit = 120;
        let timerExpiredFired = false;    // prevent duplicate timer_expired calls per pick
        let lastPickNumber = 0;          // track pick changes for timer reset
        let draftDatePassed = false;     // whether draft_date is in the past
        let lastBannerPickNum = 0;       // track last displayed pick for auto-pick detection
        let lastRenderedPickNum = 0;     // track highest pick number rendered for new-pick animation
        let autoPickPaused = false;      // true when pausing to show an auto-pick
        let preDraftInterval = null;
        const API = 'nba-wins-platform/api/draft_api.php';

        // ── Init ───────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            fetchUserInfo();
            pollDraftStatus();
            startPolling();
        });

        window.addEventListener('beforeunload', stopPolling);

        // ── User Info ──────────────────────────────────────────────
        function fetchUserInfo() {
            fetch(API + '?action=get_user_info')
                .then(r => r.json())
                .then(d => { if (d.success) userInfo = d.data; })
                .catch(e => console.error('User info error:', e));
        }

        // ── Polling ────────────────────────────────────────────────
        function startPolling() {
            stopPolling();
            pollInterval = setInterval(pollDraftStatus, 5000);
        }
        function stopPolling() {
            if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
        }

        function pollDraftStatus() {
            fetch(API + '?action=get_draft_status')
                .then(r => r.json())
                .then(d => {
                    if (!d.success) return;
                    const s = d.data;

                    // Completion check
                    if (s.pick_count >= 30 || s.status === 'completed') {
                        stopPolling();
                        stopTimer();
                        window.location.href = 'draft_summary.php';
                        return;
                    }

                    // Sync auto-draft checkbox
                    const cb = document.getElementById('autoDraftCheckbox');
                    if (cb && s.user_auto_draft_enabled !== undefined) {
                        cb.checked = s.user_auto_draft_enabled;
                    }

                    pickTimeLimit = s.pick_time_limit || 120;

                    renderDraftState(s);
                    currentStatus = s;
                })
                .catch(e => {
                    console.error('Poll error:', e);
                });
        }

        // ── Render ─────────────────────────────────────────────────
        function renderDraftState(s) {
            // Skip UI updates during auto-pick pause so everyone can see the pick
            if (autoPickPaused) return;

            const loading = document.getElementById('loadingState');
            const preDraft = document.getElementById('preDraftPanel');
            const draftInfoCard = document.getElementById('draftInfoCard');
            const board = document.getElementById('draftBoard');
            const commPanel = document.getElementById('commPanel');
            const autoDraftToggle = document.getElementById('autoDraftToggle');

            loading.style.display = 'none';

            if (s.status === 'not_started') {
                preDraft.style.display = 'block';
                if (draftInfoCard) draftInfoCard.style.display = 'none';
                board.style.display = 'none';
                stopTimer();

                // Pre-draft countdown (use server time to avoid client clock drift)
                if (s.draft_date) {
                    const serverNow = s.server_time ? new Date(s.server_time).getTime() : Date.now();
                    const clockOffset = serverNow - Date.now(); // positive = server ahead
                    draftDatePassed = new Date(s.draft_date).getTime() <= serverNow;
                    startPreDraftCountdown(s.draft_date, clockOffset);
                    document.getElementById('preDraftNote').textContent = draftDatePassed
                        ? 'Draft time has arrived — ready to start.'
                        : 'The draft will auto-start at the scheduled time.';
                } else {
                    draftDatePassed = true; // No date set — commissioner controls it
                    document.getElementById('preDraftNote').textContent = 'Waiting for the commissioner to start the draft...';
                    clearCountdown();
                }

                showCommControls('not_started');
                return;
            }

            // Active or Paused
            preDraft.style.display = 'none';
            if (draftInfoCard) draftInfoCard.style.display = 'block';
            board.style.display = 'grid';
            if (autoDraftToggle) autoDraftToggle.style.display = 'flex';

            const isPaused = s.status === 'paused';
            const pickCount = s.pick_count || 0;
            const currentPick = pickCount + 1;

            // Pick badge
            document.getElementById('pickBadge').textContent = `Pick ${currentPick} of 30`;

            // Now picking
            const nowPicking = document.getElementById('nowPicking');
            const yourTurn = document.getElementById('yourTurnLabel');
            const myParticipantId = userInfo.participant_id || s.user_participant_id;
            const isMyTurn = s.current_participant && myParticipantId && s.current_participant.participant_id == myParticipantId;

            if (s.current_participant) {
                nowPicking.innerHTML = isPaused
                    ? '<strong>DRAFT PAUSED</strong>'
                    : `Now Picking: <strong>${escHtml(s.current_participant.display_name)}</strong>`;
            } else {
                nowPicking.innerHTML = '<strong>---</strong>';
            }

            yourTurn.style.display = (isMyTurn && !isPaused) ? 'block' : 'none';

            // Timer
            const timerRing = document.getElementById('timerRing');
            const pausedBadge = document.getElementById('pausedBadge');
            if (isPaused) {
                timerRing.style.display = 'none';
                pausedBadge.style.display = 'flex';
                stopTimer();
            } else {
                timerRing.style.display = 'block';
                pausedBadge.style.display = 'none';

                // Reset timer if pick changed
                const serverPickNum = s.current_pick_number || currentPick;
                if (serverPickNum !== lastPickNumber) {
                    lastPickNumber = serverPickNum;
                    timerExpiredFired = false;
                    localTimer = (s.timer_seconds_remaining != null) ? s.timer_seconds_remaining : pickTimeLimit;
                    startTimer();
                } else if (s.timer_seconds_remaining != null) {
                    // Sync from server only when it provides actual timer data
                    if (Math.abs(localTimer - s.timer_seconds_remaining) > 3) {
                        localTimer = s.timer_seconds_remaining;
                    }
                }
                renderTimer();
            }

            // Teams grid
            renderTeamGrid(s.available_teams || [], isMyTurn, isPaused);
            document.getElementById('teamsCount').textContent = (s.available_teams || []).length;

            // Draft order
            renderDraftOrder(s.draft_order || [], s.current_participant);

            // Pick history
            renderPickHistory(s.recent_picks || []);
            document.getElementById('picksCount').textContent = pickCount;

            // Commissioner
            showCommControls(s.status);
        }

        // ── Pre-Draft Countdown ────────────────────────────────────
        function startPreDraftCountdown(draftDateStr, clockOffset) {
            if (preDraftInterval) clearInterval(preDraftInterval);
            const target = new Date(draftDateStr).getTime();
            const offset = clockOffset || 0; // ms difference: server - client

            function tick() {
                // Use client time + offset to approximate server time
                const serverNow = Date.now() + offset;
                const diff = target - serverNow;
                if (diff <= 0) {
                    clearInterval(preDraftInterval);
                    document.getElementById('cdDays').textContent = '0';
                    document.getElementById('cdHours').textContent = '0';
                    document.getElementById('cdMins').textContent = '0';
                    document.getElementById('cdSecs').textContent = '0';
                    document.getElementById('preDraftNote').textContent = 'Draft starting shortly...';
                    draftDatePassed = true;
                    showCommControls('not_started');
                    // Poll more frequently to catch the draft starting
                    let startCheck = setInterval(function() {
                        pollDraftStatus();
                        if (currentStatus && currentStatus.status !== 'not_started') {
                            clearInterval(startCheck);
                        }
                    }, 3000);
                    return;
                }
                const d = Math.floor(diff / 86400000);
                const h = Math.floor((diff % 86400000) / 3600000);
                const m = Math.floor((diff % 3600000) / 60000);
                const sec = Math.floor((diff % 60000) / 1000);
                document.getElementById('cdDays').textContent = d;
                document.getElementById('cdHours').textContent = h;
                document.getElementById('cdMins').textContent = m;
                document.getElementById('cdSecs').textContent = sec;
            }
            tick();
            preDraftInterval = setInterval(tick, 1000);
        }

        function clearCountdown() {
            if (preDraftInterval) clearInterval(preDraftInterval);
            ['cdDays','cdHours','cdMins','cdSecs'].forEach(id => {
                document.getElementById(id).textContent = '--';
            });
        }

        // ── Per-Pick Timer ─────────────────────────────────────────
        function startTimer() {
            stopTimer();
            timerInterval = setInterval(function() {
                localTimer = localTimer - 1;
                renderTimer();
                // Fire 2 seconds after local zero to account for server/client clock drift
                if (localTimer <= -2 && !timerExpiredFired) {
                    timerExpiredFired = true;
                    handleTimerExpired();
                }
            }, 1000);
        }
        function stopTimer() {
            if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }
        }

        function renderTimer() {
            const arc = document.getElementById('timerArc');
            const text = document.getElementById('timerText');
            const circumference = 2 * Math.PI * 24; // r=24
            const fraction = Math.max(0, localTimer / pickTimeLimit);
            arc.setAttribute('stroke-dashoffset', circumference * (1 - fraction));

            text.textContent = Math.max(0, localTimer);

            // Color states
            arc.classList.remove('warning', 'danger');
            text.classList.remove('warning', 'danger');
            if (localTimer <= 10) {
                arc.classList.add('danger');
                text.classList.add('danger');
            } else if (localTimer <= 30) {
                arc.classList.add('warning');
                text.classList.add('warning');
            }
        }

        function handleTimerExpired() {
            // Only the user whose turn it is (or commissioner) should trigger the auto-pick
            const isMyTurn = currentStatus && currentStatus.current_participant &&
                             currentStatus.current_participant.participant_id == userInfo.participant_id;
            const isComm = userInfo.is_commissioner;

            if (isMyTurn || isComm) {
                fetch(API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=timer_expired'
                })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        let msg = 'Time expired — auto-picked ' + (d.auto_picked_team || 'a team');
                        if (d.auto_draft_forced) {
                            msg += '. Auto-draft enabled due to consecutive timeouts.';
                            // Sync the checkbox if auto-draft was force-enabled for this user
                            const cb = document.getElementById('autoDraftCheckbox');
                            if (cb && isMyTurn) cb.checked = true;
                        }
                        showToast(msg, 'warning');

                        if (d.is_completed) {
                            setTimeout(() => { window.location.href = 'draft_summary.php'; }, 1500);
                        } else {
                            // Fetch once to update the banner, then auto-pick pause kicks in
                            setTimeout(pollDraftStatus, 500);
                        }
                    } else {
                        // Server says timer not expired yet (clock drift) — retry in 2 seconds
                        timerExpiredFired = false;
                    }
                })
                .catch(e => {
                    console.error('Timer expired error:', e);
                    // Network error — allow retry
                    timerExpiredFired = false;
                });
            } else {
                // Not our turn — just wait for next poll to pick up the change
                setTimeout(pollDraftStatus, 2000);
            }
        }

        /**
         * Flash the banner to draw attention to a new pick
         */
        function flashBanner() {
            const banner = document.getElementById('lastPickBanner');
            if (!banner) return;
            banner.classList.remove('flash');
            // Force reflow to restart animation
            void banner.offsetWidth;
            banner.classList.add('flash');
        }

        /**
         * Pause the draft display for 7 seconds when an auto-pick happens
         * so everyone can see what was picked before moving on.
         */
        function triggerAutoPickPause() {
            if (autoPickPaused) return; // already pausing
            autoPickPaused = true;
            stopTimer(); // freeze the timer ring

            // Show a paused countdown in the timer area
            const text = document.getElementById('timerText');
            const arc = document.getElementById('timerArc');
            if (text) text.textContent = '...';
            if (arc) arc.classList.remove('warning', 'danger');

            setTimeout(function() {
                autoPickPaused = false;
                const banner = document.getElementById('lastPickBanner');
                if (banner) banner.classList.remove('auto-highlight');
                pollDraftStatus(); // resume normal flow
            }, 7000);
        }

        // ── Team Grid ──────────────────────────────────────────────
        function renderTeamGrid(teams, isMyTurn, isPaused) {
            const grid = document.getElementById('teamGrid');
            const canPick = isMyTurn && !isPaused;

            grid.innerHTML = teams.map(t => {
                const sel = selectedTeamId === t.id;
                return `<div class="team-card ${sel ? 'selected' : ''} ${!canPick ? 'disabled' : ''}"
                             data-team-id="${t.id}"
                             onclick="${canPick ? 'selectTeam(' + t.id + ',this)' : ''}">
                    <div class="tc-check"><i class="fas fa-check"></i></div>
                    <img src="${logoPath(t)}" alt="${escHtml(t.team_name)}" onerror="this.style.opacity='0.3'">
                    <div class="tc-name">${escHtml(t.team_name)}</div>
                    <div class="tc-abbr">${escHtml(t.abbreviation)}</div>
                    ${canPick ? `<div class="tc-actions">
                        <button class="btn btn-green" onclick="event.stopPropagation(); confirmPick();"><i class="fas fa-check"></i> Confirm</button>
                        <button class="btn btn-ghost" onclick="event.stopPropagation(); clearSelection();">Cancel</button>
                    </div>` : ''}
                </div>`;
            }).join('');

            // Clear selection if selected team no longer available
            if (selectedTeamId && canPick) {
                if (!teams.find(t => t.id === selectedTeamId)) {
                    selectedTeamId = null;
                }
            }
        }

        // ── Draft Order ────────────────────────────────────────────
        function renderDraftOrder(order, currentParticipant) {
            const list = document.getElementById('draftOrderList');
            if (!order.length) { list.innerHTML = '<li class="empty-state">No draft order yet</li>'; return; }

            list.innerHTML = order.map(p => {
                const isCurrent = currentParticipant && currentParticipant.participant_id == p.participant_id;
                const isMe = p.participant_id == userInfo.participant_id;
                return `<li class="order-item ${isCurrent ? 'current' : ''} ${isMe ? 'is-me' : ''}">
                    <span class="oi-pos">${p.draft_position}</span>
                    <span class="oi-name">${escHtml(p.display_name)}${isMe ? ' (You)' : ''}</span>
                </li>`;
            }).join('');
        }

        // ── Pick History ───────────────────────────────────────────
        function renderPickHistory(picks) {
            const list = document.getElementById('picksList');
            if (!picks.length) { list.innerHTML = '<li class="empty-state">No picks yet</li>'; return; }

            // Detect how many new picks appeared since last render
            const highestPick = picks[0]?.pick_number || 0;
            const newPickCount = highestPick - lastRenderedPickNum;

            list.innerHTML = picks.map((p, i) => {
                const teamObj = { team_name: p.team_name, abbreviation: p.abbreviation, logo: p.team_logo };
                const isNew = (lastRenderedPickNum > 0) && (p.pick_number > lastRenderedPickNum);
                return `<li class="pick-item ${isNew ? 'new-pick' : ''}">
                    <span class="pi-num">#${p.pick_number}</span>
                    <img src="${logoPath(teamObj)}" alt="${escHtml(p.team_name)}" onerror="this.style.opacity='0.3'">
                    <div class="pi-details">
                        <div class="pi-team">${escHtml(p.team_name)}</div>
                        <div class="pi-by">${escHtml(p.participant_name)}${Number(p.auto_picked) ? ' <span class="pi-auto">AUTO</span>' : ''}</div>
                    </div>
                </li>`;
            }).join('');

            // Show toast for any auto-picks that happened between polls (back-to-back)
            if (lastRenderedPickNum > 0 && newPickCount > 1) {
                picks.slice(0, newPickCount).reverse().forEach(p => {
                    if (Number(p.auto_picked)) {
                        showToast(`${p.participant_name} auto-picked ${p.team_name} (Pick #${p.pick_number})`, 'warning');
                    }
                });
            }

            lastRenderedPickNum = highestPick;

            // Update last pick banner
            const lp = picks[0];
            if (lp) {
                const banner = document.getElementById('lastPickBanner');
                const teamObj = { team_name: lp.team_name, abbreviation: lp.abbreviation, logo: lp.team_logo };
                const isAuto = Number(lp.auto_picked);
                document.getElementById('lpLogo').src = logoPath(teamObj);
                document.getElementById('lpText').innerHTML = `<strong>${escHtml(lp.team_name)}</strong> selected by ${escHtml(lp.participant_name)}${isAuto ? '<span class="lp-auto-badge">AUTO</span>' : ''}`;
                document.getElementById('lpPickNum').textContent = `Pick #${lp.pick_number}`;

                // Detect new auto-pick → pause to let everyone see it
                const isNewPick = lp.pick_number !== lastBannerPickNum;
                lastBannerPickNum = lp.pick_number;

                banner.classList.remove('auto-highlight');
                banner.classList.add('show');

                if (isNewPick) {
                    flashBanner();
                    if (isAuto) {
                        banner.classList.add('auto-highlight');
                        triggerAutoPickPause();
                    }
                }
            }
        }

        // ── Commissioner Controls ──────────────────────────────────
        function showCommControls(status) {
            const panel = document.getElementById('commPanel');
            if (!panel) return;
            panel.style.display = 'block';

            const allBtns = ['btnStart', 'btnStartEarly', 'btnPause', 'btnResume'];
            allBtns.forEach(id => { const el = document.getElementById(id); if (el) el.style.display = 'none'; });

            switch (status) {
                case 'not_started':
                    const startBtn = document.getElementById('btnStart');
                    if (startBtn) {
                        startBtn.style.display = 'inline-flex';
                        startBtn.disabled = !draftDatePassed;
                    }
                    // Show "Start Early" only when draft time hasn't arrived yet
                    if (!draftDatePassed) {
                        show('btnStartEarly');
                    }
                    break;
                case 'active':
                    show('btnPause'); break;
                case 'paused':
                    show('btnResume'); break;
            }
            function show(id) { const el = document.getElementById(id); if (el) el.style.display = 'inline-flex'; }
        }

        // ── Actions ────────────────────────────────────────────────
        window.selectTeam = function(teamId, el) {
            if (selectedTeamId === teamId) { clearSelection(); return; }
            document.querySelectorAll('.team-card.selected').forEach(c => c.classList.remove('selected'));
            if (el) el.classList.add('selected');
            selectedTeamId = teamId;
        };

        window.clearSelection = function() {
            selectedTeamId = null;
            document.querySelectorAll('.team-card.selected').forEach(c => c.classList.remove('selected'));
        };

        window.confirmPick = function() {
            if (!selectedTeamId) { showToast('Select a team first', 'warning'); return; }

            // Grab team info BEFORE clearing selection
            const pickedTeam = (currentStatus?.available_teams || []).find(t => t.id === selectedTeamId);
            const pickNum = (currentStatus?.pick_count || 0) + 1;
            const myName = userInfo.display_name || currentStatus?.current_participant?.display_name || 'You';

            // Disable confirm buttons to prevent double-click
            document.querySelectorAll('.tc-actions .btn-green').forEach(b => b.disabled = true);

            apiPost('make_pick', `team_id=${selectedTeamId}`, function(d) {
                if (d.success) {
                    showToast('Pick confirmed!', 'success');
                    clearSelection();

                    // Immediately show YOUR pick in the banner so it doesn't get swallowed
                    if (pickedTeam) {
                        const banner = document.getElementById('lastPickBanner');
                        document.getElementById('lpLogo').src = logoPath(pickedTeam);
                        document.getElementById('lpText').innerHTML = `<strong>${escHtml(pickedTeam.team_name)}</strong> selected by ${escHtml(myName)}`;
                        document.getElementById('lpPickNum').textContent = `Pick #${pickNum}`;
                        banner.classList.remove('auto-highlight');
                        banner.classList.add('show');
                        flashBanner();
                        lastBannerPickNum = pickNum;
                    }

                    if (d.pick_count >= 30) {
                        setTimeout(() => { window.location.href = 'draft_summary.php'; }, 1500);
                    } else {
                        // Delay poll so the user sees their own pick for 5 seconds
                        setTimeout(pollDraftStatus, 5000);
                    }
                } else {
                    showToast('Error: ' + d.error, 'error');
                    document.querySelectorAll('.tc-actions .btn-green').forEach(b => b.disabled = false);
                }
            });
        };

        window.startDraft = function() {
            if (!confirm('Start the draft? This cannot be undone.')) return;
            apiPost('start_draft', '', function(d) {
                if (d.success) { showToast('Draft started!', 'success'); setTimeout(pollDraftStatus, 1000); }
                else showToast('Error: ' + d.error, 'error');
            });
        };

        window.startDraftEarly = function() {
            // Calculate how far away the draft_date is
            let timeMsg = '';
            if (currentStatus && currentStatus.draft_date) {
                const diff = new Date(currentStatus.draft_date).getTime() - Date.now();
                if (diff > 0) {
                    const hours = Math.floor(diff / 3600000);
                    const mins = Math.floor((diff % 3600000) / 60000);
                    timeMsg = hours > 0 ? `${hours}h ${mins}m` : `${mins} minutes`;
                }
            }

            const warning = `The draft isn't scheduled for another ${timeMsg || 'some time'}.\n\nParticipants may not be online yet. Starting early cannot be undone.\n\nAre you sure you want to start now?`;
            if (!confirm(warning)) return;

            apiPost('start_draft', '', function(d) {
                if (d.success) { showToast('Draft started early!', 'success'); setTimeout(pollDraftStatus, 1000); }
                else showToast('Error: ' + d.error, 'error');
            });
        };

        window.pauseDraft = function() {
            apiPost('pause_draft', '', function(d) {
                if (d.success) { showToast('Draft paused', 'info'); pollDraftStatus(); }
                else showToast('Error: ' + d.error, 'error');
            });
        };

        window.resumeDraft = function() {
            apiPost('resume_draft', '', function(d) {
                if (d.success) { showToast('Draft resumed', 'success'); pollDraftStatus(); }
                else showToast('Error: ' + d.error, 'error');
            });
        };

        window.toggleAutoDraft = function(enabled) {
            apiPost('toggle_auto_draft', `enabled=${enabled ? 'true' : 'false'}`, function(d) {
                if (d.success) {
                    showToast(d.message, 'info');
                } else {
                    showToast('Error: ' + d.error, 'error');
                    // Revert checkbox
                    document.getElementById('autoDraftCheckbox').checked = !enabled;
                }
            });
        };

        // ── Helpers ────────────────────────────────────────────────
        function apiPost(action, body, cb) {
            fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=${action}${body ? '&' + body : ''}`
            })
            .then(r => r.json())
            .then(cb)
            .catch(e => { console.error('API error:', e); showToast('Connection error', 'error'); });
        }

        function logoPath(team) {
            if (!team) return '';
            const name = team.team_name || '';
            if (team.logo && team.logo !== '') {
                return fixLogo(team.logo);
            }
            if (name) {
                return fixLogo(name.toLowerCase().replace(/\s+/g, '_') + '.png');
            }
            return 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48Y2lyY2xlIGN4PSIyMCIgY3k9IjIwIiByPSIxOCIgc3Ryb2tlPSIjNTU1IiBzdHJva2Utd2lkdGg9IjIiLz48dGV4dCB4PSIyMCIgeT0iMjUiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGZvbnQtc2l6ZT0iMjAiIGZpbGw9IiM1NTUiPj88L3RleHQ+PC9zdmc+';
        }

        function fixLogo(path) {
            if (!path) return '';
            if (path.indexOf('/media/') === 0) return 'nba-wins-platform/public/assets/team_logos/' + path.split('/').pop();
            if (path.indexOf('nba-wins-platform/public/assets/') === 0) return path;
            return 'nba-wins-platform/public/assets/team_logos/' + path.split('/').pop();
        }

        function escHtml(str) {
            if (!str) return '';
            const d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        function showToast(message, type) {
            const stack = document.getElementById('toastStack');
            const t = document.createElement('div');
            t.className = 'toast ' + (type || 'info');
            t.textContent = message;
            stack.appendChild(t);
            requestAnimationFrame(() => t.classList.add('show'));
            setTimeout(() => {
                t.classList.remove('show');
                setTimeout(() => { if (t.parentNode) t.parentNode.removeChild(t); }, 300);
            }, 4000);
        }

    })();
    </script>

    <?php $currentPage = 'draft'; include '/data/www/default/nba-wins-platform/components/pill_nav.php'; ?>
</body>
</html>