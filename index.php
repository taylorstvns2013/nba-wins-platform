<?php
// Set timezone to EST
date_default_timezone_set('America/New_York');

// Load database connection and authentication
require_once '/data/www/default/nba-wins-platform/config/db_connection.php';
require_once '/data/www/default/nba-wins-platform/config/season_config.php';
$season = getSeasonConfig();

// Require authentication - redirect to login if not authenticated
requireAuthentication($auth);

// Check if user is a guest
$isGuest = $auth->isGuest();

// Sync theme preference from DB so cross-device changes apply immediately
if (!$isGuest && isset($_SESSION['user_id'])) {
    $stmtTheme = $pdo->prepare("SELECT theme_preference FROM users WHERE id = ?");
    $stmtTheme->execute([$_SESSION['user_id']]);
    $userTheme = $stmtTheme->fetchColumn();
    if ($userTheme !== false) {
        $_SESSION['theme_preference'] = $userTheme;
    }
}

// Get current league context
$leagueContext = getCurrentLeagueContext($auth);
if (!$leagueContext || !$leagueContext['league_id']) {
    if ($isGuest) {
        header('Location: /nba-wins-platform/auth/login.php');
    } else {
        header('Location: /nba-wins-platform/auth/league_hub.php');
    }
    exit;
}

// =============================================================================
// SMART GAME DAY DETECTION
// =============================================================================
$currentHour = (int) date('G');
$currentMinute = (int) date('i');
$calendarToday = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$effectiveToday = $calendarToday;

if ($currentHour < 3) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as active_count
        FROM games 
        WHERE date = ?
        AND status_long NOT IN ('Final', 'Finished')
        AND status_long NOT IN ('Scheduled', 'Not Started', 'Postponed', 'Cancelled', 'Canceled')
    ");
    $stmt->execute([$yesterday]);
    $activeYesterdayGames = $stmt->fetch()['active_count'];
    
    if ($activeYesterdayGames > 0) {
        $effectiveToday = $yesterday;
    }
}

// =============================================================================
// SYNC FINAL GAMES — updates DB BEFORE standings are read
// This fires the MySQL trigger so the leaderboard reflects wins immediately.
// Also returns API scores so we don't call the API twice.
// =============================================================================
require_once '/data/www/default/nba-wins-platform/core/game_scores_helper.php';
try {
    $cached_api_scores = syncFinalGames($pdo, $effectiveToday);
} catch (Exception $e) {
    error_log("syncFinalGames failed: " . $e->getMessage());
    $cached_api_scores = ['scoreboard' => ['games' => []]];
}

$currentLeagueId = $leagueContext['league_id'];

// Load widget classes for dashboard
require_once '/data/www/default/nba-wins-platform/core/DashboardWidget.php';
$dashboardWidget = new DashboardWidget($pdo);

