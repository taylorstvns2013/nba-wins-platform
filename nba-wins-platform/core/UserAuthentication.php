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
            ini_set('session.gc_maxlifetime', $this->sessionLifetime); // Keep session data as long as cookie
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
            $ip = self::getRealIpAddress();
            
            // Check rate limiting before anything else
            if ($this->isRateLimited($ip)) {
                return ['success' => false, 'message' => 'Too many failed login attempts. Please try again in 15 minutes.'];
            }
            
            $stmt = $this->pdo->prepare("
                SELECT id, username, email, password_hash, display_name 
                FROM users 
                WHERE (username = ? OR email = ?) AND status = 'active'
            ");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // Record successful attempt and clear failed attempts for this IP
                $this->recordLoginAttempt($ip, $username, true);
                
                // Create session
                $sessionId = $this->generateSecureToken();
                $expiresAt = date('Y-m-d H:i:s', time() + $this->sessionLifetime);
                
                // Get user's default league, or fall back to first league
                $defaultLeague = null;

                // Check for user-configured default league
                $stmt = $this->pdo->prepare("
                    SELECT u.default_league_id
                    FROM users u
                    JOIN league_participants lp ON lp.league_id = u.default_league_id
                        AND lp.user_id = u.id AND lp.status = 'active'
                    WHERE u.id = ? AND u.default_league_id IS NOT NULL
                ");
                $stmt->execute([$user['id']]);
                $defaultLeague = $stmt->fetchColumn() ?: null;

                // Fall back to first league by league_number
                if (!$defaultLeague) {
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
                }
                
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
                    $ip,
                    isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null,
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
            
            // Record failed attempt
            $this->recordLoginAttempt($ip, $username, false);
            
            return ['success' => false, 'message' => 'Invalid credentials'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Login error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Login as guest - bypasses password, allows viewing all leagues
     * Multiple visitors can be guest simultaneously (each gets own session)
     */
    public function loginAsGuest() {
        try {
            // Find the guest user account
            $stmt = $this->pdo->prepare("
                SELECT id, username, display_name 
                FROM users 
                WHERE username = 'guest' AND status = 'active'
            ");
            $stmt->execute();
            $guest = $stmt->fetch();
            
            if (!$guest) {
                return ['success' => false, 'message' => 'Guest account not configured. Please contact administrator.'];
            }
            
            // Create session
            $sessionId = $this->generateSecureToken();
            // Guest sessions expire after 24 hours (shorter than regular 30-day sessions)
            $guestSessionLifetime = 86400; // 24 hours
            $expiresAt = date('Y-m-d H:i:s', time() + $guestSessionLifetime);
            
            // Get the first active league as default for guest
            $stmt = $this->pdo->prepare("
                SELECT id FROM leagues WHERE status = 'active' ORDER BY league_number ASC LIMIT 1
            ");
            $stmt->execute();
            $defaultLeague = $stmt->fetchColumn();
            
            // Store session in database
            $stmt = $this->pdo->prepare("
                INSERT INTO user_sessions (id, user_id, current_league_id, ip_address, user_agent, expires_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $sessionId,
                $guest['id'],
                $defaultLeague,
                self::getRealIpAddress(),
                isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null,
                $expiresAt
            ]);
            
            // Set session variables
            session_regenerate_id(true);
            $_SESSION['user_id'] = $guest['id'];
            $_SESSION['username'] = $guest['username'];
            $_SESSION['display_name'] = $guest['display_name'];
            $_SESSION['current_league_id'] = $defaultLeague;
            $_SESSION['session_id'] = $sessionId;
            $_SESSION['is_guest'] = true;
            
            return ['success' => true, 'user' => $guest];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Guest login error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Check if current user is a guest
     */
    public function isGuest() {
        return isset($_SESSION['is_guest']) && $_SESSION['is_guest'] === true;
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
     * Guests can switch to any active league; regular users must be participants
     */
    public function switchLeague($leagueId) {
        if (!$this->isAuthenticated()) {
            return false;
        }
        
        // Guest users can view any active league
        if ($this->isGuest()) {
            $stmt = $this->pdo->prepare("
                SELECT id FROM leagues WHERE id = ? AND status = 'active'
            ");
            $stmt->execute([$leagueId]);
            
            if ($stmt->rowCount() > 0) {
                $_SESSION['current_league_id'] = $leagueId;
                
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
        
        // Regular users - verify league membership
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
     * Guests see all active leagues; regular users see only their leagues
     */
    public function getUserLeagues() {
        if (!$this->isAuthenticated()) {
            return [];
        }
        
        // Guest users see all active leagues
        if ($this->isGuest()) {
            $stmt = $this->pdo->prepare("
                SELECT id, league_number, display_name, 'Guest' as participant_name
                FROM leagues
                WHERE status = 'active'
                ORDER BY league_number ASC
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        }
        
        // Regular users see their leagues
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
     * Guests get league info without participant join
     */
    public function getCurrentLeague() {
        if (!$this->isAuthenticated() || !isset($_SESSION['current_league_id'])) {
            return null;
        }
        
        // Guest users - get league info without participant membership check
        if ($this->isGuest()) {
            $stmt = $this->pdo->prepare("
                SELECT l.*, 'Guest' as participant_name
                FROM leagues l
                WHERE l.id = ?
            ");
            $stmt->execute([$_SESSION['current_league_id']]);
            return $stmt->fetch();
        }
        
        // Regular users
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
        $this->pdo->prepare("DELETE FROM user_sessions WHERE expires_at < NOW()")->execute();
        // Clean login attempts older than 24 hours
        $this->pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)")->execute();
    }
    
    private function generateSecureToken() {
        return bin2hex(random_bytes(32));
    }
    
    // =====================================================================
    // RATE LIMITING
    // =====================================================================
    
    /**
     * Check if an IP address is rate limited (5 failed attempts in 15 minutes)
     */
    private function isRateLimited($ip) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as attempts 
            FROM login_attempts 
            WHERE ip_address = ? 
            AND success = 0 
            AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute([$ip]);
        $result = $stmt->fetch();
        
        return $result['attempts'] >= 10;
    }
    
    /**
     * Record a login attempt (success or failure)
     */
    private function recordLoginAttempt($ip, $username, $success) {
        $stmt = $this->pdo->prepare("
            INSERT INTO login_attempts (ip_address, username, success) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$ip, $username, $success ? 1 : 0]);
        
        // On successful login, clear previous failed attempts for this IP
        if ($success) {
            $stmt = $this->pdo->prepare("
                DELETE FROM login_attempts 
                WHERE ip_address = ? AND success = 0
            ");
            $stmt->execute([$ip]);
        }
    }
    
    // =====================================================================
    // IP ADDRESS HELPER
    // =====================================================================
    
    /**
     * Get real client IP behind Cloudflare tunnel
     * Falls back gracefully if not behind Cloudflare
     */
    public static function getRealIpAddress() {
        // Cloudflare sends the real client IP in this header
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        // Fallback for X-Forwarded-For (first IP in chain)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        // Direct connection fallback
        return $_SERVER['REMOTE_ADDR'];
    }
}
?>