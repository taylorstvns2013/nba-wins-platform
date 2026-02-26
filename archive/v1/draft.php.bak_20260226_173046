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
    header('Location: draft_summary.php');
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
    <meta name="theme-color" content="#f5f5f5">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Draft - <?= htmlspecialchars($league['display_name']) ?></title>
    <link rel="apple-touch-icon" type="image/png" href="nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <!-- React and Babel for Navigation Component -->
    <script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
    <style>
        :root {
            --primary-color: #212121;
            --secondary-color: #424242;
            --background-color: rgba(245, 245, 245, 0.8);
            --text-color: #333333;
            --border-color: #e0e0e0;
            --hover-color: #757575;
            --basketball-orange: #FF7F00;
            --basketball-brown: #8B4513;
            --success-color: #4CAF50;
            --warning-color: #ff9800;
            --error-color: #f44336;
            --info-color: #2196F3;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 10px;
            background-image: url('nba-wins-platform/public/assets/background/geometric_white.png');
            background-repeat: repeat;
            background-attachment: fixed;
            color: var(--text-color);
            background-color: #f5f5f5;
            min-height: 100vh;
            min-height: -webkit-fill-available;
        }

        html {
            height: -webkit-fill-available;
            background-color: #f5f5f5;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            background-color: var(--background-color);
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .header {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            margin-bottom: 15px;
            background-color: rgba(255,255,255,0.8);
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .basketball-logo {
            max-width: 60px;
            margin-bottom: 10px;
        }
        
        h1 {
            margin: 10px 0;
            font-size: 28px;
            color: var(--primary-color);
        }
        
        h2 {
            margin: 5px 0;
            font-size: 20px;
            color: var(--secondary-color);
        }
        
        .draft-controls {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
            font-family: inherit;
        }
        
        .btn-primary { 
            background-color: var(--success-color); 
            color: white; 
        }
        .btn-danger { 
            background-color: var(--error-color); 
            color: white; 
        }
        .btn-warning { 
            background-color: var(--warning-color); 
            color: white; 
        }
        .btn-secondary { 
            background-color: var(--secondary-color); 
            color: white; 
        }
        .btn-success { 
            background-color: var(--success-color); 
            color: white; 
        }
        
        .btn:hover { 
            transform: translateY(-1px); 
            box-shadow: 0 2px 8px rgba(0,0,0,0.2); 
        }
        .btn:disabled { 
            opacity: 0.6; 
            cursor: not-allowed; 
            transform: none; 
        }
        
        .draft-status {
            background-color: rgba(255,255,255,0.8);
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 15px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .draft-status h2 {
            font-size: 1.2em; /* Add this to make "Now Picking:" smaller */
            margin: 5px 0; /* Add this to reduce spacing */
        }

        .draft-status h3 {
            font-size: 1.1em; /* Add this to make player name smaller */
            margin: 5px 0; /* Add this to reduce spacing */
        }
        
        .current-pick {
            font-size: 1.2em;
            margin-bottom: 10px;
            color: var(--basketball-orange);
            font-weight: bold;
        }
        
        .draft-board {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 768px) {
            .draft-board { grid-template-columns: 1fr; }
        }
        
        .available-teams, .draft-order, .recent-picks {
            background-color: rgba(255,255,255,0.8);
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .available-teams {
            max-height: 600px;
            overflow-y: auto;
        }
        
        .available-teams h3, .draft-order h3, .recent-picks h3 {
            color: var(--primary-color);
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        .team-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }
        
        .team-card {
            background: rgba(255,255,255,0.9);
            padding: 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
            border: 2px solid var(--border-color);
            position: relative;
            min-height: 120px;
        }
        
        .team-card:hover {
            background: rgba(255,255,255,1);
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        .team-card.selected {
            border-color: var(--success-color);
            background: rgba(76, 175, 80, 0.1);
            padding-bottom: 55px; /* Make room for buttons */
        }
        
        .team-card.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .team-logo {
            width: 40px;
            height: 40px;
            margin: 0 auto 8px;
            border-radius: 50%;
            display: block;
        }
        
        .team-name { 
            font-weight: bold; 
            margin-bottom: 5px;
            color: var(--text-color);
            font-size: 14px;
        }
        .team-abbr { 
            font-size: 0.85em; 
            color: var(--secondary-color);
        }
        
        /* Team selection buttons that appear directly on selected cards */
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
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-block;
            font-family: inherit;
        }
        
        .team-selection-btn.confirm {
            background-color: var(--success-color);
            color: white;
        }
        
        .team-selection-btn.clear {
            background-color: var(--secondary-color);
            color: white;
        }
        
        .team-selection-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 1px 4px rgba(0,0,0,0.2);
        }
        
        .order-list, .picks-list {
            list-style: none;
            padding: 0;
            margin: 0;
            max-height: 600px;
            overflow-y: auto;
        }
        
        .recent-picks {
            background-color: rgba(255,255,255,0.9);
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
            border: 2px solid rgba(33, 33, 33, 0.1);
        }
        
        .recent-picks h3 {
            color: var(--basketball-orange) !important;
            font-size: 20px !important;
            margin-bottom: 20px !important;
            text-align: center;
            font-weight: bold;
        }
        
        .order-item, .pick-item {
            padding: 12px;
            margin: 8px 0;
            background: rgba(255,255,255,0.9);
            border-radius: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid var(--border-color);
        }
        
        .order-item.current {
            background: rgba(255, 152, 0, 0.1);
            border: 2px solid var(--basketball-orange);
            animation: currentGlow 2s infinite;
        }
        
        @keyframes currentGlow {
            0%, 100% { box-shadow: 0 0 5px rgba(255, 152, 0, 0.3); }
            50% { box-shadow: 0 0 15px rgba(255, 152, 0, 0.5); }
        }
        
        .pick-number {
            background: var(--info-color);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: bold;
        }
        
        .pick-item {
            align-items: center;
        }
        
        .pick-team-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .pick-team-logo {
            width: 30px;
            height: 30px;
            border-radius: 50%;
        }
        
        .last-pick-display {
            background-color: rgba(255,255,255,0.9);
            border: 2px solid var(--basketball-orange);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            display: none;
        }
        
        .last-pick-display.show {
            display: block;
            animation: fadeInSlide 0.5s ease-out;
        }
        
        @keyframes fadeInSlide {
            from { 
                opacity: 0; 
                transform: translateY(-20px); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
            }
        }
        
        .last-pick-content {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }
        
        .last-pick-logo {
            width: 60px;
            height: 60px;
            border-radius: 50%;
        }
        
        .last-pick-info h4 {
            margin: 0 0 5px 0;
            color: var(--basketball-orange);
            font-size: 18px;
        }
        
        .last-pick-info .team-name {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 3px;
        }
        
        .last-pick-info .participant-name {
            font-size: 14px;
            color: var(--secondary-color);
        }
        
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 6px;
            color: white;
            font-weight: 500;
            z-index: 1000;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .notification.show { transform: translateX(0); }
        .notification.success { background: var(--success-color); }
        .notification.error { background: var(--error-color); }
        .notification.warning { background: var(--warning-color); }
        .notification.info { background: var(--info-color); }
        
        .loading {
            text-align: center;
            padding: 40px;
            font-size: 1.1em;
            color: var(--secondary-color);
        }
        
        .spinner {
            border: 3px solid var(--border-color);
            border-top: 3px solid var(--basketball-orange);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .back-link {
            position: absolute;
            top: 20px;
            left: 20px;
            color: var(--text-color);
            text-decoration: none;
            font-size: 1.1em;
            font-weight: 500;
        }
        
        .back-link:hover { 
            color: var(--basketball-orange);
            text-decoration: underline; 
        }
        
        .commissioner-controls {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .commissioner-controls h3 {
            color: #e65100;
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        /* Menu styling to match index.php */
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
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 4px;
            padding: 0.5rem;
            cursor: pointer;
            z-index: 1002;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .menu-button:hover {
            background-color: var(--secondary-color);
        }
        
        .menu-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1001;
        }
        
        .menu-panel {
            position: fixed;
            top: 0;
            left: -300px;
            width: 300px;
            height: 100vh;
            background-color: white;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            transition: left 0.3s ease;
            z-index: 1002;
        }
        
        .menu-panel.menu-open {
            left: 0;
        }
        
        .menu-header {
            padding: 1rem;
            display: flex;
            justify-content: flex-end;
            border-bottom: 1px solid var(--border-color);
        }
        
        .close-button {
            background: none;
            border: none;
            color: var(--text-color);
            cursor: pointer;
            padding: 0.5rem;
        }
        
        .close-button:hover {
            color: var(--hover-color);
        }
        
        .menu-content {
            padding: 1rem;
        }
        
        .menu-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .menu-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem;
            color: var(--text-color);
            text-decoration: none;
            transition: background-color 0.2s;
            border-radius: 4px;
        }
        
        .menu-link:hover {
            background-color: var(--background-color);
            color: var(--secondary-color);
        }
        
        .menu-link i {
            width: 20px;
        }
        
        @media (max-width: 600px) {
            .container {
                padding: 15px;
            }
            
            h1 {
                font-size: 24px;
            }
            
            .current-pick {
                font-size: 1.4em;
            }
            
            .team-card {
                padding: 10px;
                min-height: 110px;
            }
            
            .team-card.selected {
                padding-bottom: 50px;
            }
            
            .team-name {
                font-size: 12px;
            }
            
            .team-abbr {
                font-size: 11px;
            }
            
            .order-item, .pick-item {
                padding: 10px;
                font-size: 14px;
            }
            
            .btn {
                padding: 8px 16px;
                font-size: 13px;
            }
            
            .team-selection-btn {
                padding: 5px 10px;
                font-size: 11px;
            }
            
            .last-pick-content {
                flex-direction: column;
                gap: 10px;
            }
            
            .last-pick-logo {
                width: 50px;
                height: 50px;
            }
        }
        
        @media (min-width: 601px) {
            .container {
                max-width: 1200px;
                padding: 30px;
            }
        }
    </style>
</head>
<body>
    <?php 
    // Include the navigation menu component
    include $_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/components/navigation_menu.php'; 
    ?>
    
    <div class="container">
        <div class="header">
            <img src="nba-wins-platform/public/assets/team_logos/Logo.png" alt="NBA Logo" class="basketball-logo">
            <h1>Live Draft</h1>
            <h2><?= htmlspecialchars($league['display_name']) ?></h2>
            <p>Welcome, <?= htmlspecialchars($user_info['display_name']) ?></p>
        </div>
        
        <div class="last-pick-display" id="lastPickDisplay">
            <h4 style="color: var(--basketball-orange); margin-bottom: 10px;">Latest Pick</h4>
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
                <h3>Available Teams</h3>
                <div class="team-grid" id="teamGrid">
                    <!-- Teams loaded via JavaScript -->
                </div>
            </div>
            
            <div class="draft-order">
                <h3>Draft Order</h3>
                <ul class="order-list" id="draftOrderList">
                    <!-- Order loaded via JavaScript -->
                </ul>
            </div>
            
            <div class="recent-picks">
                <h3>Draft Picks</h3>
                <ul class="picks-list" id="recentPicksList">
                    <!-- Picks loaded via JavaScript -->
                </ul>
            </div>
        </div>
        
        <?php if ($is_commissioner): ?>
        <div class="commissioner-controls" style="margin-top: 40px;">
            <h3>Commissioner Controls</h3>
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
            
            // Only update if team count changed (teams rarely change during draft)
            return false;
        }
        
        function hasOrderChanged(newOrder) {
            if (!lastOrderData || !newOrder) return true;
            if (lastOrderData.length !== newOrder.length) return true;
            
            try {
                // Check if current participant changed
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
            
            // Always update if pick count changed
            if (lastPicksData.length !== newPicks.length) return true;
            
            // If same length but we have picks, check if newest pick changed
            if (newPicks.length > 0 && lastPicksData.length > 0) {
                const lastFirstPick = lastPicksData[0];
                const newFirstPick = newPicks[0];
                
                if (!lastFirstPick || !newFirstPick) return true;
                
                // Check if pick number or team changed
                return (lastFirstPick.pick_number !== newFirstPick.pick_number ||
                       lastFirstPick.team_name !== newFirstPick.team_name ||
                       lastFirstPick.participant_name !== newFirstPick.participant_name);
            }
            
            return false;
        }
        
        // SIMPLIFIED: Check for completion using multiple indicators
        function checkFor30PicksAndRedirect(status) {
            // Check multiple completion indicators
            const pickCount = status.recent_picks ? status.recent_picks.length : 0;
            const apiPickCount = status.pick_count || 0;
            const isCompleted = status.status === 'completed';
            
            console.log('Completion check:', {
                pickCount,
                apiPickCount,
                isCompleted,
                status: status.status
            });
            
            if (pickCount >= 30 || apiPickCount >= 30 || isCompleted) {
                console.log('Draft completed detected, redirecting to summary...');
                stopPolling();
                window.location.href = 'draft_summary.php';
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
                        
                        // Simple check: if 30 picks, redirect immediately
                        if (checkFor30PicksAndRedirect(status)) {
                            return; // Exit early if redirecting
                        }
                        
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
                    <p>Waiting for commissioner to start the draft...</p>
                    ${userInfo.is_commissioner ? '<p><strong>You can start the draft when ready!</strong></p>' : ''}
                `;
                boardDiv.style.display = 'none';
                updateCommissionerControls('not_started');
            }
            else if (status.status === 'active' || status.status === 'paused') {
                const isPaused = status.status === 'paused';
                
                // Use API pick count for accurate display
                const pickCount = status.pick_count || (status.recent_picks ? status.recent_picks.length : 0);
                const currentPickNumber = pickCount + 1;
                
                console.log('Updating display - Pick count:', pickCount, 'Current pick:', currentPickNumber);
                
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
                
                // Always update team grid to reflect selection state
                updateTeamGrid(status.available_teams, status.current_participant);
                
                // Only update other components if their data changed
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
            lastTeamsData = teams; // Cache the data
            
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
                         onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMTgiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjIwIiB5PSIyNSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIyMCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
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
            
            // Add current participant info to order data for comparison
            const orderWithCurrent = order.map(participant => ({
                ...participant,
                is_current: currentParticipant && currentParticipant.participant_id == participant.participant_id
            }));
            
            console.log('Updating draft order - order changed');
            lastOrderData = orderWithCurrent; // Cache the data
            
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
                                 onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzAiIGhlaWdodD0iMzAiIHZpZXdCb3g9IjAgMCAzMCAzMCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTUiIGN5PSIxNSIgcj0iMTMiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjE1IiB5PSIyMCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1ساز: IjE1IiBmaWxsPSIjMzMzMzMzIj4/PC90ZXh0Pgo8L3N2Zz4K'">
                            <div>
                                <strong>${pick.team_name}</strong><br>
                                <small>Pick #${pick.pick_number}</small>
                            </div>
                        </div>
                    </li>
                `;
                }).join('');
                
                // Show the most recent pick
                updateLastPickDisplay(picks[0]);
            } else {
                list.innerHTML = '<li style="text-align: center; opacity: 0.7; padding: 30px; font-style: italic;">No picks made yet...</li>';
            }
            
            lastPicksData = picks; // Cache the data
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
                     onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjAiIGhlaWdodD0iNjAiIHZpZXdCb3g9IjAgMCA2MCA2MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMzAiIGN5PSIzMCIgcj0iMjYiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSI0Ii8+Cjx0ZXh0IHg9IjMwIiB5PSI0MCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIzMCIgZmlsbD0iIzMzMzMzMyI+Pz48L3RleHQ+Cjwvc3ZnPgo='">
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
            
            // Hide all first
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
        
        // Updated selectTeam function - no longer needs to show separate selectedTeam div
        function selectTeam(team) {
            console.log('Selecting team:', team);
            
            // Clear previous selection
            document.querySelectorAll('.team-card.selected').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Select new team
            const teamCard = document.querySelector(`[data-team-id="${team.id}"]`);
            if (teamCard) {
                teamCard.classList.add('selected');
            }
            
            selectedTeamId = team.id;
            selectedTeamData = team;
            
            // Force refresh of team grid to show buttons on selected card
            if (currentDraftStatus && currentDraftStatus.available_teams) {
                updateTeamGrid(currentDraftStatus.available_teams, currentDraftStatus.current_participant);
            }
        }
        
        function clearSelection() {
            document.querySelectorAll('.team-card.selected').forEach(card => {
                card.classList.remove('selected');
            });
            selectedTeamId = null;
            selectedTeamData = null;
            
            // Force refresh of team grid to hide buttons
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
                    
                    // Clear cache to force fresh data after pick
                    lastTeamsData = null;
                    lastOrderData = null;
                    lastPicksData = null;
                    
                    // Check for completion immediately after pick
                    if (data.pick_count >= 30) {
                        console.log('30 picks reached, redirecting...');
                        setTimeout(() => {
                            window.location.href = 'draft_summary.php';
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
                    
                    // Clear cache to force fresh data after pick
                    lastTeamsData = null;
                    lastOrderData = null;
                    lastPicksData = null;
                    
                    // Check for completion immediately after pick
                    if (data.pick_count >= 30) {
                        console.log('30 picks reached, redirecting...');
                        setTimeout(() => {
                            window.location.href = 'draft_summary.php';
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
            
            // Show notification
            setTimeout(() => notification.classList.add('show'), 100);
            
            // Hide notification after 4 seconds
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
        
        console.log('Draft interface initialized with on-card selection buttons');
    </script>
</body>
</html>