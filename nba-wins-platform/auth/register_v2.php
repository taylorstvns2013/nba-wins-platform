<?php
// nba-wins-platform/auth/register_v2.php
// V2 Registration - Account creation decoupled from league joining
// Users create an account first, then join/create leagues via league_hub.php
require_once '../config/db_connection.php';
require_once '../core/UserAuthentication.php';

$auth = new UserAuthentication($pdo);
$message = '';
$messageType = '';

// Redirect if already logged in
if ($auth->isAuthenticated()) {
    header('Location: league_hub.php');
    exit;
}

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
    $securityQuestion = trim($_POST['security_question'] ?? '');
    $securityAnswer = trim($_POST['security_answer'] ?? '');

    // Basic validation
    if (empty($username) || empty($email) || empty($password) || empty($displayName) ||
        empty($securityQuestion) || empty($securityAnswer)) {
        $message = 'All fields are required.';
        $messageType = 'error';
    } elseif (!preg_match('/^[a-zA-Z0-9_-]{3,20}$/', $username)) {
        $message = 'Username must be 3-20 characters and contain only letters, numbers, underscores, and hyphens.';
        $messageType = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
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
        // Register without league PIN - create account only
        try {
            $pdo->beginTransaction();

            // Check if username or email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->rowCount() > 0) {
                throw new Exception("Username or email already exists.");
            }

            // Validate security question
            $stmt = $pdo->prepare("SELECT id FROM security_questions WHERE question = ? AND active = TRUE");
            $stmt->execute([$securityQuestion]);
            if ($stmt->rowCount() === 0) {
                throw new Exception("Invalid security question selected.");
            }

            // Hash password and security answer
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $securityAnswerHash = password_hash(strtolower(trim($securityAnswer)), PASSWORD_DEFAULT);

            // Create user (no league association yet)
            $stmt = $pdo->prepare("
                INSERT INTO users (username, email, password_hash, display_name, security_question, security_answer_hash, status)
                VALUES (?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$username, $email, $passwordHash, $displayName, $securityQuestion, $securityAnswerHash]);

            $pdo->commit();

            // Auto-login and redirect to league hub
            $loginResult = $auth->login($username, $password);
            if ($loginResult['success']) {
                header('Location: league_hub.php');
                exit;
            } else {
                $message = 'Account created! Please login to continue.';
                $messageType = 'success';
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $message = $e->getMessage();
            $messageType = 'error';
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
    <title>Create Account - NBA Wins Pool</title>
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
            margin: 0 0 8px 0;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .subtitle {
            text-align: center;
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 30px;
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

        .info-callout {
            background-color: var(--accent-blue-dim);
            border: 1px solid rgba(56, 139, 253, 0.2);
            border-radius: var(--radius-md);
            padding: 15px;
            margin-bottom: 25px;
            font-size: 14px;
            color: var(--text-secondary);
        }

        .info-callout i {
            color: var(--accent-blue);
            margin-right: 6px;
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
            <h1>Create Account</h1>
            <p class="subtitle">Create your account, then join or start a league</p>
        </div>

        <div class="info-callout">
            <i class="fas fa-info-circle"></i>
            After creating your account, you'll be able to join a league with a PIN code or create your own league as commissioner.
        </div>

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
                       placeholder="How your name appears in leagues"
                       required>
            </div>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username"
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                       placeholder="Used for logging in"
                       required>
                <div class="help-text">3-20 characters: letters, numbers, underscores, hyphens</div>
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
                    This is the only way to reset your password if forgotten.
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

            <button type="submit" class="submit-btn">
                <i class="fas fa-user-plus"></i> Create Account
            </button>
        </form>

        <div class="login-link">
            Already have an account? <a href="login.php">Login here</a>
        </div>
    </div>
</body>
</html>