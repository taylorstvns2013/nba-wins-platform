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
        --bg-primary: #121a23;
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
        padding: 0 12px 2rem;
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

    /* Page title */
    .page-title {
        font-size: 1.35rem;
        font-weight: 700;
        letter-spacing: -0.02em;
        text-align: center;
        padding: 16px 0 12px;
    }

    /* Draft info banner */
    .draft-info-banner {
        background: var(--bg-card);
        border-radius: var(--radius-lg);
        padding: 16px 20px;
        margin-bottom: 14px;
        box-shadow: var(--shadow-card);
        text-align: center;
    }

    .draft-info-league {
        font-size: 16px;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 4px;
    }

    .draft-info-meta {
        font-size: 13px;
        color: var(--text-muted);
        line-height: 1.6;
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
        background: var(--bg-card);
        padding: 12px 16px;
        border-radius: var(--radius-md);
        box-shadow: var(--shadow-card);
        transition: all var(--transition-fast);
    }

    .team-card:hover {
        background: var(--bg-card-hover);
        box-shadow: var(--shadow-elevated);
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
    }

    @media (min-width: 601px) {
        .app-container { padding: 0 20px 2rem; }
    }
    /* ===== FLOATING PILL NAV ===== */
    .floating-pill { position: fixed; bottom: 12px; left: 50%; z-index: 9999; display: flex; align-items: center; gap: 2px; background: rgba(32, 42, 56, 0.95); border: 1px solid var(--border-color); border-radius: 999px; padding: 5px; box-shadow: 0 4px 24px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.04); -webkit-backdrop-filter: blur(16px); backdrop-filter: blur(16px); -webkit-transform: translateX(-50%) translateZ(0); transform: translateX(-50%) translateZ(0); will-change: transform; }
    body { padding-bottom: 76px; }
    @media (max-width: 600px) { .floating-pill { bottom: calc(8px + env(safe-area-inset-bottom, 0px)); } }
    .pill-item { display: flex; align-items: center; justify-content: center; width: 42px; height: 42px; border-radius: 999px; text-decoration: none; color: var(--text-muted); font-size: 16px; transition: all 0.15s ease; cursor: pointer; border: none; background: none; -webkit-tap-highlight-color: transparent; position: relative; }
    .pill-item:hover { color: var(--text-primary); background: var(--bg-elevated); }
    .pill-item.active { color: white; background: var(--accent-blue); }
    .pill-item:active { transform: scale(0.92); }
    .pill-divider { width: 1px; height: 24px; background: var(--border-color); flex-shrink: 0; }
    @media (min-width: 601px) { .pill-item::after { content: attr(data-label); position: absolute; bottom: calc(100% + 8px); left: 50%; transform: translateX(-50%) scale(0.9); background: var(--bg-elevated); color: var(--text-primary); font-size: 11px; font-weight: 600; font-family: 'Outfit', sans-serif; padding: 4px 10px; border-radius: 6px; white-space: nowrap; opacity: 0; pointer-events: none; transition: all 0.15s ease; border: 1px solid var(--border-color); } .pill-item:hover::after { opacity: 1; transform: translateX(-50%) scale(1); } }
</style>
</head>
<body>

    <?php include '/data/www/default/nba-wins-platform/components/navigation_menu_new.php'; ?>

    <div class="app-container">

        <div class="page-title">Draft Summary</div>

        <div class="draft-info-banner">
            <div class="draft-info-league"><?= htmlspecialchars($league['display_name']) ?></div>
            <div class="draft-info-meta">
                Completed <?= date('F j, Y \a\t g:i A', strtotime($draft_info['completed_at'])) ?><br>
                <?= $draft_info['total_picks'] ?> picks across <?= $draft_info['total_rounds'] ?> rounds
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
    </div>

    <script>
        function toggleView() {
            const currentMode = new URLSearchParams(window.location.search).get('mode') || 'rank';
            const newMode = currentMode === 'rank' ? 'order' : 'rank';
            updateURL(newMode);
        }

        function changeRound(round) {
            const currentMode = new URLSearchParams(window.location.search).get('mode') || 'order';
            updateURL(currentMode, round);
        }

        function updateURL(mode, round = null) {
            const params = new URLSearchParams(window.location.search);
            params.set('mode', mode);
            if (round) params.set('round', round);
            window.location.href = `${window.location.pathname}?${params.toString()}`;
        }
    </script>
    <nav class="floating-pill">
        <a href="/index_new.php" class="pill-item" data-label="Home"><i class="fas fa-home"></i></a>
        <a href="/nba-wins-platform/profiles/participant_profile_new.php?league_id=<?php echo $currentLeagueId ?? ($_SESSION['current_league_id'] ?? 0); ?>&user_id=<?php echo $profileUserId ?? ($_SESSION['user_id'] ?? 0); ?>" class="pill-item" data-label="Profile"><i class="fas fa-user"></i></a>
        <a href="/analytics_new.php" class="pill-item" data-label="Analytics"><i class="fas fa-chart-line"></i></a>
        <a href="/claudes-column_new.php" class="pill-item" data-label="Column" style="position:relative"><i class="fa-solid fa-newspaper"></i><?php if ($hasNewArticles): ?><span style="position:absolute;top:2px;right:2px;width:7px;height:7px;background:#f85149;border-radius:50%;box-shadow:0 0 4px rgba(248,81,73,0.5)"></span><?php endif; ?></a>
        <div class="pill-divider"></div>
        <button class="pill-item" data-label="Menu" onclick="toggleDarkNav()"><i class="fas fa-bars"></i></button>
    </nav>
</body>
</html>