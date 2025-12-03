<?php
// core/services/UserAuthentication.php

require_once '../config/database.php';

class UserAuthentication {
    private $pdo;
    private $maxLoginAttempts = 5;
    private $lockoutTime = 900; // 15 minutes
    
    public function __construct() {
        $database = new Database();
        $this->pdo = $database->getConnection();
    }
    
    /**
     * Register a new user
     */
    public function register($username, $email, $password, $displayName) {
        $errors = [];
        
        // Validate input
        $errors = $this->validateRegistrationData($username, $email, $password, $displayName);
        
        if (empty($errors)) {
            // Check if user already exists
            if ($this->userExists($username, $email)) {
                $errors[] = "Username or email already exists";
            } else {
                // Create user
                $hashedPassword = $this->hashPassword($password);
                $userId = $this->createUser($username, $email, $hashedPassword, $displayName);
                
                if ($userId) {
                    return [
                        'success' => true, 
                        'message' => 'Registration successful!',
                        'user_id' => $userId
                    ];
                } else {
                    $errors[] = "Registration failed. Please try again.";
                }
            }
        }
        
        return ['success' => false, 'errors' => $errors];
    }
    
    /**
     * Authenticate user login
     */
    public function login($username, $password) {
        $ipAddress = $_SERVER['REMOTE_ADDR'];
        
        // Check brute force protection
        if ($this->isBlocked($ipAddress)) {
            throw new Exception("Too many failed login attempts. Please try again later.");
        }
        
        // Get user by username or email
        $user = $this->getUserByUsernameOrEmail($username);
        
        if ($user && $this->verifyPassword($password, $user['password_hash'])) {
            // Check if account is active
            if ($user['is_admin'] == 0 && !$this->isUserActive($user['id'])) {
                throw new Exception("Account is inactive. Please contact administrator.");
            }
            
            // Successful login
            $this->recordSuccessfulLogin($user['id'], $ipAddress);
            $this->createUserSession($user);
            
            return [
                'success' => true,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'display_name' => $user['display_name'],
                    'is_admin' => $user['is_admin']
                ]
            ];
        } else {
            // Failed login
            $this->recordFailedLogin($ipAddress, $username);
            throw new Exception("Invalid username or password");
        }
    }
    
    /**
     * Get user's available leagues
     */
    public function getUserLeagues($userId) {
        $stmt = $this->pdo->prepare("
            SELECT l.id, l.name, l.slug, l.status, lp.joined_at, lp.is_active
            FROM leagues l
            INNER JOIN league_participants lp ON l.id = lp.league_id
            WHERE lp.user_id = ? AND lp.is_active = 1
            ORDER BY l.name
        ");
        $stmt->execute([$userId]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Switch user's current league context
     */
    public function switchLeague($userId, $leagueId) {
        // Verify user has access to this league
        $stmt = $this->pdo->prepare("
            SELECT lp.*, l.name as league_name
            FROM league_participants lp
            INNER JOIN leagues l ON lp.league_id = l.id
            WHERE lp.user_id = ? AND lp.league_id = ? AND lp.is_active = 1
        ");
        $stmt->execute([$userId, $leagueId]);
        $participation = $stmt->fetch();
        
        if (!$participation) {
            throw new Exception("Access denied to this league");
        }
        
        // Update session with new league context
        $_SESSION['current_league'] = [
            'id' => $leagueId,
            'name' => $participation['league_name'],
            'joined_at' => $participation['joined_at']
        ];
        
        // Regenerate session for security
        SessionManager::regenerateSession();
        
        return true;
    }
    
    /**
     * Logout user
     */
    public function logout() {
        // Clear any remember me tokens
        if (isset($_SESSION['user_id'])) {
            $this->clearRememberTokens($_SESSION['user_id']);
        }
        
        // Destroy session
        SessionManager::destroySession();
        
        return true;
    }
    
    /**
     * Validate registration data
     */
    private function validateRegistrationData($username, $email, $password, $displayName) {
        $errors = [];
        
        // Username validation
        if (empty($username) || strlen($username) < 3) {
            $errors[] = "Username must be at least 3 characters long";
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $errors[] = "Username can only contain letters, numbers, and underscores";
        }
        
        // Email validation
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please enter a valid email address";
        }
        
        // Password validation
        if (strlen($password) < 8) {
            $errors[] = "Password must be at least 8 characters long";
        }
        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character";
        }
        
        // Display name validation
        if (empty($displayName) || strlen($displayName) < 2) {
            $errors[] = "Display name must be at least 2 characters long";
        }
        
        return $errors;
    }
    
    /**
     * Hash password using Argon2ID
     */
    private function hashPassword($password) {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536, // 64 MB
            'time_cost' => 4,       // 4 iterations
            'threads' => 1          // 1 thread
        ]);
    }
    
    /**
     * Verify password
     */
    private function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    /**
     * Check if user exists
     */
    private function userExists($username, $email) {
        $stmt = $this->pdo->prepare("
            SELECT id FROM users 
            WHERE username = ? OR email = ?
        ");
        $stmt->execute([$username, $email]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Create new user
     */
    private function createUser($username, $email, $hashedPassword, $displayName) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO users (username, email, password_hash, display_name, is_admin, created_at)
                VALUES (?, ?, ?, ?, 0, NOW())
            ");
            
            $stmt->execute([$username, $email, $hashedPassword, $displayName]);
            
            return $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log("User creation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get user by username or email
     */
    private function getUserByUsernameOrEmail($username) {
        $stmt = $this->pdo->prepare("
            SELECT id, username, email, display_name, password_hash, is_admin, last_login
            FROM users 
            WHERE username = ? OR email = ?
        ");
        $stmt->execute([$username, $username]);
        
        return $stmt->fetch();
    }
    
    /**
     * Check if user is active (has league participation)
     */
    private function isUserActive($userId) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as league_count
            FROM league_participants 
            WHERE user_id = ? AND is_active = 1
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        return $result['league_count'] > 0;
    }
    
    /**
     * Create user session
     */
    private function createUserSession($user) {
        SessionManager::regenerateSession();
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['display_name'] = $user['display_name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['is_admin'] = $user['is_admin'];
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        
        // Set default league if user has leagues
        $leagues = $this->getUserLeagues($user['id']);
        if (!empty($leagues)) {
            $_SESSION['current_league'] = [
                'id' => $leagues[0]['id'],
                'name' => $leagues[0]['name']
            ];
        }
    }
    
    /**
     * Record successful login
     */
    private function recordSuccessfulLogin($userId, $ipAddress) {
        // Update user's last login
        $stmt = $this->pdo->prepare("
            UPDATE users SET last_login = NOW() WHERE id = ?
        ");
        $stmt->execute([$userId]);
        
        // Clear failed login attempts
        $this->clearFailedAttempts($ipAddress);
    }
    
    /**
     * Record failed login attempt
     */
    private function recordFailedLogin($ipAddress, $username) {
        $stmt = $this->pdo->prepare("
            INSERT INTO login_attempts (ip_address, username, attempted_at, success)
            VALUES (?, ?, NOW(), 0)
        ");
        $stmt->execute([$ipAddress, $username]);
    }
    
    /**
     * Check if IP is blocked due to too many failed attempts
     */
    private function isBlocked($ipAddress) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as attempt_count
            FROM login_attempts 
            WHERE ip_address = ? 
            AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
            AND success = 0
        ");
        $stmt->execute([$ipAddress, $this->lockoutTime]);
        $result = $stmt->fetch();
        
        return $result['attempt_count'] >= $this->maxLoginAttempts;
    }
    
    /**
     * Clear failed login attempts
     */
    private function clearFailedAttempts($ipAddress) {
        $stmt = $this->pdo->prepare("
            DELETE FROM login_attempts 
            WHERE ip_address = ? OR attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
        ");
        $stmt->execute([$ipAddress]);
    }
    
    /**
     * Clear remember me tokens
     */
    private function clearRememberTokens($userId) {
        // Implementation for remember me functionality
        // This would clear any persistent login tokens
    }
}

// Custom exception classes
class AuthenticationException extends Exception {}
class ValidationException extends Exception {}
class SecurityException extends Exception {}
?>