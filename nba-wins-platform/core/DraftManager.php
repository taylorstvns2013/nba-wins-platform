<?php
// DraftManager.php - Snake Draft System Manager with Auto-Draft Support

class DraftManager {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Initialize a new draft for a league
     * Only commissioners can start drafts
     */
    public function startDraft($league_id, $user_id) {
        try {
            $this->pdo->beginTransaction();
            
            // Verify user is commissioner and draft is enabled
            if (!$this->canStartDraft($league_id, $user_id)) {
                throw new Exception("You don't have permission to start this draft or draft is disabled.");
            }
            
            // Check if draft already exists and is active
            $existingDraft = $this->getDraftSession($league_id);
            if ($existingDraft && in_array($existingDraft['status'], ['active', 'paused'])) {
                throw new Exception("Draft has already been started for this league.");
            }
            
            // Get league participants
            $participants = $this->getLeagueParticipants($league_id);
            if (count($participants) < 2) {
                throw new Exception("Need at least 2 participants to start a draft.");
            }
            
            // Calculate rounds (30 teams / number of participants)
            $total_rounds = floor(30 / count($participants));
            
            // Create draft session
            $stmt = $this->pdo->prepare("
                INSERT INTO draft_sessions (league_id, status, total_rounds, created_by) 
                VALUES (?, 'active', ?, ?)
            ");
            $stmt->execute([$league_id, $total_rounds, $user_id]);
            $draft_session_id = $this->pdo->lastInsertId();
            
            // Create randomized draft order
            $this->createDraftOrder($draft_session_id, $participants);
            
            // Set first pick (WITHOUT auto-draft trigger inside transaction)
            $this->setCurrentPickNoAutoDraft($draft_session_id, 1);
            
            // Clear existing team assignments for this league
            $this->clearLeagueTeams($league_id);
            
            // Log the start
            $this->logDraftEvent($draft_session_id, null, 'Draft started!', 'commissioner_action');
            
            $this->pdo->commit();
            
            // NOW trigger auto-draft AFTER transaction commits
            $this->triggerAutoDraftIfEnabled($draft_session_id, 1);
            
            return $draft_session_id;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * Make a pick in the draft
     */
    public function makePick($draft_session_id, $participant_id, $team_id, $picked_by_commissioner = false) {
        try {
            $this->pdo->beginTransaction();
            
            $draft = $this->getDraftSessionById($draft_session_id);
            if (!$draft || $draft['status'] !== 'active') {
                throw new Exception("Draft is not currently active.");
            }
            
            // Verify it's this participant's turn
            if (!$picked_by_commissioner && $draft['current_participant_id'] != $participant_id) {
                throw new Exception("It's not your turn to pick.");
            }
            
            // Verify team is available
            if (!$this->isTeamAvailable($draft_session_id, $team_id)) {
                throw new Exception("This team has already been selected.");
            }
            
            // Calculate round number
            $round_number = ceil($draft['current_pick_number'] / $this->getParticipantCount($draft_session_id));
            
            // Record the pick
            $pick_time = $draft['current_pick_started_at'] ? 
                         time() - strtotime($draft['current_pick_started_at']) : null;
            
            $stmt = $this->pdo->prepare("
                INSERT INTO draft_picks 
                (draft_session_id, league_participant_id, team_id, pick_number, round_number, 
                 pick_time_seconds, picked_by_commissioner) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $draft_session_id, $participant_id, $team_id, $draft['current_pick_number'], 
                $round_number, $pick_time, $picked_by_commissioner
            ]);
            
            // Add team to participant's roster
            $this->addTeamToParticipant($participant_id, $team_id, $round_number, $draft['current_pick_number']);
            
            // Log the pick
            $team_name = $this->getTeamName($team_id);
            $participant_name = $this->getParticipantName($participant_id);
            $message = "$participant_name selected $team_name";
            $this->logDraftEvent($draft_session_id, $participant_id, $message, 'pick');
            
            // Move to next pick (sets current pick WITHOUT triggering auto-draft)
            $this->advanceToNextPick($draft_session_id);
            
            // Get the next pick number for auto-draft trigger
            $next_draft = $this->getDraftSessionById($draft_session_id);
            $next_pick_number = $next_draft['current_pick_number'];
            
            $this->pdo->commit();
            
            // CRITICAL: Now that transaction is committed, trigger auto-draft for next participant if enabled
            if ($next_draft['status'] === 'active' && $next_pick_number) {
                $this->triggerAutoDraftIfEnabled($draft_session_id, $next_pick_number);
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * Auto-pick team based on user preferences or random if no preferences
     * NOW SUPPORTS USER PREFERENCES FROM user_draft_preferences TABLE
     */
    public function autoPickTeam($draft_session_id, $participant_id) {
        try {
            error_log("AUTO-DRAFT: Starting auto-pick for participant $participant_id");
            
            // Get user_id for this participant
            $stmt = $this->pdo->prepare("
                SELECT user_id FROM league_participants WHERE id = ?
            ");
            $stmt->execute([$participant_id]);
            $participant = $stmt->fetch();
            
            if (!$participant) {
                throw new Exception("Participant not found for auto-pick.");
            }
            
            $user_id = $participant['user_id'];
            error_log("AUTO-DRAFT: User ID is $user_id");
            
            // Get available teams
            $available_teams = $this->getAvailableTeams($draft_session_id);
            
            if (empty($available_teams)) {
                throw new Exception("No teams available for auto-pick.");
            }
            
            error_log("AUTO-DRAFT: " . count($available_teams) . " teams available");
            
            // Try to get team based on user preferences
            $selected_team = $this->getHighestRankedAvailableTeam($user_id, $available_teams, $draft_session_id);
            
            if (!$selected_team) {
                error_log("AUTO-DRAFT: No preferences found, picking best available over/under");
                // No preferences - pick the available team with the best preseason over/under
                $selected_team = $this->getBestOverUnderTeam($available_teams);
                
                if (!$selected_team) {
                    // Final fallback if over_under table has no data
                    error_log("AUTO-DRAFT: No over/under data, picking randomly");
                    $selected_team = $available_teams[array_rand($available_teams)];
                } else {
                    error_log("AUTO-DRAFT: Selected best over/under team: " . $selected_team['team_name']);
                }
            } else {
                error_log("AUTO-DRAFT: Selected team based on preferences: " . $selected_team['team_name']);
            }
            
            // Make the pick
            $this->makePick($draft_session_id, $participant_id, $selected_team['id'], false);
            
            // Mark as auto-picked
            $stmt = $this->pdo->prepare("
                UPDATE draft_picks 
                SET auto_picked = TRUE 
                WHERE draft_session_id = ? AND league_participant_id = ? 
                ORDER BY picked_at DESC LIMIT 1
            ");
            $stmt->execute([$draft_session_id, $participant_id]);
            
            // Log the auto-pick
            $participant_name = $this->getParticipantName($participant_id);
            $team_name = $selected_team['team_name'];
            $message = "$participant_name auto-picked $team_name";
            $this->logDraftEvent($draft_session_id, $participant_id, $message, 'auto_pick');
            
            error_log("AUTO-DRAFT: Successfully auto-picked team for participant $participant_id");
            
            return $selected_team;
            
        } catch (Exception $e) {
            error_log("AUTO-DRAFT ERROR: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get current draft status for AJAX polling
     */
    public function getDraftStatus($league_id) {
        $draft = $this->getDraftSession($league_id);
        
        if (!$draft) {
            return ['status' => 'not_started'];
        }
        
        $data = [
            'status' => $draft['status'],
            'current_pick_number' => $draft['current_pick_number'],
            'total_picks' => $this->getTotalPicks($draft['id']),
            'current_participant' => null,
            'time_remaining' => null,
            'recent_picks' => $this->getRecentPicks($draft['id'], 30),
            'available_teams' => $this->getAvailableTeams($draft['id']),
            'draft_order' => $this->getDraftOrder($draft['id'])
        ];
        
        if ($draft['current_participant_id']) {
            $data['current_participant'] = $this->getParticipantInfo($draft['current_participant_id']);
            
            // Calculate time remaining
            if ($draft['current_pick_started_at'] && $draft['status'] === 'active') {
                $elapsed = time() - strtotime($draft['current_pick_started_at']);
                $data['time_remaining'] = max(0, $draft['pick_time_limit'] - $elapsed);
            }
        }
        
        return $data;
    }
    
    /**
     * Pause or resume the draft (commissioner only)
     */
    public function pauseResumeDraft($draft_session_id, $user_id, $action) {
        $draft = $this->getDraftSessionById($draft_session_id);
        
        if (!$draft || $draft['created_by'] != $user_id) {
            throw new Exception("You don't have permission to control this draft.");
        }
        
        $new_status = ($action === 'pause') ? 'paused' : 'active';
        
        if ($action === 'pause') {
            $stmt = $this->pdo->prepare("
                UPDATE draft_sessions 
                SET status = ?, current_pick_started_at = NULL 
                WHERE id = ?
            ");
            $stmt->execute([$new_status, $draft_session_id]);
        } else {
            $stmt = $this->pdo->prepare("
                UPDATE draft_sessions 
                SET status = ?, current_pick_started_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$new_status, $draft_session_id]);
        }
        
        $message = "Draft " . ($action === 'pause' ? 'paused' : 'resumed') . " by commissioner";
        $this->logDraftEvent($draft_session_id, null, $message, 'commissioner_action');
        
        return true;
    }
    
    // =====================================================================
    // AUTO-DRAFT HELPER METHODS
    // =====================================================================
    
    /**
     * Check if a participant has auto-draft enabled
     */
    private function isAutoDraftEnabled($participant_id) {
        $stmt = $this->pdo->prepare("
            SELECT auto_draft_enabled 
            FROM league_participants 
            WHERE id = ?
        ");
        $stmt->execute([$participant_id]);
        $result = $stmt->fetch();
        
        $enabled = $result && $result['auto_draft_enabled'] == 1;
        error_log("AUTO-DRAFT CHECK: Participant $participant_id has auto_draft_enabled = " . ($enabled ? 'TRUE' : 'FALSE'));
        
        return $enabled;
    }
    
    /**
     * Get the highest-ranked available team based on user preferences
     */
    private function getHighestRankedAvailableTeam($user_id, $available_teams, $draft_session_id = null) {
        // Get league_id from the draft session for league-specific preferences
        $league_id = null;
        if ($draft_session_id) {
            $draft_info = $this->getDraftSessionById($draft_session_id);
            $league_id = $draft_info['league_id'] ?? null;
        }

        // Try league-specific preferences first
        $preferences = [];
        if ($league_id) {
            $stmt = $this->pdo->prepare("
                SELECT team_id, priority_rank 
                FROM user_draft_preferences 
                WHERE user_id = ? AND league_id = ?
                ORDER BY priority_rank ASC
            ");
            $stmt->execute([$user_id, $league_id]);
            $preferences = $stmt->fetchAll();
            if (!empty($preferences)) {
                error_log("AUTO-DRAFT: Using league-specific preferences for user $user_id, league $league_id");
            }
        }

        // Fall back to global preferences
        if (empty($preferences)) {
            $stmt = $this->pdo->prepare("
                SELECT team_id, priority_rank 
                FROM user_draft_preferences 
                WHERE user_id = ? AND league_id IS NULL
                ORDER BY priority_rank ASC
            ");
            $stmt->execute([$user_id]);
            $preferences = $stmt->fetchAll();
            if (!empty($preferences)) {
                error_log("AUTO-DRAFT: Using global preferences for user $user_id");
            }
        }
        
        if (empty($preferences)) {
            error_log("AUTO-DRAFT: No preferences found for user $user_id");
            return null;
        }
        
        error_log("AUTO-DRAFT: Found " . count($preferences) . " preferences for user $user_id");
        
        // Create a map of available team IDs
        $available_team_ids = array_column($available_teams, 'id');
        error_log("AUTO-DRAFT: Available team IDs: " . implode(', ', $available_team_ids));
        
        // Find the highest-ranked team that's still available
        foreach ($preferences as $pref) {
            if (in_array($pref['team_id'], $available_team_ids)) {
                // Found the highest-ranked available team
                $team_key = array_search($pref['team_id'], $available_team_ids);
                error_log("AUTO-DRAFT: Selected team_id {$pref['team_id']} with rank {$pref['priority_rank']}");
                return $available_teams[$team_key];
            }
        }
        
        error_log("AUTO-DRAFT: No preferred teams available, will use over/under fallback");
        return null;
    }
    
    /**
     * Get the available team with the best (highest) preseason over/under number.
     * Used as fallback when a user has no draft preferences set.
     */
    private function getBestOverUnderTeam($available_teams) {
        if (empty($available_teams)) return null;
        
        $available_ids = array_column($available_teams, 'id');
        if (empty($available_ids)) return null;
        
        $placeholders = implode(',', array_fill(0, count($available_ids), '?'));
        
        $stmt = $this->pdo->prepare("
            SELECT ou.id, ou.team_name, ou.over_under_number
            FROM over_under ou
            WHERE ou.id IN ($placeholders)
            ORDER BY ou.over_under_number DESC
            LIMIT 1
        ");
        $stmt->execute($available_ids);
        $best = $stmt->fetch();
        
        if (!$best) return null;
        
        // Find the matching team in the available_teams array
        foreach ($available_teams as $team) {
            if ($team['id'] == $best['id']) {
                error_log("AUTO-DRAFT: Best over/under pick: {$team['team_name']} (O/U: {$best['over_under_number']})");
                return $team;
            }
        }
        
        return null;
    }
    
    // =====================================================================
    // HELPER METHODS
    // =====================================================================
    
    private function canStartDraft($league_id, $user_id) {
        $stmt = $this->pdo->prepare("
            SELECT l.*, lp.user_id
            FROM leagues l
            LEFT JOIN league_participants lp ON l.id = lp.league_id AND lp.user_id = ?
            WHERE l.id = ? AND l.draft_enabled = TRUE AND l.draft_completed = FALSE
        ");
        $stmt->execute([$user_id, $league_id]);
        $result = $stmt->fetch();
        
        // Allow if user is commissioner or if no commissioner is set (allow any league member)
        return $result && ($result['commissioner_user_id'] == $user_id || 
                          $result['commissioner_user_id'] === null && $result['user_id']);
    }
    
    private function getDraftSession($league_id) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM draft_sessions 
            WHERE league_id = ? AND status IN ('pending', 'active', 'paused')
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$league_id]);
        return $stmt->fetch();
    }
    
    private function getDraftSessionById($draft_session_id) {
        $stmt = $this->pdo->prepare("SELECT * FROM draft_sessions WHERE id = ?");
        $stmt->execute([$draft_session_id]);
        return $stmt->fetch();
    }
    
    private function getLeagueParticipants($league_id) {
        $stmt = $this->pdo->prepare("
            SELECT lp.*, u.display_name 
            FROM league_participants lp
            JOIN users u ON lp.user_id = u.id
            WHERE lp.league_id = ? AND lp.status = 'active'
            ORDER BY lp.id
        ");
        $stmt->execute([$league_id]);
        return $stmt->fetchAll();
    }
    
    private function createDraftOrder($draft_session_id, $participants) {
        // Randomize the order
        shuffle($participants);
        
        foreach ($participants as $index => $participant) {
            $stmt = $this->pdo->prepare("
                INSERT INTO draft_order (draft_session_id, league_participant_id, draft_position) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$draft_session_id, $participant['id'], $index + 1]);
        }
    }
    
    /**
     * CRITICAL METHOD: Sets current pick WITHOUT triggering auto-draft
     * Used when called from within a transaction
     */
    private function setCurrentPickNoAutoDraft($draft_session_id, $pick_number) {
        // Get participant for this pick (snake draft logic)
        $participant_id = $this->getParticipantForPick($draft_session_id, $pick_number);
        
        error_log("DRAFT: Setting current pick to #$pick_number for participant $participant_id (no auto-draft)");
        
        $stmt = $this->pdo->prepare("
            UPDATE draft_sessions 
            SET current_pick_number = ?, current_participant_id = ?, current_pick_started_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$pick_number, $participant_id, $draft_session_id]);
        
        return $participant_id;
    }
    
    /**
     * Trigger auto-draft if enabled for the current participant
     * MUST be called AFTER transaction commits
     */
    private function triggerAutoDraftIfEnabled($draft_session_id, $pick_number) {
        $participant_id = $this->getParticipantForPick($draft_session_id, $pick_number);
        
        if ($this->isAutoDraftEnabled($participant_id)) {
            error_log("AUTO-DRAFT TRIGGER: Participant $participant_id has auto-draft enabled, executing auto-pick...");
            
            try {
                $this->autoPickTeam($draft_session_id, $participant_id);
                error_log("AUTO-DRAFT SUCCESS: Auto-pick completed for participant $participant_id");
            } catch (Exception $e) {
                error_log("AUTO-DRAFT FAILED: " . $e->getMessage());
                // Continue with normal draft flow even if auto-pick fails
            }
        } else {
            error_log("DRAFT: Participant $participant_id will pick manually");
        }
    }
    
    /**
     * DEPRECATED: Old method kept for reference
     * Sets current pick and triggers auto-draft if enabled
     */
    private function setCurrentPick($draft_session_id, $pick_number) {
        // Get participant for this pick (snake draft logic)
        $participant_id = $this->getParticipantForPick($draft_session_id, $pick_number);
        
        error_log("DRAFT: Setting current pick to #$pick_number for participant $participant_id");
        
        $stmt = $this->pdo->prepare("
            UPDATE draft_sessions 
            SET current_pick_number = ?, current_participant_id = ?, current_pick_started_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$pick_number, $participant_id, $draft_session_id]);
        
        // CRITICAL: Check if this participant has auto-draft enabled
        // If yes, immediately execute their auto-pick
        if ($this->isAutoDraftEnabled($participant_id)) {
            error_log("AUTO-DRAFT TRIGGER: Participant $participant_id has auto-draft enabled, executing auto-pick...");
            
            // Small delay to ensure database commit completes
            usleep(100000); // 0.1 second delay
            
            try {
                $this->autoPickTeam($draft_session_id, $participant_id);
                error_log("AUTO-DRAFT SUCCESS: Auto-pick completed for participant $participant_id");
            } catch (Exception $e) {
                error_log("AUTO-DRAFT FAILED: " . $e->getMessage());
                // Continue with normal draft flow even if auto-pick fails
            }
        } else {
            error_log("DRAFT: Participant $participant_id will pick manually");
        }
    }
    
    private function getParticipantForPick($draft_session_id, $pick_number) {
        $participant_count = $this->getParticipantCount($draft_session_id);
        $round = ceil($pick_number / $participant_count);
        $position_in_round = (($pick_number - 1) % $participant_count) + 1;
        
        // Snake draft: reverse order on even rounds
        if ($round % 2 == 0) {
            $position_in_round = $participant_count - $position_in_round + 1;
        }
        
        $stmt = $this->pdo->prepare("
            SELECT league_participant_id 
            FROM draft_order 
            WHERE draft_session_id = ? AND draft_position = ?
        ");
        $stmt->execute([$draft_session_id, $position_in_round]);
        $result = $stmt->fetch();
        
        return $result ? $result['league_participant_id'] : null;
    }
    
    private function getParticipantCount($draft_session_id) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count FROM draft_order WHERE draft_session_id = ?
        ");
        $stmt->execute([$draft_session_id]);
        $result = $stmt->fetch();
        return $result['count'];
    }
    
    private function getTotalPicks($draft_session_id) {
        $draft = $this->getDraftSessionById($draft_session_id);
        $participant_count = $this->getParticipantCount($draft_session_id);
        return $draft['total_rounds'] * $participant_count;
    }
    
    private function isTeamAvailable($draft_session_id, $team_id) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count 
            FROM draft_picks 
            WHERE draft_session_id = ? AND team_id = ?
        ");
        $stmt->execute([$draft_session_id, $team_id]);
        $result = $stmt->fetch();
        return $result['count'] == 0;
    }
    
    private function getAvailableTeams($draft_session_id) {
        $stmt = $this->pdo->prepare("
            SELECT nt.id, nt.name as team_name, nt.abbreviation 
            FROM nba_teams nt
            WHERE nt.id NOT IN (
                SELECT COALESCE(team_id, 0) FROM draft_picks WHERE draft_session_id = ?
            )
            ORDER BY nt.name
        ");
        $stmt->execute([$draft_session_id]);
        return $stmt->fetchAll();
    }
    
    private function addTeamToParticipant($participant_id, $team_id, $round, $pick_number) {
        $team_name = $this->getTeamName($team_id);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO league_participant_teams 
            (league_participant_id, team_name, draft_round, draft_pick_number) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$participant_id, $team_name, $round, $pick_number]);
    }
    
    private function getTeamName($team_id) {
        $stmt = $this->pdo->prepare("SELECT name FROM nba_teams WHERE id = ?");
        $stmt->execute([$team_id]);
        $result = $stmt->fetch();
        return $result ? $result['name'] : 'Unknown Team';
    }
    
    private function getParticipantName($participant_id) {
        $stmt = $this->pdo->prepare("
            SELECT u.display_name 
            FROM league_participants lp
            JOIN users u ON lp.user_id = u.id
            WHERE lp.id = ?
        ");
        $stmt->execute([$participant_id]);
        $result = $stmt->fetch();
        return $result ? $result['display_name'] : 'Unknown Participant';
    }
    
    private function getParticipantInfo($participant_id) {
        $stmt = $this->pdo->prepare("
            SELECT lp.id as participant_id, lp.participant_name, u.display_name, u.id as user_id
            FROM league_participants lp
            JOIN users u ON lp.user_id = u.id
            WHERE lp.id = ?
        ");
        $stmt->execute([$participant_id]);
        return $stmt->fetch();
    }
    
    private function getRecentPicks($draft_session_id, $limit = 5) {
        $stmt = $this->pdo->prepare("
            SELECT 
                dp.pick_number,
                dp.round_number,
                dp.picked_at,
                dp.auto_picked,
                dp.picked_by_commissioner,
                nt.name as team_name,
                nt.abbreviation,
                u.display_name as participant_name
            FROM draft_picks dp
            LEFT JOIN nba_teams nt ON dp.team_id = nt.id
            LEFT JOIN league_participants lp ON dp.league_participant_id = lp.id
            LEFT JOIN users u ON lp.user_id = u.id
            WHERE dp.draft_session_id = ?
            ORDER BY dp.pick_number DESC
            LIMIT ?
        ");
        $stmt->execute([$draft_session_id, $limit]);
        return $stmt->fetchAll();
    }
    
    private function getDraftOrder($draft_session_id) {
        $stmt = $this->pdo->prepare("
            SELECT 
                dor.draft_position, 
                lp.participant_name, 
                u.display_name, 
                lp.id as participant_id
            FROM draft_order dor
            LEFT JOIN league_participants lp ON dor.league_participant_id = lp.id
            LEFT JOIN users u ON lp.user_id = u.id
            WHERE dor.draft_session_id = ?
            ORDER BY dor.draft_position
        ");
        $stmt->execute([$draft_session_id]);
        return $stmt->fetchAll();
    }
    
    private function clearLeagueTeams($league_id) {
        $stmt = $this->pdo->prepare("
            DELETE lpt FROM league_participant_teams lpt
            JOIN league_participants lp ON lpt.league_participant_id = lp.id
            WHERE lp.league_id = ?
        ");
        $stmt->execute([$league_id]);
    }
    
    private function advanceToNextPick($draft_session_id) {
        $draft = $this->getDraftSessionById($draft_session_id);
        $total_picks = $this->getTotalPicks($draft_session_id);
        $next_pick = $draft['current_pick_number'] + 1;
        
        if ($next_pick > $total_picks) {
            // Draft is complete
            $stmt = $this->pdo->prepare("
                UPDATE draft_sessions 
                SET status = 'completed', completed_at = NOW(), 
                    current_participant_id = NULL, current_pick_started_at = NULL
                WHERE id = ?
            ");
            $stmt->execute([$draft_session_id]);
            
            // Mark league draft as completed
            $stmt = $this->pdo->prepare("
                UPDATE leagues SET draft_completed = TRUE WHERE id = ?
            ");
            $stmt->execute([$draft['league_id']]);
            
            $this->logDraftEvent($draft_session_id, null, 'Draft completed!', 'commissioner_action');
        } else {
            // Set next pick WITHOUT auto-draft trigger (we're inside makePick's transaction)
            $this->setCurrentPickNoAutoDraft($draft_session_id, $next_pick);
            
            // NOTE: Auto-draft will be triggered AFTER makePick commits its transaction
            // This happens in the makePick method after commit
        }
    }
    
    private function logDraftEvent($draft_session_id, $participant_id, $message, $event_type) {
        $stmt = $this->pdo->prepare("
            INSERT INTO draft_log (draft_session_id, league_participant_id, message, event_type) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$draft_session_id, $participant_id, $message, $event_type]);
    }
}
?>