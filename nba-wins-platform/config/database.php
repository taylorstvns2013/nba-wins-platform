<?php
// config/database.php - Enhanced Secure Database Configuration

class Database {
    private $host = 'localhost';
    private $db_name = 'nba_wins_platform';
    private $username = 'nba_user';
    private $password = 'NBAUser123!';
    private $conn;
    
    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    // Security configurations
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false, // Use real prepared statements
                    PDO::ATTR_PERSISTENT => false,       // Disable persistent connections
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
                ]
            );
            
            // Set SQL mode for strict data validation
            $this->conn->exec("SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'");
            
        } catch(PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            throw new Exception("Database connection failed");
        }
        
        return $this->conn;
    }
    
    // Close connection
    public function closeConnection() {
        $this->conn = null;
    }
}

// Session Security Configuration Class
class SessionManager {
    
    public static function startSecureSession() {
        // Session configuration for security
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_secure', 0); // Set to 1 when you have HTTPS
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_strict_mode', 1);
        ini_set('session.sid_length', 48);
        ini_set('session.sid_bits_per_character', 6);
        
        // Start session with secure settings
        session_start([
            'name' => 'NBA_SECURE_SESSION',
            'cookie_lifetime' => 0,
            'cookie_path' => '/',
            'cookie_secure' => false, // Change to true with HTTPS
            'cookie_httponly' => true,
            'cookie_samesite' => 'Strict',
            'use_strict_mode' => true,
            'sid_length' => 48,
            'sid_bits_per_character' => 6
        ]);
        
        // Session fingerprinting for security
        if (!isset($_SESSION['session_fingerprint'])) {
            $_SESSION['session_fingerprint'] = self::generateFingerprint();
        } else {
            if ($_SESSION['session_fingerprint'] !== self::generateFingerprint()) {
                self::destroySession();
                throw new Exception("Session security violation detected");
            }
        }
        
        // Session timeout check
        if (isset($_SESSION['last_activity']) && 
            (time() - $_SESSION['last_activity'] > 1800)) { // 30 minutes
            self::destroySession();
            throw new Exception("Session expired");
        }
        
        $_SESSION['last_activity'] = time();
    }
    
    private static function generateFingerprint() {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        
        return hash('sha256', $userAgent . $acceptLanguage . $acceptEncoding);
    }
    
    public static function regenerateSession() {
        session_regenerate_id(true);
        $_SESSION['session_fingerprint'] = self::generateFingerprint();
    }
    
    public static function destroySession() {
        $_SESSION = array();
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
    }
    
    public static function isLoggedIn() {
        return isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }
}

// CSRF Protection Class
class CSRFProtection {
    
    public static function generateToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    public static function validateToken($token) {
        if (!isset($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    public static function getTokenInput() {
        $token = self::generateToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }
}

// Security Headers
function setSecurityHeaders() {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Only set HSTS if using HTTPS
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Call security headers on every page
setSecurityHeaders();
?>