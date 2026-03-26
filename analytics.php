<?php
/**
 * analytics.php - Analytics Dashboard
 * 
 * Displays platform and league analytics including:
 *   - Wins progression chart (7d/21d/30d/full season)
 *   - Weekly win rankings (React component)
 *   - Vegas over/under performance tracking
 *   - Head-to-head comparisons
 *   - Strength of schedule
 *   - Platform-wide leaderboard with expandable rosters
 *   - Draft value analysis (steals & busts)
 *   - Games played rankings
 * 
 * Path: /data/www/default/nba-wins-platform/public/analytics.php
 */

date_default_timezone_set('America/New_York');
session_start();


// =====================================================================
// SESSION CONTEXT
// =====================================================================
$current_league_id = isset($_SESSION['current_league_id']) ? $_SESSION['current_league_id'] : '';
$current_user_id   = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : '';
$user_id           = $_SESSION['user_id'];
$league_id         = $_SESSION['current_league_id'];
$currentLeagueId   = $league_id;


// =====================================================================
// DEPENDENCIES
// =====================================================================
require_once '/data/www/default/nba-wins-platform/config/db_connection.php';
require_once '/data/www/default/nba-wins-platform/config/season_config.php';
$season = getSeasonConfig();


// =====================================================================
// PINNED WIDGETS
// =====================================================================
$pinned_widgets = [];
if (isset($current_user_id) && !empty($current_user_id)) {
    $stmt = $pdo->prepare("
        SELECT widget_type 
        FROM user_dashboard_widgets 
        WHERE user_id = ? AND is_active = 1
    ");
    $stmt->execute([$current_user_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pinned_widgets[] = $row['widget_type'];
    }
}


// =====================================================================
// DATA QUERIES
// =====================================================================
try {

    // ------ Platform Stats ------
    $stats = [];

    $stmt = $pdo->query("SELECT COUNT(*) AS total_leagues FROM leagues");
    $stats['total_leagues'] = $stmt->fetch()['total_leagues'];

    $stmt = $pdo->query("SELECT COUNT(*) AS total_participants FROM league_participants WHERE status = 'active'");
    $stats['total_participants'] = $stmt->fetch()['total_participants'];

    $stmt = $pdo->query("SELECT COUNT(*) AS total_users FROM users WHERE status = 'active'");
    $stats['total_users'] = $stmt->fetch()['total_users'];

    $stmt = $pdo->query("SELECT COUNT(*) AS completed_drafts FROM draft_sessions WHERE status = 'completed'");
    $stats['completed_drafts'] = $stmt->fetch()['completed_drafts'];


    // ------ Top NBA Teams ------
    $stmt = $pdo->query("
        SELECT name, win, loss,
               ROUND((win / (win + loss)) * 100, 1) AS win_percentage
        FROM {$season['standings_table']}
        WHERE (win + loss) > 0
        ORDER BY win_percentage DESC
        LIMIT 10
    ");
    $top_teams = $stmt->fetchAll();


    // ------ League Activity ------
    $stmt = $pdo->query("
        SELECT l.display_name, COUNT(lp.id) AS participant_count, l.created_at
        FROM leagues l
        LEFT JOIN league_participants lp ON l.id = lp.league_id AND lp.status = 'active'
        GROUP BY l.id, l.display_name, l.created_at
        ORDER BY participant_count DESC
        LIMIT 5
    ");
    $league_activity = $stmt->fetchAll();


    // ------ Recent Drafts ------
    $stmt = $pdo->query("
        SELECT ds.created_at, ds.status, l.display_name AS league_name
        FROM draft_sessions ds
        JOIN leagues l ON ds.league_id = l.id
        ORDER BY ds.created_at DESC
        LIMIT 5
    ");
    $recent_drafts = $stmt->fetchAll();


    // ------ Vegas Over/Under Lines ------
    $stmt = $pdo->query("
        SELECT team_name, over_under_number
        FROM over_under
        ORDER BY over_under_number DESC, team_name ASC
    ");
    $over_unders = $stmt->fetchAll();


    // ==========================================================================
    // VEGAS OVER/UNDER PERFORMANCE
    // ==========================================================================
    $overperformers  = [];
    $underperformers = [];

    if (!empty($league_id)) {
        // Get team owners in the current league
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                lpt.team_name,
                u.display_name AS owner,
                lp.id AS participant_id,
                u.id AS user_id
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
                'owner'          => $row['owner'],
                'user_id'        => $row['user_id'],
                'participant_id' => $row['participant_id']
            ];
        }

        // Calculate pace vs projection for each team
        foreach ($over_unders as $ou) {
            $team_name       = $ou['team_name'];
            $vegas_projection = $ou['over_under_number'];

            $stmt = $pdo->prepare("SELECT win, loss FROM {$season['standings_table']} WHERE name = ?");
            $stmt->execute([$team_name]);
            $record = $stmt->fetch();

            if ($record) {
                $games_played = $record['win'] + $record['loss'];
                if ($games_played > 0) {
                    $current_pace = ($record['win'] / $games_played) * 82;
                    $variance     = $current_pace - $vegas_projection;

                    $team_data = [
                        'team_name'        => $team_name,
                        'vegas_projection' => $vegas_projection,
                        'current_pace'     => $current_pace,
                        'variance'         => $variance,
                        'current_record'   => $record['win'] . '-' . $record['loss'],
                        'games_played'     => $games_played,
                        'owner'            => isset($ownerMap[$team_name]) ? $ownerMap[$team_name]['owner'] : null,
                        'user_id'          => isset($ownerMap[$team_name]) ? $ownerMap[$team_name]['user_id'] : null,
                        'participant_id'   => isset($ownerMap[$team_name]) ? $ownerMap[$team_name]['participant_id'] : null
                    ];

                    if (isset($ownerMap[$team_name])) {
                        if ($variance > 0) {
                            $overperformers[] = $team_data;
                        } elseif ($variance < 0) {
                            $underperformers[] = $team_data;
                        }
                    }
                }
            }
        }

        usort($overperformers, function ($a, $b) {
            return $b['variance'] <=> $a['variance'];
        });
        usort($underperformers, function ($a, $b) {
            return $a['variance'] <=> $b['variance'];
        });

        $overperformers  = array_slice($overperformers, 0, 5);
        $underperformers = array_slice($underperformers, 0, 5);
    }


    // ==========================================================================
    // PLATFORM LEADERBOARD
    // ==========================================================================
    $stmt = $pdo->query("
        SELECT 
            u.display_name,
            l.display_name AS league_name,
            l.id AS league_id,
            lp.id AS participant_id,
            u.id AS user_id,
            COALESCE(SUM(t.win), 0) AS total_wins
        FROM league_participants lp
        JOIN users u ON lp.user_id = u.id
        JOIN leagues l ON lp.league_id = l.id
        LEFT JOIN league_participant_teams lpt ON lp.id = lpt.league_participant_id
        LEFT JOIN {$season['standings_table']} t ON lpt.team_name = t.name
        WHERE lp.status = 'active'
        GROUP BY u.id, u.display_name, l.id, l.display_name, lp.id
        ORDER BY total_wins DESC
        LIMIT 5
    ");
    $platform_leaderboard = $stmt->fetchAll();


    // ==========================================================================
    // BEST DRAFT STEALS
    // ==========================================================================
    $bestDraftSteals = [];

    // Get average wins per draft round
    $stmt = $pdo->query("
        SELECT dp.round_number, AVG(COALESCE(t.win, 0)) AS avg_round_wins
        FROM draft_picks dp
        JOIN nba_teams nt ON dp.team_id = nt.id
        LEFT JOIN {$season['standings_table']} t ON nt.name = t.name
        WHERE EXISTS (
            SELECT 1 FROM draft_sessions ds
            WHERE ds.id = dp.draft_session_id AND ds.status = 'completed'
        )
        GROUP BY dp.round_number
    ");
    $roundAverages = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Get all drafted teams
    $stmt = $pdo->query("
        SELECT 
            nt.name AS team_name,
            COALESCE(t.win, 0) AS actual_wins,
            dp.pick_number,
            dp.round_number,
            u.display_name AS owner_name,
            u.id AS user_id,
            l.display_name AS league_name,
            l.id AS league_id,
            lp.id AS participant_id
        FROM draft_picks dp
        JOIN league_participants lp ON dp.league_participant_id = lp.id
        JOIN users u ON lp.user_id = u.id
        JOIN leagues l ON lp.league_id = l.id
        JOIN nba_teams nt ON dp.team_id = nt.id
        LEFT JOIN {$season['standings_table']} t ON nt.name = t.name
        WHERE EXISTS (
            SELECT 1 FROM draft_sessions ds
            WHERE ds.id = dp.draft_session_id AND ds.status = 'completed'
        )
        AND lp.status = 'active'
    ");
    $allDraftedTeams = $stmt->fetchAll();

    // Calculate steal scores
    foreach ($allDraftedTeams as $team) {
        $round     = $team['round_number'];
        $round_avg = isset($roundAverages[$round]) ? $roundAverages[$round] : 0;

        $base_steal_score    = $team['actual_wins'] - $round_avg;
        $pick_position_bonus = $team['pick_number'] / 4;
        $steal_score         = $base_steal_score + $pick_position_bonus;

        $team['round_avg_wins']   = round($round_avg, 1);
        $team['steal_score']      = round($steal_score, 2);
        $team['base_steal_score'] = round($base_steal_score, 1);

        if ($base_steal_score >= 3.0) {
            $team['steal_grade'] = 'MASSIVE STEAL';
            $team['grade_color'] = '#3fb950';
        } elseif ($base_steal_score >= 2.0) {
            $team['steal_grade'] = 'STEAL';
            $team['grade_color'] = '#56d364';
        } elseif ($base_steal_score >= 1.0) {
            $team['steal_grade'] = 'Good Value';
            $team['grade_color'] = '#388bfd';
        } elseif ($base_steal_score >= 0) {
            $team['steal_grade'] = 'Fair';
            $team['grade_color'] = '#8b949e';
        } else {
            $team['steal_grade'] = 'Below Avg';
            $team['grade_color'] = '#d29922';
        }

        $bestDraftSteals[] = $team;
    }

    // Sort and rank steals
    usort($bestDraftSteals, function ($a, $b) {
        if (abs($a['steal_score'] - $b['steal_score']) > 0.001) {
            return $b['steal_score'] <=> $a['steal_score'];
        }
        return $b['pick_number'] <=> $a['pick_number'];
    });

    $topSteals      = array_slice($bestDraftSteals, 0, 5);
    $bestDraftSteals = [];
    $current_rank    = 1;
    $prev_steal_score     = null;
    $items_at_current_rank = 0;

    foreach ($topSteals as $team) {
        if ($prev_steal_score !== null && abs($team['steal_score'] - $prev_steal_score) < 0.005) {
            $team['rank'] = $current_rank;
        } else {
            if ($items_at_current_rank > 0) $current_rank += $items_at_current_rank;
            $team['rank'] = $current_rank;
            $items_at_current_rank = 0;
        }
        $prev_steal_score = $team['steal_score'];
        $items_at_current_rank++;
        $bestDraftSteals[] = $team;
    }


    // ==========================================================================
    // WORST DRAFT PICKS (BUSTS)
    // ==========================================================================
    $worstDraftPicks = [];

    foreach ($allDraftedTeams as $team) {
        $round     = $team['round_number'];
        $round_avg = isset($roundAverages[$round]) ? $roundAverages[$round] : 0;

        $base_bust_score       = $round_avg - $team['actual_wins'];
        $pick_position_penalty = (31 - $team['pick_number']) / 10;
        $bust_score            = $base_bust_score + $pick_position_penalty;

        $team['round_avg_wins']   = round($round_avg, 1);
        $team['bust_score']       = round($bust_score, 2);
        $team['base_bust_score']  = round($base_bust_score, 1);

        if ($base_bust_score >= 3.0) {
            $team['bust_grade'] = 'MASSIVE BUST';
            $team['grade_color'] = '#f85149';
        } elseif ($base_bust_score >= 2.0) {
            $team['bust_grade'] = 'BUST';
            $team['grade_color'] = '#ff7b72';
        } elseif ($base_bust_score >= 1.0) {
            $team['bust_grade'] = 'Underperforming';
            $team['grade_color'] = '#d29922';
        } elseif ($base_bust_score >= 0) {
            $team['bust_grade'] = 'Below Avg';
            $team['grade_color'] = '#8b949e';
        } else {
            $team['bust_grade'] = 'Fair';
            $team['grade_color'] = '#545d68';
        }

        $worstDraftPicks[] = $team;
    }

    // Sort and rank busts
    usort($worstDraftPicks, function ($a, $b) {
        if (abs($a['bust_score'] - $b['bust_score']) > 0.001) {
            return $b['bust_score'] <=> $a['bust_score'];
        }
        return $a['pick_number'] <=> $b['pick_number'];
    });

    $topBusts        = array_slice($worstDraftPicks, 0, 5);
    $worstDraftPicks = [];
    $current_rank    = 1;
    $prev_bust_score      = null;
    $items_at_current_rank = 0;

    foreach ($topBusts as $team) {
        if ($prev_bust_score !== null && abs($team['bust_score'] - $prev_bust_score) < 0.005) {
            $team['rank'] = $current_rank;
        } else {
            if ($items_at_current_rank > 0) $current_rank += $items_at_current_rank;
            $team['rank'] = $current_rank;
            $items_at_current_rank = 0;
        }
        $prev_bust_score = $team['bust_score'];
        $items_at_current_rank++;
        $worstDraftPicks[] = $team;
    }


    // ==========================================================================
    // GAMES PLAYED RANKINGS
    // ==========================================================================
    $gamesPlayedRankings = [];

    if (!empty($league_id)) {
        $stmt = $pdo->prepare("
            SELECT 
                u.display_name,
                u.id AS user_id,
                lp.id AS participant_id,
                COALESCE(SUM(t.win + t.loss), 0) AS total_games_played,
                COALESCE(SUM(t.win), 0) AS total_wins,
                COALESCE(SUM(t.loss), 0) AS total_losses
            FROM league_participants lp
            JOIN users u ON lp.user_id = u.id
            LEFT JOIN league_participant_teams lpt ON lp.id = lpt.league_participant_id
            LEFT JOIN {$season['standings_table']} t ON lpt.team_name = t.name
            WHERE lp.league_id = ? AND lp.status = 'active'
            GROUP BY u.id, u.display_name, lp.id
            ORDER BY total_games_played DESC, total_wins DESC
        ");
        $stmt->execute([$league_id]);
        $gamesPlayedRankings = $stmt->fetchAll();
    }


    // ==========================================================================
    // STRENGTH OF SCHEDULE
    // ==========================================================================
    $strengthOfSchedule = [];

    if (!empty($league_id)) {
        $stmt = $pdo->prepare("
            SELECT u.display_name, u.id AS user_id, lp.id AS participant_id
            FROM league_participants lp
            JOIN users u ON lp.user_id = u.id
            WHERE lp.league_id = ? AND lp.status = 'active'
        ");
        $stmt->execute([$league_id]);
        $an_participants = $stmt->fetchAll();

        foreach ($an_participants as $an_participant) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT 
                    g.id AS game_id,
                    CASE 
                        WHEN g.home_team IN (
                            REPLACE(lpt.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                            REPLACE(REPLACE(lpt.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                        ) THEN g.away_team
                        WHEN g.away_team IN (
                            REPLACE(lpt.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                            REPLACE(REPLACE(lpt.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                        ) THEN g.home_team
                    END AS opponent
                FROM league_participant_teams lpt
                JOIN games g 
                    ON (g.home_team IN (
                            REPLACE(lpt.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                            REPLACE(REPLACE(lpt.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                        ) 
                        OR g.away_team IN (
                            REPLACE(lpt.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                            REPLACE(REPLACE(lpt.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                        ))
                WHERE lpt.league_participant_id = ?
                  AND g.status_long IN ('Final', 'Finished')
                  AND DATE(g.start_time) >= '{$season['season_start_date']}'
            ");
            $stmt->execute([$an_participant['participant_id']]);
            $games = $stmt->fetchAll();

            if (count($games) > 0) {
                $total_opp_win_pct = 0;
                $game_count        = 0;

                foreach ($games as $game) {
                    $oppStmt = $pdo->prepare("
                        SELECT COALESCE(win / NULLIF(win + loss, 0) * 100, 0) AS win_pct
                        FROM {$season['standings_table']}
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
                        'display_name'   => $an_participant['display_name'],
                        'user_id'        => $an_participant['user_id'],
                        'participant_id' => $an_participant['participant_id'],
                        'total_games'    => $game_count,
                        'opponent_win_pct' => round($total_opp_win_pct / $game_count, 1)
                    ];
                }
            }
        }

        usort($strengthOfSchedule, function ($a, $b) {
            if ($b['opponent_win_pct'] == $a['opponent_win_pct']) {
                return strcmp($a['display_name'], $b['display_name']);
            }
            return $b['opponent_win_pct'] <=> $a['opponent_win_pct'];
        });
    }


    // ==========================================================================
    // PLATFORM LEADERBOARD WITH TEAMS (expandable rosters)
    // ==========================================================================
    $platform_leaderboard_with_teams = [];

    foreach ($platform_leaderboard as $entry) {
        $stmt = $pdo->prepare("
            SELECT 
                lpt.team_name,
                COALESCE(t.win, 0) AS wins,
                COALESCE(dp.pick_number, 999) AS draft_pick_number
            FROM league_participant_teams lpt
            LEFT JOIN {$season['standings_table']} t ON lpt.team_name = t.name
            LEFT JOIN nba_teams nt ON lpt.team_name = nt.name
            LEFT JOIN draft_picks dp 
                ON (lpt.league_participant_id = dp.league_participant_id
                    AND dp.draft_session_id = (
                        SELECT id FROM draft_sessions 
                        WHERE league_id = ? AND status = 'completed'
                        ORDER BY created_at DESC LIMIT 1
                    )
                    AND dp.team_id = nt.id)
            WHERE lpt.league_participant_id = ?
            ORDER BY COALESCE(dp.pick_number, 999) ASC
        ");
        $stmt->execute([$entry['league_id'], $entry['participant_id']]);
        $entry['teams'] = $stmt->fetchAll();
        $platform_leaderboard_with_teams[] = $entry;
    }

    $platform_leaderboard = $platform_leaderboard_with_teams;


    // ==========================================================================
    // WINS PROGRESSION TRACKING GRAPH
    // ==========================================================================
    $trackingData         = [];
    $trackingDates        = [];
    $trackingParticipants = [];
    $timeWindow           = isset($_GET['time_window']) ? intval($_GET['time_window']) : 7;

    if ($timeWindow === 0) {
        $startDate = $season['season_start_date'];
    } else {
        $startDate = date('Y-m-d', strtotime("-{$timeWindow} days"));
        if ($startDate < $season['season_start_date']) $startDate = $season['season_start_date'];
    }

    if (!empty($league_id)) {
        // Get all daily win records
        $stmt = $pdo->prepare("
            SELECT date, league_participant_id, total_wins
            FROM league_participant_daily_wins
            WHERE league_participant_id IN (
                SELECT id FROM league_participants 
                WHERE league_id = ? AND status = 'active'
            )
            ORDER BY date ASC
        ");
        $stmt->execute([$league_id]);
        $dailyWinsData = $stmt->fetchAll();

        // Get participant name mapping
        $stmt = $pdo->prepare("
            SELECT lp.id AS participant_id, u.display_name
            FROM league_participants lp
            JOIN users u ON lp.user_id = u.id
            WHERE lp.league_id = ? AND lp.status = 'active'
            ORDER BY u.display_name
        ");
        $stmt->execute([$league_id]);
        $participantInfo = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Build tracking arrays
        foreach ($dailyWinsData as $record) {
            if ($record['date'] < $startDate) continue;

            $pid = $record['league_participant_id'];
            if (!isset($participantInfo[$pid])) continue;

            $pname = $participantInfo[$pid];
            if (!in_array($pname, $trackingParticipants)) $trackingParticipants[] = $pname;
            if (!in_array($record['date'], $trackingDates)) $trackingDates[] = $record['date'];
        }

        // Initialize data arrays
        foreach ($trackingParticipants as $p) {
            $trackingData[$p] = [];
        }

        // Fill in wins per date per participant
        foreach ($trackingDates as $date) {
            foreach ($trackingParticipants as $p) {
                $pid  = array_search($p, $participantInfo);
                $wins = 0;
                foreach ($dailyWinsData as $record) {
                    if ($record['date'] === $date && $record['league_participant_id'] == $pid) {
                        $wins = $record['total_wins'];
                        break;
                    }
                }
                $trackingData[$p][] = $wins;
            }
        }
    }


    // ==========================================================================
    // HEAD-TO-HEAD RECORDS
    // ==========================================================================
    $h2hRecords             = [];
    $intraTeamRecords       = [];
    $leagueParticipantsForH2H = [];

    if (!empty($league_id)) {
        $stmt = $pdo->prepare("
            SELECT lp.id, u.display_name, u.id AS user_id
            FROM league_participants lp
            JOIN users u ON lp.user_id = u.id
            WHERE lp.league_id = ? AND lp.status = 'active'
            ORDER BY u.display_name
        ");
        $stmt->execute([$league_id]);
        $leagueParticipantsForH2H = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($leagueParticipantsForH2H as $p1) {
            foreach ($leagueParticipantsForH2H as $p2) {

                // Same participant: count intra-team games
                if ($p1['id'] === $p2['id']) {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(DISTINCT g.id) AS total_games
                        FROM league_participant_teams team1
                        JOIN league_participant_teams team2 
                            ON team1.league_participant_id = team2.league_participant_id 
                            AND team1.id < team2.id
                        JOIN games g 
                            ON ((g.home_team IN (
                                    REPLACE(team1.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                                    REPLACE(REPLACE(team1.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                                ) 
                                AND g.away_team IN (
                                    REPLACE(team2.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                                    REPLACE(REPLACE(team2.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                                ))
                                OR (g.away_team IN (
                                    REPLACE(team1.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                                    REPLACE(REPLACE(team1.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                                ) 
                                AND g.home_team IN (
                                    REPLACE(team2.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                                    REPLACE(REPLACE(team2.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                                )))
                            AND g.status_long IN ('Final', 'Finished')
                            AND DATE(g.start_time) >= '{$season['season_start_date']}'
                        WHERE team1.league_participant_id = ?
                    ");
                    $stmt->execute([$p1['id']]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $intraTeamRecords[$p1['id']] = ['total_games' => $result['total_games'] ?? 0];
                    continue;
                }

                // Different participants: H2H wins/losses
                $stmt = $pdo->prepare("
                    SELECT 
                        SUM(CASE 
                            WHEN ((g.home_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers')
                                   OR g.home_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                                  AND g.home_points > g.away_points) THEN 1
                            WHEN ((g.away_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers')
                                   OR g.away_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                                  AND g.away_points > g.home_points) THEN 1
                            ELSE 0 
                        END) AS wins,
                        SUM(CASE 
                            WHEN ((g.home_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers')
                                   OR g.home_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                                  AND g.home_points < g.away_points) THEN 1
                            WHEN ((g.away_team = REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers')
                                   OR g.away_team = REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers'))
                                  AND g.away_points < g.home_points) THEN 1
                            ELSE 0 
                        END) AS losses
                    FROM league_participant_teams my_team
                    JOIN league_participants my_participant 
                        ON my_team.league_participant_id = my_participant.id
                    JOIN games g 
                        ON (g.home_team IN (
                                REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                                REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                            ) 
                            OR g.away_team IN (
                                REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                                REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                            ))
                        AND g.status_long IN ('Final', 'Finished')
                        AND DATE(g.start_time) >= '{$season['season_start_date']}'
                    JOIN league_participant_teams opponent_team 
                        ON ((g.home_team IN (
                                REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                                REPLACE(REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                            ) 
                            AND g.away_team IN (
                                REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                                REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                            ))
                            OR (g.away_team IN (
                                REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                                REPLACE(REPLACE(opponent_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                            ) 
                            AND g.home_team IN (
                                REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'),
                                REPLACE(REPLACE(my_team.team_name, 'Los Angeles Clippers', 'LA Clippers'), 'L.A. Clippers', 'LA Clippers')
                            )))
                    JOIN league_participants opponent_participant 
                        ON opponent_team.league_participant_id = opponent_participant.id
                        AND opponent_participant.league_id = my_participant.league_id
                    WHERE my_participant.id = ? AND opponent_participant.id = ?
                ");
                $stmt->execute([$p1['id'], $p2['id']]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                $h2hRecords[$p1['id']][$p2['id']] = [
                    'wins'   => $result['wins'] ?? 0,
                    'losses' => $result['losses'] ?? 0
                ];
            }
        }
    }


    // ==========================================================================
    // WEEKLY WIN TRACKER
    // ==========================================================================
    $weeklyRankingsData = [];

    if (!empty($league_id)) {
        $stmt = $pdo->prepare("
            SELECT 
                main.display_name,
                main.week_num,
                CONCAT('Week of ', main.monday_date) AS week_label,
                main.week_total - IFNULL(prev_week.end_total, main.week_start_total) AS weekly_wins
            FROM (
                SELECT 
                    u.display_name,
                    YEARWEEK(DATE_SUB(pdw.date, INTERVAL 1 DAY), 1) AS week_num,
                    DATE_FORMAT(MIN(DATE_SUB(pdw.date, INTERVAL DAYOFWEEK(pdw.date) - 2 DAY)), '%m/%d') AS monday_date,
                    MIN(pdw.total_wins) AS week_start_total,
                    MAX(pdw.total_wins) AS week_total,
                    MIN(pdw.date) AS week_start_date
                FROM league_participant_daily_wins pdw
                JOIN league_participants lp ON pdw.league_participant_id = lp.id
                JOIN users u ON lp.user_id = u.id
                WHERE pdw.date >= '{$season['season_start_date']}'
                  AND lp.league_id = ?
                  AND lp.status = 'active'
                GROUP BY u.display_name, YEARWEEK(DATE_SUB(pdw.date, INTERVAL 1 DAY), 1)
            ) main
            LEFT JOIN (
                SELECT 
                    u.display_name,
                    YEARWEEK(DATE_SUB(pdw.date, INTERVAL 1 DAY), 1) AS week_num,
                    MAX(pdw.total_wins) AS end_total
                FROM league_participant_daily_wins pdw
                JOIN league_participants lp ON pdw.league_participant_id = lp.id
                JOIN users u ON lp.user_id = u.id
                WHERE pdw.date >= '{$season['season_start_date']}'
                  AND lp.league_id = ?
                  AND lp.status = 'active'
                GROUP BY u.display_name, YEARWEEK(DATE_SUB(pdw.date, INTERVAL 1 DAY), 1)
            ) prev_week 
                ON prev_week.display_name = main.display_name
                AND prev_week.week_num = main.week_num - 1
            WHERE main.week_total - IFNULL(prev_week.end_total, main.week_start_total) >= 0
            ORDER BY main.week_num DESC,
                     (main.week_total - IFNULL(prev_week.end_total, main.week_start_total)) DESC
        ");
        $stmt->execute([$league_id, $league_id]);
        $weeklyRankingsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}


// =====================================================================
// HELPER FUNCTIONS
// =====================================================================

/**
 * Get team logo path from team name
 */
function getTeamLogo($teamName) {
    $logoMap = [
        'Atlanta Hawks'          => 'atlanta_hawks.png',
        'Boston Celtics'         => 'boston_celtics.png',
        'Brooklyn Nets'          => 'brooklyn_nets.png',
        'Charlotte Hornets'      => 'charlotte_hornets.png',
        'Chicago Bulls'          => 'chicago_bulls.png',
        'Cleveland Cavaliers'    => 'cleveland_cavaliers.png',
        'Dallas Mavericks'       => 'dallas_mavericks.png',
        'Denver Nuggets'         => 'denver_nuggets.png',
        'Detroit Pistons'        => 'detroit_pistons.png',
        'Golden State Warriors'  => 'golden_state_warriors.png',
        'Houston Rockets'        => 'houston_rockets.png',
        'Indiana Pacers'         => 'indiana_pacers.png',
        'LA Clippers'            => 'la_clippers.png',
        'Los Angeles Clippers'   => 'la_clippers.png',
        'Los Angeles Lakers'     => 'los_angeles_lakers.png',
        'Memphis Grizzlies'      => 'memphis_grizzlies.png',
        'Miami Heat'             => 'miami_heat.png',
        'Milwaukee Bucks'        => 'milwaukee_bucks.png',
        'Minnesota Timberwolves' => 'minnesota_timberwolves.png',
        'New Orleans Pelicans'   => 'new_orleans_pelicans.png',
        'New York Knicks'        => 'new_york_knicks.png',
        'Oklahoma City Thunder'  => 'oklahoma_city_thunder.png',
        'Orlando Magic'          => 'orlando_magic.png',
        'Philadelphia 76ers'     => 'philadelphia_76ers.png',
        'Phoenix Suns'           => 'phoenix_suns.png',
        'Portland Trail Blazers' => 'portland_trail_blazers.png',
        'Sacramento Kings'       => 'sacramento_kings.png',
        'San Antonio Spurs'      => 'san_antonio_spurs.png',
        'Toronto Raptors'        => 'toronto_raptors.png',
        'Utah Jazz'              => 'utah_jazz.png',
        'Washington Wizards'     => 'washington_wizards.png'
    ];

    if (isset($logoMap[$teamName])) {
        return 'nba-wins-platform/public/assets/team_logos/' . $logoMap[$teamName];
    }

    return 'nba-wins-platform/public/assets/team_logos/' . strtolower(str_replace(' ', '_', $teamName)) . '.png';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?= ($_SESSION['theme_preference'] ?? 'dark') === 'classic' ? '#f5f5f5' : '#121a23' ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Analytics - NBA Wins Platform</title>
    <link rel="apple-touch-icon" type="image/png" href="nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
/* ==========================================================================
   CSS VARIABLES
   ========================================================================== */
:root {
    --bg-primary: #151d28;
    --bg-secondary: #1a222c;
    --bg-card: #202a38;
    --bg-card-hover: #273140;
    --bg-elevated: #2a3446;
    --border-color: rgba(255, 255, 255, 0.08);
    --text-primary: #e6edf3;
    --text-secondary: #8b949e;
    --text-muted: #545d68;
    --accent-blue: #388bfd;
    --accent-blue-dim: rgba(56, 139, 253, 0.15);
    --accent-green: #3fb950;
    --accent-red: #f85149;
    --accent-orange: #d29922;
    --accent-purple: #a371f7;
    --radius-md: 10px;
    --radius-lg: 14px;
    --shadow-card: 0 1px 3px rgba(0, 0, 0, 0.4), 0 0 0 1px var(--border-color);
    --transition-fast: 0.15s ease;
}

<?php if (($_SESSION['theme_preference'] ?? 'dark') === 'classic'): ?>
:root {
    --bg-primary: #f5f5f5;
    --bg-secondary: rgba(245, 245, 245, 0.95);
    --bg-card: #ffffff;
    --bg-card-hover: #f8f9fa;
    --bg-elevated: #f0f0f2;
    --border-color: #e0e0e0;
    --border-subtle: rgba(0, 0, 0, 0.06);
    --text-primary: #333333;
    --text-secondary: #666666;
    --text-muted: #999999;
    --accent-blue: #0066ff;
    --accent-blue-dim: rgba(0, 102, 255, 0.08);
    --accent-blue-glow: rgba(0, 102, 255, 0.15);
    --accent-green: #28a745;
    --accent-green-dim: rgba(40, 167, 69, 0.08);
    --accent-red: #dc3545;
    --accent-red-dim: rgba(220, 53, 69, 0.08);
    --accent-gold: #d4a017;
    --accent-silver: #8a8a8a;
    --accent-bronze: #b5651d;
    --shadow-card: 0 1px 4px rgba(0, 0, 0, 0.08), 0 0 0 1px rgba(0, 0, 0, 0.04);
    --shadow-elevated: 0 4px 16px rgba(0, 0, 0, 0.1), 0 0 0 1px rgba(0, 0, 0, 0.06);
}
body {
    background-image: url('nba-wins-platform/public/assets/background/geometric_white.png');
    background-repeat: repeat;
    background-attachment: fixed;
}
<?php endif; ?>

/* ==========================================================================
   BASE / RESET
   ========================================================================== */
* { margin: 0; padding: 0; box-sizing: border-box; }
html { background: var(--bg-primary); }
body {
    font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
    line-height: 1.5; color: var(--text-primary);
    background: var(--bg-primary);
    background-image: radial-gradient(ellipse at 50% 0%, rgba(56, 139, 253, 0.04) 0%, transparent 60%);
    min-height: 100vh;
    -webkit-font-smoothing: antialiased; padding: 0;
}

/* ==========================================================================
   LAYOUT
   ========================================================================== */
.app-container { max-width: 1000px; margin: 0 auto; padding: 0 12px 2rem; }

/* Desktop grid layout */
@media (min-width: 900px) {
    .app-container { max-width: 1400px; }
    .analytics-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    .analytics-grid > .full-width { grid-column: 1 / -1; }
    .analytics-grid > .section { margin-bottom: 0; }
}

/* Page title */
.page-title {
    font-size: 1.35rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    text-align: center;
    padding: 16px 0 12px;
}

.app-header {
    display: flex; align-items: center; justify-content: center;
    gap: 10px; padding: 16px 16px 12px; position: relative;
}
.app-header-logo { width: 36px; height: 36px; }
.app-header-title { font-size: 1.35rem; font-weight: 700; letter-spacing: -0.02em; }
.app-header-sub { font-size: 0.85rem; color: var(--text-muted); text-align: center; margin-bottom: 12px; }

.nav-toggle-btn {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    width: 38px; height: 38px;
    display: flex; align-items: center; justify-content: center;
    background: var(--bg-card); border: 1px solid var(--border-color);
    border-radius: var(--radius-md); color: var(--text-secondary);
    font-size: 16px; cursor: pointer; transition: all var(--transition-fast);
}
.nav-toggle-btn:hover {
    color: var(--text-primary);
    border-color: rgba(56, 139, 253, 0.3);
    background: var(--accent-blue-dim);
}

/* ==========================================================================
   TABS (Main)
   ========================================================================== */
.tab-navigation { display: flex; justify-content: center; gap: 8px; margin-bottom: 16px; }
.tab-button {
    padding: 10px 24px; background: var(--bg-card);
    border: 1px solid var(--border-color); border-radius: var(--radius-md);
    cursor: pointer; font-family: 'Outfit', sans-serif;
    font-size: 0.9rem; font-weight: 600; color: var(--text-secondary);
    transition: all 0.2s;
}
.tab-button:hover { border-color: rgba(56, 139, 253, 0.3); color: var(--text-primary); }
.tab-button.active { background: var(--accent-blue); color: white; border-color: var(--accent-blue); }
.tab-button i { margin-right: 6px; }
.tab-content { display: none; }
.tab-content.active { display: block; animation: fadeIn 0.3s ease; }
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ==========================================================================
   SECTION CARDS
   ========================================================================== */
.section {
    background: var(--bg-card); padding: 18px;
    border-radius: var(--radius-lg); box-shadow: var(--shadow-card);
    margin-bottom: 14px; position: relative;
    opacity: 0;
}
@keyframes sectionCascade {
    from { opacity: 0; transform: translateY(14px); }
    to   { opacity: 1; transform: translateY(0); }
}
.section.cascade-in {
    animation: sectionCascade 0.4s ease-out forwards;
}
.section-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 12px; padding-bottom: 8px;
    border-bottom: 1px solid var(--border-color);
}
.section-title { display: flex; align-items: center; gap: 8px; flex: 1; }
.section-title h2 { margin: 0; color: var(--text-primary); font-size: 1.15rem; font-weight: 700; }

/* Info Tooltips */
.info-icon {
    cursor: help; color: var(--text-muted); font-size: 0.85rem;
    position: relative; transition: color 0.2s;
}
.info-icon:hover { color: var(--accent-blue); }
.info-tooltip {
    position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%);
    background: var(--bg-elevated); color: var(--text-secondary);
    padding: 10px 14px; border-radius: var(--radius-md);
    font-size: 0.8rem; line-height: 1.4; width: 280px; max-width: 90vw;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.5);
    border: 1px solid var(--border-color); z-index: 1000;
    pointer-events: none; opacity: 0; transition: opacity 0.2s; margin-bottom: 8px;
}
.info-tooltip::after {
    content: ''; position: absolute; top: 100%; left: 50%;
    transform: translateX(-50%); border: 6px solid transparent;
    border-top-color: var(--bg-elevated);
}
.info-icon:hover .info-tooltip { opacity: 1; }

/* ==========================================================================
   VEGAS / DRAFT SUB-TABS
   ========================================================================== */
.vegas-tabs { display: flex; justify-content: center; gap: 8px; margin-bottom: 14px; }
.vegas-tab {
    padding: 8px 20px; background: var(--bg-elevated);
    border: 1px solid var(--border-color); border-radius: var(--radius-md);
    cursor: pointer; font-family: 'Outfit', sans-serif;
    font-size: 0.85rem; font-weight: 600; color: var(--text-secondary);
    transition: all 0.2s;
}
.vegas-tab:hover { border-color: rgba(56, 139, 253, 0.3); color: var(--text-primary); }
.vegas-tab.active { background: var(--accent-blue); color: white; border-color: var(--accent-blue); }
.vegas-content { display: none; }
.vegas-content.active { display: block; }

/* ==========================================================================
   TIME WINDOW BUTTONS
   ========================================================================== */
.time-window-btn {
    padding: 8px 16px; background: var(--bg-elevated);
    border: 1px solid var(--border-color); border-radius: var(--radius-md);
    cursor: pointer; font-family: 'Outfit', sans-serif;
    font-size: 0.85rem; font-weight: 500; color: var(--text-secondary);
    transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px;
}
.time-window-btn:hover { border-color: rgba(56, 139, 253, 0.3); color: var(--text-primary); }
.time-window-btn.active { background: var(--accent-blue); color: white; border-color: var(--accent-blue); }

/* ==========================================================================
   LEADERBOARD TABLES
   ========================================================================== */
.leaderboard-table { width: 100%; border-collapse: collapse; border-radius: var(--radius-md); overflow: hidden; }
.leaderboard-table thead { background: var(--bg-elevated); }
.leaderboard-table th,
.leaderboard-table td { padding: 10px 14px; text-align: left; border-bottom: 1px solid var(--border-color); }
.leaderboard-table thead th {
    font-weight: 600; font-size: 12px; text-transform: uppercase;
    letter-spacing: 0.5px; color: var(--text-muted);
}
.leaderboard-table tbody tr { transition: background 0.15s; }
.leaderboard-table tbody tr:hover { background: var(--bg-card-hover); }

.leaderboard-table .rank-cell {
    font-weight: 700; color: var(--text-primary);
    width: 60px; text-align: center; font-variant-numeric: tabular-nums;
}
.leaderboard-table .rank-container { display: flex; align-items: center; font-size: 15px; }
.leaderboard-table .expand-indicator {
    margin: 0 4px; color: var(--text-muted);
    transition: transform 0.3s; font-size: 11px;
}
.leaderboard-table tr.expanded .expand-indicator { transform: rotate(180deg); }

.leaderboard-table .participant-name { font-weight: 500; color: var(--text-primary); }
.leaderboard-table .participant-name a {
    color: var(--text-primary); text-decoration: none; transition: color 0.2s;
}
.leaderboard-table .participant-name a:hover { color: var(--accent-blue); }
.leaderboard-table .participant-name .league-suffix { display: none; }
.leaderboard-table .league-name { color: var(--text-muted); font-size: 13px; }
.leaderboard-table .total-wins {
    text-align: center; font-size: 16px;
    color: var(--text-primary); font-variant-numeric: tabular-nums;
}
.leaderboard-table .games-played-record { text-align: center !important; vertical-align: middle !important; }

/* Expandable team list */
.leaderboard-table .team-list { display: none; background: var(--bg-secondary); }
.leaderboard-table .expanded-content { padding: 10px 14px; }
.leaderboard-table .inner-table {
    width: 100%; border-collapse: collapse;
    background: var(--bg-card); border-radius: 6px; overflow: hidden;
}
.leaderboard-table .inner-table thead { background: var(--bg-elevated); }
.leaderboard-table .inner-table th { color: var(--text-muted); font-weight: 600; font-size: 12px; padding: 8px 10px; }
.leaderboard-table .inner-table td { padding: 8px 10px; border-bottom: none !important; }
.leaderboard-table .team-name { display: flex; align-items: center; gap: 8px; }
.leaderboard-table .team-name a {
    display: flex; align-items: center; gap: 8px;
    color: var(--text-primary); text-decoration: none;
}
.leaderboard-table .team-name a:hover { color: var(--accent-blue); }
.leaderboard-table .team-logo { width: 22px; height: 22px; object-fit: contain; }
.leaderboard-table .team-wins {
    text-align: right; font-weight: 600;
    color: var(--text-primary); font-variant-numeric: tabular-nums;
}

/* ==========================================================================
   CHART CONTAINER
   ========================================================================== */
.chart-container {
    position: relative; height: 450px; max-width: 100%;
    margin: 16px auto; background: var(--bg-elevated);
    padding: 16px; border-radius: var(--radius-md);
    border: 1px solid var(--border-color);
}

/* ==========================================================================
   HEAD-TO-HEAD
   ========================================================================== */
.h2h-selector {
    display: grid; grid-template-columns: 1fr auto 1fr;
    gap: 12px; align-items: center; margin: 14px 0;
    padding: 16px; background: var(--bg-elevated);
    border-radius: var(--radius-md); border: 1px solid var(--border-color);
}
.h2h-select-container { display: flex; flex-direction: column; gap: 6px; }
.h2h-select-container label { font-weight: 600; color: var(--text-secondary); font-size: 0.85rem; }
.h2h-select-container select {
    padding: 9px; border: 1px solid var(--border-color);
    border-radius: var(--radius-md); font-family: 'Outfit', sans-serif;
    font-size: 0.9rem; background: var(--bg-card); color: var(--text-primary);
    cursor: pointer; transition: border-color 0.2s;
}
.h2h-select-container select:hover { border-color: rgba(56, 139, 253, 0.3); }
.h2h-select-container select:focus { outline: none; border-color: var(--accent-blue); }
.h2h-select-container select option { background: var(--bg-card); color: var(--text-primary); }
.h2h-vs { font-size: 1.3rem; font-weight: 700; color: var(--text-muted); text-align: center; }

.h2h-result {
    margin-top: 14px; padding: 16px; background: var(--bg-elevated);
    border-radius: var(--radius-md); border: 1px solid var(--border-color);
    text-align: center; min-height: 70px;
    display: flex; flex-direction: column; justify-content: center; align-items: center;
}
.h2h-result-text { font-size: 1rem; color: var(--text-secondary); }
.h2h-result-highlight {
    font-size: 1.4rem; font-weight: 700; color: var(--text-primary);
    margin: 8px 0; font-variant-numeric: tabular-nums;
}
.h2h-record-status { font-size: 0.85rem; color: var(--text-muted); font-style: italic; }
.h2h-record-status.winning { color: var(--accent-green); font-weight: 600; }
.h2h-record-status.losing { color: var(--accent-red); font-weight: 600; }

/* ==========================================================================
   WEEKLY RANKINGS
   ========================================================================== */
.weekly-rankings { border-radius: var(--radius-md); }
.weekly-rankings-header { margin-bottom: 14px; display: flex; justify-content: center; }
.weekly-rankings-select {
    padding: 9px 14px; border: 1px solid var(--border-color);
    border-radius: var(--radius-md); font-family: 'Outfit', sans-serif;
    font-size: 0.9rem; background: var(--bg-elevated); color: var(--text-primary);
    cursor: pointer; min-width: 200px; transition: border-color 0.2s;
}
.weekly-rankings-select:hover { border-color: rgba(56, 139, 253, 0.3); }
.weekly-rankings-select:focus { outline: none; border-color: var(--accent-blue); }
.weekly-rankings-list { display: flex; flex-direction: column; gap: 8px; }

.weekly-rankings-item {
    display: grid; grid-template-columns: 44px 1fr auto;
    align-items: center; padding: 12px; border-radius: var(--radius-md);
    transition: transform 0.2s; background: var(--bg-elevated);
    border: 1px solid var(--border-color);
}
.weekly-rankings-item:hover { transform: translateX(3px); }
.weekly-rankings-item.rank-1 { background: rgba(210, 153, 34, 0.12); border-color: rgba(210, 153, 34, 0.2); }
.weekly-rankings-item.rank-2 { background: rgba(139, 148, 158, 0.1); border-color: rgba(139, 148, 158, 0.15); }
.weekly-rankings-item.rank-3 { background: rgba(205, 127, 50, 0.1); border-color: rgba(205, 127, 50, 0.15); }

.weekly-rankings-rank { font-size: 1.3rem; font-weight: 700; color: var(--text-primary); text-align: center; }
.weekly-rankings-name { font-size: 1rem; color: var(--text-primary); }
.weekly-rankings-wins {
    font-size: 1.2rem; font-weight: 700; color: var(--text-primary);
    text-align: right; padding-right: 8px; font-variant-numeric: tabular-nums;
}

/* ==========================================================================
   WIDGET PIN ICON
   ========================================================================== */
.widget-pin-icon {
    position: absolute; top: 12px; right: 12px;
    background: transparent; color: var(--text-muted);
    border: none; border-radius: 4px; width: 28px; height: 28px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 13px; transition: all 0.2s;
    z-index: 10; opacity: 0.5;
}
.widget-pin-icon:hover { opacity: 1; color: var(--accent-blue); background: var(--accent-blue-dim); }
.widget-pin-icon.pinned { color: var(--accent-green); opacity: 0.7; }
.widget-pin-icon.pinned:hover { opacity: 1; background: rgba(63, 185, 80, 0.1); }

/* ==========================================================================
   MOBILE UTILITY
   ========================================================================== */
.hide-mobile { display: table-cell; }
.mobile-owner { display: none; }

/* ==========================================================================
   MOBILE RESPONSIVE
   ========================================================================== */
@media (max-width: 600px) {
    .app-container { padding: 0 8px 2rem; }
    .tab-navigation { flex-wrap: wrap; gap: 4px; }
    .tab-button { padding: 8px 16px; font-size: 0.82rem; }
    .section { padding: 14px; }
    .section-title h2 { font-size: 1rem; }
    .chart-container { height: 320px; padding: 10px; }
    .h2h-selector { grid-template-columns: 1fr; gap: 8px; }
    .hide-mobile { display: none !important; }
    .mobile-owner { display: inline; }

    .leaderboard-table th,
    .leaderboard-table td { padding: 8px 6px; font-size: 12px; }
    .leaderboard-table .rank-cell { width: 40px; }
    .leaderboard-table .total-wins { font-size: 13px; }
    .leaderboard-table .team-logo { width: 18px; height: 18px; }
    .leaderboard-table.platform-leaderboard thead th:nth-child(3) { display: none; }
    .leaderboard-table.platform-leaderboard tbody td.league-name { display: none; }
    .leaderboard-table .participant-name .league-suffix {
        display: block; font-size: 11px; color: var(--text-muted);
        font-weight: normal; margin-top: 2px;
    }
    .time-window-btn { padding: 6px 12px; font-size: 0.78rem; }
    .vegas-tab { padding: 6px 14px; font-size: 0.82rem; }
    .weekly-rankings-select { width: 100%; min-width: unset; }
}

@media (min-width: 601px) {
    .app-container { padding: 0 24px 2rem; }
}
    /* ===== FLOATING PILL NAV ===== */
    .floating-pill {
        position: fixed;
        bottom: 18px;
        left: 50%;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        align-items: center;
        background: rgba(24, 33, 47, 0.82);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 999px;
        padding: 6px;
        box-shadow: 0 4px 24px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.03);
        -webkit-backdrop-filter: blur(20px);
        backdrop-filter: blur(20px);
        -webkit-transform: translateX(-50%) translateZ(0);
        transform: translateX(-50%) translateZ(0);
        will-change: transform;
        transition: border-radius 0.35s ease, padding 0.35s ease;
    }

    .floating-pill.expanded {
        border-radius: 22px;
        padding: 8px;
    }

    .pill-main-row {
        display: flex;
        align-items: center;
        gap: 2px;
    }

    .pill-expanded-row {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        max-height: 0;
        opacity: 0;
        overflow: hidden;
        transition: max-height 0.35s ease, opacity 0.25s ease, margin 0.35s ease, padding 0.35s ease;
        margin-bottom: 0;
        padding: 0 4px;
    }
    .floating-pill.expanded .pill-expanded-row {
        max-height: 60px;
        opacity: 1;
        margin-bottom: 6px;
        padding: 0 4px 6px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    }

    .pill-expanded-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 2px;
        width: 52px;
        height: 44px;
        border-radius: 12px;
        text-decoration: none;
        color: var(--text-muted);
        font-size: 14px;
        transition: all var(--transition-fast);
        cursor: pointer;
        border: none;
        background: none;
        -webkit-tap-highlight-color: transparent;
    }
    .pill-expanded-item span {
        font-size: 9px;
        font-weight: 600;
        font-family: 'Outfit', sans-serif;
        letter-spacing: 0.02em;
        line-height: 1;
        white-space: nowrap;
    }
    .pill-expanded-item:hover {
        color: var(--text-primary);
        background: rgba(255, 255, 255, 0.08);
    }
    .pill-expanded-item.logout-item:hover {
        color: var(--accent-red);
    }

    .pill-menu-btn .fa-bars,
    .pill-menu-btn .fa-xmark { transition: transform 0.3s ease, opacity 0.2s ease; }
    .pill-menu-btn .fa-xmark { position: absolute; opacity: 0; transform: rotate(-90deg); }
    .floating-pill.expanded .pill-menu-btn .fa-bars { opacity: 0; transform: rotate(90deg); }
    .floating-pill.expanded .pill-menu-btn .fa-xmark { opacity: 1; transform: rotate(0deg); }

    body { padding-bottom: 84px; }

    @media (max-width: 600px) {
        .floating-pill {
            bottom: calc(14px + env(safe-area-inset-bottom, 0px));
        }
    }

    .pill-item {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 46px;
        height: 46px;
        border-radius: 999px;
        text-decoration: none;
        color: var(--text-muted);
        font-size: 17px;
        transition: all var(--transition-fast);
        cursor: pointer;
        border: none;
        background: none;
        -webkit-tap-highlight-color: transparent;
        position: relative;
    }

    .pill-item:hover {
        color: var(--text-primary);
        background: var(--bg-elevated);
    }

    .pill-item.active {
        color: white;
        background: var(--accent-blue);
    }

    .pill-item:active {
        transform: scale(0.92);
    }

    .pill-divider {
        width: 1px;
        height: 26px;
        background: var(--border-color);
        flex-shrink: 0;
    }

    @media (min-width: 601px) {
        .pill-item::after {
            content: attr(data-label);
            position: absolute;
            bottom: calc(100% + 8px);
            left: 50%;
            transform: translateX(-50%) scale(0.9);
            background: var(--bg-elevated);
            color: var(--text-primary);
            font-size: 11px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            padding: 4px 10px;
            border-radius: var(--radius-sm);
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: all 0.15s ease;
            border: 1px solid var(--border-color);
        }

        .pill-item:hover::after {
            opacity: 1;
            transform: translateX(-50%) scale(1);
        }

        .floating-pill.expanded .pill-item:hover::after { opacity: 0; }
    }
</style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/components/navigation_menu.php'; ?>

<div class="app-container">

    <!-- ================================================================
         HEADER
         ================================================================ -->
    <div class="page-title"></div>

    <!-- ================================================================
         TAB NAVIGATION
         ================================================================ -->
    <div class="tab-navigation">
        <?php if (!empty($league_id)): ?>
            <button class="tab-button active" onclick="switchTab('league')" id="tab-league">
                <i class="fas fa-users"></i> Your League
            </button>
        <?php endif; ?>
        <button class="tab-button <?= empty($league_id) ? 'active' : '' ?>" onclick="switchTab('platform')" id="tab-platform">
            <i class="fas fa-globe"></i> Platform Wide
        </button>
    </div>


    <!-- ================================================================
         LEAGUE TAB CONTENT
         ================================================================ -->
    <?php if (!empty($league_id)): ?>
    <div id="league-content" class="tab-content active">
    <div class="analytics-grid">

        <!-- ---- Wins Progression Chart ---- -->
        <?php if (!empty($trackingParticipants)): ?>
            <div class="section full-width">
                <div class="section-header">
                    <div class="section-title">
                        <h2><i class="fas fa-chart-line"></i> Wins Progression</h2>
                        <div class="info-icon">
                            <i class="fas fa-question-circle"></i>
                            <div class="info-tooltip">Track total wins over time. Select time windows for short-term trends or full season.</div>
                        </div>
                    </div>
                </div>

                <div style="display: flex; justify-content: center; margin-bottom: 14px; gap: 6px; flex-wrap: wrap">
                    <button onclick="changeTimeWindow(7)" class="time-window-btn <?= $timeWindow === 7 ? 'active' : '' ?>">
                        <i class="fas fa-calendar-week"></i> 7 Days
                    </button>
                    <button onclick="changeTimeWindow(21)" class="time-window-btn <?= $timeWindow === 21 ? 'active' : '' ?>">
                        <i class="fas fa-calendar-alt"></i> 21 Days
                    </button>
                    <button onclick="changeTimeWindow(30)" class="time-window-btn <?= $timeWindow === 30 ? 'active' : '' ?>">
                        <i class="fas fa-calendar"></i> 30 Days
                    </button>
                    <button onclick="changeTimeWindow(0)" class="time-window-btn <?= $timeWindow === 0 ? 'active' : '' ?>">
                        <i class="fas fa-calendar-check"></i> Full Season
                    </button>
                </div>

                <div class="chart-container">
                    <canvas id="winsProgressChart"></canvas>
                </div>
            </div>
        <?php elseif (!empty($league_id)): ?>
            <div class="section full-width">
                <div class="section-header">
                    <div class="section-title">
                        <h2><i class="fas fa-chart-line"></i> Wins Progression</h2>
                    </div>
                </div>
                <p style="text-align: center; color: var(--text-muted); font-style: italic; padding: 20px">
                    No tracking data yet. Data appears once games are recorded.
                </p>
            </div>
        <?php endif; ?>

        <!-- ---- Weekly Win Rankings ---- -->
        <?php if (!empty($weeklyRankingsData)): ?>
            <div class="section full-width">
                <button class="widget-pin-icon <?= in_array('weekly_rankings', $pinned_widgets) ? 'pinned' : '' ?>"
                        onclick="toggleWidgetPin('weekly_rankings', this)">
                    <i class="fas fa-<?= in_array('weekly_rankings', $pinned_widgets) ? 'check' : 'thumbtack' ?>"></i>
                </button>
                <div class="section-header">
                    <div class="section-title">
                        <h2><i class="fas fa-trophy"></i> Weekly Win Rankings</h2>
                        <div class="info-icon">
                            <i class="fas fa-question-circle"></i>
                            <div class="info-tooltip">Who dominated each week. Weeks run Monday through Sunday.</div>
                        </div>
                    </div>
                </div>
                <div id="weekly-tracker-root"></div>
            </div>
        <?php endif; ?>

        <!-- ---- Vegas Zone ---- -->
        <?php if (!empty($overperformers) || !empty($underperformers)): ?>
            <div class="section">
                <button class="widget-pin-icon <?= in_array('vegas_zone', $pinned_widgets) ? 'pinned' : '' ?>"
                        onclick="toggleWidgetPin('vegas_zone', this)">
                    <i class="fas fa-<?= in_array('vegas_zone', $pinned_widgets) ? 'check' : 'thumbtack' ?>"></i>
                </button>
                <div class="section-header">
                    <div class="section-title">
                        <h2><i class="fa-solid fa-dice"></i> The Vegas Zone</h2>
                        <div class="info-icon">
                            <i class="fas fa-question-circle"></i>
                            <div class="info-tooltip">Compare current pace vs Vegas preseason projections.</div>
                        </div>
                    </div>
                </div>

                <div class="vegas-tabs">
                    <button class="vegas-tab active" onclick="switchVegasTab('over')">
                        <i class="fas fa-arrow-trend-up"></i> Over
                    </button>
                    <button class="vegas-tab" onclick="switchVegasTab('under')">
                        <i class="fas fa-arrow-trend-down"></i> Under
                    </button>
                </div>

                <!-- Over-performers -->
                <div id="vegas-over" class="vegas-content active">
                    <?php if (!empty($overperformers)): ?>
                        <table class="leaderboard-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Team</th>
                                    <th>Owner</th>
                                    <th style="text-align: center">Line</th>
                                    <th style="text-align: center">Pace</th>
                                    <th style="text-align: center">Diff</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($overperformers as $i => $team): ?>
                                    <tr>
                                        <td class="rank-cell"><?= $i + 1 ?></td>
                                        <td class="participant-name">
                                            <a href="/nba-wins-platform/stats/team_data.php?team=<?= urlencode($team['team_name']) ?>"
                                               style="display: flex; align-items: center; gap: 8px">
                                                <img src="<?= htmlspecialchars(getTeamLogo($team['team_name'])) ?>"
                                                     class="team-logo" onerror="this.style.opacity='0.3'">
                                                <span><?= htmlspecialchars($team['team_name']) ?></span>
                                            </a>
                                            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px">
                                                <?= $team['current_record'] ?>
                                            </div>
                                        </td>
                                        <td class="participant-name">
                                            <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?= $league_id ?>&user_id=<?= $team['user_id'] ?>">
                                                <?= htmlspecialchars($team['owner']) ?>
                                            </a>
                                        </td>
                                        <td class="total-wins"><strong><?= number_format($team['vegas_projection'], 1) ?></strong></td>
                                        <td class="total-wins"><strong style="color: var(--accent-green)"><?= number_format($team['current_pace'], 1) ?></strong></td>
                                        <td class="total-wins"><strong style="color: var(--accent-green)">+<?= number_format($team['variance'], 1) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="text-align: center; color: var(--text-muted); font-style: italic; padding: 16px">No teams exceeding expectations</p>
                    <?php endif; ?>
                </div>

                <!-- Under-performers -->
                <div id="vegas-under" class="vegas-content">
                    <?php if (!empty($underperformers)): ?>
                        <table class="leaderboard-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Team</th>
                                    <th>Owner</th>
                                    <th style="text-align: center">Line</th>
                                    <th style="text-align: center">Pace</th>
                                    <th style="text-align: center">Diff</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($underperformers as $i => $team): ?>
                                    <tr>
                                        <td class="rank-cell"><?= $i + 1 ?></td>
                                        <td class="participant-name">
                                            <a href="/nba-wins-platform/stats/team_data.php?team=<?= urlencode($team['team_name']) ?>"
                                               style="display: flex; align-items: center; gap: 8px">
                                                <img src="<?= htmlspecialchars(getTeamLogo($team['team_name'])) ?>"
                                                     class="team-logo" onerror="this.style.opacity='0.3'">
                                                <span><?= htmlspecialchars($team['team_name']) ?></span>
                                            </a>
                                            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px">
                                                <?= $team['current_record'] ?>
                                            </div>
                                        </td>
                                        <td class="participant-name">
                                            <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?= $league_id ?>&user_id=<?= $team['user_id'] ?>">
                                                <?= htmlspecialchars($team['owner']) ?>
                                            </a>
                                        </td>
                                        <td class="total-wins"><strong><?= number_format($team['vegas_projection'], 1) ?></strong></td>
                                        <td class="total-wins"><strong style="color: var(--accent-red)"><?= number_format($team['current_pace'], 1) ?></strong></td>
                                        <td class="total-wins"><strong style="color: var(--accent-red)"><?= number_format($team['variance'], 1) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="text-align: center; color: var(--text-muted); font-style: italic; padding: 16px">No teams falling short</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ---- Head-to-Head ---- -->
        <?php if (count($leagueParticipantsForH2H) >= 2): ?>
            <div class="section">
                <div class="section-header">
                    <div class="section-title">
                        <h2><i class="fas fa-users"></i> Head-to-Head</h2>
                        <div class="info-icon">
                            <i class="fas fa-question-circle"></i>
                            <div class="info-tooltip">Compare matchup records. When your teams play opponents' teams, who wins?</div>
                        </div>
                    </div>
                </div>

                <div class="h2h-selector">
                    <div class="h2h-select-container">
                        <label for="participant1">Participant 1:</label>
                        <select id="participant1">
                            <option value="">-- Select --</option>
                            <?php foreach ($leagueParticipantsForH2H as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['display_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="h2h-vs">VS</div>
                    <div class="h2h-select-container">
                        <label for="participant2">Participant 2:</label>
                        <select id="participant2">
                            <option value="">-- Select --</option>
                            <?php foreach ($leagueParticipantsForH2H as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['display_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="h2h-result" id="h2hResult">
                    <div class="h2h-result-text">Select two participants to compare</div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ---- Strength of Schedule ---- -->
        <?php if (!empty($strengthOfSchedule)): ?>
            <div class="section full-width">
                <div class="section-header">
                    <div class="section-title">
                        <h2><i class="fas fa-calendar-check"></i> Strength of Schedule</h2>
                        <div class="info-icon">
                            <i class="fas fa-question-circle"></i>
                            <div class="info-tooltip">Average win % of opponents faced. Higher = tougher schedule.</div>
                        </div>
                    </div>
                </div>

                <table class="leaderboard-table" id="sos-table">
                    <thead>
                        <tr>
                            <th>Participant</th>
                            <th style="text-align: center">Games</th>
                            <th style="text-align: center">Opp Win %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($strengthOfSchedule as $entry): ?>
                            <tr>
                                <td class="participant-name">
                                    <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?= $league_id ?>&user_id=<?= $entry['user_id'] ?>">
                                        <?= htmlspecialchars($entry['display_name']) ?>
                                    </a>
                                </td>
                                <td class="total-wins"><strong><?= $entry['total_games'] ?></strong></td>
                                <td class="games-played-record">
                                    <strong style="color: <?= $entry['opponent_win_pct'] >= 50 ? 'var(--accent-red)' : 'var(--accent-green)' ?>">
                                        <?= number_format($entry['opponent_win_pct'], 1) ?>%
                                    </strong>
                                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px">
                                        <?= $entry['opponent_win_pct'] >= 50 ? 'Tough' : 'Easy' ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </div><!-- /.analytics-grid -->
    </div><!-- /#league-content -->
    <?php endif; ?>


    <!-- ================================================================
         PLATFORM TAB CONTENT
         ================================================================ -->
    <div id="platform-content" class="tab-content <?= empty($league_id) ? 'active' : '' ?>">
    <div class="analytics-grid">

        <!-- ---- Platform Leaderboard ---- -->
        <div class="section full-width">
            <button class="widget-pin-icon <?= in_array('platform_leaderboard', $pinned_widgets) ? 'pinned' : '' ?>"
                    onclick="toggleWidgetPin('platform_leaderboard', this)">
                <i class="fas fa-<?= in_array('platform_leaderboard', $pinned_widgets) ? 'check' : 'thumbtack' ?>"></i>
            </button>
            <div class="section-header">
                <div class="section-title">
                    <h2><i class="fas fa-globe"></i> Top 5 Leaderboard</h2>
                    <div class="info-icon">
                        <i class="fas fa-question-circle"></i>
                        <div class="info-tooltip">Top 5 across all leagues. Click to expand rosters.</div>
                    </div>
                </div>
            </div>

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
                    $rank     = 1;
                    $prevWins = null;
                    $nextRank = 1;

                    foreach ($platform_leaderboard as $index => $entry):
                        if ($prevWins !== null && $entry['total_wins'] < $prevWins) $rank = $nextRank;
                        $prevWins = $entry['total_wins'];
                        $nextRank = $index + 2;
                        $tId      = 'pt-' . $entry['participant_id'];
                    ?>
                        <tr class="expandable-row" onclick="togglePlatformTeams('<?= $tId ?>', this)">
                            <td class="rank-cell">
                                <div class="rank-container">
                                    <?= $rank ?>
                                    <i class="fas fa-chevron-down expand-indicator"></i>
                                    <?php if ($rank === 1 && $entry['total_wins'] > 0): ?>
                                        <i class="fa-solid fa-trophy" style="color: gold; margin-left: 5px"></i>
                                    <?php elseif ($rank === 2): ?>
                                        <i class="fa-solid fa-trophy" style="color: silver; margin-left: 5px"></i>
                                    <?php elseif ($rank === 3): ?>
                                        <i class="fa-solid fa-trophy" style="color: #CD7F32; margin-left: 5px"></i>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="participant-name">
                                <?= htmlspecialchars($entry['display_name']) ?>
                                <span class="league-suffix">(<?= htmlspecialchars($entry['league_name']) ?>)</span>
                            </td>
                            <td class="league-name"><?= htmlspecialchars($entry['league_name']) ?></td>
                            <td class="total-wins"><strong><?= $entry['total_wins'] ?></strong></td>
                        </tr>

                        <!-- Expandable team roster -->
                        <tr class="team-list" id="<?= $tId ?>">
                            <td colspan="4" class="expanded-content">
                                <table class="inner-table">
                                    <thead><tr><th>Team</th><th>Wins</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($entry['teams'] as $team): ?>
                                            <tr>
                                                <td class="team-name">
                                                    <a href="/nba-wins-platform/stats/team_data.php?team=<?= urlencode($team['team_name']) ?>">
                                                        <img src="<?= htmlspecialchars(getTeamLogo($team['team_name'])) ?>"
                                                             class="team-logo" onerror="this.style.opacity='0.3'">
                                                        <span><?= htmlspecialchars($team['team_name']) ?></span>
                                                    </a>
                                                </td>
                                                <td class="team-wins"><?= $team['wins'] ?></td>
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

        <!-- ---- Draft Value Analysis ---- -->
        <?php if (!empty($bestDraftSteals) || !empty($worstDraftPicks)): ?>
            <div class="section full-width">
                <button class="widget-pin-icon <?= in_array('draft_steals', $pinned_widgets) ? 'pinned' : '' ?>"
                        onclick="toggleWidgetPin('draft_steals', this)">
                    <i class="fas fa-<?= in_array('draft_steals', $pinned_widgets) ? 'check' : 'thumbtack' ?>"></i>
                </button>
                <div class="section-header">
                    <div class="section-title">
                        <h2><i class="fas fa-chart-line"></i> Draft Value Analysis</h2>
                        <div class="info-icon">
                            <i class="fas fa-question-circle"></i>
                            <div class="info-tooltip">Compare teams to draft round averages. Later steals score higher, early busts penalized more.</div>
                        </div>
                    </div>
                </div>

                <div class="vegas-tabs draft-tabs">
                    <button class="vegas-tab active" onclick="switchDraftTab('steals')">
                        <i class="fas fa-gem"></i> Steals
                    </button>
                    <button class="vegas-tab" onclick="switchDraftTab('busts')">
                        <i class="fas fa-arrow-trend-down"></i> Busts
                    </button>
                </div>

                <!-- Steals Table -->
                <div id="draft-steals" style="display: block">
                    <?php if (!empty($bestDraftSteals)): ?>
                        <table class="leaderboard-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Team</th>
                                    <th class="hide-mobile">Owner / League</th>
                                    <th style="text-align: center">Rnd</th>
                                    <th style="text-align: center">Wins</th>
                                    <th class="hide-mobile" style="text-align: center">Avg</th>
                                    <th style="text-align: center">Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bestDraftSteals as $steal): ?>
                                    <tr>
                                        <td class="rank-cell"><?= $steal['rank'] ?></td>
                                        <td class="participant-name">
                                            <a href="/nba-wins-platform/stats/team_data.php?team=<?= urlencode($steal['team_name']) ?>"
                                               style="display: flex; align-items: center; gap: 8px">
                                                <img src="<?= htmlspecialchars(getTeamLogo($steal['team_name'])) ?>"
                                                     class="team-logo" style="width: 22px; height: 22px"
                                                     onerror="this.style.opacity='0.3'">
                                                <span><?= htmlspecialchars($steal['team_name']) ?></span>
                                            </a>
                                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px">
                                                Pick #<?= $steal['pick_number'] ?>
                                                <span class="mobile-owner"> • <?= htmlspecialchars($steal['owner_name']) ?></span>
                                            </div>
                                        </td>
                                        <td class="participant-name hide-mobile">
                                            <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?= $steal['league_id'] ?>&user_id=<?= $steal['user_id'] ?>">
                                                <?= htmlspecialchars($steal['owner_name']) ?>
                                            </a>
                                            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px">
                                                <?= htmlspecialchars($steal['league_name']) ?>
                                            </div>
                                        </td>
                                        <td class="total-wins"><strong><?= $steal['round_number'] ?></strong></td>
                                        <td class="total-wins"><strong style="color: var(--accent-green)"><?= $steal['actual_wins'] ?></strong></td>
                                        <td class="total-wins hide-mobile"><strong><?= $steal['round_avg_wins'] ?></strong></td>
                                        <td class="total-wins">
                                            <strong style="color: <?= $steal['grade_color'] ?>; font-size: 1.1em">
                                                +<?= number_format($steal['steal_score'], 2) ?>
                                            </strong>
                                            <div style="font-size: 0.65rem; color: <?= $steal['grade_color'] ?>; margin-top: 2px; font-weight: 700">
                                                <?= $steal['steal_grade'] ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div style="padding: 2rem; text-align: center; color: var(--text-muted)">No draft steals data</div>
                    <?php endif; ?>
                </div>

                <!-- Busts Table -->
                <div id="draft-busts" style="display: none">
                    <?php if (!empty($worstDraftPicks)): ?>
                        <table class="leaderboard-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Team</th>
                                    <th class="hide-mobile">Owner / League</th>
                                    <th style="text-align: center">Rnd</th>
                                    <th style="text-align: center">Wins</th>
                                    <th class="hide-mobile" style="text-align: center">Avg</th>
                                    <th style="text-align: center">Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($worstDraftPicks as $bust): ?>
                                    <tr>
                                        <td class="rank-cell"><?= $bust['rank'] ?></td>
                                        <td class="participant-name">
                                            <a href="/nba-wins-platform/stats/team_data.php?team=<?= urlencode($bust['team_name']) ?>"
                                               style="display: flex; align-items: center; gap: 8px">
                                                <img src="<?= htmlspecialchars(getTeamLogo($bust['team_name'])) ?>"
                                                     class="team-logo" style="width: 22px; height: 22px"
                                                     onerror="this.style.opacity='0.3'">
                                                <span><?= htmlspecialchars($bust['team_name']) ?></span>
                                            </a>
                                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px">
                                                Pick #<?= $bust['pick_number'] ?>
                                                <span class="mobile-owner"> • <?= htmlspecialchars($bust['owner_name']) ?></span>
                                            </div>
                                        </td>
                                        <td class="participant-name hide-mobile">
                                            <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?= $bust['league_id'] ?>&user_id=<?= $bust['user_id'] ?>">
                                                <?= htmlspecialchars($bust['owner_name']) ?>
                                            </a>
                                            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px">
                                                <?= htmlspecialchars($bust['league_name']) ?>
                                            </div>
                                        </td>
                                        <td class="total-wins"><strong><?= $bust['round_number'] ?></strong></td>
                                        <td class="total-wins"><strong style="color: var(--accent-red)"><?= $bust['actual_wins'] ?></strong></td>
                                        <td class="total-wins hide-mobile"><strong><?= $bust['round_avg_wins'] ?></strong></td>
                                        <td class="total-wins">
                                            <strong style="color: <?= $bust['grade_color'] ?>; font-size: 1.1em">
                                                <?= number_format($bust['bust_score'], 2) ?>
                                            </strong>
                                            <div style="font-size: 0.65rem; color: <?= $bust['grade_color'] ?>; margin-top: 2px; font-weight: 700">
                                                <?= $bust['bust_grade'] ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div style="padding: 2rem; text-align: center; color: var(--text-muted)">No draft busts data</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    </div><!-- /.analytics-grid -->
    </div><!-- /#platform-content -->

</div><!-- /.app-container -->


<!-- ====================================================================
     JAVASCRIPT - Core UI Functions
     ==================================================================== -->
<script>
// --- Tab Switching ---
function cascadeSections(container) {
    if (!container) return;
    container.querySelectorAll('.section').forEach(function(s, i) {
        s.classList.remove('cascade-in');
        s.style.animation = '';
        s.offsetHeight; // force reflow
        s.classList.add('cascade-in');
        s.style.animationDelay = (i * 80) + 'ms';
    });
}

function switchTab(tab) {
    document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

    let contentEl;
    if (tab === 'league') {
        document.getElementById('tab-league')?.classList.add('active');
        contentEl = document.getElementById('league-content');
    } else {
        document.getElementById('tab-platform').classList.add('active');
        contentEl = document.getElementById('platform-content');
    }
    if (contentEl) {
        contentEl.classList.add('active');
        cascadeSections(contentEl);
    }

    localStorage.setItem('analytics_active_tab', tab);
}

window.addEventListener('DOMContentLoaded', () => {
    const s = localStorage.getItem('analytics_active_tab');
    if (s && document.getElementById('tab-' + s)) switchTab(s);
    // Cascade the initially active tab
    const activeTab = document.querySelector('.tab-content.active');
    if (activeTab) cascadeSections(activeTab);
});

// --- Vegas Sub-Tab Switching ---
function switchVegasTab(tab) {
    document.querySelectorAll('.vegas-tabs:not(.draft-tabs) .vegas-tab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.vegas-content:not(#draft-steals):not(#draft-busts)').forEach(c => c.classList.remove('active'));

    const tabs = document.querySelectorAll('.vegas-tabs:not(.draft-tabs) .vegas-tab');
    tabs[tab === 'over' ? 0 : 1]?.classList.add('active');
    document.getElementById('vegas-' + tab)?.classList.add('active');
}

// --- Draft Sub-Tab Switching ---
function switchDraftTab(tab) {
    document.querySelectorAll('.draft-tabs .vegas-tab').forEach(b => b.classList.remove('active'));

    const stealsEl = document.getElementById('draft-steals');
    const bustsEl  = document.getElementById('draft-busts');
    if (stealsEl) stealsEl.style.display = 'none';
    if (bustsEl) bustsEl.style.display = 'none';

    if (tab === 'steals') {
        if (stealsEl) stealsEl.style.display = 'block';
        document.querySelectorAll('.draft-tabs .vegas-tab')[0]?.classList.add('active');
    } else {
        if (bustsEl) bustsEl.style.display = 'block';
        document.querySelectorAll('.draft-tabs .vegas-tab')[1]?.classList.add('active');
    }
}

// --- Platform Leaderboard Expand ---
function togglePlatformTeams(id, row) {
    const t = document.getElementById(id);
    const e = row.classList.contains('expanded');

    if (e) {
        t.style.display = 'none';
        row.classList.remove('expanded');
    } else {
        t.style.display = 'table-row';
        row.classList.add('expanded');
    }
}

// --- Time Window ---
function changeTimeWindow(d) {
    const u = new URL(window.location.href);
    u.searchParams.set('time_window', d);
    window.location.href = u.toString();
}

// --- Widget Pin Toggle ---
function toggleWidgetPin(w, b) {
    const p = b.classList.contains('pinned');
    if (!p && !confirm('Pin to homepage?')) return;

    const fd = new FormData();
    fd.append('action', p ? 'unpin' : 'pin');
    fd.append('widget_type', w);

    fetch('/nba-wins-platform/core/handle_widget_pin.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert(d.message);
            window.location.reload();
        } else {
            alert('Error: ' + d.error);
        }
    })
    .catch(() => alert('Error. Try again.'));
}

// --- Mobile Tooltip Click Support ---
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.info-icon').forEach(icon => {
        icon.addEventListener('click', function (e) {
            e.stopPropagation();
            document.querySelectorAll('.info-icon').forEach(o => {
                if (o !== icon) o.classList.remove('active');
            });
            this.classList.toggle('active');
        });
    });

    document.addEventListener('click', () => {
        document.querySelectorAll('.info-icon').forEach(i => i.classList.remove('active'));
    });
});
</script>


<!-- ====================================================================
     JAVASCRIPT - Chart.js Wins Progression
     ==================================================================== -->
<script>
<?php if (!empty($league_id) && !empty($trackingParticipants)): ?>
const trackingDates        = <?= json_encode($trackingDates) ?>;
const trackingParticipants = <?= json_encode($trackingParticipants) ?>;
const trackingChartData    = <?= json_encode($trackingData) ?>;

// Calculate min/max for Y-axis range
let allWins = [];
Object.values(trackingChartData).forEach(d => { allWins = allWins.concat(d); });
const minWins = Math.min(...allWins);
const maxWins = Math.max(...allWins);

// Generate distinct colors
const generateColor = (i, t) => `hsl(${(i * 360 / t) % 360}, 70%, 55%)`;
const colors = trackingParticipants.map((_, i) => generateColor(i, trackingParticipants.length));

// Build chart datasets
const datasets = trackingParticipants.map((p, i) => ({
    label: p,
    data: trackingChartData[p],
    borderColor: colors[i],
    backgroundColor: colors[i] + '20',
    fill: false,
    tension: 0,
    borderWidth: 2,
    pointRadius: 3,
    pointHoverRadius: 5
}));

// Responsive chart options
function getChartOptions() {
    const m = window.innerWidth < 768;
    return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            title: { display: false },
            legend: {
                position: 'top',
                labels: {
                    boxWidth: m ? 10 : 40,
                    padding: m ? 6 : 10,
                    font: { size: m ? 10 : 12 },
                    color: '#8b949e'
                }
            },
            tooltip: {
                mode: 'index',
                intersect: false,
                backgroundColor: '#1c2333',
                titleColor: '#e6edf3',
                bodyColor: '#8b949e',
                borderColor: 'rgba(255,255,255,0.06)',
                borderWidth: 1,
                itemSort: (a, b) => b.parsed.y - a.parsed.y,
                callbacks: {
                    title: ctx => {
                        const [y,m,day] = ctx[0].label.split('-'); const d = new Date(y, m-1, day);
                        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                    }
                }
            }
        },
        scales: {
            y: {
                min: Math.max(0, minWins - 5),
                max: maxWins + 5,
                title: {
                    display: !m,
                    text: 'Total Wins',
                    font: { size: 13, weight: 'bold' },
                    color: '#8b949e'
                },
                ticks: { font: { size: m ? 10 : 12 }, stepSize: 1, color: '#545d68' },
                grid: { color: 'rgba(255,255,255,0.04)' }
            },
            x: {
                grid: { color: 'rgba(255,255,255,0.04)' },
                ticks: {
                    maxRotation: 45,
                    minRotation: 45,
                    font: { size: m ? 8 : 10 },
                    padding: 8,
                    color: '#545d68',
                    callback: function (v) {
                        const lbl = this.getLabelForValue(v); const [y,m,day] = lbl.split('-'); const d = new Date(y, m-1, day);
                        return (d.getMonth() + 1) + '/' + d.getDate();
                    }
                }
            }
        },
        interaction: { mode: 'nearest', axis: 'x', intersect: false },
        layout: { padding: { bottom: 30 } }
    };
}

// Initialize chart
const ctx = document.getElementById('winsProgressChart');
if (ctx) {
    const wc = new Chart(ctx, {
        type: 'line',
        data: { labels: trackingDates, datasets: datasets },
        options: getChartOptions()
    });

    // Responsive resize handler
    let rt;
    window.addEventListener('resize', () => {
        clearTimeout(rt);
        rt = setTimeout(() => {
            wc.options = getChartOptions();
            wc.update();
        }, 250);
    });
}
<?php endif; ?>
</script>


<!-- ====================================================================
     JAVASCRIPT - Head-to-Head Logic
     ==================================================================== -->
<script>
<?php if (!empty($league_id) && count($leagueParticipantsForH2H) >= 2): ?>
const h2hData         = <?= json_encode($h2hRecords) ?>;
const intraTeamData   = <?= json_encode($intraTeamRecords) ?>;
const participantsData = <?= json_encode($leagueParticipantsForH2H) ?>;

const participantNames = {};
participantsData.forEach(p => { participantNames[p.id] = p.display_name; });

function updateH2HResult() {
    const p1 = document.getElementById('participant1').value;
    const p2 = document.getElementById('participant2').value;
    const r  = document.getElementById('h2hResult');

    if (!p1 || !p2) {
        r.innerHTML = '<div class="h2h-result-text">Select two participants to compare</div>';
        return;
    }

    const n1 = participantNames[p1];
    const n2 = participantNames[p2];

    // Same participant: show intra-team games
    if (p1 === p2) {
        const ir = intraTeamData[p1];
        const tg = ir ? ir.total_games : 0;

        r.innerHTML = tg === 0
            ? `<div class="h2h-result-text"><strong>${n1}</strong> has no intra-team games yet</div>`
            : `<div class="h2h-result-text"><strong>${n1}</strong>'s teams vs each other</div>
               <div class="h2h-result-highlight">${tg} intra-team ${tg === 1 ? 'game' : 'games'}</div>
               <div class="h2h-record-status">Each game counts as both a win and loss</div>`;
        return;
    }

    // Different participants: show H2H record
    const rec = h2hData[p1] && h2hData[p1][p2] ? h2hData[p1][p2] : { wins: 0, losses: 0 };
    const w = rec.wins || 0;
    const l = rec.losses || 0;
    const t = w + l;

    if (t === 0) {
        r.innerHTML = `<div class="h2h-result-text"><strong>${n1}</strong> and <strong>${n2}</strong> haven't faced each other yet</div>`;
        return;
    }

    let sc = '', st = '';
    if (w > l)      { sc = 'winning'; st = 'Winning record'; }
    else if (w < l) { sc = 'losing';  st = 'Losing record'; }
    else            { st = 'Tied'; }

    r.innerHTML = `<div class="h2h-result-text"><strong>${n1}</strong> vs <strong>${n2}</strong></div>
                   <div class="h2h-result-highlight">${w}-${l}</div>
                   <div class="h2h-record-status ${sc}">${st}</div>`;
}

document.getElementById('participant1').addEventListener('change', updateH2HResult);
document.getElementById('participant2').addEventListener('change', updateH2HResult);
<?php endif; ?>
</script>


<!-- ====================================================================
     JAVASCRIPT - Weekly Win Tracker (React)
     ==================================================================== -->
<script type="text/babel">
<?php if (!empty($league_id) && !empty($weeklyRankingsData)): ?>
const WeeklyWinTracker = () => {
    const data = <?= json_encode($weeklyRankingsData) ?>;

    const weeklyData = React.useMemo(() => {
        const weeks = {};

        data.forEach(r => {
            const wn = r.week_num;
            if (!weeks[wn]) {
                weeks[wn] = { weekNum: wn, label: r.week_label, participants: [] };
            }
            weeks[wn].participants.push({ name: r.display_name, wins: parseInt(r.weekly_wins) });
        });

        return Object.values(weeks).map(w => {
            const sorted = w.participants.sort((a, b) => b.wins - a.wins);
            let rank = 1, prev = null, next = 1;

            const ranked = sorted.map((p, i) => {
                if (prev !== null && p.wins < prev) rank = next;
                prev = p.wins;
                next = i + 2;
                return { ...p, rank };
            });

            return { ...w, participants: ranked };
        }).sort((a, b) => b.weekNum - a.weekNum);
    }, [data]);

    const [selectedWeek, setSelectedWeek] = React.useState(
        weeklyData.length > 0 ? weeklyData[0].weekNum : null
    );

    const selectedWeekData = weeklyData.find(w => w.weekNum === selectedWeek) || weeklyData[0];

    return React.createElement('div', { className: 'weekly-rankings' },
        React.createElement('div', { className: 'weekly-rankings-header' },
            React.createElement('select', {
                value: selectedWeek,
                onChange: e => setSelectedWeek(parseInt(e.target.value)),
                className: 'weekly-rankings-select'
            },
                weeklyData.map(w =>
                    React.createElement('option', { key: w.weekNum, value: w.weekNum }, w.label)
                )
            )
        ),
        React.createElement('div', { className: 'weekly-rankings-list' },
            selectedWeekData?.participants.map(p =>
                React.createElement('div', {
                    key: p.name,
                    className: `weekly-rankings-item rank-${p.rank}`
                },
                    React.createElement('div', { className: 'weekly-rankings-rank' }, p.rank),
                    React.createElement('div', { className: 'weekly-rankings-name' }, p.name),
                    React.createElement('div', { className: 'weekly-rankings-wins' }, p.wins)
                )
            )
        )
    );
};

const wc = document.getElementById('weekly-tracker-root');
if (wc) ReactDOM.createRoot(wc).render(React.createElement(WeeklyWinTracker));
<?php endif; ?>
</script>
    <!-- Floating Pill Navigation -->
    <nav class="floating-pill" id="floatingPill">
        <!-- Expanded row (hidden until menu tap) -->
        <div class="pill-expanded-row" id="pillExpandedRow">
            <a href="/nba_standings.php" class="pill-expanded-item">
                <i class="fas fa-basketball-ball"></i>
                <span>Standings</span>
            </a>
            <a href="/draft_summary.php" class="pill-expanded-item">
                <i class="fas fa-file-alt"></i>
                <span>Draft</span>
            </a>
            <a href="https://buymeacoffee.com/taylorstvns" target="_blank" class="pill-expanded-item">
                <i class="fas fa-mug-hot"></i>
                <span>Tip Jar</span>
            </a>
            <?php if (empty($is_guest)): ?>
            <a href="/nba-wins-platform/auth/logout.php" class="pill-expanded-item logout-item">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
            <?php endif; ?>
        </div>
        <!-- Main row -->
        <div class="pill-main-row">
            <a href="/index.php" class="pill-item" data-label="Home">
                <i class="fas fa-home"></i>
            </a>
            <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?php echo $currentLeagueId ?? ($_SESSION['current_league_id'] ?? 0); ?>&user_id=<?php echo $profileUserId ?? ($_SESSION['user_id'] ?? 0); ?>" class="pill-item" data-label="Profile">
                <i class="fas fa-user"></i>
            </a>
            <a href="/analytics.php" class="pill-item active" data-label="Analytics">
                <i class="fas fa-chart-line"></i>
            </a>
            <a href="/claudes-column.php" class="pill-item" data-label="Column" style="position:relative">
                <i class="fa-solid fa-newspaper"></i>
                <?php if ($hasNewArticles): ?><span style="position:absolute;top:2px;right:2px;width:7px;height:7px;background:#f85149;border-radius:50%;box-shadow:0 0 4px rgba(248,81,73,0.5)"></span><?php endif; ?>
            </a>
            <div class="pill-divider"></div>
            <button class="pill-item pill-menu-btn" data-label="Menu" onclick="togglePillMenu()">
                <i class="fas fa-bars"></i>
                <i class="fas fa-xmark"></i>
            </button>
        </div>
    </nav>
    <script>
    function togglePillMenu() {
        document.getElementById('floatingPill').classList.toggle('expanded');
    }
    document.addEventListener('click', function(e) {
        var pill = document.getElementById('floatingPill');
        if (pill.classList.contains('expanded') && !pill.contains(e.target)) {
            pill.classList.remove('expanded');
        }
    });
    </script>
</body>
</html>