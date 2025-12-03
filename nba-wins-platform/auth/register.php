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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - NBA Wins Pool</title>
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

        .register-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
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
        input[type="email"],
        input[type="password"],
        select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            box-sizing: border-box;
            transition: border-color 0.3s ease;
            background-color: white;
        }

        select {
            cursor: pointer;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: #2196F3;
        }

        .help-text {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }

        .security-section {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
        }

        .security-section h3 {
            color: #495057;
            margin: 0 0 15px 0;
            font-size: 18px;
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

        .login-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .login-link a {
            color: #2196F3;
            text-decoration: none;
            font-weight: 500;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        .league-info {
            background-color: #e3f2fd;
            border: 1px solid #bbdefb;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .league-info h4 {
            margin: 0 0 10px 0;
            color: #1565c0;
        }

        .league-status {
            margin: 8px 0;
            padding: 8px;
            border-radius: 4px;
            background-color: rgba(255, 255, 255, 0.7);
        }

        .league-status.league-full {
            background-color: #ffebee;
            border-left: 3px solid #f44336;
        }

        .league-status.league-open {
            background-color: #e8f5e8;
            border-left: 3px solid #4caf50;
        }

        .participant-count {
            font-size: 12px;
            color: #666;
        }

        .full-text {
            color: #f44336;
            font-weight: bold;
        }

        .spots-text {
            color: #4caf50;
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
                <p style="font-size: 14px; color: #666; margin-bottom: 15px;">
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
                        <i class="fas fa-exclamation-triangle"></i> 
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