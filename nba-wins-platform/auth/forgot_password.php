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
    <meta name="theme-color" content="#121a23">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - NBA Wins Pool</title>
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
        input[type="email"] {
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

        .message.info {
            background-color: var(--accent-blue-dim);
            color: var(--accent-blue);
            border: 1px solid rgba(56, 139, 253, 0.25);
        }

        .reset-code {
            background-color: rgba(210, 153, 34, 0.1);
            border: 1px solid rgba(210, 153, 34, 0.35);
            border-radius: var(--radius-md);
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }

        .reset-code h3 {
            color: var(--accent-orange);
            margin: 0 0 15px 0;
            font-size: 1rem;
            font-weight: 600;
        }

        .code-display {
            background-color: var(--bg-elevated);
            border: 2px dashed rgba(210, 153, 34, 0.4);
            padding: 15px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            font-weight: bold;
            color: var(--text-primary);
            word-break: break-all;
            user-select: all;
            cursor: text;
            line-height: 1.4;
        }

        .copy-btn {
            background: var(--accent-orange);
            color: #1a1a1a;
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius-md);
            margin: 15px 5px 10px 5px;
            cursor: pointer;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            font-size: 0.9rem;
            transition: opacity 0.2s;
        }

        .copy-btn:hover {
            opacity: 0.85;
        }

        .continue-btn {
            display: inline-block;
            background: linear-gradient(135deg, var(--accent-blue), #1a6ddb);
            color: white;
            padding: 12px 25px;
            border-radius: var(--radius-md);
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

        .info-box {
            background-color: var(--accent-blue-dim);
            border: 1px solid rgba(56, 139, 253, 0.2);
            border-radius: var(--radius-md);
            padding: 15px;
            margin-bottom: 20px;
            font-size: 14px;
            color: var(--text-secondary);
        }

        .info-box h4 {
            margin: 0 0 10px 0;
            color: var(--accent-blue);
            font-weight: 600;
        }

        .info-box p {
            margin: 0;
        }

        .security-question-box {
            background: rgba(210, 153, 34, 0.08);
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
            border-left: 4px solid var(--accent-orange);
            color: var(--text-primary);
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
                    <div class="security-question-box">
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
                    <a href="forgot_password.php" style="color: var(--accent-blue); text-decoration: none; font-size: 14px;">
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
                
                <p style="margin-top: 15px; color: var(--accent-orange); font-size: 13px;">
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