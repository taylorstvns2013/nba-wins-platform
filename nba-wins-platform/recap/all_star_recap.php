<?php
// Set timezone to EST
date_default_timezone_set('America/New_York');

// Load database connection and authentication
require_once '/data/www/default/nba-wins-platform/config/db_connection.php';

// Require authentication - redirect to login if not authenticated
requireAuthentication($auth);

// Get current league context
$leagueContext = getCurrentLeagueContext($auth);
if (!$leagueContext || !$leagueContext['league_id']) {
    die('Error: No league selected. Please contact administrator.');
}

$currentLeagueId = $leagueContext['league_id'];
$seasonStartDate = '2025-10-21';

// Team logo mapping function - maps team names to actual logo filenames
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
        return '/nba-wins-platform/public/assets/team_logos/' . $logoMap[$teamName];
    }
    
    $filename = strtolower(str_replace(' ', '_', $teamName)) . '.png';
    return '/nba-wins-platform/public/assets/team_logos/' . $filename;
}

// Profile photo helper function
function getProfilePhotoUrl($user_id, $profile_photo) {
    if ($profile_photo && file_exists($_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/public/assets/profile_photos/' . $profile_photo)) {
        return '/nba-wins-platform/public/assets/profile_photos/' . $profile_photo;
    }
    // Return default avatar
    return 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMjAiIGZpbGw9IiNFNUU3RUIiLz4KPHBhdGggZD0iTTIwIDIyQzIzLjMxMzcgMjIgMjYgMTkuMzEzNyAyNiAxNkMyNiAxMi42ODYzIDIzLjMxMzcgMTAgMjAgMTBDMTYuNjg2MyAxMCAxNCAxMi42ODYzIDE0IDE2QzE0IDE5LjMxMzcgMTYuNjg2MyAyMiAyMCAyMloiIGZpbGw9IiM5Q0EzQUYiLz4KPHBhdGggZD0iTTI4IDMwQzI4IDI1LjU4MTcgMjQuNDE4MyAyMiAyMCAyMkMxNS41ODE3IDIyIDEyIDI1LjU4MTcgMTIgMzBIMjhaIiBmaWxsPSIjOUNBM0FGIi8+Cjwvc3ZnPgo=';
}

try {
    // =====================================================================
    // CURRENT LEADERS - Get leaders for current league
    // =====================================================================
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            COALESCE(u.display_name, lp.participant_name) as name,
            lpw.total_wins,
            u.id as user_id,
            u.profile_photo
        FROM league_participant_daily_wins lpw
        JOIN league_participants lp ON lpw.league_participant_id = lp.id
        LEFT JOIN users u ON lp.user_id = u.id
        WHERE lpw.date = (
            SELECT MAX(date) 
            FROM league_participant_daily_wins lpw2
            JOIN league_participants lp2 ON lpw2.league_participant_id = lp2.id
            WHERE lp2.league_id = ?
        )
        AND lp.league_id = ?
        ORDER BY lpw.total_wins DESC
    ");
    $stmt->execute([$currentLeagueId, $currentLeagueId]);
    
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Find highest score
    $highestScore = $participants[0]['total_wins'];
    
    // Get leaders (handle ties)
    $leaders = array_filter($participants, function($p) use ($highestScore) {
        return $p['total_wins'] == $highestScore;
    });

    // Now get teams for each leader with proper logo paths
    $currentLeaders = [];
    foreach ($leaders as $leader) {
        $stmt = $pdo->prepare("
            SELECT 
                nt.name,
                nt.logo_filename,
                t.win,
                t.loss,
                CONCAT(t.win, '-', t.loss) as record,
                COALESCE(dp.pick_number, 999) as draft_pick_number
            FROM league_participants lp
            JOIN users u ON lp.user_id = u.id
            JOIN league_participant_teams lpt ON lp.id = lpt.league_participant_id
            JOIN nba_teams nt ON lpt.team_name = nt.name
            JOIN 2025_2026 t ON nt.name = t.name
            LEFT JOIN draft_picks dp ON (
                lp.id = dp.league_participant_id 
                AND dp.team_id = nt.id
                AND dp.draft_session_id = (
                    SELECT id FROM draft_sessions 
                    WHERE league_id = ? AND status = 'completed' 
                    ORDER BY created_at DESC LIMIT 1
                )
            )
            WHERE COALESCE(u.display_name, lp.participant_name) = ?
            AND lp.league_id = ?
            ORDER BY COALESCE(dp.pick_number, 999) ASC
        ");
        
        $stmt->execute([$currentLeagueId, $leader['name'], $currentLeagueId]);
        $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $currentLeaders[] = [
            'name' => $leader['name'],
            'total_wins' => $leader['total_wins'],
            'teams' => $teams,
            'user_id' => $leader['user_id'] ?? null,
            'profile_photo' => $leader['profile_photo'] ?? null
        ];
    }

    // =====================================================================
    // DAYS AT #1 - For current league - FIXED to exclude day 0 ties
    // =====================================================================
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(u.display_name, lp.participant_name) as name,
            COUNT(*) as days_in_first,
            u.id as user_id,
            u.profile_photo
        FROM league_participant_daily_wins lpw1
        JOIN league_participants lp ON lpw1.league_participant_id = lp.id
        LEFT JOIN users u ON lp.user_id = u.id
        WHERE lp.league_id = ?
        AND lpw1.date >= ?
        AND lpw1.total_wins > 0
        AND NOT EXISTS (
            SELECT 1 
            FROM league_participant_daily_wins lpw2 
            JOIN league_participants lp2 ON lpw2.league_participant_id = lp2.id
            WHERE lpw2.date = lpw1.date 
            AND lp2.league_id = ?
            AND lpw2.total_wins > lpw1.total_wins
        )
        GROUP BY lp.id, u.display_name, lp.participant_name, u.id, u.profile_photo
        ORDER BY days_in_first DESC
    ");
    $stmt->execute([$currentLeagueId, $seasonStartDate, $currentLeagueId]);
    $daysInFirst = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // =====================================================================
    // BEST WEEKLY PERFORMANCES - MATCHES analytics.php calculation
    // Calculate wins gained from END of previous week to END of current week
    // Weeks defined as Monday-Sunday using YEARWEEK with mode 1
    // =====================================================================
    
    $stmt = $pdo->prepare("
        SELECT 
            main.display_name as participant_name,
            main.week_num,
            CONCAT('Week of ', main.monday_date) as week_label,
            main.week_total - IFNULL(prev_week.end_total, main.week_start_total) as weekly_wins,
            main.user_id,
            main.profile_photo,
            main.monday_date as week_start
        FROM (
            SELECT 
                COALESCE(u.display_name, lp.participant_name) as display_name,
                lp.id as participant_id,
                YEARWEEK(DATE_SUB(pdw.date, INTERVAL 1 DAY), 1) as week_num,
                DATE_FORMAT(
                    MIN(DATE_SUB(pdw.date, INTERVAL DAYOFWEEK(pdw.date)-2 DAY)),
                    '%m/%d'
                ) as monday_date,
                MIN(pdw.total_wins) as week_start_total,
                MAX(pdw.total_wins) as week_total,
                MIN(pdw.date) as week_start_date,
                u.id as user_id,
                u.profile_photo
            FROM league_participant_daily_wins pdw
            JOIN league_participants lp ON pdw.league_participant_id = lp.id
            LEFT JOIN users u ON lp.user_id = u.id
            WHERE pdw.date >= ?
                AND lp.league_id = ?
                AND lp.status = 'active'
            GROUP BY 
                lp.id,
                u.display_name,
                lp.participant_name,
                YEARWEEK(DATE_SUB(pdw.date, INTERVAL 1 DAY), 1),
                u.id,
                u.profile_photo
        ) main
        LEFT JOIN (
            SELECT 
                lp.id as participant_id,
                YEARWEEK(DATE_SUB(pdw.date, INTERVAL 1 DAY), 1) as week_num,
                MAX(pdw.total_wins) as end_total
            FROM league_participant_daily_wins pdw
            JOIN league_participants lp ON pdw.league_participant_id = lp.id
            WHERE pdw.date >= ?
                AND lp.league_id = ?
                AND lp.status = 'active'
            GROUP BY 
                lp.id,
                YEARWEEK(DATE_SUB(pdw.date, INTERVAL 1 DAY), 1)
        ) prev_week ON prev_week.participant_id = main.participant_id 
            AND prev_week.week_num = main.week_num - 1
        WHERE main.week_total - IFNULL(prev_week.end_total, main.week_start_total) >= 0
        ORDER BY 
            (main.week_total - IFNULL(prev_week.end_total, main.week_start_total)) DESC,
            main.week_start_date DESC
        LIMIT 30
    ");
    $stmt->execute([$seasonStartDate, $currentLeagueId, $seasonStartDate, $currentLeagueId]);
    $allWeeks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Store user info for later use
    $userPhotoMap = [];
    foreach ($allWeeks as $week) {
        if (!isset($userPhotoMap[$week['participant_name']])) {
            $userPhotoMap[$week['participant_name']] = [
                'user_id' => $week['user_id'],
                'profile_photo' => $week['profile_photo']
            ];
        }
    }
    
    // Group by wins count
    $consolidatedPerformances = [];
    foreach ($allWeeks as $week) {
        $wins = $week['weekly_wins'];
        $name = $week['participant_name'];
        
        if (!isset($consolidatedPerformances[$wins])) {
            $consolidatedPerformances[$wins] = [];
        }
        
        if (!isset($consolidatedPerformances[$wins][$name])) {
            $consolidatedPerformances[$wins][$name] = [
                'count' => 0,
                'dates' => [],
                'user_id' => $week['user_id'],
                'profile_photo' => $week['profile_photo']
            ];
        }
        
        $consolidatedPerformances[$wins][$name]['count']++;
        $consolidatedPerformances[$wins][$name]['dates'][] = $week['week_label'];
    }
    
    // Sort by wins descending
    krsort($consolidatedPerformances);
    
    // Keep only top 3 win levels
    $consolidatedPerformances = array_slice($consolidatedPerformances, 0, 3, true);
    
    // =====================================================================
    // BEST MONTHLY PERFORMANCES - For current league
    // =====================================================================
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(u.display_name, lp.participant_name) as participant_name,
            DATE_FORMAT(MIN(lpw.date), '%M %Y') as month_label,
            (MAX(lpw.total_wins) - MIN(lpw.total_wins)) as monthly_wins,
            u.id as user_id,
            u.profile_photo
        FROM league_participant_daily_wins lpw
        JOIN league_participants lp ON lpw.league_participant_id = lp.id
        LEFT JOIN users u ON lp.user_id = u.id
        WHERE lpw.date >= ? AND lp.league_id = ?
        GROUP BY 
            lp.id,
            u.display_name,
            lp.participant_name,
            YEAR(lpw.date),
            MONTH(lpw.date),
            u.id,
            u.profile_photo
        HAVING monthly_wins > 0
        ORDER BY monthly_wins DESC
    ");
    $stmt->execute([$seasonStartDate, $currentLeagueId]);
    
    $bestMonths = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group and consolidate monthly performances
    $consolidatedMonths = [];
    foreach ($bestMonths as $month) {
        $wins = $month['monthly_wins'];
        $name = $month['participant_name'];
        
        if (!isset($consolidatedMonths[$wins])) {
            $consolidatedMonths[$wins] = [];
        }
        
        if (!isset($consolidatedMonths[$wins][$name])) {
            $consolidatedMonths[$wins][$name] = [
                'count' => 0,
                'months' => [],
                'user_id' => $month['user_id'],
                'profile_photo' => $month['profile_photo']
            ];
        }
        
        $consolidatedMonths[$wins][$name]['count']++;
        $consolidatedMonths[$wins][$name]['months'][] = $month['month_label'];
    }
    krsort($consolidatedMonths);
    
    // Get top 3 win totals
    $winTotals = array_keys($consolidatedMonths);
    $topThreeWins = array_slice($winTotals, 0, 3);
    $filteredMonths = array_intersect_key($consolidatedMonths, array_flip($topThreeWins));
    
    // =====================================================================
    // WIN STREAKS - For current league
    // MATCHES PARTICIPANT PROFILE LOGIC: Counts individual game wins in consecutive sequence
    // =====================================================================
    
    // Get all participants in the league
    $stmt = $pdo->prepare("
        SELECT lp.id, COALESCE(u.display_name, lp.participant_name) as name, u.id as user_id, u.profile_photo
        FROM league_participants lp
        LEFT JOIN users u ON lp.user_id = u.id
        WHERE lp.league_id = ? AND lp.status = 'active'
    ");
    $stmt->execute([$currentLeagueId]);
    $participantsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $topStreaks = [];
    
    // Calculate streaks for each participant (matching participant profile logic)
    foreach ($participantsData as $participantData) {
        $participantId = $participantData['id'];
        $participantName = $participantData['name'];
        
        // Get all team names for this participant
        $teamNamesQuery = $pdo->prepare("
            SELECT nt.name 
            FROM draft_picks dp
            JOIN nba_teams nt ON dp.team_id = nt.id
            WHERE dp.league_participant_id = ?
        ");
        $teamNamesQuery->execute([$participantId]);
        $participantTeams = $teamNamesQuery->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($participantTeams)) {
            $placeholders = str_repeat('?,', count($participantTeams) - 1) . '?';
            
            // Get all games for this participant's teams (ordered chronologically)
            $stmt = $pdo->prepare("
                SELECT DISTINCT
                    g.date as game_date,
                    g.start_time,
                    g.home_team,
                    g.away_team,
                    g.home_points,
                    g.away_points,
                    CASE 
                        WHEN (g.home_team IN ($placeholders) AND g.home_points > g.away_points) OR 
                             (g.away_team IN ($placeholders) AND g.away_points > g.home_points) THEN 'W'
                        WHEN g.home_points IS NOT NULL THEN 'L'
                        ELSE NULL
                    END as result
                FROM games g
                WHERE (g.home_team IN ($placeholders) OR g.away_team IN ($placeholders))
                AND g.status_long IN ('Final', 'Finished')
                AND g.date >= ?
                ORDER BY g.date DESC, g.start_time DESC
            ");
            
            $params = array_merge(
                $participantTeams, $participantTeams,
                $participantTeams, $participantTeams,
                [$seasonStartDate]
            );
            $stmt->execute($params);
            $games = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate streaks
            $currentStreak = 0;
            $maxStreak = 0;
            $streakStartDate = null;
            $streakEndDate = null;
            $maxStreakStartDate = null;
            $maxStreakEndDate = null;
            
            // Process games in reverse order (oldest to newest) for streak calculation
            $reversedGames = array_reverse($games);
            
            foreach ($reversedGames as $game) {
                if ($game['result'] === 'W') {
                    if ($currentStreak === 0) {
                        $streakStartDate = $game['game_date'];
                    }
                    $currentStreak++;
                    $streakEndDate = $game['game_date'];
                    
                    // Check if this is a new max
                    if ($currentStreak > $maxStreak) {
                        $maxStreak = $currentStreak;
                        $maxStreakStartDate = $streakStartDate;
                        $maxStreakEndDate = $streakEndDate;
                    }
                } elseif ($game['result'] === 'L') {
                    // Streak broken
                    $currentStreak = 0;
                    $streakStartDate = null;
                    $streakEndDate = null;
                }
            }
            
            // Calculate days in streak
            $streakDays = 0;
            if ($maxStreakStartDate && $maxStreakEndDate) {
                $start = new DateTime($maxStreakStartDate);
                $end = new DateTime($maxStreakEndDate);
                $streakDays = $end->diff($start)->days + 1;
            }
            
            $topStreaks[] = [
                'participant' => $participantName,
                'wins' => $maxStreak,
                'days' => $streakDays,
                'start_date' => $maxStreakStartDate,
                'end_date' => $maxStreakEndDate,
                'user_id' => $participantData['user_id'] ?? null,
                'profile_photo' => $participantData['profile_photo'] ?? null
            ];
        }
    }
    
    // Sort all participants by their best streak (by wins, then by days)
    usort($topStreaks, function($a, $b) {
        return ($b['wins'] <=> $a['wins']) ?: ($b['days'] <=> $a['days']);
    });

    // =====================================================================
    // NAIL-BITERS - Games decided by 3 points or less - For current league
    // =====================================================================
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(u.display_name, lp.participant_name) as participant_name,
            u.id as user_id,
            u.profile_photo,
            COUNT(DISTINCT CASE 
                WHEN (lpt.team_name = g.home_team AND g.home_points > g.away_points) OR
                     (lpt.team_name = g.away_team AND g.away_points > g.home_points)
                THEN g.id 
            END) as close_wins,
            COUNT(DISTINCT CASE 
                WHEN (lpt.team_name = g.home_team AND g.home_points < g.away_points) OR
                     (lpt.team_name = g.away_team AND g.away_points < g.home_points)
                THEN g.id 
            END) as close_losses,
            COUNT(DISTINCT g.id) as total_close_games
        FROM league_participants lp
        LEFT JOIN users u ON lp.user_id = u.id
        JOIN league_participant_teams lpt ON lp.id = lpt.league_participant_id
        JOIN games g ON (lpt.team_name = g.home_team OR lpt.team_name = g.away_team)
        WHERE g.status_long IN ('Final', 'Finished')
        AND ABS(g.home_points - g.away_points) <= 3
        AND lp.league_id = ?
        GROUP BY lp.id, u.display_name, lp.participant_name, u.id, u.profile_photo
        ORDER BY close_wins DESC, close_losses ASC
    ");
    $stmt->execute([$currentLeagueId]);
    
    $nailBiters = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // =====================================================================
    // PERFECT NIGHTS - Multiple teams played, all won - For current league
    // =====================================================================
    $stmt = $pdo->prepare("
        SELECT 
            perfect_nights.participant_name,
            perfect_nights.date,
            perfect_nights.games_won,
            perfect_nights.user_id,
            perfect_nights.profile_photo,
            GROUP_CONCAT(DISTINCT CONCAT(
                CASE 
                    WHEN g.home_team = lpt.team_name THEN g.home_team_code
                    ELSE g.away_team_code
                END,
                ' ',
                g.home_points,
                '-',
                g.away_points,
                ' ',
                CASE 
                    WHEN g.home_team = lpt.team_name THEN g.away_team_code
                    ELSE g.home_team_code
                END
            ) ORDER BY g.start_time SEPARATOR ', ') as game_results
        FROM (
            SELECT 
                COALESCE(u.display_name, lp.participant_name) as participant_name,
                g.date,
                COUNT(DISTINCT g.id) as games_played,
                COUNT(DISTINCT CASE 
                    WHEN (lpt.team_name = g.home_team AND g.home_points > g.away_points) OR
                         (lpt.team_name = g.away_team AND g.away_points > g.home_points)
                    THEN g.id 
                END) as games_won,
                u.id as user_id,
                u.profile_photo
            FROM league_participants lp
            LEFT JOIN users u ON lp.user_id = u.id
            JOIN league_participant_teams lpt ON lp.id = lpt.league_participant_id
            JOIN games g ON (lpt.team_name = g.home_team OR lpt.team_name = g.away_team)
            WHERE g.status_long IN ('Final', 'Finished')
            AND lp.league_id = ?
            GROUP BY lp.id, u.display_name, lp.participant_name, g.date, u.id, u.profile_photo
            HAVING games_played >= 2 AND games_played = games_won
        ) perfect_nights
        JOIN league_participants lp2 ON COALESCE(
            (SELECT display_name FROM users WHERE id = lp2.user_id),
            lp2.participant_name
        ) = perfect_nights.participant_name
        JOIN league_participant_teams lpt ON lpt.league_participant_id = lp2.id
        JOIN games g ON (lpt.team_name = g.home_team OR lpt.team_name = g.away_team)
            AND g.date = perfect_nights.date
        WHERE lp2.league_id = ?
        GROUP BY perfect_nights.participant_name, perfect_nights.date, perfect_nights.games_won, perfect_nights.user_id, perfect_nights.profile_photo
        ORDER BY perfect_nights.games_won DESC, perfect_nights.date DESC
    ");
    $stmt->execute([$currentLeagueId, $currentLeagueId]);
    
    $perfectNights = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group perfect nights by participant
    $participantPerfectNights = [];
    foreach ($perfectNights as $night) {
        $name = $night['participant_name'];
        if (!isset($participantPerfectNights[$name])) {
            $participantPerfectNights[$name] = [
                'nights' => [],
                'user_id' => $night['user_id'],
                'profile_photo' => $night['profile_photo']
            ];
        }
        $participantPerfectNights[$name]['nights'][] = $night;
    }
    
    // Sort perfect nights by number of occurrences first, then by most wins in a night
    $participantPerfectNights = array_filter($participantPerfectNights);
    uasort($participantPerfectNights, function($a, $b) {
        // Compare by number of perfect nights
        $countDiff = count($b['nights']) - count($a['nights']);
        if ($countDiff !== 0) {
            return $countDiff;
        }
        
        // If tied on perfect nights, compare by highest number of wins in a night
        $aMaxWins = max(array_map(fn($night) => $night['games_won'], $a['nights']));
        $bMaxWins = max(array_map(fn($night) => $night['games_won'], $b['nights']));
        return $bMaxWins - $aMaxWins;
    });
        
    // =====================================================================
    // OVER/UNDERACHIEVING TEAMS - For current league teams only
    // =====================================================================
    $stmt = $pdo->prepare("
        SELECT 
            t.name,
            nt.logo_filename,
            t.win,
            t.loss,
            ou.over_under_number,
            ROUND(((t.win * 82.0) / NULLIF((t.win + t.loss), 0)) - ou.over_under_number, 1) as diff
        FROM 2025_2026 t
        JOIN nba_teams nt ON t.name = nt.name
        JOIN over_under ou ON t.name = ou.team_name
        JOIN league_participant_teams lpt ON t.name = lpt.team_name
        JOIN league_participants lp ON lpt.league_participant_id = lp.id
        WHERE t.win + t.loss > 0
        AND lp.league_id = ?
        GROUP BY t.id, t.name, nt.logo_filename, t.win, t.loss, ou.over_under_number
        ORDER BY ABS(ROUND(((t.win * 82.0) / NULLIF((t.win + t.loss), 0)) - ou.over_under_number, 1)) DESC
    ");
    $stmt->execute([$currentLeagueId]);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $overachievers = array_slice(array_filter($teams, fn($t) => $t['diff'] > 0), 0, 3);
    $underachievers = array_slice(array_filter($teams, fn($t) => $t['diff'] < 0), 0, 3);

    // =====================================================================
    // PLATFORM-WIDE TOP 5 LEADERBOARD
    // =====================================================================
    $stmt = $pdo->query("
        SELECT 
            COALESCE(u.display_name, lp.participant_name) as participant_name,
            l.display_name as league_name,
            SUM(t.win) as total_wins,
            u.id as user_id,
            u.profile_photo
        FROM league_participants lp
        LEFT JOIN users u ON lp.user_id = u.id
        JOIN leagues l ON lp.league_id = l.id
        LEFT JOIN league_participant_teams lpt ON lp.id = lpt.league_participant_id
        LEFT JOIN 2025_2026 t ON lpt.team_name = t.name
        WHERE lp.status = 'active'
        GROUP BY lp.id, u.display_name, lp.participant_name, l.display_name, u.id, u.profile_photo
        ORDER BY total_wins DESC
        LIMIT 5
    ");
    $platformTopFive = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="theme-color" content="#1a1a1a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="mobile-web-app-capable" content="yes">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>All-Star Break Recap - NBA Wins Platform</title>
    <link rel="apple-touch-icon" type="image/svg+xml" href="../public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="../public/assets/favicon/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Global Styles */
        * {
            box-sizing: border-box;
        }
        
        html {
            height: 100%;
            height: -webkit-fill-available;
            background: #1a1a1a;
        }
        
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, 
                         "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #1a1a1a 0%, #2a2a2a 100%);
            background-attachment: fixed;
            color: white;
            min-height: 100vh;
            min-height: -webkit-fill-available;
            overflow-x: hidden;
            position: relative;
        }
        
        /* Extend background to cover entire viewport including bottom safe area */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, #1a1a1a 0%, #2a2a2a 100%);
            z-index: -1;
        }
        
        /* Base slide styles */
        .slide {
            min-height: 100vh;
            min-height: -webkit-fill-available;
            opacity: 0;
            display: none;
            padding: 1.5rem;
            padding-bottom: 6rem;
            transition: all 0.5s ease-in-out;
            transform: translateY(20px);
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
    
        /* Active slide styles */
        .slide.active {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            opacity: 1;
            transform: translateY(0);
        }
    
        /* Slide direction animations */
        .slide.slide-left {
            transform: translateX(-100%);
        }
    
        .slide.slide-right {
            transform: translateX(100%);
        }
    
        /* Content fade-in animation */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    
        /* Apply staggered animations to slide content */
        .slide.active > * {
            animation: fadeIn 0.5s ease-out forwards;
            opacity: 0;
        }
    
        .slide.active > *:nth-child(1) { animation-delay: 0.1s; }
        .slide.active > *:nth-child(2) { animation-delay: 0.2s; }
        .slide.active > *:nth-child(3) { animation-delay: 0.3s; }
        
        /* Profile Photo - Force Circular */
        .profile-photo {
            width: 40px !important;
            height: 40px !important;
            min-width: 40px !important;
            min-height: 40px !important;
            max-width: 40px !important;
            max-height: 40px !important;
            border-radius: 50% !important;
            object-fit: cover !important;
            border: 2px solid white !important;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3) !important;
            flex-shrink: 0;
        }
        
        .profile-photo-small {
            width: 36px !important;
            height: 36px !important;
            min-width: 36px !important;
            min-height: 36px !important;
            max-width: 36px !important;
            max-height: 36px !important;
            border-radius: 50% !important;
            object-fit: cover !important;
            border: 2px solid white !important;
            flex-shrink: 0;
        }
        
        .profile-photo-large {
            width: 100px !important;
            height: 100px !important;
            min-width: 100px !important;
            min-height: 100px !important;
            max-width: 100px !important;
            max-height: 100px !important;
            border-radius: 50% !important;
            object-fit: cover !important;
            border: 4px solid white !important;
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.3) !important;
            flex-shrink: 0;
        }
        
        /* Progress Bar */
        .progress-bar {
            height: 6px;
            background: rgba(255,255,255,0.1);
            border-radius: 3px;
            overflow: hidden;
        }
        .progress-bar-fill {
            height: 100%;
            background: #3b82f6;
            transition: width 0.5s ease;
        }
        
        /* Mobile Optimizations */
        @media (max-width: 768px) {
            .slide {
                padding: 1rem;
                padding-bottom: 5rem;
            }
            
            /* Reduce all heading sizes */
            h2 {
                font-size: 1.75rem !important;
                line-height: 1.2 !important;
                margin-bottom: 0.75rem !important;
            }
            
            /* Reduce emoji sizes */
            .slide > div:first-child {
                font-size: 3rem !important;
                margin-bottom: 1rem !important;
            }
            
            /* Reduce subtitle text */
            .text-gray-400 {
                font-size: 0.875rem !important;
            }
            
            /* Profile photos */
            .profile-photo-large {
                width: 80px !important;
                height: 80px !important;
                min-width: 80px !important;
                min-height: 80px !important;
                max-width: 80px !important;
                max-height: 80px !important;
            }
            
            .profile-photo {
                width: 36px !important;
                height: 36px !important;
                min-width: 36px !important;
                min-height: 36px !important;
                max-width: 36px !important;
                max-height: 36px !important;
            }
            
            .profile-photo-small {
                width: 32px !important;
                height: 32px !important;
                min-width: 32px !important;
                min-height: 32px !important;
                max-width: 32px !important;
                max-height: 32px !important;
            }
            
            /* Text sizes */
            .text-5xl {
                font-size: 2rem !important;
            }
            
            .text-4xl {
                font-size: 1.75rem !important;
            }
            
            .text-3xl {
                font-size: 1.5rem !important;
            }
            
            .text-2xl {
                font-size: 1.25rem !important;
            }
            
            .text-xl {
                font-size: 1.125rem !important;
            }
            
            .text-lg {
                font-size: 1rem !important;
            }
            
            /* Reduce spacing */
            .mb-8 {
                margin-bottom: 1rem !important;
            }
            
            .mb-6 {
                margin-bottom: 0.75rem !important;
            }
            
            .mb-4 {
                margin-bottom: 0.5rem !important;
            }
            
            .gap-8 {
                gap: 1rem !important;
            }
            
            .gap-6 {
                gap: 0.75rem !important;
            }
            
            .gap-4 {
                gap: 0.5rem !important;
            }
            
            .gap-3 {
                gap: 0.375rem !important;
            }
            
            /* Team logo grid */
            .grid-cols-3 {
                grid-template-columns: repeat(2, 1fr) !important;
            }
            
            /* Team logos */
            .w-16 {
                width: 2.5rem !important;
                height: 2.5rem !important;
            }
            
            .w-12 {
                width: 2rem !important;
                height: 2rem !important;
            }
            
            /* Reduce padding on cards */
            .p-4 {
                padding: 0.75rem !important;
            }
            
            .p-6 {
                padding: 1rem !important;
            }
            
            /* Button positioning */
            .fixed {
                bottom: 1rem !important;
            }
            
            .fixed.bottom-8.left-8 {
                left: 0.5rem !important;
            }
            
            .fixed.bottom-8.right-8 {
                right: 0.5rem !important;
            }
            
            /* Button sizing */
            button {
                font-size: 0.875rem !important;
                padding: 0.5rem 1rem !important;
            }
            
            /* Medal emoji */
            .w-8 {
                font-size: 1.25rem !important;
            }
        }
        
        /* Extra small screens */
        @media (max-width: 380px) {
            h2 {
                font-size: 1.5rem !important;
            }
            
            .text-5xl {
                font-size: 1.75rem !important;
            }
            
            .text-2xl {
                font-size: 1.125rem !important;
            }
            
            .text-xl {
                font-size: 1rem !important;
            }
            
            .profile-photo-large {
                width: 70px !important;
                height: 70px !important;
                min-width: 70px !important;
                min-height: 70px !important;
                max-width: 70px !important;
                max-height: 70px !important;
            }
        }
    </style>
</head>
<body>
    <div id="slides">
        <!-- Current Leaders Slide -->
        <div class="slide active" id="slide1">
            <div class="text-6xl mb-8">🏆</div>
            <h2 class="text-3xl font-bold mb-4 text-center">
                Current <?php echo count($currentLeaders) > 1 ? 'Leaders' : 'Leader' ?>
            </h2>
            
            <div class="flex flex-col items-center gap-16">
                <?php foreach ($currentLeaders as $leader): ?>
                    <section class="text-center">
                        <?php 
                        $profile_photo_url = getProfilePhotoUrl($leader['user_id'], $leader['profile_photo']);
                        ?>
                        <img src="<?php echo htmlspecialchars($profile_photo_url); ?>" 
                             alt="<?php echo htmlspecialchars($leader['name']); ?>" 
                             class="profile-photo-large mx-auto mb-4"
                             onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMjAiIGZpbGw9IiNFNUU3RUIiLz4KcGF0aCBkPSJNMjAgMjJDMjMuMzEzNyAyMiAyNiAxOS4zMTM3IDI2IDE2QzI2IDEyLjY4NjMgMjMuMzEzNyAxMCAyMCAxMEMxNi42ODYzIDEwIDE0IDEyLjY4NjMgMTQgMTZDMTQgMTkuMzEzNyAxNi42ODYzIDIyIDIwIDIyWiIgZmlsbD0iIzlDQTNBRiIvPgo8cGF0aCBkPSJNMjggMzBDMjggMjUuNTgxNyAyNC40MTgzIDIyIDIwIDIyQzE1LjU4MTcgMjIgMTIgMjUuNTgxNyAxMiAzMEgyOFoiIGZpbGw9IiM5Q0EzQUYiLz4KPC9zdmc+Cg=='">
                        <div class="text-5xl font-bold mb-2"><?php echo htmlspecialchars($leader['name']); ?></div>
                        <div class="text-2xl mb-8"><?php echo $leader['total_wins']; ?> Wins</div>
                        <div class="grid grid-cols-3 gap-4 max-w-2xl mx-auto">
                            <?php foreach ($leader['teams'] as $team): ?>
                                <div class="bg-white/10 rounded-lg p-4 flex flex-col items-center">
                                    <img src="<?php echo htmlspecialchars(getTeamLogo($team['name'])); ?>" 
                                         alt="<?php echo htmlspecialchars($team['name']); ?>" 
                                         class="w-16 h-16 object-contain mb-2"
                                         onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjEyIiB5PSIxNiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                                    <div class="text-sm font-semibold"><?php echo htmlspecialchars($team['name']); ?></div>
                                    <div class="text-sm opacity-75"><?php echo $team['record']; ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
    
        <!-- Days at #1 Slide -->
        <div class="slide" id="slide2">
            <div class="text-6xl mb-8">📅</div>
            <h2 class="text-3xl font-bold mb-8 text-center">Days at #1</h2>
            <div class="w-full max-w-2xl space-y-6">
                <?php if (!empty($daysInFirst)): ?>
                    <?php foreach ($daysInFirst as $participant): ?>
                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <div class="flex items-center gap-3">
                                    <?php 
                                    $profile_photo_url = getProfilePhotoUrl($participant['user_id'], $participant['profile_photo']);
                                    ?>
                                    <img src="<?php echo htmlspecialchars($profile_photo_url); ?>" 
                                         alt="<?php echo htmlspecialchars($participant['name']); ?>" 
                                         class="profile-photo-small"
                                         onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMjAiIGZpbGw9IiNFNUU3RUIiLz4KcGF0aCBkPSJNMjAgMjJDMjMuMzEzNyAyMiAyNiAxOS4zMTM3IDI2IDE2QzI2IDEyLjY4NjMgMjMuMzEzNyAxMCAyMCAxMEMxNi42ODYzIDEwIDE0IDEyLjY4NjMgMTQgMTZDMTQgMTkuMzEzNyAxNi42ODYzIDIyIDIwIDIyWiIgZmlsbD0iIzlDQTNBRiIvPgo8cGF0aCBkPSJNMjggMzBDMjggMjUuNTgxNyAyNC40MTgzIDIyIDIwIDIyQzE1LjU4MTcgMjIgMTIgMjUuNTgxNyAxMiAzMEgyOFoiIGZpbGw9IiM5Q0EzQUYiLz4KPC9zdmc+Cg=='">
                                    <span class="font-semibold"><?php echo htmlspecialchars($participant['name']); ?></span>
                                </div>
                                <span><?php echo $participant['days_in_first']; ?> days</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-bar-fill" style="width: <?php echo ($participant['days_in_first'] / $daysInFirst[0]['days_in_first'] * 100); ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-gray-400">No data available yet</div>
                <?php endif; ?>
            </div>
        </div>
    
        <!-- Best Weeks Slide -->
        <div class="slide" id="slide3">
            <div class="text-6xl mb-8">📈</div>
            <h2 class="text-3xl font-bold mb-8 text-center">Best Weekly Performances</h2>
            
            <div class="flex flex-col items-center w-full max-w-md">
                <?php if (!empty($consolidatedPerformances)): ?>
                    <?php 
                    $medalIndex = 0;
                    foreach ($consolidatedPerformances as $wins => $participants): 
                        $medalIndex++;
                    ?>
                        <div class="w-full mb-6">
                            <div class="text-xl font-bold mb-3 text-gray-400">
                                <?php echo $wins; ?> Wins
                            </div>
                            
                            <div class="space-y-3">
                                <?php foreach ($participants as $name => $data): ?>
                                    <div class="flex items-center w-full">
                                        <div class="w-8 flex-shrink-0">
                                            <?php if ($medalIndex === 1): ?>🥇<?php elseif ($medalIndex === 2): ?>🥈<?php else: ?>🥉<?php endif; ?>
                                        </div>
                                        <div class="flex items-center gap-3 flex-grow">
                                            <?php 
                                            $profile_photo_url = getProfilePhotoUrl($data['user_id'], $data['profile_photo']);
                                            ?>
                                            <img src="<?php echo htmlspecialchars($profile_photo_url); ?>" 
                                                 alt="<?php echo htmlspecialchars($name); ?>" 
                                                 class="profile-photo"
                                                 onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMjAiIGZpbGw9IiNFNUU3RUIiLz4KcGF0aCBkPSJNMjAgMjJDMjMuMzEzNyAyMiAyNiAxOS4zMTM3IDI2IDE2QzI2IDEyLjY4NjMgMjMuMzEzNyAxMCAyMCAxMEMxNi42ODYzIDEwIDE0IDEyLjY4NjMgMTQgMTZDMTQgMTkuMzEzNyAxNi42ODYzIDIyIDIwIDIyWiIgZmlsbD0iIzlDQTNBRiIvPgo8cGF0aCBkPSJNMjggMzBDMjggMjUuNTgxNyAyNC40MTgzIDIyIDIwIDIyQzE1LjU4MTcgMjIgMTIgMjUuNTgxNyAxMiAzMEgyOFoiIGZpbGw9IiM5Q0EzQUYiLz4KPC9zdmc+Cg=='">
                                            <div class="flex items-center">
                                                <span class="text-2xl font-bold"><?php echo htmlspecialchars($name); ?></span>
                                                <?php if ($data['count'] > 1): ?>
                                                    <span class="ml-2 text-sm text-gray-400">(<?php echo $data['count']; ?> times)</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-gray-400">No data available yet</div>
                <?php endif; ?>
            </div>
        </div>
    
        <!-- Best Months Slide -->
        <div class="slide" id="slide4">
            <div class="text-6xl mb-8">📅</div>
            <h2 class="text-3xl font-bold mb-8 text-center">Best Monthly Performances</h2>
            
            <div class="flex flex-col items-center w-full max-w-md">
                <?php if (!empty($filteredMonths)): ?>
                    <?php 
                    $medalIndex = 0;
                    foreach ($filteredMonths as $wins => $participants): 
                        $medalIndex++;
                    ?>
                        <div class="w-full mb-6">
                            <div class="text-xl font-bold mb-3 text-gray-400">
                                <?php echo $wins; ?> Wins
                            </div>
                            
                            <div class="space-y-3">
                                <?php foreach ($participants as $name => $data): ?>
                                    <div class="flex items-center w-full">
                                        <div class="w-8 flex-shrink-0">
                                            <?php if ($medalIndex === 1): ?>🥇<?php elseif ($medalIndex === 2): ?>🥈<?php else: ?>🥉<?php endif; ?>
                                        </div>
                                        <div class="flex flex-col gap-2 flex-grow">
                                            <div class="flex items-center gap-3">
                                                <?php 
                                                $profile_photo_url = getProfilePhotoUrl($data['user_id'], $data['profile_photo']);
                                                ?>
                                                <img src="<?php echo htmlspecialchars($profile_photo_url); ?>" 
                                                     alt="<?php echo htmlspecialchars($name); ?>" 
                                                     class="profile-photo"
                                                     onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMjAiIGZpbGw9IiNFNUU3RUIiLz4KcGF0aCBkPSJNMjAgMjJDMjMuMzEzNyAyMiAyNiAxOS4zMTM3IDI2IDE2QzI2IDEyLjY4NjMgMjMuMzEzNyAxMCAyMCAxMEMxNi42ODYzIDEwIDE0IDEyLjY4NjMgMTQgMTZDMTQgMTkuMzEzNyAxNi42ODYzIDIyIDIwIDIyWiIgZmlsbD0iIzlDQTNBRiIvPgo8cGF0aCBkPSJNMjggMzBDMjggMjUuNTgxNyAyNC40MTgzIDIyIDIwIDIyQzE1LjU4MTcgMjIgMTIgMjUuNTgxNyAxMiAzMEgyOFoiIGZpbGw9IiM5Q0EzQUYiLz4KPC9zdmc+Cg=='">
                                                <div class="flex items-center">
                                                    <span class="text-2xl font-bold"><?php echo htmlspecialchars($name); ?></span>
                                                    <?php if ($data['count'] > 1): ?>
                                                        <span class="ml-2 text-sm text-gray-400">(<?php echo $data['count']; ?> times)</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="text-sm text-gray-400">
                                                <?php echo htmlspecialchars($data['months'][0]); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-gray-400">No data available yet</div>
                <?php endif; ?>
            </div>
        </div>
    
        <!-- Win Streaks Slide -->
        <div class="slide" id="slide5">
            <div class="text-6xl mb-8">🔥</div>
            <h2 class="text-3xl font-bold mb-8 text-center">Best Win Streaks</h2>
            
            <div class="flex flex-col items-center w-full max-w-md">
                <?php foreach ($topStreaks as $streak): ?>
                    <div class="w-full mb-6">
                        <div class="flex items-center gap-3 mb-2">
                            <?php 
                            $profile_photo_url = getProfilePhotoUrl($streak['user_id'], $streak['profile_photo']);
                            ?>
                            <img src="<?php echo htmlspecialchars($profile_photo_url); ?>" 
                                 alt="<?php echo htmlspecialchars($streak['participant']); ?>" 
                                 class="profile-photo"
                                 onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMjAiIGZpbGw9IiNFNUU3RUIiLz4KcGF0aCBkPSJNMjAgMjJDMjMuMzEzNyAyMiAyNiAxOS4zMTM3IDI2IDE2QzI2IDEyLjY4NjMgMjMuMzEzNyAxMCAyMCAxMEMxNi42ODYzIDEwIDE0IDEyLjY4NjMgMTQgMTZDMTQgMTkuMzEzNyAxNi42ODYzIDIyIDIwIDIyWiIgZmlsbD0iIzlDQTNBRiIvPgo8cGF0aCBkPSJNMjggMzBDMjggMjUuNTgxNyAyNC40MTgzIDIyIDIwIDIyQzE1LjU4MTcgMjIgMTIgMjUuNTgxNyAxMiAzMEgyOFoiIGZpbGw9IiM5Q0EzQUYiLz4KPC9zdmc+Cg=='">
                            <div>
                                <div class="text-2xl font-bold"><?php echo htmlspecialchars($streak['participant']); ?></div>
                            </div>
                        </div>
                        <div class="text-xl">
                            <?php echo $streak['wins']; ?> Wins
                            <span class="text-sm text-gray-400">(<?php echo $streak['days']; ?> days)</span>
                        </div>
                        <?php if ($streak['start_date']): ?>
                            <div class="text-sm text-gray-400">
                                <?php 
                                echo date('M j', strtotime($streak['start_date']));
                                echo ' - ';
                                echo date('M j', strtotime($streak['end_date']));
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    
        <!-- Nail-Biters Slide -->
        <div class="slide" id="slide6">
            <div class="text-6xl mb-8">😰</div>
            <h2 class="text-3xl font-bold mb-8 text-center">Nail-Biters</h2>
            <div class="text-center text-gray-400 mb-6">Games decided by 3 points or less</div>
            
            <div class="flex flex-col items-center w-full max-w-md">
                <?php if (!empty($nailBiters)): ?>
                    <?php foreach ($nailBiters as $record): ?>
                        <div class="w-full mb-4">
                            <div class="flex items-center justify-between bg-white/10 rounded-lg p-4">
                                <div class="flex-1 flex items-center gap-3">
                                    <?php 
                                    $profile_photo_url = getProfilePhotoUrl($record['user_id'], $record['profile_photo']);
                                    ?>
                                    <img src="<?php echo htmlspecialchars($profile_photo_url); ?>" 
                                         alt="<?php echo htmlspecialchars($record['participant_name']); ?>" 
                                         class="profile-photo"
                                         onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMjAiIGZpbGw9IiNFNUU3RUIiLz4KcGF0aCBkPSJNMjAgMjJDMjMuMzEzNyAyMiAyNiAxOS4zMTM3IDI2IDE2QzI2IDEyLjY4NjMgMjMuMzEzNyAxMCAyMCAxMEMxNi42ODYzIDEwIDE0IDEyLjY4NjMgMTQgMTZDMTQgMTkuMzEzNyAxNi42ODYzIDIyIDIwIDIyWiIgZmlsbD0iIzlDQTNBRiIvPgo8cGF0aCBkPSJNMjggMzBDMjggMjUuNTgxNyAyNC40MTgzIDIyIDIwIDIyQzE1LjU4MTcgMjIgMTIgMjUuNTgxNyAxMiAzMEgyOFoiIGZpbGw9IiM5Q0EzQUYiLz4KPC9zdmc+Cg=='">
                                    <div>
                                        <div class="text-xl font-bold"><?php echo htmlspecialchars($record['participant_name']); ?></div>
                                        <div class="text-sm text-gray-400">
                                            <?php echo $record['total_close_games']; ?> close games
                                        </div>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-2xl">
                                        <span class="text-green-400"><?php echo $record['close_wins']; ?></span>
                                        -
                                        <span class="text-red-400"><?php echo $record['close_losses']; ?></span>
                                    </div>
                                    <div class="text-sm text-gray-400">
                                       <?php echo round(($record['close_wins'] / $record['total_close_games']) * 100); ?>% win rate
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-gray-400">No data available yet</div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Perfect Nights Slide -->
        <div class="slide" id="slide7">
            <div class="text-6xl mb-8">💯</div>
            <h2 class="text-3xl font-bold mb-8 text-center">Perfect Nights</h2>
            <div class="text-center text-gray-400 mb-6">Multiple teams played, all victorious</div>
            
            <div class="flex flex-col items-center w-full max-w-md">
                <?php if (!empty($participantPerfectNights)): ?>
                    <?php 
                    $rank = 0;
                    $prevCount = null;
                    foreach ($participantPerfectNights as $participant => $data): 
                        $perfectNightCount = count($data['nights']);
                        $mostWinsInOneNight = max(array_map(function($night) {
                            return $night['games_won'];
                        }, $data['nights']));
                        
                        // Only increment rank if count is different from previous
                        if ($perfectNightCount !== $prevCount) {
                            $rank++;
                        }
                        $prevCount = $perfectNightCount;
                    ?>
                        <div class="w-full mb-6">
                            <div class="bg-white/10 rounded-lg p-4">
                                <div class="flex justify-between items-center">
                                    <div class="flex items-center gap-3">
                                        <div class="text-gray-400 font-bold">#<?php echo $rank; ?></div>
                                        <?php 
                                        $profile_photo_url = getProfilePhotoUrl($data['user_id'], $data['profile_photo']);
                                        ?>
                                        <img src="<?php echo htmlspecialchars($profile_photo_url); ?>" 
                                             alt="<?php echo htmlspecialchars($participant); ?>" 
                                             class="profile-photo"
                                             onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMjAiIGZpbGw9IiNFNUU3RUIiLz4KcGF0aCBkPSJNMjAgMjJDMjMuMzEzNyAyMiAyNiAxOS4zMTM3IDI2IDE2QzI2IDEyLjY4NjMgMjMuMzEzNyAxMCAyMCAxMEMxNi42ODYzIDEwIDE0IDEyLjY4NjMgMTQgMTZDMTQgMTkuMzEzNyAxNi42ODYzIDIyIDIwIDIyWiIgZmlsbD0iIzlDQTNBRiIvPgo8cGF0aCBkPSJNMjggMzBDMjggMjUuNTgxNyAyNC40MTgzIDIyIDIwIDIyQzE1LjU4MTcgMjIgMTIgMjUuNTgxNyAxMiAzMEgyOFoiIGZpbGw9IiM5Q0EzQUYiLz4KPC9zdmc+Cg=='">
                                        <div class="text-xl font-bold"><?php echo htmlspecialchars($participant); ?></div>
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <div class="text-sm text-gray-400">
                                            <?php echo $perfectNightCount; ?> perfect nights
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-gray-400">No data available yet</div>
                <?php endif; ?>
            </div>
        </div>
    
        <!-- Over/Underachievers Slide -->
        <div class="slide" id="slide8">
            <div class="text-6xl mb-8">🌟</div>
            <h2 class="text-3xl font-bold mb-8 text-center">Bet The House</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 max-w-4xl w-full">
                <div>
                    <h3 class="text-xl font-semibold mb-4">Overachievers</h3>
                    <?php if (!empty($overachievers)): ?>
                        <?php foreach ($overachievers as $team): ?>
                            <div class="flex items-center space-x-4 mb-4 bg-white/10 rounded-lg p-4">
                                <img src="<?php echo htmlspecialchars(getTeamLogo($team['name'])); ?>" 
                                     alt="<?php echo htmlspecialchars($team['name']); ?>" 
                                     class="w-12 h-12 object-contain"
                                     onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjEyIiB5PSIxNiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                                <div>
                                    <div class="font-semibold"><?php echo htmlspecialchars($team['name']); ?></div>
                                    <div class="text-green-400">+<?php echo $team['diff']; ?> vs Vegas</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-gray-400">No data available</div>
                    <?php endif; ?>
                </div>
                <div>
                    <h3 class="text-xl font-semibold mb-4">Underachievers</h3>
                    <?php if (!empty($underachievers)): ?>
                        <?php foreach ($underachievers as $team): ?>
                            <div class="flex items-center space-x-4 mb-4 bg-white/10 rounded-lg p-4">
                                <img src="<?php echo htmlspecialchars(getTeamLogo($team['name'])); ?>" 
                                     alt="<?php echo htmlspecialchars($team['name']); ?>" 
                                     class="w-12 h-12 object-contain"
                                     onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjEyIiB5PSIxNiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                                <div>
                                    <div class="font-semibold"><?php echo htmlspecialchars($team['name']); ?></div>
                                    <div class="text-red-400"><?php echo $team['diff']; ?> vs Vegas</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-gray-400">No data available</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Platform-Wide Top 5 Leaderboard Slide -->
        <div class="slide" id="slide9">
            <div class="text-6xl mb-8">🌐</div>
            <h2 class="text-3xl font-bold mb-8 text-center">Platform-Wide Top 5</h2>
            <div class="text-center text-gray-400 mb-6">Best performers across all leagues</div>
            
            <div class="flex flex-col items-center w-full max-w-lg">
                <?php foreach ($platformTopFive as $index => $performer): ?>
                    <div class="w-full mb-4">
                        <div class="flex items-center justify-between bg-white/10 rounded-lg p-4">
                            <div class="flex items-center gap-4">
                                <div class="text-2xl font-bold text-gray-400">#<?php echo ($index + 1); ?></div>
                                <?php 
                                $profile_photo_url = getProfilePhotoUrl($performer['user_id'], $performer['profile_photo']);
                                ?>
                                <img src="<?php echo htmlspecialchars($profile_photo_url); ?>" 
                                     alt="<?php echo htmlspecialchars($performer['participant_name']); ?>" 
                                     class="profile-photo"
                                     onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMjAiIGZpbGw9IiNFNUU3RUIiLz4KcGF0aCBkPSJNMjAgMjJDMjMuMzEzNyAyMiAyNiAxOS4zMTM3IDI2IDE2QzI2IDEyLjY4NjMgMjMuMzEzNyAxMCAyMCAxMEMxNi42ODYzIDEwIDE0IDEyLjY4NjMgMTQgMTZDMTQgMTkuMzEzNyAxNi42ODYzIDIyIDIwIDIyWiIgZmlsbD0iIzlDQTNBRiIvPgo8cGF0aCBkPSJNMjggMzBDMjggMjUuNTgxNyAyNC40MTgzIDIyIDIwIDIyQzE1LjU4MTcgMjIgMTIgMjUuNTgxNyAxMiAzMEgyOFoiIGZpbGw9IiM5Q0EzQUYiLz4KPC9zdmc+Cg=='">
                                <div>
                                    <div class="text-xl font-bold"><?php echo htmlspecialchars($performer['participant_name']); ?></div>
                                    <div class="text-sm text-gray-400"><?php echo htmlspecialchars($performer['league_name']); ?></div>
                                </div>
                            </div>
                            <div class="text-2xl font-bold text-blue-400">
                                <?php echo $performer['total_wins']; ?> wins
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <div class="fixed z-[100] bottom-8 left-8" style="padding-bottom: env(safe-area-inset-bottom);">
        <button onclick="previousSlide()" 
                class="bg-white text-black rounded-full px-6 py-3 flex items-center space-x-2 hover:bg-gray-200 transition-colors">
            <span class="text-xl">←</span>
            <span>Previous</span>
        </button>
    </div>
    
    <div class="fixed z-[100] bottom-8 right-8" style="padding-bottom: env(safe-area-inset-bottom);">
        <button onclick="nextSlide()" 
                class="bg-white text-black rounded-full px-6 py-3 flex items-center space-x-2 hover:bg-gray-200 transition-colors">
            <span>Next</span>
            <span class="text-xl">→</span>
        </button>
    </div>
    
    <script>
        const slides = document.querySelectorAll('.slide');
        let currentSlide = 0;
    
        function showSlide(index, direction = 'next') {
            // Remove all transition classes from current slide first
            slides[currentSlide].classList.remove('active', 'slide-left', 'slide-right');
            
            // Calculate new index with wrapping
            const newIndex = (index + slides.length) % slides.length;
            
            // Remove all transition classes from new slide
            slides[newIndex].classList.remove('slide-left', 'slide-right');
            
            // Add the appropriate transition class
            if (direction === 'next') {
                slides[currentSlide].classList.add('slide-left');
                slides[newIndex].classList.add('slide-right');
            } else {
                slides[currentSlide].classList.add('slide-right');
                slides[newIndex].classList.add('slide-left');
            }
            
            // Small delay to ensure transitions are set up
            setTimeout(() => {
                slides[currentSlide].classList.remove('active');
                slides[newIndex].classList.add('active');
                slides[newIndex].classList.remove('slide-left', 'slide-right');
            }, 50);
            
            currentSlide = newIndex;
        }
    
        function nextSlide() {
            showSlide(currentSlide + 1, 'next');
        }
    
        function previousSlide() {
            showSlide(currentSlide - 1, 'prev');
        }
    
        // Add keyboard navigation
        document.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowRight') nextSlide();
            if (e.key === 'ArrowLeft') previousSlide();
        });
    
        // Add optional swipe support for mobile
        let touchStartX = 0;
        let touchEndX = 0;
        
        document.addEventListener('touchstart', e => {
            touchStartX = e.changedTouches[0].screenX;
        });
    
        document.addEventListener('touchend', e => {
            touchEndX = e.changedTouches[0].screenX;
            handleSwipe();
        });
    
        function handleSwipe() {
            const swipeThreshold = 50;
            if (touchEndX < touchStartX - swipeThreshold) {
                nextSlide();
            }
            if (touchEndX > touchStartX + swipeThreshold) {
                previousSlide();
            }
        }
    </script>
</body>
</html>