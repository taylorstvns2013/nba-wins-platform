<?php
// admin/draft_admin.php - Admin page for managing draft settings
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['current_league_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '/data/www/default/nba-wins-platform/config/db_connection.php';

$user_id = $_SESSION['user_id'];
$league_id = $_SESSION['current_league_id'];
$currentLeagueId = $league_id; // Define for navigation menu

// Check if user is commissioner
$stmt = $pdo->prepare("
    SELECT l.*, u.display_name as commissioner_name
    FROM leagues l
    LEFT JOIN users u ON l.commissioner_user_id = u.id
    WHERE l.id = ?
");
$stmt->execute([$league_id]);
$league = $stmt->fetch();

if (!$league) {
    die("League not found");
}

$is_commissioner = $league['commissioner_user_id'] == $user_id || $league['commissioner_user_id'] === null;

if (!$is_commissioner) {
    die("Access denied. Commissioner privileges required.");
}

// Handle form submissions using POST-Redirect-GET pattern
$message = '';
$error = '';

// Display session messages if they exist
if (isset($_SESSION['admin_message'])) {
    $message = $_SESSION['admin_message'];
    unset($_SESSION['admin_message']);
}

if (isset($_SESSION['admin_error'])) {
    $error = $_SESSION['admin_error'];
    unset($_SESSION['admin_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    
    try {
        $pdo->beginTransaction();
        
        switch ($action) {
            case 'enable_draft':
                $stmt = $pdo->prepare("UPDATE leagues SET draft_enabled = TRUE, draft_completed = FALSE WHERE id = ?");
                $stmt->execute([$league_id]);
                $_SESSION['admin_message'] = "Draft enabled for league.";
                break;
                
            case 'disable_draft':
                $stmt = $pdo->prepare("UPDATE leagues SET draft_enabled = FALSE WHERE id = ?");
                $stmt->execute([$league_id]);
                $_SESSION['admin_message'] = "Draft disabled for league.";
                break;
                
            case 'reset_draft':
                // Delete draft data
                $stmt = $pdo->prepare("SELECT id FROM draft_sessions WHERE league_id = ?");
                $stmt->execute([$league_id]);
                $draft_sessions = $stmt->fetchAll();
                
                foreach ($draft_sessions as $session) {
                    // Delete draft picks
                    $stmt = $pdo->prepare("DELETE FROM draft_picks WHERE draft_session_id = ?");
                    $stmt->execute([$session['id']]);
                    
                    // Delete draft order
                    $stmt = $pdo->prepare("DELETE FROM draft_order WHERE draft_session_id = ?");
                    $stmt->execute([$session['id']]);
                    
                    // Delete draft log
                    $stmt = $pdo->prepare("DELETE FROM draft_log WHERE draft_session_id = ?");
                    $stmt->execute([$session['id']]);
                }
                
                // Delete draft sessions
                $stmt = $pdo->prepare("DELETE FROM draft_sessions WHERE league_id = ?");
                $stmt->execute([$league_id]);
                
                // Clear team assignments
                $stmt = $pdo->prepare("
                    DELETE lpt FROM league_participant_teams lpt
                    JOIN league_participants lp ON lpt.league_participant_id = lp.id
                    WHERE lp.league_id = ?
                ");
                $stmt->execute([$league_id]);
                
                // Reset league flags
                $stmt = $pdo->prepare("UPDATE leagues SET draft_completed = FALSE, draft_enabled = TRUE WHERE id = ?");
                $stmt->execute([$league_id]);
                
                $_SESSION['admin_message'] = "Draft reset successfully. All team assignments cleared.";
                break;
                
            case 'set_commissioner':
                $new_commissioner_id = (int)$_POST['commissioner_id'];
                
                // Verify user is in league
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count FROM league_participants 
                    WHERE user_id = ? AND league_id = ?
                ");
                $stmt->execute([$new_commissioner_id, $league_id]);
                $result = $stmt->fetch();
                
                if ($result['count'] > 0) {
                    $stmt = $pdo->prepare("UPDATE leagues SET commissioner_user_id = ? WHERE id = ?");
                    $stmt->execute([$new_commissioner_id, $league_id]);
                    $_SESSION['admin_message'] = "Commissioner updated successfully.";
                } else {
                    throw new Exception("Selected user is not a member of this league.");
                }
                break;
                
            default:
                throw new Exception("Invalid action.");
        }
        
        $pdo->commit();
        
        // POST-Redirect-GET: Redirect after successful POST to prevent re-submission
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['admin_error'] = "Error: " . $e->getMessage();
        
        // Redirect even on error to prevent re-submission
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Get current draft status
$stmt = $pdo->prepare("
    SELECT ds.*, COUNT(dp.id) as picks_made
    FROM draft_sessions ds
    LEFT JOIN draft_picks dp ON ds.id = dp.draft_session_id
    WHERE ds.league_id = ? AND ds.status IN ('pending', 'active', 'paused', 'completed')
    GROUP BY ds.id
    ORDER BY ds.created_at DESC 
    LIMIT 1
");
$stmt->execute([$league_id]);
$current_draft = $stmt->fetch();

// Get league participants for commissioner selection
$stmt = $pdo->prepare("
    SELECT lp.*, u.display_name, u.id as user_id
    FROM league_participants lp
    JOIN users u ON lp.user_id = u.id
    WHERE lp.league_id = ? AND lp.status = 'active'
    ORDER BY u.display_name
");
$stmt->execute([$league_id]);
$participants = $stmt->fetchAll();

// Get team assignment stats
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT lp.id) as total_participants,
        COUNT(lpt.id) as total_teams_assigned,
        COUNT(DISTINCT lpt.league_participant_id) as participants_with_teams
    FROM league_participants lp
    LEFT JOIN league_participant_teams lpt ON lp.id = lpt.league_participant_id
    WHERE lp.league_id = ? AND lp.status = 'active'
");
$stmt->execute([$league_id]);
$team_stats = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Draft Administration - <?= htmlspecialchars($league['display_name']) ?></title>
    <link rel="apple-touch-icon" type="image/png" href="../public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="../public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/react/18.2.0/umd/react.development.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/react-dom/18.2.0/umd/react-dom.development.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/babel-standalone/7.23.5/babel.min.js"></script>
    <style>
        :root {
            --primary-color: #212121;
            --secondary-color: #424242;
            --background-color: rgba(245, 245, 245, 0.8);
            --text-color: #333333;
            --border-color: #e0e0e0;
            --hover-color: #757575;
            --success-color: #4CAF50;
            --danger-color: #f44336;
            --warning-color: #ff9800;
            --info-color: #2196F3;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background-image: url('nba-wins-platform/public/assets/background/geometric_white.png');
            background-repeat: repeat;
            background-attachment: fixed;
            color: var(--text-color);
            background-color: #f5f5f5;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
            background-color: var(--background-color);
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        header {
            text-align: center;
            margin-bottom: 30px;
            background-color: rgba(255,255,255,0.8);
            padding: 20px;
            border-radius: 8px;
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
            color: var(--secondary-color);
            margin: 5px 0;
            font-size: 20px;
        }
        
        .commissioner-info {
            color: var(--secondary-color);
            font-size: 14px;
            margin-top: 10px;
        }
        
        .card {
            background: white;
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .card h3 {
            color: var(--primary-color);
            margin-top: 0;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 10px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: bold;
            border-left: 4px solid;
        }
        
        .alert-success { 
            background: rgba(76, 175, 80, 0.1); 
            border-left-color: var(--success-color);
            color: #2e7d32;
        }
        
        .alert-error { 
            background: rgba(244, 67, 54, 0.1); 
            border-left-color: var(--danger-color);
            color: #c62828;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-block;
            margin: 5px;
            font-size: 14px;
        }
        
        .btn-primary { 
            background: var(--success-color); 
            color: white; 
        }
        
        .btn-danger { 
            background: var(--danger-color); 
            color: white; 
        }
        
        .btn-warning { 
            background: var(--warning-color); 
            color: white; 
        }
        
        .btn-secondary { 
            background: var(--secondary-color); 
            color: white; 
        }
        
        .btn-info { 
            background: var(--info-color); 
            color: white; 
        }
        
        .btn:hover { 
            transform: translateY(-1px); 
            box-shadow: 0 4px 8px rgba(0,0,0,0.15); 
            opacity: 0.9;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .form-group select, .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            background: white;
            color: var(--text-color);
            font-size: 16px;
            transition: border-color 0.2s ease;
        }
        
        .form-group select:focus, .form-group input:focus {
            outline: none;
            border-color: var(--info-color);
        }
        
        .form-group select option {
            background: white;
            color: var(--text-color);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: rgba(33, 150, 243, 0.05);
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid var(--border-color);
        }
        
        .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            color: var(--info-color);
        }
        
        .stat-label {
            font-size: 0.9em;
            color: var(--secondary-color);
            margin-top: 5px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .danger-zone {
            border: 2px solid var(--danger-color);
            background: rgba(244, 67, 54, 0.05);
        }
        
        .danger-zone h3 {
            color: var(--danger-color);
            border-bottom-color: var(--danger-color);
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            color: var(--text-color);
            text-decoration: none;
            font-weight: 500;
            padding: 8px 16px;
            background: rgba(255,255,255,0.8);
            border-radius: 6px;
            transition: background-color 0.2s;
        }
        
        .back-link:hover {
            background: rgba(255,255,255,1);
            color: var(--info-color);
        }
        
        .status-indicator {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: bold;
            text-transform: uppercase;
            margin-left: 10px;
        }
        
        .status-enabled { 
            background: var(--success-color); 
            color: white;
        }
        
        .status-disabled { 
            background: var(--secondary-color); 
            color: white;
        }
        
        .status-completed { 
            background: var(--info-color); 
            color: white;
        }
        
        .status-active { 
            background: var(--warning-color); 
            color: white;
        }
        
        .status-paused { 
            background: #9c27b0; 
            color: white;
        }
        
        .instructions-list {
            color: var(--text-color);
            margin-left: 20px;
        }
        
        .instructions-list li {
            margin-bottom: 5px;
        }
        
        .status-info {
            background: rgba(33, 150, 243, 0.1);
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid var(--info-color);
            margin: 15px 0;
        }
        
        .draft-completed-notice {
            background: rgba(76, 175, 80, 0.1);
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid var(--success-color);
            margin-bottom: 20px;
        }
        
        .draft-completed-notice h4 {
            color: var(--success-color);
            margin-top: 0;
            margin-bottom: 10px;
        }
        
        .draft-completed-notice p {
            margin: 0;
            color: var(--text-color);
        }
        
        /* Navigation Menu Styles */
        .menu-container {
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
        }
        
        .menu-button {
            position: fixed;
            top: 1rem;
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

        /* Mobile Optimizations */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            .container {
                padding: 15px;
                margin: 0;
                border-radius: 0;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 15px;
            }

            .stat-number {
                font-size: 2em;
            }

            .btn {
                padding: 10px 16px;
                font-size: 13px;
                margin: 3px;
            }
        }
    </style>
</head>
<body>
    <div id="navigation-root"></div>
    
    <div class="container">
        
        <header>
            <img src="../public/assets/team_logos/Logo.png" alt="NBA Logo" class="basketball-logo">
            <h1>Draft Administration</h1>
            <h2><?= htmlspecialchars($league['display_name']) ?></h2>
            <div class="commissioner-info">Commissioner: <?= $league['commissioner_name'] ?: 'Not Set' ?></div>
        </header>
        
        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <!-- Draft Completed Notice -->
        <?php if ($league['draft_completed']): ?>
            <div class="draft-completed-notice">
                <h4><i class="fas fa-check-circle"></i> Draft Completed</h4>
                <p>This league's draft has been completed and teams have been assigned. The season is now underway!</p>
            </div>
        <?php endif; ?>
        
        <!-- Current Status -->
        <div class="card">
            <h3><i class="fas fa-chart-bar"></i> Current Status</h3>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?= $team_stats['total_participants'] ?></div>
                    <div class="stat-label">Participants</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $team_stats['total_teams_assigned'] ?></div>
                    <div class="stat-label">Teams Assigned</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $team_stats['participants_with_teams'] ?></div>
                    <div class="stat-label">Have Teams</div>
                </div>
            </div>
            
            <div class="status-info">
                <p><strong>Draft Status:</strong> 
                    <span class="status-indicator status-<?= $league['draft_enabled'] ? 'enabled' : 'disabled' ?>">
                        <?= $league['draft_enabled'] ? 'Enabled' : 'Disabled' ?>
                    </span>
                    
                    <?php if ($league['draft_completed']): ?>
                        <span class="status-indicator status-completed">Completed</span>
                    <?php endif; ?>
                </p>
                
                <?php if ($current_draft): ?>
                    <p><strong>Current Draft:</strong> 
                        <span class="status-indicator status-<?= $current_draft['status'] ?>">
                            <?= ucfirst($current_draft['status']) ?>
                        </span>
                        (<?= $current_draft['picks_made'] ?> picks made)
                    </p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Draft Controls -->
        <div class="card">
            <h3><i class="fas fa-cogs"></i> Draft Controls</h3>
            
            <div style="margin-bottom: 20px;">
                <?php if (!$league['draft_enabled']): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="enable_draft">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-play"></i> Enable Draft
                        </button>
                    </form>
                <?php else: ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="disable_draft">
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-pause"></i> Disable Draft
                        </button>
                    </form>
                <?php endif; ?>
                
                <a href="draft.php" class="btn btn-info">
                    <i class="fas fa-external-link-alt"></i> Go to Live Draft
                </a>
            </div>
            
            <p><strong>Draft Status Information:</strong></p>
            <ul class="instructions-list">
                <li><strong>Enabled:</strong> Commissioners can start drafts</li>
                <li><strong>Disabled:</strong> Draft functionality is locked</li>
                <li><strong>Completed:</strong> Draft has finished, teams assigned</li>
            </ul>
        </div>
        
        <!-- Commissioner Management -->
        <div class="card">
            <h3><i class="fas fa-crown"></i> Commissioner Management</h3>
            
            <form method="POST">
                <input type="hidden" name="action" value="set_commissioner">
                <div class="form-group">
                    <label for="commissioner_id">Set Commissioner:</label>
                    <select name="commissioner_id" id="commissioner_id" required>
                        <option value="">Select Participant</option>
                        <?php foreach ($participants as $participant): ?>
                            <option value="<?= $participant['user_id'] ?>" 
                                    <?= $participant['user_id'] == $league['commissioner_user_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($participant['display_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Update Commissioner
                </button>
            </form>
            
            <div class="status-info" style="margin-top: 15px;">
                <i class="fas fa-info-circle"></i>
                The commissioner can start drafts, make picks for others, and pause/resume drafts.
            </div>
        </div>
        
        <!-- Instructions -->
        <div class="card">
            <h3><i class="fas fa-book"></i> Instructions</h3>
            
            <h4>Starting a Draft:</h4>
            <ol class="instructions-list">
                <li>Ensure all participants have joined the league</li>
                <li>Make sure draft is enabled</li>
                <li>Go to the Live Draft page</li>
                <li>Click "Start Draft" (only commissioners can do this)</li>
                <li>Draft order will be randomized automatically</li>
            </ol>
            
            <h4>During the Draft:</h4>
            <ul class="instructions-list">
                <li>Commissioners can make picks for absent participants</li>
                <li>Commissioners can pause/resume the draft</li>
                <li>The page updates automatically every 6 seconds</li>
            </ul>
            
            <h4>Snake Draft Logic:</h4>
            <ul class="instructions-list">
                <li>Round 1: 1st → 2nd → 3rd → 4th → 5th</li>
                <li>Round 2: 5th → 4th → 3rd → 2nd → 1st</li>
                <li>Round 3: 1st → 2nd → 3rd → 4th → 5th</li>
                <li>And so on...</li>
            </ul>
        </div>
        
        <!-- Danger Zone - Only show if draft is NOT completed -->
        <?php if (!$league['draft_completed']): ?>
        <div class="card danger-zone">
            <h3><i class="fas fa-exclamation-triangle"></i> Danger Zone</h3>
            
            <p style="margin-bottom: 20px;">
                <strong>Reset Draft:</strong> This will permanently delete all draft data, 
                team assignments, and draft history for this league. This action cannot be undone.
            </p>
            
            <form method="POST" onsubmit="return confirm('Are you absolutely sure you want to reset the draft? This will delete ALL team assignments and draft data. This cannot be undone!');">
                <input type="hidden" name="action" value="reset_draft">
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash-alt"></i> Reset Draft
                </button>
            </form>
            
            <div style="margin-top: 20px; font-size: 0.9em;">
                <p><strong>What gets reset:</strong></p>
                <ul class="instructions-list">
                    <li>All draft sessions and picks</li>
                    <li>All team assignments</li>
                    <li>Draft order and history</li>
                    <li>Draft completion status</li>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script type="text/babel">
        const NavigationMenu = () => {
            const [isOpen, setIsOpen] = React.useState(false);
        
            const toggleMenu = () => {
                setIsOpen(!isOpen);
            };
        
            return (
                <div className="menu-container">
                    <button
                        onClick={toggleMenu}
                        className="menu-button"
                        aria-label="Toggle menu"
                    >
                        <i className="fas fa-bars"></i>
                    </button>
        
                    {isOpen && (
                        <div 
                            className="menu-overlay"
                            onClick={toggleMenu}
                        />
                    )}
        
                    <div className={`menu-panel ${isOpen ? 'menu-open' : ''}`}>
                        <div className="menu-header">
                            <button onClick={toggleMenu} className="close-button">
                                <i className="fas fa-times"></i>
                            </button>
                        </div>
                        <nav className="menu-content">
                            <ul className="menu-list">
                                <li>
                                    <a href="../../index.php" className="menu-link">
                                        <i className="fas fa-home"></i>
                                        Home
                                    </a>
                                </li>
                                <li>
                                    <a href={`../profiles/participant_profile.php?league_id=<?php echo $currentLeagueId; ?>&user_id=<?php echo $_SESSION['user_id']; ?>`} className="menu-link">
                                        <i className="fas fa-user"></i>
                                        My Profile
                                    </a>
                                </li>
                                <li>
                                    <a href="../../analytics.php" className="menu-link">
                                        <i className="fas fa-chart-line"></i>
                                        Analytics
                                    </a>
                                </li>
                                <li>
                                    <a href="../../nba_standings.php" className="menu-link">
                                        <i className="fas fa-basketball-ball"></i>
                                        NBA Standings
                                    </a>
                                </li>
                                <li>
                                    <a href="../../draft.php" className="menu-link">
                                        <i className="fas fa-file-alt"></i>
                                        Draft
                                    </a>
                                </li>
                                <li>
                                    <a href="https://buymeacoffee.com/taylorstvns" className="menu-link" target="_blank" rel="noopener noreferrer">
                                        <i className="fas fa-coffee"></i>
                                        Buy Me a Coffee
                                    </a>
                                </li>
                                <li>
                                    <a href="../auth/logout.php" className="menu-link">
                                        <i className="fas fa-sign-out-alt"></i>
                                        Logout
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                </div>
            );
        };
        
        const container = document.getElementById('navigation-root');
        const root = ReactDOM.createRoot(container);
        root.render(<NavigationMenu />);
    </script>
</body>
</html>