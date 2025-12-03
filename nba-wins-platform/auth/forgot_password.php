<?php
// nba-wins-platform/auth/forgot_password.php
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/db_connection.php';

$message = '';
$messageType = '';
$showResetCode = false;
$resetCode = '';
$step = 1; // Track which step we're on
$userInfo = null; // Store user info between steps

if ($_POST) {
    if (isset($_POST['step']) && $_POST['step'] == '2') {
        // Step 2: Process security answer
        $securityAnswer = trim($_POST['security_answer'] ?? '');
        $username = $_POST['username'] ?? '';
        $email = $_POST['email'] ?? '';
        
        if (empty($securityAnswer)) {
            $message = 'Security answer is required.';
            $messageType = 'error';
            $step = 2;
            
            // Re-fetch user info to show the question again
            $stmt = $pdo->prepare("
                SELECT id, username, email, security_question, security_answer_hash
                FROM users 
                WHERE username = ? AND email = ? AND status = 'active'
            ");
            $stmt->execute([$username, $email]);
            $userInfo = $stmt->fetch();
        } else {
            // Find user and verify answer
            $stmt = $pdo->prepare("
                SELECT id, username, email, security_question, security_answer_hash
                FROM users 
                WHERE username = ? AND email = ? AND status = 'active'
            ");
            $stmt->execute([$username, $email]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $message = 'Invalid user information.';
                $messageType = 'error';
                $step = 1;
            } else {
                // Verify security answer (case-insensitive)
                $answerCorrect = password_verify(strtolower($securityAnswer), $user['security_answer_hash']);
                
                if ($answerCorrect) {
                    // Generate reset token
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);
                    $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry
                    
                    // Clear any existing tokens for this user
                    $stmt = $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?");
                    $stmt->execute([$user['id']]);
                    
                    // Store token
                    $stmt = $pdo->prepare("
                        INSERT INTO password_reset_tokens 
                        (user_id, token, token_hash, expires_at, used, ip_address) 
                        VALUES (?, ?, ?, ?, FALSE, ?)
                    ");
                    $stmt->execute([$user['id'], $token, $tokenHash, $expires, $_SERVER['REMOTE_ADDR'] ?? '']);
                    
                    // Log success
                    $stmt = $pdo->prepare("
                        INSERT INTO password_reset_log 
                        (user_id, email_attempted, username_attempted, success, ip_address, user_agent) 
                        VALUES (?, ?, ?, TRUE, ?, ?)
                    ");
                    $stmt->execute([
                        $user['id'], 
                        $email, 
                        $username, 
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        $_SERVER['HTTP_USER_AGENT'] ?? ''
                    ]);
                    
                    // Show reset code
                    $showResetCode = true;
                    $resetCode = $token;
                    $message = 'Reset code generated successfully. Please save this code:';
                    $messageType = 'success';
                } else {
                    $message = "Incorrect security answer.";
                    $messageType = 'error';
                    $step = 2;
                    $userInfo = $user; // Keep user info to show question again
                    
                    // Log failed attempt
                    $stmt = $pdo->prepare("
                        INSERT INTO password_reset_log 
                        (user_id, email_attempted, username_attempted, success, failure_reason, ip_address, user_agent) 
                        VALUES (?, ?, ?, FALSE, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $user['id'], 
                        $email, 
                        $username, 
                        'Wrong security answer',
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        $_SERVER['HTTP_USER_AGENT'] ?? ''
                    ]);
                }
            }
        }
    } else {
        // Step 1: Validate username and email, then show security question
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        if (empty($username) || empty($email)) {
            $message = 'Username and email are required.';
            $messageType = 'error';
            $step = 1;
        } else {
            // Find user
            $stmt = $pdo->prepare("
                SELECT id, username, email, security_question, security_answer_hash
                FROM users 
                WHERE username = ? AND email = ? AND status = 'active'
            ");
            $stmt->execute([$username, $email]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $message = 'If the account information matches our records, you will see the security question.';
                $messageType = 'info';
                $step = 1;
            } elseif (empty($user['security_answer_hash'])) {
                $message = 'Security question not configured. Please contact an administrator.';
                $messageType = 'error';
                $step = 1;
            } else {
                // Show security question
                $step = 2;
                $userInfo = $user;
                $message = 'Please answer your security question:';
                $messageType = 'success';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - NBA Wins Pool</title>
    <link rel="apple-touch-icon" type="image/png" href="../public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="../public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-image: url('../public/assets/background/geometric_white.png');
            background-repeat: repeat;
            background-attachment: fixed;
            background-color: #f5f5f5;
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .reset-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
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
            color: #333;
            margin: 0 0 30px 0;
            font-size: 28px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
        }

        input[type="text"],
        input[type="email"] {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            box-sizing: border-box;
            transition: border-color 0.3s ease;
        }

        input:focus {
            outline: none;
            border-color: #2196F3;
        }

        .help-text {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }

        .submit-btn {
            width: 100%;
            background: linear-gradient(135deg, #2196F3, #1976D2);
            color: white;
            padding: 14px 20px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease;
            margin-top: 10px;
        }

        .submit-btn:hover {
            transform: translateY(-1px);
        }

        .message {
            padding: 12px 15px;
            border-radius: 6px;
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

        .message.info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .reset-code {
            background-color: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }

        .reset-code h3 {
            color: #856404;
            margin: 0 0 15px 0;
        }

        .code-display {
            background-color: #fff;
            border: 2px dashed #ffc107;
            padding: 15px;
            font-family: 'Courier New', monospace;
            font-size: 16px;
            font-weight: bold;
            color: #333;
            word-break: break-all;
            user-select: all;
            cursor: text;
            line-height: 1.4;
        }

        .copy-btn {
            background: #ffc107;
            color: #333;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            margin: 15px 5px 10px 5px;
            cursor: pointer;
            font-weight: 600;
        }

        .copy-btn:hover {
            background: #ffb300;
        }

        .continue-btn {
            display: inline-block;
            background: linear-gradient(135deg, #2196F3, #1976D2);
            color: white;
            padding: 12px 25px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            margin: 5px;
            transition: transform 0.2s ease;
        }

        .continue-btn:hover {
            transform: translateY(-1px);
        }

        .links {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .links a {
            color: #2196F3;
            text-decoration: none;
            font-weight: 500;
            margin: 0 10px;
        }

        .links a:hover {
            text-decoration: underline;
        }

        .info-box {
            background-color: #e3f2fd;
            border: 1px solid #bbdefb;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .info-box h4 {
            margin: 0 0 10px 0;
            color: #1565c0;
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
            <h1>Password Reset</h1>
        </div>

        <?php if (!$showResetCode): ?>
            <?php if ($step == 1): ?>
                <!-- Step 1: Get username and email -->
                <div class="info-box">
                    <h4><i class="fas fa-info-circle"></i> Step 1: Account Information</h4>
                    <p>Enter your username and email to see your security question.</p>
                </div>

                <?php if ($message && $step == 1): ?>
                    <div class="message <?php echo $messageType; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" 
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" 
                               required autofocus>
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                               required>
                    </div>

                    <button type="submit" class="submit-btn">
                        <i class="fas fa-arrow-right"></i> Continue to Security Question
                    </button>
                </form>
            
            <?php elseif ($step == 2 && $userInfo): ?>
                <!-- Step 2: Show security question and get answer -->
                <div class="info-box">
                    <h4><i class="fas fa-question-circle"></i> Step 2: Security Question</h4>
                    <p><strong>Your Security Question:</strong></p>
                    <div style="background: #fff3cd; padding: 15px; border-radius: 6px; margin: 10px 0; border-left: 4px solid #ffc107;">
                        <strong><?php echo htmlspecialchars($userInfo['security_question']); ?></strong>
                    </div>
                </div>

                <?php if ($message && $step == 2): ?>
                    <div class="message <?php echo $messageType; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <!-- Hidden fields to maintain state -->
                    <input type="hidden" name="step" value="2">
                    <input type="hidden" name="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">

                    <div class="form-group">
                        <label for="security_answer">Your Answer</label>
                        <input type="text" id="security_answer" name="security_answer" 
                               placeholder="Enter your answer here" required autofocus>
                        <div class="help-text">Enter the answer to your security question above</div>
                    </div>

                    <button type="submit" class="submit-btn">
                        <i class="fas fa-key"></i> Generate Reset Code
                    </button>
                </form>

                <div style="text-align: center; margin-top: 15px;">
                    <a href="forgot_password.php" style="color: #2196F3; text-decoration: none; font-size: 14px;">
                        <i class="fas fa-arrow-left"></i> Back to Step 1
                    </a>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="message success">
                <?php echo htmlspecialchars($message); ?>
            </div>

            <div class="reset-code">
                <h3><i class="fas fa-exclamation-triangle"></i> Important: Save This Code!</h3>
                <div class="code-display" id="resetCode"><?php echo htmlspecialchars($resetCode); ?></div>
                
                <div style="margin-top: 15px;">
                    <button class="copy-btn" onclick="copyCode()">
                        <i class="fas fa-copy"></i> Copy Code
                    </button>
                    <a href="reset_password.php" class="continue-btn">
                        <i class="fas fa-arrow-right"></i> Continue to Reset Password
                    </a>
                </div>
                
                <p style="margin-top: 15px; color: #856404; font-size: 14px;">
                    This code is valid for 1 hour. Save it now as it won't be shown again!
                </p>
            </div>

            <script>
                function copyCode() {
                    const codeElement = document.getElementById('resetCode');
                    const range = document.createRange();
                    range.selectNode(codeElement);
                    window.getSelection().removeAllRanges();
                    window.getSelection().addRange(range);
                    document.execCommand('copy');
                    window.getSelection().removeAllRanges();
                    
                    const btn = event.target;
                    const originalText = btn.innerHTML;
                    btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                    setTimeout(() => {
                        btn.innerHTML = originalText;
                    }, 2000);
                }
            </script>
        <?php endif; ?>

        <div class="links">
            <a href="login.php"><i class="fas fa-sign-in-alt"></i> Back to Login</a>
            <?php if ($showResetCode): ?>
                <a href="reset_password.php"><i class="fas fa-lock"></i> Reset Password</a>
            <?php else: ?>
                <a href="register.php"><i class="fas fa-user-plus"></i> Register</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>