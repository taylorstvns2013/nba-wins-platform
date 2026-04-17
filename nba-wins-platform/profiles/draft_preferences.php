<?php
// /data/www/default/nba-wins-platform/profiles/draft_preferences.php
// Draft Preferences - League-specific team ranking for auto-draft
session_start();
require_once '/data/www/default/nba-wins-platform/config/db_connection.php';

$user_id = $_SESSION['user_id'] ?? null;
$current_league_id = $_SESSION['current_league_id'] ?? null;

if (!$user_id) {
    header('Location: /nba-wins-platform/auth/login.php');
    exit;
}

// Sync theme preference
$stmtTheme = $pdo->prepare("SELECT display_name, theme_preference FROM users WHERE id = ?");
$stmtTheme->execute([$user_id]);
$userRow = $stmtTheme->fetch();
$current_theme = $userRow['theme_preference'] ?? $_SESSION['theme_preference'] ?? 'dark';
$_SESSION['theme_preference'] = $current_theme;
$display_name = $userRow['display_name'] ?? 'User';

// Get user's leagues for the league selector
$stmt = $pdo->prepare("
    SELECT l.id, l.display_name, l.draft_completed,
           (l.commissioner_user_id = ?) as is_commissioner
    FROM leagues l
    JOIN league_participants lp ON l.id = lp.league_id
    WHERE lp.user_id = ? AND lp.status = 'active' AND l.status = 'active'
    ORDER BY l.league_number ASC
");
$stmt->execute([$user_id, $user_id]);
$user_leagues = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Determine which league/scope to show initially
$selected_league_id = $_GET['league_id'] ?? 'global';
if ($selected_league_id !== 'global') $selected_league_id = (int)$selected_league_id;

// Get all NBA teams with projections
$stmt = $pdo->prepare("
    SELECT nt.id, nt.name, nt.abbreviation, nt.conference,
           COALESCE(ou.over_under_number, 41) as projected_wins
    FROM nba_teams nt
    LEFT JOIN over_under ou ON nt.name = ou.team_name
    ORDER BY projected_wins DESC, nt.name ASC
");
$stmt->execute();
$all_teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get existing preferences for initial scope
$prefs_query_league_id = ($selected_league_id === 'global') ? null : $selected_league_id;

// Try league-specific first
$existing_prefs = [];
if ($prefs_query_league_id !== null) {
    $stmt = $pdo->prepare("SELECT team_id, priority_rank FROM user_draft_preferences WHERE user_id = ? AND league_id = ? ORDER BY priority_rank ASC");
    $stmt->execute([$user_id, $prefs_query_league_id]);
    $existing_prefs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fall back to global
$prefs_scope = 'global';
if (empty($existing_prefs)) {
    $stmt = $pdo->prepare("SELECT team_id, priority_rank FROM user_draft_preferences WHERE user_id = ? AND league_id IS NULL ORDER BY priority_rank ASC");
    $stmt->execute([$user_id]);
    $existing_prefs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $prefs_scope = 'league';
}

// Build ordered team list
$prefs_map = [];
foreach ($existing_prefs as $p) $prefs_map[$p['team_id']] = $p['priority_rank'];

$ranked = [];
$unranked = [];
foreach ($all_teams as $t) {
    if (isset($prefs_map[$t['id']])) {
        $ranked[$prefs_map[$t['id']]] = $t;
    } else {
        $unranked[] = $t;
    }
}
ksort($ranked);
$display_teams = empty($ranked) ? $all_teams : array_merge(array_values($ranked), $unranked);

// Logo helper
function getTeamLogo($name) {
    $map = [
        'Atlanta Hawks'=>'atlanta_hawks.png','Boston Celtics'=>'boston_celtics.png','Brooklyn Nets'=>'brooklyn_nets.png',
        'Charlotte Hornets'=>'charlotte_hornets.png','Chicago Bulls'=>'chicago_bulls.png','Cleveland Cavaliers'=>'cleveland_cavaliers.png',
        'Dallas Mavericks'=>'dallas_mavericks.png','Denver Nuggets'=>'denver_nuggets.png','Detroit Pistons'=>'detroit_pistons.png',
        'Golden State Warriors'=>'golden_state_warriors.png','Houston Rockets'=>'houston_rockets.png','Indiana Pacers'=>'indiana_pacers.png',
        'LA Clippers'=>'la_clippers.png','Los Angeles Clippers'=>'la_clippers.png','Los Angeles Lakers'=>'los_angeles_lakers.png',
        'Memphis Grizzlies'=>'memphis_grizzlies.png','Miami Heat'=>'miami_heat.png','Milwaukee Bucks'=>'milwaukee_bucks.png',
        'Minnesota Timberwolves'=>'minnesota_timberwolves.png','New Orleans Pelicans'=>'new_orleans_pelicans.png',
        'New York Knicks'=>'new_york_knicks.png','Oklahoma City Thunder'=>'oklahoma_city_thunder.png','Orlando Magic'=>'orlando_magic.png',
        'Philadelphia 76ers'=>'philadelphia_76ers.png','Phoenix Suns'=>'phoenix_suns.png','Portland Trail Blazers'=>'portland_trail_blazers.png',
        'Sacramento Kings'=>'sacramento_kings.png','San Antonio Spurs'=>'san_antonio_spurs.png','Toronto Raptors'=>'toronto_raptors.png',
        'Utah Jazz'=>'utah_jazz.png','Washington Wizards'=>'washington_wizards.png',
    ];
    return '/nba-wins-platform/public/assets/team_logos/' . ($map[$name] ?? strtolower(str_replace(' ', '_', $name)) . '.png');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="theme-color" content="<?= $current_theme === 'classic' ? '#f5f5f5' : '#0f1419' ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Draft Preferences - NBA Wins Pool</title>
    <link rel="icon" type="image/png" href="/nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #0f1419;
            --bg-card: #1a2230;
            --bg-card-hover: #1f2a3a;
            --bg-elevated: #243044;
            --border-color: rgba(255,255,255,0.07);
            --text-primary: #e6edf3;
            --text-secondary: #8b949e;
            --text-muted: #484f58;
            --accent-blue: #388bfd;
            --accent-blue-dim: rgba(56,139,253,0.12);
            --accent-green: #3fb950;
            --accent-green-dim: rgba(63,185,80,0.12);
            --accent-red: #f85149;
            --accent-orange: #d29922;
            --accent-purple: #a371f7;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --shadow-card: 0 1px 3px rgba(0,0,0,0.5), 0 0 0 1px var(--border-color);
            --transition-fast: 0.15s ease;
            --conf-east: #dc3545;
            --conf-west: #388bfd;
        }
        <?php if ($current_theme === 'classic'): ?>
        :root {
            --bg-primary: #f3f4f6;
            --bg-card: #ffffff;
            --bg-card-hover: #f8f9fb;
            --bg-elevated: #f0f1f4;
            --border-color: rgba(0,0,0,0.08);
            --text-primary: #1a1d23;
            --text-secondary: #5a6370;
            --text-muted: #9ca3af;
            --accent-blue: #2563eb;
            --accent-blue-dim: rgba(37,99,235,0.08);
            --accent-green: #16a34a;
            --accent-green-dim: rgba(22,163,74,0.08);
            --accent-red: #dc2626;
            --accent-orange: #ca8a04;
            --accent-purple: #7c3aed;
            --shadow-card: 0 1px 3px rgba(0,0,0,0.06), 0 0 0 1px rgba(0,0,0,0.04);
            --conf-east: #dc3545;
            --conf-west: #2563eb;
        }
        <?php endif; ?>

        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
        html { background: var(--bg-primary); }
        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            line-height: 1.5;
            padding: 20px 16px 100px;
        }

        .container { max-width: 720px; margin: 0 auto; }

        /* Header */
        .pref-header {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 24px;
            margin-bottom: 16px;
            box-shadow: var(--shadow-card);
            text-align: center;
        }
        .pref-header h1 {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin-bottom: 4px;
        }
        .pref-header h1 i { color: var(--accent-blue); margin-right: 8px; }
        .pref-header p { font-size: 13px; color: var(--text-secondary); }

        /* League Selector */
        .scope-bar {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 14px 18px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            box-shadow: var(--shadow-card);
        }
        .scope-bar label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            white-space: nowrap;
        }
        .scope-select {
            flex: 1;
            min-width: 180px;
            padding: 8px 32px 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-family: 'Outfit', sans-serif;
            font-size: 14px;
            font-weight: 500;
            background-color: var(--bg-elevated);
            color: var(--text-primary);
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%238b949e' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            outline: none;
        }
        .scope-select:focus { border-color: var(--accent-blue); }
        .scope-select option { background: var(--bg-card); color: var(--text-primary); }

        .scope-badge {
            font-size: 11px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .scope-badge.global { background: var(--accent-blue-dim); color: var(--accent-blue); border: 1px solid rgba(56,139,253,0.2); }
        .scope-badge.league { background: var(--accent-green-dim); color: var(--accent-green); border: 1px solid rgba(63,185,80,0.2); }
        .scope-badge.fallback { background: rgba(210,153,34,0.12); color: var(--accent-orange); border: 1px solid rgba(210,153,34,0.2); }

        /* Controls */
        .controls-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .ctrl-btn {
            padding: 8px 14px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            background: var(--bg-card);
            color: var(--text-secondary);
            font-family: 'Outfit', sans-serif;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all var(--transition-fast);
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .ctrl-btn:hover { color: var(--text-primary); border-color: var(--accent-blue); background: var(--accent-blue-dim); }
        .ctrl-btn.save-btn { background: var(--accent-green); color: #fff; border-color: var(--accent-green); margin-left: auto; }
        .ctrl-btn.save-btn:hover { box-shadow: 0 2px 12px rgba(63,185,80,0.3); }
        .ctrl-btn.apply-all { background: var(--accent-blue-dim); color: var(--accent-blue); border-color: rgba(56,139,253,0.2); }
        .ctrl-btn.apply-all:hover { background: var(--accent-blue); color: #fff; }
        .ctrl-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

        /* Team List */
        .team-list {
            list-style: none;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-card);
        }
        .team-item {
            display: flex;
            align-items: center;
            padding: 10px 16px;
            border-bottom: 1px solid var(--border-color);
            transition: background var(--transition-fast);
            gap: 12px;
        }
        .team-item:last-child { border-bottom: none; }
        .team-item:hover { background: var(--bg-card-hover); }
        .team-item.dragging { opacity: 0.5; background: var(--accent-blue-dim); }

        .team-item.conf-Eastern { border-left: 3px solid var(--conf-east); }
        .team-item.conf-Western { border-left: 3px solid var(--conf-west); }

        .rank-num {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--bg-elevated);
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
            border: 1px solid var(--border-color);
        }
        .team-item:nth-child(-n+3) .rank-num { background: var(--accent-blue-dim); color: var(--accent-blue); border-color: rgba(56,139,253,0.2); }

        .team-logo { width: 32px; height: 32px; object-fit: contain; flex-shrink: 0; }
        .team-info { flex: 1; min-width: 0; }
        .team-name { font-weight: 600; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .team-meta { font-size: 11px; color: var(--text-muted); }

        .proj-badge {
            font-size: 11px;
            color: var(--text-secondary);
            padding: 3px 8px;
            background: var(--bg-elevated);
            border-radius: 10px;
            flex-shrink: 0;
            white-space: nowrap;
            border: 1px solid var(--border-color);
        }

        .move-btns {
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex-shrink: 0;
        }
        .mv-btn {
            width: 28px;
            height: 28px;
            border: 1px solid var(--border-color);
            background: var(--bg-elevated);
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            font-size: 11px;
            transition: all var(--transition-fast);
        }
        .mv-btn:hover:not(:disabled) { background: var(--accent-blue); color: #fff; border-color: var(--accent-blue); }
        .mv-btn:disabled { opacity: 0.2; cursor: not-allowed; }

        /* Notification */
        .notif {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: var(--radius-md);
            color: #fff;
            font-family: 'Outfit', sans-serif;
            font-size: 14px;
            font-weight: 500;
            z-index: 10000;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            box-shadow: 0 4px 16px rgba(0,0,0,0.3);
        }
        .notif.show { transform: translateX(0); }
        .notif.success { background: var(--accent-green); }
        .notif.error { background: var(--accent-red); }

        /* Unsaved indicator */
        .unsaved-dot {
            display: none;
            width: 8px;
            height: 8px;
            background: var(--accent-orange);
            border-radius: 50%;
            margin-left: 6px;
        }
        .unsaved-dot.visible { display: inline-block; }

        /* Responsive */
        @media (max-width: 600px) {
            body { padding: 12px 10px 100px; }
            .pref-header { padding: 18px 16px; }
            .pref-header h1 { font-size: 18px; }
            .scope-bar { padding: 12px 14px; }
            .team-item { padding: 8px 12px; gap: 8px; }
            .team-logo { width: 28px; height: 28px; }
            .rank-num { width: 28px; height: 28px; font-size: 12px; }
            .team-name { font-size: 13px; }
            .proj-badge { display: none; }
            .controls-bar { gap: 6px; }
            .ctrl-btn { padding: 7px 10px; font-size: 12px; }
        }

        @media (min-width: 601px) {
            body { padding: 32px 20px 100px; }
            .pref-header { padding: 28px 32px; }
            .team-item { padding: 12px 20px; }
        }

        <?php if ($current_theme === 'classic'): ?>
        .scope-select { background-color: #fff; border-color: #ddd; }
        .ctrl-btn { background: #fff; }
        .team-item:hover { background: rgba(0,0,0,0.02); }
        .mv-btn { background: #fff; }
        .mv-btn:hover:not(:disabled) { background: var(--accent-blue); color: #fff; }
        <?php endif; ?>
    </style>
</head>
<body>
    <div class="container">
        <div class="pref-header">
            <h1><i class="fas fa-list-ol"></i> Draft Preferences</h1>
            <p>Rank teams 1-30 for auto-draft priority &middot; <?= htmlspecialchars($display_name) ?></p>
        </div>

        <!-- League Scope Selector -->
        <div class="scope-bar">
            <label><i class="fas fa-shield-alt"></i> Scope</label>
            <select class="scope-select" id="scopeSelect" onchange="switchScope(this.value)">
                <option value="global" <?= $selected_league_id === 'global' ? 'selected' : '' ?>>Global Default</option>
                <?php foreach ($user_leagues as $ul): ?>
                <option value="<?= $ul['id'] ?>" <?= $selected_league_id === (int)$ul['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ul['display_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <span class="scope-badge <?= $prefs_scope === 'league' ? 'league' : ($prefs_scope === 'global' && $selected_league_id !== 'global' ? 'fallback' : 'global') ?>" id="scopeBadge">
                <?php
                if ($prefs_scope === 'league') echo 'League-specific';
                elseif ($selected_league_id !== 'global' && !empty($existing_prefs)) echo 'Using global';
                else echo 'Global';
                ?>
            </span>
        </div>

        <!-- Controls -->
        <div class="controls-bar">
            <button class="ctrl-btn" onclick="resetToProjections()" title="Reset to Vegas projections">
                <i class="fas fa-undo"></i> Reset
            </button>
            <button class="ctrl-btn" onclick="reverseOrder()" title="Reverse order">
                <i class="fas fa-exchange-alt"></i> Reverse
            </button>
            <button class="ctrl-btn apply-all" onclick="applyGlobally()" title="Apply current ranking to all leagues">
                <i class="fas fa-globe"></i> Apply to All
            </button>
            <button class="ctrl-btn save-btn" id="saveBtn" onclick="savePreferences()">
                <i class="fas fa-save"></i> Save
                <span class="unsaved-dot" id="unsavedDot"></span>
            </button>
        </div>

        <!-- Team List -->
        <ul class="team-list" id="teamList">
            <?php foreach ($display_teams as $i => $team): ?>
            <li class="team-item conf-<?= htmlspecialchars($team['conference']) ?>" data-team-id="<?= $team['id'] ?>">
                <div class="rank-num"><?= $i + 1 ?></div>
                <img src="<?= htmlspecialchars(getTeamLogo($team['name'])) ?>" alt="" class="team-logo"
                     onerror="this.src='/nba-wins-platform/public/assets/team_logos/default.png'">
                <div class="team-info">
                    <div class="team-name"><?= htmlspecialchars($team['name']) ?></div>
                    <div class="team-meta"><?= htmlspecialchars($team['abbreviation']) ?> &middot; <?= $team['conference'] ?></div>
                </div>
                <div class="proj-badge">O/U <?= number_format($team['projected_wins'], 1) ?></div>
                <div class="move-btns">
                    <button class="mv-btn mv-up"><i class="fas fa-chevron-up"></i></button>
                    <button class="mv-btn mv-down"><i class="fas fa-chevron-down"></i></button>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <script>
    var hasUnsaved = false;
    var currentScope = '<?= $selected_league_id === 'global' ? 'global' : $selected_league_id ?>';

    document.addEventListener('DOMContentLoaded', function() {
        var list = document.getElementById('teamList');

        // Event delegation for move buttons
        list.addEventListener('click', function(e) {
            var btn = e.target.closest('.mv-btn');
            if (!btn || btn.disabled) return;
            var item = btn.closest('.team-item');
            var items = Array.from(list.children);
            var idx = items.indexOf(item);

            if (btn.classList.contains('mv-up') && idx > 0) {
                list.insertBefore(item, items[idx - 1]);
                markUnsaved();
            } else if (btn.classList.contains('mv-down') && idx < items.length - 1) {
                list.insertBefore(items[idx + 1], item);
                markUnsaved();
            }
            updateRanks();
        });

        // Touch drag support
        var dragItem = null, dragY = 0, placeholder = null;

        list.addEventListener('touchstart', function(e) {
            var item = e.target.closest('.team-item');
            if (!item || e.target.closest('.mv-btn')) return;
            dragItem = item;
            dragY = e.touches[0].clientY;
            item.classList.add('dragging');
        }, { passive: true });

        list.addEventListener('touchmove', function(e) {
            if (!dragItem) return;
            e.preventDefault();
            var touch = e.touches[0];
            var target = document.elementFromPoint(touch.clientX, touch.clientY);
            var targetItem = target ? target.closest('.team-item') : null;
            if (targetItem && targetItem !== dragItem) {
                var rect = targetItem.getBoundingClientRect();
                var mid = rect.top + rect.height / 2;
                if (touch.clientY < mid) {
                    list.insertBefore(dragItem, targetItem);
                } else {
                    list.insertBefore(dragItem, targetItem.nextSibling);
                }
                markUnsaved();
            }
        }, { passive: false });

        list.addEventListener('touchend', function() {
            if (dragItem) {
                dragItem.classList.remove('dragging');
                dragItem = null;
                updateRanks();
            }
        });

        updateRanks();
    });

    function updateRanks() {
        var items = document.getElementById('teamList').children;
        for (var i = 0; i < items.length; i++) {
            var rn = items[i].querySelector('.rank-num');
            if (rn) rn.textContent = i + 1;
            var up = items[i].querySelector('.mv-up');
            var dn = items[i].querySelector('.mv-down');
            if (up) up.disabled = (i === 0);
            if (dn) dn.disabled = (i === items.length - 1);
        }
    }

    function markUnsaved() {
        hasUnsaved = true;
        var dot = document.getElementById('unsavedDot');
        if (dot) dot.classList.add('visible');
    }

    function switchScope(val) {
        window.location.href = 'draft_preferences.php?league_id=' + encodeURIComponent(val);
    }

    function getPreferencesArray() {
        var items = document.getElementById('teamList').children;
        var prefs = [];
        for (var i = 0; i < items.length; i++) {
            prefs.push({ team_id: parseInt(items[i].dataset.teamId), priority_rank: i + 1 });
        }
        return prefs;
    }

    function savePreferences() {
        var btn = document.getElementById('saveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

        var scope = document.getElementById('scopeSelect').value;

        fetch('/nba-wins-platform/api/draft_preferences_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save_preferences',
                league_id: scope,
                preferences: getPreferencesArray()
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                hasUnsaved = false;
                notify('Preferences saved!', 'success');
                // Update badge
                var badge = document.getElementById('scopeBadge');
                if (scope === 'global') {
                    badge.className = 'scope-badge global';
                    badge.textContent = 'Global';
                } else {
                    badge.className = 'scope-badge league';
                    badge.textContent = 'League-specific';
                }
            } else {
                throw new Error(data.error || 'Save failed');
            }
        })
        .catch(function(err) { notify('Error: ' + err.message, 'error'); })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Save <span class="unsaved-dot' + (hasUnsaved ? ' visible' : '') + '" id="unsavedDot"></span>';
        });
    }

    function applyGlobally() {
        if (!confirm('Apply the current ranking to ALL your leagues and as the global default?')) return;

        var btn = document.querySelector('.apply-all');
        btn.disabled = true;

        fetch('/nba-wins-platform/api/draft_preferences_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'apply_globally',
                preferences: getPreferencesArray()
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                hasUnsaved = false;
                var dot = document.getElementById('unsavedDot');
                if (dot) dot.classList.remove('visible');
                notify(data.message, 'success');
            } else {
                throw new Error(data.error || 'Failed');
            }
        })
        .catch(function(err) { notify('Error: ' + err.message, 'error'); })
        .finally(function() { btn.disabled = false; });
    }

    function resetToProjections() {
        if (!confirm('Reset to Vegas projected wins order?')) return;
        var list = document.getElementById('teamList');
        var items = Array.from(list.children);
        items.sort(function(a, b) {
            var pa = parseFloat(a.querySelector('.proj-badge').textContent.replace('O/U ', ''));
            var pb = parseFloat(b.querySelector('.proj-badge').textContent.replace('O/U ', ''));
            return pb - pa;
        });
        list.innerHTML = '';
        items.forEach(function(item) { list.appendChild(item); });
        markUnsaved();
        updateRanks();
        notify('Reset to projections', 'success');
    }

    function reverseOrder() {
        var list = document.getElementById('teamList');
        var items = Array.from(list.children);
        items.reverse().forEach(function(item) { list.appendChild(item); });
        markUnsaved();
        updateRanks();
        notify('Order reversed', 'success');
    }

    function notify(msg, type) {
        var n = document.createElement('div');
        n.className = 'notif ' + type;
        n.textContent = msg;
        document.body.appendChild(n);
        setTimeout(function() { n.classList.add('show'); }, 50);
        setTimeout(function() {
            n.classList.remove('show');
            setTimeout(function() { if (n.parentNode) n.parentNode.removeChild(n); }, 300);
        }, 3500);
    }

    window.addEventListener('beforeunload', function(e) {
        if (hasUnsaved) { e.preventDefault(); e.returnValue = 'Unsaved changes'; }
    });
    </script>

    <?php $currentPage = ''; include '/data/www/default/nba-wins-platform/components/pill_nav.php'; ?>
</body>
</html>