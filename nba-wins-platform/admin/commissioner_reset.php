<?php
// nba-wins-platform/admin/commissioner_reset.php
session_start();
require_once '../config/db_connection.php';
require_once '../core/UserAuthentication.php';

$auth = new UserAuthentication($pdo);

// Check if user is logged in
if (!$auth->isAuthenticated()) {
    header('Location: ../auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$message = '';
$messageType = '';

// Get leagues where user is commissioner
$stmt = $pdo->prepare("
    SELECT l.id, l.display_name, l.allow_commissioner_reset
    FROM leagues l
    WHERE l.commissioner_user_id = ? AND l.status = 'active'
    ORDER BY l.league_number
");
$stmt->execute([$userId]);
$commissionerLeagues = $stmt->fetchAll();

if (empty($commissionerLeagues)) {
    die("Access denied. You are not a commissioner of any leagues.");
}

// Handle password reset
if ($_POST && isset($_POST['reset_password'])) {
    $leagueId = intval($_POST['league_id'] ?? 0);
    $participantUserId = intval($_POST['participant_user_id'] ?? 0);
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Verify user is commissioner of this league
    $isCommissioner = false;
    foreach ($commissionerLeagues as $league) {
        if ($league['id'] == $leagueId && $league['allow_commissioner_reset']) {
            $isCommissioner = true;
            break;
        }
    }
    
    if (!$isCommissioner) {
        $message = 'Access denied. You are not authorized to reset passwords in this league.';
        $messageType = 'error';
    } elseif ($newPassword !== $confirmPassword) {
        $message = 'Passwords do not match.';
        $messageType = 'error';
    } elseif (strlen($newPassword) < 6) {
        $message = 'Password must be at least 6 characters long.';
        $messageType = 'error';
    } else {
        // Verify participant is in the league
        $stmt = $pdo->prepare("
            SELECT u.username, u.display_name
            FROM users u
            JOIN league_participants lp ON u.id = lp.user_id
            WHERE lp.league_id = ? AND u.id = ? AND lp.status = 'active'
        ");
        $stmt->execute([$leagueId, $participantUserId]);
        $participant = $stmt->fetch();
        
        if (!$participant) {
            $message = 'Participant not found in this league.';
            $messageType = 'error';
        } else {
            // Reset password
            try {
                $passwordHash = password_hash($newPassword, PASSWORD_ARGON2ID, [
                    'memory_cost' => 65536,
                    'time_cost' => 4,
                    'threads' => 1
                ]);
                
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET password_hash = ?, 
                        failed_attempts = 0,
                        failed_reset_attempts = 0,
                        reset_lockout_until = NULL,
                        last_attempt = NULL,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$passwordHash, $participantUserId]);
                
                // Log the reset
                $stmt = $pdo->prepare("
                    INSERT INTO password_reset_log 
                    (user_id, success, failure_reason, ip_address, user_agent, created_at) 
                    VALUES (?, 1, CONCAT('Commissioner reset by user ID ', ?), ?, ?, NOW())
                ");
                $stmt->execute([
                    $participantUserId,
                    $userId,
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
                
                $message = "Password successfully reset for {$participant['display_name']} ({$participant['username']})";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Error resetting password. Please try again.';
                $messageType = 'error';
                error_log("Commissioner password reset error: " . $e->getMessage());
            }
        }
    }
}

// Get participants for selected league
$selectedLeagueId = $_GET['league_id'] ?? $commissionerLeagues[0]['id'];
$participants = [];

if ($selectedLeagueId) {
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.display_name, u.last_login,
               lp.joined_at, lp.draft_position
        FROM users u
        JOIN league_participants lp ON u.id = lp.user_id
        WHERE lp.league_id = ? AND lp.status = 'active'
        ORDER BY u.display_name
    ");
    $stmt->execute([$selectedLeagueId]);
    $participants = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commissioner Password Reset - NBA Wins Pool</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }

        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }

        .commissioner-badge {
            background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
            color: white;
            padding: 8px 20px;
            border-radius: 20px;
            display: inline-block;
            margin-bottom: 20px;
            font-weight: bold;
        }

        .league-selector {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }

        .league-selector select {
            width: 100%;
            padding: 10px;
            border: 2px solid #dee2e6;
            border-radius: 5px;
            font-size: 16px;
        }

        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }

        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .participants-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }

        .participants-table th {
            background: #6c757d;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }

        .participants-table td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
        }

        .participants-table tr:hover {
            background-color: #f8f9fa;
        }

        .reset-btn {
            background: #dc3545;
            color: white;
            padding: 6px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }

        .reset-btn:hover {
            background: #c82333;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: #000;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .form-group input {
            width: 100%;
            padding: 10px;
            border: 2px solid #dee2e6;
            border-radius: 5px;
            font-size: 16px;
        }

        .submit-btn {
            width: 100%;
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            padding: 12px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
        }

        .submit-btn:hover {
            transform: translateY(-1px);
        }

        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .warning-box h3 {
            color: #856404;
            margin-top: 0;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #007bff;
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .last-login {
            font-size: 12px;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="../index.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to League
        </a>
        
        <h1><i class="fas fa-user-shield"></i> Commissioner Password Reset</h1>
        
        <div class="commissioner-badge">
            <i class="fas fa-crown"></i> Commissioner Access
        </div>
        
        <div class="warning-box">
            <h3><i class="fas fa-exclamation-triangle"></i> Important Notice</h3>
            <p>As a league commissioner, you have the ability to reset passwords for participants in your league. 
            This should only be used when a participant has forgotten their password and cannot reset it themselves.</p>
            <p><strong>Please use this feature responsibly.</strong> All password resets are logged for security purposes.</p>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="league-selector">
            <label for="league_select"><strong>Select League:</strong></label>
            <select id="league_select" onchange="changeLeague(this.value)">
                <?php foreach ($commissionerLeagues as $league): ?>
                    <option value="<?php echo $league['id']; ?>" 
                            <?php echo ($league['id'] == $selectedLeagueId) ? 'selected' : ''; ?>
                            <?php echo !$league['allow_commissioner_reset'] ? 'disabled' : ''; ?>>
                        <?php echo htmlspecialchars($league['display_name']); ?>
                        <?php echo !$league['allow_commissioner_reset'] ? ' (Reset Disabled)' : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <h2>League Participants</h2>
        
        <table class="participants-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Last Login</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($participants as $participant): ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($participant['display_name']); ?></strong>
                        <?php if ($participant['draft_position']): ?>
                            <span style="color: #6c757d; font-size: 12px;">
                                (Pick #<?php echo $participant['draft_position']; ?>)
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($participant['username']); ?></td>
                    <td><?php echo htmlspecialchars($participant['email']); ?></td>
                    <td>
                        <?php if ($participant['last_login']): ?>
                            <span class="last-login">
                                <?php echo date('M d, Y g:i A', strtotime($participant['last_login'])); ?>
                            </span>
                        <?php else: ?>
                            <span class="last-login">Never</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="reset-btn" onclick="openResetModal(<?php echo $participant['id']; ?>, '<?php echo htmlspecialchars($participant['display_name']); ?>', '<?php echo htmlspecialchars($participant['username']); ?>')">
                            <i class="fas fa-key"></i> Reset Password
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Reset Password Modal -->
    <div id="resetModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeResetModal()">&times;</span>
            <h2>Reset Password</h2>
            <p>Resetting password for: <strong id="resetUserName"></strong></p>
            
            <form method="POST" action="" onsubmit="return confirmReset()">
                <input type="hidden" name="reset_password" value="1">
                <input type="hidden" name="league_id" value="<?php echo $selectedLeagueId; ?>">
                <input type="hidden" id="participant_user_id" name="participant_user_id" value="">
                
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required minlength="6">
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
                </div>
                
                <button type="submit" class="submit-btn">
                    <i class="fas fa-lock"></i> Reset Password
                </button>
            </form>
        </div>
    </div>
    
    <script>
        function changeLeague(leagueId) {
            window.location.href = '?league_id=' + leagueId;
        }
        
        function openResetModal(userId, displayName, username) {
            document.getElementById('participant_user_id').value = userId;
            document.getElementById('resetUserName').textContent = displayName + ' (' + username + ')';
            document.getElementById('resetModal').style.display = 'block';
        }
        
        function closeResetModal() {
            document.getElementById('resetModal').style.display = 'none';
            document.getElementById('new_password').value = '';
            document.getElementById('confirm_password').value = '';
        }
        
        function confirmReset() {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                alert('Passwords do not match!');
                return false;
            }
            
            if (password.length < 6) {
                alert('Password must be at least 6 characters long!');
                return false;
            }
            
            return confirm('Are you sure you want to reset this user\'s password?');
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('resetModal');
            if (event.target == modal) {
                closeResetModal();
            }
        }
    </script>
</body>
</html>