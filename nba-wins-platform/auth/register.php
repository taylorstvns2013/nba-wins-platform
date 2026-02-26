<?php
// nba-wins-platform/auth/register.php
require_once '../config/db_connection.php';
require_once '../core/UserAuthentication.php';

$auth = new UserAuthentication($pdo);
$message = '';
$messageType = '';

// Get security questions
$stmt = $pdo->query("
    SELECT id, question 
    FROM security_questions 
    WHERE active = TRUE 
    ORDER BY sort_order
");
$securityQuestions = $stmt->fetchAll();

if ($_POST) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $displayName = trim($_POST['display_name'] ?? '');
    $leaguePins = trim($_POST['league_pins'] ?? '');
    $securityQuestion = trim($_POST['security_question'] ?? '');
    $securityAnswer = trim($_POST['security_answer'] ?? '');
    
    // Basic validation
    if (empty($username) || empty($email) || empty($password) || empty($displayName) || 
        empty($leaguePins) || empty($securityQuestion) || empty($securityAnswer)) {
        $message = 'All fields are required.';
        $messageType = 'error';
    } elseif (!preg_match('/^[a-zA-Z0-9_-]{3,20}$/', $username)) {
        $message = 'Username must be 3-20 characters and contain only letters, numbers, underscores, and hyphens.';
        $messageType = 'error';
    } elseif ($password !== $confirmPassword) {
        $message = 'Passwords do not match.';
        $messageType = 'error';
    } elseif (strlen($password) < 6) {
        $message = 'Password must be at least 6 characters long.';
        $messageType = 'error';
    } elseif (strlen($securityAnswer) < 3) {
        $message = 'Security answer must be at least 3 characters long.';
        $messageType = 'error';
    } else {
        $result = $auth->register($username, $email, $password, $displayName, $leaguePins, $securityQuestion, $securityAnswer);
        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'error';
        
        if ($result['success']) {
            // Auto-login after successful registration
            $loginResult = $auth->login($username, $password);
            if ($loginResult['success']) {
                header('Location: /index.php');
                exit;
            }
        }
    }
}

// Get league availability info
$stmt = $pdo->query("
    SELECT 
        l.league_number,
        l.display_name,
        l.pin_code,
        l.user_limit,
        COUNT(lp.id) as current_participants,
        (l.user_limit - COUNT(lp.id)) as spots_remaining
    FROM leagues l
    LEFT JOIN league_participants lp ON l.id = lp.league_id AND lp.status = 'active'
    WHERE l.status = 'active'
    GROUP BY l.id
    ORDER BY l.league_number
");
$leagues = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="theme-color" content="#121a23">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - NBA Wins Pool</title>
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

        .register-container {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-elevated);
            border: 1px solid var(--border-color);
            padding: 40px;
            width: 100%;
            max-width: 500px;
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
        input[type="email"],
        input[type="password"],
        select {
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

        input:focus,
        select:focus {
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

        .security-section {
            background-color: var(--bg-elevated);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 20px;
            margin: 25px 0;
        }

        .security-section h3 {
            color: var(--text-primary);
            margin: 0 0 6px 0;
            font-size: 16px;
            font-weight: 600;
        }
        .security-section h3 i {
            color: var(--accent-blue);
            margin-right: 6px;
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

        .login-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 0.92rem;
        }

        .login-link a {
            color: var(--accent-blue);
            text-decoration: none;
            font-weight: 500;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        .league-info {
            background-color: var(--accent-blue-dim);
            border: 1px solid rgba(56, 139, 253, 0.2);
            border-radius: var(--radius-md);
            padding: 15px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .league-info h4 {
            margin: 0 0 10px 0;
            color: var(--accent-blue);
        }

        .league-status {
            margin: 8px 0;
            padding: 8px;
            border-radius: 6px;
            background-color: rgba(0, 0, 0, 0.2);
        }

        .league-status.league-full {
            background-color: rgba(248, 81, 73, 0.1);
            border-left: 3px solid var(--accent-red);
        }

        .league-status.league-open {
            background-color: rgba(63, 185, 80, 0.1);
            border-left: 3px solid var(--accent-green);
        }

        .participant-count {
            font-size: 12px;
            color: var(--text-muted);
        }

        .full-text {
            color: var(--accent-red);
            font-weight: bold;
        }

        .spots-text {
            color: var(--accent-green);
            font-weight: bold;
        }

        @media (max-width: 500px) {
            .register-container {
                padding: 25px 20px;
                margin: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="logo">
            <img src="../public/assets/team_logos/Logo.png" alt="NBA Logo">
            <h1>Join NBA Wins Pool</h1>
        </div>
        <!--
        <div class="league-info">
            <h4><i class="fas fa-info-circle"></i> Available Leagues</h4>
            <?php foreach ($leagues as $league): 
                $isFull = $league['spots_remaining'] <= 0;
                $statusClass = $isFull ? 'league-full' : 'league-open';
            ?>
                <div class="league-status <?php echo $statusClass; ?>">
                    <strong><?php echo htmlspecialchars($league['display_name']); ?></strong> 
                    (PIN: <?php echo htmlspecialchars($league['pin_code']); ?>)
                    <br>
                    <span class="participant-count">
                        <?php echo $league['current_participants']; ?>/<?php echo $league['user_limit']; ?> participants
                        <?php if ($isFull): ?>
                            - <span class="full-text">FULL</span>
                        <?php else: ?>
                            - <span class="spots-text"><?php echo $league['spots_remaining']; ?> spots left</span>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
        -->
        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="display_name">Your Name</label>
                <input type="text" id="display_name" name="display_name" 
                       value="<?php echo htmlspecialchars($_POST['display_name'] ?? ''); ?>" 
                       required>
                <div class="help-text">This name will appear in all leagues you join</div>
            </div>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" 
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" 
                       required>
                <div class="help-text">Used for logging in, must be unique</div>
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" 
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                       required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
                <div class="help-text">Minimum 6 characters</div>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>

            <div class="security-section">
                <h3><i class="fas fa-shield-alt"></i> Security Question</h3>
                <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 15px;">
                    Choose a security question for password recovery. Since we don't use email verification, 
                    this is the only way to reset your password if forgotten.
                </p>
                
                <div class="form-group">
                    <label for="security_question">Select a Security Question</label>
                    <select id="security_question" name="security_question" required>
                        <option value="">-- Choose a question --</option>
                        <?php foreach ($securityQuestions as $question): ?>
                            <option value="<?php echo htmlspecialchars($question['question']); ?>" 
                                    <?php echo (($_POST['security_question'] ?? '') == $question['question']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($question['question']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="security_answer">Your Answer</label>
                    <input type="text" id="security_answer" name="security_answer" 
                           value="<?php echo htmlspecialchars($_POST['security_answer'] ?? ''); ?>" 
                           required>
                    <div class="help-text">
                        <i class="fas fa-exclamation-triangle" style="color: var(--accent-orange);"></i> 
                        Remember this answer exactly - you'll need it to reset your password
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="league_pins">League PIN(s)</label>
                <input type="text" id="league_pins" name="league_pins" 
                       value="<?php echo htmlspecialchars($_POST['league_pins'] ?? ''); ?>" 
                       placeholder="PIN001, PIN002" required>
                <div class="help-text">Enter league PIN codes separated by commas to join multiple leagues</div>
            </div>

            <button type="submit" class="submit-btn">
                <i class="fas fa-user-plus"></i> Register & Join League(s)
            </button>
        </form>

        <div class="login-link">
            Already have an account? <a href="login.php">Login here</a>
        </div>
    </div>
</body>
</html>