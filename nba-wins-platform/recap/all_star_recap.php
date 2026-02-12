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
$seasonStartDate = '2025-10-20';
$snapshotEndDate = '2026-02-18';

// Create temporary snapshot standings table (wins/losses only through All-Star break)
try {
    $pdo->exec("DROP TEMPORARY TABLE IF EXISTS snapshot_standings");
    $pdo->exec("
        CREATE TEMPORARY TABLE snapshot_standings AS
        SELECT 
            nt.name,
            COALESCE(SUM(CASE 
                WHEN (g.home_team = nt.name AND g.home_points > g.away_points) 
                  OR (g.away_team = nt.name AND g.away_points > g.home_points) 
                THEN 1 ELSE 0 END), 0) as win,
            COALESCE(SUM(CASE 
                WHEN (g.home_team = nt.name AND g.home_points < g.away_points) 
                  OR (g.away_team = nt.name AND g.away_points < g.home_points) 
                THEN 1 ELSE 0 END), 0) as loss
        FROM nba_teams nt
        LEFT JOIN games g ON (g.home_team = nt.name OR g.away_team = nt.name)
            AND g.status_long IN ('Final', 'Finished')
            AND g.date BETWEEN '2025-10-20' AND '2026-02-18'
        GROUP BY nt.name
    ");
} catch(PDOException $e) {
    error_log("Failed to create snapshot_standings: " . $e->getMessage());
}

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
            AND lpw2.date <= '2026-02-18'
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
            JOIN snapshot_standings t ON nt.name = t.name
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
    // RACE CHART DATA - Cumulative wins over time for animated leaderboard
    // Sample every 3 days for smooth animation (~35 frames over season)
    // =====================================================================
    $stmt = $pdo->prepare("
        SELECT 
            lpw.date,
            COALESCE(u.display_name, lp.participant_name) as name,
            lpw.total_wins,
            u.id as user_id,
            u.profile_photo
        FROM league_participant_daily_wins lpw
        JOIN league_participants lp ON lpw.league_participant_id = lp.id
        LEFT JOIN users u ON lp.user_id = u.id
        WHERE lp.league_id = ?
        AND lpw.total_wins > 0
        AND lpw.date <= '2026-02-18'
        ORDER BY lpw.date ASC, lpw.total_wins DESC
    ");
    $stmt->execute([$currentLeagueId]);
    $allDailyWins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by date
    $winsByDate = [];
    foreach ($allDailyWins as $row) {
        $winsByDate[$row['date']][] = [
            'name' => $row['name'],
            'wins' => (int)$row['total_wins'],
            'user_id' => $row['user_id'],
            'profile_photo' => $row['profile_photo']
        ];
    }
    
    // Sample every 3 days + always include first and last dates
    $allDates = array_keys($winsByDate);
    $raceFrames = [];
    $dateCount = count($allDates);
    
    if ($dateCount > 0) {
        // Calculate step: aim for ~30 frames max
        $step = max(1, (int)ceil($dateCount / 30));
        
        for ($i = 0; $i < $dateCount; $i += $step) {
            $date = $allDates[$i];
            $raceFrames[] = [
                'date' => $date,
                'label' => date('M j', strtotime($date)),
                'participants' => $winsByDate[$date]
            ];
        }
        
        // Always include the final date
        $lastDate = end($allDates);
        $lastFrame = end($raceFrames);
        if ($lastFrame['date'] !== $lastDate) {
            $raceFrames[] = [
                'date' => $lastDate,
                'label' => date('M j', strtotime($lastDate)),
                'participants' => $winsByDate[$lastDate]
            ];
        }
    }
    
    // Build profile photo map for JS
    $participantPhotos = [];
    foreach ($allDailyWins as $row) {
        if (!isset($participantPhotos[$row['name']])) {
            $participantPhotos[$row['name']] = getProfilePhotoUrl($row['user_id'], $row['profile_photo']);
        }
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
        AND lpw1.date <= '2026-02-18'
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
                AND pdw.date <= ?
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
                AND pdw.date <= ?
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
    $stmt->execute([$seasonStartDate, $snapshotEndDate, $currentLeagueId, $seasonStartDate, $snapshotEndDate, $currentLeagueId]);
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
        WHERE lpw.date >= ? AND lpw.date <= ? AND lp.league_id = ?
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
    $stmt->execute([$seasonStartDate, $snapshotEndDate, $currentLeagueId]);
    
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
                AND g.date <= ?
                ORDER BY g.date DESC, g.start_time DESC
            ");
            
            $params = array_merge(
                $participantTeams, $participantTeams,
                $participantTeams, $participantTeams,
                [$seasonStartDate, $snapshotEndDate]
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
    // LOSS STREAKS - For current league
    // MIRRORS WIN STREAK LOGIC: Counts individual game losses in consecutive sequence
    // =====================================================================
    
    $topLossStreaks = [];
    
    // Calculate loss streaks for each participant
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
                AND g.date <= ?
                ORDER BY g.date DESC, g.start_time DESC
            ");
            
            $params = array_merge(
                $participantTeams, $participantTeams,
                $participantTeams, $participantTeams,
                [$seasonStartDate, $snapshotEndDate]
            );
            $stmt->execute($params);
            $games = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate loss streaks
            $currentLossStreak = 0;
            $maxLossStreak = 0;
            $lossStreakStartDate = null;
            $lossStreakEndDate = null;
            $maxLossStreakStartDate = null;
            $maxLossStreakEndDate = null;
            
            // Process games in reverse order (oldest to newest)
            $reversedGames = array_reverse($games);
            
            foreach ($reversedGames as $game) {
                if ($game['result'] === 'L') {
                    if ($currentLossStreak === 0) {
                        $lossStreakStartDate = $game['game_date'];
                    }
                    $currentLossStreak++;
                    $lossStreakEndDate = $game['game_date'];
                    
                    if ($currentLossStreak > $maxLossStreak) {
                        $maxLossStreak = $currentLossStreak;
                        $maxLossStreakStartDate = $lossStreakStartDate;
                        $maxLossStreakEndDate = $lossStreakEndDate;
                    }
                } elseif ($game['result'] === 'W') {
                    $currentLossStreak = 0;
                    $lossStreakStartDate = null;
                    $lossStreakEndDate = null;
                }
            }
            
            // Calculate days in loss streak
            $lossStreakDays = 0;
            if ($maxLossStreakStartDate && $maxLossStreakEndDate) {
                $start = new DateTime($maxLossStreakStartDate);
                $end = new DateTime($maxLossStreakEndDate);
                $lossStreakDays = $end->diff($start)->days + 1;
            }
            
            $topLossStreaks[] = [
                'participant' => $participantName,
                'losses' => $maxLossStreak,
                'days' => $lossStreakDays,
                'start_date' => $maxLossStreakStartDate,
                'end_date' => $maxLossStreakEndDate,
                'user_id' => $participantData['user_id'] ?? null,
                'profile_photo' => $participantData['profile_photo'] ?? null
            ];
        }
    }
    
    // Sort all participants by their worst loss streak (by losses, then by days)
    usort($topLossStreaks, function($a, $b) {
        return ($b['losses'] <=> $a['losses']) ?: ($b['days'] <=> $a['days']);
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
        AND g.date BETWEEN '2025-10-20' AND '2026-02-18'
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
            AND g.date BETWEEN '2025-10-20' AND '2026-02-18'
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
            AND g.date BETWEEN '2025-10-20' AND '2026-02-18'
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
    // HEARTBREAKER NIGHTS - Multiple teams played, all lost
    // =====================================================================
    $stmt = $pdo->prepare("
        SELECT 
            heartbreak_nights.participant_name,
            heartbreak_nights.date,
            heartbreak_nights.games_lost,
            heartbreak_nights.user_id,
            heartbreak_nights.profile_photo,
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
                    WHEN (lpt.team_name = g.home_team AND g.home_points < g.away_points) OR
                         (lpt.team_name = g.away_team AND g.away_points < g.home_points)
                    THEN g.id 
                END) as games_lost,
                u.id as user_id,
                u.profile_photo
            FROM league_participants lp
            LEFT JOIN users u ON lp.user_id = u.id
            JOIN league_participant_teams lpt ON lp.id = lpt.league_participant_id
            JOIN games g ON (lpt.team_name = g.home_team OR lpt.team_name = g.away_team)
            WHERE g.status_long IN ('Final', 'Finished')
            AND g.date BETWEEN '2025-10-20' AND '2026-02-18'
            AND lp.league_id = ?
            GROUP BY lp.id, u.display_name, lp.participant_name, g.date, u.id, u.profile_photo
            HAVING games_played >= 2 AND games_played = games_lost
        ) heartbreak_nights
        JOIN league_participants lp2 ON COALESCE(
            (SELECT display_name FROM users WHERE id = lp2.user_id),
            lp2.participant_name
        ) = heartbreak_nights.participant_name
        JOIN league_participant_teams lpt ON lpt.league_participant_id = lp2.id
        JOIN games g ON (lpt.team_name = g.home_team OR lpt.team_name = g.away_team)
            AND g.date = heartbreak_nights.date
            AND g.date BETWEEN '2025-10-20' AND '2026-02-18'
        WHERE lp2.league_id = ?
        GROUP BY heartbreak_nights.participant_name, heartbreak_nights.date, heartbreak_nights.games_lost, heartbreak_nights.user_id, heartbreak_nights.profile_photo
        ORDER BY heartbreak_nights.games_lost DESC, heartbreak_nights.date DESC
    ");
    $stmt->execute([$currentLeagueId, $currentLeagueId]);
    
    $heartbreakNights = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group heartbreak nights by participant
    $participantHeartbreakNights = [];
    foreach ($heartbreakNights as $night) {
        $name = $night['participant_name'];
        if (!isset($participantHeartbreakNights[$name])) {
            $participantHeartbreakNights[$name] = [
                'nights' => [],
                'user_id' => $night['user_id'],
                'profile_photo' => $night['profile_photo']
            ];
        }
        $participantHeartbreakNights[$name]['nights'][] = $night;
    }
    
    // Sort by number of heartbreak nights, then by most losses in a single night
    uasort($participantHeartbreakNights, function($a, $b) {
        $countDiff = count($b['nights']) - count($a['nights']);
        if ($countDiff !== 0) {
            return $countDiff;
        }
        $aMaxLosses = max(array_map(fn($night) => $night['games_lost'], $a['nights']));
        $bMaxLosses = max(array_map(fn($night) => $night['games_lost'], $b['nights']));
        return $bMaxLosses - $aMaxLosses;
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
        FROM snapshot_standings t
        JOIN nba_teams nt ON t.name = nt.name
        JOIN over_under ou ON t.name = ou.team_name
        JOIN league_participant_teams lpt ON t.name = lpt.team_name
        JOIN league_participants lp ON lpt.league_participant_id = lp.id
        WHERE t.win + t.loss > 0
        AND lp.league_id = ?
        GROUP BY t.name, nt.logo_filename, t.win, t.loss, ou.over_under_number
        ORDER BY ABS(ROUND(((t.win * 82.0) / NULLIF((t.win + t.loss), 0)) - ou.over_under_number, 1)) DESC
    ");
    $stmt->execute([$currentLeagueId]);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $overachievers = array_slice(array_filter($teams, fn($t) => $t['diff'] > 0), 0, 3);
    $underachievers = array_slice(array_filter($teams, fn($t) => $t['diff'] < 0), 0, 3);

    // =====================================================================
    // PLATFORM-WIDE TOP 5 LEADERBOARD
    // =================================================================================================================================
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
        LEFT JOIN snapshot_standings t ON lpt.team_name = t.name
        WHERE lp.status = 'active'
        GROUP BY lp.id, u.display_name, lp.participant_name, l.display_name, u.id, u.profile_photo
        ORDER BY total_wins DESC
        LIMIT 5
    ");
    $platformTopFive = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        LEFT JOIN snapshot_standings t ON nt.name = t.name
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
            u.profile_photo,
            l.display_name as league_name,
            l.id as league_id,
            lp.id as participant_id
        FROM draft_picks dp
        JOIN league_participants lp ON dp.league_participant_id = lp.id
        JOIN users u ON lp.user_id = u.id
        JOIN leagues l ON lp.league_id = l.id
        JOIN nba_teams nt ON dp.team_id = nt.id
        LEFT JOIN snapshot_standings t ON nt.name = t.name
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
        $team['base_steal_score'] = round($base_steal_score, 1);
        
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
        if (abs($a['steal_score'] - $b['steal_score']) > 0.001) {
            return $b['steal_score'] <=> $a['steal_score'];
        }
        return $b['pick_number'] <=> $a['pick_number'];
    });
    
    // Take top 5
    $topSteals = array_slice($bestDraftSteals, 0, 5);
    
    // Assign rankings with ties - teams with identical steal scores get the same rank
    $bestDraftSteals = [];
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
    // SEASON SUMMARY STATS - Platform-wide totals
    // =====================================================================
    $stmt = $pdo->query("SELECT COUNT(*) FROM games WHERE date BETWEEN '2025-10-20' AND '2026-02-18' AND status_long IN ('Final', 'Finished')");
    $totalGamesTracked = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT SUM(win) FROM snapshot_standings");
    $totalWinsPlatform = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM leagues WHERE draft_completed = TRUE");
    $totalLeagues = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(DISTINCT lp.id) FROM league_participants lp JOIN leagues l ON lp.league_id = l.id WHERE lp.status = 'active' AND l.draft_completed = TRUE");
    $totalParticipants = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT MIN(date) FROM league_participant_daily_wins WHERE date >= '2025-10-20'");
    $seasonTrackingStart = $stmt->fetchColumn();
    $daysSoFar = $seasonTrackingStart ? (int)((strtotime('2026-02-18') - strtotime($seasonTrackingStart)) / 86400) : 0;

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
    <!-- Canvas Confetti for celebration effects -->
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js"></script>
    <!-- Lottie Web Component for animated icons -->
    <script src="https://unpkg.com/@lottiefiles/dotlottie-wc@0.8.11/dist/dotlottie-wc.js" type="module"></script>
    <style>
        /* Global Styles */
        * {
            box-sizing: border-box;
        }
        
        html {
            height: 100%;
            height: -webkit-fill-available;
            background: #0f0f0f;
        }
        
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, 
                         "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #0f0f0f 0%, #1a1a2e 50%, #16213e 100%);
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
            background: linear-gradient(135deg, #0f0f0f 0%, #1a1a2e 50%, #16213e 100%);
            z-index: -1;
        }
        
        /* Subtle animated background particles */
        body::after {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: radial-gradient(circle at 20% 50%, rgba(59, 130, 246, 0.03) 0%, transparent 50%),
                              radial-gradient(circle at 80% 20%, rgba(147, 51, 234, 0.03) 0%, transparent 50%),
                              radial-gradient(circle at 40% 80%, rgba(59, 130, 246, 0.02) 0%, transparent 50%);
            z-index: -1;
            animation: bgShift 20s ease-in-out infinite alternate;
        }
        
        @keyframes bgShift {
            0% { opacity: 0.5; }
            100% { opacity: 1; }
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
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(25px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    
        /* Apply staggered animations to slide content */
        .slide.active > * {
            animation: fadeInUp 0.6s cubic-bezier(0.22, 1, 0.36, 1) forwards;
            opacity: 0;
        }
    
        .slide.active > *:nth-child(1) { animation-delay: 0.05s; }
        .slide.active > *:nth-child(2) { animation-delay: 0.15s; }
        .slide.active > *:nth-child(3) { animation-delay: 0.25s; }
        .slide.active > *:nth-child(4) { animation-delay: 0.35s; }
        
        /* ====== EMOJI ANIMATION ====== */
        @keyframes emojiBounce {
            0% { transform: scale(0.3) rotate(-15deg); opacity: 0; }
            50% { transform: scale(1.15) rotate(5deg); opacity: 1; }
            70% { transform: scale(0.95) rotate(-2deg); }
            100% { transform: scale(1) rotate(0deg); opacity: 1; }
        }
        
        .slide-emoji {
            display: inline-block;
        }
        
        .slide.active .slide-emoji {
            animation: emojiBounce 0.7s cubic-bezier(0.34, 1.56, 0.64, 1) forwards !important;
        }
        
        /* ====== LOTTIE ICON CONTAINERS ====== */
        .lottie-icon-wrapper {
            width: 100px;
            height: 100px;
            margin: 0 auto 2rem;
            position: relative;
        }
        .lottie-icon-wrapper dotlottie-wc {
            position: absolute;
            inset: 0;
            width: 80px;
            height: 80px;
            display: block;
            opacity: 0;
            transition: opacity 0.3s;
            z-index: 2;
        }
        .lottie-icon-wrapper .emoji-fallback {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            font-size: 3.75rem;
            transition: opacity 0.3s;
        }
        .lottie-icon-wrapper.lottie-loaded dotlottie-wc {
            opacity: 1;
        }
        .lottie-icon-wrapper.lottie-loaded .emoji-fallback {
            opacity: 0;
            pointer-events: none;
        }
        
        /* ====== TROPHY SHIMMER for Slide 1 ====== */
        @keyframes trophyShimmer {
            0%, 100% { filter: drop-shadow(0 0 6px rgba(255,215,0,0.3)); }
            50% { filter: drop-shadow(0 0 20px rgba(255,215,0,0.8)) drop-shadow(0 0 40px rgba(255,165,0,0.4)); }
        }
        .slide.active .lottie-icon-wrapper.trophy-glow {
            animation: trophyShimmer 2s ease-in-out infinite;
            animation-delay: 0.8s;
        }
        .slide.active .lottie-icon-wrapper.trophy-glow .emoji-fallback {
            animation: emojiBounce 0.7s cubic-bezier(0.34, 1.56, 0.64, 1) forwards, trophyShimmer 2s ease-in-out 0.8s infinite;
        }
        
        /* ====== FIRE PULSE for Slide 5 ====== */
        @keyframes firePulse {
            0%, 100% { filter: drop-shadow(0 0 4px rgba(255,100,0,0.4)); transform: scale(1); }
            50% { filter: drop-shadow(0 0 16px rgba(255,60,0,0.7)) drop-shadow(0 0 30px rgba(255,0,0,0.3)); transform: scale(1.05); }
        }
        .slide.active .lottie-icon-wrapper.fire-glow {
            animation: firePulse 1.5s ease-in-out infinite;
            animation-delay: 0.8s;
        }
        .slide.active .lottie-icon-wrapper.fire-glow .emoji-fallback {
            animation: emojiBounce 0.7s cubic-bezier(0.34, 1.56, 0.64, 1) forwards, firePulse 1.5s ease-in-out 0.8s infinite;
        }
        
        /* ====== PERFECT 100 SPARKLE for Slide 8 ====== */
        @keyframes sparkleGlow {
            0%, 100% { filter: drop-shadow(0 0 4px rgba(59,130,246,0.3)); }
            33% { filter: drop-shadow(0 0 14px rgba(139,92,246,0.6)); }
            66% { filter: drop-shadow(0 0 14px rgba(59,130,246,0.6)); }
        }
        .slide.active .lottie-icon-wrapper.sparkle-glow {
            animation: sparkleGlow 2.5s ease-in-out infinite;
            animation-delay: 0.8s;
        }
        .slide.active .lottie-icon-wrapper.sparkle-glow .emoji-fallback {
            animation: emojiBounce 0.7s cubic-bezier(0.34, 1.56, 0.64, 1) forwards, sparkleGlow 2.5s ease-in-out 0.8s infinite;
        }
        
        /* ====== RACE CHART ====== */
        .race-container {
            width: 100%;
            max-width: 700px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex: 1;
        }
        .race-date-label {
            font-size: 2.5rem;
            font-weight: 800;
            text-align: center;
            margin-bottom: 1.5rem;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.02em;
            min-height: 3.5rem;
        }
        .race-bars {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
        }
        .race-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            width: 100%;
            height: 44px;
            transition: transform 0.5s cubic-bezier(0.22, 1, 0.36, 1),
                        opacity 0.3s ease;
            position: relative;
        }
        .race-row .race-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
            border: 2px solid rgba(255,255,255,0.2);
        }
        .race-row .race-name {
            width: 130px;
            font-size: 0.85rem;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex-shrink: 0;
            text-align: right;
        }
        .race-row .race-bar-track {
            flex: 1;
            height: 28px;
            background: rgba(255,255,255,0.05);
            border-radius: 6px;
            overflow: hidden;
            position: relative;
        }
        .race-row .race-bar-fill {
            height: 100%;
            border-radius: 6px;
            position: relative;
            min-width: 2px;
        }
        .race-row .race-wins-label {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.8rem;
            font-weight: 700;
            color: #fff;
            text-shadow: 0 1px 3px rgba(0,0,0,0.5);
        }
        /* Current user highlight */
        .race-row.is-current-user .race-bar-fill {
            box-shadow: 0 0 12px rgba(255,215,0,0.4);
        }
        .race-row.is-current-user .race-name {
            color: #FFD700;
            font-weight: 700;
        }
        .race-controls {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        .race-progress-track {
            flex: 1;
            height: 4px;
            background: rgba(255,255,255,0.1);
            border-radius: 2px;
            overflow: hidden;
            max-width: 300px;
        }
        .race-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6);
            border-radius: 2px;
            transition: width 0.3s ease;
        }
        .race-skip-btn {
            font-size: 0.75rem;
            padding: 0.35rem 0.9rem;
            border-radius: 9999px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.15);
            color: rgba(255,255,255,0.6);
            cursor: pointer;
            transition: all 0.2s;
        }
        .race-skip-btn:hover {
            background: rgba(255,255,255,0.15);
            color: #fff;
        }
        /* Race/Reveal phase layering */
        .race-phase {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex: 1;
            transition: opacity 0.6s ease, transform 0.6s ease;
        }
        .race-phase.fade-out {
            opacity: 0;
            transform: scale(0.95);
            pointer-events: none;
            display: none !important;
        }
        .reveal-phase {
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 100%;
            flex: 1;
        }
        @keyframes revealFadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }
        #slide2 {
            overflow: hidden;
        }
        @media (max-width: 640px) {
            .race-date-label { font-size: 1.75rem; }
            .race-row .race-name { width: 90px; font-size: 0.75rem; }
            .race-row .race-avatar { width: 28px; height: 28px; }
            .race-row .race-bar-track { height: 24px; }
            .race-row { height: 36px; gap: 0.5rem; }
        }

        /* ====== GLASSMORPHISM CARDS ====== */
        .glass-card {
            background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%) !important;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px !important;
            transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
        }
        
        .glass-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px rgba(0,0,0,0.3), 0 0 0 1px rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.15);
        }
        
        /* Team cards on leader slide */
        .team-card {
            background: linear-gradient(135deg, rgba(255,255,255,0.06) 0%, rgba(255,255,255,0.02) 100%) !important;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 12px !important;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .team-card:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 8px 24px rgba(0,0,0,0.3);
        }
        
        /* ====== STAGGERED CARD ENTRANCE ====== */
        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-40px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .slide.active .stagger-item {
            opacity: 0;
            animation: slideInLeft 0.6s cubic-bezier(0.22, 1, 0.36, 1) forwards;
        }
        
        .slide.active .stagger-item:nth-child(1) { animation-delay: 0.3s; }
        .slide.active .stagger-item:nth-child(2) { animation-delay: 0.55s; }
        .slide.active .stagger-item:nth-child(3) { animation-delay: 0.8s; }
        .slide.active .stagger-item:nth-child(4) { animation-delay: 1.05s; }
        .slide.active .stagger-item:nth-child(5) { animation-delay: 1.3s; }
        .slide.active .stagger-item:nth-child(6) { animation-delay: 1.55s; }
        .slide.active .stagger-item:nth-child(7) { animation-delay: 1.8s; }
        .slide.active .stagger-item:nth-child(8) { animation-delay: 2.05s; }
        .slide.active .stagger-item:nth-child(9) { animation-delay: 2.3s; }
        .slide.active .stagger-item:nth-child(10) { animation-delay: 2.55s; }
        
        /* ====== GLOWING STAT NUMBERS ====== */
        .stat-glow {
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            filter: drop-shadow(0 0 12px rgba(59, 130, 246, 0.4));
        }
        
        .stat-glow-green {
            background: linear-gradient(135deg, #22c55e, #4ade80);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            filter: drop-shadow(0 0 10px rgba(34, 197, 94, 0.4));
        }
        
        .stat-glow-red {
            background: linear-gradient(135deg, #ef4444, #f87171);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            filter: drop-shadow(0 0 10px rgba(239, 68, 68, 0.4));
        }
        
        .stat-glow-gold {
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            filter: drop-shadow(0 0 10px rgba(245, 158, 11, 0.4));
        }
        
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
        
        /* ====== ANIMATED PROGRESS BARS ====== */
        .progress-bar {
            height: 8px;
            background: rgba(255,255,255,0.06);
            border-radius: 4px;
            overflow: hidden;
            position: relative;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6);
            border-radius: 4px;
            width: 0;
            transition: width 1.2s cubic-bezier(0.22, 1, 0.36, 1);
        }
        
        /* ====== SLIDE INDICATOR DOTS ====== */
        /* Halftime title card */
        .halftime-title {
            font-size: 3.5rem;
            font-weight: 800;
            text-align: center;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6, #f59e0b);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1.2;
        }
        .halftime-subtitle {
            font-size: 1.25rem;
            color: #9ca3af;
            text-align: center;
            margin-top: 1rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
        }
        .halftime-divider {
            width: 80px;
            height: 3px;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6);
            border-radius: 2px;
            margin: 1.5rem auto;
        }
        @keyframes halftimeFadeUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .halftime-animate {
            opacity: 0;
        }
        .slide.active .halftime-animate {
            animation: halftimeFadeUp 0.8s ease forwards;
        }
        .slide.active .halftime-animate:nth-child(1) { animation-delay: 0.2s; }
        .slide.active .halftime-animate:nth-child(2) { animation-delay: 0.6s; }
        .slide.active .halftime-animate:nth-child(3) { animation-delay: 1.0s; }
        .slide.active .halftime-animate:nth-child(4) { animation-delay: 1.3s; }

        /* Summary stats card */
        .summary-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
            max-width: 420px;
            width: 100%;
        }
        .summary-stat {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px;
            padding: 1.25rem;
            text-align: center;
        }
        .summary-stat .stat-number {
            font-size: 2.25rem;
            font-weight: 800;
            background: linear-gradient(135deg, #3b82f6, #60a5fa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .summary-stat .stat-label {
            font-size: 0.8rem;
            color: #9ca3af;
            margin-top: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .summary-stat:nth-child(2) .stat-number {
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .summary-stat:nth-child(3) .stat-number {
            background: linear-gradient(135deg, #10b981, #34d399);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .summary-stat:nth-child(4) .stat-number {
            background: linear-gradient(135deg, #8b5cf6, #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        @media (max-width: 640px) {
            .halftime-title { font-size: 2.5rem; }
            .summary-grid { gap: 0.75rem; }
            .summary-stat .stat-number { font-size: 1.75rem; }
        }

        .slide-dots {
            position: fixed;
            bottom: 2rem;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 6px;
            z-index: 101;
            padding: 8px 14px;
            background: rgba(0,0,0,0.4);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.06);
        }
        .slide-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .slide-dot.active {
            background: #3b82f6;
            box-shadow: 0 0 8px rgba(59, 130, 246, 0.5);
            transform: scale(1.3);
        }
        .slide-dot:hover:not(.active) {
            background: rgba(255,255,255,0.4);
        }
        
        /* ====== ENHANCED NAV BUTTONS ====== */
        .nav-btn {
            background: rgba(255,255,255,0.1) !important;
            backdrop-filter: blur(12px) !important;
            -webkit-backdrop-filter: blur(12px) !important;
            border: 1px solid rgba(255,255,255,0.15) !important;
            color: white !important;
            border-radius: 9999px !important;
            padding: 0.625rem 1.25rem !important;
            font-weight: 600 !important;
            transition: all 0.3s ease !important;
            cursor: pointer;
        }
        .nav-btn:hover {
            background: rgba(255,255,255,0.2) !important;
            border-color: rgba(255,255,255,0.3) !important;
            transform: scale(1.05);
        }
        .nav-btn:active {
            transform: scale(0.97);
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
            
            /* Slide dots */
            .slide-dots {
                bottom: 1rem;
                gap: 4px;
                padding: 6px 10px;
            }
            .slide-dot {
                width: 6px;
                height: 6px;
            }
            
            /* Nav buttons */
            .nav-btn {
                padding: 0.4rem 0.8rem !important;
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
        <!-- Halftime Title Card -->
        <div class="slide active" id="slide1">
            <div class="halftime-animate">
            </div>
            <div class="halftime-animate">
                <h1 class="halftime-title">All-Star Break<br>2026</h1>
            </div>
            <div class="halftime-animate">
                <div class="halftime-divider"></div>
            </div>
            <div class="halftime-animate">
                <div class="halftime-subtitle">Race to Wins Pool Glory</div>
            </div>
        </div>

        <!-- Current Leaders Slide -->
        <div class="slide" id="slide2">
            <!-- PHASE 1: Animated Race Chart -->
            <div class="race-phase" id="racePhase">
                <div class="race-container">
                    <h2 class="text-2xl font-bold mb-2 text-center" style="opacity:0.7;">Season Race</h2>
                    <div class="race-date-label" id="raceDateLabel">Oct 21</div>
                    <div class="race-bars" id="raceBars"></div>
                    <div class="race-controls">
                        <div class="race-progress-track">
                            <div class="race-progress-fill" id="raceProgress" style="width:0%"></div>
                        </div>
                        <button class="race-skip-btn" id="raceSkipBtn" onclick="skipRace()">Skip ›</button>
                    </div>
                </div>
            </div>
            
            <!-- PHASE 2: Leader Reveal (hidden until race completes) -->
            <div class="reveal-phase" id="revealPhase" style="display:none;">
                <div class="lottie-icon-wrapper trophy-glow" id="lottie-trophy">
                    <dotlottie-wc src="https://lottie.host/cc12b04c-6f3d-4eb7-ad0d-990217682c9e/MnrrD1tSUG.lottie" autoplay loop style="width:100px;height:100px;"></dotlottie-wc>
                    <div class="emoji-fallback slide-emoji">🏆</div>
                </div>
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
                            <div class="text-2xl mb-8"><span class="stat-glow" data-count="<?php echo $leader['total_wins']; ?>"><?php echo $leader['total_wins']; ?></span> Wins</div>
                            <div class="grid grid-cols-3 gap-4 max-w-2xl mx-auto">
                                <?php foreach ($leader['teams'] as $team): ?>
                                    <div class="team-card p-4 flex flex-col items-center">
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
        </div>

        <!-- Race chart data -->
        <script>
            const raceFrames = <?php echo json_encode($raceFrames); ?>;
            const participantPhotos = <?php echo json_encode($participantPhotos); ?>;
            const raceCurrentUserId = <?php echo json_encode($leagueContext['user_id'] ?? ''); ?>;
            // Color palette for participants
            const raceColors = [
                'linear-gradient(90deg, #3b82f6, #60a5fa)', // blue
                'linear-gradient(90deg, #f59e0b, #fbbf24)', // amber
                'linear-gradient(90deg, #10b981, #34d399)', // emerald
                'linear-gradient(90deg, #ef4444, #f87171)', // red
                'linear-gradient(90deg, #8b5cf6, #a78bfa)', // violet
                'linear-gradient(90deg, #ec4899, #f472b6)', // pink
                'linear-gradient(90deg, #06b6d4, #22d3ee)', // cyan
                'linear-gradient(90deg, #f97316, #fb923c)', // orange
                'linear-gradient(90deg, #14b8a6, #2dd4bf)', // teal
                'linear-gradient(90deg, #6366f1, #818cf8)', // indigo
            ];
        </script>
    
        <!-- Days at #1 Slide -->
        <div class="slide" id="slide3">
            <div class="text-6xl mb-8 slide-emoji">📅</div>
            <h2 class="text-3xl font-bold mb-8 text-center">Days at #1</h2>
            <div class="w-full max-w-2xl space-y-6">
                <?php if (!empty($daysInFirst)): ?>
                    <?php foreach ($daysInFirst as $participant): ?>
                        <div class="stagger-item">
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
                                <span class="stat-glow-gold"><?php echo $participant['days_in_first']; ?> days</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-bar-fill" data-target-width="<?php echo ($participant['days_in_first'] / $daysInFirst[0]['days_in_first'] * 100); ?>%" style="width: 0%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-gray-400">No data available yet</div>
                <?php endif; ?>
            </div>
        </div>
    
        <!-- Best Weeks Slide -->
        <div class="slide" id="slide4">
            <div class="text-6xl mb-8 slide-emoji">📈</div>
            <h2 class="text-3xl font-bold mb-8 text-center">Best Weekly Performances</h2>
            
            <div class="flex flex-col items-center w-full max-w-md">
                <?php if (!empty($consolidatedPerformances)): ?>
                    <?php 
                    $medalIndex = 0;
                    foreach ($consolidatedPerformances as $wins => $participants): 
                        $medalIndex++;
                    ?>
                        <div class="w-full mb-6 stagger-item">
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
        <div class="slide" id="slide5">
            <div class="text-6xl mb-8 slide-emoji">📅</div>
            <h2 class="text-3xl font-bold mb-8 text-center">Best Monthly Performances</h2>
            
            <div class="flex flex-col items-center w-full max-w-md">
                <?php if (!empty($filteredMonths)): ?>
                    <?php 
                    $medalIndex = 0;
                    foreach ($filteredMonths as $wins => $participants): 
                        $medalIndex++;
                    ?>
                        <div class="w-full mb-6 stagger-item">
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
        <div class="slide" id="slide6">
            <div class="text-6xl mb-8 slide-emoji">🔥</div>
            <h2 class="text-3xl font-bold mb-8 text-center">Best Win Streaks</h2>
            
            <div class="flex flex-col items-center w-full max-w-md">
                <?php foreach ($topStreaks as $streak): ?>
                    <div class="w-full mb-6 stagger-item">
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
    
        <!-- Loss Streaks Slide -->
        <div class="slide" id="slide7">
            <div class="text-6xl mb-8 slide-emoji">🥶</div>
            <h2 class="text-3xl font-bold mb-8 text-center">Worst Loss Streaks</h2>
            
            <div class="flex flex-col items-center w-full max-w-md">
                <?php foreach ($topLossStreaks as $streak): ?>
                    <div class="w-full mb-6 stagger-item">
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
                            <?php echo $streak['losses']; ?> Losses
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
        <div class="slide" id="slide8">
            <div class="text-6xl mb-8 slide-emoji">😰</div>
            <h2 class="text-3xl font-bold mb-8 text-center">Nail-Biters</h2>
            <div class="text-center text-gray-400 mb-6">Games decided by 3 points or less</div>
            
            <div class="flex flex-col items-center w-full max-w-md">
                <?php if (!empty($nailBiters)): ?>
                    <?php foreach ($nailBiters as $record): ?>
                        <div class="w-full mb-4 stagger-item">
                            <div class="flex items-center justify-between glass-card p-4">
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
                                        <span class="stat-glow-green"><?php echo $record['close_wins']; ?></span>
                                        <span style="-webkit-text-fill-color: white;">-</span>
                                        <span class="stat-glow-red"><?php echo $record['close_losses']; ?></span>
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
        <div class="slide" id="slide9">
            <div class="text-6xl mb-8 slide-emoji">💯</div>
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
                        <div class="w-full mb-6 stagger-item">
                            <div class="glass-card p-4">
                                <div class="flex justify-between items-center">
                                    <div class="flex items-center gap-3">
                                        <div class="stat-glow-gold font-bold">#<?php echo $rank; ?></div>
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
                                        <div class="text-sm stat-glow-green">
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
    
        <!-- Heartbreaker Nights Slide -->
        <div class="slide" id="slide10">
            <div class="text-6xl mb-8 slide-emoji">💔</div>
            <h2 class="text-3xl font-bold mb-8 text-center">Heartbreaker Nights</h2>
            <div class="text-center text-gray-400 mb-6">Multiple teams played, all defeated</div>
            
            <div class="flex flex-col items-center w-full max-w-md">
                <?php if (!empty($participantHeartbreakNights)): ?>
                    <?php 
                    $rank = 0;
                    $prevCount = null;
                    foreach ($participantHeartbreakNights as $participant => $data): 
                        $heartbreakCount = count($data['nights']);
                        $mostLossesInOneNight = max(array_map(function($night) {
                            return $night['games_lost'];
                        }, $data['nights']));
                        
                        if ($heartbreakCount !== $prevCount) {
                            $rank++;
                        }
                        $prevCount = $heartbreakCount;
                    ?>
                        <div class="w-full mb-6 stagger-item">
                            <div class="glass-card p-4">
                                <div class="flex justify-between items-center">
                                    <div class="flex items-center gap-3">
                                        <div class="stat-glow-red font-bold">#<?php echo $rank; ?></div>
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
                                        <div class="text-sm stat-glow-red">
                                            <?php echo $heartbreakCount; ?> rough nights
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-gray-400">No heartbreaker nights yet - lucky you!</div>
                <?php endif; ?>
            </div>
        </div>
    
        <!-- Over/Underachievers Slide -->
        <div class="slide" id="slide11">
            <div class="text-6xl mb-8 slide-emoji">🌟</div>
            <h2 class="text-3xl font-bold mb-8 text-center">The Vegas Zone</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 max-w-4xl w-full">
                <div>
                    <h3 class="text-xl font-semibold mb-4">Overachievers</h3>
                    <?php if (!empty($overachievers)): ?>
                        <?php foreach ($overachievers as $team): ?>
                            <div class="flex items-center space-x-4 mb-4 glass-card p-4 stagger-item">
                                <img src="<?php echo htmlspecialchars(getTeamLogo($team['name'])); ?>" 
                                     alt="<?php echo htmlspecialchars($team['name']); ?>" 
                                     class="w-12 h-12 object-contain"
                                     onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjEyIiB5PSIxNiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                                <div>
                                    <div class="font-semibold"><?php echo htmlspecialchars($team['name']); ?></div>
                                    <div class="stat-glow-green">+<?php echo $team['diff']; ?> vs Vegas</div>
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
                            <div class="flex items-center space-x-4 mb-4 glass-card p-4 stagger-item">
                                <img src="<?php echo htmlspecialchars(getTeamLogo($team['name'])); ?>" 
                                     alt="<?php echo htmlspecialchars($team['name']); ?>" 
                                     class="w-12 h-12 object-contain"
                                     onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjEyIiB5PSIxNiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo='">
                                <div>
                                    <div class="font-semibold"><?php echo htmlspecialchars($team['name']); ?></div>
                                    <div class="stat-glow-red"><?php echo $team['diff']; ?> vs Vegas</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-gray-400">No data available</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Draft Steals Slide -->
        <div class="slide" id="slide12">
            <div class="text-6xl mb-8 slide-emoji">💰</div>
            <h2 class="text-3xl font-bold mb-8 text-center">Best Draft Steals</h2>
            <div class="text-center text-gray-400 mb-6">Teams outperforming their draft round average</div>
            
            <div class="flex flex-col items-center w-full max-w-lg">
                <?php foreach ($bestDraftSteals as $index => $steal): ?>
                    <div class="glass-card p-4 mb-4 w-full stagger-item">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <span class="text-lg font-bold" style="color: <?php echo $steal['grade_color']; ?>">#<?php echo $steal['rank']; ?></span>
                                <img src="<?php echo htmlspecialchars(getTeamLogo($steal['team_name'])); ?>" 
                                     alt="<?php echo htmlspecialchars($steal['team_name']); ?>" 
                                     class="w-10 h-10 object-contain"
                                     onerror="this.style.display='none'">
                                <div>
                                    <div class="font-bold text-sm"><?php echo htmlspecialchars($steal['team_name']); ?></div>
                                    <div class="text-xs text-gray-400">
                                        <?php echo htmlspecialchars($steal['owner_name']); ?> &bull; <?php echo htmlspecialchars($steal['league_name']); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-bold" style="color: <?php echo $steal['grade_color']; ?>">
                                    <?php echo $steal['steal_grade']; ?>
                                </div>
                                <div class="text-xs text-gray-400">
                                    <?php echo $steal['actual_wins']; ?>W vs <?php echo $steal['round_avg_wins']; ?> avg (Rd <?php echo $steal['round_number']; ?>, Pick #<?php echo $steal['pick_number']; ?>)
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Worst Draft Picks Slide -->
        <div class="slide" id="slide13">
            <div class="text-6xl mb-8 slide-emoji">📉</div>
            <h2 class="text-3xl font-bold mb-8 text-center">Worst Draft Picks</h2>
            <div class="text-center text-gray-400 mb-6">Teams underperforming their draft round average</div>
            
            <div class="flex flex-col items-center w-full max-w-lg">
                <?php foreach ($worstDraftPicks as $index => $bust): ?>
                    <div class="glass-card p-4 mb-4 w-full stagger-item">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <span class="text-lg font-bold" style="color: <?php echo $bust['grade_color']; ?>">#<?php echo $bust['rank']; ?></span>
                                <img src="<?php echo htmlspecialchars(getTeamLogo($bust['team_name'])); ?>" 
                                     alt="<?php echo htmlspecialchars($bust['team_name']); ?>" 
                                     class="w-10 h-10 object-contain"
                                     onerror="this.style.display='none'">
                                <div>
                                    <div class="font-bold text-sm"><?php echo htmlspecialchars($bust['team_name']); ?></div>
                                    <div class="text-xs text-gray-400">
                                        <?php echo htmlspecialchars($bust['owner_name']); ?> &bull; <?php echo htmlspecialchars($bust['league_name']); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-bold" style="color: <?php echo $bust['grade_color']; ?>">
                                    <?php echo $bust['bust_grade']; ?>
                                </div>
                                <div class="text-xs text-gray-400">
                                    <?php echo $bust['actual_wins']; ?>W vs <?php echo $bust['round_avg_wins']; ?> avg (Rd <?php echo $bust['round_number']; ?>, Pick #<?php echo $bust['pick_number']; ?>)
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Platform-Wide Top 5 Leaderboard Slide -->
        <div class="slide" id="slide14" style="padding-bottom: 6rem;">
            <div class="text-6xl mb-8 slide-emoji">🌐</div>
            <h2 class="text-3xl font-bold mb-8 text-center">Platform-Wide Top 5</h2>
            <div class="text-center text-gray-400 mb-6">Best performers across all leagues</div>
            
            <div class="flex flex-col items-center w-full max-w-lg">
                <?php foreach ($platformTopFive as $index => $performer): ?>
                    <div class="w-full mb-4 stagger-item">
                        <div class="flex items-center justify-between glass-card p-4">
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
                            <div class="text-2xl font-bold stat-glow">
                                <?php echo $performer['total_wins']; ?> wins
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- Season Summary Slide -->
        <div class="slide" id="slide15">
            <div class="halftime-animate">
                <div class="text-6xl mb-6">📊</div>
            </div>
            <div class="halftime-animate">
                <h1 class="halftime-title" style="font-size: 2.5rem;">By the Numbers</h1>
                <div class="halftime-divider"></div>
            </div>
            <div class="halftime-animate">
                <div class="summary-grid">
                    <div class="summary-stat">
                        <div class="stat-number"><?= number_format($totalGamesTracked) ?></div>
                        <div class="stat-label">Games Tracked</div>
                    </div>
                    <div class="summary-stat">
                        <div class="stat-number"><?= number_format($totalWinsPlatform) ?></div>
                        <div class="stat-label">Total Wins</div>
                    </div>
                    <div class="summary-stat">
                        <div class="stat-number"><?= $totalLeagues ?></div>
                        <div class="stat-label">Active Leagues</div>
                    </div>
                    <div class="summary-stat">
                        <div class="stat-number"><?= $daysSoFar ?></div>
                        <div class="stat-label">Days of Action</div>
                    </div>
                </div>
            </div>
            <div class="halftime-animate">
                <p style="color: #6b7280; font-size: 0.85rem; margin-top: 1.5rem; text-align: center;"><?= $totalParticipants ?> participants across <?= $totalLeagues ?> leagues</p>
            </div>
        </div>

        <!-- Shameless Plug Slide -->
        <div class="slide" id="slide16">
            <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; flex: 1; width: 100%;">
                <div class="text-6xl mb-8 slide-emoji">☕</div>
                <h2 class="text-3xl font-bold mb-4 text-center">Enjoying the Platform?<br>(Shameless Plug)</h2>
                <div class="text-center text-gray-400 mb-8">If you're having fun tracking wins, consider buying me a coffee!</div>
                
                <a href="https://buymeacoffee.com/taylorstvns" target="_blank" rel="noopener noreferrer"
                   style="display: inline-flex; align-items: center; gap: 0.75rem; background: #FFDD00; color: #000000; font-weight: 700; font-size: 1.25rem; padding: 1rem 2rem; border-radius: 12px; text-decoration: none; transition: transform 0.2s ease, box-shadow 0.2s ease; box-shadow: 0 4px 15px rgba(255, 221, 0, 0.3);">
                    <img src="https://cdn.buymeacoffee.com/buttons/bmc-new-btn-logo.svg" alt="" style="height: 28px; width: 28px;">
                    <span>Buy Me a Coffee</span>
                </a>
                
                <div style="margin-top: 2.5rem; text-align: center;">
                    <div style="font-size: 0.85rem; color: #9ca3af; margin-bottom: 0.75rem;">Shoutout to our early supporters 🙏</div>
                    <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
                        <span style="background: rgba(255,255,255,0.08); padding: 0.4rem 1rem; border-radius: 999px; font-size: 0.85rem; color: #e5e7eb;">🎉 brianshane.com</span>
                        <span style="background: rgba(255,255,255,0.08); padding: 0.4rem 1rem; border-radius: 999px; font-size: 0.85rem; color: #e5e7eb;">🎉 BasedKhan</span>
                    </div>
                </div>
                <div class="text-center text-gray-500 mt-6 text-sm">Thanks for being part of the NBA Wins Platform 🏀</div>
            </div>
        </div>
    </div>
    
    <!-- Slide Indicator Dots -->
    <div class="slide-dots" id="slideDots"></div>
    
    <div id="navButtons">
        <div class="fixed z-[100] bottom-8 left-8" style="padding-bottom: env(safe-area-inset-bottom);">
            <button onclick="previousSlide()" class="nav-btn flex items-center space-x-2">
                <span class="text-xl">←</span>
                <span>Previous</span>
            </button>
        </div>
        
        <div class="fixed z-[100] bottom-8 right-8" style="padding-bottom: env(safe-area-inset-bottom);">
            <button onclick="nextSlide()" class="nav-btn flex items-center space-x-2">
                <span>Next</span>
                <span class="text-xl">→</span>
            </button>
        </div>
    </div>
    
    <script>
        const slides = document.querySelectorAll('.slide');
        let currentSlide = 0;
        
        // ====== NAVIGATION LOCK ======
        // Lock navigation until the intro race animation completes on first load
        let navigationLocked = true;
        const navButtons = document.getElementById('navButtons');
        const dotsEl = document.getElementById('slideDots');
        if (navButtons) navButtons.style.opacity = '0';
        if (dotsEl) dotsEl.style.opacity = '0';
        
        function unlockNavigation() {
            navigationLocked = false;
            if (navButtons) { navButtons.style.transition = 'opacity 0.5s ease'; navButtons.style.opacity = '1'; }
            if (dotsEl) { dotsEl.style.transition = 'opacity 0.5s ease'; dotsEl.style.opacity = '1'; }
        }
        
        // ====== BUILD SLIDE INDICATOR DOTS ======
        const dotsContainer = document.getElementById('slideDots');
        slides.forEach((_, i) => {
            const dot = document.createElement('div');
            dot.className = 'slide-dot' + (i === 0 ? ' active' : '');
            dot.addEventListener('click', () => {
                if (navigationLocked) return;
                const direction = i > currentSlide ? 'next' : 'prev';
                showSlide(i, direction);
            });
            dotsContainer.appendChild(dot);
        });
        const dots = dotsContainer.querySelectorAll('.slide-dot');
        
        function updateDots(index) {
            dots.forEach((d, i) => d.classList.toggle('active', i === index));
        }
        
        // ====== ANIMATED PROGRESS BARS ======
        function animateProgressBars(slide) {
            const bars = slide.querySelectorAll('.progress-bar-fill[data-target-width]');
            // Small delay so the CSS transition property is active first
            setTimeout(() => {
                bars.forEach(bar => {
                    bar.style.width = bar.getAttribute('data-target-width');
                });
            }, 400);
        }
        
        function resetProgressBars(slide) {
            const bars = slide.querySelectorAll('.progress-bar-fill[data-target-width]');
            bars.forEach(bar => {
                bar.style.transition = 'none';
                bar.style.width = '0%';
                // Force reflow then restore transition
                bar.offsetHeight;
                bar.style.transition = '';
            });
        }
        
        // ====== ANIMATED NUMBER COUNTERS ======
        function animateCounters(slide) {
            const counters = slide.querySelectorAll('[data-count]');
            counters.forEach(el => {
                const target = parseInt(el.getAttribute('data-count'));
                if (isNaN(target)) return;
                const duration = 1200;
                const start = performance.now();
                const initial = 0;
                
                function tick(now) {
                    const elapsed = now - start;
                    const progress = Math.min(elapsed / duration, 1);
                    // Ease out cubic
                    const eased = 1 - Math.pow(1 - progress, 3);
                    const current = Math.round(initial + (target - initial) * eased);
                    el.textContent = current;
                    if (progress < 1) requestAnimationFrame(tick);
                }
                
                setTimeout(() => requestAnimationFrame(tick), 300);
            });
        }
    
        function showSlide(index, direction = 'next') {
            // Remove all transition classes from current slide first
            slides[currentSlide].classList.remove('active', 'slide-left', 'slide-right');
            resetProgressBars(slides[currentSlide]);
            
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
            
            // Reset confetti flag for the slide we're leaving
            resetConfettiFlag(slides[currentSlide].id);
            
            // Small delay to ensure transitions are set up
            setTimeout(() => {
                slides[currentSlide].classList.remove('active');
                slides[newIndex].classList.add('active');
                slides[newIndex].classList.remove('slide-left', 'slide-right');
                
                // Trigger animations for new slide
                animateProgressBars(slides[newIndex]);
                animateCounters(slides[newIndex]);
                
                // Start race when arriving at slide 2 for the first time
                if (slides[newIndex].id === 'slide2' && !raceInitialized) {
                    raceInitialized = true;
                    initRace();
                }
                
                // Fire confetti if this slide has it (skip slide2 - race handles its own confetti)
                if (slides[newIndex].id !== 'slide2') {
                    fireConfetti(slides[newIndex].id);
                }
            }, 50);
            
            currentSlide = newIndex;
            updateDots(newIndex);
        }
    
        function nextSlide() {
            if (navigationLocked) return;
            showSlide(currentSlide + 1, 'next');
        }
    
        function previousSlide() {
            if (navigationLocked) return;
            showSlide(currentSlide - 1, 'prev');
        }
        
        // ====== CONFETTI EFFECTS ======
        // Map slide IDs to confetti configs
        const confettiSlides = {
            'slide2': { // Current Leaders - gold celebration
                particleCount: 80,
                spread: 70,
                origin: { y: 0.3 },
                colors: ['#FFD700', '#FFA500', '#FF8C00', '#FFFFFF', '#3B82F6'],
                ticks: 150,
                gravity: 1.2
            },

        };
        
        let confettiFiredForSlide = {};
        
        function fireConfetti(slideId) {
            if (typeof confetti === 'undefined') return;
            if (confettiFiredForSlide[slideId]) return; // Only fire once per visit to slide
            confettiFiredForSlide[slideId] = true;
            
            const config = confettiSlides[slideId];
            if (!config) return;
            
            // Fire main burst after a short delay for dramatic effect
            setTimeout(() => {
                confetti(config);
                // Second smaller burst for richness
                setTimeout(() => {
                    confetti({
                        ...config,
                        particleCount: Math.round(config.particleCount * 0.4),
                        spread: config.spread * 0.6,
                        origin: { y: 0.4 }
                    });
                }, 250);
            }, 600);
        }
        
        // Reset confetti flags when leaving a slide so it fires again on revisit
        function resetConfettiFlag(slideId) {
            confettiFiredForSlide[slideId] = false;
        }
        
        // ====== LOTTIE FALLBACK DETECTION ======
        function setupLottieFallbacks() {
            document.querySelectorAll('.lottie-icon-wrapper').forEach(wrapper => {
                const lottieEl = wrapper.querySelector('dotlottie-wc');
                if (!lottieEl) return;
                
                // Listen for successful load
                lottieEl.addEventListener('load', () => {
                    wrapper.classList.add('lottie-loaded');
                });
                
                // If Lottie fails or takes too long, keep showing emoji
                setTimeout(() => {
                    if (!wrapper.classList.contains('lottie-loaded')) {
                        // Lottie didn't load — emoji fallback stays visible
                        if (lottieEl) lottieEl.style.display = 'none';
                    }
                }, 5000);
            });
        }
        setupLottieFallbacks();
        
        // ====== RACE CHART ANIMATION ENGINE (smooth rAF-based) ======
        let raceRunning = false;
        let raceComplete = false;
        let raceColorMap = {};
        let raceUserIdMap = {};
        let raceRowEls = {};
        const RACE_TARGET_MS = 16000; // race animation portion (~17.5s total - 1.2s pause - 0.3s fade)
        let TOTAL_DURATION_MS = RACE_TARGET_MS;
        const DEFAULT_AVATAR = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMjAiIGZpbGw9IiNFNUU3RUIiLz4KPHBhdGggZD0iTTIwIDIyQzIzLjMxMzcgMjIgMjYgMTkuMzEzNyAyNiAxNkMyNiAxMi42ODYzIDIzLjMxMzcgMTAgMjAgMTBDMTYuNjg2MyAxMCAxNCAxMi42ODYzIDE0IDE2QzE0IDE5LjMxMzcgMTYuNjg2MyAyMiAyMCAyMloiIGZpbGw9IiM5Q0EzQUYiLz4KPHBhdGggZD0iTTI4IDMwQzI4IDI1LjU4MTcgMjQuNDE4MyAyMiAyMCAyMkMxNS41ODE3IDIyIDEyIDI1LjU4MTcgMTIgMzBIMjhaIiBmaWxsPSIjOUNBM0FGIi8+Cjwvc3ZnPgo=';
        
        // Build a lookup: for each participant, their wins at each frame index
        let raceParticipantNames = [];
        let raceWinsGrid = {}; // name -> [wins_at_frame0, wins_at_frame1, ...]
        
        function initRace() {
            if (!raceFrames || raceFrames.length < 2) {
                triggerReveal();
                return;
            }
            
            // Collect all unique participant names, assign colors, and map user_ids
            let colorIdx = 0;
            const nameSet = new Set();
            raceUserIdMap = {}; // name -> user_id
            raceFrames.forEach(frame => {
                frame.participants.forEach(p => {
                    if (!nameSet.has(p.name)) {
                        nameSet.add(p.name);
                        raceColorMap[p.name] = raceColors[colorIdx % raceColors.length];
                        colorIdx++;
                    }
                    if (p.user_id && !raceUserIdMap[p.name]) {
                        raceUserIdMap[p.name] = String(p.user_id);
                    }
                });
            });
            raceParticipantNames = Array.from(nameSet);
            
            // Build wins grid: fill gaps with previous value (carry forward)
            // Prepend a zero-frame so everyone starts at 0
            raceParticipantNames.forEach(name => {
                raceWinsGrid[name] = [0]; // Frame 0 = everyone at 0
                let lastWins = 0;
                raceFrames.forEach((frame, i) => {
                    const found = frame.participants.find(p => p.name === name);
                    if (found) lastWins = found.wins;
                    raceWinsGrid[name].push(lastWins);
                });
            });
            
            // Prepend a "Start" label for the zero-frame
            raceFrames.unshift({ label: 'Season Start', participants: [] });
            
            // Build DOM rows for all participants
            buildAllRaceRows();
            
            // Render frame 0 (everyone at 0) immediately
            renderInterpolated(0);
            
            // Start smooth animation loop after a pause to show the starting grid
            setTimeout(startRaceLoop, 1200);
        }
        
        function buildAllRaceRows() {
            const container = document.getElementById('raceBars');
            if (!container) return;
            container.innerHTML = '';
            raceRowEls = {};
            
            raceParticipantNames.forEach(name => {
                const row = document.createElement('div');
                row.className = 'race-row';
                
                const avatar = document.createElement('img');
                avatar.className = 'race-avatar';
                avatar.src = participantPhotos[name] || DEFAULT_AVATAR;
                avatar.onerror = function() { this.src = DEFAULT_AVATAR; };
                
                const nameEl = document.createElement('div');
                nameEl.className = 'race-name';
                nameEl.textContent = name;
                
                const track = document.createElement('div');
                track.className = 'race-bar-track';
                
                const fill = document.createElement('div');
                fill.className = 'race-bar-fill';
                fill.style.background = raceColorMap[name];
                fill.style.width = '0%';
                
                const label = document.createElement('div');
                label.className = 'race-wins-label';
                label.textContent = '0';
                
                fill.appendChild(label);
                track.appendChild(fill);
                row.appendChild(avatar);
                row.appendChild(nameEl);
                row.appendChild(track);
                container.appendChild(row);
                
                raceRowEls[name] = { row, fill, label, nameEl };
            });
        }
        
        let raceStartTime = null;
        let raceAnimId = null;
        
        function startRaceLoop() {
            raceRunning = true;
            raceStartTime = performance.now();
            raceAnimId = requestAnimationFrame(raceLoop);
        }
        
        function raceLoop(timestamp) {
            if (!raceRunning) return;
            
            const elapsed = timestamp - raceStartTime;
            const progress = Math.min(elapsed / TOTAL_DURATION_MS, 1);
            
            // Ease: start slow, speed up in middle, slow at end
            const eased = progress < 0.5
                ? 2 * progress * progress
                : 1 - Math.pow(-2 * progress + 2, 2) / 2;
            
            // Map eased progress to a fractional frame index
            const fractionalFrame = eased * (raceFrames.length - 1);
            
            renderInterpolated(fractionalFrame);
            
            // Update progress bar
            const progressEl = document.getElementById('raceProgress');
            if (progressEl) progressEl.style.width = (progress * 100) + '%';
            
            if (progress < 1) {
                raceAnimId = requestAnimationFrame(raceLoop);
            } else {
                raceRunning = false;
                // Pause on final frame then reveal
                setTimeout(triggerReveal, 800);
            }
        }
        
        function renderInterpolated(fractionalFrame) {
            const fi = Math.min(Math.floor(fractionalFrame), raceFrames.length - 1);
            const nextFi = Math.min(fi + 1, raceFrames.length - 1);
            const t = fractionalFrame - fi; // 0..1 between frames
            
            // Update date label to discrete frame
            const dateLabel = document.getElementById('raceDateLabel');
            if (dateLabel) dateLabel.textContent = raceFrames[fi].label;
            
            // Interpolate wins for each participant
            const interpolated = [];
            raceParticipantNames.forEach(name => {
                const wA = raceWinsGrid[name][fi] || 0;
                const wB = raceWinsGrid[name][nextFi] || 0;
                const wins = wA + (wB - wA) * t;
                interpolated.push({ name, wins });
            });
            
            // Sort by wins descending
            interpolated.sort((a, b) => b.wins - a.wins);
            
            // Find max for scaling
            const maxWins = Math.max(...interpolated.map(p => p.wins), 1);
            
            // Update bars
            const container = document.getElementById('raceBars');
            interpolated.forEach((p, idx) => {
                const els = raceRowEls[p.name];
                if (!els) return;
                
                const pct = (p.wins / maxWins) * 100;
                els.fill.style.width = pct + '%';
                els.label.textContent = Math.round(p.wins);
                
                // Current user highlight
                if (raceCurrentUserId && raceUserIdMap[p.name] === String(raceCurrentUserId)) {
                    els.row.classList.add('is-current-user');
                } else {
                    els.row.classList.remove('is-current-user');
                }
                
                // Reorder in DOM
                container.appendChild(els.row);
            });
        }
        
        function triggerReveal() {
            raceComplete = true;
            const racePhase = document.getElementById('racePhase');
            const revealPhase = document.getElementById('revealPhase');
            
            // Step 1: Fade race out visually
            if (racePhase) {
                racePhase.style.transition = 'opacity 0.5s ease';
                racePhase.style.opacity = '0';
            }
            
            // Step 2: After fade, hide race completely and show reveal
            setTimeout(() => {
                if (racePhase) racePhase.classList.add('fade-out');
                if (revealPhase) {
                    revealPhase.style.display = 'flex';
                    revealPhase.style.animation = 'revealFadeIn 0.8s ease forwards';
                }
                fireConfetti('slide2');
                animateCounters(slides[1]);
                
                // Unlock navigation now that the intro sequence is complete
                unlockNavigation();
            }, 550);
        }
        
        function skipRace() {
            raceRunning = false;
            if (raceAnimId) cancelAnimationFrame(raceAnimId);
            // Snap to last frame
            renderInterpolated(raceFrames.length - 1);
            const progressEl = document.getElementById('raceProgress');
            if (progressEl) progressEl.style.width = '100%';
            setTimeout(triggerReveal, 300);
        }
        
        // Auto-advance halftime title card → race slide after animations finish
        let raceInitialized = false;
        setTimeout(() => {
            if (currentSlide === 0) {
                showSlide(1, 'next');
            }
        }, 4000);
        
        // Animate first slide on load (progress bars for other content)
        animateProgressBars(slides[0]);
    
        // Keyboard navigation
        document.addEventListener('keydown', (e) => {
            if (navigationLocked) return;
            if (e.key === 'ArrowRight') nextSlide();
            if (e.key === 'ArrowLeft') previousSlide();
        });
    
        // Swipe support for mobile
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