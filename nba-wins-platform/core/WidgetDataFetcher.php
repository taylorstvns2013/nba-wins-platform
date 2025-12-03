<?php
// /data/www/default/nba-wins-platform/core/WidgetDataFetcher.php
// Extracts data logic from participant_profile.php and analytics.php for reusable widgets

class WidgetDataFetcher {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Get upcoming 5 games for a user's teams in a specific league
     * 
     * @param int $user_id - The user ID
     * @param int $league_id - The league ID
     * @param string $after_date - Optional date to get games after (defaults to today)
     * @return array - Array of upcoming games
     */
    public function getUpcomingGames($user_id, $league_id, $after_date = null) {
        // If no date specified, use tomorrow (day after today)
        if ($after_date === null) {
            $after_date = date('Y-m-d', strtotime('+1 day'));
        } else {
            // Use the day after the provided date
            $after_date = date('Y-m-d', strtotime($after_date . ' +1 day'));
        }
        // First get the participant ID for this user in this league
        $stmt = $this->pdo->prepare("
            SELECT id FROM league_participants 
            WHERE user_id = ? AND league_id = ? AND status = 'active'
        ");
        $stmt->execute([$user_id, $league_id]);
        $participant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$participant) {
            return [];
        }
        
        $participant_id = $participant['id'];
        
        // Get all team names for this participant
        $teamNamesQuery = $this->pdo->prepare("
            SELECT nt.name 
            FROM draft_picks dp
            JOIN nba_teams nt ON dp.team_id = nt.id
            WHERE dp.league_participant_id = ?
        ");
        $teamNamesQuery->execute([$participant_id]);
        $participantTeams = $teamNamesQuery->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($participantTeams)) {
            return [];
        }
        
        $placeholders = str_repeat('?,', count($participantTeams) - 1) . '?';
        
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT
                g.date as game_date,
                g.home_team,
                g.away_team,
                g.home_team_code,
                g.away_team_code,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN 'home'
                    WHEN g.away_team IN ($placeholders) THEN 'away'
                END as team_location,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN g.home_team
                    WHEN g.away_team IN ($placeholders) THEN g.away_team
                END as my_team,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN g.away_team
                    WHEN g.away_team IN ($placeholders) THEN g.home_team
                END as opponent
            FROM games g
            WHERE (g.home_team IN ($placeholders) OR g.away_team IN ($placeholders))
            AND g.status_long = 'Scheduled'
            AND g.date >= ?
            ORDER BY g.date ASC
            LIMIT 5
        ");
        
        // Execute with team names repeated for each placeholder, plus the after_date parameter
        $params = array_merge(
            $participantTeams, $participantTeams, $participantTeams, $participantTeams,
            $participantTeams, $participantTeams, $participantTeams, $participantTeams,
            [$after_date]  // Add the date parameter at the end
        );
        $stmt->execute($params);
        $upcomingGames = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Look up owner for each opponent team
        foreach ($upcomingGames as &$game) {
            $ownerStmt = $this->pdo->prepare("
                SELECT u.display_name
                FROM draft_picks dp
                JOIN nba_teams nt ON dp.team_id = nt.id
                JOIN league_participants lp ON dp.league_participant_id = lp.id
                JOIN users u ON lp.user_id = u.id
                WHERE nt.name = ? AND lp.league_id = ?
                LIMIT 1
            ");
            $ownerStmt->execute([$game['opponent'], $league_id]);
            $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
            $game['opponent_owner'] = $owner ? $owner['display_name'] : null;
        }
        unset($game);
        
        return $upcomingGames;
    }
    
    /**
     * Get last 10 games for a user's teams in a specific league
     * 
     * @param int $user_id - The user ID
     * @param int $league_id - The league ID
     * @return array - Array of last 10 games
     */
    public function getLastGames($user_id, $league_id) {
        // First get the participant ID for this user in this league
        $stmt = $this->pdo->prepare("
            SELECT id FROM league_participants 
            WHERE user_id = ? AND league_id = ? AND status = 'active'
        ");
        $stmt->execute([$user_id, $league_id]);
        $participant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$participant) {
            return [];
        }
        
        $participant_id = $participant['id'];
        
        // Get all team names for this participant
        $teamNamesQuery = $this->pdo->prepare("
            SELECT nt.name 
            FROM draft_picks dp
            JOIN nba_teams nt ON dp.team_id = nt.id
            WHERE dp.league_participant_id = ?
        ");
        $teamNamesQuery->execute([$participant_id]);
        $participantTeams = $teamNamesQuery->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($participantTeams)) {
            return [];
        }
        
        $placeholders = str_repeat('?,', count($participantTeams) - 1) . '?';
        
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT
                g.date as game_date,
                g.start_time,
                g.home_team,
                g.away_team,
                g.home_team_code,
                g.away_team_code,
                g.home_points,
                g.away_points,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN 'home'
                    WHEN g.away_team IN ($placeholders) THEN 'away'
                END as team_location,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN g.home_team
                    WHEN g.away_team IN ($placeholders) THEN g.away_team
                END as my_team,
                CASE 
                    WHEN g.home_team IN ($placeholders) THEN g.away_team
                    WHEN g.away_team IN ($placeholders) THEN g.home_team
                END as opponent,
                CASE 
                    WHEN (g.home_team IN ($placeholders) AND g.home_points > g.away_points) OR 
                         (g.away_team IN ($placeholders) AND g.away_points > g.home_points) THEN 'W'
                    WHEN g.home_points IS NOT NULL THEN 'L'
                    ELSE NULL
                END as result
            FROM games g
            WHERE (g.home_team IN ($placeholders) OR g.away_team IN ($placeholders))
            AND g.status_long IN ('Final', 'Finished')
            AND g.date >= '2025-10-21'
            ORDER BY g.date DESC, g.start_time DESC
            LIMIT 10
        ");
        
        // Execute with team names repeated for each placeholder
        $params = array_merge(
            $participantTeams, $participantTeams, $participantTeams, $participantTeams,
            $participantTeams, $participantTeams, $participantTeams, $participantTeams,
            $participantTeams, $participantTeams
        );
        $stmt->execute($params);
        $lastGames = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Look up owner for each opponent team
        foreach ($lastGames as &$game) {
            $ownerStmt = $this->pdo->prepare("
                SELECT u.display_name
                FROM draft_picks dp
                JOIN nba_teams nt ON dp.team_id = nt.id
                JOIN league_participants lp ON dp.league_participant_id = lp.id
                JOIN users u ON lp.user_id = u.id
                WHERE nt.name = ? AND lp.league_id = ?
                LIMIT 1
            ");
            $ownerStmt->execute([$game['opponent'], $league_id]);
            $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
            $game['opponent_owner'] = $owner ? $owner['display_name'] : null;
        }
        unset($game);
        
        return $lastGames;
    }
    
    /**
     * Get league stats and rivals data for a user in a specific league
     * 
     * @param int $user_id - The user ID
     * @param int $league_id - The league ID
     * @return array - Array with league stats and rivals data
     */
    public function getLeagueStatsAndRivals($user_id, $league_id) {
        // First get the participant ID and team data for this user in this league
        $stmt = $this->pdo->prepare("
            SELECT lp.*, u.display_name, u.id as user_id, u.profile_photo
            FROM league_participants lp
            JOIN users u ON lp.user_id = u.id
            WHERE lp.user_id = ? AND lp.league_id = ?
        ");
        $stmt->execute([$user_id, $league_id]);
        $participant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$participant) {
            return null;
        }
        
        $participant_id = $participant['id'];
        
        // Fetch participant's teams
        $stmt = $this->pdo->prepare("
            SELECT dp.*, nt.name as team_name, nt.abbreviation, nt.logo_filename as logo, 
                   COALESCE(s.win, 0) as wins, COALESCE(s.loss, 0) as losses,
                   (COALESCE(s.win, 0) + COALESCE(s.loss, 0)) as games_played,
                   CASE 
                       WHEN (COALESCE(s.win, 0) + COALESCE(s.loss, 0)) > 0 
                       THEN ROUND((COALESCE(s.win, 0) / (COALESCE(s.win, 0) + COALESCE(s.loss, 0))) * 100, 1)
                       ELSE 0 
                   END as win_percentage
            FROM draft_picks dp
            JOIN league_participants lp ON dp.league_participant_id = lp.id
            JOIN nba_teams nt ON dp.team_id = nt.id
            LEFT JOIN 2025_2026 s ON nt.name = s.name
            WHERE dp.league_participant_id = ? AND lp.league_id = ?
            ORDER BY dp.pick_number ASC
        ");
        $stmt->execute([$participant_id, $league_id]);
        $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate total wins and losses
        $total_wins = 0;
        $total_losses = 0;
        foreach ($teams as $team) {
            $total_wins += $team['wins'];
            $total_losses += $team['losses'];
        }
        
        // Find best team
        $best_team = null;
        $best_wins = -1;
        $best_win_pct = -1;
        
        foreach ($teams as $team) {
            if ($team['wins'] > $best_wins) {
                $best_wins = $team['wins'];
                $best_win_pct = $team['win_percentage'];
                $best_team = $team;
            } elseif ($team['wins'] == $best_wins && $team['win_percentage'] > $best_win_pct) {
                $best_win_pct = $team['win_percentage'];
                $best_team = $team;
            }
        }
        
        // Get BIGGEST RIVAL (most wins against)
        $stmt = $this->pdo->prepare("
            SELECT 
                opponent_user.id as opponent_user_id,
                opponent_user.display_name as opponent_name,
                SUM(CASE 
                    WHEN (
                        (g.home_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers') 
                         OR g.home_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                        AND g.home_points > g.away_points
                    ) THEN 1
                    WHEN (
                        (g.away_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers')
                         OR g.away_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                        AND g.away_points > g.home_points
                    ) THEN 1
                    ELSE 0 
                END) as wins_against_opponent,
                SUM(CASE 
                    WHEN (
                        (g.home_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers')
                         OR g.home_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                        AND g.home_points < g.away_points
                    ) THEN 1
                    WHEN (
                        (g.away_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers')
                         OR g.away_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                        AND g.away_points < g.home_points
                    ) THEN 1
                    ELSE 0 
                END) as losses_against_opponent
            FROM league_participant_teams my_team
            JOIN league_participants my_participant ON my_team.league_participant_id = my_participant.id
            JOIN games g ON (
                g.home_team IN (
                    REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                    REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                ) OR 
                g.away_team IN (
                    REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                    REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                )
            ) 
            AND g.status_long IN ('Final', 'Finished')
            AND DATE(g.start_time) >= '2025-10-21'
            JOIN league_participant_teams opponent_team ON (
                (g.home_team IN (
                    REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                    REPLACE(REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                ) AND g.away_team IN (
                    REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                    REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                )) OR
                (g.away_team IN (
                    REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                    REPLACE(REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                ) AND g.home_team IN (
                    REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                    REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                ))
            )
            JOIN league_participants opponent_participant ON opponent_team.league_participant_id = opponent_participant.id
                AND opponent_participant.league_id = my_participant.league_id
                AND opponent_participant.id != my_participant.id
            JOIN users opponent_user ON opponent_participant.user_id = opponent_user.id
            WHERE my_participant.id = ?
            GROUP BY opponent_user.id, opponent_user.display_name
            HAVING wins_against_opponent > 0
            ORDER BY wins_against_opponent DESC, losses_against_opponent ASC, opponent_user.display_name
            LIMIT 1
        ");
        $stmt->execute([$participant_id]);
        $biggest_rival = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get NEMESIS (most losses against)
        $stmt = $this->pdo->prepare("
            SELECT 
                opponent_user.id as opponent_user_id,
                opponent_user.display_name as opponent_name,
                SUM(CASE 
                    WHEN (
                        (g.home_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers')
                         OR g.home_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                        AND g.home_points < g.away_points
                    ) THEN 1
                    WHEN (
                        (g.away_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers')
                         OR g.away_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                        AND g.away_points < g.home_points
                    ) THEN 1
                    ELSE 0 
                END) as losses_against_opponent,
                SUM(CASE 
                    WHEN (
                        (g.home_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers')
                         OR g.home_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                        AND g.home_points > g.away_points
                    ) THEN 1
                    WHEN (
                        (g.away_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers')
                         OR g.away_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                        AND g.away_points > g.home_points
                    ) THEN 1
                    ELSE 0 
                END) as wins_against_opponent
            FROM league_participant_teams my_team
            JOIN league_participants my_participant ON my_team.league_participant_id = my_participant.id
            JOIN games g ON (
                g.home_team IN (
                    REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                    REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                ) OR 
                g.away_team IN (
                    REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                    REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                )
            ) 
            AND g.status_long IN ('Final', 'Finished')
            AND DATE(g.start_time) >= '2025-10-21'
            JOIN league_participant_teams opponent_team ON (
                (g.home_team IN (
                    REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                    REPLACE(REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                ) AND g.away_team IN (
                    REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                    REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                )) OR
                (g.away_team IN (
                    REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                    REPLACE(REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                ) AND g.home_team IN (
                    REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                    REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                ))
            )
            JOIN league_participants opponent_participant ON opponent_team.league_participant_id = opponent_participant.id
                AND opponent_participant.league_id = my_participant.league_id
                AND opponent_participant.id != my_participant.id
            JOIN users opponent_user ON opponent_participant.user_id = opponent_user.id
            WHERE my_participant.id = ?
            GROUP BY opponent_user.id, opponent_user.display_name
            HAVING losses_against_opponent > 0
            ORDER BY losses_against_opponent DESC, wins_against_opponent ASC, opponent_user.display_name
            LIMIT 1
        ");
        $stmt->execute([$participant_id]);
        $nemesis = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'total_games_played' => array_sum(array_column($teams, 'games_played')),
            'total_wins' => $total_wins,
            'total_losses' => $total_losses,
            'avg_wins' => count($teams) > 0 ? round($total_wins / count($teams), 1) : 0,
            'avg_losses' => count($teams) > 0 ? round($total_losses / count($teams), 1) : 0,
            'best_team' => $best_team,
            'biggest_rival' => $biggest_rival,
            'nemesis' => $nemesis
        ];
    }
    
    // =====================================================================
    // ANALYTICS PAGE WIDGETS - From analytics.php
    // =====================================================================
    
    /**
     * Get platform-wide top 5 leaderboard (simple version without teams)
     * 
     * @return array - Array of top 5 participants across all leagues
     */
    public function getPlatformLeaderboard() {
        $stmt = $this->pdo->query("
            SELECT 
                u.display_name,
                l.display_name as league_name,
                l.id as league_id,
                lp.id as participant_id,
                u.id as user_id,
                COALESCE(SUM(t.win), 0) as total_wins
            FROM league_participants lp
            JOIN users u ON lp.user_id = u.id
            JOIN leagues l ON lp.league_id = l.id
            LEFT JOIN league_participant_teams lpt ON lp.id = lpt.league_participant_id
            LEFT JOIN 2025_2026 t ON lpt.team_name = t.name
            WHERE lp.status = 'active'
            GROUP BY u.id, u.display_name, l.id, l.display_name, lp.id
            ORDER BY total_wins DESC
            LIMIT 5
        ");
        return $stmt->fetchAll();
    }
    
    /**
     * Get platform-wide top 5 leaderboard WITH expandable team details
     * 
     * @return array - Array of top 5 participants with their teams
     */
    public function getPlatformLeaderboardWithTeams() {
        $stmt = $this->pdo->query("
            SELECT 
                u.display_name,
                l.display_name as league_name,
                l.id as league_id,
                lp.id as participant_id,
                u.id as user_id,
                COALESCE(SUM(t.win), 0) as total_wins
            FROM league_participants lp
            JOIN users u ON lp.user_id = u.id
            JOIN leagues l ON lp.league_id = l.id
            LEFT JOIN league_participant_teams lpt ON lp.id = lpt.league_participant_id
            LEFT JOIN 2025_2026 t ON lpt.team_name = t.name
            WHERE lp.status = 'active'
            GROUP BY u.id, u.display_name, l.id, l.display_name, lp.id
            ORDER BY total_wins DESC
            LIMIT 5
        ");
        $leaderboard = $stmt->fetchAll();
        
        // Get team details for each participant
        $leaderboard_with_teams = [];
        foreach ($leaderboard as $entry) {
            $stmt = $this->pdo->prepare("
                SELECT 
                    lpt.team_name, 
                    COALESCE(t.win, 0) as wins,
                    COALESCE(dp.pick_number, 999) as draft_pick_number
                FROM league_participant_teams lpt
                LEFT JOIN 2025_2026 t ON lpt.team_name = t.name
                LEFT JOIN nba_teams nt ON lpt.team_name = nt.name
                LEFT JOIN draft_picks dp ON (
                    lpt.league_participant_id = dp.league_participant_id
                    AND dp.draft_session_id = (
                        SELECT id FROM draft_sessions 
                        WHERE league_id = ? AND status = 'completed' 
                        ORDER BY created_at DESC LIMIT 1
                    )
                    AND dp.team_id = nt.id
                )
                WHERE lpt.league_participant_id = ?
                ORDER BY COALESCE(dp.pick_number, 999) ASC
            ");
            $stmt->execute([$entry['league_id'], $entry['participant_id']]);
            $entry['teams'] = $stmt->fetchAll();
            $leaderboard_with_teams[] = $entry;
        }
        
        return $leaderboard_with_teams;
    }
    
    /**
     * Get best draft steals across all leagues
     * 
     * @return array - Array of top 5 draft steals
     */
    public function getBestDraftSteals() {
        // First, calculate the average wins for each draft round
        $stmt = $this->pdo->query("
            SELECT 
                dp.round_number,
                AVG(COALESCE(t.win, 0)) as avg_round_wins
            FROM draft_picks dp
            JOIN nba_teams nt ON dp.team_id = nt.id
            LEFT JOIN 2025_2026 t ON nt.name = t.name
            WHERE EXISTS (
                SELECT 1 FROM draft_sessions ds 
                WHERE ds.id = dp.draft_session_id 
                AND ds.status = 'completed'
            )
            GROUP BY dp.round_number
        ");
        $roundAverages = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Get all drafted teams with their performance
        $stmt = $this->pdo->query("
            SELECT 
                nt.name as team_name,
                COALESCE(t.win, 0) as actual_wins,
                dp.pick_number,
                dp.round_number,
                u.display_name as owner_name,
                u.id as user_id,
                l.display_name as league_name,
                l.id as league_id,
                lp.id as participant_id
            FROM draft_picks dp
            JOIN league_participants lp ON dp.league_participant_id = lp.id
            JOIN users u ON lp.user_id = u.id
            JOIN leagues l ON lp.league_id = l.id
            JOIN nba_teams nt ON dp.team_id = nt.id
            LEFT JOIN 2025_2026 t ON nt.name = t.name
            WHERE EXISTS (
                SELECT 1 FROM draft_sessions ds 
                WHERE ds.id = dp.draft_session_id 
                AND ds.status = 'completed'
            )
            AND lp.status = 'active'
        ");
        $allDraftedTeams = $stmt->fetchAll();
        
        // Calculate steal score for each team
        $bestDraftSteals = [];
        foreach ($allDraftedTeams as $team) {
            $round = $team['round_number'];
            $round_avg = isset($roundAverages[$round]) ? $roundAverages[$round] : 0;
            
            $base_steal_score = $team['actual_wins'] - $round_avg;
            $pick_position_bonus = $team['pick_number'] / 100;
            $steal_score = $base_steal_score + $pick_position_bonus;
            
            $team['round_avg_wins'] = round($round_avg, 1);
            $team['steal_score'] = round($steal_score, 2);
            $team['base_steal_score'] = round($base_steal_score, 1);
            
            // Assign grade
            if ($base_steal_score >= 3.0) {
                $team['steal_grade'] = 'MASSIVE STEAL';
                $team['grade_color'] = '#28a745';
            } elseif ($base_steal_score >= 2.0) {
                $team['steal_grade'] = 'STEAL';
                $team['grade_color'] = '#5cb85c';
            } elseif ($base_steal_score >= 1.0) {
                $team['steal_grade'] = 'Good Value';
                $team['grade_color'] = '#17a2b8';
            } elseif ($base_steal_score >= 0) {
                $team['steal_grade'] = 'Fair';
                $team['grade_color'] = '#6c757d';
            } else {
                $team['steal_grade'] = 'Below Avg';
                $team['grade_color'] = '#ffc107';
            }
            
            $bestDraftSteals[] = $team;
        }
        
        // Sort by steal score
        usort($bestDraftSteals, function($a, $b) {
            if (abs($a['steal_score'] - $b['steal_score']) > 0.001) {
                return $b['steal_score'] <=> $a['steal_score'];
            }
            return $b['pick_number'] <=> $a['pick_number'];
        });
        
        // Take top 5 and assign ranks
        $topSteals = array_slice($bestDraftSteals, 0, 5);
        $rankedSteals = [];
        $current_rank = 1;
        $prev_steal_score = null;
        $items_at_current_rank = 0;
        
        foreach ($topSteals as $team) {
            if ($prev_steal_score !== null && abs($team['steal_score'] - $prev_steal_score) < 0.005) {
                $team['rank'] = $current_rank;
            } else {
                if ($items_at_current_rank > 0) {
                    $current_rank += $items_at_current_rank;
                }
                $team['rank'] = $current_rank;
                $items_at_current_rank = 0;
            }
            
            $prev_steal_score = $team['steal_score'];
            $items_at_current_rank++;
            $rankedSteals[] = $team;
        }
        
        return $rankedSteals;
    }
    
    /**
     * Get tracking graph data for a specific league
     * 
     * @param int $league_id - The league ID
     * @return array - Array with dates, participants, and tracking data
     */
    public function getTrackingGraphData($league_id) {
        // Fetch participant daily wins history
        $stmt = $this->pdo->prepare("
            SELECT 
                date,
                league_participant_id,
                total_wins
            FROM league_participant_daily_wins
            WHERE league_participant_id IN (
                SELECT id FROM league_participants 
                WHERE league_id = ? AND status = 'active'
            )
            AND date >= '2025-10-21'
            ORDER BY date ASC
        ");
        $stmt->execute([$league_id]);
        $dailyWinsData = $stmt->fetchAll();
        
        // Get participant information
        $stmt = $this->pdo->prepare("
            SELECT lp.id as participant_id, u.display_name
            FROM league_participants lp
            JOIN users u ON lp.user_id = u.id
            WHERE lp.league_id = ? AND lp.status = 'active'
            ORDER BY u.display_name
        ");
        $stmt->execute([$league_id]);
        $participantInfo = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Process data
        $trackingDates = [];
        $trackingParticipants = [];
        $trackingData = [];
        
        foreach ($dailyWinsData as $record) {
            if ($record['date'] < '2025-10-21') {
                continue;
            }
            
            $participant_id = $record['league_participant_id'];
            
            if (!isset($participantInfo[$participant_id])) {
                continue;
            }
            
            $participant_name = $participantInfo[$participant_id];
            
            if (!in_array($participant_name, $trackingParticipants)) {
                $trackingParticipants[] = $participant_name;
            }
            if (!in_array($record['date'], $trackingDates)) {
                $trackingDates[] = $record['date'];
            }
        }
        
        // Initialize dataset for each participant
        foreach ($trackingParticipants as $participant) {
            $trackingData[$participant] = [];
        }
        
        // Fill in the data points
        foreach ($trackingDates as $date) {
            foreach ($trackingParticipants as $participant) {
                $participant_id = array_search($participant, $participantInfo);
                
                $wins = 0;
                foreach ($dailyWinsData as $record) {
                    if ($record['date'] === $date && $record['league_participant_id'] == $participant_id) {
                        $wins = $record['total_wins'];
                        break;
                    }
                }
                $trackingData[$participant][] = $wins;
            }
        }
        
        return [
            'dates' => $trackingDates,
            'participants' => $trackingParticipants,
            'data' => $trackingData
        ];
    }
    
    /**
     * Get weekly rankings data for a specific league
     * 
     * @param int $league_id - The league ID
     * @return array - Array of weekly rankings
     */
    public function getWeeklyRankings($league_id) {
        $stmt = $this->pdo->prepare("
            SELECT 
                main.display_name,
                main.week_num,
                CONCAT('Week of ', main.monday_date) as week_label,
                main.week_total - IFNULL(prev_week.end_total, main.week_start_total) as weekly_wins
            FROM (
                SELECT 
                    u.display_name,
                    YEARWEEK(DATE_SUB(pdw.date, INTERVAL 1 DAY), 1) as week_num,
                    DATE_FORMAT(
                        MIN(DATE_SUB(pdw.date, INTERVAL DAYOFWEEK(pdw.date)-2 DAY)),
                        '%m/%d'
                    ) as monday_date,
                    MIN(pdw.total_wins) as week_start_total,
                    MAX(pdw.total_wins) as week_total,
                    MIN(pdw.date) as week_start_date
                FROM league_participant_daily_wins pdw
                JOIN league_participants lp ON pdw.league_participant_id = lp.id
                JOIN users u ON lp.user_id = u.id
                WHERE pdw.date >= '2025-10-21'
                    AND lp.league_id = ?
                    AND lp.status = 'active'
                GROUP BY 
                    u.display_name,
                    YEARWEEK(DATE_SUB(pdw.date, INTERVAL 1 DAY), 1)
            ) main
            LEFT JOIN (
                SELECT 
                    u.display_name,
                    YEARWEEK(DATE_SUB(pdw.date, INTERVAL 1 DAY), 1) as week_num,
                    MAX(pdw.total_wins) as end_total
                FROM league_participant_daily_wins pdw
                JOIN league_participants lp ON pdw.league_participant_id = lp.id
                JOIN users u ON lp.user_id = u.id
                WHERE pdw.date >= '2025-10-21'
                    AND lp.league_id = ?
                    AND lp.status = 'active'
                GROUP BY 
                    u.display_name,
                    YEARWEEK(DATE_SUB(pdw.date, INTERVAL 1 DAY), 1)
            ) prev_week ON prev_week.display_name = main.display_name 
                AND prev_week.week_num = main.week_num - 1
            WHERE main.week_total - IFNULL(prev_week.end_total, main.week_start_total) >= 0
            ORDER BY 
                main.week_num DESC,
                (main.week_total - IFNULL(prev_week.end_total, main.week_start_total)) DESC
        ");
        $stmt->execute([$league_id, $league_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get strength of schedule for a specific league
     * 
     * @param int $league_id - The league ID
     * @return array - Array of strength of schedule data
     */
    public function getStrengthOfSchedule($league_id) {
        $stmt = $this->pdo->prepare("
            SELECT 
                u.display_name,
                u.id as user_id,
                lp.id as participant_id
            FROM league_participants lp
            JOIN users u ON lp.user_id = u.id
            WHERE lp.league_id = ? AND lp.status = 'active'
        ");
        $stmt->execute([$league_id]);
        $participants = $stmt->fetchAll();
        
        $strengthOfSchedule = [];
        
        foreach ($participants as $participant) {
            // Get all games for this participant
            $stmt = $this->pdo->prepare("
                SELECT DISTINCT
                    g.id as game_id,
                    CASE 
                        WHEN g.home_team IN (
                            REPLACE(lpt.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                            REPLACE(REPLACE(lpt.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                        ) THEN g.away_team
                        WHEN g.away_team IN (
                            REPLACE(lpt.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                            REPLACE(REPLACE(lpt.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                        ) THEN g.home_team
                    END as opponent
                FROM league_participant_teams lpt
                JOIN games g ON (
                    g.home_team IN (
                        REPLACE(lpt.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                        REPLACE(REPLACE(lpt.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                    ) OR g.away_team IN (
                        REPLACE(lpt.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                        REPLACE(REPLACE(lpt.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                    )
                )
                WHERE lpt.league_participant_id = ?
                AND g.status_long IN ('Final', 'Finished')
                AND DATE(g.start_time) >= '2025-10-21'
            ");
            $stmt->execute([$participant['participant_id']]);
            $games = $stmt->fetchAll();
            
            if (count($games) > 0) {
                $total_opp_win_pct = 0;
                $game_count = 0;
                
                foreach ($games as $game) {
                    $oppStmt = $this->pdo->prepare("
                        SELECT 
                            COALESCE(win / NULLIF(win + loss, 0) * 100, 0) as win_pct
                        FROM 2025_2026
                        WHERE name = ?
                    ");
                    $oppStmt->execute([$game['opponent']]);
                    $oppRecord = $oppStmt->fetch();
                    
                    if ($oppRecord) {
                        $total_opp_win_pct += $oppRecord['win_pct'];
                        $game_count++;
                    }
                }
                
                if ($game_count > 0) {
                    $strengthOfSchedule[] = [
                        'display_name' => $participant['display_name'],
                        'user_id' => $participant['user_id'],
                        'participant_id' => $participant['participant_id'],
                        'total_games' => $game_count,
                        'opponent_win_pct' => round($total_opp_win_pct / $game_count, 1)
                    ];
                }
            }
        }
        
        // Sort by opponent win percentage
        usort($strengthOfSchedule, function($a, $b) {
            if ($b['opponent_win_pct'] == $a['opponent_win_pct']) {
                return strcmp($a['display_name'], $b['display_name']);
            }
            return $b['opponent_win_pct'] <=> $a['opponent_win_pct'];
        });
        
        return $strengthOfSchedule;
    }
    
    /**
     * Get Vegas over/under performance for a specific league
     * 
     * @param int $league_id - The league ID
     * @return array - Array with overperformers and underperformers
     */
    public function getVegasOverUnderPerformance($league_id) {
        // Get over/under projections
        $stmt = $this->pdo->query("
            SELECT team_name, over_under_number
            FROM over_under 
            ORDER BY over_under_number DESC, team_name ASC
        ");
        $over_unders = $stmt->fetchAll();
        
        // Get team owners for this league
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT
                lpt.team_name,
                u.display_name as owner,
                lp.id as participant_id,
                u.id as user_id
            FROM league_participant_teams lpt
            JOIN league_participants lp ON lpt.league_participant_id = lp.id
            JOIN users u ON lp.user_id = u.id
            WHERE lp.league_id = ? AND lp.status = 'active'
        ");
        $stmt->execute([$league_id]);
        $teamOwners = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $ownerMap = [];
        foreach ($teamOwners as $row) {
            $ownerMap[$row['team_name']] = [
                'owner' => $row['owner'],
                'user_id' => $row['user_id'],
                'participant_id' => $row['participant_id']
            ];
        }
        
        $overperformers = [];
        $underperformers = [];
        
        foreach ($over_unders as $ou) {
            $team_name = $ou['team_name'];
            $vegas_projection = $ou['over_under_number'];
            
            // Get current record
            $stmt = $this->pdo->prepare("
                SELECT win, loss
                FROM 2025_2026
                WHERE name = ?
            ");
            $stmt->execute([$team_name]);
            $record = $stmt->fetch();
            
            if ($record && isset($ownerMap[$team_name])) {
                $games_played = $record['win'] + $record['loss'];
                
                if ($games_played > 0) {
                    $current_pace = ($record['win'] / $games_played) * 82;
                    $variance = $current_pace - $vegas_projection;
                    
                    $team_data = [
                        'team_name' => $team_name,
                        'vegas_projection' => $vegas_projection,
                        'current_pace' => $current_pace,
                        'variance' => $variance,
                        'current_record' => $record['win'] . '-' . $record['loss'],
                        'games_played' => $games_played,
                        'owner' => $ownerMap[$team_name]['owner'],
                        'user_id' => $ownerMap[$team_name]['user_id'],
                        'participant_id' => $ownerMap[$team_name]['participant_id']
                    ];
                    
                    if ($variance > 0) {
                        $overperformers[] = $team_data;
                    } else if ($variance < 0) {
                        $underperformers[] = $team_data;
                    }
                }
            }
        }
        
        // Sort
        usort($overperformers, function($a, $b) {
            return $b['variance'] <=> $a['variance'];
        });
        
        usort($underperformers, function($a, $b) {
            return $a['variance'] <=> $b['variance'];
        });
        
        return [
            'overperformers' => array_slice($overperformers, 0, 5),
            'underperformers' => array_slice($underperformers, 0, 5)
        ];
    }
}
?>