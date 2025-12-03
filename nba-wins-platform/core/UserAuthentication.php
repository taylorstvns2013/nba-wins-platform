<?php
// nba-wins-platform/core/UserAuthentication.php

class UserAuthentication {
    private $pdo;
    private $sessionLifetime = 2592000; // 30 days
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        
        // Configure secure session settings
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_lifetime', $this->sessionLifetime);
            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_only_cookies', 1);
            ini_set('session.cookie_samesite', 'Lax');
            
            session_start();
        }
    }
    
    /**
     * Register a new user and join leagues by PIN
     */
    public function register($username, $email, $password, $displayName, $leaguePins, $securityQuestion = '', $securityAnswer = '') {
        try {
            $this->pdo->beginTransaction();
            
            // Check if username or email already exists
            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            
            if ($stmt->rowCount() > 0) {
                throw new Exception("Username or email already exists");
            }
            
            // Validate security question and answer
            if (empty($securityQuestion) || empty($securityAnswer)) {
                throw new Exception("Security question and answer are required");
            }
            
            if (strlen($securityAnswer) < 3) {
                throw new Exception("Security answer must be at least 3 characters long");
            }
            
            // Validate that security question exists in the security_questions table
            $stmt = $this->pdo->prepare("SELECT id FROM security_questions WHERE question = ? AND active = TRUE");
            $stmt->execute([$securityQuestion]);
            if ($stmt->rowCount() === 0) {
                throw new Exception("Invalid security question selected");
            }
            
            // Validate league PINs
            if (empty($leaguePins)) {
                throw new Exception("At least one league PIN is required");
            }
            
            $pinsArray = array_map('trim', explode(',', $leaguePins));
            $validLeagues = [];
            
            foreach ($pinsArray as $pin) {
                $stmt = $this->pdo->prepare("
                    SELECT l.id, l.display_name, l.user_limit,
                           COUNT(lp.id) as current_participants
                    FROM leagues l
                    LEFT JOIN league_participants lp ON l.id = lp.league_id AND lp.status = 'active'
                    WHERE l.pin_code = ? AND l.status = 'active'
                    GROUP BY l.id
                ");
                $stmt->execute([$pin]);
                $league = $stmt->fetch();
                
                if (!$league) {
                    throw new Exception("Invalid league PIN: " . $pin);
                }
                
                // Check if league is full
                if ($league['current_participants'] >= $league['user_limit']) {
                    throw new Exception("League '{$league['display_name']}' is full ({$league['current_participants']}/{$league['user_limit']} participants)");
                }
                
                $validLeagues[] = $league;
            }
            
            // Hash password and security answer
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            // Hash security answer (case-insensitive for consistency)
            $securityAnswerHash = password_hash(strtolower(trim($securityAnswer)), PASSWORD_DEFAULT);
            
            // Create user with security question and answer
            $stmt = $this->pdo->prepare("
                INSERT INTO users (username, email, password_hash, display_name, security_question, security_answer_hash, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$username, $email, $passwordHash, $displayName, $securityQuestion, $securityAnswerHash]);
            $userId = $this->pdo->lastInsertId();
            
            // Add user to leagues
            foreach ($validLeagues as $league) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO league_participants (user_id, league_id, participant_name) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$userId, $league['id'], $displayName]);
            }
            
            $this->pdo->commit();
            return ['success' => true, 'message' => 'Registration successful!'];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Authenticate user login
     */
    public function login($username, $password) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, username, email, password_hash, display_name 
                FROM users 
                WHERE (username = ? OR email = ?) AND status = 'active'
            ");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // Create session
                $sessionId = $this->generateSecureToken();
                $expiresAt = date('Y-m-d H:i:s', time() + $this->sessionLifetime);
                
                // Get user's first league as default
                $stmt = $this->pdo->prepare("
                    SELECT l.id 
                    FROM leagues l
                    JOIN league_participants lp ON l.id = lp.league_id
                    WHERE lp.user_id = ? AND lp.status = 'active'
                    ORDER BY l.league_number ASC
                    LIMIT 1
                ");
                $stmt->execute([$user['id']]);
                $defaultLeague = $stmt->fetchColumn();
                
                // Store session in database
                $stmt = $this->pdo->prepare("
                    INSERT INTO user_sessions (id, user_id, current_league_id, ip_address, user_agent, expires_at)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    current_league_id = VALUES(current_league_id),
                    expires_at = VALUES(expires_at)
                ");
                $stmt->execute([
                    $sessionId, 
                    $user['id'], 
                    $defaultLeague,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $expiresAt
                ]);
                
                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['display_name'] = $user['display_name'];
                $_SESSION['current_league_id'] = $defaultLeague;
                $_SESSION['session_id'] = $sessionId;
                
                return ['success' => true, 'user' => $user];
            }
            
            return ['success' => false, 'message' => 'Invalid credentials'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Login error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Check if user is authenticated
     */
    public function isAuthenticated() {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_id'])) {
            return false;
        }
        
        // Verify session in database
        $stmt = $this->pdo->prepare("
            SELECT user_id, current_league_id 
            FROM user_sessions 
            WHERE id = ? AND user_id = ? AND expires_at > NOW()
        ");
        $stmt->execute([$_SESSION['session_id'], $_SESSION['user_id']]);
        $session = $stmt->fetch();
        
        if ($session) {
            // Update current league in session if it changed
            $_SESSION['current_league_id'] = $session['current_league_id'];
            return true;
        }
        
        // Invalid session - clear it
        $this->logout();
        return false;
    }
    
    /**
     * Switch user's current league
     */
    public function switchLeague($leagueId) {
        if (!$this->isAuthenticated()) {
            return false;
        }
        
        // Verify user has access to this league
        $stmt = $this->pdo->prepare("
            SELECT lp.id 
            FROM league_participants lp
            JOIN leagues l ON lp.league_id = l.id
            WHERE lp.user_id = ? AND l.id = ? AND lp.status = 'active' AND l.status = 'active'
        ");
        $stmt->execute([$_SESSION['user_id'], $leagueId]);
        
        if ($stmt->rowCount() > 0) {
            // Update session
            $_SESSION['current_league_id'] = $leagueId;
            
            // Update database
            if (isset($_SESSION['session_id'])) {
                $stmt = $this->pdo->prepare("
                    UPDATE user_sessions 
                    SET current_league_id = ? 
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$leagueId, $_SESSION['session_id'], $_SESSION['user_id']]);
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Get user's available leagues
     */
    public function getUserLeagues() {
        if (!$this->isAuthenticated()) {
            return [];
        }
        
        $stmt = $this->pdo->prepare("
            SELECT l.id, l.league_number, l.display_name, lp.participant_name
            FROM leagues l
            JOIN league_participants lp ON l.id = lp.league_id
            WHERE lp.user_id = ? AND lp.status = 'active' AND l.status = 'active'
            ORDER BY l.league_number ASC
        ");
        $stmt->execute([$_SESSION['user_id']]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get current league info
     */
    public function getCurrentLeague() {
        if (!$this->isAuthenticated() || !isset($_SESSION['current_league_id'])) {
            return null;
        }
        
        $stmt = $this->pdo->prepare("
            SELECT l.*, lp.participant_name
            FROM leagues l
            JOIN league_participants lp ON l.id = lp.league_id
            WHERE l.id = ? AND lp.user_id = ?
        ");
        $stmt->execute([$_SESSION['current_league_id'], $_SESSION['user_id']]);
        
        return $stmt->fetch();
    }
    
    /**
     * Logout user
     */
    public function logout() {
        if (isset($_SESSION['session_id'])) {
            // Remove session from database
            $stmt = $this->pdo->prepare("DELETE FROM user_sessions WHERE id = ?");
            $stmt->execute([$_SESSION['session_id']]);
        }
        
        // Clear session
        $_SESSION = array();
        
        // Delete session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
    }
    
    /**
     * Clean up expired sessions
     */
    public function cleanupExpiredSessions() {
        $stmt = $this->pdo->prepare("DELETE FROM user_sessions WHERE expires_at < NOW()");
        $stmt->execute();
    }
    
    private function generateSecureToken() {
        return bin2hex(random_bytes(32));
    }
}
?>