<?php
// nba-wins-platform/auth/login.php
require_once '../config/db_connection.php';
require_once '../core/UserAuthentication.php';

$auth = new UserAuthentication($pdo);
$message = '';

// Redirect if already logged in
if ($auth->isAuthenticated()) {
    header('Location: ../index.php');
    exit;
}

// Handle guest login
if (isset($_GET['guest']) && $_GET['guest'] == '1') {
    $result = $auth->loginAsGuest();
    if ($result['success']) {
        header('Location: /index.php');
        exit;
    } else {
        $message = $result['message'];
    }
}

if ($_POST) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $message = 'Username and password are required.';
    } else {
        $result = $auth->login($username, $password);
        if ($result['success']) {
            header('Location: /index.php');
            exit;
        } else {
            $message = $result['message'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#121a23">
    <title>Login - NBA Wins Pool</title>
    <link rel="apple-touch-icon" type="image/png" href="../public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="../public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #121a23;
            background-image: radial-gradient(ellipse at 50% 0%, rgba(56, 139, 253, 0.06) 0%, transparent 60%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            -webkit-font-smoothing: antialiased;
        }

        .login-container {
            background: rgba(22, 30, 40, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 40px;
            width: 100%;
            max-width: 400px;
        }

        .logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo img {
            width: 80px;
            height: 80px;
            margin-bottom: 10px;
            border-radius: 16px;
        }

        h1 {
            text-align: center;
            color: #e6edf3;
            margin: 0 0 30px 0;
            font-size: 26px;
            font-weight: 700;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #8b949e;
            font-weight: 500;
            font-size: 14px;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            font-size: 15px;
            font-family: 'Outfit', sans-serif;
            box-sizing: border-box;
            transition: border-color 0.25s ease, box-shadow 0.25s ease;
            background: rgba(255, 255, 255, 0.04);
            color: #e6edf3;
        }

        input::placeholder { color: #484f58; }

        input:focus {
            outline: none;
            border-color: #388bfd;
            box-shadow: 0 0 0 3px rgba(56, 139, 253, 0.15);
        }

        .submit-btn {
            width: 100%;
            background: linear-gradient(135deg, #388bfd, #2563eb);
            color: white;
            padding: 13px 20px;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            margin-top: 10px;
        }

        .submit-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(56, 139, 253, 0.3);
        }

        .guest-btn {
            width: 100%;
            background: rgba(255, 255, 255, 0.06);
            color: #8b949e;
            padding: 13px 20px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 10px;
            text-decoration: none;
            display: block;
            text-align: center;
            box-sizing: border-box;
        }

        .guest-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #c9d1d9;
            border-color: rgba(255, 255, 255, 0.14);
        }

        .divider {
            display: flex;
            align-items: center;
            margin: 20px 0 10px 0;
            color: #484f58;
            font-size: 13px;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }

        .divider::before { margin-right: 12px; }
        .divider::after { margin-left: 12px; }

        .message {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            background-color: rgba(248, 81, 73, 0.12);
            color: #f85149;
            border: 1px solid rgba(248, 81, 73, 0.2);
            font-size: 14px;
        }

        .register-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            color: #8b949e;
            font-size: 14px;
        }

        .register-link a {
            color: #388bfd;
            text-decoration: none;
            font-weight: 500;
        }

        .register-link a:hover { text-decoration: underline; }

        .forgot-password-link {
            text-align: right;
            margin-top: 5px;
        }

        .forgot-password-link a {
            color: #388bfd;
            font-size: 13px;
            text-decoration: none;
        }

        .forgot-password-link a:hover { text-decoration: underline; }

        .test-accounts {
            background-color: rgba(56, 139, 253, 0.08);
            border: 1px solid rgba(56, 139, 253, 0.15);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 13px;
        }

        .test-accounts h4 {
            margin: 0 0 10px 0;
            color: #388bfd;
        }

        .test-accounts p {
            margin: 5px 0;
            color: #c9d1d9;
        }

        @media (max-width: 500px) {
            .login-container {
                padding: 28px 22px;
                margin: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <img src="../public/assets/team_logos/Logo.png" alt="NBA Logo">
            <h1>NBA Wins Pool</h1>
        </div>

        <!--
        <div class="test-accounts">
            <h4><i class="fas fa-info-circle"></i> Test Accounts</h4>
            <p><strong>Username:</strong> Any name from current participants (no spaces, lowercase)</p>
            <p><strong>Password:</strong> password</p>
            <p><strong>Examples:</strong> johndoe, janedoe, mikejohnson</p>
        </div>
        -->

        <?php if ($message): ?>
            <div class="message">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Username or Email</label>
                <input type="text" id="username" name="username" 
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" 
                       required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
                <div class="forgot-password-link">
                    <a href="forgot_password.php">
                        <i class="fas fa-key"></i> Forgot password?
                    </a>
                </div>
            </div>

            <button type="submit" class="submit-btn">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>
        </form>

        <div class="divider">or</div>

        <a href="login.php?guest=1" class="guest-btn">
            <i class="fas fa-eye"></i> Browse as Guest
        </a>

        <div class="register-link">
            Don't have an account? <a href="register.php">Register here</a>
        </div>
    </div>
</body>
</html>