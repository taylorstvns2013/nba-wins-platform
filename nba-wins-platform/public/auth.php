<?php
// public/auth.php - Login and Registration Page
require_once '../config/database.php';
require_once '../core/services/UserAuthentication.php';

// Start secure session
try {
    SessionManager::startSecureSession();
} catch (Exception $e) {
    // Session error - start fresh
    session_start();
}

// Redirect if already logged in
if (SessionManager::isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$message = '';
$errors = [];
$action = $_GET['action'] ?? 'login';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!CSRFProtection::validateToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Security token validation failed. Please try again.";
    } else {
        $auth = new UserAuthentication();
        
        if ($_POST['form_type'] === 'login') {
            // Handle login
            try {
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                
                if (empty($username) || empty($password)) {
                    $errors[] = "Please enter both username and password";
                } else {
                    $result = $auth->login($username, $password);
                    if ($result['success']) {
                        $message = "Login successful! Redirecting...";
                        echo "<script>
                            setTimeout(function() {
                                window.location.href = 'dashboard.php';
                            }, 1500);
                        </script>";
                    }
                }
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
            
        } elseif ($_POST['form_type'] === 'register') {
            // Handle registration
            try {
                $username = trim($_POST['username'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $password = $_POST['password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';
                $displayName = trim($_POST['display_name'] ?? '');
                
                if ($password !== $confirmPassword) {
                    $errors[] = "Passwords do not match";
                } else {
                    $result = $auth->register($username, $email, $password, $displayName);
                    if ($result['success']) {
                        $message = $result['message'] . " You can now log in.";
                        $action = 'login'; // Switch to login form
                    } else {
                        $errors = $result['errors'];
                    }
                }
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
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
    <title><?= $action === 'login' ? 'Login' : 'Register' ?> - NBA Wins Platform</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .auth-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            padding: 40px;
            width: 100%;
            max-width: 450px;
            margin: 20px;
        }
        
        .auth-header {
            text-align: center;
            margin-bottom: 32px;
        }
        
        .auth-header h1 {
            color: #1e3c72;
            font-size: 28px;
            margin-bottom: 8px;
        }
        
        .auth-header p {
            color: #666;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #333;
            font-weight: 500;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #2a5298;
        }
        
        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .switch-form {
            text-align: center;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e1e5e9;
        }
        
        .switch-form a {
            color: #2a5298;
            text-decoration: none;
            font-weight: 500;
        }
        
        .switch-form a:hover {
            text-decoration: underline;
        }
        
        .message {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
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
        
        .password-requirements {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
            line-height: 1.4;
        }
        
        @media (max-width: 480px) {
            .auth-container {
                padding: 24px;
                margin: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-header">
            <h1><?= $action === 'login' ? 'Welcome Back' : 'Join NBA Wins Platform' ?></h1>
            <p><?= $action === 'login' ? 'Sign in to your account' : 'Create your account to get started' ?></p>
        </div>
        
        <?php if ($message): ?>
            <div class="message success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="message error">
                <?php foreach ($errors as $error): ?>
                    <div><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($action === 'login'): ?>
            <!-- Login Form -->
            <form method="POST" action="">
                <?= CSRFProtection::getTokenInput() ?>
                <input type="hidden" name="form_type" value="login">
                
                <div class="form-group">
                    <label for="username">Username or Email</label>
                    <input type="text" 
                           id="username" 
                           name="username" 
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           required 
                           autocomplete="username">
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" 
                           id="password" 
                           name="password" 
                           required 
                           autocomplete="current-password">
                </div>
                
                <button type="submit" class="btn">Sign In</button>
            </form>
            
            <div class="switch-form">
                Don't have an account? <a href="?action=register">Sign up</a>
            </div>
            
        <?php else: ?>
            <!-- Registration Form -->
            <form method="POST" action="">
                <?= CSRFProtection::getTokenInput() ?>
                <input type="hidden" name="form_type" value="register">
                
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" 
                           id="username" 
                           name="username" 
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           required 
                           autocomplete="username"
                           pattern="[a-zA-Z0-9_]{3,}"
                           title="Username must be at least 3 characters and contain only letters, numbers, and underscores">
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" 
                           id="email" 
                           name="email" 
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           required 
                           autocomplete="email">
                </div>
                
                <div class="form-group">
                    <label for="display_name">Display Name</label>
                    <input type="text" 
                           id="display_name" 
                           name="display_name" 
                           value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>"
                           required 
                           autocomplete="name">
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" 
                           id="password" 
                           name="password" 
                           required 
                           autocomplete="new-password"
                           minlength="8">
                    <div class="password-requirements">
                        Password must be at least 8 characters and include uppercase, lowercase, number, and special character
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" 
                           id="confirm_password" 
                           name="confirm_password" 
                           required 
                           autocomplete="new-password">
                </div>
                
                <button type="submit" class="btn">Create Account</button>
            </form>
            
            <div class="switch-form">
                Already have an account? <a href="?action=login">Sign in</a>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Client-side password confirmation
        document.addEventListener('DOMContentLoaded', function() {
            const password = document.getElementById('password');
            const confirmPassword = document.getElementById('confirm_password');
            
            if (confirmPassword) {
                confirmPassword.addEventListener('input', function() {
                    if (password.value !== confirmPassword.value) {
                        confirmPassword.setCustomValidity('Passwords do not match');
                    } else {
                        confirmPassword.setCustomValidity('');
                    }
                });
            }
        });
    </script>
</body>
</html>