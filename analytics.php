<?php
// Set timezone to EST
date_default_timezone_set('America/New_York');

// Start session and check authentication
session_start();

// Get current league and user context
$current_league_id = isset($_SESSION['current_league_id']) ? $_SESSION['current_league_id'] : '';
$current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : '';

$user_id = $_SESSION['user_id'];
$league_id = $_SESSION['current_league_id'];
$currentLeagueId = $league_id; // Define for navigation menu

// Use centralized database connection
require_once '/data/www/default/nba-wins-platform/config/db_connection.php';

// Check which widgets are already pinned (only if authenticated)
$pinned_widgets = [];
if (isset($current_user_id) && !empty($current_user_id)) {
    $stmt = $pdo->prepare("
        SELECT widget_type FROM user_dashboard_widgets 
        WHERE user_id = ? AND is_active = 1
    ");
    $stmt->execute([$current_user_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pinned_widgets[] = $row['widget_type'];
    }
}

try {
    // Get basic platform statistics
    $stats = [];
    
    // Total leagues
    $stmt = $pdo->query("SELECT COUNT(*) as total_leagues FROM leagues");
    $stats['total_leagues'] = $stmt->fetch()['total_leagues'];
    
    // Total participants
    $stmt = $pdo->query("SELECT COUNT(*) as total_participants FROM league_participants WHERE status = 'active'");
    $stats['total_participants'] = $stmt->fetch()['total_participants'];
    
    // Total users
    $stmt = $pdo->query("SELECT COUNT(*) as total_users FROM users WHERE status = 'active'");
    $stats['total_users'] = $stmt->fetch()['total_users'];
    
    // Total completed drafts
    $stmt = $pdo->query("SELECT COUNT(*) as completed_drafts FROM draft_sessions WHERE status = 'completed'");
    $stats['completed_drafts'] = $stmt->fetch()['completed_drafts'];

    // Get top performing teams (highest win percentage)
    $stmt = $pdo->query("
        SELECT name, win, loss, 
               ROUND((win / (win + loss)) * 100, 1) as win_percentage
        FROM 2025_2026 
        WHERE (win + loss) > 0
        ORDER BY win_percentage DESC 
        LIMIT 10
    ");
    $top_teams = $stmt->fetchAll();

    // Get league activity summary
    $stmt = $pdo->query("
        SELECT l.display_name, 
               COUNT(lp.id) as participant_count,
               l.created_at
        FROM leagues l
        LEFT JOIN league_participants lp ON l.id = lp.league_id AND lp.status = 'active'
        GROUP BY l.id, l.display_name, l.created_at
        ORDER BY participant_count DESC
        LIMIT 5
    ");
    $league_activity = $stmt->fetchAll();

    // Get recent draft activity
    $stmt = $pdo->query("
        SELECT ds.created_at, ds.status, l.display_name as league_name
        FROM draft_sessions ds
        JOIN leagues l ON ds.league_id = l.id
        ORDER BY ds.created_at DESC
        LIMIT 5
    ");
    $recent_drafts = $stmt->fetchAll();

    // Get preseason over/unders
    $stmt = $pdo->query("
        SELECT team_name, over_under_number
        FROM over_under 
        ORDER BY over_under_number DESC, team_name ASC
    ");
    $over_unders = $stmt->fetchAll();
    
    // =====================================================================
    // VEGAS OVER/UNDER PERFORMANCE - LEAGUE SPECIFIC
    // Compare current pace vs preseason Vegas projections
    // =====================================================================
    
    $overperformers = [];
    $underperformers = [];
    
    if (!empty($league_id)) {
        // Get all teams with owner information for current league
        $stmt = $pdo->prepare("
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
        
        // Create a map of team names to owners
        $ownerMap = [];
        foreach ($teamOwners as $row) {
            $ownerMap[$row['team_name']] = [
                'owner' => $row['owner'],
                'user_id' => $row['user_id'],
                'participant_id' => $row['participant_id']
            ];
        }
        
        // Calculate over/under performance for all teams
        foreach ($over_unders as $ou) {
            $team_name = $ou['team_name'];
            $vegas_projection = $ou['over_under_number'];
            
            // Get current record from 2025_2026 table
            $stmt = $pdo->prepare("
                SELECT win, loss
                FROM 2025_2026
                WHERE name = ?
            ");
            $stmt->execute([$team_name]);
            $record = $stmt->fetch();
            
            if ($record) {
                $games_played = $record['win'] + $record['loss'];
                
                if ($games_played > 0) {
                    // Calculate projected wins based on current pace
                    $current_pace = ($record['win'] / $games_played) * 82;
                    $variance = $current_pace - $vegas_projection;
                    
                    $team_data = [
                        'team_name' => $team_name,
                        'vegas_projection' => $vegas_projection,
                        'current_pace' => $current_pace,
                        'variance' => $variance,
                        'current_record' => $record['win'] . '-' . $record['loss'],
                        'games_played' => $games_played,
                        'owner' => isset($ownerMap[$team_name]) ? $ownerMap[$team_name]['owner'] : null,
                        'user_id' => isset($ownerMap[$team_name]) ? $ownerMap[$team_name]['user_id'] : null,
                        'participant_id' => isset($ownerMap[$team_name]) ? $ownerMap[$team_name]['participant_id'] : null
                    ];
                    
                    // Only include teams that are owned in the current league
                    if (isset($ownerMap[$team_name])) {
                        if ($variance > 0) {
                            $overperformers[] = $team_data;
                        } else if ($variance < 0) {
                            $underperformers[] = $team_data;
                        }
                    }
                }
            }
        }
        
        // Sort by variance (absolute value)
        usort($overperformers, function($a, $b) {
            return $b['variance'] <=> $a['variance'];
        });
        
        usort($underperformers, function($a, $b) {
            return $a['variance'] <=> $b['variance'];
        });
        
        // Keep top 5 from each category
        $overperformers = array_slice($overperformers, 0, 5);
        $underperformers = array_slice($underperformers, 0, 5);
    }

    // Get platform-wide top 5 leaderboard with proper tie handling
    $stmt = $pdo->query("
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
    $platform_leaderboard = $stmt->fetchAll();

    // =====================================================================
    // BEST DRAFT STEALS - PLATFORM WIDE
    // Shows individual teams outperforming their draft round average
    // Compares each team's actual wins vs average wins for their draft round
    // UPDATED: Later picks get higher value (pick #20 better than pick #10)
    // =====================================================================
    $bestDraftSteals = [];
    
    // First, calculate the average wins for each draft round across all completed drafts
    $stmt = $pdo->query("
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
    
    // Now get all drafted teams with their performance
    $stmt = $pdo->query("
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
    foreach ($allDraftedTeams as $team) {
        $round = $team['round_number'];
        $round_avg = isset($roundAverages[$round]) ? $roundAverages[$round] : 0;
        
        // Base Steal Score = Actual Wins - Round Average Wins
        $base_steal_score = $team['actual_wins'] - $round_avg;
        
        // Pick Position Bonus: LATER picks in a round get HIGHER value
        // Picking 76ers at #17 is better value than at #11 if they perform the same
        // Use pick_number directly as bonus (higher number = better value)
        $pick_position_bonus = $team['pick_number'] / 100;
        
        // Final Steal Score = Base Score + Pick Position Bonus
        $steal_score = $base_steal_score + $pick_position_bonus;
        
        $team['round_avg_wins'] = round($round_avg, 1);
        $team['steal_score'] = round($steal_score, 2);
        $team['base_steal_score'] = round($base_steal_score, 1); // For display purposes
        
        // Assign grade based on BASE steal score (not adjusted)
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
    
    // Sort by adjusted steal score (highest first), then by later pick number as tiebreaker
    usort($bestDraftSteals, function($a, $b) {
        // Compare steal scores - we want DESCENDING order (highest first)
        $result = $b['steal_score'] <=> $a['steal_score'];
        
        // If scores are different (not essentially equal), use that comparison
        // Using very small tolerance to handle floating point precision
        if (abs($a['steal_score'] - $b['steal_score']) > 0.001) {
            return $result;
        }
        
        // Scores are essentially equal, use pick number as tiebreaker (higher pick = better)
        return $b['pick_number'] <=> $a['pick_number'];
    });
    
    // DEBUG: Store sorted order for display
    $debug_sorted = [];
    foreach (array_slice($bestDraftSteals, 0, 10) as $idx => $team) {
        $debug_sorted[] = "Pos $idx: {$team['team_name']} = {$team['steal_score']} (pick #{$team['pick_number']})";
    }
    
    // Take top 5
    $topSteals = array_slice($bestDraftSteals, 0, 5);
    
    // Assign rankings with ties - teams with identical steal scores get the same rank
    $bestDraftSteals = [];
    $current_rank = 1;
    $prev_steal_score = null;
    $items_at_current_rank = 0;
    
    foreach ($topSteals as $team) {
        // If steal score matches previous within tolerance, keep same rank
        // Use smaller tolerance to only treat truly identical scores as ties
        if ($prev_steal_score !== null && abs($team['steal_score'] - $prev_steal_score) < 0.005) {
            $team['rank'] = $current_rank;
        } else {
            // New steal score - advance rank by number of items at previous rank
            if ($items_at_current_rank > 0) {
                $current_rank += $items_at_current_rank;
            }
            $team['rank'] = $current_rank;
            $items_at_current_rank = 0;
        }
        
        $prev_steal_score = $team['steal_score'];
        $items_at_current_rank++;
        $bestDraftSteals[] = $team;
    }

    // =====================================================================
    // GAMES PLAYED RANKINGS - LEAGUE SPECIFIC
    // Shows all participants ranked by total games played
    // =====================================================================
    
    $gamesPlayedRankings = [];
    
    if (!empty($league_id)) {
        $stmt = $pdo->prepare("
            SELECT 
                u.display_name,
                u.id as user_id,
                lp.id as participant_id,
                COALESCE(SUM(t.win + t.loss), 0) as total_games_played,
                COALESCE(SUM(t.win), 0) as total_wins,
                COALESCE(SUM(t.loss), 0) as total_losses
            FROM league_participants lp
            JOIN users u ON lp.user_id = u.id
            LEFT JOIN league_participant_teams lpt ON lp.id = lpt.league_participant_id
            LEFT JOIN 2025_2026 t ON lpt.team_name = t.name
            WHERE lp.league_id = ? AND lp.status = 'active'
            GROUP BY u.id, u.display_name, lp.id
            ORDER BY total_games_played DESC, total_wins DESC
        ");
        $stmt->execute([$league_id]);
        $gamesPlayedRankings = $stmt->fetchAll();
    }

    // =====================================================================
    // STRENGTH OF SCHEDULE - LEAGUE SPECIFIC
    // Calculate opponent win percentage for each participant
    // =====================================================================
    
    $strengthOfSchedule = [];
    
    if (!empty($league_id)) {
        // First, get all participants and their game counts
        $stmt = $pdo->prepare("
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
        
        foreach ($participants as $participant) {
            // Get all unique games and their opponents for this participant
            $stmt = $pdo->prepare("
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
                // Calculate opponent win percentages
                $total_opp_win_pct = 0;
                $game_count = 0;
                
                foreach ($games as $game) {
                    // Get opponent's record
                    $oppStmt = $pdo->prepare("
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
        
        // Sort by opponent win percentage (highest first)
        usort($strengthOfSchedule, function($a, $b) {
            if ($b['opponent_win_pct'] == $a['opponent_win_pct']) {
                return strcmp($a['display_name'], $b['display_name']);
            }
            return $b['opponent_win_pct'] <=> $a['opponent_win_pct'];
        });
    }

    // Get team details for each platform leaderboard participant
    $platform_leaderboard_with_teams = [];
    foreach ($platform_leaderboard as $entry) {
        $stmt = $pdo->prepare("
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
        $platform_leaderboard_with_teams[] = $entry;
    }
    $platform_leaderboard = $platform_leaderboard_with_teams;

    // =====================================================================
    // TRACKING GRAPH - LEAGUE SPECIFIC
    // Shows win progression over time for current league participants
    // Only counts games from 2025-10-21 onwards
    // =====================================================================
    
    $trackingData = [];
    $trackingDates = [];
    $trackingParticipants = [];
    
    if (!empty($league_id)) {
        // Fetch participant daily wins history for current league only
        $stmt = $pdo->prepare("
            SELECT 
                date,
                league_participant_id,
                total_wins
            FROM league_participant_daily_wins
            WHERE league_participant_id IN (
                SELECT id FROM league_participants 
                WHERE league_id = ? AND status = 'active'
            )
            ORDER BY date ASC
        ");
        $stmt->execute([$league_id]);
        $dailyWinsData = $stmt->fetchAll();
        
        // Get participant information (display_name from users table)
        $stmt = $pdo->prepare("
            SELECT lp.id as participant_id, u.display_name
            FROM league_participants lp
            JOIN users u ON lp.user_id = u.id
            WHERE lp.league_id = ? AND lp.status = 'active'
            ORDER BY u.display_name
        ");
        $stmt->execute([$league_id]);
        $participantInfo = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Process daily wins into chart format
        // FILTER: Only include dates from 2025-10-21 onwards
        foreach ($dailyWinsData as $record) {
            // Skip dates before October 21, 2025
            if ($record['date'] < '2025-10-21') {
                continue;
            }
            
            $participant_id = $record['league_participant_id'];
            
            if (!isset($participantInfo[$participant_id])) {
                continue; // Skip if participant not found
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
                // Find participant_id for this participant name
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
    }

    // =====================================================================
    // HEAD-TO-HEAD COMPARISON - LEAGUE SPECIFIC
    // Calculate matchup records between all participants in current league
    // Only counts games from 2025-10-21 onwards
    // =====================================================================
    
    $h2hRecords = [];
    $intraTeamRecords = [];
    $leagueParticipantsForH2H = [];
    
    if (!empty($league_id)) {
        // Get all participants in the current league
        $stmt = $pdo->prepare("
            SELECT lp.id, u.display_name, u.id as user_id
            FROM league_participants lp
            JOIN users u ON lp.user_id = u.id
            WHERE lp.league_id = ? AND lp.status = 'active'
            ORDER BY u.display_name
        ");
        $stmt->execute([$league_id]);
        $leagueParticipantsForH2H = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate head-to-head records for all participant pairs
        foreach ($leagueParticipantsForH2H as $p1) {
            foreach ($leagueParticipantsForH2H as $p2) {
                if ($p1['id'] === $p2['id']) {
                    // Calculate INTRA-TEAM record (games between own teams)
                    // Use DISTINCT g.id to count each game only once
                    $stmt = $pdo->prepare("
                        SELECT 
                            COUNT(DISTINCT g.id) as total_games
                        FROM league_participant_teams team1
                        JOIN league_participant_teams team2 ON team1.league_participant_id = team2.league_participant_id
                            AND team1.id < team2.id
                        JOIN games g ON (
                            (g.home_team IN (
                                REPLACE(team1.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                                REPLACE(REPLACE(team1.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                            ) AND g.away_team IN (
                                REPLACE(team2.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                                REPLACE(REPLACE(team2.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                            )) OR
                            (g.away_team IN (
                                REPLACE(team1.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                                REPLACE(REPLACE(team1.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                            ) AND g.home_team IN (
                                REPLACE(team2.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                                REPLACE(REPLACE(team2.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                            ))
                        )
                        AND g.status_long IN ('Final', 'Finished')
                        AND DATE(g.start_time) >= '2025-10-21'
                        WHERE team1.league_participant_id = ?
                    ");
                    $stmt->execute([$p1['id']]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $intraTeamRecords[$p1['id']] = [
                        'total_games' => $result['total_games'] ?? 0
                    ];
                    
                    continue; // Skip regular H2H calculation for self
                }
                
                // Query to get wins/losses between two different participants
                $stmt = $pdo->prepare("
                    SELECT 
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
                        END) as wins,
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
                        END) as losses
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
                    WHERE my_participant.id = ? AND opponent_participant.id = ?
                ");
                $stmt->execute([$p1['id'], $p2['id']]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $h2hRecords[$p1['id']][$p2['id']] = [
                    'wins' => $result['wins'] ?? 0,
                    'losses' => $result['losses'] ?? 0
                ];
            }
        }
    }

    // =====================================================================
    // WEEKLY WIN TRACKER - LEAGUE SPECIFIC
    // Shows weekly win rankings by participant
    // Week defined as Monday-Sunday (games starting in that week)
    // Only counts games from 2025-10-21 onwards
    // =====================================================================
    
    $weeklyRankingsData = [];
    
    if (!empty($league_id)) {
        // Get weekly rankings - weeks start on Monday
        $stmt = $pdo->prepare("
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
        $weeklyRankingsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch(PDOException $e) {
    die("Could not connect to the database $db_name :" . $e->getMessage());
}

// Simple team logo mapping (reusing from NBA standings)
function getTeamLogo($teamName) {
    $logoMap = [
        'Atlanta Hawks' => 'atlanta_hawks.png',
        'Boston Celtics' => 'boston_celtics.png',
        'Brooklyn Nets' => 'brooklyn_nets.png',
        'Charlotte Hornets' => 'charlotte_hornets.png',
        'Chicago Bulls' => 'chicago_bulls.png',
        'Cleveland Cavaliers' => 'cleveland_cavaliers.png',
        'Detroit Pistons' => 'detroit_pistons.png',
        'Indiana Pacers' => 'indiana_pacers.png',
        'Miami Heat' => 'miami_heat.png',
        'Milwaukee Bucks' => 'milwaukee_bucks.png',
        'New York Knicks' => 'new_york_knicks.png',
        'Orlando Magic' => 'orlando_magic.png',
        'Philadelphia 76ers' => 'philadelphia_76ers.png',
        'Toronto Raptors' => 'toronto_raptors.png',
        'Washington Wizards' => 'washington_wizards.png',
        'Dallas Mavericks' => 'dallas_mavericks.png',
        'Denver Nuggets' => 'denver_nuggets.png',
        'Golden State Warriors' => 'golden_state_warriors.png',
        'Houston Rockets' => 'houston_rockets.png',
        'LA Clippers' => 'la_clippers.png',
        'Los Angeles Clippers' => 'la_clippers.png',
        'Los Angeles Lakers' => 'los_angeles_lakers.png',
        'Memphis Grizzlies' => 'memphis_grizzlies.png',
        'Minnesota Timberwolves' => 'minnesota_timberwolves.png',
        'New Orleans Pelicans' => 'new_orleans_pelicans.png',
        'Oklahoma City Thunder' => 'oklahoma_city_thunder.png',
        'Phoenix Suns' => 'phoenix_suns.png',
        'Portland Trail Blazers' => 'portland_trail_blazers.png',
        'Sacramento Kings' => 'sacramento_kings.png',
        'San Antonio Spurs' => 'san_antonio_spurs.png',
        'Utah Jazz' => 'utah_jazz.png'
    ];
    
    if (isset($logoMap[$teamName])) {
        return 'nba-wins-platform/public/assets/team_logos/' . $logoMap[$teamName];
    }
    
    $filename = strtolower(str_replace(' ', '_', $teamName)) . '.png';
    return 'nba-wins-platform/public/assets/team_logos/' . $filename;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f5f5f5">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title>Analytics - NBA Wins Platform</title>
    <link rel="apple-touch-icon" type="image/png" href="nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <!-- React and Babel for Navigation Component -->
    <script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
<style>
    :root {
        --primary-color: #212121;
        --secondary-color: #424242;
        --background-color: rgba(245, 245, 245, 0.8);
        --text-color: #333333;
        --border-color: #e0e0e0;
        --success-color: #28a745;
        --info-color: #17a2b8;
        --warning-color: #ffc107;
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        line-height: 1.6;
        margin: 0;
        padding: 20px;
        background-image: url('nba-wins-platform/public/assets/background/geometric_white.png');
        background-repeat: repeat;
        background-attachment: fixed;
        color: var(--text-color);
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }
    
    .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
        background-color: var(--background-color);
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    header {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        margin-bottom: 30px;
    }
    
    .basketball-logo {
        max-width: 60px;
        margin-bottom: 10px;
    }
    
    h1 {
        margin: 10px 0;
        font-size: 28px;
        color: var(--primary-color);
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        background: white;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        text-align: center;
    }

    .stat-number {
        font-size: 2.5em;
        font-weight: bold;
        color: var(--primary-color);
        margin-bottom: 5px;
    }

    .stat-label {
        color: var(--secondary-color);
        font-size: 0.9em;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .section {
        background: white;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        margin-bottom: 20px;
    }

    .section h2 {
        color: var(--primary-color);
        margin-top: 0;
        margin-bottom: 20px;
        border-bottom: 2px solid var(--border-color);
        padding-bottom: 10px;
    }

    .team-list {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
    }

    .team-item {
        display: flex;
        align-items: center;
        padding: 10px;
        background: rgba(245, 245, 245, 0.5);
        border-radius: 5px;
        border-left: 4px solid var(--success-color);
    }

    .team-logo {
        width: 30px;
        height: 30px;
        margin-right: 10px;
    }

    .team-info {
        flex: 1;
    }

    .team-name {
        font-weight: bold;
        margin-bottom: 2px;
    }

    .team-record {
        font-size: 0.9em;
        color: var(--secondary-color);
    }

    .win-percentage {
        font-weight: bold;
        color: var(--success-color);
    }

    /* Over/Under Section Styles */
    .over-under-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 10px;
    }

    .over-under-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px;
        background: rgba(245, 245, 245, 0.5);
        border-radius: 5px;
        border-left: 4px solid var(--info-color);
    }

    .over-under-team {
        display: flex;
        align-items: center;
        flex: 1;
    }

    .over-under-team .team-logo {
        width: 28px;
        height: 28px;
        margin-right: 10px;
    }

    .over-under-info {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .over-under-line {
        font-weight: bold;
        font-size: 1.1em;
        color: var(--primary-color);
        text-align: center;
    }

    .activity-list {
        list-style: none;
        padding: 0;
    }

    .activity-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px;
        border-bottom: 1px solid var(--border-color);
    }

    .activity-item:last-child {
        border-bottom: none;
    }

    .activity-info {
        flex: 1;
    }

    .activity-name {
        font-weight: bold;
        margin-bottom: 2px;
    }

    .activity-meta {
        font-size: 0.9em;
        color: var(--secondary-color);
    }

    .activity-count {
        background: var(--info-color);
        color: white;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 0.8em;
        font-weight: bold;
    }

    .status-badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 0.8em;
        font-weight: bold;
        text-transform: uppercase;
    }

    .status-completed {
        background: var(--success-color);
        color: white;
    }

    .status-active {
        background: var(--info-color);
        color: white;
    }

    .status-not-started {
        background: var(--warning-color);
        color: black;
    }

    /* Navigation Menu Styles */
    .menu-container {
        position: fixed;
        top: 0;
        left: 0;
        z-index: 1000;
    }
    
    .menu-button {
        position: fixed;
        top: 1rem;
        left: 1rem;
        background-color: var(--primary-color);
        color: white;
        border: none;
        border-radius: 4px;
        padding: 0.5rem;
        cursor: pointer;
        z-index: 1002;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .menu-button:hover {
        background-color: var(--secondary-color);
    }
    
    .menu-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 1001;
    }
    
    .menu-panel {
        position: fixed;
        top: 0;
        left: -300px;
        width: 300px;
        height: 100vh;
        background-color: white;
        box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        transition: left 0.3s ease;
        z-index: 1002;
    }
    
    .menu-panel.menu-open {
        left: 0;
    }
    
    .menu-header {
        padding: 1rem;
        display: flex;
        justify-content: flex-end;
        border-bottom: 1px solid var(--border-color);
    }
    
    .menu-content {
        padding-top: 4rem;
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    .menu-list {
        list-style: none;
        padding: 0;
        margin: 0;
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    
    .menu-link {
        display: block;
        padding: 0.5rem 1rem;
        color: #374151;
        text-decoration: none;
        transition: background-color 0.2s;
        border-radius: 0.375rem;
    }
    
    .menu-link:hover {
        background-color: var(--background-color);
        color: var(--secondary-color);
    }
    
    .menu-link i {
        width: 20px;
    }

    /* Hide mobile helpers */
    .hide-mobile {
        display: table-cell;
    }
    
    .mobile-owner {
        display: none;
    }

    /* Mobile Optimizations */
    @media (max-width: 768px) {
        body {
            padding: 10px;
        }

        .container {
            padding: 15px;
            margin: 0;
            border-radius: 0;
        }

        .stats-grid {
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }

        .stat-number {
            font-size: 2em;
        }

        .team-list {
            grid-template-columns: 1fr;
        }

        .over-under-grid {
            grid-template-columns: 1fr;
        }

        .over-under-item {
            align-items: center;
            gap: 8px;
        }

        .over-under-info {
            justify-content: flex-end;
            width: auto;
        }

        .activity-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 5px;
        }
        
        /* Inner Tables - Mobile */
        .leaderboard-table .inner-table th,
        .leaderboard-table .inner-table td {
            padding: 4px;
            font-size: 10px;
        }
        
        /* Draft Steals Mobile Optimizations */
        .hide-mobile {
            display: none !important;
        }
        
        .mobile-owner {
            display: inline;
        }
        
        .draft-steals-table {
            font-size: 12px;
        }
        
        .draft-steals-table th,
        .draft-steals-table td {
            padding: 8px 4px;
        }
        
        .draft-steals-table .rank-cell {
            width: 40px;
            min-width: 40px;
            padding: 8px 2px;
        }
        
        .draft-steals-table .team-logo {
            width: 20px !important;
            height: 20px !important;
        }
        
        .draft-steals-table .team-name-text {
            font-size: 12px;
        }
        
        .draft-steals-table .total-wins {
            font-size: 13px;
            padding: 8px 4px;
        }
    }

    /* Tracking Graph Styles */
    .chart-container {
        position: relative;
        height: 500px;
        max-width: 1400px;
        margin: 30px auto;
        background: white;
        padding: 25px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .league-specific-badge {
        display: inline-block;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 0.75em;
        font-weight: bold;
        margin-left: 10px;
        vertical-align: middle;
    }

    @media (max-width: 768px) {
        .chart-container {
            height: 350px;
            padding: 15px;
            margin: 20px auto;
        }
    }
    
    @media (min-width: 1200px) {
        .chart-container {
            height: 550px;
        }
    }

    /* Head-to-Head Comparison Styles */
    .h2h-selector {
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        gap: 15px;
        align-items: center;
        margin: 20px 0;
        padding: 20px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .h2h-select-container {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .h2h-select-container label {
        font-weight: bold;
        color: var(--primary-color);
        font-size: 0.9em;
    }

    .h2h-select-container select {
        padding: 10px;
        border: 2px solid var(--border-color);
        border-radius: 6px;
        font-size: 1em;
        background: white;
        cursor: pointer;
        transition: border-color 0.3s;
    }

    .h2h-select-container select:hover {
        border-color: var(--primary-color);
    }

    .h2h-select-container select:focus {
        outline: none;
        border-color: var(--info-color);
    }

    .h2h-vs {
        font-size: 1.5em;
        font-weight: bold;
        color: var(--secondary-color);
        text-align: center;
    }

    .h2h-result {
        margin-top: 20px;
        padding: 20px;
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        border-radius: 8px;
        text-align: center;
        min-height: 80px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
    }

    .h2h-result-text {
        font-size: 1.1em;
        color: var(--text-color);
        line-height: 1.6;
    }

    .h2h-result-highlight {
        font-size: 1.4em;
        font-weight: bold;
        color: var(--primary-color);
        margin: 10px 0;
    }

    .h2h-record-status {
        font-size: 0.9em;
        color: var(--secondary-color);
        font-style: italic;
    }

    .h2h-record-status.winning {
        color: var(--success-color);
        font-weight: bold;
    }

    .h2h-record-status.losing {
        color: #dc3545;
        font-weight: bold;
    }

    @media (max-width: 768px) {
        .h2h-selector {
            grid-template-columns: 1fr;
            gap: 12px;
        }

        .h2h-vs {
            font-size: 1.2em;
        }

        .h2h-result {
            padding: 15px;
            min-height: 60px;
        }

        .h2h-result-text {
            font-size: 1em;
        }

        .h2h-result-highlight {
            font-size: 1.2em;
        }
    }
    
    /* Weekly Win Tracker Styles */
    .weekly-rankings {
        background: white;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .weekly-rankings-header {
        margin-bottom: 20px;
        display: flex;
        justify-content: center;
    }

    .weekly-rankings-select {
        padding: 10px 15px;
        border: 2px solid var(--border-color);
        border-radius: 6px;
        font-size: 1em;
        background: white;
        cursor: pointer;
        min-width: 200px;
        transition: border-color 0.3s;
    }

    .weekly-rankings-select:hover {
        border-color: var(--primary-color);
    }

    .weekly-rankings-select:focus {
        outline: none;
        border-color: var(--info-color);
    }

    .weekly-rankings-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .weekly-rankings-item {
        display: grid;
        grid-template-columns: 50px 1fr auto;
        align-items: center;
        padding: 15px;
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        border-radius: 6px;
        transition: transform 0.2s;
    }

    .weekly-rankings-item:hover {
        transform: translateX(5px);
    }

    .weekly-rankings-item:nth-child(1) {
        background: linear-gradient(135deg, #ffd700 0%, #ffed4e 100%);
        font-weight: bold;
    }

    .weekly-rankings-item:nth-child(2) {
        background: linear-gradient(135deg, #c0c0c0 0%, #e8e8e8 100%);
        font-weight: bold;
    }

    .weekly-rankings-item:nth-child(3) {
        background: linear-gradient(135deg, #cd7f32 0%, #d4a76a 100%);
        font-weight: bold;
    }

    .weekly-rankings-rank {
        font-size: 1.5em;
        font-weight: bold;
        color: var(--primary-color);
        text-align: center;
    }

    .weekly-rankings-name {
        font-size: 1.1em;
        color: var(--text-color);
    }

    .weekly-rankings-wins {
        font-size: 1.3em;
        font-weight: bold;
        color: var(--primary-color);
        text-align: right;
        padding-right: 10px;
    }

    @media (max-width: 768px) {
        .weekly-rankings-select {
            width: 100%;
            min-width: unset;
        }

        .weekly-rankings-item {
            grid-template-columns: 40px 1fr auto;
            padding: 12px;
        }

        .weekly-rankings-rank {
            font-size: 1.2em;
        }

        .weekly-rankings-name {
            font-size: 1em;
        }

        .weekly-rankings-wins {
            font-size: 1.1em;
        }
    }

    /* Platform-Wide Leaderboard Styles */
    .leaderboard-table {
        width: 100%;
        border-collapse: collapse;
        background-color: white;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .leaderboard-table thead {
        background-color: var(--primary-color);
        color: white;
    }

    .leaderboard-table th,
    .leaderboard-table td {
        padding: 12px 16px;
        text-align: left;
        border-bottom: 1px solid var(--border-color);
    }

    .leaderboard-table thead th {
        font-weight: 600;
        font-size: 14px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .leaderboard-table tbody tr.expandable-row {
        transition: background-color 0.2s ease;
        cursor: pointer;
    }

    .leaderboard-table tbody tr.expandable-row:hover {
        background-color: rgba(0, 0, 0, 0.02);
    }

    .leaderboard-table tbody tr:last-child td {
        border-bottom: none;
    }

    .leaderboard-table .rank-cell {
        font-weight: bold;
        color: var(--primary-color);
        width: 100px;
        text-align: center;
    }

    .leaderboard-table .rank-container {
        display: flex;
        align-items: center;
        font-size: 16px;
    }

    .leaderboard-table .expand-indicator {
        margin-left: 5px;
        margin-right: 5px;
        color: #666;
        transition: transform 0.3s ease;
        font-size: 12px;
    }

    .leaderboard-table tr.expanded .expand-indicator {
        transform: rotate(180deg);
    }

    .leaderboard-table .participant-name {
        font-weight: 500;
        color: var(--text-color);
    }

    /* Hide league suffix on desktop - only show on mobile */
    .leaderboard-table .participant-name .league-suffix {
        display: none;
    }

    .leaderboard-table .league-name {
        color: #666;
        font-size: 14px;
    }

    .leaderboard-table .total-wins {
        text-align: center;
        font-size: 18px;
        color: var(--primary-color);
        width: 120px;
    }

    .leaderboard-table .team-list {
        display: none;
        background-color: #f9fafb;
    }

    .leaderboard-table .team-list td {
        padding: 0;
    }

    .leaderboard-table .expanded-content {
        padding: 12px 16px;
    }

    .leaderboard-table .inner-table {
        width: 100%;
        border-collapse: collapse;
        background-color: white;
        border-radius: 4px;
        overflow: hidden;
        margin: 0;
    }

    .leaderboard-table .inner-table thead {
        background-color: #f3f4f6;
    }

    .leaderboard-table .inner-table th {
        color: var(--text-color);
        font-weight: 600;
        font-size: 13px;
        padding: 10px 12px;
        text-align: left;
    }

    .leaderboard-table .inner-table td {
        padding: 10px 12px;
        border-bottom: 1px solid #e5e7eb;
    }

    .leaderboard-table .inner-table tbody tr:last-child td {
        border-bottom: none;
    }

    .leaderboard-table .team-name {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .leaderboard-table .team-name a {
        display: flex;
        align-items: center;
        gap: 8px;
        color: inherit;
        text-decoration: none;
    }

    .leaderboard-table .team-name a:hover {
        color: var(--secondary-color);
    }

    .leaderboard-table .team-logo {
        width: 24px;
        height: 24px;
        object-fit: contain;
    }

    .leaderboard-table .team-wins {
        text-align: right;
        font-weight: 600;
        color: var(--primary-color);
    }

    /* Table wrapper for consistent spacing */
    .table-responsive {
        width: 100%;
    }

    @media (max-width: 768px) {
        .leaderboard-table {
            width: 100%;
            font-size: 13px;
        }

        .leaderboard-table th,
        .leaderboard-table td {
            padding: 10px 8px;
            font-size: 13px;
        }

        .leaderboard-table thead th {
            font-size: 12px;
            padding: 10px 8px;
        }

        /* Hide league column header on mobile - ONLY for Platform-Wide Leaderboard */
        .leaderboard-table.platform-leaderboard thead th:nth-child(3) {
            display: none;
        }

        /* Hide league data cells on mobile - ONLY for Platform-Wide Leaderboard */
        .leaderboard-table.platform-leaderboard tbody td.league-name {
            display: none;
        }

        .leaderboard-table .rank-container {
            font-size: 14px;
            flex-wrap: nowrap;
        }

        .leaderboard-table .total-wins {
            font-size: 16px;
            width: 70px;
            text-align: center;
        }

        .leaderboard-table .rank-cell {
            width: 80px;
            min-width: 80px;
        }

        .leaderboard-table .participant-name {
            font-size: 13px;
            line-height: 1.4;
        }

        /* Style for league name in parentheses */
        .leaderboard-table .participant-name .league-suffix {
            display: block;
            font-size: 11px;
            color: #666;
            font-weight: normal;
            margin-top: 2px;
        }

        .leaderboard-table .expand-indicator {
            font-size: 11px;
            margin-left: 4px;
            margin-right: 4px;
        }

        .leaderboard-table .fa-trophy {
            font-size: 12px;
            margin-left: 4px !important;
        }

        .leaderboard-table .team-logo {
            width: 20px;
            height: 20px;
        }

        .leaderboard-table .inner-table th,
        .leaderboard-table .inner-table td {
            padding: 8px 10px;
            font-size: 12px;
        }
    }
        .leaderboard-table .inner-table th,
        .leaderboard-table .inner-table td {
        border-bottom: none !important;
    }
    
    /* Games Played Record Alignment */
    .leaderboard-table .games-played-record {
        text-align: center !important;
        vertical-align: middle !important;
        padding: 12px 16px !important;
    }
    
    /* Games Played table header alignment - target 3rd column in Games Played section */
    .section:has(.games-played-record) .leaderboard-table thead th:nth-child(3) {
        text-align: center !important;
    }
    
    /* Sort buttons for Strength of Schedule */
    .sos-sort-btn {
        padding: 10px 20px;
        background-color: #f8f9fa;
        border: 2px solid var(--border-color);
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.95rem;
        font-weight: 500;
        color: var(--text-color);
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .sos-sort-btn:hover {
        background-color: #e9ecef;
        border-color: var(--secondary-color);
        transform: translateY(-1px);
    }
    
    .sos-sort-btn.active {
        background-color: var(--primary-color);
        color: white;
        border-color: var(--primary-color);
    }
    
    .sos-sort-btn.active:hover {
        background-color: var(--secondary-color);
        border-color: var(--secondary-color);
    }
    
    @media (max-width: 768px) {
        /* Platform-wide leaderboard: Hide league column header on mobile (only for 4-column tables) */
        .leaderboard-table:not(:has(.games-played-record)) thead th:nth-child(3):not(:last-child) {
            display: none;
        }
        
        /* Platform-wide leaderboard: Hide league data cells on mobile (only for 4-column tables) */
        .leaderboard-table:not(:has(.games-played-record)) tbody td.league-name {
            display: none;
        }
        
        /* Games Played table: Ensure Record column shows on mobile */
        .leaderboard-table tbody td.games-played-record {
            display: table-cell !important;
            text-align: center !important;
        }
        
        /* Vegas Over/Under tables on mobile - make them fit without scrolling */
        .section h2 {
            font-size: 1.1rem;
        }
        
        /* For Vegas tables with 6 columns, hide Owner column and make it fit */
        .section:has(.leaderboard-table thead th:nth-child(6)) .leaderboard-table thead th:nth-child(3),
        .section:has(.leaderboard-table thead th:nth-child(6)) .leaderboard-table tbody td:nth-child(3) {
            display: none;
        }
        
        .section:has(.leaderboard-table thead th:nth-child(6)) .leaderboard-table th,
        .section:has(.leaderboard-table thead th:nth-child(6)) .leaderboard-table td {
            padding: 10px 6px;
            font-size: 13px;
        }
        
        .section:has(.leaderboard-table thead th:nth-child(6)) .rank-cell {
            width: 45px;
            padding: 10px 4px;
        }
        
        .section:has(.leaderboard-table thead th:nth-child(6)) .total-wins {
            width: auto;
            padding: 10px 8px;
            font-size: 13px;
        }
        
        .section:has(.leaderboard-table thead th:nth-child(6)) .participant-name {
            font-size: 13px;
        }
        
        .section:has(.leaderboard-table thead th:nth-child(6)) .team-logo {
            width: 18px !important;
            height: 18px !important;
        }
        
        /* Strength of Schedule mobile adjustments */
        #sos-table thead th {
            text-align: center !important;
            white-space: nowrap;
        }
        
        #sos-table thead th:nth-child(1) {
            text-align: left !important;
        }
        
        #sos-table tbody td:nth-child(2),
        #sos-table tbody td:nth-child(3) {
            text-align: center !important;
        }
        
        #sos-table th,
        #sos-table td {
            padding: 10px 8px !important;
        }
        
        /* Vegas tables - shrink rank column significantly on mobile */
        .section:has(.leaderboard-table thead th:nth-child(6)) .rank-cell {
            width: 35px;
            min-width: 35px;
            padding: 10px 2px;
            font-size: 14px;
        }
    }
    /* Widget Pin Icon Styles - Subtle version matching participant_profile.php */
    .widget-pin-icon {
        position: absolute;
        top: 12px;
        right: 12px;
        background: transparent;
        color: #999;
        border: none;
        border-radius: 4px;
        width: 28px;
        height: 28px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 14px;
        transition: all 0.2s ease;
        z-index: 500;
        opacity: 0.6;
    }
    
    .widget-pin-icon:hover {
        opacity: 1;
        color: #007bff;
        background: rgba(0, 123, 255, 0.08);
    }
    
    .widget-pin-icon.pinned {
        color: #28a745;
        opacity: 0.8;
    }
    
    .widget-pin-icon.pinned:hover {
        opacity: 1;
        background: rgba(40, 167, 69, 0.08);
    }
</style>
</head>
<body>
    <?php 
    // Include the navigation menu component
    include $_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/components/navigation_menu.php'; 
    ?>
    <div class="container">
        <header>
            <img src="nba-wins-platform/public/assets/team_logos/Logo.png" alt="NBA Logo" class="basketball-logo">
            <h1>Analytics Dashboard</h1>
            <p>Platform Overview & Statistics</p>
        </header>

        <!-- Key Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_leagues']; ?></div>
                <div class="stat-label">Total Leagues</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_participants']; ?></div>
                <div class="stat-label">Active Participants</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_users']; ?></div>
                <div class="stat-label">Registered Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['completed_drafts']; ?></div>
                <div class="stat-label">Completed Drafts</div>
            </div>
        </div>
        
        <!-- Wins Tracking Graph - LEAGUE SPECIFIC -->
        <?php if (!empty($league_id) && !empty($trackingParticipants)): ?>
        <div class="section" style="position: relative;">
            <h2>
                <i class="fas fa-chart-line"></i> 
                <?php echo htmlspecialchars($user_name); ?>'s Wins Progression Tracker
                <span class="league-specific-badge">LEAGUE SPECIFIC</span>
            </h2>
            <p style="text-align: center; color: var(--secondary-color); margin-bottom: 20px; font-style: italic;">
                Tracking wins from October 21, 2025 onwards for your league
            </p>
            <div class="chart-container">
                <canvas id="winsProgressChart"></canvas>
            </div>
        </div>
        <?php elseif (!empty($league_id) && empty($trackingParticipants)): ?>
        <div class="section">
            <h2>
                <i class="fas fa-chart-line"></i> Wins Progression Tracker
                <span class="league-specific-badge">LEAGUE SPECIFIC</span>
            </h2>
            <p style="text-align: center; color: var(--secondary-color); font-style: italic;">
                No tracking data available yet for your league. Data will appear once games begin being recorded.
            </p>
        </div>
        <?php endif; ?>

        <!-- Head-to-Head Comparison - LEAGUE SPECIFIC -->
        <?php if (!empty($league_id) && count($leagueParticipantsForH2H) >= 2): ?>
        <div class="section" style="position: relative;">
            <h2>
                <i class="fas fa-users"></i> 
                Head-to-Head Comparison
                <span class="league-specific-badge">LEAGUE SPECIFIC</span>
            </h2>
            <p style="text-align: center; color: var(--secondary-color); margin-bottom: 20px; font-style: italic;">
                Compare matchup records between participants in your league
            </p>
            
            <div class="h2h-selector">
                <div class="h2h-select-container">
                    <label for="participant1">Select Participant 1:</label>
                    <select id="participant1" name="participant1">
                        <option value="">-- Select --</option>
                        <?php foreach ($leagueParticipantsForH2H as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['display_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="h2h-vs">VS</div>
                
                <div class="h2h-select-container">
                    <label for="participant2">Select Participant 2:</label>
                    <select id="participant2" name="participant2">
                        <option value="">-- Select --</option>
                        <?php foreach ($leagueParticipantsForH2H as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['display_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="h2h-result" id="h2hResult">
                <div class="h2h-result-text">Select two participants to see their head-to-head record</div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Weekly Win Tracker - LEAGUE SPECIFIC -->
        <?php if (!empty($league_id) && !empty($weeklyRankingsData)): ?>
        <div class="section" style="position: relative;">
            <button class="widget-pin-icon <?php echo in_array('weekly_rankings', $pinned_widgets) ? 'pinned' : ''; ?>" 
                    onclick="toggleWidgetPin('weekly_rankings', this)"
                    title="<?php echo in_array('weekly_rankings', $pinned_widgets) ? 'Unpin from homepage' : 'Pin to homepage'; ?>">
                <i class="fas fa-<?php echo in_array('weekly_rankings', $pinned_widgets) ? 'check' : 'thumbtack'; ?>"></i>
            </button>
            <h2>
                <i class="fas fa-trophy"></i> 
                Weekly Win Rankings
                <span class="league-specific-badge">LEAGUE SPECIFIC</span>
            </h2>
            <p style="text-align: center; color: var(--secondary-color); margin-bottom: 20px; font-style: italic;">
                Week-by-week win leaders (Monday-Sunday)
            </p>
            <div id="weekly-tracker-root"></div>
        </div>
        <?php endif; ?>

        <!-- Strength of Schedule - LEAGUE SPECIFIC -->
        <?php if (!empty($league_id) && !empty($strengthOfSchedule)): ?>
        <div class="section" style="position: relative;">
            <button class="widget-pin-icon <?php echo in_array('strength_of_schedule', $pinned_widgets) ? 'pinned' : ''; ?>" 
                    onclick="toggleWidgetPin('strength_of_schedule', this)"
                    title="<?php echo in_array('strength_of_schedule', $pinned_widgets) ? 'Unpin from homepage' : 'Pin to homepage'; ?>">
                <i class="fas fa-<?php echo in_array('strength_of_schedule', $pinned_widgets) ? 'check' : 'thumbtack'; ?>"></i>
            </button>
            <h2>
                <i class="fas fa-calendar-check"></i> 
                Strength of Schedule
                <span class="league-specific-badge">LEAGUE SPECIFIC</span>
            </h2>
            <p style="text-align: center; color: var(--secondary-color); margin-bottom: 20px; font-style: italic;">
                Based on the combined win percentage of opponents faced
            </p>
            
            <!-- Sort Controls -->
            <div style="display: flex; justify-content: center; margin-bottom: 20px; gap: 10px; flex-wrap: wrap;">
                <button onclick="sortSOSTable('opponent_win_pct')" id="sos-sort-pct" class="sos-sort-btn active">
                    <i class="fas fa-percentage"></i> Sort by Opp Win %
                </button>
                <button onclick="sortSOSTable('total_games')" id="sos-sort-games" class="sos-sort-btn">
                    <i class="fas fa-hashtag"></i> Sort by Games Played
                </button>
            </div>
            
            <div class="table-responsive">
            <table class="leaderboard-table" id="sos-table">
                <thead>
                    <tr>
                        <th>Participant</th>
                        <th style="text-align: center;">Games</th>
                        <th style="text-align: center;">Opp Win %</th>
                    </tr>
                </thead>
                <tbody id="sos-table-body">
                    <?php foreach ($strengthOfSchedule as $entry): ?>
                    <tr data-games="<?php echo $entry['total_games']; ?>" data-pct="<?php echo $entry['opponent_win_pct']; ?>" data-name="<?php echo htmlspecialchars($entry['display_name']); ?>">
                        <td class="participant-name">
                            <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?php echo $league_id; ?>&user_id=<?php echo $entry['user_id']; ?>" 
                               style="text-decoration: none; color: inherit;">
                                <?php echo htmlspecialchars($entry['display_name']); ?>
                            </a>
                        </td>
                        <td class="total-wins">
                            <strong><?php echo $entry['total_games']; ?></strong>
                        </td>
                        <td class="games-played-record">
                            <strong style="color: <?php echo $entry['opponent_win_pct'] >= 50 ? '#dc3545' : '#28a745'; ?>;">
                                <?php echo number_format($entry['opponent_win_pct'], 1); ?>%
                            </strong>
                            <div style="font-size: 0.85rem; color: #666; margin-top: 2px;">
                                <?php echo $entry['opponent_win_pct'] >= 50 ? 'Tough' : 'Easy'; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        
        <script>
        function sortSOSTable(sortBy) {
            const tbody = document.getElementById('sos-table-body');
            const rows = Array.from(tbody.getElementsByTagName('tr'));
            
            // Remove active class from all buttons
            document.querySelectorAll('.sos-sort-btn').forEach(btn => btn.classList.remove('active'));
            
            // Add active class to clicked button
            if (sortBy === 'opponent_win_pct') {
                document.getElementById('sos-sort-pct').classList.add('active');
            } else {
                document.getElementById('sos-sort-games').classList.add('active');
            }
            
            // Sort rows
            rows.sort((a, b) => {
                if (sortBy === 'opponent_win_pct') {
                    const aVal = parseFloat(a.dataset.pct);
                    const bVal = parseFloat(b.dataset.pct);
                    if (bVal !== aVal) {
                        return bVal - aVal; // Descending order
                    }
                    return a.dataset.name.localeCompare(b.dataset.name);
                } else {
                    const aVal = parseInt(a.dataset.games);
                    const bVal = parseInt(b.dataset.games);
                    if (bVal !== aVal) {
                        return bVal - aVal; // Descending order
                    }
                    return a.dataset.name.localeCompare(b.dataset.name);
                }
            });
            
            // Reorder rows in the table
            rows.forEach(row => tbody.appendChild(row));
        }
        </script>
        <?php endif; ?>

    <!-- Platform-Wide Leaderboard - ALL LEAGUES -->
    <div class="section" style="position: relative;">
        <button class="widget-pin-icon <?php echo in_array('platform_leaderboard', $pinned_widgets) ? 'pinned' : ''; ?>" 
                onclick="toggleWidgetPin('platform_leaderboard', this)"
                title="<?php echo in_array('platform_leaderboard', $pinned_widgets) ? 'Unpin from homepage' : 'Pin to homepage'; ?>">
            <i class="fas fa-<?php echo in_array('platform_leaderboard', $pinned_widgets) ? 'check' : 'thumbtack'; ?>"></i>
        </button>
        <h2>
            <i class="fas fa-globe"></i> 
            Platform-Wide Top 5 Leaderboard
            <span class="league-specific-badge">PLATFORM WIDE</span>
        </h2>
            <div class="table-responsive">
            <table class="leaderboard-table platform-leaderboard">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Participant</th>
                        <th>League</th>
                        <th>Total Wins</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $rank = 1;
                    $prevWins = null;
                    $nextRank = 1;
                    foreach ($platform_leaderboard as $index => $entry): 
                        // Proper tie handling: if wins are different from previous, use nextRank
                        if ($prevWins !== null && $entry['total_wins'] < $prevWins) {
                            $rank = $nextRank;
                        }
                        $prevWins = $entry['total_wins'];
                        $nextRank = $index + 2; // Next possible rank
                        
                        $rowId = 'platform-row-' . $entry['participant_id'];
                        $teamListId = 'platform-teams-' . $entry['participant_id'];
                    ?>
                    <tr class="expandable-row" onclick="togglePlatformTeams('<?php echo $teamListId; ?>', this)" id="<?php echo $rowId; ?>">
                        <td class="rank-cell">
                            <div class="rank-container">
                                <?php echo $rank; ?>
                                <i class="fas fa-chevron-down expand-indicator"></i>
                                <?php if ($rank === 1 && $entry['total_wins'] > 0): ?>
                                    <i class="fa-solid fa-trophy" style="color: gold; margin-left: 5px;" title="1st Place"></i>
                                <?php elseif ($rank === 2): ?>
                                    <i class="fa-solid fa-trophy" style="color: silver; margin-left: 5px;" title="2nd Place"></i>
                                <?php elseif ($rank === 3): ?>
                                    <i class="fa-solid fa-trophy" style="color: #CD7F32; margin-left: 5px;" title="3rd Place"></i>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="participant-name">
                            <?php echo htmlspecialchars($entry['display_name']); ?>
                            <span class="league-suffix">(<?php echo htmlspecialchars($entry['league_name']); ?>)</span>
                        </td>
                        <td class="league-name">
                            <?php echo htmlspecialchars($entry['league_name']); ?>
                        </td>
                        <td class="total-wins">
                            <strong><?php echo $entry['total_wins']; ?></strong>
                        </td>
                    </tr>
                    <tr class="team-list" id="<?php echo $teamListId; ?>">
                        <td colspan="4" class="expanded-content">
                            <table class="inner-table">
                                <thead>
                                    <tr>
                                        <th>Team</th>
                                        <th>Wins</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($entry['teams'] as $team): ?>
                                    <tr>
                                        <td class="team-name">
                                            <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($team['team_name']); ?>" 
                                               style="text-decoration: none; color: inherit; display: flex; align-items: center;">
                                                <img src="<?php echo htmlspecialchars(getTeamLogo($team['team_name'])); ?>" 
                                                     alt="<?php echo htmlspecialchars($team['team_name']); ?>" 
                                                     class="team-logo"
                                                     onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjEyIiB5PSIxNiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                                                <span><?php echo htmlspecialchars($team['team_name']); ?></span>
                                            </a>
                                        </td>
                                        <td class="team-wins"><?php echo $team['wins']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <script>
        // Toggle function for platform leaderboard teams
        function togglePlatformTeams(teamListId, rowElement) {
            const teamList = document.getElementById(teamListId);
            const isExpanded = rowElement.classList.contains('expanded');
            
            if (isExpanded) {
                teamList.style.display = 'none';
                rowElement.classList.remove('expanded');
            } else {
                teamList.style.display = 'table-row';
                rowElement.classList.add('expanded');
            }
        }
        </script>

        <!-- Best Draft Steals - PLATFORM WIDE -->
        <?php if (!empty($bestDraftSteals)): ?>
        <div class="section" style="position: relative;">
            <button class="widget-pin-icon <?php echo in_array('draft_steals', $pinned_widgets) ? 'pinned' : ''; ?>" 
                    onclick="toggleWidgetPin('draft_steals', this)"
                    title="<?php echo in_array('draft_steals', $pinned_widgets) ? 'Unpin from homepage' : 'Pin to homepage'; ?>">
                <i class="fas fa-<?php echo in_array('draft_steals', $pinned_widgets) ? 'check' : 'thumbtack'; ?>"></i>
            </button>
            <h2>
                <i class="fas fa-gem"></i> Draft Steals
                <span class="league-specific-badge">PLATFORM WIDE</span>
            </h2>
            <p style="text-align: center; color: var(--secondary-color); margin-bottom: 20px; font-style: italic;">
                Top 5 teams outperforming their draft round average across all leagues (adjusted for pick position)
            </p>
            
            <div class="table-responsive">
                <table class="leaderboard-table draft-steals-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Team</th>
                            <th class="hide-mobile">Owner / League</th>
                            <th style="text-align: center;">Rnd</th>
                            <th style="text-align: center;">Wins</th>
                            <th class="hide-mobile" style="text-align: center;">Avg</th>
                            <th style="text-align: center;">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bestDraftSteals as $steal): 
                            $rank = $steal['rank'];
                        ?>
                        <tr>
                            <td class="rank-cell">
                                <div class="rank-container">
                                    <?php echo $rank; ?>
                                    <?php if ($rank === 1): ?>
                                        <i class="fa-solid fa-trophy" style="color: gold; margin-left: 5px;" title="Best Draft Steal"></i>
                                    <?php elseif ($rank === 2): ?>
                                        <i class="fa-solid fa-trophy" style="color: silver; margin-left: 5px;" title="2nd Best Steal"></i>
                                    <?php elseif ($rank === 3): ?>
                                        <i class="fa-solid fa-trophy" style="color: #CD7F32; margin-left: 5px;" title="3rd Best Steal"></i>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="participant-name">
                                <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($steal['team_name']); ?>" 
                                   style="text-decoration: none; color: inherit; display: flex; align-items: center; gap: 8px;">
                                    <img src="<?php echo htmlspecialchars(getTeamLogo($steal['team_name'])); ?>" 
                                         alt="<?php echo htmlspecialchars($steal['team_name']); ?>" 
                                         class="team-logo"
                                         style="width: 24px; height: 24px;"
                                         onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjEyIiB5PSIxNiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                                    <span class="team-name-text"><?php echo htmlspecialchars($steal['team_name']); ?></span>
                                </a>
                                <div style="font-size: 0.75rem; color: #666; margin-top: 2px;">
                                    Pick #<?php echo $steal['pick_number']; ?>
                                    <span class="mobile-owner"> • <?php echo htmlspecialchars($steal['owner_name']); ?></span>
                                </div>
                            </td>
                            <td class="participant-name hide-mobile">
                                <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?php echo $steal['league_id']; ?>&user_id=<?php echo $steal['user_id']; ?>" 
                                   style="text-decoration: none; color: inherit;">
                                    <?php echo htmlspecialchars($steal['owner_name']); ?>
                                </a>
                                <div style="font-size: 0.85rem; color: #999; margin-top: 2px;">
                                    <?php echo htmlspecialchars($steal['league_name']); ?>
                                </div>
                            </td>
                            <td class="total-wins" style="text-align: center;">
                                <strong><?php echo $steal['round_number']; ?></strong>
                            </td>
                            <td class="total-wins" style="text-align: center;">
                                <strong style="color: var(--success-color);"><?php echo $steal['actual_wins']; ?></strong>
                            </td>
                            <td class="total-wins hide-mobile" style="text-align: center;">
                                <strong><?php echo $steal['round_avg_wins']; ?></strong>
                            </td>
                            <td class="total-wins" style="text-align: center;">
                                <strong style="color: <?php echo $steal['grade_color']; ?>; font-size: 1.1em;">
                                    +<?php echo number_format($steal['steal_score'], 2); ?>
                                </strong>
                                <div style="font-size: 0.7rem; color: <?php echo $steal['grade_color']; ?>; margin-top: 2px; font-weight: bold;">
                                    <?php echo $steal['steal_grade']; ?>
                                </div>
                                <div style="font-size: 0.65rem; color: #999; margin-top: 1px;">
                                    (<?php echo $steal['base_steal_score'] > 0 ? '+' : ''; ?><?php echo $steal['base_steal_score']; ?> 
                                    + <?php echo number_format($steal['pick_number'] / 100, 2); ?>)
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                        border-radius: 12px; 
                        padding: 20px; 
                        margin-top: 20px;
                        color: white;">
                <h3 style="margin: 0 0 10px 0; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-info-circle"></i> How Draft Steals Work
                </h3>
                <div style="font-size: 0.95rem; line-height: 1.6;">
                    <p style="margin: 8px 0;">
                        <strong>Round Average</strong> = Average current wins for all teams drafted in that round
                    </p>
                    <p style="margin: 8px 0;">
                        <strong>Base Value</strong> = Team's actual wins - Round average
                    </p>
                    <p style="margin: 8px 0;">
                        <strong>Pick Position Bonus</strong> = Pick number ÷ 100 (later picks get more credit)
                    </p>
                    <p style="margin: 8px 0;">
                        <strong>Final Value Score</strong> = Base Value + Pick Position Bonus
                    </p>
                    <p style="margin: 12px 0 0 0; font-style: italic; opacity: 0.9;">
                        💡 Example: Getting the 76ers at pick #17 is better value than at pick #11 if they perform the same!
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Vegas Over/Under Performance - LEAGUE SPECIFIC -->
        <?php if (!empty($league_id) && (!empty($overperformers) || !empty($underperformers))): ?>
        <div class="section" style="position: relative;">
            <button class="widget-pin-icon <?php echo in_array('exceeding_expectations', $pinned_widgets) ? 'pinned' : ''; ?>" 
                    onclick="toggleWidgetPin('exceeding_expectations', this)"
                    title="<?php echo in_array('exceeding_expectations', $pinned_widgets) ? 'Unpin from homepage' : 'Pin to homepage'; ?>">
                <i class="fas fa-<?php echo in_array('exceeding_expectations', $pinned_widgets) ? 'check' : 'thumbtack'; ?>"></i>
            </button>
            <h2>
                <i class="fa-solid fa-dice"></i> Exceeding Expectations
                <span class="league-specific-badge">LEAGUE SPECIFIC</span>
            </h2>
            <p style="text-align: center; color: var(--secondary-color); margin-bottom: 20px; font-style: italic;">
                Teams currently on pace to exceed their preseason win total projections
            </p>
            
            <?php if (!empty($overperformers)): ?>
            <div class="table-responsive">
                <table class="leaderboard-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Team</th>
                            <th>Owner</th>
                            <th style="text-align: center;">Line</th>
                            <th style="text-align: center;">Pace</th>
                            <th style="text-align: center;">Diff</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($overperformers as $index => $team): ?>
                        <tr>
                            <td class="rank-cell">
                                <?php echo $index + 1; ?>
                            </td>
                            <td class="participant-name">
                                <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($team['team_name']); ?>" 
                                   style="text-decoration: none; color: inherit; display: flex; align-items: center; gap: 8px;">
                                    <img src="<?php echo htmlspecialchars(getTeamLogo($team['team_name'])); ?>" 
                                         alt="<?php echo htmlspecialchars($team['team_name']); ?>" 
                                         class="team-logo"
                                         style="width: 24px; height: 24px;"
                                         onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjEyIiB5PSIxNiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                                    <span><?php echo htmlspecialchars($team['team_name']); ?></span>
                                </a>
                                <div style="font-size: 0.85rem; color: #666; margin-top: 2px;">
                                    <?php echo $team['current_record']; ?>
                                </div>
                            </td>
                            <td class="participant-name">
                                <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?php echo $league_id; ?>&user_id=<?php echo $team['user_id']; ?>" 
                                   style="text-decoration: none; color: inherit;">
                                    <?php echo htmlspecialchars($team['owner']); ?>
                                </a>
                            </td>
                            <td class="total-wins" style="text-align: center;">
                                <strong><?php echo number_format($team['vegas_projection'], 1); ?></strong>
                            </td>
                            <td class="total-wins" style="text-align: center;">
                                <strong style="color: var(--success-color);"><?php echo number_format($team['current_pace'], 1); ?></strong>
                            </td>
                            <td class="total-wins" style="text-align: center;">
                                <strong style="color: var(--success-color);">+<?php echo number_format($team['variance'], 1); ?></strong>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p style="text-align: center; color: #666; font-style: italic;">No teams currently exceeding Vegas expectations in your league</p>
            <?php endif; ?>
        </div>
        
        <div class="section" style="position: relative;">
            <button class="widget-pin-icon <?php echo in_array('falling_short', $pinned_widgets) ? 'pinned' : ''; ?>" 
                    onclick="toggleWidgetPin('falling_short', this)"
                    title="<?php echo in_array('falling_short', $pinned_widgets) ? 'Unpin from homepage' : 'Pin to homepage'; ?>">
                <i class="fas fa-<?php echo in_array('falling_short', $pinned_widgets) ? 'check' : 'thumbtack'; ?>"></i>
            </button>
            <h2>
                <i class="fa-solid fa-dice"></i> Falling Short of Expectations
                <span class="league-specific-badge">LEAGUE SPECIFIC</span>
            </h2>
            <p style="text-align: center; color: var(--secondary-color); margin-bottom: 20px; font-style: italic;">
                Teams currently on pace to fall short of their preseason win total projections
            </p>
            
            <?php if (!empty($underperformers)): ?>
            <div class="table-responsive">
                <table class="leaderboard-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Team</th>
                            <th>Owner</th>
                            <th style="text-align: center;">Line</th>
                            <th style="text-align: center;">Pace</th>
                            <th style="text-align: center;">Diff</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($underperformers as $index => $team): ?>
                        <tr>
                            <td class="rank-cell">
                                <?php echo $index + 1; ?>
                            </td>
                            <td class="participant-name">
                                <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($team['team_name']); ?>" 
                                   style="text-decoration: none; color: inherit; display: flex; align-items: center; gap: 8px;">
                                    <img src="<?php echo htmlspecialchars(getTeamLogo($team['team_name'])); ?>" 
                                         alt="<?php echo htmlspecialchars($team['team_name']); ?>" 
                                         class="team-logo"
                                         style="width: 24px; height: 24px;"
                                         onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjEyIiB5PSIxNiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                                    <span><?php echo htmlspecialchars($team['team_name']); ?></span>
                                </a>
                                <div style="font-size: 0.85rem; color: #666; margin-top: 2px;">
                                    <?php echo $team['current_record']; ?>
                                </div>
                            </td>
                            <td class="participant-name">
                                <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?php echo $league_id; ?>&user_id=<?php echo $team['user_id']; ?>" 
                                   style="text-decoration: none; color: inherit;">
                                    <?php echo htmlspecialchars($team['owner']); ?>
                                </a>
                            </td>
                            <td class="total-wins" style="text-align: center;">
                                <strong><?php echo number_format($team['vegas_projection'], 1); ?></strong>
                            </td>
                            <td class="total-wins" style="text-align: center;">
                                <strong style="color: #dc3545;"><?php echo number_format($team['current_pace'], 1); ?></strong>
                            </td>
                            <td class="total-wins" style="text-align: center;">
                                <strong style="color: #dc3545;"><?php echo number_format($team['variance'], 1); ?></strong>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p style="text-align: center; color: #666; font-style: italic;">No teams currently falling short of Vegas expectations in your league</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
    // =====================================================================
    // WINS TRACKING CHART - LEAGUE SPECIFIC
    // =====================================================================
    <?php if (!empty($league_id) && !empty($trackingParticipants)): ?>
    
    // Prepare chart data from PHP
    const trackingDates = <?php echo json_encode($trackingDates); ?>;
    const trackingParticipants = <?php echo json_encode($trackingParticipants); ?>;
    const trackingChartData = <?php echo json_encode($trackingData); ?>;
    
    // Generate distinct colors for each participant
    const generateColor = (index, total) => {
        const hue = (index * 360 / total) % 360;
        return `hsl(${hue}, 70%, 50%)`;
    };
    
    const colors = trackingParticipants.map((_, index) => 
        generateColor(index, trackingParticipants.length)
    );
    
    // Create datasets for the chart
    const datasets = trackingParticipants.map((participant, index) => ({
        label: participant,
        data: trackingChartData[participant],
        borderColor: colors[index],
        backgroundColor: colors[index] + '20', // Add transparency
        fill: false,
        tension: 0,
        borderWidth: 2,
        pointRadius: 3,
        pointHoverRadius: 5
    }));

    // Function to get chart options based on screen size
    function getChartOptions() {
        const isMobile = window.innerWidth < 768;
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                title: {
                    display: false
                },
                legend: {
                    position: 'top',
                    labels: {
                        boxWidth: isMobile ? 10 : 40,
                        padding: isMobile ? 8 : 10,
                        font: {
                            size: isMobile ? 11 : 12
                        }
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    itemSort: function(a, b) {
                        // Sort tooltip items by value (wins) descending
                        return b.parsed.y - a.parsed.y;
                    },
                    callbacks: {
                        title: function(context) {
                            const date = new Date(context[0].label);
                            return date.toLocaleDateString('en-US', { 
                                month: 'short', 
                                day: 'numeric',
                                year: 'numeric'
                            });
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: !isMobile,
                        text: 'Total Wins',
                        font: {
                            size: 14,
                            weight: 'bold'
                        }
                    },
                    ticks: {
                        font: {
                            size: isMobile ? 10 : 12
                        },
                        stepSize: 1
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                },
                x: {
                    grid: {
                        drawOnChartArea: true,
                        color: 'rgba(0, 0, 0, 0.05)'
                    },
                    title: {
                        display: false
                    },
                    ticks: {
                        maxRotation: 45,
                        minRotation: 45,
                        font: {
                            size: isMobile ? 8 : 10
                        },
                        padding: 8,
                        callback: function(value) {
                            const date = new Date(this.getLabelForValue(value));
                            return (date.getMonth() + 1) + '/' + date.getDate();
                        }
                    }
                }
            },
            interaction: {
                mode: 'nearest',
                axis: 'x',
                intersect: false
            },
            layout: {
                padding: {
                    bottom: 40
                }
            }
        };
    }
    
    // Create the chart
    const ctx = document.getElementById('winsProgressChart');
    if (ctx) {
        const winsChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: trackingDates,
                datasets: datasets
            },
            options: getChartOptions()
        });

        // Handle window resizing
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                winsChart.options = getChartOptions();
                winsChart.update();
            }, 250);
        });
    }
    
    <?php endif; ?>
    </script>

    <script>
    // =====================================================================
    // HEAD-TO-HEAD COMPARISON LOGIC - LEAGUE SPECIFIC
    // =====================================================================
    <?php if (!empty($league_id) && count($leagueParticipantsForH2H) >= 2): ?>
    
    // Head-to-head records data
    const h2hData = <?php echo json_encode($h2hRecords); ?>;
    const intraTeamData = <?php echo json_encode($intraTeamRecords); ?>;
    const participantsData = <?php echo json_encode($leagueParticipantsForH2H); ?>;
    
    // Create a map of participant IDs to names
    const participantNames = {};
    participantsData.forEach(p => {
        participantNames[p.id] = p.display_name;
    });
    
    function updateH2HResult() {
        const p1Id = document.getElementById('participant1').value;
        const p2Id = document.getElementById('participant2').value;
        const resultDiv = document.getElementById('h2hResult');
        
        if (!p1Id || !p2Id) {
            resultDiv.innerHTML = '<div class="h2h-result-text">Select two participants to see their head-to-head record</div>';
            return;
        }
        
        const p1Name = participantNames[p1Id];
        const p2Name = participantNames[p2Id];
        
        // Check if this is a self-comparison (intra-team record)
        if (p1Id === p2Id) {
            const intraRecord = intraTeamData[p1Id];
            const totalGames = intraRecord ? intraRecord.total_games : 0;
            
            if (totalGames === 0) {
                resultDiv.innerHTML = `
                    <div class="h2h-result-text">
                        <strong>${p1Name}</strong> has not had any games between their own teams yet this season
                    </div>
                `;
                return;
            }
            
            resultDiv.innerHTML = `
                <div class="h2h-result-text">
                    <strong>${p1Name}</strong>'s teams vs each other
                </div>
                <div class="h2h-result-highlight">
                    ${totalGames} intra-team ${totalGames === 1 ? 'game' : 'games'}
                </div>
                <div class="h2h-record-status">
                    Games between their own teams (each game counts as both a win and loss)
                </div>
            `;
            return;
        }
        
        // Get the record between two different participants
        const record = h2hData[p1Id] && h2hData[p1Id][p2Id] ? h2hData[p1Id][p2Id] : { wins: 0, losses: 0 };
        const wins = record.wins || 0;
        const losses = record.losses || 0;
        const totalGames = wins + losses;
        
        if (totalGames === 0) {
            resultDiv.innerHTML = `
                <div class="h2h-result-text">
                    <strong>${p1Name}</strong> and <strong>${p2Name}</strong> have not faced each other yet this season
                </div>
            `;
            return;
        }
        
        let statusClass = '';
        let statusText = '';
        
        if (wins > losses) {
            statusClass = 'winning';
            statusText = 'Winning record';
        } else if (wins < losses) {
            statusClass = 'losing';
            statusText = 'Losing record';
        } else {
            statusClass = '';
            statusText = 'Tied';
        }
        
        resultDiv.innerHTML = `
            <div class="h2h-result-text">
                <strong>${p1Name}</strong> vs <strong>${p2Name}</strong>
            </div>
            <div class="h2h-result-highlight">
                ${wins}-${losses}
            </div>
            <div class="h2h-record-status ${statusClass}">
                ${statusText}
            </div>
        `;
    }
    
    // Add event listeners
    document.getElementById('participant1').addEventListener('change', updateH2HResult);
    document.getElementById('participant2').addEventListener('change', updateH2HResult);
    
    <?php endif; ?>
    </script>

    <script type="text/babel">
    // =====================================================================
    // WEEKLY WIN TRACKER - LEAGUE SPECIFIC
    // =====================================================================
    <?php if (!empty($league_id) && !empty($weeklyRankingsData)): ?>
    
    const WeeklyWinTracker = () => {
        const data = <?php echo json_encode($weeklyRankingsData); ?>;
        
        // Process weeks from data
        const weeklyData = React.useMemo(() => {
            const weeks = {};
            
            data.forEach(record => {
                const weekNum = record.week_num;
                if (!weeks[weekNum]) {
                    weeks[weekNum] = {
                        weekNum,
                        label: record.week_label,
                        participants: []
                    };
                }
                weeks[weekNum].participants.push({
                    name: record.display_name,
                    wins: parseInt(record.weekly_wins)
                });
            });
    
            return Object.values(weeks)
                .map(week => {
                    // Sort by wins descending
                    const sortedParticipants = week.participants.sort((a, b) => b.wins - a.wins);
                    
                    // Assign ranks with proper tie handling
                    let rank = 1;
                    let prevWins = null;
                    let nextRank = 1;
                    
                    const rankedParticipants = sortedParticipants.map((p, index) => {
                        if (prevWins !== null && p.wins < prevWins) {
                            rank = nextRank;
                        }
                        prevWins = p.wins;
                        nextRank = index + 2;
                        
                        return { ...p, rank };
                    });
                    
                    return {
                        ...week,
                        participants: rankedParticipants
                    };
                })
                .sort((a, b) => b.weekNum - a.weekNum);
        }, [data]);
        
        const [selectedWeek, setSelectedWeek] = React.useState(
            weeklyData.length > 0 ? weeklyData[0].weekNum : null
        );
    
        const selectedWeekData = weeklyData.find(week => week.weekNum === selectedWeek) || weeklyData[0];
    
        return (
            <div className="weekly-rankings">
                <div className="weekly-rankings-header">
                    <select 
                        value={selectedWeek}
                        onChange={(e) => setSelectedWeek(parseInt(e.target.value))}
                        className="weekly-rankings-select"
                    >
                        {weeklyData.map(week => (
                            <option key={week.weekNum} value={week.weekNum}>
                                {week.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="weekly-rankings-list">
                    {selectedWeekData?.participants.map((participant) => (
                        <div 
                            key={participant.name}
                            className="weekly-rankings-item"
                        >
                            <div className="weekly-rankings-rank">
                                {participant.rank}
                            </div>
                            <div className="weekly-rankings-name">
                                {participant.name}
                            </div>
                            <div className="weekly-rankings-wins">
                                {participant.wins}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        );
    };
    
    // Render the weekly tracker
    const weeklyContainer = document.getElementById('weekly-tracker-root');
    if (weeklyContainer) {
        const weeklyRoot = ReactDOM.createRoot(weeklyContainer);
        weeklyRoot.render(<WeeklyWinTracker />);
    }
    
    <?php endif; ?>
    </script>


    <script>
    // Widget pin/unpin functionality
    function toggleWidgetPin(widgetType, button) {
        const isPinned = button.classList.contains('pinned');
        const action = isPinned ? 'unpin' : 'pin';
        
        // Show confirmation for pinning
        if (!isPinned) {
            if (!confirm('Pin this section to your homepage?')) {
                return;
            }
        }
        
        // Make AJAX request to pin/unpin
        const formData = new FormData();
        formData.append('action', action);
        formData.append('widget_type', widgetType);
        
        fetch('/nba-wins-platform/core/handle_widget_pin.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                window.location.reload();
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }
    </script>
</body>
</html>