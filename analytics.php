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
        // Using /4 for very strong late-round reward (max swing: ~7.5 points)
        // This ensures later picks of same team always rank higher
        $pick_position_bonus = $team['pick_number'] / 4;
        
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
    // WORST DRAFT PICKS - PLATFORM WIDE
    // Shows individual teams underperforming their draft round average
    // Compares each team's actual wins vs average wins for their draft round
    // REVERSE LOGIC of Best Draft Steals: Earlier picks get penalized more
    // =====================================================================
    $worstDraftPicks = [];
    
    // Reuse $roundAverages and $allDraftedTeams from Best Draft Steals above
    
    // Calculate bust score for each team (opposite of steal score)
    foreach ($allDraftedTeams as $team) {
        $round = $team['round_number'];
        $round_avg = isset($roundAverages[$round]) ? $roundAverages[$round] : 0;
        
        // Base Bust Score = Round Average Wins - Actual Wins (opposite of steal score)
        $base_bust_score = $round_avg - $team['actual_wins'];
        
        // Pick Position Penalty: EARLIER picks in a round get HIGHER penalty
        // Using /10 for moderate penalty - early busts hurt more
        // This ensures early picks of bad teams always rank higher as busts
        $pick_position_penalty = (31 - $team['pick_number']) / 10;
        
        // Final Bust Score = Base Score + Pick Position Penalty
        $bust_score = $base_bust_score + $pick_position_penalty;
        
        $team['round_avg_wins'] = round($round_avg, 1);
        $team['bust_score'] = round($bust_score, 2);
        $team['base_bust_score'] = round($base_bust_score, 1);
        
        // Assign grade based on BASE bust score (not adjusted)
        if ($base_bust_score >= 3.0) {
            $team['bust_grade'] = 'MASSIVE BUST';
            $team['grade_color'] = '#ef4444';
        } elseif ($base_bust_score >= 2.0) {
            $team['bust_grade'] = 'BUST';
            $team['grade_color'] = '#f87171';
        } elseif ($base_bust_score >= 1.0) {
            $team['bust_grade'] = 'Underperforming';
            $team['grade_color'] = '#fbbf24';
        } elseif ($base_bust_score >= 0) {
            $team['bust_grade'] = 'Below Avg';
            $team['grade_color'] = '#9ca3af';
        } else {
            $team['bust_grade'] = 'Fair';
            $team['grade_color'] = '#6b7280';
        }
        
        $worstDraftPicks[] = $team;
    }
    
    // Sort by adjusted bust score (highest first), then by earlier pick number as tiebreaker
    usort($worstDraftPicks, function($a, $b) {
        if (abs($a['bust_score'] - $b['bust_score']) > 0.001) {
            return $b['bust_score'] <=> $a['bust_score'];
        }
        return $a['pick_number'] <=> $b['pick_number'];
    });
    
    // Take top 5 worst
    $topBusts = array_slice($worstDraftPicks, 0, 5);
    
    // Assign rankings with ties - teams with identical bust scores get the same rank
    $worstDraftPicks = [];
    $current_rank = 1;
    $prev_bust_score = null;
    $items_at_current_rank = 0;
    
    foreach ($topBusts as $team) {
        if ($prev_bust_score !== null && abs($team['bust_score'] - $prev_bust_score) < 0.005) {
            $team['rank'] = $current_rank;
        } else {
            if ($items_at_current_rank > 0) {
                $current_rank += $items_at_current_rank;
            }
            $team['rank'] = $current_rank;
            $items_at_current_rank = 0;
        }
        
        $prev_bust_score = $team['bust_score'];
        $items_at_current_rank++;
        $worstDraftPicks[] = $team;
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
    // Supports flexible time windows: 7, 21, 30 days, or full season
    // =====================================================================
    
    $trackingData = [];
    $trackingDates = [];
    $trackingParticipants = [];
    
    // Get time window parameter (defaults to 7 days)
    $timeWindow = isset($_GET['time_window']) ? intval($_GET['time_window']) : 7;
    
    // Calculate start date based on time window
    // 0 = full season (from 2025-10-21)
    if ($timeWindow === 0) {
        $startDate = '2025-10-21';
    } else {
        $startDate = date('Y-m-d', strtotime("-{$timeWindow} days"));
        // Don't go earlier than season start
        if ($startDate < '2025-10-21') {
            $startDate = '2025-10-21';
        }
    }
    
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
        // FILTER: Only include dates from calculated start date onwards
        foreach ($dailyWinsData as $record) {
            // Skip dates before the calculated start date
            if ($record['date'] < $startDate) {
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
        --tab-active: #667eea;
        --tab-hover: #764ba2;
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

    /* Tab Navigation Styles */
    .tab-navigation {
        display: flex;
        justify-content: center;
        gap: 10px;
        margin: 30px 0;
        border-bottom: 2px solid var(--border-color);
        padding-bottom: 10px;
    }

    .tab-button {
        padding: 12px 30px;
        background: white;
        border: 2px solid var(--border-color);
        border-bottom: none;
        border-radius: 8px 8px 0 0;
        cursor: pointer;
        font-size: 1rem;
        font-weight: 600;
        color: var(--secondary-color);
        transition: all 0.3s ease;
        position: relative;
        bottom: -2px;
    }

    .tab-button:hover {
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        border-color: var(--tab-hover);
    }

    .tab-button.active {
        background: linear-gradient(135deg, var(--tab-active) 0%, var(--tab-hover) 100%);
        color: white;
        border-color: var(--tab-active);
    }

    .tab-button i {
        margin-right: 8px;
    }

    .tab-content {
        display: none;
    }

    .tab-content.active {
        display: block;
        animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Section Styles */
    .section {
        background: white;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        margin-bottom: 20px;
        position: relative;
    }

    .section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--border-color);
    }

    .section-title {
        display: flex;
        align-items: center;
        gap: 10px;
        flex: 1;
    }

    .section-title h2 {
        margin: 0;
        color: var(--primary-color);
        font-size: 1.4rem;
    }

    .info-icon {
        cursor: help;
        color: var(--info-color);
        font-size: 1rem;
        transition: color 0.2s;
        position: relative;
    }

    .info-icon:hover {
        color: var(--tab-active);
    }

    .info-tooltip {
        position: absolute;
        bottom: 100%;
        left: 50%;
        transform: translateX(-50%);
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 12px 16px;
        border-radius: 8px;
        font-size: 0.85rem;
        line-height: 1.4;
        width: 300px;
        max-width: 90vw;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        z-index: 1000;
        pointer-events: none;
        opacity: 0;
        transition: opacity 0.2s;
        margin-bottom: 10px;
    }

    @media (max-width: 768px) {
        .info-tooltip {
            position: fixed;
            top: 50%;
            left: 50%;
            bottom: auto;
            transform: translate(-50%, -50%);
            width: 280px;
            max-width: 85vw;
            margin: 0;
            pointer-events: auto;
            display: none;
        }
        
        .info-icon.active .info-tooltip {
            display: block;
        }
        
        .info-tooltip::after {
            display: none;
        }
    }

    .info-tooltip::after {
        content: '';
        position: absolute;
        top: 100%;
        left: 50%;
        transform: translateX(-50%);
        border: 8px solid transparent;
        border-top-color: #764ba2;
    }

    .info-icon:hover .info-tooltip {
        opacity: 1;
    }

    /* Desktop tooltip hover support */
    @media (min-width: 769px) {
        .info-icon.active .info-tooltip {
            opacity: 1;
        }
    }



    .section-content {
        overflow: hidden;
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

        .tab-navigation {
            flex-wrap: wrap;
            gap: 5px;
        }

        .tab-button {
            padding: 10px 20px;
            font-size: 0.9rem;
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

        .info-tooltip {
            width: 250px;
            font-size: 0.8rem;
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
        border-radius: 8px;
        transition: transform 0.2s;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .weekly-rankings-item:hover {
        transform: translateX(5px);
    }

    /* Gold - 1st place */
    .weekly-rankings-item.rank-1 {
        background: linear-gradient(135deg, rgba(255, 215, 0, 0.15) 0%, rgba(255, 193, 7, 0.25) 100%);
        font-weight: bold;
        border-left: 4px solid rgba(218, 165, 32, 0.6);
    }

    /* Silver - 2nd place */
    .weekly-rankings-item.rank-2 {
        background: linear-gradient(135deg, rgba(192, 192, 192, 0.15) 0%, rgba(169, 169, 169, 0.25) 100%);
        font-weight: bold;
        border-left: 4px solid rgba(169, 169, 169, 0.6);
    }

    /* Bronze - 3rd place */
    .weekly-rankings-item.rank-3 {
        background: linear-gradient(135deg, rgba(205, 127, 50, 0.15) 0%, rgba(184, 115, 51, 0.25) 100%);
        font-weight: bold;
        border-left: 4px solid rgba(205, 127, 50, 0.6);
    }

    /* 4th place and below */
    .weekly-rankings-item:not(.rank-1):not(.rank-2):not(.rank-3) {
        background: linear-gradient(135deg, #FAFAFA 0%, #F0F0F0 100%);
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
    
    /* Time Window Button Styles */
    .time-window-btn {
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
    
    .time-window-btn:hover {
        background-color: #e9ecef;
        border-color: var(--secondary-color);
        transform: translateY(-1px);
    }
    
    .time-window-btn.active {
        background-color: var(--primary-color);
        color: white;
        border-color: var(--primary-color);
    }
    
    .time-window-btn.active:hover {
        background-color: var(--secondary-color);
        border-color: var(--secondary-color);
    }
    
    /* Vegas Zone Tab Styles */
    .vegas-tabs {
        display: flex;
        justify-content: center;
        gap: 10px;
        margin-bottom: 20px;
    }

    .vegas-tab {
        padding: 10px 25px;
        background: white;
        border: 2px solid var(--border-color);
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.95rem;
        font-weight: 500;
        color: var(--secondary-color);
        transition: all 0.2s ease;
    }

    .vegas-tab:hover {
        background: rgba(102, 126, 234, 0.1);
        border-color: var(--tab-active);
    }

    .vegas-tab.active {
        background: linear-gradient(135deg, var(--tab-active) 0%, var(--tab-hover) 100%);
        color: white;
        border-color: var(--tab-active);
    }

    .vegas-content {
        display: none;
    }

    .vegas-content.active {
        display: block;
    }
    
    @media (max-width: 768px) {
        .time-window-btn {
            padding: 8px 16px;
            font-size: 0.85rem;
        }

        .vegas-tabs {
            flex-wrap: wrap;
        }

        .vegas-tab {
            padding: 8px 18px;
            font-size: 0.9rem;
        }
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

        <!-- Tab Navigation -->
        <div class="tab-navigation">
            <?php if (!empty($league_id)): ?>
            <button class="tab-button active" onclick="switchTab('league')" id="tab-league">
                <i class="fas fa-users"></i> Your League
            </button>
            <?php endif; ?>
            <button class="tab-button <?php echo empty($league_id) ? 'active' : ''; ?>" onclick="switchTab('platform')" id="tab-platform">
                <i class="fas fa-globe"></i> Platform Wide
            </button>
        </div>

        <!-- LEAGUE SPECIFIC CONTENT -->
        <?php if (!empty($league_id)): ?>
        <div id="league-content" class="tab-content active">
            
            <!-- Wins Tracking Graph - LEAGUE SPECIFIC -->
            <?php if (!empty($trackingParticipants)): ?>
            <div class="section">
                <div class="section-header">
                    <div class="section-title">
                        <h2>
                            <i class="fas fa-chart-line"></i> 
                            Wins Progression Tracker
                        </h2>
                        <div class="info-icon">
                            <i class="fas fa-question-circle"></i>
                            <div class="info-tooltip">
                                Track how each participant's total wins have progressed throughout the season. Select different time windows to view short-term trends or full season performance.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="section-content">
                    <!-- Time Window Selector -->
                    <div style="display: flex; justify-content: center; margin-bottom: 20px; gap: 10px; flex-wrap: wrap;">
                        <button onclick="changeTimeWindow(7)" class="time-window-btn <?php echo $timeWindow === 7 ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-week"></i> Last 7 Days
                        </button>
                        <button onclick="changeTimeWindow(21)" class="time-window-btn <?php echo $timeWindow === 21 ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-alt"></i> Last 21 Days
                        </button>
                        <button onclick="changeTimeWindow(30)" class="time-window-btn <?php echo $timeWindow === 30 ? 'active' : ''; ?>">
                            <i class="fas fa-calendar"></i> Last 30 Days
                        </button>
                        <button onclick="changeTimeWindow(0)" class="time-window-btn <?php echo $timeWindow === 0 ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-check"></i> Full Season
                        </button>
                    </div>
                    
                    <div class="chart-container">
                        <canvas id="winsProgressChart"></canvas>
                    </div>
                </div>
            </div>
            <?php elseif (!empty($league_id)): ?>
            <div class="section">
                <div class="section-header">
                    <div class="section-title">
                        <h2>
                            <i class="fas fa-chart-line"></i> 
                            Wins Progression Tracker
                        </h2>
                        <div class="info-icon">
                            <i class="fas fa-question-circle"></i>
                            <div class="info-tooltip">
                                Track how each participant's total wins have progressed throughout the season.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="section-content">
                    <p style="text-align: center; color: var(--secondary-color); font-style: italic;">
                        No tracking data available yet for your league. Data will appear once games begin being recorded.
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Weekly Win Tracker - LEAGUE SPECIFIC (MOVED UP) -->
            <?php if (!empty($weeklyRankingsData)): ?>
            <div class="section" style="position: relative;">
                <button class="widget-pin-icon <?php echo in_array('weekly_rankings', $pinned_widgets) ? 'pinned' : ''; ?>" 
                        onclick="toggleWidgetPin('weekly_rankings', this)"
                        title="<?php echo in_array('weekly_rankings', $pinned_widgets) ? 'Unpin from homepage' : 'Pin to homepage'; ?>">
                    <i class="fas fa-<?php echo in_array('weekly_rankings', $pinned_widgets) ? 'check' : 'thumbtack'; ?>"></i>
                </button>
                <div class="section-header">
                    <div class="section-title">
                        <h2>
                            <i class="fas fa-trophy"></i> 
                            Weekly Win Rankings
                        </h2>
                        <div class="info-icon">
                            <i class="fas fa-question-circle"></i>
                            <div class="info-tooltip">
                                See who dominated each week. Weeks run Monday through Sunday. Great for tracking momentum swings!
                            </div>
                        </div>
                    </div>
                </div>
                <div class="section-content">
                    <div id="weekly-tracker-root"></div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- The Vegas Zone - LEAGUE SPECIFIC -->
            <?php if ((!empty($overperformers) || !empty($underperformers))): ?>
            <div class="section" style="position: relative;">
                <button class="widget-pin-icon <?php echo in_array('vegas_zone', $pinned_widgets) ? 'pinned' : ''; ?>" 
                        onclick="toggleWidgetPin('vegas_zone', this)"
                        title="<?php echo in_array('vegas_zone', $pinned_widgets) ? 'Unpin from homepage' : 'Pin to homepage'; ?>">
                    <i class="fas fa-<?php echo in_array('vegas_zone', $pinned_widgets) ? 'check' : 'thumbtack'; ?>"></i>
                </button>
                <div class="section-header">
                    <div class="section-title">
                        <h2>
                            <i class="fa-solid fa-dice"></i> 
                            The Vegas Zone
                        </h2>
                        <div class="info-icon">
                            <i class="fas fa-question-circle"></i>
                            <div class="info-tooltip">
                                Compare current team performance vs Vegas preseason win total projections. See which teams are beating or falling short of expectations.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="section-content">
                    <!-- Vegas Tabs -->
                    <div class="vegas-tabs">
                        <button class="vegas-tab active" onclick="switchVegasTab('over')">
                            <i class="fas fa-arrow-trend-up"></i> Exceeding Expectations
                        </button>
                        <button class="vegas-tab" onclick="switchVegasTab('under')">
                            <i class="fas fa-arrow-trend-down"></i> Falling Short
                        </button>
                    </div>

                    <!-- Exceeding Expectations -->
                    <div id="vegas-over" class="vegas-content active">
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

                    <!-- Falling Short -->
                    <div id="vegas-under" class="vegas-content">
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
                </div>
            </div>
            <?php endif; ?>

            <!-- Head-to-Head Comparison - LEAGUE SPECIFIC (SECOND LAST) -->
            <?php if (count($leagueParticipantsForH2H) >= 2): ?>
            <div class="section">
                <div class="section-header">
                    <div class="section-title">
                        <h2>
                            <i class="fas fa-users"></i> 
                            Head-to-Head Comparison
                        </h2>
                        <div class="info-icon">
                            <i class="fas fa-question-circle"></i>
                            <div class="info-tooltip">
                                Compare matchup records between participants. When your teams play against an opponent's teams, who comes out ahead?
                            </div>
                        </div>
                    </div>
                </div>
                <div class="section-content">
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
            </div>
            <?php endif; ?>

            <!-- Strength of Schedule - LEAGUE SPECIFIC (LAST) -->
            <?php if (!empty($strengthOfSchedule)): ?>
            <div class="section" style="position: relative;">
                <div class="section-header">
                    <div class="section-title">
                        <h2>
                            <i class="fas fa-calendar-check"></i> 
                            Strength of Schedule
                        </h2>
                        <div class="info-icon">
                            <i class="fas fa-question-circle"></i>
                            <div class="info-tooltip">
                                Shows how tough each participant's schedule has been based on the average win percentage of opponents faced. Higher percentage = tougher schedule.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="section-content">
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
                            <tr>
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
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- PLATFORM WIDE CONTENT -->
        <div id="platform-content" class="tab-content <?php echo empty($league_id) ? 'active' : ''; ?>">
            
            <!-- Platform-Wide Leaderboard - ALL LEAGUES -->
            <div class="section" style="position: relative;">
                <button class="widget-pin-icon <?php echo in_array('platform_leaderboard', $pinned_widgets) ? 'pinned' : ''; ?>" 
                        onclick="toggleWidgetPin('platform_leaderboard', this)"
                        title="<?php echo in_array('platform_leaderboard', $pinned_widgets) ? 'Unpin from homepage' : 'Pin to homepage'; ?>">
                    <i class="fas fa-<?php echo in_array('platform_leaderboard', $pinned_widgets) ? 'check' : 'thumbtack'; ?>"></i>
                </button>
                <div class="section-header">
                    <div class="section-title">
                        <h2>
                            <i class="fas fa-globe"></i> 
                            Top 5 Leaderboard
                        </h2>
                        <div class="info-icon">
                            <i class="fas fa-question-circle"></i>
                            <div class="info-tooltip">
                                The top 5 participants across all leagues on the platform, ranked by total wins. Click to expand and see their rosters.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="section-content">
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
            </div>

            <!-- Draft Value Analysis - PLATFORM WIDE (Steals & Busts) -->
            <?php if (!empty($bestDraftSteals) || !empty($worstDraftPicks)): ?>
            <div class="section" style="position: relative;">
                <button class="widget-pin-icon <?php echo in_array('draft_steals', $pinned_widgets) ? 'pinned' : ''; ?>" 
                        onclick="toggleWidgetPin('draft_steals', this)"
                        title="<?php echo in_array('draft_steals', $pinned_widgets) ? 'Unpin from homepage' : 'Pin to homepage'; ?>">
                    <i class="fas fa-<?php echo in_array('draft_steals', $pinned_widgets) ? 'check' : 'thumbtack'; ?>"></i>
                </button>
                <div class="section-header">
                    <div class="section-title">
                        <h2>
                            <i class="fas fa-chart-line"></i> 
                            Draft Value Analysis
                        </h2>
                        <div class="info-icon">
                            <i class="fas fa-question-circle"></i>
                            <div class="info-tooltip">
                                Compare teams to their draft round averages. Steals = outperformers (later picks score higher). Busts = underperformers (earlier picks penalized more).
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Toggle Tabs -->
                <div class="vegas-tabs draft-tabs" style="margin: 1rem 1.5rem 1.5rem;">
                    <button class="vegas-tab active" onclick="switchDraftTab('steals')">
                        <i class="fas fa-gem"></i> Steals
                    </button>
                    <button class="vegas-tab" onclick="switchDraftTab('busts')">
                        <i class="fas fa-arrow-trend-down"></i> Busts
                    </button>
                </div>
                <!-- STEALS CONTENT -->
                <div class="section-content vegas-content active" id="draft-steals" style="display: block;">
                    <?php if (!empty($bestDraftSteals)): ?>
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
                                        <?php echo $rank; ?>
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
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div style="padding: 2rem; text-align: center; color: #666;">
                        No draft steals data available
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- BUSTS CONTENT -->
                <div class="section-content vegas-content" id="draft-busts" style="display: none;">
                    <?php if (!empty($worstDraftPicks)): ?>
                    <div class="table-responsive">
                        <table class="leaderboard-table draft-busts-table">
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
                                <?php foreach ($worstDraftPicks as $bust): 
                                    $rank = $bust['rank'];
                                ?>
                                <tr>
                                    <td class="rank-cell">
                                        <?php echo $rank; ?>
                                    </td>
                                    <td class="participant-name">
                                        <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($bust['team_name']); ?>" 
                                           style="text-decoration: none; color: inherit; display: flex; align-items: center; gap: 8px;">
                                            <img src="<?php echo htmlspecialchars(getTeamLogo($bust['team_name'])); ?>" 
                                                 alt="<?php echo htmlspecialchars($bust['team_name']); ?>" 
                                                 class="team-logo"
                                                 style="width: 24px; height: 24px;"
                                                 onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjEyIiB5PSIxNiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                                            <span class="team-name-text"><?php echo htmlspecialchars($bust['team_name']); ?></span>
                                        </a>
                                        <div style="font-size: 0.75rem; color: #666; margin-top: 2px;">
                                            Pick #<?php echo $bust['pick_number']; ?>
                                            <span class="mobile-owner"> • <?php echo htmlspecialchars($bust['owner_name']); ?></span>
                                        </div>
                                    </td>
                                    <td class="participant-name hide-mobile">
                                        <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?php echo $bust['league_id']; ?>&user_id=<?php echo $bust['user_id']; ?>" 
                                           style="text-decoration: none; color: inherit;">
                                            <?php echo htmlspecialchars($bust['owner_name']); ?>
                                        </a>
                                        <div style="font-size: 0.85rem; color: #999; margin-top: 2px;">
                                            <?php echo htmlspecialchars($bust['league_name']); ?>
                                        </div>
                                    </td>
                                    <td class="total-wins" style="text-align: center;">
                                        <strong><?php echo $bust['round_number']; ?></strong>
                                    </td>
                                    <td class="total-wins" style="text-align: center;">
                                        <strong style="color: #ef4444;"><?php echo $bust['actual_wins']; ?></strong>
                                    </td>
                                    <td class="total-wins hide-mobile" style="text-align: center;">
                                        <strong><?php echo $bust['round_avg_wins']; ?></strong>
                                    </td>
                                    <td class="total-wins" style="text-align: center;">
                                        <strong style="color: <?php echo $bust['grade_color']; ?>; font-size: 1.1em;">
                                            <?php echo number_format($bust['bust_score'], 2); ?>
                                        </strong>
                                        <div style="font-size: 0.7rem; color: <?php echo $bust['grade_color']; ?>; margin-top: 2px; font-weight: bold;">
                                            <?php echo $bust['bust_grade']; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div style="padding: 2rem; text-align: center; color: #666;">
                        No draft busts data available
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // =====================================================================
    // MOBILE TOOLTIP CLICK SUPPORT
    // =====================================================================
    document.addEventListener('DOMContentLoaded', function() {
        // Add click handlers to info icons for mobile
        const infoIcons = document.querySelectorAll('.info-icon');
        
        infoIcons.forEach(icon => {
            icon.addEventListener('click', function(e) {
                e.stopPropagation();
                
                // Close all other tooltips
                infoIcons.forEach(other => {
                    if (other !== icon) {
                        other.classList.remove('active');
                    }
                });
                
                // Toggle this tooltip
                const wasActive = this.classList.contains('active');
                this.classList.toggle('active');
                

            });
        });
        
        // Close tooltips when clicking outside
        document.addEventListener('click', function() {
            infoIcons.forEach(icon => {
                icon.classList.remove('active');
            });
        });
    });

    // =====================================================================
    // TAB SWITCHING FUNCTIONALITY
    // =====================================================================
    function switchTab(tab) {
        // Remove active class from all tabs
        document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
        
        // Add active class to selected tab
        if (tab === 'league') {
            document.getElementById('tab-league').classList.add('active');
            document.getElementById('league-content').classList.add('active');
        } else {
            document.getElementById('tab-platform').classList.add('active');
            document.getElementById('platform-content').classList.add('active');
        }
        
        // Save preference to localStorage
        localStorage.setItem('analytics_active_tab', tab);
    }
    
    // Restore tab preference on load
    window.addEventListener('DOMContentLoaded', () => {
        const savedTab = localStorage.getItem('analytics_active_tab');
        if (savedTab && document.getElementById('tab-' + savedTab)) {
            switchTab(savedTab);
        }
    });

    // =====================================================================
    // VEGAS ZONE TAB SWITCHING
    // =====================================================================
    function switchVegasTab(tab) {
        // Remove active class from all vegas tabs
        document.querySelectorAll('.vegas-tab').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.vegas-content').forEach(content => content.classList.remove('active'));
        
        // Add active class to selected tab
        document.querySelectorAll('.vegas-tab')[tab === 'over' ? 0 : 1].classList.add('active');
        document.getElementById('vegas-' + tab).classList.add('active');
    }

    // =====================================================================
    // DRAFT VALUE TAB SWITCHING
    // =====================================================================
    function switchDraftTab(tab) {
        // Remove active class from draft tabs only
        const allTabs = document.querySelectorAll('.draft-tabs .vegas-tab');
        allTabs.forEach(btn => btn.classList.remove('active'));
        
        // Hide all content sections
        const stealsContent = document.getElementById('draft-steals');
        const bustsContent = document.getElementById('draft-busts');
        
        if (stealsContent) stealsContent.style.display = 'none';
        if (bustsContent) bustsContent.style.display = 'none';
        
        // Show selected content and activate tab
        if (tab === 'steals') {
            if (stealsContent) stealsContent.style.display = 'block';
            allTabs[0]?.classList.add('active');
        } else {
            if (bustsContent) bustsContent.style.display = 'block';
            allTabs[1]?.classList.add('active');
        }
    }

    // =====================================================================
    // WINS TRACKING CHART - LEAGUE SPECIFIC
    // =====================================================================
    <?php if (!empty($league_id) && !empty($trackingParticipants)): ?>
    
    // Prepare chart data from PHP
    const trackingDates = <?php echo json_encode($trackingDates); ?>;
    const trackingParticipants = <?php echo json_encode($trackingParticipants); ?>;
    const trackingChartData = <?php echo json_encode($trackingData); ?>;
    
    // Calculate min and max for dynamic Y-axis
    let allWins = [];
    Object.values(trackingChartData).forEach(data => {
        allWins = allWins.concat(data);
    });
    const minWins = Math.min(...allWins);
    const maxWins = Math.max(...allWins);
    
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
                    min: Math.max(0, minWins - 5),
                    max: maxWins + 5,
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
                            className={`weekly-rankings-item rank-${participant.rank}`}
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
    // =====================================================================
    // PLATFORM LEADERBOARD EXPAND
    // =====================================================================
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

    // =====================================================================
    // TIME WINDOW CHANGE
    // =====================================================================
    function changeTimeWindow(days) {
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('time_window', days);
        window.location.href = currentUrl.toString();
    }
    
    // =====================================================================
    // WIDGET PIN/UNPIN
    // =====================================================================
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
