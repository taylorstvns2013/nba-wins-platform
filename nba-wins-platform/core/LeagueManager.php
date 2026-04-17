<?php
// nba-wins-platform/core/LeagueManager.php
// League creation, joining, and management for user-created leagues

require_once __DIR__ . '/../config/platform_settings.php';

class LeagueManager {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Create a new league with the current user as commissioner
     */
    public function createLeague($userId, $leagueName, $leagueSize, $draftDate = null) {
        try {
            // Check if league creation is enabled (season control)
            if (!LEAGUE_CREATION_ENABLED) {
                return ['success' => false, 'message' => LEAGUE_CREATION_DISABLED_MESSAGE];
            }

            // Check per-user league creation cap
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM leagues 
                WHERE commissioner_user_id = ? AND status = 'active'
            ");
            $stmt->execute([$userId]);
            if ($stmt->fetchColumn() >= MAX_LEAGUES_PER_USER) {
                return ['success' => false, 'message' => 'You have reached the maximum of ' . MAX_LEAGUES_PER_USER . ' active leagues.'];
            }

            $this->pdo->beginTransaction();

            // Validate inputs
            $leagueName = trim($leagueName);
            if (empty($leagueName) || strlen($leagueName) < 3 || strlen($leagueName) > 50) {
                throw new Exception("League name must be 3-50 characters.");
            }

            $leagueSize = (int)$leagueSize;
            if ($leagueSize < 5 || $leagueSize > 6) {
                throw new Exception("League size must be 5 or 6 participants.");
            }

            // Validate draft date if provided
            if ($draftDate) {
                $parsedDate = strtotime($draftDate);
                if (!$parsedDate) {
                    throw new Exception("Invalid draft date format.");
                }
                if ($parsedDate < time()) {
                    throw new Exception("Draft date must be in the future.");
                }
                $draftDate = date('Y-m-d H:i:s', $parsedDate);
            }

            // Generate unique PIN
            $pinCode = $this->generateUniquePIN();

            // Get next league_number
            $stmt = $this->pdo->query("SELECT COALESCE(MAX(league_number), 0) + 1 FROM leagues");
            $nextLeagueNumber = $stmt->fetchColumn();

            // Create the league
            $stmt = $this->pdo->prepare("
                INSERT INTO leagues (league_number, display_name, pin_code, user_limit, status, commissioner_user_id, draft_completed, draft_date)
                VALUES (?, ?, ?, ?, 'active', ?, FALSE, ?)
            ");
            $stmt->execute([$nextLeagueNumber, $leagueName, $pinCode, $leagueSize, $userId, $draftDate]);
            $leagueId = $this->pdo->lastInsertId();

            // Add commissioner as first participant
            $stmt = $this->pdo->prepare("SELECT display_name FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $displayName = $stmt->fetchColumn();

            $stmt = $this->pdo->prepare("
                INSERT INTO league_participants (user_id, league_id, participant_name, status)
                VALUES (?, ?, ?, 'active')
            ");
            $stmt->execute([$userId, $leagueId, $displayName]);

            $this->pdo->commit();

            return [
                'success' => true,
                'message' => "League created! Your league PIN is: $pinCode",
                'league_id' => $leagueId,
                'pin_code' => $pinCode
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Join an existing league by PIN code
     */
    public function joinLeague($userId, $pinCode) {
        try {
            // Check if league joining is enabled (season control)
            if (!LEAGUE_JOINING_ENABLED) {
                return ['success' => false, 'message' => LEAGUE_JOINING_DISABLED_MESSAGE];
            }

            $this->pdo->beginTransaction();

            $pinCode = strtoupper(trim($pinCode));
            if (empty($pinCode)) {
                throw new Exception("League PIN is required.");
            }

            // Find the league
            $stmt = $this->pdo->prepare("
                SELECT l.id, l.display_name, l.user_limit,
                       COUNT(lp.id) as current_participants
                FROM leagues l
                LEFT JOIN league_participants lp ON l.id = lp.league_id AND lp.status = 'active'
                WHERE l.pin_code = ? AND l.status = 'active'
                GROUP BY l.id
            ");
            $stmt->execute([$pinCode]);
            $league = $stmt->fetch();

            if (!$league) {
                throw new Exception("No active league found with that PIN code.");
            }

            // Check if already a member
            $stmt = $this->pdo->prepare("
                SELECT id FROM league_participants
                WHERE user_id = ? AND league_id = ? AND status = 'active'
            ");
            $stmt->execute([$userId, $league['id']]);
            if ($stmt->rowCount() > 0) {
                throw new Exception("You are already a member of '{$league['display_name']}'.");
            }

            // Check capacity
            if ($league['current_participants'] >= $league['user_limit']) {
                throw new Exception("League '{$league['display_name']}' is full ({$league['current_participants']}/{$league['user_limit']} participants).");
            }

            // Get user's display name
            $stmt = $this->pdo->prepare("SELECT display_name FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $displayName = $stmt->fetchColumn();

            // Add user to league
            $stmt = $this->pdo->prepare("
                INSERT INTO league_participants (user_id, league_id, participant_name, status)
                VALUES (?, ?, ?, 'active')
            ");
            $stmt->execute([$userId, $league['id'], $displayName]);

            $this->pdo->commit();

            $spotsLeft = $league['user_limit'] - $league['current_participants'] - 1;
            return [
                'success' => true,
                'message' => "Joined '{$league['display_name']}' successfully! ($spotsLeft spots remaining)",
                'league_id' => $league['id'],
                'league_name' => $league['display_name']
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get leagues where user is commissioner, with member details
     */
    public function getCommissionerLeagues($userId) {
        $stmt = $this->pdo->prepare("
            SELECT l.id, l.league_number, l.display_name, l.pin_code, l.user_limit,
                   l.draft_completed, l.draft_date, l.created_at,
                   COUNT(lp.id) as current_participants
            FROM leagues l
            LEFT JOIN league_participants lp ON l.id = lp.league_id AND lp.status = 'active'
            WHERE l.commissioner_user_id = ? AND l.status = 'active'
            GROUP BY l.id
            ORDER BY l.created_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Get members of a specific league
     */
    public function getLeagueMembers($leagueId) {
        $stmt = $this->pdo->prepare("
            SELECT lp.id, lp.user_id, lp.participant_name, lp.status,
                   u.username, u.display_name,
                   (l.commissioner_user_id = lp.user_id) as is_commissioner
            FROM league_participants lp
            JOIN users u ON lp.user_id = u.id
            JOIN leagues l ON lp.league_id = l.id
            WHERE lp.league_id = ? AND lp.status = 'active'
            ORDER BY is_commissioner DESC, lp.participant_name ASC
        ");
        $stmt->execute([$leagueId]);
        return $stmt->fetchAll();
    }

    /**
     * Get all leagues a user belongs to with participant counts
     */
    public function getUserLeaguesWithDetails($userId) {
        $stmt = $this->pdo->prepare("
            SELECT l.id, l.league_number, l.display_name, l.pin_code, l.user_limit,
                   l.draft_completed, l.draft_date, l.commissioner_user_id,
                   COUNT(lp2.id) as current_participants,
                   (l.commissioner_user_id = ?) as is_commissioner
            FROM leagues l
            JOIN league_participants lp ON l.id = lp.league_id AND lp.user_id = ? AND lp.status = 'active'
            LEFT JOIN league_participants lp2 ON l.id = lp2.league_id AND lp2.status = 'active'
            WHERE l.status = 'active'
            GROUP BY l.id
            ORDER BY l.league_number ASC
        ");
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Check if user is commissioner of a league
     */
    public function isCommissioner($userId, $leagueId) {
        $stmt = $this->pdo->prepare("
            SELECT id FROM leagues WHERE id = ? AND commissioner_user_id = ? AND status = 'active'
        ");
        $stmt->execute([$leagueId, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Update draft date (commissioner only)
     */
    public function updateDraftDate($userId, $leagueId, $draftDate) {
        if (!$this->isCommissioner($userId, $leagueId)) {
            return ['success' => false, 'message' => 'Only the commissioner can update the draft date.'];
        }

        $parsedDate = strtotime($draftDate);
        if (!$parsedDate || $parsedDate < time()) {
            return ['success' => false, 'message' => 'Draft date must be a valid future date.'];
        }

        $stmt = $this->pdo->prepare("UPDATE leagues SET draft_date = ? WHERE id = ?");
        $stmt->execute([date('Y-m-d H:i:s', $parsedDate), $leagueId]);

        return ['success' => true, 'message' => 'Draft date updated.'];
    }

    /**
     * Generate a unique 6-character alphanumeric PIN code
     */
    private function generateUniquePIN() {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Excluded I, O, 0, 1 to avoid confusion
        $maxAttempts = 20;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $pin = '';
            for ($i = 0; $i < 6; $i++) {
                $pin .= $chars[random_int(0, strlen($chars) - 1)];
            }

            // Check uniqueness
            $stmt = $this->pdo->prepare("SELECT id FROM leagues WHERE pin_code = ?");
            $stmt->execute([$pin]);
            if ($stmt->rowCount() === 0) {
                return $pin;
            }
        }

        throw new Exception("Unable to generate unique PIN code. Please try again.");
    }
}
?>