<?php
// nba-wins-platform/auth/league_hub.php
// League Hub - Where users join existing leagues or create new ones
// Accessible after login/registration when user has no leagues, or via navigation
require_once '../config/db_connection.php';
require_once '../core/UserAuthentication.php';
require_once '../core/LeagueManager.php';

$auth = new UserAuthentication($pdo);
$leagueManager = new LeagueManager($pdo);

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
$message = '';
$messageType = '';
$activeTab = $_GET['tab'] ?? 'overview';

// Handle form submissions
if ($_POST) {
    $action = $_POST['action'] ?? '';

    if ($action === 'join_league') {
        $pinCode = trim($_POST['pin_code'] ?? '');
        $result = $leagueManager->joinLeague($userId, $pinCode);
        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'error';
        if ($result['success']) {
            // Update session if user had no league selected
            if (empty($_SESSION['current_league_id'])) {
                $_SESSION['current_league_id'] = $result['league_id'];
                $stmt = $pdo->prepare("UPDATE user_sessions SET current_league_id = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$result['league_id'], $_SESSION['session_id'], $userId]);
            }
            $activeTab = 'overview';
        } else {
            $activeTab = 'join';
        }
    }

    if ($action === 'create_league') {
        $leagueName = trim($_POST['league_name'] ?? '');
        $leagueSize = (int)($_POST['league_size'] ?? 6);
        $draftDate = trim($_POST['draft_date'] ?? '');
        $draftTime = trim($_POST['draft_time'] ?? '');

        $fullDraftDate = null;
        if (!empty($draftDate) && !empty($draftTime)) {
            $fullDraftDate = $draftDate . ' ' . $draftTime . ':00';
        } elseif (!empty($draftDate)) {
            $fullDraftDate = $draftDate . ' 20:00:00'; // Default to 8 PM
        }

        $result = $leagueManager->createLeague($userId, $leagueName, $leagueSize, $fullDraftDate);
        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'error';
        if ($result['success']) {
            // Update session if user had no league selected
            if (empty($_SESSION['current_league_id'])) {
                $_SESSION['current_league_id'] = $result['league_id'];
                $stmt = $pdo->prepare("UPDATE user_sessions SET current_league_id = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$result['league_id'], $_SESSION['session_id'], $userId]);
            }
            $activeTab = 'overview';
        } else {
            $activeTab = 'create';
        }
    }

    if ($action === 'update_draft_date') {
        $leagueId = (int)($_POST['league_id'] ?? 0);
        $draftDate = trim($_POST['draft_date'] ?? '');
        $draftTime = trim($_POST['draft_time'] ?? '');

        $fullDraftDate = $draftDate . ' ' . ($draftTime ?: '20:00') . ':00';
        $result = $leagueManager->updateDraftDate($userId, $leagueId, $fullDraftDate);
        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'error';
    }
}

// Get user's leagues
$userLeagues = $leagueManager->getUserLeaguesWithDetails($userId);
$commissionerLeagues = $leagueManager->getCommissionerLeagues($userId);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="theme-color" content="#121a23">
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
            --accent-green-dim: rgba(63, 185, 80, 0.15);
            --accent-red: #f85149;
            --accent-orange: #d29922;
            --accent-purple: #a371f7;
            --radius-md: 10px;
            --radius-lg: 14px;
            --shadow-card: 0 1px 3px rgba(0, 0, 0, 0.4), 0 0 0 1px var(--border-color);
            --shadow-elevated: 0 8px 25px rgba(0, 0, 0, 0.5);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { background: var(--bg-primary); }

        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg-primary);
            background-image: radial-gradient(ellipse at 50% 0%, rgba(56, 139, 253, 0.04) 0%, transparent 60%);
            color: var(--text-primary);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            line-height: 1.5;
        }

        .hub-container {
            max-width: 800px;
            margin: 0 auto;
        }

        /* Header */
        .hub-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .hub-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .hub-header-left img {
            width: 48px;
            height: 48px;
        }

        .hub-header h1 {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .hub-header-right {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .header-btn {
            padding: 8px 16px;
            border-radius: var(--radius-md);
            font-family: 'Outfit', sans-serif;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
            border: 1px solid var(--border-color);
            background: var(--bg-elevated);
            color: var(--text-secondary);
        }

        .header-btn:hover {
            background: var(--bg-card-hover);
            color: var(--text-primary);
        }

        /* Message */
        .message {
            padding: 14px 18px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            font-size: 14px;
        }

        .message.success {
            background-color: var(--accent-green-dim);
            color: var(--accent-green);
            border: 1px solid rgba(63, 185, 80, 0.25);
        }

        .message.error {
            background-color: rgba(248, 81, 73, 0.12);
            color: var(--accent-red);
            border: 1px solid rgba(248, 81, 73, 0.25);
        }

        /* Tabs */
        .tab-nav {
            display: flex;
            gap: 4px;
            margin-bottom: 24px;
            background: var(--bg-card);
            padding: 4px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
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
            background: rgba(255, 255, 255, 0.04);
        }

        .tab-btn.active {
            background: var(--accent-blue);
            color: white;
        }

        .tab-btn i {
            margin-right: 6px;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Cards */
        .card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            padding: 24px;
            margin-bottom: 16px;
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-title i {
            color: var(--accent-blue);
        }

        /* League cards in overview */
        .league-card {
            background: var(--bg-elevated);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            padding: 18px;
            margin-bottom: 12px;
            transition: border-color 0.15s ease;
        }

        .league-card:hover {
            border-color: rgba(56, 139, 253, 0.3);
        }

        .league-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .league-name {
            font-size: 16px;
            font-weight: 600;
        }

        .league-badge {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .badge-commissioner {
            background: rgba(163, 113, 247, 0.15);
            color: var(--accent-purple);
            border: 1px solid rgba(163, 113, 247, 0.25);
        }

        .badge-member {
            background: var(--accent-blue-dim);
            color: var(--accent-blue);
            border: 1px solid rgba(56, 139, 253, 0.25);
        }

        .league-meta {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .league-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .league-meta-item i {
            font-size: 12px;
            color: var(--text-muted);
        }

        /* PIN display */
        .pin-display {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--bg-primary);
            padding: 6px 14px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0.15em;
            color: var(--accent-green);
            border: 1px solid rgba(63, 185, 80, 0.2);
            margin-top: 8px;
        }

        .pin-display .copy-btn {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 14px;
            padding: 2px 4px;
            transition: color 0.15s ease;
        }

        .pin-display .copy-btn:hover {
            color: var(--accent-blue);
        }

        /* Members list */
        .members-list {
            list-style: none;
            margin-top: 10px;
        }

        .members-list li {
            padding: 8px 12px;
            border-bottom: 1px solid var(--border-color);
            font-size: 13px;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .members-list li:last-child {
            border-bottom: none;
        }

        .members-list .commissioner-icon {
            color: var(--accent-purple);
        }

        /* Forms */
        .form-group {
            margin-bottom: 18px;
        }

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
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 15px;
            font-family: 'Outfit', sans-serif;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            background-color: var(--bg-elevated);
            color: var(--text-primary);
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
        select option {
            background: var(--bg-card);
            color: var(--text-primary);
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px var(--accent-blue-dim);
        }

        /* Color scheme fix for date/time inputs */
        input[type="date"]::-webkit-calendar-picker-indicator,
        input[type="time"]::-webkit-calendar-picker-indicator {
            filter: invert(0.7);
        }

        .help-text {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 6px;
        }

        .form-row {
            display: flex;
            gap: 12px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .submit-btn {
            width: 100%;
            padding: 14px 20px;
            border: none;
            border-radius: var(--radius-md);
            font-size: 15px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .submit-btn:hover {
            transform: translateY(-1px);
        }

        .btn-blue {
            background: linear-gradient(135deg, var(--accent-blue), #1a6ddb);
            color: white;
        }

        .btn-blue:hover {
            box-shadow: 0 4px 16px rgba(56, 139, 253, 0.3);
        }

        .btn-green {
            background: linear-gradient(135deg, var(--accent-green), #2ea043);
            color: white;
        }

        .btn-green:hover {
            box-shadow: 0 4px 16px rgba(63, 185, 80, 0.3);
        }

        /* Empty state */
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

        .empty-state p {
            font-size: 14px;
            margin-bottom: 20px;
        }

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
            transition: all 0.15s ease;
        }

        .empty-state-actions .btn-primary {
            background: var(--accent-blue);
            color: white;
        }

        .empty-state-actions .btn-secondary {
            background: var(--bg-elevated);
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }

        /* Draft date display */
        .draft-date {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
        }

        .draft-date.upcoming {
            color: var(--accent-orange);
        }

        .draft-date.not-set {
            color: var(--text-muted);
            font-style: italic;
        }

        /* Responsive */
        @media (max-width: 600px) {
            body { padding: 12px; }

            .hub-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .tab-nav {
                flex-direction: column;
            }

            .form-row {
                flex-direction: column;
                gap: 0;
            }

            .league-meta {
                flex-direction: column;
                gap: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="hub-container">
        <!-- Header -->
        <div class="hub-header">
            <div class="hub-header-left">
                <img src="../public/assets/team_logos/Logo.png" alt="NBA Logo">
                <div>
                    <h1>League Hub</h1>
                    <span style="font-size: 13px; color: var(--text-muted);"><?php echo htmlspecialchars($_SESSION['display_name']); ?></span>
                </div>
            </div>
            <div class="hub-header-right">
                <?php if (!empty($userLeagues)): ?>
                    <a href="/index.php" class="header-btn"><i class="fas fa-home"></i> Dashboard</a>
                <?php endif; ?>
                <a href="logout.php" class="header-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tab-nav">
            <button class="tab-btn <?php echo $activeTab === 'overview' ? 'active' : ''; ?>" onclick="switchTab('overview')">
                <i class="fas fa-trophy"></i> My Leagues
            </button>
            <button class="tab-btn <?php echo $activeTab === 'join' ? 'active' : ''; ?>" onclick="switchTab('join')">
                <i class="fas fa-sign-in-alt"></i> Join League
            </button>
            <button class="tab-btn <?php echo $activeTab === 'create' ? 'active' : ''; ?>" onclick="switchTab('create')">
                <i class="fas fa-plus-circle"></i> Create League
            </button>
        </div>

        <!-- Tab: My Leagues Overview -->
        <div id="tab-overview" class="tab-content <?php echo $activeTab === 'overview' ? 'active' : ''; ?>">
            <?php if (empty($userLeagues)): ?>
                <div class="card">
                    <div class="empty-state">
                        <i class="fas fa-basketball-ball"></i>
                        <h3>No Leagues Yet</h3>
                        <p>Join an existing league with a PIN code, or create your own league and invite friends.</p>
                        <div class="empty-state-actions">
                            <button class="btn-primary" onclick="switchTab('join')"><i class="fas fa-sign-in-alt"></i> Join a League</button>
                            <button class="btn-secondary" onclick="switchTab('create')"><i class="fas fa-plus"></i> Create a League</button>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($userLeagues as $league):
                    $isCommish = $league['is_commissioner'];
                    $members = $leagueManager->getLeagueMembers($league['id']);
                ?>
                    <div class="league-card">
                        <div class="league-card-header">
                            <div>
                                <div class="league-name"><?php echo htmlspecialchars($league['display_name']); ?></div>
                            </div>
                            <span class="league-badge <?php echo $isCommish ? 'badge-commissioner' : 'badge-member'; ?>">
                                <?php echo $isCommish ? 'Commissioner' : 'Member'; ?>
                            </span>
                        </div>

                        <div class="league-meta">
                            <div class="league-meta-item">
                                <i class="fas fa-users"></i>
                                <?php echo $league['current_participants']; ?>/<?php echo $league['user_limit']; ?> members
                            </div>
                            <div class="league-meta-item draft-date <?php echo $league['draft_date'] ? 'upcoming' : 'not-set'; ?>">
                                <i class="fas fa-calendar-alt"></i>
                                <?php if ($league['draft_date']): ?>
                                    Draft: <?php echo date('M j, Y g:i A', strtotime($league['draft_date'])); ?>
                                <?php else: ?>
                                    Draft date not set
                                <?php endif; ?>
                            </div>
                            <?php if ($league['draft_completed']): ?>
                                <div class="league-meta-item" style="color: var(--accent-green);">
                                    <i class="fas fa-check-circle"></i> Draft complete
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($isCommish): ?>
                            <!-- Commissioner PIN display -->
                            <div style="margin-top: 12px;">
                                <span style="font-size: 12px; color: var(--text-muted);">Share this PIN with players:</span>
                                <div class="pin-display">
                                    <span id="pin-<?php echo $league['id']; ?>"><?php echo htmlspecialchars($league['pin_code']); ?></span>
                                    <button class="copy-btn" onclick="copyPIN('pin-<?php echo $league['id']; ?>')" title="Copy PIN">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Members list for commissioners -->
                            <div style="margin-top: 14px;">
                                <span style="font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Members</span>
                                <ul class="members-list">
                                    <?php foreach ($members as $member): ?>
                                        <li>
                                            <?php if ($member['is_commissioner']): ?>
                                                <i class="fas fa-crown commissioner-icon" title="Commissioner"></i>
                                            <?php else: ?>
                                                <i class="fas fa-user" style="color: var(--text-muted);"></i>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($member['display_name']); ?>
                                            <span style="color: var(--text-muted); font-size: 12px;">@<?php echo htmlspecialchars($member['username']); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                    <?php
                                    $emptySlots = $league['user_limit'] - count($members);
                                    for ($i = 0; $i < $emptySlots; $i++): ?>
                                        <li style="color: var(--text-muted); font-style: italic;">
                                            <i class="fas fa-user-plus" style="color: var(--text-muted);"></i>
                                            Open slot
                                        </li>
                                    <?php endfor; ?>
                                </ul>
                            </div>

                            <!-- Update draft date form for commissioners -->
                            <?php if (!$league['draft_completed']): ?>
                                <form method="POST" style="margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border-color);">
                                    <input type="hidden" name="action" value="update_draft_date">
                                    <input type="hidden" name="league_id" value="<?php echo $league['id']; ?>">
                                    <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em;">
                                        <?php echo $league['draft_date'] ? 'Update Draft Date' : 'Set Draft Date'; ?>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group" style="margin-bottom: 0;">
                                            <input type="date" name="draft_date"
                                                   value="<?php echo $league['draft_date'] ? date('Y-m-d', strtotime($league['draft_date'])) : ''; ?>"
                                                   min="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        <div class="form-group" style="margin-bottom: 0;">
                                            <input type="time" name="draft_time"
                                                   value="<?php echo $league['draft_date'] ? date('H:i', strtotime($league['draft_date'])) : '20:00'; ?>">
                                        </div>
                                        <div style="flex: 0;">
                                            <button type="submit" class="submit-btn btn-blue" style="width: auto; padding: 12px 18px;">
                                                <i class="fas fa-save"></i>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Tab: Join a League -->
        <div id="tab-join" class="tab-content <?php echo $activeTab === 'join' ? 'active' : ''; ?>">
            <div class="card">
                <div class="card-title">
                    <i class="fas fa-sign-in-alt"></i> Join a League
                </div>
                <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 20px;">
                    Enter the league PIN code provided by your commissioner to join their league.
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
                        <div class="help-text">PIN codes are 6 characters, provided by the league commissioner</div>
                    </div>
                    <button type="submit" class="submit-btn btn-blue">
                        <i class="fas fa-sign-in-alt"></i> Join League
                    </button>
                </form>
            </div>
        </div>

        <!-- Tab: Create a League -->
        <div id="tab-create" class="tab-content <?php echo $activeTab === 'create' ? 'active' : ''; ?>">
            <div class="card">
                <div class="card-title">
                    <i class="fas fa-plus-circle"></i> Create a New League
                </div>
                <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 20px;">
                    Start your own league as commissioner. You'll receive a unique PIN code to share with players.
                </p>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="create_league">

                    <div class="form-group">
                        <label for="league_name">League Name</label>
                        <input type="text" id="league_name" name="league_name"
                               placeholder="e.g. The Ballers League"
                               maxlength="50"
                               value="<?php echo htmlspecialchars($_POST['league_name'] ?? ''); ?>"
                               required>
                        <div class="help-text">3-50 characters</div>
                    </div>

                    <div class="form-group">
                        <label for="league_size">League Size</label>
                        <select id="league_size" name="league_size" required>
                            <option value="5" <?php echo (($_POST['league_size'] ?? '') == '5') ? 'selected' : ''; ?>>5 Participants (6 teams each)</option>
                            <option value="6" <?php echo (($_POST['league_size'] ?? '6') == '6') ? 'selected' : ''; ?>>6 Participants (5 teams each)</option>
                        </select>
                        <div class="help-text">30 NBA teams split among participants</div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="draft_date">Draft Date</label>
                            <input type="date" id="draft_date" name="draft_date"
                                   min="<?php echo date('Y-m-d'); ?>"
                                   value="<?php echo htmlspecialchars($_POST['draft_date'] ?? ''); ?>">
                            <div class="help-text">Optional - can be set later</div>
                        </div>
                        <div class="form-group">
                            <label for="draft_time">Draft Time</label>
                            <input type="time" id="draft_time" name="draft_time"
                                   value="<?php echo htmlspecialchars($_POST['draft_time'] ?? '20:00'); ?>">
                        </div>
                    </div>

                    <button type="submit" class="submit-btn btn-green">
                        <i class="fas fa-trophy"></i> Create League
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
    function switchTab(tabName) {
        // Update tab buttons
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        event.target.closest('.tab-btn')?.classList.add('active');

        // If called from empty state buttons (no .tab-btn parent)
        if (!event.target.closest('.tab-btn')) {
            document.querySelectorAll('.tab-btn').forEach(btn => {
                if (btn.textContent.toLowerCase().includes(tabName === 'join' ? 'join' : 'create')) {
                    btn.classList.add('active');
                }
            });
        }

        // Update tab content
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
        document.getElementById('tab-' + tabName)?.classList.add('active');

        // Update URL without reload
        history.replaceState(null, '', '?tab=' + tabName);
    }

    function copyPIN(elementId) {
        const pin = document.getElementById(elementId).textContent;
        navigator.clipboard.writeText(pin).then(() => {
            const btn = document.querySelector('#' + elementId + ' + .copy-btn');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check" style="color: var(--accent-green);"></i>';
            setTimeout(() => { btn.innerHTML = originalHTML; }, 1500);
        }).catch(() => {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = pin;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
        });
    }

    // Auto-uppercase PIN input
    document.getElementById('pin_code')?.addEventListener('input', function() {
        this.value = this.value.toUpperCase();
    });
    </script>
</body>
</html>