// Get user's pinned widgets (guests won't have any - returns empty)
$pinnedWidgets = [];
if (isset($_SESSION['user_id']) && !$isGuest) {
    $stmt = $pdo->prepare("
        SELECT widget_type, display_order 
        FROM user_dashboard_widgets 
        WHERE user_id = ? AND is_active = 1
        ORDER BY display_order ASC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $pinnedWidgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Check if user is in edit mode for widgets (disabled for guests)
$widgetEditMode = !$isGuest && isset($_GET['edit_widgets']) && $_GET['edit_widgets'] == '1';

// Get league display name for header
$stmt = $pdo->prepare("SELECT display_name FROM leagues WHERE id = ?");
$stmt->execute([$currentLeagueId]);
$leagueDisplayName = $stmt->fetchColumn() ?: 'NBA Wins Pool';

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

$stmt = $pdo->query("
    SELECT t.*, 
           COALESCE(ou.over_under_number, 0) as projected_wins,
           nt.logo_filename as logo,
           t.loss as losses
    FROM {$season['standings_table']} t
    LEFT JOIN over_under ou ON t.name = ou.team_name
    LEFT JOIN nba_teams nt ON t.name = nt.name
    ORDER BY t.win DESC
");
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT 
        lp.id, 
        lp.user_id, 
        COALESCE(u.display_name, lp.participant_name) as name, 
        lpt.team_name,
        COALESCE(dp.pick_number, 999) as draft_pick_number
    FROM league_participants lp 
    LEFT JOIN users u ON lp.user_id = u.id
    LEFT JOIN league_participant_teams lpt ON lp.id = lpt.league_participant_id 
    LEFT JOIN draft_picks dp ON (
        lp.id = dp.league_participant_id 
        AND dp.draft_session_id = (
            SELECT id FROM draft_sessions 
            WHERE league_id = ? AND status = 'completed' 
            ORDER BY created_at DESC LIMIT 1
        )
        AND dp.team_id = (
            SELECT id FROM nba_teams WHERE name = lpt.team_name LIMIT 1
        )
    )
    WHERE lp.league_id = ? AND lp.status = 'active'
    ORDER BY lp.id, COALESCE(dp.pick_number, 999) ASC
");
$stmt->execute([$currentLeagueId, $currentLeagueId]);
$participantData = $stmt->fetchAll(PDO::FETCH_ASSOC);

function normalizeTeamName($teamName) {
    $teamName = trim($teamName);
    $nameVariations = [
        'Los Angeles Clippers' => 'LA Clippers',
        'L.A. Clippers' => 'Los Angeles Clippers',
        'LAC' => 'Los Angeles Clippers',
        'LA Lakers' => 'Los Angeles Lakers',
        'L.A. Lakers' => 'Los Angeles Lakers',
        'LAL' => 'Los Angeles Lakers',
        'Philadelphia Sixers' => 'Philadelphia 76ers',
        'Philly 76ers' => 'Philadelphia 76ers',
    ];
    return isset($nameVariations[$teamName]) ? $nameVariations[$teamName] : $teamName;
}

$participants = [];
$uniqueParticipants = [];
foreach ($participantData as $row) {
    $normalizedTeamName = normalizeTeamName($row['team_name']);
    $uniqueKey = $row['name'] . '_' . $normalizedTeamName;
    if (!isset($uniqueParticipants[$uniqueKey])) {
        if (!isset($participants[$row['name']])) {
            $participants[$row['name']] = [
                'user_id' => $row['user_id'],
                'teams' => []
            ];
        }
        $participants[$row['name']]['teams'][] = $normalizedTeamName;
        $uniqueParticipants[$uniqueKey] = true;
    }
}

$todayDate = $effectiveToday;
$yesterdayDate = date('Y-m-d', strtotime($effectiveToday . ' -1 day'));

$stmt = $pdo->prepare("
    SELECT COALESCE(u.display_name, lp.participant_name) as participant_name, lpw.total_wins 
    FROM league_participant_daily_wins lpw
    JOIN league_participants lp ON lpw.league_participant_id = lp.id
    LEFT JOIN users u ON lp.user_id = u.id
    WHERE lpw.date = ? AND lp.league_id = ?
");
$stmt->execute([$yesterdayDate, $currentLeagueId]);
$previousDayWins = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$stmt = $pdo->prepare("
    SELECT COALESCE(u.display_name, lp.participant_name) as participant_name, lpw.total_wins 
    FROM league_participant_daily_wins lpw
    JOIN league_participants lp ON lpw.league_participant_id = lp.id
    LEFT JOIN users u ON lp.user_id = u.id
    WHERE lpw.date = ? AND lp.league_id = ?
");
$stmt->execute([$todayDate, $currentLeagueId]);
$todayLoggedWins = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$standings = [];
foreach ($participants as $name => $participant_data) {
    $participant_teams = $participant_data['teams'];
    $user_id = $participant_data['user_id'];
    
    $total_wins = 0;
    $total_losses = 0;
    $total_projected_wins = 0;
    $team_data = [];
    
    foreach ($participant_teams as $team) {
        $normalizedTeam = normalizeTeamName($team);
        $team_info = array_values(array_filter($teams, function($t) use ($normalizedTeam) {
            return normalizeTeamName($t['name']) == $normalizedTeam;
        }))[0] ?? null;
        
        if ($team_info) {
            $streak = $team_info['streak'] ?? 0;
            $winstreak = $team_info['winstreak'] ?? 0;
            
            $total_wins += $team_info['win'];
            $total_losses += $team_info['losses'] ?? 0;
            $total_projected_wins += $team_info['projected_wins'];
            $team_data[] = [
                'name' => $normalizedTeam,
                'wins' => $team_info['win'],
                'projected_wins' => $team_info['projected_wins'],
                'logo' => getTeamLogo($normalizedTeam),
                'streak' => $streak,
                'winstreak' => $winstreak
            ];
        }
    }
    
    $wins_change = 0;
    if (isset($previousDayWins[$name]) && isset($todayLoggedWins[$name])) {
        $wins_change = $todayLoggedWins[$name] - $previousDayWins[$name];
    }
    
    $total_games = $total_wins + $total_losses;
    $win_percentage = $total_games > 0 ? round(($total_wins / $total_games) * 100, 1) : 0;
    
    $standings[] = [
        'name' => $name,
        'user_id' => $user_id,
        'total_wins' => $total_wins,
        'total_losses' => $total_losses,
        'total_games' => $total_games,
        'win_percentage' => $win_percentage,
        'total_projected_wins' => $total_projected_wins,
        'teams' => $team_data,
        'wins_change' => $wins_change
    ];
}

usort($standings, function($a, $b) {
    // Primary sort: total wins (descending)
    if ($b['total_wins'] !== $a['total_wins']) {
        return $b['total_wins'] - $a['total_wins'];
    }
    // Tiebreaker: higher win percentage ranks first
    return $b['win_percentage'] <=> $a['win_percentage'];
});

// Calculate participant win/loss streaks for leaderboard flame/ice icons
try {
    foreach ($standings as &$standing) {
        $pTeams = array_column($standing['teams'], 'name');
        $standing['win_streak'] = 0;
        $standing['loss_streak'] = 0;
        if (empty($pTeams)) continue;
        
        $ph = str_repeat('?,', count($pTeams) - 1) . '?';
        
        $streakStmt = $pdo->prepare("
            SELECT 
                CASE 
                    WHEN (g.home_team IN ($ph) AND g.away_team IN ($ph)) THEN 'W'
                    WHEN (g.home_team IN ($ph) AND g.home_points > g.away_points) THEN 'W'
                    WHEN (g.away_team IN ($ph) AND g.away_points > g.home_points) THEN 'W'
                    ELSE 'L'
                END AS result
            FROM games g
            WHERE (g.home_team IN ($ph) OR g.away_team IN ($ph))
              AND g.status_long IN ('Final', 'Finished')
              AND g.date >= '{$season['season_start_date']}'
            ORDER BY g.date DESC, g.start_time DESC
            LIMIT 50
        ");
        
        $params = array_merge(
            $pTeams, $pTeams,  // both-teams check
            $pTeams,           // home win check
            $pTeams,           // away win check
            $pTeams, $pTeams   // WHERE clause
        );
        $streakStmt->execute($params);
        $gameResults = $streakStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($gameResults)) {
            $streakType = $gameResults[0]['result'];
            $streak = 0;
            foreach ($gameResults as $game) {
                if ($game['result'] === $streakType) {
                    $streak++;
                } else {
                    break;
                }
            }
            if ($streakType === 'W') {
                $standing['win_streak'] = $streak;
            } else {
                $standing['loss_streak'] = $streak;
            }
        }
    }
    unset($standing);
} catch (Exception $e) {
    error_log("Streak calculation error: " . $e->getMessage());
    // Ensure defaults are set even on failure
    foreach ($standings as &$standing) {
        if (!isset($standing['win_streak'])) $standing['win_streak'] = 0;
        if (!isset($standing['loss_streak'])) $standing['loss_streak'] = 0;
    }
    unset($standing);
}

$nbaCupDates = $season['nba_cup_dates'];

$selectedDate = isset($_GET['date']) ? $_GET['date'] : $effectiveToday;
$isNbaCupDate = in_array($selectedDate, $nbaCupDates);

// Prev/next dates for swipe navigation
$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
if ($prevDate < $season['season_start_date']) $prevDate = null;
// nextDate capped later after $rangeEnd is known

$stmt = $pdo->prepare("
    SELECT DISTINCT g.*, 
           (SELECT COALESCE(u1.display_name, lp1.participant_name)
            FROM league_participant_teams lpt1 
            JOIN league_participants lp1 ON lpt1.league_participant_id = lp1.id 
            LEFT JOIN users u1 ON lp1.user_id = u1.id
            WHERE lpt1.team_name = g.home_team AND lp1.league_id = ? 
            LIMIT 1) AS home_participant,
           (SELECT COALESCE(u2.display_name, lp2.participant_name)
            FROM league_participant_teams lpt2 
            JOIN league_participants lp2 ON lpt2.league_participant_id = lp2.id 
            LEFT JOIN users u2 ON lp2.user_id = u2.id
            WHERE lpt2.team_name = g.away_team AND lp2.league_id = ? 
            LIMIT 1) AS away_participant,
           nt1.logo_filename AS home_logo,
           nt2.logo_filename AS away_logo,
           gsu.game_time AS stream_game_time
    FROM games g
    LEFT JOIN nba_teams nt1 ON g.home_team = nt1.name
    LEFT JOIN nba_teams nt2 ON g.away_team = nt2.name
    LEFT JOIN game_stream_urls gsu ON (g.home_team = gsu.home_team AND g.away_team = gsu.away_team AND DATE(gsu.game_date) = ?)
    WHERE DATE(g.start_time) = ?
    ORDER BY COALESCE(gsu.game_time, TIME(g.start_time)) ASC
");
$stmt->execute([$currentLeagueId, $currentLeagueId, $selectedDate, $selectedDate]);
$games = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Reuse API scores from syncFinalGames (already fetched above)
$api_scores = $cached_api_scores;
$latest_scores = getLatestGameScores($games, $api_scores);

// Earliest non-final game start time (Unix ms) for auto-poll wake-up
$earliestPendingGameMs = null;
foreach ($games as $g) {
    if (!in_array($g['status_long'], ['Final', 'Finished', 'Postponed', 'Cancelled', 'Canceled'])) {
        $ts = strtotime($g['start_time']);
        if ($ts && ($earliestPendingGameMs === null || $ts < $earliestPendingGameMs)) {
            $earliestPendingGameMs = $ts;
        }
    }
}
$earliestPendingGameMs = $earliestPendingGameMs ? ($earliestPendingGameMs * 1000) : null;

// =============================================================================
// PLAYOFF SERIES TRACKING
// =============================================================================
$playoffsStartDate = $season['playoffs_start_date'] ?? '2026-04-19';
$playInStartDate = $season['play_in_start_date'] ?? '2026-04-14';
$previewStartDate = date('Y-m-d', strtotime($playInStartDate . ' -1 day'));
$finalsStartDate = $season['finals_start_date'] ?? '2026-06-03';
$isPlayoffDate = ($selectedDate >= $playoffsStartDate);
$isPlayInDate = ($selectedDate >= $playInStartDate && $selectedDate < $playoffsStartDate);
$isFinalsDate = ($selectedDate >= $finalsStartDate);
$showPlayoffPreview = ($effectiveToday >= $previewStartDate) || (isset($_GET['preview']) && $_GET['preview'] === 'playoffs');
$seriesData = [];

if ($isPlayoffDate) {
    // Query all Final/Finished playoff games to compute series wins
    $stmtSeries = $pdo->prepare("
        SELECT 
            LEAST(home_team, away_team) AS team_a,
            GREATEST(home_team, away_team) AS team_b,
            home_team, away_team, home_points, away_points
        FROM games 
        WHERE date >= ? 
          AND status_long IN ('Final', 'Finished')
        ORDER BY date ASC, start_time ASC
    ");
    $stmtSeries->execute([$playoffsStartDate]);
    $playoffGames = $stmtSeries->fetchAll(PDO::FETCH_ASSOC);

    foreach ($playoffGames as $pg) {
        $key = $pg['team_a'] . '|' . $pg['team_b'];
        if (!isset($seriesData[$key])) {
            $seriesData[$key] = ['team_a' => $pg['team_a'], 'team_b' => $pg['team_b'], 'wins' => [$pg['team_a'] => 0, $pg['team_b'] => 0]];
        }
        // Determine winner
        if ($pg['home_points'] > $pg['away_points']) {
            $seriesData[$key]['wins'][$pg['home_team']]++;
        } else {
            $seriesData[$key]['wins'][$pg['away_team']]++;
        }
    }

    // Filter out Scheduled games where a team already has 4 wins (series over)
    $games = array_values(array_filter($games, function($g) use ($seriesData) {
        if (in_array($g['status_long'], ['Final', 'Finished'])) return true; // always show completed
        $key = min($g['home_team'], $g['away_team']) . '|' . max($g['home_team'], $g['away_team']);
        if (isset($seriesData[$key])) {
            $wins = $seriesData[$key]['wins'];
            if (max($wins) >= 4) return false; // series over, hide future game
        }
        return true;
    }));
}

/**
 * Get series badge text for a game card
 */
function getSeriesBadge($homeTeam, $awayTeam, $seriesData, $isPlayoffDate) {
    if (!$isPlayoffDate || empty($seriesData)) return null;
    
    $key = min($homeTeam, $awayTeam) . '|' . max($homeTeam, $awayTeam);
    if (!isset($seriesData[$key])) return null;
    
    $wins = $seriesData[$key]['wins'];
    $homeWins = $wins[$homeTeam] ?? 0;
    $awayWins = $wins[$awayTeam] ?? 0;
    
    if ($homeWins == 0 && $awayWins == 0) return null;
    
    if ($homeWins >= 4) {
        return ['text' => $homeTeam . ' wins series ' . $homeWins . '-' . $awayWins, 'class' => 'series-won'];
    } elseif ($awayWins >= 4) {
        return ['text' => $awayTeam . ' wins series ' . $awayWins . '-' . $homeWins, 'class' => 'series-won'];
    } elseif ($homeWins === $awayWins) {
        return ['text' => 'Series tied ' . $homeWins . '-' . $awayWins, 'class' => 'series-tied'];
    } elseif ($homeWins > $awayWins) {
        return ['text' => $homeTeam . ' leads ' . $homeWins . '-' . $awayWins, 'class' => 'series-lead'];
    } else {
        return ['text' => $awayTeam . ' leads ' . $awayWins . '-' . $homeWins, 'class' => 'series-lead'];
    }
}

$stmt = $pdo->prepare("
    SELECT home_team, away_team, stream_url 
    FROM game_stream_urls 
    WHERE DATE(game_date) = ?
");
$stmt->execute([$selectedDate]);
$streamUrls = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $homeTeam = trim($row['home_team']);
    $awayTeam = trim($row['away_team']);
    $streamUrls[$homeTeam . '-' . $awayTeam] = $row['stream_url'];
    $streamUrls[$awayTeam . '-' . $homeTeam] = $row['stream_url'];
}

function getParticipantGameCounts($games, $participants) {
    $counts = [];
    foreach ($participants as $name => $participant_data) {
        $participant_teams = $participant_data['teams'];
        $gameCount = 0;
        $countedGames = [];
        foreach ($games as $game) {
            $gameKey = $game['home_team'] . '-' . $game['away_team'];
            $participantInvolved = false;
            foreach ($participant_teams as $team) {
                if ($team === $game['home_team'] || $team === $game['away_team']) {
                    $participantInvolved = true;
                    break;
                }
            }
            if ($participantInvolved && !in_array($gameKey, $countedGames)) {
                $gameCount++;
                $countedGames[] = $gameKey;
            }
        }
        $counts[$name] = $gameCount;
    }
    return $counts;
}

// Build full season date range from Oct 21 through last game in DB
$seasonStart = $season['season_start_date'];
$stmt = $pdo->query("SELECT MAX(date) FROM games WHERE date >= '{$season['season_start_date']}'");
$lastGameDate = $stmt->fetchColumn() ?: date('Y-m-d', strtotime('+7 days'));

// Extend at least 7 days past today so upcoming games are visible
$rangeEnd = max($lastGameDate, date('Y-m-d', strtotime($effectiveToday . ' +7 days')));

// Cap nextDate to range end
if ($nextDate > $rangeEnd) $nextDate = null;

$dateRange = [];
$current = $seasonStart;
while ($current <= $rangeEnd) {
    $dateRange[] = [
        'date' => $current,
        'dayName' => date('D', strtotime($current)),
        'dayNum' => date('j', strtotime($current)),
        'monthShort' => date('M', strtotime($current)),
        'isToday' => ($current === $effectiveToday),
        'isSelected' => ($current === $selectedDate)
    ];
    $current = date('Y-m-d', strtotime($current . ' +1 day'));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="theme-color" content="<?= ($_SESSION['theme_preference'] ?? 'dark') === 'classic' ? '#f5f5f5' : '#121a23' ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NBA Wins Pool League</title>
    <link rel="apple-touch-icon" type="image/png" href="nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    :root {
        --bg-primary: #151d28;
        --bg-secondary: #1a222c;
        --bg-card: #202a38;
        --bg-card-hover: #273140;
        --bg-elevated: #2a3446;
        --border-color: rgba(255, 255, 255, 0.08);
        --border-subtle: rgba(255, 255, 255, 0.05);
        --text-primary: #e6edf3;
        --text-secondary: #8b949e;
        --text-muted: #545d68;
        --accent-blue: #388bfd;
        --accent-blue-dim: rgba(56, 139, 253, 0.15);
        --accent-blue-glow: rgba(56, 139, 253, 0.3);
        --accent-green: #3fb950;
        --accent-green-dim: rgba(63, 185, 80, 0.12);
        --accent-red: #f85149;
        --accent-red-dim: rgba(248, 81, 73, 0.12);
        --accent-gold: #f0c644;
        --accent-silver: #a0aec0;
        --accent-bronze: #cd7f32;
        --radius-sm: 6px;
        --radius-md: 10px;
        --radius-lg: 14px;
        --shadow-card: 0 1px 3px rgba(0, 0, 0, 0.4), 0 0 0 1px var(--border-color);
        --shadow-elevated: 0 4px 16px rgba(0, 0, 0, 0.5), 0 0 0 1px var(--border-color);
        --transition-fast: 0.15s ease;
        --transition-normal: 0.25s ease;
    }

    <?php if (($_SESSION['theme_preference'] ?? 'dark') === 'classic'): ?>
    /* ============================================================
       CLASSIC THEME OVERRIDES
       ============================================================ */
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
    .standings-header, .dw-header {
        background: rgba(100, 116, 130, 0.18) !important;
    }
    .standings-header span { color: #666 !important; }
    .dw-title { color: #333 !important; }
    .dw-title i { color: #777 !important; }
    .team-detail-row:hover, .dw-team-stat-row:hover { background: rgba(0, 0, 0, 0.03); }
    .game-card {
        background: linear-gradient(135deg, rgba(240, 240, 240, 0.8) 0%, rgba(225, 225, 225, 0.6) 100%);
    }
    .game-team-row.winner {
        background: linear-gradient(90deg, rgba(40, 167, 69, 0.12) 0%, rgba(40, 167, 69, 0.04) 60%, transparent 100%);
    }
    .standings-row:hover { background: rgba(0, 0, 0, 0.03); }
    .rank-1 { background: linear-gradient(to right, rgba(212, 160, 23, 0.1) 0%, transparent 60%); }
    #participantsTable tbody tr.rank-1:hover,
    .rank-1:hover { background: linear-gradient(to right, rgba(212, 160, 23, 0.16) 0%, transparent 60%); }
    .expand-panel { background: var(--bg-elevated); }
    .team-detail-row { border-bottom-color: rgba(0, 0, 0, 0.06); }
    .date-item { color: #888; }
    .date-item:hover { color: #333; background: rgba(0, 0, 0, 0.04); }
    .date-item.selected { background: var(--accent-blue); color: white; }
    .date-item.today { border-color: var(--accent-blue); }
    #participant-filter {
        background: white;
        color: #333;
        border-color: #ccc;
    }
    .no-games, .allstar-break { color: #888; }
    .game-status-badge.status-final { background: #76a5af; color: white; }
    .game-status-badge.status-live { background: #e63946; color: white; }
    .left-column::-webkit-scrollbar-thumb { background: #ccc; }
    .games-section::-webkit-scrollbar-thumb { background: #ccc; }
    .game-footer { background: #e8ecef !important; }
    .series-badge { background: rgba(0, 0, 0, 0.04); color: #555; }
    .series-badge.series-won { background: rgba(40, 167, 69, 0.1); color: #28a745; }
    .watch-btn { background: var(--accent-blue); color: white; }
    .stats-btn { background: #e6f0ff; color: var(--accent-blue); }
    .stats-btn:hover { background: #cce0ff; }
    .dw-card { border-color: #e0e0e0; }
    .dw-edit-bar { background: var(--bg-elevated); border-color: #e0e0e0; }
    .modal-content { background: white; }
    .playoff-preview-banner {
        background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(245, 158, 11, 0.08));
        border-color: rgba(139, 92, 246, 0.2);
        color: #333;
    }
    .playoff-preview-banner:hover {
        background: linear-gradient(135deg, rgba(139, 92, 246, 0.18), rgba(245, 158, 11, 0.12));
    }
    .game-special-logo { filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.2)); }
    <?php endif; ?>

    * { margin: 0; padding: 0; box-sizing: border-box; }

    html {
        height: -webkit-fill-available;
        background-color: var(--bg-primary);
    }

    body {
        font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
        line-height: 1.5;
        color: var(--text-primary);
        background: var(--bg-primary);
        background-image: radial-gradient(ellipse at 50% 0%, rgba(56, 139, 253, 0.04) 0%, transparent 60%);
        min-height: 100vh;
        min-height: -webkit-fill-available;
        -webkit-font-smoothing: antialiased;
    }

    .app-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 12px 2rem;
    }

    /* ===== HEADER ===== */
    .app-header {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 16px 16px 12px;
        position: relative;
    }

    .nav-toggle-btn {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        width: 38px;
        height: 38px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-md);
        color: var(--text-secondary);
        font-size: 16px;
        cursor: pointer;
        transition: all var(--transition-fast);
        z-index: 10;
    }

    .nav-toggle-btn:hover {
        color: var(--text-primary);
        border-color: rgba(56, 139, 253, 0.3);
        background: var(--accent-blue-dim);
    }

    .app-header-logo { width: 36px; height: 36px; }

    .app-header-title {
        font-size: 1.35rem;
        font-weight: 700;
        letter-spacing: -0.02em;
        color: var(--text-primary);
    }

    /* ===== GUEST BANNER ===== */
    .guest-banner {
        margin: 14px 0 12px;
        padding: 10px 14px;
        background: linear-gradient(135deg, rgba(56, 139, 253, 0.15), rgba(56, 139, 253, 0.05));
        border: 1px solid rgba(56, 139, 253, 0.2);
        border-radius: var(--radius-md);
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 13px;
        color: var(--text-secondary);
    }

    .guest-banner-actions { display: flex; gap: 8px; }

    .guest-banner-btn {
        padding: 5px 12px;
        border-radius: var(--radius-sm);
        text-decoration: none;
        font-weight: 600;
        font-size: 12px;
        transition: all var(--transition-fast);
    }

    .guest-signup-btn { background: var(--accent-blue); color: white; }
    .guest-login-btn { background: transparent; color: var(--accent-blue); border: 1px solid rgba(56, 139, 253, 0.3); }
    .guest-logout-btn { background: transparent; color: var(--text-muted); border: 1px solid var(--border-color); }
    .guest-logout-btn:hover { color: var(--accent-red); border-color: var(--accent-red); }

    /* ===== STANDINGS ===== */
    .standings-section { margin-bottom: 20px; margin-top: 16px; }

    .standings-card {
        background: var(--bg-card);
        border-radius: var(--radius-lg);
        overflow: hidden;
        box-shadow: var(--shadow-card);
    }

    .standings-header {
        display: grid;
        grid-template-columns: 44px 1fr 70px;
        padding: 10px 14px;
        background: #161e28;
        border-bottom: 1px solid var(--border-color);
    }

    .standings-header span {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-muted);
    }

    .standings-header span:last-child {
        text-align: right;
        cursor: pointer;
        transition: color var(--transition-fast);
    }

    .standings-header span:last-child:hover { color: var(--accent-blue); }

    .standings-row {
        display: grid;
        grid-template-columns: 44px 1fr 70px;
        align-items: center;
        padding: 10px 14px;
        border-bottom: 1px solid var(--border-subtle);
        cursor: pointer;
        transition: background var(--transition-fast);
        position: relative;
        overflow: hidden;
        /* Staggered entrance */
        opacity: 0;
        transform: translateX(-12px);
        animation: rowSlideIn 0.55s ease forwards;
    }

    @keyframes rowSlideIn {
        to { opacity: 1; transform: translateX(0); }
    }

    .standings-row:hover { background: var(--bg-card-hover); }
    .standings-row:active { background: var(--bg-elevated); }

    /* Progress bar behind each row — grows from 0 */
    .standings-row::after {
        content: '';
        position: absolute;
        left: 0;
        bottom: 0;
        height: 3px;
        width: 0;
        background: linear-gradient(to right, var(--accent-blue), rgba(56, 139, 253, 0.15));
        border-radius: 0 2px 2px 0;
        opacity: 0;
        transition: width 1.2s cubic-bezier(0.25, 0.46, 0.45, 0.94), opacity 0.4s ease;
    }
    .standings-row.animate-bars::after {
        width: var(--progress, 0%);
        opacity: 0.25;
    }
    .rank-1::after {
        background: linear-gradient(to right, var(--accent-gold), rgba(240, 198, 68, 0.15));
    }

    /* #1 row treatment */
    .rank-1 {
        background: linear-gradient(to right, rgba(240, 198, 68, 0.06) 0%, transparent 60%);
    }
    .rank-1:hover {
        background: linear-gradient(to right, rgba(240, 198, 68, 0.1) 0%, var(--bg-card-hover) 60%);
    }

    .rank-badge {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 14px;
        font-weight: 700;
        color: var(--text-secondary);
    }

    .rank-badge .expand-arrow {
        font-size: 10px;
        color: var(--text-muted);
        transition: transform var(--transition-normal);
    }

    .standings-row.expanded .rank-badge .expand-arrow { transform: rotate(180deg); }

    .rank-1 .rank-num { color: var(--accent-gold); }
    .rank-2 .rank-num { color: var(--accent-silver); }
    .rank-3 .rank-num { color: var(--accent-bronze); }

    .participant-info {
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 0;
    }

    .participant-name-text {
        font-size: 14px;
        font-weight: 600;
        color: var(--text-primary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .wins-cell {
        text-align: right;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 6px;
    }

    .wins-number {
        font-size: 16px;
        font-weight: 700;
        color: var(--text-primary);
        font-variant-numeric: tabular-nums;
    }

    .wins-change-badge {
        display: inline-flex;
        align-items: center;
        gap: 2px;
        padding: 1px 5px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 700;
        background: var(--accent-green-dim);
        color: var(--accent-green);
    }

    /* Selective refresh flash — uses box-shadow inset so it doesn't conflict with rowSlideIn animation */
    .standings-row.wins-flash {
        box-shadow: inset 0 0 0 100px var(--accent-green-dim);
        transition: box-shadow 1.4s ease-out;
    }
    .standings-row.wins-flash-fade {
        box-shadow: inset 0 0 0 100px transparent;
    }
    .standings-row.wins-flash .wins-number {
        color: var(--accent-green) !important;
        transition: color 1.2s ease;
    }
    .standings-row.wins-flash-fade .wins-number {
        color: var(--text-primary);
    }

    /* Name pulse on score change */
    @keyframes namePulse {
        0%   { transform: scale(1); opacity: 1; }
        30%  { transform: scale(0.7); opacity: 0; }
        60%  { transform: scale(0.7); opacity: 0; }
        100% { transform: scale(1); opacity: 1; }
    }
    .standings-row.wins-flash .participant-name-text {
        display: inline-block;
        animation: namePulse 0.7s ease-in-out;
    }

    /* Expanded team detail */
    .team-detail-panel {
        display: none;
        background: var(--bg-secondary);
        border-bottom: 1px solid var(--border-subtle);
    }

    .team-detail-panel.show { display: block; }

    .team-detail-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 6px 14px;
        background: var(--bg-elevated);
        border-bottom: 1px solid var(--border-color);
    }

    .team-detail-header span {
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-muted);
    }

    @keyframes cascadeIn {
        from { opacity: 0; transform: translateY(-6px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .team-detail-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 8px 14px;
        border-bottom: 1px solid var(--border-subtle);
        transition: background var(--transition-fast);
    }

    .team-detail-panel.show .team-detail-row,
    .team-detail-panel.show .profile-link-row {
        animation: cascadeIn 0.25s ease both;
        animation-delay: calc(var(--cascade-i, 0) * 60ms);
    }

    .team-detail-row:last-child { border-bottom: none; }
    .team-detail-row:hover { background: rgba(255, 255, 255, 0.02); }

    .team-detail-info { display: flex; align-items: center; gap: 8px; }

    .team-detail-info a {
        display: flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        color: var(--text-primary);
    }

    .team-detail-logo { width: 22px; height: 22px; }

    .team-detail-name {
        font-size: 13px;
        font-weight: 500;
        color: var(--text-secondary);
    }

    .streak-icon { margin-left: 4px; font-size: 11px; position: relative; }
    .streak-icon.hot { color: #f59e0b; }
    .streak-icon.cold { color: #60a5fa; }

    /* Streak burst animations — fire on reveal */
    @keyframes flameBurst {
        0%   { transform: scale(1); opacity: 0; text-shadow: none; }
        15%  { transform: scale(2.4); opacity: 1; color: #ff4500; text-shadow: 0 0 8px #ff6a00, 0 0 18px #ff4500; }
        35%  { transform: scale(1.6); color: #ff8c00; text-shadow: 0 0 12px #ffa500, 0 0 24px #ff6a00; }
        55%  { transform: scale(2); color: #ff5e00; text-shadow: 0 0 6px #ff6a00, 0 0 14px #ff4500; }
        75%  { transform: scale(1.2); color: #f5a623; text-shadow: 0 0 4px rgba(245, 166, 35, 0.4); }
        100% { transform: scale(1); color: #f59e0b; text-shadow: none; }
    }
    @keyframes iceBurst {
        0%   { transform: scale(1) rotate(0deg); opacity: 0; text-shadow: none; }
        15%  { transform: scale(2.4) rotate(-30deg); opacity: 1; color: #ffffff; text-shadow: 0 0 10px #93c5fd, 0 0 20px #60a5fa; }
        35%  { transform: scale(1.6) rotate(15deg); color: #bfdbfe; text-shadow: 0 0 14px #93c5fd, 0 0 28px #3b82f6; }
        55%  { transform: scale(2) rotate(-10deg); color: #93c5fd; text-shadow: 0 0 8px #60a5fa, 0 0 16px #3b82f6; }
        75%  { transform: scale(1.2) rotate(5deg); color: #7ab8f5; text-shadow: 0 0 4px rgba(96, 165, 250, 0.4); }
        100% { transform: scale(1) rotate(0deg); color: #60a5fa; text-shadow: none; }
    }

    .team-detail-panel.show .streak-icon.hot {
        animation: flameBurst 1.5s ease-out both;
        animation-delay: calc(var(--cascade-i, 0) * 60ms + 100ms);
    }
    .team-detail-panel.show .streak-icon.cold {
        animation: iceBurst 1.5s ease-out both;
        animation-delay: calc(var(--cascade-i, 0) * 60ms + 100ms);
    }

    /* Streak number flash — shows count after icon burst starts */
    .streak-icon[data-streak]::after {
        content: attr(data-streak);
        position: absolute;
        top: 50%; left: 50%;
        transform: translate(-50%, -50%) scale(0);
        font-family: 'Outfit', sans-serif;
        font-weight: 800;
        font-style: normal;
        font-size: 14.5px;
        opacity: 0;
        pointer-events: none;
        z-index: 2;
    }
    .streak-icon.hot[data-streak]::after { color: #ff5e00; text-shadow: 0 0 9px rgba(255, 94, 0, 0.55); }
    .streak-icon.cold[data-streak]::after { color: #93c5fd; text-shadow: 0 0 9px rgba(96, 165, 250, 0.55); }

    @keyframes streakNumberFlash {
        0%   { transform: translate(-50%, -50%) scale(0); opacity: 0; }
        12%  { transform: translate(-50%, -50%) scale(2.4); opacity: 1; }
        40%  { transform: translate(-50%, -50%) scale(2.0); opacity: 1; }
        65%  { transform: translate(-50%, -50%) scale(1.6); opacity: 0.7; }
        85%  { transform: translate(-50%, -50%) scale(1.2); opacity: 0.3; }
        100% { transform: translate(-50%, -50%) scale(1); opacity: 0; }
    }
    .team-detail-panel.show .streak-icon[data-streak]::after {
        animation: streakNumberFlash 1.5s ease-out both;
        animation-delay: calc(var(--cascade-i, 0) * 60ms + 700ms);
    }

    /* Leaderboard participant win streak flame */
    .lb-streak-icon {
        margin-left: 5px;
        font-size: 11px;
        position: relative;
        display: inline-block;
        color: #f59e0b;
    }
    .lb-streak-icon.animate {
        animation: flameBurst 1.5s ease-out both;
    }
    .lb-streak-icon[data-streak]::after {
        content: attr(data-streak);
        position: absolute;
        top: 50%; left: 50%;
        transform: translate(-50%, -50%) scale(0);
        font-family: 'Outfit', sans-serif;
        font-weight: 800;
        font-style: normal;
        font-size: 14.5px;
        color: #ff5e00;
        text-shadow: 0 0 9px rgba(255, 94, 0, 0.55);
        opacity: 0;
        pointer-events: none;
        z-index: 2;
    }
    .lb-streak-icon.animate[data-streak]::after {
        animation: streakNumberFlash 1.5s ease-out both;
        animation-delay: 700ms;
    }
    /* Leaderboard loss streak ice */
    .lb-streak-icon.cold {
        color: #60a5fa;
    }
    .lb-streak-icon.cold.animate {
        animation: iceBurst 1.5s ease-out both;
    }
    .lb-streak-icon.cold[data-streak]::after {
        color: #93c5fd;
        text-shadow: 0 0 9px rgba(96, 165, 250, 0.55);
    }

    .team-detail-wins {
        font-size: 14px;
        font-weight: 600;
        color: var(--text-primary);
        font-variant-numeric: tabular-nums;
    }

    .profile-link-row { padding: 6px 14px 10px; }

    .profile-link-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 12px;
        background: var(--accent-blue-dim);
        color: var(--accent-blue);
        border-radius: var(--radius-sm);
        text-decoration: none;
        font-size: 12px;
        font-weight: 600;
        transition: all var(--transition-fast);
    }

    .profile-link-btn:hover { background: var(--accent-blue-glow); }

    /* ===== GAMES SECTION ===== */
    .games-section {
        background: var(--bg-card);
        border-radius: var(--radius-lg);
        padding: 14px;
        box-shadow: var(--shadow-card);
    }

    /* Date Scroll Bar */
    .date-scroll-container {
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .date-nav-btn {
        display: none;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-sm);
        color: var(--text-secondary);
        cursor: pointer;
        flex-shrink: 0;
        transition: all var(--transition-fast);
        font-size: 13px;
    }

    .date-nav-btn:hover {
        color: var(--text-primary);
        background: var(--bg-elevated);
        border-color: rgba(56, 139, 253, 0.3);
    }

    @media (min-width: 601px) {
        .date-nav-btn { display: flex; }
    }

    .date-scroll {
        display: flex;
        gap: 3px;
        overflow-x: auto;
        padding: 4px 0;
        scroll-behavior: smooth;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        flex: 1;
    }

    .date-scroll::-webkit-scrollbar { display: none; }

    .date-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-width: 46px;
        padding: 4px 3px 6px;
        border-radius: var(--radius-sm);
        cursor: pointer;
        transition: all var(--transition-fast);
        text-decoration: none;
        flex-shrink: 0;
        position: relative;
        background: transparent;
    }

    .date-item:hover { background: var(--bg-elevated); }
    .date-item.selected { background: var(--accent-blue); }

    .date-item.today:not(.selected)::after {
        content: '';
        position: absolute;
        bottom: 2px;
        width: 4px;
        height: 4px;
        border-radius: 50%;
        background: var(--accent-blue);
    }

    .date-day-name {
        font-size: 9px;
        font-weight: 500;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .date-day-num {
        font-size: 15px;
        font-weight: 700;
        color: var(--text-primary);
        line-height: 1.2;
    }

    .date-month {
        font-size: 8px;
        font-weight: 500;
        color: var(--text-muted);
        text-transform: uppercase;
    }

    .date-item.selected .date-day-name,
    .date-item.selected .date-day-num,
    .date-item.selected .date-month { color: white; }

    /* Filter bar */
    .games-filter-bar {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 10px;
    }

    .filter-select {
        flex: 1;
        padding: 8px 32px 8px 12px;
        font-size: 14px;
        font-family: 'Outfit', sans-serif;
        background: var(--bg-elevated);
        color: var(--text-primary);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-md);
        cursor: pointer;
        appearance: none;
        -webkit-appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238b949e' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
    }

    .filter-select:focus {
        outline: none;
        border-color: var(--accent-blue);
        box-shadow: 0 0 0 2px var(--accent-blue-dim);
    }

    .refresh-btn {
        width: 38px;
        height: 38px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--bg-elevated);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-md);
        color: var(--text-muted);
        cursor: pointer;
        transition: all var(--transition-fast);
        font-size: 14px;
    }

    .refresh-btn:hover {
        color: var(--accent-blue);
        border-color: rgba(56, 139, 253, 0.3);
        background: var(--accent-blue-dim);
    }

    /* Game Cards */
    .games-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .game-card {
        flex: 1 1 calc(50% - 5px);
        max-width: calc(50% - 5px);
        background: var(--bg-secondary);
        border-radius: var(--radius-md);
        overflow: visible;
        border: 1px solid var(--border-subtle);
        transition: all var(--transition-fast);
        position: relative;
    }

    .game-card:hover { border-color: var(--border-color); box-shadow: var(--shadow-elevated); }
    .game-card.hidden { display: none !important; }
    .game-card-body { border-radius: var(--radius-md) var(--radius-md) 0 0; overflow: hidden; }
    .game-card > :last-child { border-radius: 0 0 var(--radius-md) var(--radius-md); overflow: hidden; }

    .game-team-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 14px;
        position: relative;
    }

    .game-team-row:first-child { border-bottom: 1px solid var(--border-subtle); }

    .game-team-row.winner {
        background: linear-gradient(90deg, rgba(63, 185, 80, 0.18) 0%, rgba(63, 185, 80, 0.06) 60%, transparent 100%);
    }

    .game-team-row.winner::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 3px;
        background: var(--accent-green);
        border-radius: 0 2px 2px 0;
    }

    .game-team-left {
        display: flex;
        align-items: center;
        gap: 10px;
        flex: 1;
        min-width: 0;
    }

    .game-team-logo { width: 30px; height: 30px; flex-shrink: 0; }

    .game-team-name { font-size: 14px; font-weight: 600; color: var(--text-primary); }
    .game-team-name a { color: inherit; text-decoration: none; }

    .game-participant-tag {
        font-size: 11px;
        color: var(--text-muted);
        font-weight: 400;
        font-style: italic;
        margin-left: 2px;
    }

    .game-team-score {
        font-size: 18px;
        font-weight: 700;
        color: var(--text-primary);
        font-variant-numeric: tabular-nums;
        min-width: 36px;
        text-align: right;
    }

    .game-team-row.winner .game-team-score { color: var(--accent-green); }

    .game-footer {
        display: flex;
        gap: 6px;
        padding: 6px 10px;
        background: var(--bg-elevated);
        border-top: 1px solid var(--border-color);
    }

    .game-btn {
        flex: 1;
        padding: 7px 12px;
        border-radius: var(--radius-sm);
        text-decoration: none;
        font-weight: 600;
        font-size: 12px;
        text-align: center;
        transition: all var(--transition-fast);
        font-family: 'Outfit', sans-serif;
        border: none;
        cursor: pointer;
    }

    .game-btn-primary { background: var(--accent-blue); color: white; }
    .game-btn-primary:hover { background: #4d9afd; }
    .game-btn-primary.live { background: var(--accent-red); animation: livePulse 2s ease-in-out infinite; }
    .game-btn-final { background: var(--bg-card); color: var(--text-muted); cursor: default; border: 1px solid var(--border-color); }
    .game-btn-postponed { background: var(--accent-red-dim); color: var(--accent-red); cursor: default; border: 1px solid rgba(248, 81, 73, 0.2); }
    .game-btn-secondary { background: transparent; color: var(--accent-blue); border: 1px solid rgba(56, 139, 253, 0.3); }
    .game-btn-secondary:hover { background: var(--accent-blue-dim); }

    @keyframes livePulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.75; } }

    /* Playoff series badge */
    .series-badge {
        text-align: center;
        padding: 5px 10px;
        font-size: 11px;
        font-weight: 600;
        color: var(--text-muted);
        background: var(--bg-elevated);
        border-top: 1px solid var(--border-subtle);
        letter-spacing: 0.02em;
    }
    .series-badge.series-lead { color: var(--accent-blue); }
    .series-badge.series-tied { color: var(--text-secondary); }
    .series-badge.series-won { color: var(--accent-green); background: rgba(63, 185, 80, 0.08); }

    /* AJAX date-switch transitions */
    .games-section.loading-games {
        opacity: 0.4;
        pointer-events: none;
        transition: opacity 0.15s ease;
    }
    .games-section.games-enter {
        animation: gamesSlideIn 0.3s ease forwards;
    }
    @keyframes gamesSlideIn {
        from { opacity: 0; transform: translateY(8px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Empty states */
    .no-games {
        text-align: center;
        padding: 3rem 1.5rem;
        color: var(--text-muted);
    }

    .no-games i { font-size: 2.5rem; margin-bottom: 12px; display: block; opacity: 0.4; }
    .no-games-title { font-size: 1rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px; }
    .no-games-sub { font-size: 0.85rem; }

    .allstar-break { text-align: center; padding: 2.5rem 1.5rem; }
    .allstar-break-title { font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 4px; }
    .allstar-break-sub { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 16px; }

    .allstar-recap-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 10px 20px;
        background: linear-gradient(135deg, var(--accent-blue), #8b5cf6);
        color: white;
        border-radius: 999px;
        text-decoration: none;
        font-weight: 600;
        font-size: 14px;
        box-shadow: 0 4px 15px rgba(56, 139, 253, 0.3);
    }

    .nba-cup-badge { display: flex; align-items: center; justify-content: center; padding: 8px 0 4px; }
    .nba-cup-badge img { height: 32px; }

    /* Game card special event logo (Cup / Finals) — top-left badge overlay */
    .game-special-logo {
        position: absolute;
        top: -8px;
        left: -8px;
        width: 26px;
        height: 26px;
        object-fit: contain;
        z-index: 2;
        filter: drop-shadow(0 1px 3px rgba(0, 0, 0, 0.45));
        pointer-events: none;
    }

    /* Playoff Preview banner under leaderboard */
    .playoff-preview-banner {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        margin-top: 10px;
        padding: 10px 16px;
        background: linear-gradient(135deg, rgba(139, 92, 246, 0.18), rgba(245, 158, 11, 0.12));
        border: 1px solid rgba(139, 92, 246, 0.25);
        border-radius: var(--radius-md);
        color: var(--text-primary);
        text-decoration: none;
        font-weight: 600;
        font-size: 14px;
        transition: all var(--transition-fast);
    }
    .playoff-preview-banner:hover {
        background: linear-gradient(135deg, rgba(139, 92, 246, 0.28), rgba(245, 158, 11, 0.18));
        border-color: rgba(139, 92, 246, 0.4);
        transform: translateY(-1px);
    }
    .playoff-preview-banner img {
        height: 20px;
        width: auto;
        object-fit: contain;
    }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 600px) {
        .game-card {
            flex: 1 1 100%;
            max-width: 100%;
        }

        .game-team-name { font-size: 13px; }
        .game-team-logo { width: 26px; height: 26px; }
        .game-team-score { font-size: 16px; }
        .participant-name-text { font-size: 13px; }

        .guest-banner {
            flex-direction: column;
            gap: 8px;
            text-align: center;
        }
    }

    /* Mobile: keep widgets below games (preserve original mobile order) */
    @media (max-width: 899px) {
        .main-content-grid {
            display: flex;
            flex-direction: column;
        }
        .left-column {
            display: contents; /* Unwrap so children participate in flex order */
        }
        .standings-section { order: 1; }
        .games-section { order: 2; }
        .dashboard-widgets-section { order: 3; }
    }

    @media (min-width: 601px) {
        .app-container { padding: 0 20px 2rem; }
        .standings-header { grid-template-columns: 50px 1fr 80px; }
        .standings-row { grid-template-columns: 50px 1fr 80px; }
        .participant-name-text { font-size: 15px; }
        .wins-number { font-size: 17px; }
    }

    @media (min-width: 900px) {
        .app-container { max-width: 1300px; padding: 0 24px 2rem; }
        .main-content-grid {
            display: grid;
            grid-template-columns: 55fr 45fr;
            gap: 20px;
            align-items: start;
        }
        /* Left column: standings + dashboard widgets, scrolls independently */
        .left-column {
            display: flex;
            flex-direction: column;
            gap: 0;
            max-height: calc(100vh - 80px);
            overflow-y: auto;
            padding-right: 2px;
            position: sticky;
            top: 16px;
        }
        .left-column::-webkit-scrollbar { width: 4px; }
        .left-column::-webkit-scrollbar-track { background: transparent; }
        .left-column::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 4px; }
        .standings-section { margin-bottom: 0; }
        /* Widgets sit right below standings in left column */
        .left-column .dashboard-widgets-section {
            margin-top: 16px;
        }
        /* Right side: games scroll independently */
        .games-section {
            margin-top: 16px;
            max-height: calc(100vh - 80px);
            overflow-y: auto;
            position: sticky;
            top: 16px;
        }
        .games-section::-webkit-scrollbar { width: 4px; }
        .games-section::-webkit-scrollbar-track { background: transparent; }
        .games-section::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 4px; }
        .game-card { flex: 1 1 100%; max-width: 100%; }
    }



    @media (min-width: 1280px) {
        .app-container { max-width: 1400px; }
    }

    /* ===== DASHBOARD WIDGETS ===== */
    .dashboard-widgets-section {
        margin-top: 20px;
    }

    .dashboard-widgets-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 14px;
        padding: 0 2px;
    }

    .dashboard-divider {
        flex: 1;
        height: 1px;
        background: var(--border-color);
        margin-right: 12px;
    }

    .widget-edit-toggle {
        width: 34px;
        height: 34px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-md);
        color: var(--text-muted);
        cursor: pointer;
        font-size: 14px;
        transition: all var(--transition-fast);
    }

    .widget-edit-toggle:hover {
        color: var(--text-primary);
        border-color: rgba(56, 139, 253, 0.3);
        background: var(--accent-blue-dim);
    }

    .widget-edit-toggle.active {
        color: var(--accent-green);
        border-color: rgba(63, 185, 80, 0.3);
        background: var(--accent-green-dim);
    }

    /* Widget Card */
    .dw-card {
        background: var(--bg-card);
        border-radius: var(--radius-lg);
        overflow: hidden;
        box-shadow: var(--shadow-card);
        margin-bottom: 14px;
    }

    .dw-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 14px;
        border-bottom: 1px solid var(--border-color);
        background: #161e28;
    }

    .dw-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--text-primary);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .dw-title i { color: var(--text-muted); font-size: 0.9rem; }

    .dw-body { padding: 4px 0; }

    .dw-empty {
        text-align: center;
        padding: 30px 16px;
        color: var(--text-muted);
        font-size: 0.9rem;
    }

    /* Widget edit controls */
    .dw-controls {
        display: flex;
        gap: 6px;
    }

    .dw-ctrl-btn {
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--bg-elevated);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-sm);
        color: var(--text-muted);
        cursor: pointer;
        font-size: 12px;
        transition: all var(--transition-fast);
    }

    .dw-ctrl-btn:hover {
        color: var(--accent-blue);
        border-color: rgba(56, 139, 253, 0.3);
        background: var(--accent-blue-dim);
    }

    .dw-ctrl-remove { border-color: rgba(248, 81, 73, 0.3) !important; color: var(--accent-red) !important; }
    .dw-ctrl-remove:hover { background: var(--accent-red) !important; color: white !important; }

    /* Shared team logo sizes */
    .dw-team-logo { width: 22px; height: 22px; flex-shrink: 0; }
    .dw-team-logo-sm { width: 18px; height: 18px; vertical-align: middle; margin: 0 3px; }

    /* Team Stat Row (used by Vegas, League Stats, etc.) */
    .dw-team-stat-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 14px;
        border-bottom: 1px solid var(--border-subtle);
        transition: background var(--transition-fast);
    }

    .dw-team-stat-row:last-child { border-bottom: none; }
    .dw-team-stat-row:hover { background: rgba(255, 255, 255, 0.02); }

    .dw-team-stat-left {
        display: flex;
        align-items: center;
        gap: 8px;
        flex: 1;
        min-width: 0;
        color: var(--text-secondary);
    }

    .dw-team-stat-rank {
        font-weight: 700;
        color: var(--text-muted);
        min-width: 24px;
        flex-shrink: 0;
    }

    .dw-team-stat-name {
        color: var(--text-primary);
        text-decoration: none;
        font-weight: 600;
        font-size: 0.9rem;
    }

    .dw-team-stat-name:hover { color: var(--accent-blue); }

    .dw-team-stat-sub {
        font-size: 0.8rem;
        color: var(--text-muted);
        margin-top: 1px;
    }

    .dw-team-stat-right {
        text-align: right;
        flex-shrink: 0;
    }

    .dw-team-stat-secondary {
        font-size: 0.8rem;
        color: var(--text-muted);
    }

    .dw-team-stat-value {
        font-weight: 700;
        font-size: 1rem;
        color: var(--text-primary);
        font-variant-numeric: tabular-nums;
        flex-shrink: 0;
    }

    /* Rivals section */
    .dw-rivals-section {
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px solid var(--border-color);
    }

    .dw-rivals-title {
        margin: 0 14px 10px;
        font-size: 0.9rem;
        font-weight: 700;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .dw-rivals-title i { color: var(--text-muted); }

    /* Leaderboard rows */
    .dw-lb-row {
        display: flex;
        align-items: center;
        padding: 10px 14px;
        border-bottom: 1px solid var(--border-subtle);
        cursor: pointer;
        transition: background var(--transition-fast);
        gap: 8px;
    }

    .dw-lb-row:hover { background: var(--bg-card-hover); }
    .dw-lb-row.expanded { background: var(--bg-elevated); }

    .dw-lb-rank {
        font-weight: 700;
        color: var(--text-muted);
        min-width: 50px;
        display: flex;
        align-items: center;
        font-size: 0.9rem;
        flex-shrink: 0;
    }

    .dw-lb-arrow {
        font-size: 9px;
        margin-left: 4px;
        transition: transform var(--transition-normal);
        color: var(--text-muted);
    }

    .dw-lb-row.expanded .dw-lb-arrow { transform: rotate(180deg); }

    .dw-lb-info {
        flex: 1;
        min-width: 0;
        overflow: hidden;
    }

    .dw-lb-name {
        font-weight: 600;
        font-size: 0.9rem;
        color: var(--text-primary);
        line-height: 1.3;
    }

    .dw-lb-league {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-top: 1px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .dw-lb-wins {
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-primary);
        font-variant-numeric: tabular-nums;
        flex-shrink: 0;
        padding-right: 4px;
    }

    .dw-lb-teams {
        display: none;
        background: var(--bg-secondary);
        border-bottom: 1px solid var(--border-subtle);
    }

    .dw-lb-team-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 7px 14px 7px 60px;
        border-bottom: 1px solid var(--border-subtle);
    }

    .dw-lb-team-row:last-child { border-bottom: none; }

    .dw-lb-team-link {
        display: flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        color: var(--text-secondary);
        font-size: 0.85rem;
    }

    .dw-lb-team-link:hover { color: var(--accent-blue); }

    .dw-lb-team-wins {
        font-weight: 600;
        color: var(--text-primary);
        font-size: 0.9rem;
    }

    /* Draft Steals table */
    .dw-steals-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
    }

    .dw-steals-table th {
        padding: 10px 8px;
        text-align: left;
        font-weight: 600;
        color: var(--text-muted);
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        border-bottom: 1px solid var(--border-color);
        background: var(--bg-elevated);
    }

    .dw-steals-table td {
        padding: 10px 8px;
        border-bottom: 1px solid var(--border-subtle);
        color: var(--text-secondary);
    }

    .dw-steals-rank-col { width: 55px; text-align: center; }
    .dw-steals-value-col { text-align: center; width: 85px; }

    .dw-steals-team-link {
        display: flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        color: var(--text-primary);
        font-weight: 600;
    }

    .dw-steals-team-name {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .dw-steals-meta {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-top: 3px;
    }

    .dw-steals-show-mobile { display: none; }

    @media (max-width: 768px) {
        .dw-steals-hide-mobile { display: none; }
        .dw-steals-show-mobile { display: block; font-size: 0.72rem; }
        .dw-steals-table { font-size: 0.78rem; }
        .dw-steals-rank-col { width: 42px; }
        .dw-steals-team-name { max-width: 100px; }
    }

    /* Game List (Upcoming / Last 10) */
    .dw-game-list { padding: 0; }

    .dw-game-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 14px;
        border-bottom: 1px solid var(--border-subtle);
        text-decoration: none;
        color: inherit;
        transition: background var(--transition-fast);
    }

    .dw-game-item:last-child { border-bottom: none; }
    .dw-game-item:hover { background: var(--bg-card-hover); }

    .dw-game-win { background: var(--accent-green-dim); }
    .dw-game-win:hover { background: rgba(63, 185, 80, 0.18); }
    .dw-game-loss { background: var(--accent-red-dim); }
    .dw-game-loss:hover { background: rgba(248, 81, 73, 0.18); }

    .dw-game-info { flex: 1; min-width: 0; }

    .dw-game-date {
        font-size: 0.8rem;
        color: var(--text-muted);
        margin-bottom: 2px;
    }

    .dw-game-matchup {
        font-weight: 600;
        font-size: 0.9rem;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 2px;
    }

    .dw-game-owner {
        font-size: 0.8rem;
        color: var(--text-muted);
        font-weight: 400;
    }

    .dw-game-result { text-align: right; flex-shrink: 0; margin-left: 10px; }

    .dw-game-score {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 1px;
    }

    .dw-game-outcome {
        font-size: 0.85rem;
        font-weight: 700;
    }

    .dw-game-outcome.win { color: var(--accent-green); }
    .dw-game-outcome.loss { color: var(--accent-red); }

    /* Weekly Rankings */
    .dw-select {
        padding: 8px 32px 8px 14px;
        font-size: 0.9rem;
        font-family: 'Outfit', sans-serif;
        background: var(--bg-elevated);
        color: var(--text-primary);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-md);
        cursor: pointer;
        appearance: none;
        -webkit-appearance: none;
        min-width: 200px;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238b949e' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
    }

    .dw-select:focus {
        outline: none;
        border-color: var(--accent-blue);
        box-shadow: 0 0 0 2px var(--accent-blue-dim);
    }

    .dw-weekly-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 14px;
        border-bottom: 1px solid var(--border-subtle);
        margin: 0 4px 4px;
        border-radius: var(--radius-sm);
        background: var(--bg-secondary);
    }

    .dw-weekly-gold {
        background: linear-gradient(135deg, rgba(240, 198, 68, 0.12) 0%, rgba(240, 198, 68, 0.04) 100%);
        border: 1px solid rgba(240, 198, 68, 0.2);
    }

    .dw-weekly-silver {
        background: linear-gradient(135deg, rgba(160, 174, 192, 0.12) 0%, rgba(160, 174, 192, 0.04) 100%);
        border: 1px solid rgba(160, 174, 192, 0.15);
    }

    .dw-weekly-bronze {
        background: linear-gradient(135deg, rgba(205, 127, 50, 0.12) 0%, rgba(205, 127, 50, 0.04) 100%);
        border: 1px solid rgba(205, 127, 50, 0.15);
    }

    .dw-weekly-rank {
        font-size: 0.9rem;
        font-weight: 700;
        color: var(--text-muted);
        min-width: 28px;
        flex-shrink: 0;
    }

    .dw-weekly-name {
        font-size: 0.9rem;
        font-weight: 500;
        color: var(--text-primary);
        white-space: normal;
        line-height: 1.3;
    }

    .dw-weekly-wins {
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-primary);
        flex-shrink: 0;
        margin-left: 8px;
    }

    /* SOS Table */
    .dw-sos-table {
        width: 100%;
        border-collapse: collapse;
    }

    .dw-sos-table th {
        padding: 10px 8px;
        font-weight: 600;
        color: var(--text-muted);
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        border-bottom: 1px solid var(--border-color);
        background: var(--bg-elevated);
    }

    .dw-sos-table td {
        padding: 10px 8px;
        border-bottom: 1px solid var(--border-subtle);
        color: var(--text-secondary);
    }

    /* Sort buttons */
    .dw-sort-btn {
        padding: 8px 14px;
        background: var(--bg-elevated);
        color: var(--text-secondary);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-sm);
        cursor: pointer;
        font-size: 0.85rem;
        font-weight: 500;
        font-family: 'Outfit', sans-serif;
        transition: all var(--transition-fast);
    }

    .dw-sort-btn:hover {
        border-color: rgba(56, 139, 253, 0.3);
        color: var(--text-primary);
    }

    .dw-sort-active {
        background: var(--accent-blue) !important;
        color: white !important;
        border-color: var(--accent-blue) !important;
    }

    /* Widget responsive */
    @media (max-width: 600px) {
        .dw-lb-rank { min-width: 42px; font-size: 0.8rem; }
        .dw-lb-name { font-size: 0.85rem; }
        .dw-lb-league { font-size: 0.7rem; }
        .dw-lb-team-row { padding-left: 42px; }
        .dw-weekly-rank { font-size: 0.8rem; min-width: 24px; }
        .dw-weekly-name { font-size: 0.8rem; }
        .dw-weekly-wins { font-size: 0.9rem; }
        .dw-game-matchup { font-size: 0.82rem; }
        .dw-game-date { font-size: 0.75rem; }
        .dw-game-score { font-size: 0.9rem; }
        .dw-game-outcome { font-size: 0.8rem; }
        .dw-sos-table, .dw-steals-table { font-size: 0.78rem; }
        .dw-sos-table th, .dw-sos-table td,
        .dw-steals-table th, .dw-steals-table td { padding: 8px 4px; }
        .dw-team-stat-row { padding: 8px 10px; }
    }

</style>
</head>
<body>

    <?php 
    // Use new dark-themed components
    include '/data/www/default/nba-wins-platform/components/navigation_menu.php'; 
    ?>
    <?php include '/data/www/default/nba-wins-platform/components/LeagueSwitcher.php'; ?>

    <div class="app-container">

        <?php if ($isGuest): ?>
        <div class="guest-banner">
            <span><i class="fas fa-eye"></i> Browsing as guest</span>
            <div class="guest-banner-actions">
                <a href="/nba-wins-platform/auth/register.php" class="guest-banner-btn guest-signup-btn">Sign Up</a>
                <a href="/nba-wins-platform/auth/logout.php" class="guest-banner-btn guest-logout-btn"><i class="fas fa-sign-out-alt" style="margin-right:3px"></i>Exit</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- ==================== MAIN CONTENT GRID ==================== -->
        <div class="main-content-grid">

        <!-- ==================== LEFT COLUMN (standings + widgets) ==================== -->
        <div class="left-column">
        <div class="standings-section">
            <div class="standings-card">
                <div class="standings-header">
                    <span>#</span>
                    <span>Participant</span>
                    <span id="wins-header-text" onclick="toggleWinsDisplay()">Wins</span>
                </div>

                <?php 
                $rank = 1;
                $prevScore = null;
                $maxWins = !empty($standings) ? $standings[0]['total_wins'] : 1;
                if ($maxWins < 1) $maxWins = 1;
                foreach ($standings as $index => $participant): 
                    if ($prevScore !== null && $participant['total_wins'] < $prevScore) {
                        $rank = $index + 1;
                    }
                    $prevScore = $participant['total_wins'];
                    $rankClass = ($rank <= 3) ? "rank-$rank" : '';
                    $progressPct = round(($participant['total_wins'] / $maxWins) * 100, 1);
                    $staggerDelay = $index * 80; // 80ms per row
                ?>
                <div class="standings-row <?php echo $rankClass; ?>" 
                     onclick="toggleTeams('<?php echo htmlspecialchars($participant['name'], ENT_QUOTES); ?>', this)"
                     id="row-<?php echo htmlspecialchars($participant['name']); ?>"
                     data-win-streak="<?php echo $participant['win_streak']; ?>"
                     data-loss-streak="<?php echo $participant['loss_streak']; ?>"
                     style="animation-delay: <?php echo $staggerDelay; ?>ms; --progress: <?php echo $progressPct; ?>%">
                    <div class="rank-badge">
                        <span class="rank-num"><?php echo $rank; ?></span>
                        <i class="fas fa-chevron-down expand-arrow"></i>
                    </div>
                    <div class="participant-info">
                        <span class="participant-name-text"><?php echo htmlspecialchars($participant['name']); ?></span>
                        <?php if ($participant['win_streak'] >= 10): ?>
                            <i class="fas fa-fire lb-streak-icon animate" data-streak="<?php echo $participant['win_streak']; ?>"></i>
                        <?php elseif ($participant['loss_streak'] >= 10): ?>
                            <i class="fa-solid fa-snowflake lb-streak-icon cold animate" data-streak="<?php echo $participant['loss_streak']; ?>"></i>
                        <?php endif; ?>
                    </div>
                    <div class="wins-cell">
                        <?php if ($participant['wins_change'] > 0): ?>
                        <span class="wins-change-badge">
                            <i class="fa-solid fa-caret-up" style="font-size:10px;"></i><?php echo $participant['wins_change']; ?>
                        </span>
                        <?php endif; ?>
                        <span class="wins-number wins-display" 
                              data-wins="<?php echo $participant['total_wins']; ?>" 
                              data-win-percentage="<?php echo $participant['win_percentage']; ?>%">
                            0
                        </span>
                    </div>
                </div>

                <div class="team-detail-panel" id="panel-<?php echo htmlspecialchars($participant['name']); ?>">
                    <?php foreach ($participant['teams'] as $team): ?>
                    <div class="team-detail-row">
                        <div class="team-detail-info">
                            <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($team['name']); ?>">
                                <img src="<?php echo htmlspecialchars($team['logo']); ?>" alt="" class="team-detail-logo"
                                     onerror="this.style.opacity='0.3'">
                                <span class="team-detail-name"><?php echo htmlspecialchars($team['name']); ?></span>
                                <?php if ($team['streak'] >= 5 && $team['winstreak'] == 1): ?>
                                    <i class="fas fa-fire streak-icon hot" data-streak="<?php echo $team['streak']; ?>" title="Win streak: <?php echo $team['streak']; ?>"></i>
                                <?php elseif ($team['streak'] >= 5 && $team['winstreak'] == 0): ?>
                                    <i class="fa-solid fa-snowflake streak-icon cold" data-streak="<?php echo $team['streak']; ?>" title="Lose streak: <?php echo $team['streak']; ?>"></i>
                                <?php endif; ?>
                            </a>
                        </div>
                        <span class="team-detail-wins"><?php echo $team['wins']; ?></span>
                    </div>
                    <?php endforeach; ?>
                    <div class="profile-link-row">
                        <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?php echo $currentLeagueId; ?>&user_id=<?php echo $participant['user_id']; ?>" 
                           class="profile-link-btn">
                            <i class="fa-regular fa-user"></i> View Profile
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($showPlayoffPreview): ?>
            <a href="/nba-wins-platform/recap/playoff_preview.php" class="playoff-preview-banner">
                <img src="/nba-wins-platform/public/assets/league_logos/nba_champ.png" alt="">
                Playoff Analysis
            </a>
            <?php endif; ?>
        </div>

        <!-- Dashboard Widgets (inside left column on desktop, hidden for guests) -->
        <?php if (!empty($pinnedWidgets) && !$isGuest): ?>
        <div class="dashboard-widgets-section">
            <div class="dashboard-widgets-header">
                <div class="dashboard-divider"></div>
                <button class="widget-edit-toggle <?php echo $widgetEditMode ? 'active' : ''; ?>" onclick="toggleEditMode()" title="<?php echo $widgetEditMode ? 'Done editing' : 'Edit dashboard'; ?>">
                    <i class="fas fa-<?php echo $widgetEditMode ? 'check' : 'pen'; ?>"></i>
                </button>
            </div>
            
            <?php foreach ($pinnedWidgets as $widget): ?>
                <?php echo $dashboardWidget->render(
                    $widget['widget_type'], 
                    $_SESSION['user_id'], 
                    $currentLeagueId,
                    $widgetEditMode,
                    $selectedDate
                ); ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        </div><!-- end left-column -->

        <!-- ==================== GAMES ==================== -->
        <div class="games-section">

            <!-- Date Scroll Bar -->
            <div class="date-scroll-container">
                <button class="date-nav-btn" onclick="scrollDates(-1)" title="Scroll left">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <div class="date-scroll" id="dateScroll">
                    <?php foreach ($dateRange as $d): ?>
                    <a href="?date=<?php echo $d['date']; ?>" 
                       class="date-item <?php echo $d['isSelected'] ? 'selected' : ''; ?> <?php echo $d['isToday'] ? 'today' : ''; ?>"
                       data-date="<?php echo $d['date']; ?>">
                        <span class="date-day-name"><?php echo $d['dayName']; ?></span>
                        <span class="date-day-num"><?php echo $d['dayNum']; ?></span>
                        <span class="date-month"><?php echo $d['monthShort']; ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <button class="date-nav-btn" onclick="scrollDates(1)" title="Scroll right">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>

            <!-- Filter Bar -->
            <div class="games-filter-bar">
                <?php $gameCounts = getParticipantGameCounts($games, $participants); ?>
                <select id="participant-filter" class="filter-select" onchange="filterGames()">
                    <option value="">All Participants</option>
                    <?php foreach ($standings as $participant): 
                        $gameCount = $gameCounts[$participant['name']] ?? 0;
                    ?>
                    <option value="<?php echo htmlspecialchars($participant['name']); ?>">
                        (<?php echo $gameCount; ?>) <?php echo htmlspecialchars($participant['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button onclick="refreshScores()" class="refresh-btn" title="Refresh Scores">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </button>
            </div>

            <?php 
            $allStarStart = $season['all_star_break_start'];
            $allStarEnd = $season['all_star_break_end'];
            $isAllStarBreak = ($selectedDate >= $allStarStart && $selectedDate <= $allStarEnd);
            $todayStr = date('Y-m-d');
            $isAllStarBreakToday = (isset($_GET['preview']) && $_GET['preview'] === 'allstar') 
                ? true 
                : ($todayStr >= $allStarStart && $todayStr <= $allStarEnd);
            ?>

            <?php if ($isAllStarBreak && empty($games)): ?>
                <div class="allstar-break">
                    <div class="allstar-break-title">All-Star Break</div>
                    <div class="allstar-break-sub">No regular season games during the All-Star Break.</div>
                    <?php if ($isAllStarBreakToday): ?>
                    <a href="/nba-wins-platform/recap/all_star_recap.php" class="allstar-recap-btn">
                        🎬 View All-Star Recap
                    </a>
                    <?php endif; ?>
                </div>
            <?php elseif (empty($games)): ?>
                <div class="no-games">
                    <i class="fas fa-basketball-ball"></i>
                    <div class="no-games-title">No Games</div>
                    <div class="no-games-sub">No games scheduled for <?php echo date('M j', strtotime($selectedDate)); ?></div>
                </div>
            <?php else: ?>
                <div class="games-grid">
                    <?php 
                    foreach ($games as $game):
                        $game_key = $game['date'] . '_' . $game['home_team'] . ' vs ' . $game['away_team'];
                        $current_scores = $latest_scores[$game_key] ?? null;
                        
                        $home_points = $current_scores ? $current_scores['home_points'] : $game['home_points'];
                        $away_points = $current_scores ? $current_scores['away_points'] : $game['away_points'];
                        $game_status = $current_scores ? $current_scores['status'] : $game['status_long'];
                        $is_live = $current_scores && $current_scores['source'] === 'api' && isset($current_scores['game_status']) && $current_scores['game_status'] === 2;
                        $is_final = ($game_status === 'Final' || $game_status === 'Finished' || $game['status_long'] === 'Finished');
                        $is_postponed = ($game_status === 'Postponed' || $game['status_long'] === 'Postponed');
                        
                        $gameKey = $game['home_team'] . '-' . $game['away_team'];
                        $streamUrl = isset($streamUrls[$gameKey]) ? $streamUrls[$gameKey] : 'https://thetvapp.to/nba';
                        $startTime = new DateTime($game['start_time']);
                        $currentTime = new DateTime();
                        $hasStarted = $currentTime >= $startTime;
                    ?>
                    <div class="game-card" 
                         data-home-participant="<?php echo htmlspecialchars($game['home_participant'] ?? ''); ?>"
                         data-away-participant="<?php echo htmlspecialchars($game['away_participant'] ?? ''); ?>">
                        <?php if ($isNbaCupDate): ?>
                            <img class="game-special-logo" src="/nba-wins-platform/public/assets/league_logos/nba_cup.png" alt="">
                        <?php elseif ($isFinalsDate): ?>
                            <img class="game-special-logo" src="/nba-wins-platform/public/assets/league_logos/nba_champ.png" alt="">
                        <?php endif; ?>
                        <div class="game-card-body">
                            <div class="game-team-row <?php echo ($is_final && $away_points > $home_points) ? 'winner' : ''; ?>">
                                <div class="game-team-left">
                                    <img src="<?php echo htmlspecialchars(getTeamLogo($game['away_team'])); ?>" 
                                         alt="" class="game-team-logo" onerror="this.style.opacity='0.3'">
                                    <div>
                                        <span class="game-team-name">
                                            <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($game['away_team']); ?>">
                                                <?php echo htmlspecialchars($game['away_team_code'] ?? substr($game['away_team'], 0, 3)); ?>
                                            </a>
                                        </span>
                                        <?php if (!empty($game['away_participant'])): ?>
                                            <span class="game-participant-tag"><?php echo htmlspecialchars($game['away_participant']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="game-team-score"><?php echo ($home_points || $away_points) ? $away_points : ''; ?></div>
                            </div>
                            <div class="game-team-row <?php echo ($is_final && $home_points > $away_points) ? 'winner' : ''; ?>">
                                <div class="game-team-left">
                                    <img src="<?php echo htmlspecialchars(getTeamLogo($game['home_team'])); ?>" 
                                         alt="" class="game-team-logo" onerror="this.style.opacity='0.3'">
                                    <div>
                                        <span class="game-team-name">
                                            <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($game['home_team']); ?>">
                                                <?php echo htmlspecialchars($game['home_team_code'] ?? substr($game['home_team'], 0, 3)); ?>
                                            </a>
                                        </span>
                                        <?php if (!empty($game['home_participant'])): ?>
                                            <span class="game-participant-tag"><?php echo htmlspecialchars($game['home_participant']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="game-team-score"><?php echo ($home_points || $away_points) ? $home_points : ''; ?></div>
                            </div>
                        </div>
                        <div class="game-footer">
                            <?php if ($is_final): ?>
                                <div class="game-btn game-btn-final">Final</div>
                                <a href="/nba-wins-platform/stats/game_details.php?home_team=<?php echo urlencode($game['home_team_code'] ?? substr($game['home_team'], 0, 3)); ?>&away_team=<?php echo urlencode($game['away_team_code'] ?? substr($game['away_team'], 0, 3)); ?>&date=<?php echo urlencode($game['date']); ?>" 
                                   class="game-btn game-btn-secondary">Box Score</a>
                            <?php elseif ($is_postponed): ?>
                                <div class="game-btn game-btn-postponed">Postponed</div>
                                <a href="/nba-wins-platform/stats/team_comparison.php?home_team=<?php echo urlencode($game['home_team_code'] ?? substr($game['home_team'], 0, 3)); ?>&away_team=<?php echo urlencode($game['away_team_code'] ?? substr($game['away_team'], 0, 3)); ?>&date=<?php echo urlencode($game['date']); ?>" 
                                   class="game-btn game-btn-secondary">Preview</a>
                            <?php else: ?>
                                <?php 
                                $displayTime = $startTime->format('g:i A');
                                if (!empty($game['stream_game_time'])) {
                                    $streamTime = new DateTime($game['stream_game_time']);
                                    $displayTime = $streamTime->format('g:i A');
                                }
                                if ($is_live && !empty($current_scores['clock']) && !empty($current_scores['period'])) {
                                    $displayTime = $current_scores['clock'] . ' Q' . $current_scores['period'];
                                }
                                ?>
                                <a href="<?php echo htmlspecialchars($streamUrl); ?>" target="_blank" rel="noopener noreferrer" 
                                   class="game-btn game-btn-primary <?php echo $is_live ? 'live' : ''; ?>">
                                    <i class="fa-solid fa-video" style="margin-right: 4px;"></i> <?php echo $displayTime; ?>
                                </a>
                                <a href="<?php echo $hasStarted ? 
                                    '/nba-wins-platform/stats/game_details.php?home_team=' . urlencode($game['home_team_code'] ?? substr($game['home_team'], 0, 3)) . '&away_team=' . urlencode($game['away_team_code'] ?? substr($game['away_team'], 0, 3)) . '&date=' . urlencode($game['date']) :
                                    '/nba-wins-platform/stats/team_comparison.php?home_team=' . urlencode($game['home_team_code'] ?? substr($game['home_team'], 0, 3)) . '&away_team=' . urlencode($game['away_team_code'] ?? substr($game['away_team'], 0, 3)) . '&date=' . urlencode($game['date']); ?>" 
                                   class="game-btn game-btn-secondary">
                                    <?php echo $hasStarted ? 'Box Score' : 'Preview'; ?>
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php 
                        $badge = getSeriesBadge($game['home_team'], $game['away_team'], $seriesData, $isPlayoffDate);
                        if ($badge): ?>
                            <div class="series-badge <?php echo $badge['class']; ?>"><?php echo htmlspecialchars($badge['text']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        </div><!-- end main-content-grid -->
    </div>

    <script>
        // Date bar auto-scroll to selected (instant center on load)
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                var el = document.querySelector('.date-item.selected') || document.querySelector('.date-item.today');
                if (!el) return;
                var scroll = document.getElementById('dateScroll');
                var scrollRect = scroll.getBoundingClientRect();
                var elRect = el.getBoundingClientRect();
                // Calculate how far left the element is from the scroll container's left edge,
                // then offset so it lands in the center
                var offset = (elRect.left - scrollRect.left) + scroll.scrollLeft - (scrollRect.width / 2) + (elRect.width / 2);
                scroll.style.scrollBehavior = 'auto';
                scroll.scrollLeft = offset;
                scroll.style.scrollBehavior = 'smooth';
            }, 80);
        });

        // Desktop arrow navigation for date scroll
        function scrollDates(direction) {
            const scroll = document.getElementById('dateScroll');
            const scrollAmount = scroll.offsetWidth * 0.7;
            scroll.scrollBy({ left: direction * scrollAmount, behavior: 'smooth' });
        }

        // Standings expand/collapse
        function toggleTeams(participantName, row) {
            const panel = document.getElementById('panel-' + participantName);
            if (!panel) return;
            
            const isOpen = panel.classList.contains('show');
            
            if (isOpen) {
                panel.classList.remove('show');
                row.classList.remove('expanded');
            } else {
                // Assign cascade index to each child row
                const children = panel.querySelectorAll('.team-detail-row, .profile-link-row');
                children.forEach((el, i) => el.style.setProperty('--cascade-i', i));
                panel.classList.add('show');
                row.classList.add('expanded');
            }
        }

        // Toggle Wins / Win %
        function toggleWinsDisplay() {
            const currentMode = localStorage.getItem('winsDisplayMode') || 'wins';
            const newMode = currentMode === 'wins' ? 'percentage' : 'wins';
            localStorage.setItem('winsDisplayMode', newMode);
            
            const headerText = document.getElementById('wins-header-text');
            const winsDisplays = document.querySelectorAll('.wins-display');
            
            if (newMode === 'percentage') {
                headerText.textContent = 'Win %';
                winsDisplays.forEach(el => { el.textContent = el.getAttribute('data-win-percentage'); });
            } else {
                headerText.textContent = 'Wins';
                winsDisplays.forEach(el => { el.textContent = el.getAttribute('data-wins'); });
            }
        }

        // Percentage mode applied after count-up (see count-up code below)

        // Filter games
        function filterGames() {
            const selected = document.getElementById('participant-filter').value;
            document.querySelectorAll('.game-card').forEach(card => {
                const home = card.getAttribute('data-home-participant');
                const away = card.getAttribute('data-away-participant');
                if (selected === '' || home === selected || away === selected) {
                    card.classList.remove('hidden');
                } else {
                    card.classList.add('hidden');
                }
            });
        }

        // Track currently displayed date
        let currentSelectedDate = '<?php echo $selectedDate; ?>';

        // Refresh scores (AJAX, no full reload) + selective leaderboard update
        function refreshScores() {
            // Save current filter selection
            const filterEl = document.getElementById('participant-filter');
            if (filterEl && filterEl.value) window._savedFilterValue = filterEl.value;

            // Snapshot current wins from the DOM before fetching
            const oldWins = {};
            document.querySelectorAll('.standings-row').forEach(row => {
                const name = row.querySelector('.participant-name-text')?.textContent?.trim();
                const winsEl = row.querySelector('.wins-number');
                if (name && winsEl) {
                    oldWins[name] = parseInt(winsEl.getAttribute('data-wins')) || 0;
                }
            });

            // Store snapshot so loadDate callback can use it
            window._preRefreshWins = oldWins;
            window._isScoreRefresh = true;
            loadDate(currentSelectedDate);
        }

        // Silent auto-refresh: updates scores and leaderboard in-place, no DOM replacement
        let _autoRefreshInFlight = false;
        function autoRefreshScores() {
            if (_autoRefreshInFlight) return; // prevent stacking
            _autoRefreshInFlight = true;

            const oldWins = {};
            document.querySelectorAll('.standings-row').forEach(row => {
                const name = row.querySelector('.participant-name-text')?.textContent?.trim();
                const winsEl = row.querySelector('.wins-number');
                if (name && winsEl) {
                    oldWins[name] = parseInt(winsEl.getAttribute('data-wins')) || 0;
                }
            });

            fetch(window.location.pathname + '?date=' + currentSelectedDate)
                .then(r => r.text())
                .then(html => {
                    const doc = new DOMParser().parseFromString(html, 'text/html');

                    // --- Surgical game card updates (no DOM replacement) ---
                    const existingCards = document.querySelectorAll('.games-grid .game-card');
                    const newCards = doc.querySelectorAll('.games-grid .game-card');

                    existingCards.forEach((card, i) => {
                        const newCard = newCards[i];
                        if (!newCard) return;

                        // Update score numbers
                        const oldScores = card.querySelectorAll('.game-team-score');
                        const newScores = newCard.querySelectorAll('.game-team-score');
                        oldScores.forEach((el, j) => {
                            if (newScores[j] && el.textContent !== newScores[j].textContent) {
                                el.textContent = newScores[j].textContent;
                            }
                        });

                        // Update winner highlighting
                        const oldRows = card.querySelectorAll('.game-team-row');
                        const newRows = newCard.querySelectorAll('.game-team-row');
                        oldRows.forEach((el, j) => {
                            if (newRows[j]) {
                                el.classList.toggle('winner', newRows[j].classList.contains('winner'));
                            }
                        });

                        // Replace footer only (status badge, buttons)
                        const oldFooter = card.querySelector('.game-footer');
                        const newFooter = newCard.querySelector('.game-footer');
                        if (oldFooter && newFooter) {
                            oldFooter.innerHTML = newFooter.innerHTML;
                        }

                        // Update series badge
                        const oldBadge = card.querySelector('.series-badge');
                        const newBadge = newCard.querySelector('.series-badge');
                        if (newBadge) {
                            if (oldBadge) {
                                oldBadge.outerHTML = newBadge.outerHTML;
                            } else {
                                card.appendChild(newBadge.cloneNode(true));
                            }
                        } else if (oldBadge) {
                            oldBadge.remove();
                        }
                    });

                    // --- Selective leaderboard update ---
                    const newWins = {};
                    doc.querySelectorAll('.standings-row').forEach(row => {
                        const name = row.querySelector('.participant-name-text')?.textContent?.trim();
                        const winsEl = row.querySelector('.wins-number');
                        if (name && winsEl) {
                            newWins[name] = parseInt(winsEl.getAttribute('data-wins')) || 0;
                        }
                    });

                    document.querySelectorAll('.standings-row').forEach(row => {
                        const name = row.querySelector('.participant-name-text')?.textContent?.trim();
                        const winsEl = row.querySelector('.wins-number');
                        if (!name || !winsEl) return;

                        const oldVal = oldWins[name] ?? 0;
                        const newVal = newWins[name] ?? oldVal;

                        if (newVal !== oldVal) {
                            winsEl.setAttribute('data-wins', newVal);
                            // Update win percentage from fetched data
                            const fetchedRow = [...doc.querySelectorAll('.standings-row')].find(r =>
                                r.querySelector('.participant-name-text')?.textContent?.trim() === name
                            );
                            if (fetchedRow) {
                                const newPct = fetchedRow.querySelector('.wins-number')?.getAttribute('data-win-percentage');
                                if (newPct) winsEl.setAttribute('data-win-percentage', newPct);
                            }

                            const isPercentageMode = localStorage.getItem('winsDisplayMode') === 'percentage';
                            if (!isPercentageMode) winsEl.textContent = newVal;

                            row.classList.remove('wins-flash-fade');
                            row.classList.add('wins-flash');
                            setTimeout(() => {
                                row.classList.remove('wins-flash');
                                row.classList.add('wins-flash-fade');
                            }, 1500);
                        }
                    });

                    // Re-rank leaderboard after updating wins
                    const updatedWins = {};
                    document.querySelectorAll('.standings-row').forEach(row => {
                        const name = row.querySelector('.participant-name-text')?.textContent?.trim();
                        const winsEl = row.querySelector('.wins-number');
                        if (!name || !winsEl) return;
                        updatedWins[name] = parseInt(winsEl.getAttribute('data-wins')) || 0;
                    });

                    // Update progress bars
                    const allWins = Object.values(updatedWins);
                    const maxWins = Math.max(...allWins, 1);
                    document.querySelectorAll('.standings-row').forEach(row => {
                        const name = row.querySelector('.participant-name-text')?.textContent?.trim();
                        const w = updatedWins[name] ?? 0;
                        row.style.setProperty('--progress', (w / maxWins * 100).toFixed(1) + '%');
                    });

                    // --- Update win/loss streak flames/ice ---
                    document.querySelectorAll('.standings-row').forEach(row => {
                        const name = row.querySelector('.participant-name-text')?.textContent?.trim();
                        const fetchedRow = [...doc.querySelectorAll('.standings-row')].find(r =>
                            r.querySelector('.participant-name-text')?.textContent?.trim() === name
                        );
                        if (!fetchedRow) return;

                        const oldWinStreak = parseInt(row.getAttribute('data-win-streak')) || 0;
                        const oldLossStreak = parseInt(row.getAttribute('data-loss-streak')) || 0;
                        const newWinStreak = parseInt(fetchedRow.getAttribute('data-win-streak')) || 0;
                        const newLossStreak = parseInt(fetchedRow.getAttribute('data-loss-streak')) || 0;

                        if (newWinStreak === oldWinStreak && newLossStreak === oldLossStreak) return;

                        row.setAttribute('data-win-streak', newWinStreak);
                        row.setAttribute('data-loss-streak', newLossStreak);
                        const info = row.querySelector('.participant-info');
                        const existingIcon = info?.querySelector('.lb-streak-icon');

                        // Determine what icon should be showing
                        const shouldShowFire = newWinStreak >= 10;
                        const shouldShowIce = newLossStreak >= 10;

                        if (!shouldShowFire && !shouldShowIce) {
                            // No streak — remove any icon
                            if (existingIcon) existingIcon.remove();
                        } else if (shouldShowFire) {
                            if (existingIcon && existingIcon.classList.contains('cold')) {
                                // Was ice, now fire — swap
                                existingIcon.remove();
                            }
                            const icon = info?.querySelector('.lb-streak-icon');
                            if (icon) {
                                // Update existing fire
                                icon.setAttribute('data-streak', newWinStreak);
                                if (newWinStreak !== oldWinStreak) {
                                    icon.classList.remove('animate');
                                    void icon.offsetWidth;
                                    icon.classList.add('animate');
                                }
                            } else if (info) {
                                // Add new fire
                                const flame = document.createElement('i');
                                flame.className = 'fas fa-fire lb-streak-icon animate';
                                flame.setAttribute('data-streak', newWinStreak);
                                info.appendChild(flame);
                            }
                        } else if (shouldShowIce) {
                            if (existingIcon && !existingIcon.classList.contains('cold')) {
                                // Was fire, now ice — swap
                                existingIcon.remove();
                            }
                            const icon = info?.querySelector('.lb-streak-icon');
                            if (icon) {
                                // Update existing ice
                                icon.setAttribute('data-streak', newLossStreak);
                                if (newLossStreak !== oldLossStreak) {
                                    icon.classList.remove('animate');
                                    void icon.offsetWidth;
                                    icon.classList.add('animate');
                                }
                            } else if (info) {
                                // Add new ice
                                const ice = document.createElement('i');
                                ice.className = 'fa-solid fa-snowflake lb-streak-icon cold animate';
                                ice.setAttribute('data-streak', newLossStreak);
                                info.appendChild(ice);
                            }
                        }
                    });

                    // --- Update dashboard widgets in-place (score-dependent only) ---
                    ['league_stats', 'last_10_games', 'exceeding_expectations', 'falling_short'].forEach(type => {
                        const oldWidget = document.querySelector('.dw-card[data-widget-type="' + type + '"] .dw-body');
                        const newWidget = doc.querySelector('.dw-card[data-widget-type="' + type + '"] .dw-body');
                        if (oldWidget && newWidget) {
                            oldWidget.innerHTML = newWidget.innerHTML;
                        }
                    });

                    // Check if leaderboard order changed — only reorder + cascade if it did
                    const currentOrder = [...document.querySelectorAll('.standings-row')].map(r =>
                        r.querySelector('.participant-name-text')?.textContent?.trim()
                    );

                    // Build new order sorted by wins desc, win% tiebreaker
                    const sortable = currentOrder.map(name => {
                        const row = [...document.querySelectorAll('.standings-row')].find(r =>
                            r.querySelector('.participant-name-text')?.textContent?.trim() === name
                        );
                        const winsEl = row?.querySelector('.wins-number');
                        return {
                            name,
                            wins: updatedWins[name] ?? 0,
                            winPct: winsEl ? parseFloat(winsEl.getAttribute('data-win-percentage')) || 0 : 0
                        };
                    });
                    sortable.sort((a, b) => {
                        if (b.wins !== a.wins) return b.wins - a.wins;
                        return b.winPct - a.winPct;
                    });
                    const newOrder = sortable.map(s => s.name);

                    const orderChanged = currentOrder.some((name, i) => name !== newOrder[i]);

                    if (orderChanged) {
                        // Positions changed — use reRankLeaderboard which reorders DOM + cascades
                        reRankLeaderboard(updatedWins);
                    } else {
                        // Same positions — just update rank numbers in-place, no animation
                        let rank = 1;
                        sortable.forEach((entry, index) => {
                            if (index > 0 && entry.wins < sortable[index - 1].wins) rank = index + 1;
                            const row = [...document.querySelectorAll('.standings-row')].find(r =>
                                r.querySelector('.participant-name-text')?.textContent?.trim() === entry.name
                            );
                            if (row) {
                                const rankNum = row.querySelector('.rank-num');
                                if (rankNum) rankNum.textContent = rank;
                                row.classList.remove('rank-1', 'rank-2', 'rank-3');
                                if (rank <= 3) row.classList.add('rank-' + rank);
                            }
                        });
                    }
                })
                .catch(err => { console.error('Auto-refresh error:', err); })
                .finally(() => { _autoRefreshInFlight = false; });
        }

        // Auto-refresh every 15s when live games showing OR within game window
        const _earliestGameMs = <?= $earliestPendingGameMs ?? 'null' ?>;
        const _pollLeadMs = 30 * 60 * 1000; // 30 minutes before first tip

        setInterval(function() {
            const hasLive = document.querySelector('.game-btn-primary.live');
            const inGameWindow = _earliestGameMs && (Date.now() >= _earliestGameMs - _pollLeadMs);
            if (hasLive || inGameWindow) {
                autoRefreshScores();
            }
        }, 15000);

        // Selective leaderboard update after AJAX refresh
        function updateLeaderboardSelective(fetchedDoc) {
            const oldWins = window._preRefreshWins || {};
            window._preRefreshWins = null;
            window._isScoreRefresh = false;

            // Parse new wins from the fetched page
            const newRows = fetchedDoc.querySelectorAll('.standings-row');
            const newWins = {};
            newRows.forEach(row => {
                const name = row.querySelector('.participant-name-text')?.textContent?.trim();
                const winsEl = row.querySelector('.wins-number');
                if (name && winsEl) {
                    newWins[name] = parseInt(winsEl.getAttribute('data-wins')) || 0;
                }
            });

            // Compare and animate only changed participants
            let anyChanged = false;
            document.querySelectorAll('.standings-row').forEach(row => {
                const name = row.querySelector('.participant-name-text')?.textContent?.trim();
                const winsEl = row.querySelector('.wins-number');
                if (!name || !winsEl) return;

                const oldVal = oldWins[name] ?? 0;
                const newVal = newWins[name] ?? oldVal;

                if (newVal !== oldVal) {
                    anyChanged = true;
                    // Update the data attribute
                    winsEl.setAttribute('data-wins', newVal);

                    // Also update win percentage if available
                    const newRow = [...newRows].find(r => 
                        r.querySelector('.participant-name-text')?.textContent?.trim() === name
                    );
                    if (newRow) {
                        const newPct = newRow.querySelector('.wins-number')?.getAttribute('data-win-percentage');
                        if (newPct) winsEl.setAttribute('data-win-percentage', newPct);
                    }

                    // Check if currently in percentage mode
                    const isPercentageMode = localStorage.getItem('winsDisplayMode') === 'percentage';

                    // Flash the row green — stays on through entire count-up
                    row.classList.remove('wins-flash-fade');
                    row.classList.add('wins-flash');

                    // Delay count start until name is invisible (pulse is at 30-60%)
                    // At 250ms the name is hidden, we start counting from 0.
                    // By 420ms when name returns, count is ~15% through = well above 0.
                    setTimeout(() => {
                        const duration = 1100;
                        const startTime = performance.now();

                        function tick(now) {
                            const elapsed = now - startTime;
                            const progress = Math.min(elapsed / duration, 1);
                            const eased = 1 - Math.pow(1 - progress, 3);
                            const current = Math.round(newVal * eased);
                            if (!isPercentageMode) winsEl.textContent = current || '';
                            if (progress < 1) {
                                requestAnimationFrame(tick);
                            } else {
                                winsEl.textContent = isPercentageMode
                                    ? winsEl.getAttribute('data-win-percentage')
                                    : newVal;
                                // Hold flash a moment, then fade out
                                setTimeout(() => {
                                    row.classList.add('wins-flash-fade');
                                    setTimeout(() => {
                                        row.classList.remove('wins-flash', 'wins-flash-fade');
                                    }, 1500);
                                }, 800);
                            }
                        }
                        requestAnimationFrame(tick);
                    }, 250);

                    // Update wins-change badge
                    const winsCell = row.querySelector('.wins-cell');
                    const existingBadge = winsCell?.querySelector('.wins-change-badge');
                    const newRowMatch = [...newRows].find(r =>
                        r.querySelector('.participant-name-text')?.textContent?.trim() === name
                    );
                    const newBadge = newRowMatch?.querySelector('.wins-change-badge');
                    if (newBadge && winsCell) {
                        if (existingBadge) existingBadge.remove();
                        winsCell.insertBefore(newBadge.cloneNode(true), winsEl);
                    }
                }
            });

            // Update progress bar widths if max wins changed
            if (anyChanged) {
                const allWins = Object.values(newWins);
                const maxWins = Math.max(...allWins, 1);
                document.querySelectorAll('.standings-row').forEach(row => {
                    const name = row.querySelector('.participant-name-text')?.textContent?.trim();
                    const w = newWins[name] ?? 0;
                    row.style.setProperty('--progress', (w / maxWins * 100).toFixed(1) + '%');
                });

                // Re-sort DOM rows and update rank numbers/classes
                reRankLeaderboard(newWins);
            }
        }

        // Re-sort leaderboard rows by wins (desc) and update rank numbers + classes
        function reRankLeaderboard(newWins) {
            const container = document.querySelector('.standings-card');
            if (!container) return;

            // Collect each standings-row paired with its following team-detail-panel
            const pairs = [];
            const rows = container.querySelectorAll('.standings-row');
            rows.forEach(row => {
                const name = row.querySelector('.participant-name-text')?.textContent?.trim();
                const wins = newWins[name] ?? 0;
                const winsEl = row.querySelector('.wins-number');
                const winPct = winsEl ? parseFloat(winsEl.getAttribute('data-win-percentage')) || 0 : 0;
                // The panel immediately follows the row in the DOM
                const panel = row.nextElementSibling?.classList.contains('team-detail-panel')
                    ? row.nextElementSibling : null;
                pairs.push({ row, panel, name, wins, winPct });
            });

            // Sort descending by wins, then by win percentage as tiebreaker
            pairs.sort((a, b) => {
                if (b.wins !== a.wins) return b.wins - a.wins;
                return b.winPct - a.winPct;
            });

            // Re-append in new order (after the standings-header)
            const header = container.querySelector('.standings-header');
            pairs.forEach(({ row, panel }) => {
                container.appendChild(row);
                if (panel) container.appendChild(panel);
            });

            // Recalculate ranks with tie handling and update display
            let rank = 1;
            pairs.forEach((entry, index) => {
                if (index > 0 && entry.wins < pairs[index - 1].wins) {
                    rank = index + 1;
                }

                const row = entry.row;
                // Update rank number text
                const rankNum = row.querySelector('.rank-num');
                if (rankNum) rankNum.textContent = rank;

                // Update rank CSS classes
                row.classList.remove('rank-1', 'rank-2', 'rank-3');
                if (rank <= 3) row.classList.add('rank-' + rank);
            });
        }

        /**
         * AJAX date switch — fetches the page for a new date,
         * extracts the games-section content, and swaps it in.
         * Standings + widgets stay untouched.
         */
        function loadDate(dateStr, slideDir) {
            const gamesSection = document.querySelector('.games-section');
            if (!gamesSection) return;

            // Save scroll positions before swap
            const savedPageScroll = window.scrollY;
            const savedGamesScroll = gamesSection.scrollTop;

            // Visual feedback: dim current games
            gamesSection.classList.add('loading-games');
            gamesSection.classList.remove('games-enter');

            // Optional slide-out direction
            if (slideDir) {
                const inner = gamesSection.querySelector('.games-grid, .no-games, .allstar-break');
                if (inner) {
                    inner.style.transition = 'transform 0.15s ease, opacity 0.15s ease';
                    inner.style.transform = 'translateX(' + (slideDir === 'left' ? '-30px' : '30px') + ')';
                    inner.style.opacity = '0';
                }
            }

            fetch(window.location.pathname + '?date=' + dateStr)
                .then(r => r.text())
                .then(html => {
                    // Parse the fetched HTML
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newGames = doc.querySelector('.games-section');

                    if (newGames) {
                        // Replace inner content (keep the .games-section container)
                        gamesSection.innerHTML = newGames.innerHTML;
                    }

                    // If this was a score refresh, selectively update leaderboard
                    if (window._isScoreRefresh) {
                        updateLeaderboardSelective(doc);
                    }

                    // Update date bar selection
                    document.querySelectorAll('.date-item').forEach(d => {
                        d.classList.toggle('selected', d.getAttribute('data-date') === dateStr);
                    });

                    // Update browser URL without reload
                    const newUrl = window.location.pathname + '?date=' + dateStr;
                    history.pushState({ date: dateStr }, '', newUrl);
                    currentSelectedDate = dateStr;

                    // Re-bind the participant filter dropdown and restore selection
                    const newFilter = gamesSection.querySelector('#participant-filter');
                    if (newFilter) {
                        newFilter.addEventListener('change', filterGames);
                        if (window._savedFilterValue) {
                            newFilter.value = window._savedFilterValue;
                            window._savedFilterValue = null;
                            filterGames();
                        }
                    }

                    // Re-attach swipe listeners
                    attachSwipeListeners();

                    // Restore scroll positions
                    window.scrollTo(0, savedPageScroll);
                    gamesSection.scrollTop = savedGamesScroll;

                    // Re-center date picker on selected date (it was replaced by innerHTML swap)
                    const newScroll = gamesSection.querySelector('#dateScroll') || document.getElementById('dateScroll');
                    const newSelected = gamesSection.querySelector('.date-item.selected');
                    if (newScroll && newSelected) {
                        const scrollRect = newScroll.getBoundingClientRect();
                        const elRect = newSelected.getBoundingClientRect();
                        const offset = (elRect.left - scrollRect.left) + newScroll.scrollLeft - (scrollRect.width / 2) + (elRect.width / 2);
                        newScroll.style.scrollBehavior = 'auto';
                        newScroll.scrollLeft = offset;
                        newScroll.style.scrollBehavior = 'smooth';
                    }

                    // Entrance animation
                    gamesSection.classList.remove('loading-games');
                    gamesSection.classList.add('games-enter');
                    setTimeout(() => gamesSection.classList.remove('games-enter'), 350);
                })
                .catch(err => {
                    console.error('Date load error:', err);
                    window._isScoreRefresh = false;
                    window._preRefreshWins = null;
                    gamesSection.classList.remove('loading-games');
                    // Fallback: full reload
                    window.location.href = window.location.pathname + '?date=' + dateStr;
                });
        }

        // Intercept date-item clicks for AJAX navigation
        document.addEventListener('click', function(e) {
            const dateItem = e.target.closest('.date-item');
            if (!dateItem) return;
            e.preventDefault();
            const date = dateItem.getAttribute('data-date');
            if (date && date !== currentSelectedDate) {
                loadDate(date);
                // Center the clicked date in scroll bar
                setTimeout(() => {
                    const scroll = document.getElementById('dateScroll');
                    const scrollRect = scroll.getBoundingClientRect();
                    const elRect = dateItem.getBoundingClientRect();
                    const offset = (elRect.left - scrollRect.left) + scroll.scrollLeft - (scrollRect.width / 2) + (elRect.width / 2);
                    scroll.scrollTo({ left: offset, behavior: 'smooth' });
                }, 50);
            }
        });

        // Handle browser back/forward
        window.addEventListener('popstate', function(e) {
            if (e.state && e.state.date) {
                loadDate(e.state.date);
            }
        });

        // Count-up animation + progress bar trigger
        (function() {
            const rows = document.querySelectorAll('.standings-row');
            const totalRows = rows.length;
            const totalStagger = totalRows * 80; // matches CSS stagger

            // Start count-up immediately so numbers are mid-count when rows appear
            (function() {
                var pending = 0;
                document.querySelectorAll('.wins-number').forEach(function(el) {
                    const target = parseInt(el.getAttribute('data-wins'));
                    if (isNaN(target) || target === 0) { el.textContent = target; return; }
                    pending++;
                    const duration = 1300; // ms
                    const startTime = performance.now();

                    function tick(now) {
                        const elapsed = now - startTime;
                        const progress = Math.min(elapsed / duration, 1);
                        // Ease-out cubic
                        const eased = 1 - Math.pow(1 - progress, 3);
                        el.textContent = Math.round(eased * target);
                        if (progress < 1) {
                            requestAnimationFrame(tick);
                        } else {
                            el.textContent = target;
                            pending--;
                            // After all count-ups done, apply percentage mode if saved
                            if (pending === 0 && localStorage.getItem('winsDisplayMode') === 'percentage') {
                                toggleWinsDisplay();
                            }
                        }
                    }
                    requestAnimationFrame(tick);
                });

                // If no countable elements, still apply percentage mode
                if (pending === 0 && localStorage.getItem('winsDisplayMode') === 'percentage') {
                    toggleWinsDisplay();
                }
            })();

            // Trigger progress bars with per-row stagger for a filling cascade
            setTimeout(function() {
                rows.forEach(function(row, i) {
                    setTimeout(function() { row.classList.add('animate-bars'); }, i * 100);
                });
            }, totalStagger + 200);
        })();

        // Swipe to change date on games section (AJAX)
        function attachSwipeListeners() {
            const gamesSection = document.querySelector('.games-section');
            if (!gamesSection) return;

            // Remove old listeners by cloning the inner content area
            // (event delegation approach — listeners on games-section itself)
            let touchStartX = 0;
            let touchStartY = 0;
            let touchStartTime = 0;
            let swiping = false;

            // Remove previous listeners if any (use a flag)
            if (gamesSection._swipeBound) return;
            gamesSection._swipeBound = true;

            gamesSection.addEventListener('touchstart', function(e) {
                // Don't activate swipe-to-change-date when touching the date picker
                if (e.target.closest('.date-scroll-container')) {
                    swiping = false;
                    return;
                }
                touchStartX = e.touches[0].clientX;
                touchStartY = e.touches[0].clientY;
                touchStartTime = Date.now();
                swiping = true;
            }, { passive: true });

            gamesSection.addEventListener('touchend', function(e) {
                if (!swiping) return;
                swiping = false;

                const dx = e.changedTouches[0].clientX - touchStartX;
                const dy = e.changedTouches[0].clientY - touchStartY;
                const dt = Date.now() - touchStartTime;

                if (Math.abs(dx) < 60 || Math.abs(dy) > Math.abs(dx) * 0.75 || dt > 400) return;

                // Calculate prev/next from currently selected date
                const allDates = Array.from(document.querySelectorAll('.date-item')).map(d => d.getAttribute('data-date'));
                const currentIdx = allDates.indexOf(currentSelectedDate);
                if (currentIdx === -1) return;

                const targetIdx = dx > 0 ? currentIdx - 1 : currentIdx + 1;
                if (targetIdx < 0 || targetIdx >= allDates.length) return;

                const targetDate = allDates[targetIdx];
                const slideDir = dx > 0 ? 'right' : 'left';

                loadDate(targetDate, slideDir);

                // Also scroll the date bar to center the new date
                const targetEl = document.querySelector('.date-item[data-date="' + targetDate + '"]');
                if (targetEl) {
                    setTimeout(() => {
                        const scroll = document.getElementById('dateScroll');
                        const scrollRect = scroll.getBoundingClientRect();
                        const elRect = targetEl.getBoundingClientRect();
                        const offset = (elRect.left - scrollRect.left) + scroll.scrollLeft - (scrollRect.width / 2) + (elRect.width / 2);
                        scroll.scrollTo({ left: offset, behavior: 'smooth' });
                    }, 50);
                }
            }, { passive: true });
        }
        attachSwipeListeners();

        // ===== Widget Management =====
        function toggleEditMode() {
            sessionStorage.setItem('scrollPosition', window.scrollY);
            const currentUrl = new URL(window.location.href);
            const editMode = currentUrl.searchParams.get('edit_widgets');
            if (editMode === '1') {
                currentUrl.searchParams.delete('edit_widgets');
            } else {
                currentUrl.searchParams.set('edit_widgets', '1');
            }
            window.location.href = currentUrl.toString();
        }

        function moveWidget(widgetType, direction) {
            const formData = new FormData();
            formData.append('action', 'reorder');
            formData.append('widget_type', widgetType);
            formData.append('direction', direction);
            
            fetch('/nba-wins-platform/core/handle_widget_pin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else if (data.error && !data.error.includes('Already at')) {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        function removeWidget(widgetType) {
            if (!confirm('Remove this widget from your dashboard?')) return;
            
            const formData = new FormData();
            formData.append('action', 'unpin');
            formData.append('widget_type', widgetType);
            
            fetch('/nba-wins-platform/core/handle_widget_pin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        // Restore scroll position (for widget edits and general navigation)
        (function() {
            const savedPos = sessionStorage.getItem('scrollPosition');
            if (savedPos !== null) {
                setTimeout(() => {
                    window.scrollTo(0, parseInt(savedPos));
                    sessionStorage.removeItem('scrollPosition');
                }, 120);
            }
        })();
    </script>

    <?php $currentPage = 'home'; include '/data/www/default/nba-wins-platform/components/pill_nav.php'; ?>
</body>
</html>