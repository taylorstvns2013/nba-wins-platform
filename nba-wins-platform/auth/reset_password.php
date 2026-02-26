<?php
// nba-wins-platform/auth/reset_password.php
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/db_connection.php';

$message = '';
$messageType = '';
$resetSuccess = false;

if ($_POST) {
    $resetCode = trim($_POST['reset_code'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($resetCode) || empty($newPassword) || empty($confirmPassword)) {
        $message = 'All fields are required.';
        $messageType = 'error';
    } elseif ($newPassword !== $confirmPassword) {
        $message = 'Passwords do not match.';
        $messageType = 'error';
    } elseif (strlen($newPassword) < 6) {
        $message = 'Password must be at least 6 characters long.';
        $messageType = 'error';
    } else {
        try {
            // Validate token
            $tokenHash = hash('sha256', $resetCode);
            
            $stmt = $pdo->prepare("
                SELECT prt.user_id, prt.expires_at, prt.used, u.username
                FROM password_reset_tokens prt
                JOIN users u ON prt.user_id = u.id
                WHERE prt.token_hash = ? AND prt.used = FALSE
                ORDER BY prt.created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$tokenHash]);
            $tokenData = $stmt->fetch();
            
            if (!$tokenData) {
                $message = 'Invalid or expired reset code. Please request a new one.';
                $messageType = 'error';
            } elseif (strtotime($tokenData['expires_at']) < time()) {
                $message = 'This reset code has expired. Please request a new one.';
                $messageType = 'error';
            } else {
                // Update password
                $pdo->beginTransaction();
                
                try {
                    // Hash new password
                    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    
                    // Update user password - FIXED to use only existing columns
                    $stmt = $pdo->prepare("
                        UPDATE users 
                        SET password_hash = ?, 
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $result = $stmt->execute([$passwordHash, $tokenData['user_id']]);
                    
                    if (!$result || $stmt->rowCount() === 0) {
                        throw new Exception("Failed to update password");
                    }
                    
                    // Mark token as used
                    $stmt = $pdo->prepare("
                        UPDATE password_reset_tokens 
                        SET used = TRUE 
                        WHERE token_hash = ?
                    ");
                    $stmt->execute([$tokenHash]);
                    
                    // Log successful reset
                    $stmt = $pdo->prepare("
                        INSERT INTO password_reset_log 
                        (user_id, email_attempted, username_attempted, success, ip_address, user_agent) 
                        VALUES (?, '', ?, TRUE, ?, ?)
                    ");
                    $stmt->execute([
                        $tokenData['user_id'],
                        $tokenData['username'],
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        $_SERVER['HTTP_USER_AGENT'] ?? ''
                    ]);
                    
                    $pdo->commit();
                    
                    $message = 'Password reset successfully! You can now login with your new password.';
                    $messageType = 'success';
                    $resetSuccess = true;
                    
                    // Clear any existing sessions for this user
                    $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?");
                    $stmt->execute([$tokenData['user_id']]);
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    
                    $message = 'An error occurred while resetting your password: ' . $e->getMessage();
                    $messageType = 'error';
                    error_log("Password reset error for user " . $tokenData['user_id'] . ": " . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            $message = 'An unexpected error occurred: ' . $e->getMessage();
            $messageType = 'error';
            error_log("Password reset validation error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="theme-color" content="#121a23">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - NBA Wins Pool</title>
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
            --accent-red: #f85149;
            --accent-orange: #d29922;
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
            display: flex;
            align-items: center;
            justify-content: center;
            -webkit-font-smoothing: antialiased;
            line-height: 1.5;
        }

        .reset-container {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-elevated);
            border: 1px solid var(--border-color);
            padding: 40px;
            width: 100%;
            max-width: 450px;
        }

        .logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo img {
            width: 80px;
            height: 80px;
            margin-bottom: 10px;
        }

        h1 {
            text-align: center;
            color: var(--text-primary);
            margin: 0 0 30px 0;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: 0.9rem;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 16px;
            font-family: 'Outfit', sans-serif;
            box-sizing: border-box;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            background-color: var(--bg-elevated);
            color: var(--text-primary);
        }

        input:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px var(--accent-blue-dim);
        }

        input::placeholder {
            color: var(--text-muted);
        }

        .help-text {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 6px;
        }

        .submit-btn {
            width: 100%;
            background: linear-gradient(135deg, var(--accent-blue), #1a6ddb);
            color: white;
            padding: 14px 20px;
            border: none;
            border-radius: var(--radius-md);
            font-size: 16px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            margin-top: 10px;
        }

        .submit-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(56, 139, 253, 0.3);
        }

        .message {
            padding: 12px 15px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            text-align: center;
            font-size: 0.92rem;
        }

        .message.success {
            background-color: rgba(63, 185, 80, 0.12);
            color: var(--accent-green);
            border: 1px solid rgba(63, 185, 80, 0.25);
        }

        .message.error {
            background-color: rgba(248, 81, 73, 0.12);
            color: var(--accent-red);
            border: 1px solid rgba(248, 81, 73, 0.25);
        }

        .password-strength {
            margin-top: 10px;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 13px;
            display: none;
        }

        .password-strength.weak {
            background-color: rgba(248, 81, 73, 0.12);
            color: var(--accent-red);
            border: 1px solid rgba(248, 81, 73, 0.2);
        }

        .password-strength.medium {
            background-color: rgba(210, 153, 34, 0.12);
            color: var(--accent-orange);
            border: 1px solid rgba(210, 153, 34, 0.2);
        }

        .password-strength.strong {
            background-color: rgba(63, 185, 80, 0.12);
            color: var(--accent-green);
            border: 1px solid rgba(63, 185, 80, 0.2);
        }

        .links {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }

        .links a {
            color: var(--accent-blue);
            text-decoration: none;
            font-weight: 500;
            margin: 0 10px;
            font-size: 0.92rem;
        }

        .links a:hover {
            text-decoration: underline;
        }

        .success-actions {
            text-align: center;
            margin-top: 20px;
        }

        .success-actions a {
            display: inline-block;
            background: linear-gradient(135deg, var(--accent-green), #2ea043);
            color: white;
            padding: 12px 30px;
            border-radius: var(--radius-md);
            text-decoration: none;
            font-weight: 600;
            margin: 10px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .success-actions a:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(63, 185, 80, 0.3);
        }

        .info-box {
            background-color: var(--accent-blue-dim);
            border: 1px solid rgba(56, 139, 253, 0.2);
            border-radius: var(--radius-md);
            padding: 15px;
            margin-bottom: 20px;
            font-size: 14px;
            color: var(--text-secondary);
        }

        .info-box p {
            margin: 0;
        }

        @media (max-width: 500px) {
            .reset-container {
                padding: 25px 20px;
                margin: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="logo">
            <img src="../public/assets/team_logos/Logo.png" alt="NBA Logo">
            <h1>Set New Password</h1>
        </div>

        <?php if (!$resetSuccess): ?>
            <div class="info-box">
                <p><i class="fas fa-info-circle" style="color: var(--accent-blue); margin-right: 6px;"></i> Enter the reset code you received and choose a new password.</p>
            </div>

            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" onsubmit="return validateForm()">
                <div class="form-group">
                    <label for="reset_code">Reset Code</label>
                    <input type="text" id="reset_code" name="reset_code" 
                           placeholder="Paste your reset code here"
                           value="<?php echo htmlspecialchars($_POST['reset_code'] ?? ''); ?>" 
                           required autofocus>
                    <div class="help-text">Paste the code you received from the password reset request</div>
                </div>

                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" 
                           onkeyup="checkPasswordStrength()" required>
                    <div class="help-text">Minimum 6 characters</div>
                    <div id="passwordStrength" class="password-strength"></div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" 
                           onkeyup="checkPasswordMatch()" required>
                    <div id="passwordMatch" class="help-text"></div>
                </div>

                <button type="submit" class="submit-btn">
                    <i class="fas fa-lock"></i> Reset Password
                </button>
            </form>

            <div class="links">
                <a href="forgot_password.php"><i class="fas fa-key"></i> Request New Code</a>
                <a href="login.php"><i class="fas fa-sign-in-alt"></i> Back to Login</a>
            </div>
        <?php else: ?>
            <div class="message success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
            </div>

            <div class="success-actions">
                <a href="login.php">
                    <i class="fas fa-sign-in-alt"></i> Login Now
                </a>
            </div>

            <div class="info-box">
                <p><i class="fas fa-info-circle" style="color: var(--accent-blue); margin-right: 6px;"></i> Your password has been successfully updated. All existing sessions have been cleared for security.</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function checkPasswordStrength() {
            const password = document.getElementById('new_password').value;
            const strengthDiv = document.getElementById('passwordStrength');
            
            if (password.length === 0) {
                strengthDiv.style.display = 'none';
                return;
            }
            
            strengthDiv.style.display = 'block';
            
            let strength = 0;
            if (password.length >= 8) strength++;
            if (password.match(/[a-z]+/)) strength++;
            if (password.match(/[A-Z]+/)) strength++;
            if (password.match(/[0-9]+/)) strength++;
            if (password.match(/[$@#&!]+/)) strength++;
            
            if (strength < 2) {
                strengthDiv.className = 'password-strength weak';
                strengthDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Weak password';
            } else if (strength < 4) {
                strengthDiv.className = 'password-strength medium';
                strengthDiv.innerHTML = '<i class="fas fa-shield-alt"></i> Medium strength';
            } else {
                strengthDiv.className = 'password-strength strong';
                strengthDiv.innerHTML = '<i class="fas fa-check-circle"></i> Strong password';
            }
        }
        
        function checkPasswordMatch() {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchDiv = document.getElementById('passwordMatch');
            
            if (confirmPassword.length === 0) {
                matchDiv.innerHTML = '';
                return;
            }
            
            if (password === confirmPassword) {
                matchDiv.innerHTML = '<span style="color: var(--accent-green);"><i class="fas fa-check"></i> Passwords match</span>';
            } else {
                matchDiv.innerHTML = '<span style="color: var(--accent-red);"><i class="fas fa-times"></i> Passwords do not match</span>';
            }
        }
        
        function validateForm() {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const resetCode = document.getElementById('reset_code').value.trim();
            
            if (resetCode.length === 0) {
                alert('Please enter your reset code!');
                return false;
            }
            
            if (password !== confirmPassword) {
                alert('Passwords do not match!');
                return false;
            }
            
            if (password.length < 6) {
                alert('Password must be at least 6 characters long!');
                return false;
            }
            
            return true;
        }
    </script>
</body>
</html>