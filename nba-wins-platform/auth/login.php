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
    <title>Login - NBA Wins Pool</title>
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

        .login-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
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
        input[type="password"] {
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
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .register-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .register-link a {
            color: #2196F3;
            text-decoration: none;
            font-weight: 500;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        .forgot-password-link {
            text-align: right;
            margin-top: 5px;
        }

        .forgot-password-link a {
            color: #2196F3;
            font-size: 14px;
            text-decoration: none;
        }

        .forgot-password-link a:hover {
            text-decoration: underline;
        }

        .test-accounts {
            background-color: #e3f2fd;
            border: 1px solid #bbdefb;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .test-accounts h4 {
            margin: 0 0 10px 0;
            color: #1565c0;
        }

        .test-accounts p {
            margin: 5px 0;
            color: #333;
        }

        @media (max-width: 500px) {
            .login-container {
                padding: 25px 20px;
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

        <div class="register-link">
            Don't have an account? <a href="register.php">Register here</a>
        </div>
    </div>
</body>
</html>