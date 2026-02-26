<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['current_league_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once '/data/www/default/nba-wins-platform/config/db_connection.php';

$user_id = $_SESSION['user_id'];
$league_id = $_SESSION['current_league_id'];
$currentLeagueId = $league_id;

// ==================== AJAX ENDPOINT FOR ROUND DATA ====================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'round_data') {
    header('Content-Type: application/json');
    require_once '/data/www/default/nba-wins-platform/config/db_connection.php';

    $stmt = $pdo->prepare("
        SELECT ds.*, COUNT(dp.id) as total_picks,
               (SELECT COUNT(*) FROM league_participants WHERE league_id = ? AND status = 'active') as participant_count
        FROM draft_sessions ds
        LEFT JOIN draft_picks dp ON ds.id = dp.draft_session_id
        WHERE ds.league_id = ?
        GROUP BY ds.id
        ORDER BY ds.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$league_id, $league_id]);
    $draft_info_ajax = $stmt->fetch();

    if (!$draft_info_ajax) { echo json_encode([]); exit; }

    $round = isset($_GET['round']) ? max(1, min((int)$_GET['round'], $draft_info_ajax['total_rounds'])) : 1;
    $mode = isset($_GET['mode']) ? $_GET['mode'] : 'order';

    $stmt = $pdo->prepare("
        SELECT 
            dp.id, dp.pick_number, dp.round_number, dp.picked_at, dp.picked_by_commissioner,
            nt.name as team_name, nt.abbreviation, nt.logo_filename as logo,
            lp.participant_name, u.display_name,
            COALESCE(nss.win, 0) as wins, COALESCE(nss.loss, 0) as losses
        FROM draft_picks dp
        JOIN nba_teams nt ON dp.team_id = nt.id
        JOIN league_participants lp ON dp.league_participant_id = lp.id
        JOIN users u ON lp.user_id = u.id
        LEFT JOIN 2025_2026 nss ON nt.name = nss.name
        WHERE dp.draft_session_id = ? AND dp.round_number = ?
        ORDER BY dp.pick_number ASC
    ");
    $stmt->execute([$draft_info_ajax['id'], $round]);
    $picks = $stmt->fetchAll();

    $participants_count = $draft_info_ajax['participant_count'];
    foreach ($picks as &$p) {
        $p['position_in_round'] = (($p['pick_number'] - 1) % $participants_count) + 1;
        $p['logo_path'] = getTeamLogo($p['team_name']);
    }
    unset($p);

    if ($mode === 'rank') {
        usort($picks, function($a, $b) {
            if ($a['wins'] === $b['wins']) return $a['losses'] - $b['losses'];
            return $b['wins'] - $a['wins'];
        });
    } else {
        usort($picks, function($a, $b) {
            return $a['pick_number'] - $b['pick_number'];
        });
    }

    echo json_encode($picks);
    exit;
}

function getTeamLogo($teamName) {
    $teamName = trim($teamName);
    $nameVariations = [
        'LA Clippers' => 'Los Angeles Clippers',
        'L.A. Clippers' => 'Los Angeles Clippers',
        'LAC' => 'Los Angeles Clippers',
        'LA Lakers' => 'Los Angeles Lakers',
        'L.A. Lakers' => 'Los Angeles Lakers',
        'LAL' => 'Los Angeles Lakers',
        'Philadelphia Sixers' => 'Philadelphia 76ers',
        'Philly 76ers' => 'Philadelphia 76ers',
    ];
    if (isset($nameVariations[$teamName])) $teamName = $nameVariations[$teamName];

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
    if (isset($logoMap[$teamName])) return 'nba-wins-platform/public/assets/team_logos/' . $logoMap[$teamName];
    $filename = strtolower(str_replace(' ', '_', $teamName)) . '.png';
    return 'nba-wins-platform/public/assets/team_logos/' . $filename;
}

function getTeamLogoPath($team) {
    if (!empty($team['team_name'])) return getTeamLogo($team['team_name']);
    return 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMTgiIHN0cm9rZT0iIzMzMzMzMyIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjx0ZXh0IHg9IjIwIiB5PSIyNSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1zaXplPSIyMCIgZmlsbD0iIzMzMzMzMyI+Pz88L3RleHQ+Cjwvc3ZnPgo=';
}

// Get league info
$stmt = $pdo->prepare("SELECT * FROM leagues WHERE id = ?");
$stmt->execute([$league_id]);
$league = $stmt->fetch();
if (!$league) die("League not found");

// Check if draft is completed
$stmt = $pdo->prepare("
    SELECT ds.*, COUNT(dp.id) as total_picks,
           (SELECT COUNT(*) FROM league_participants WHERE league_id = ? AND status = 'active') as participant_count
    FROM draft_sessions ds
    LEFT JOIN draft_picks dp ON ds.id = dp.draft_session_id
    WHERE ds.league_id = ?
    GROUP BY ds.id
    ORDER BY ds.created_at DESC
    LIMIT 1
");
$stmt->execute([$league_id, $league_id]);
$draft_info = $stmt->fetch();

if (!$draft_info || $draft_info['status'] !== 'completed') {
    header('Location: draft.php');
    exit;
}

// Get all draft picks
$stmt = $pdo->prepare("
    SELECT 
        dp.id, dp.pick_number, dp.round_number, dp.picked_at, dp.picked_by_commissioner,
        nt.name as team_name, nt.abbreviation, nt.logo_filename as logo,
        lp.participant_name, u.display_name,
        COALESCE(nss.win, 0) as wins, COALESCE(nss.loss, 0) as losses
    FROM draft_picks dp
    JOIN nba_teams nt ON dp.team_id = nt.id
    JOIN league_participants lp ON dp.league_participant_id = lp.id
    JOIN users u ON lp.user_id = u.id
    LEFT JOIN 2025_2026 nss ON nt.name = nss.name
    WHERE dp.draft_session_id = ?
    ORDER BY dp.pick_number ASC
");
$stmt->execute([$draft_info['id']]);
$all_picks = $stmt->fetchAll();

// AUTOMATIC DRAFT BACKUP
try {
    $backup_dir = '/data/www/default/nba-wins-platform/draft-backup';
    if (!is_dir($backup_dir)) mkdir($backup_dir, 0755, true);
    
    $league_name_clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', $league['display_name']);
    $timestamp = date('Y-m-d_H-i-s', strtotime($draft_info['completed_at']));
    $filename = "draft_backup_{$league_name_clean}_L{$league_id}_{$timestamp}.txt";
    $filepath = $backup_dir . '/' . $filename;
    
    if (!file_exists($filepath)) {
        $content = "NBA WINS POOL - DRAFT BACKUP\n";
        $content .= "==============================\n\n";
        $content .= "League: " . $league['display_name'] . "\n";
        $content .= "League ID: " . $league_id . "\n";
        $content .= "Draft Completed: " . date('Y-m-d H:i:s', strtotime($draft_info['completed_at'])) . "\n";
        $content .= "Backup Created: " . date('Y-m-d H:i:s') . "\n";
        $content .= "Total Picks: " . count($all_picks) . "\n\n";
        $content .= "DRAFT RESULTS (Pick Order)\n==========================\n\n";
        
        foreach ($all_picks as $pick) {
            $commissioner_indicator = $pick['picked_by_commissioner'] ? ' (COMMISSIONER)' : '';
            $pick_time = date('H:i:s', strtotime($pick['picked_at']));
            $content .= sprintf("Pick #%02d: %-20s selected %-25s (%s) [%d-%d] at %s%s\n",
                $pick['pick_number'], $pick['display_name'], $pick['team_name'],
                $pick['abbreviation'], $pick['wins'], $pick['losses'], $pick_time, $commissioner_indicator);
        }
        
        $content .= "\n\nTEAMS BY PARTICIPANT\n====================\n\n";
        $participants_backup = [];
        foreach ($all_picks as $pick) {
            if (!isset($participants_backup[$pick['display_name']])) $participants_backup[$pick['display_name']] = [];
            $participants_backup[$pick['display_name']][] = $pick;
        }
        foreach ($participants_backup as $name => $participant_picks) {
            $total_wins = array_sum(array_column($participant_picks, 'wins'));
            $total_losses = array_sum(array_column($participant_picks, 'losses'));
            $content .= $name . " ({$total_wins}W-{$total_losses}L):\n";
            foreach ($participant_picks as $pick) {
                $content .= "  - " . $pick['team_name'] . " (" . $pick['abbreviation'] . ") [{$pick['wins']}-{$pick['losses']}]\n";
            }
            $content .= "\n";
        }
        file_put_contents($filepath, $content);
    }
} catch (Exception $e) {
    error_log("Failed to create draft backup: " . $e->getMessage());
}

// Calculate position_in_round
foreach ($all_picks as &$pick) {
    $participants_count = $draft_info['participant_count'];
    $pick['position_in_round'] = (($pick['pick_number'] - 1) % $participants_count) + 1;
}
unset($pick);

$selected_round = isset($_GET['round']) ? max(1, min((int)$_GET['round'], $draft_info['total_rounds'])) : 1;
$view_mode = isset($_GET['mode']) ? $_GET['mode'] : 'order';

$round_picks = array_filter($all_picks, function($pick) use ($selected_round) {
    return (int)$pick['round_number'] == (int)$selected_round;
});

if ($view_mode === 'rank') {
    usort($round_picks, function($a, $b) {
        if ($a['wins'] === $b['wins']) return $a['losses'] - $b['losses'];
        return $b['wins'] - $a['wins'];
    });
} else {
    usort($round_picks, function($a, $b) {
        return $a['pick_number'] - $b['pick_number'];
    });
}

// ==================== BUILD PARTICIPANT ROSTER DATA ====================
$participant_rosters = [];
foreach ($all_picks as $pick) {
    $name = $pick['display_name'];
    if (!isset($participant_rosters[$name])) {
        $participant_rosters[$name] = [
            'display_name' => $name,
            'teams' => [],
            'total_wins' => 0,
            'total_losses' => 0,
            'first_pick' => $pick['pick_number']
        ];
    }
    $participant_rosters[$name]['teams'][] = $pick;
    $participant_rosters[$name]['total_wins'] += $pick['wins'];
    $participant_rosters[$name]['total_losses'] += $pick['losses'];
}

// Sort by total wins descending
uasort($participant_rosters, function($a, $b) {
    return $b['total_wins'] - $a['total_wins'];
});
$participant_rosters = array_values($participant_rosters);

// Calculate highest pace participant
$highest_pace = 0;
$highest_pace_name = '';
foreach ($participant_rosters as $roster) {
    $total_games = $roster['total_wins'] + $roster['total_losses'];
    $team_count = count($roster['teams']);
    $pace = $total_games > 0 ? round(($roster['total_wins'] / $total_games) * 82 * $team_count, 0) : 0;
    if ($pace > $highest_pace) {
        $highest_pace = $pace;
        $highest_pace_name = $roster['display_name'];
    }
}

// Calculate draft stats
$total_commissioner_picks = count(array_filter($all_picks, fn($p) => $p['picked_by_commissioner']));
$best_record_pick = null;
$best_record_wins = -1;
foreach ($all_picks as $pick) {
    if ($pick['wins'] > $best_record_wins) {
        $best_record_wins = $pick['wins'];
        $best_record_pick = $pick;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="theme-color" content="<?= ($_SESSION['theme_preference'] ?? 'dark') === 'classic' ? '#f5f5f5' : '#121a23' ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Draft Summary - <?= htmlspecialchars($league['display_name']) ?></title>
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
        --accent-green: #3fb950;
        --accent-red: #f85149;
        --accent-orange: #d29922;
        --accent-gold: #f0c644;
        --accent-silver: #a0aec0;
        --accent-bronze: #cd7f32;
        --radius-sm: 6px;
        --radius-md: 10px;
        --radius-lg: 14px;
        --shadow-card: 0 1px 3px rgba(0, 0, 0, 0.4), 0 0 0 1px var(--border-color);
        --shadow-elevated: 0 4px 16px rgba(0, 0, 0, 0.5), 0 0 0 1px var(--border-color);
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

    * { margin: 0; padding: 0; box-sizing: border-box; }
    html { background-color: var(--bg-primary); }

    body {
        font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
        line-height: 1.5;
        color: var(--text-primary);
        background: var(--bg-primary);
        background-image: radial-gradient(ellipse at 50% 0%, rgba(56, 139, 253, 0.04) 0%, transparent 60%);
        min-height: 100vh;
        -webkit-font-smoothing: antialiased;
    }

    .app-container {
        max-width: 1000px;
        margin: 0 auto;
        padding: 16px 12px 2rem;
    }

    /* Draft container card */
    .draft-container {
        background: var(--bg-card);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-card);
        padding: 16px;
        overflow: hidden;
    }

    /* Header */
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
    }

    /* Draft info banner */
    .draft-info-banner {
        background: var(--bg-elevated);
        border-radius: var(--radius-md);
        padding: 10px 16px;
        margin-bottom: 10px;
        border: 1px solid var(--border-subtle);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .draft-info-league {
        font-size: 14px;
        font-weight: 700;
        color: var(--text-primary);
    }

    .draft-info-dot {
        width: 3px;
        height: 3px;
        border-radius: 50%;
        background: var(--text-muted);
        flex-shrink: 0;
    }

    .draft-info-meta {
        font-size: 12px;
        color: var(--text-muted);
        line-height: 1.4;
    }

    /* Controls bar */
    .controls-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
        gap: 12px;
    }

    /* Round scroller */
    .round-scroller-wrapper {
        position: relative;
        flex: 1;
        min-width: 0;
    }
    .round-scroller {
        display: flex;
        gap: 6px;
        overflow-x: auto;
        scroll-behavior: smooth;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        -ms-overflow-style: none;
        padding: 2px 0;
    }
    .round-scroller::-webkit-scrollbar { display: none; }
    .round-btn {
        flex-shrink: 0;
        padding: 7px 16px;
        font-size: 13px;
        font-family: 'Outfit', sans-serif;
        font-weight: 600;
        background: var(--bg-elevated);
        color: var(--text-muted);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        cursor: pointer;
        transition: all 0.15s ease;
        white-space: nowrap;
        -webkit-tap-highlight-color: transparent;
    }
    .round-btn:hover { color: var(--text-primary); border-color: rgba(56, 139, 253, 0.3); }
    .round-btn.active {
        background: var(--accent-blue);
        color: white;
        border-color: var(--accent-blue);
    }

    /* Toggle switch */
    .toggle-switch {
        display: flex;
        background: var(--bg-elevated);
        border-radius: 999px;
        padding: 3px;
        cursor: pointer;
        position: relative;
        user-select: none;
        border: 1px solid var(--border-color);
    }

    .toggle-option {
        padding: 7px 16px;
        z-index: 1;
        color: var(--text-muted);
        transition: color 0.3s ease;
        font-weight: 600;
        font-size: 13px;
        text-align: center;
        white-space: nowrap;
    }

    .toggle-option.active { color: white; }

    .toggle-slider {
        position: absolute;
        top: 3px;
        left: 3px;
        width: calc(50% - 3px);
        height: calc(100% - 6px);
        background: var(--accent-blue);
        border-radius: 999px;
        transition: transform 0.3s ease;
    }

    .toggle-slider.order { transform: translateX(100%); }

    /* Team cards */
    .picks-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .team-card {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: var(--bg-elevated);
        padding: 12px 16px;
        border-radius: var(--radius-md);
        border: 1px solid var(--border-subtle);
        transition: all var(--transition-fast);
    }

    .team-card:hover {
        background: var(--bg-card-hover);
    }

    .team-info {
        display: flex;
        align-items: center;
        gap: 12px;
        flex: 1;
        min-width: 0;
    }

    .pick-number {
        font-weight: 700;
        min-width: 28px;
        font-size: 16px;
        color: var(--accent-blue);
        font-variant-numeric: tabular-nums;
    }

    .team-logo {
        width: 42px;
        height: 42px;
        object-fit: contain;
        flex-shrink: 0;
    }

    .team-details {
        display: flex;
        flex-direction: column;
        gap: 1px;
        min-width: 0;
    }

    .team-name {
        font-weight: 600;
        font-size: 15px;
        color: var(--text-primary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .drafter-info {
        font-size: 12px;
        color: var(--text-muted);
    }

    .commissioner-pick {
        color: var(--accent-orange);
        font-style: italic;
    }

    .team-record {
        font-weight: 600;
        font-size: 15px;
        text-align: right;
        min-width: 70px;
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
    }

    .wins { color: var(--accent-green); }
    .losses { color: var(--accent-red); }
    .record-dash { color: var(--text-muted); margin: 0 2px; }

    /* Empty state */
    .empty-state {
        text-align: center;
        padding: 3rem 1.5rem;
        color: var(--text-muted);
        font-style: italic;
    }

    /* Responsive */
    @media (max-width: 600px) {
        .controls-bar {
            flex-direction: column;
            align-items: stretch;
        }

        .toggle-switch { align-self: center; }

        .team-card { padding: 10px 12px; }
        .team-logo { width: 36px; height: 36px; }
        .team-info { gap: 10px; }
        .pick-number { font-size: 14px; min-width: 24px; }
        .team-name { font-size: 14px; }
        .drafter-info { font-size: 11px; }
        .team-record { font-size: 14px; min-width: 60px; }
        .draft-container { padding: 12px; }
    }

    @media (min-width: 601px) {
        .app-container { padding: 16px 20px 2rem; }
        .draft-container { padding: 20px; }
    }
    /* ===== FLOATING PILL NAV ===== */
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

    /* ===== DRAFT STATS STRIP ===== */
    .draft-stats-strip {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
        margin-bottom: 12px;
    }
    .stat-card {
        background: var(--bg-elevated);
        border-radius: var(--radius-md);
        padding: 10px 12px;
        border: 1px solid var(--border-subtle);
        text-align: center;
    }
    .stat-card-value {
        font-size: 1.15rem;
        font-weight: 800;
        color: var(--text-primary);
        line-height: 1.2;
        font-variant-numeric: tabular-nums;
    }
    .stat-card-label {
        font-size: 10px;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-top: 2px;
    }
    .stat-card-sub {
        font-size: 11px;
        color: var(--text-secondary);
        margin-top: 1px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* ===== SECTION DIVIDER ===== */
    .section-divider {
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 24px 0 14px;
    }
    .section-divider-line {
        flex: 1;
        height: 1px;
        background: var(--border-color);
    }
    .section-divider-title {
        font-size: 14px;
        font-weight: 700;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.06em;
        white-space: nowrap;
    }

    /* ===== ROSTER OVERVIEW CARDS ===== */
    .roster-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 12px;
        margin-bottom: 14px;
    }
    .roster-card {
        background: var(--bg-card);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-card);
        overflow: hidden;
        transition: box-shadow var(--transition-fast);
    }
    .roster-card:hover {
        box-shadow: var(--shadow-elevated);
    }
    .roster-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 16px;
        border-bottom: 1px solid var(--border-subtle);
    }
    .roster-card-rank {
        font-size: 1.3rem;
        font-weight: 800;
        min-width: 32px;
    }
    .roster-card-rank.rank-1 { color: var(--accent-gold); }
    .roster-card-rank.rank-2 { color: var(--accent-silver); }
    .roster-card-rank.rank-3 { color: var(--accent-bronze); }
    .roster-card-rank.rank-other { color: var(--text-muted); }
    .roster-card-name {
        flex: 1;
        font-size: 16px;
        font-weight: 700;
        color: var(--text-primary);
        margin-left: 10px;
    }
    .roster-card-wins {
        font-size: 1.1rem;
        font-weight: 800;
        color: var(--accent-green);
        font-variant-numeric: tabular-nums;
    }
    .roster-card-wins small {
        font-size: 12px;
        font-weight: 600;
        color: var(--text-muted);
    }
    .roster-card-body {
        padding: 0;
    }
    .roster-team-row {
        display: flex;
        align-items: center;
        padding: 9px 16px;
        gap: 10px;
        border-bottom: 1px solid var(--border-subtle);
        transition: background var(--transition-fast);
    }
    .roster-team-row:last-child { border-bottom: none; }
    .roster-team-row:hover { background: var(--bg-card-hover); }
    .roster-team-row a {
        display: flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        color: var(--text-primary);
        flex: 1;
        min-width: 0;
    }
    .roster-team-row a:hover { color: var(--accent-blue); }
    .roster-team-logo {
        width: 28px;
        height: 28px;
        object-fit: contain;
        flex-shrink: 0;
    }
    .roster-team-name {
        font-size: 14px;
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .roster-team-meta {
        font-size: 11px;
        color: var(--text-muted);
        white-space: nowrap;
    }
    .roster-team-record {
        font-size: 14px;
        font-weight: 600;
        font-variant-numeric: tabular-nums;
        text-align: right;
        min-width: 52px;
        flex-shrink: 0;
    }
    .roster-card-footer {
        padding: 10px 16px;
        background: var(--bg-elevated);
        display: flex;
        justify-content: space-between;
        font-size: 12px;
        color: var(--text-muted);
    }
    .roster-card-footer strong { color: var(--text-secondary); }

    @media (max-width: 600px) {
        .draft-stats-strip { grid-template-columns: repeat(3, 1fr); gap: 6px; }
        .stat-card { padding: 10px 8px; }
        .stat-card-value { font-size: 1.2rem; }
        .stat-card-label { font-size: 10px; }
        .stat-card-sub { font-size: 11px; }
        .roster-grid { grid-template-columns: 1fr; }
        .roster-card-name { font-size: 14px; }
        .roster-team-row { padding: 8px 12px; }
        .roster-team-logo { width: 24px; height: 24px; }
    }

    /* Cascade animation */
    @keyframes cascadeIn {
        from { opacity: 0; transform: translateY(12px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .cascade-card {
        opacity: 0;
        animation: cascadeIn 0.35s ease-out forwards;
    }
</style>
</head>
<body>

    <?php include '/data/www/default/nba-wins-platform/components/navigation_menu.php'; ?>

    <div class="app-container">

        <div class="draft-container">

        <div class="draft-info-banner">
            <span class="draft-info-league"><?= htmlspecialchars($league['display_name']) ?></span>
            <span class="draft-info-dot"></span>
            <span class="draft-info-meta"><?= date('M j, Y', strtotime($draft_info['completed_at'])) ?></span>
            <span class="draft-info-dot"></span>
            <span class="draft-info-meta"><?= $draft_info['total_picks'] ?> picks · <?= $draft_info['total_rounds'] ?> rounds</span>
        </div>

        <!-- Draft Stats Strip -->
        <div class="draft-stats-strip">
            <div class="stat-card">
                <div class="stat-card-value"><?= count($participant_rosters) ?></div>
                <div class="stat-card-label">Participants</div>
                <div class="stat-card-sub"><?= count($participant_rosters[0]['teams'] ?? []) ?> teams each</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-value" style="color: var(--accent-green)"><?= $highest_pace ?></div>
                <div class="stat-card-label">Highest Pace</div>
                <div class="stat-card-sub"><?= htmlspecialchars($highest_pace_name) ?></div>
            </div>
            <div class="stat-card">
                <?php if ($best_record_pick): ?>
                <div class="stat-card-value"><?= $best_record_pick['wins'] ?>-<?= $best_record_pick['losses'] ?></div>
                <div class="stat-card-label">Best Team</div>
                <div class="stat-card-sub"><?= htmlspecialchars($best_record_pick['team_name']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="controls-bar" style="flex-direction: column; align-items: stretch;">
            <div class="round-scroller-wrapper">
                <div class="round-scroller">
                    <?php for ($i = 1; $i <= $draft_info['total_rounds']; $i++): ?>
                        <button class="round-btn<?php echo $i === $selected_round ? ' active' : ''; ?>" onclick="changeRound(<?php echo $i; ?>)">
                            Round <?php echo $i; ?>
                        </button>
                    <?php endfor; ?>
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end;">
                <div class="toggle-switch" onclick="toggleView()">
                    <div class="toggle-slider <?php echo $view_mode === 'order' ? 'order' : ''; ?>"></div>
                    <div class="toggle-option <?php echo $view_mode === 'rank' ? 'active' : ''; ?>">By Record</div>
                    <div class="toggle-option <?php echo $view_mode === 'order' ? 'active' : ''; ?>">By Pick</div>
                </div>
            </div>
        </div>

        <?php if (empty($round_picks)): ?>
            <div class="empty-state">No picks found for Round <?= $selected_round ?></div>
        <?php else: ?>
            <div class="picks-list">
                <?php foreach ($round_picks as $index => $pick): ?>
                <div class="team-card">
                    <div class="team-info">
                        <span class="pick-number">
                            <?php echo $view_mode === 'rank' ? ($index + 1) . '.' : $pick['position_in_round'] . '.'; ?>
                        </span>
                        <img src="<?php echo getTeamLogoPath($pick); ?>" 
                             alt="" class="team-logo"
                             onerror="this.style.opacity='0.3'">
                        <div class="team-details">
                            <div class="team-name"><?php echo htmlspecialchars($pick['team_name']); ?></div>
                            <div class="drafter-info">
                                Pick #<?php echo $pick['pick_number']; ?> by <?php echo htmlspecialchars($pick['display_name']); ?>
                                <?php if ($pick['picked_by_commissioner']): ?>
                                    <span class="commissioner-pick">(Commissioner)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="team-record">
                        <span class="wins"><?php echo $pick['wins']; ?>W</span><span class="record-dash">-</span><span class="losses"><?php echo $pick['losses']; ?>L</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        </div><!-- /.draft-container -->

    </div>

    <script>
        let currentRound = <?php echo $selected_round; ?>;
        let currentMode = '<?php echo $view_mode; ?>';

        function toggleView() {
            currentMode = currentMode === 'rank' ? 'order' : 'rank';
            // Update toggle UI instantly
            const slider = document.querySelector('.toggle-slider');
            const options = document.querySelectorAll('.toggle-option');
            if (currentMode === 'order') {
                slider.classList.add('order');
                options[0].classList.remove('active');
                options[1].classList.add('active');
            } else {
                slider.classList.remove('order');
                options[0].classList.add('active');
                options[1].classList.remove('active');
            }
            // Update URL without reload
            const params = new URLSearchParams(window.location.search);
            params.set('mode', currentMode);
            params.set('round', currentRound);
            history.replaceState(null, '', `${window.location.pathname}?${params.toString()}`);
            // Fetch and re-render picks (no cascade on mode toggle)
            fetchRoundData(currentRound, currentMode, false);
        }

        function changeRound(round) {
            if (round === currentRound) return;
            currentRound = round;
            // Update active round button
            document.querySelectorAll('.round-btn').forEach(function(btn, i) {
                btn.classList.toggle('active', (i + 1) === round);
            });
            // Update URL without reload
            const params = new URLSearchParams(window.location.search);
            params.set('round', round);
            params.set('mode', currentMode);
            history.replaceState(null, '', `${window.location.pathname}?${params.toString()}`);
            // Fetch and re-render picks with cascade
            fetchRoundData(round, currentMode, true);
        }

        function fetchRoundData(round, mode, cascade) {
            fetch(`${window.location.pathname}?ajax=round_data&round=${round}&mode=${mode}`)
                .then(r => r.json())
                .then(picks => renderPicks(picks, mode, cascade))
                .catch(err => console.error('Failed to fetch round data:', err));
        }

        function renderPicks(picks, mode, cascade) {
            const container = document.querySelector('.picks-list') || document.querySelector('.empty-state')?.parentElement;
            if (!container) return;

            // Find or create picks-list
            let picksList = document.querySelector('.picks-list');
            const emptyState = document.querySelector('.empty-state');

            if (picks.length === 0) {
                if (picksList) picksList.remove();
                if (!emptyState) {
                    const empty = document.createElement('div');
                    empty.className = 'empty-state';
                    empty.textContent = 'No picks found for Round ' + currentRound;
                    // Insert after controls-bar
                    const controlsBar = document.querySelector('.controls-bar');
                    controlsBar.parentNode.insertBefore(empty, controlsBar.nextSibling.nextSibling || null);
                } else {
                    emptyState.textContent = 'No picks found for Round ' + currentRound;
                }
                return;
            }

            if (emptyState) emptyState.remove();

            if (!picksList) {
                picksList = document.createElement('div');
                picksList.className = 'picks-list';
                const controlsBar = document.querySelector('.controls-bar');
                controlsBar.parentNode.insertBefore(picksList, controlsBar.nextSibling.nextSibling || null);
            }

            picksList.innerHTML = '';

            picks.forEach(function(pick, index) {
                const card = document.createElement('div');
                card.className = 'team-card';
                if (cascade) {
                    card.classList.add('cascade-card');
                    card.style.animationDelay = (index * 50) + 'ms';
                }

                const pickNum = mode === 'rank' ? (index + 1) + '.' : pick.position_in_round + '.';
                const commBadge = pick.picked_by_commissioner == 1
                    ? '<span class="commissioner-pick">(Commissioner)</span>' : '';

                card.innerHTML = `
                    <div class="team-info">
                        <span class="pick-number">${pickNum}</span>
                        <img src="${escapeHtml(pick.logo_path)}" alt="" class="team-logo" onerror="this.style.opacity='0.3'">
                        <div class="team-details">
                            <div class="team-name">${escapeHtml(pick.team_name)}</div>
                            <div class="drafter-info">
                                Pick #${pick.pick_number} by ${escapeHtml(pick.display_name)}
                                ${commBadge}
                            </div>
                        </div>
                    </div>
                    <div class="team-record">
                        <span class="wins">${pick.wins}W</span><span class="record-dash">-</span><span class="losses">${pick.losses}L</span>
                    </div>
                `;
                picksList.appendChild(card);
            });
        }

        function escapeHtml(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    </script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initial page load: cascade stat cards, round buttons, and team cards
        document.querySelectorAll('.stat-card').forEach(function(card, i) {
            card.classList.add('cascade-card');
            card.style.animationDelay = (i * 60) + 'ms';
        });
        document.querySelectorAll('.round-btn').forEach(function(btn, i) {
            btn.classList.add('cascade-card');
            btn.style.animationDelay = (i * 40) + 'ms';
        });
        document.querySelectorAll('.picks-list .team-card').forEach(function(card, i) {
            card.classList.add('cascade-card');
            card.style.animationDelay = (i * 50) + 'ms';
        });
    });
    </script>
    <!-- Floating Pill Navigation -->
    <nav class="floating-pill" id="floatingPill">
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
            <?php if (empty($isGuest)): ?>
            <a href="/nba-wins-platform/auth/logout.php" class="pill-expanded-item logout-item">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
            <?php endif; ?>
        </div>
        <div class="pill-main-row">
            <a href="/index.php" class="pill-item" data-label="Home">
                <i class="fas fa-home"></i>
            </a>
            <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?php echo $currentLeagueId ?? ($_SESSION['current_league_id'] ?? 0); ?>&user_id=<?php echo $profileUserId ?? ($_SESSION['user_id'] ?? 0); ?>" class="pill-item" data-label="Profile">
                <i class="fas fa-user"></i>
            </a>
            <a href="/analytics.php" class="pill-item" data-label="Analytics">
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