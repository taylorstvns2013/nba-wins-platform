<?php
// /data/www/default/nba-wins-platform/components/pill_nav.php
// Floating Pill Navigation Component
// Include at the bottom of any page, before </body>
//
// REQUIRES: $pdo must be available
// OPTIONAL: Set $currentPage before including to highlight the active item
//           Recognized values: 'home', 'profile', 'analytics', 'column', 
//                              'standings', 'draft', 'league_hub'

// ---------------------------------------------------------------------------
// Resolve variables the pill needs from session (safe defaults if unset)
// ---------------------------------------------------------------------------
$pillLeagueId   = $_SESSION['current_league_id'] ?? 0;
$pillUserId     = $_SESSION['user_id'] ?? 0;
$pillIsGuest    = isset($_SESSION['is_guest']) && $_SESSION['is_guest'] === true;
$pillActivePage = $currentPage ?? '';
$pillHasLeague  = !empty($pillLeagueId);

// Check if current league's draft is completed (for draft link routing)
$pillDraftCompleted = false;
if ($pillHasLeague && isset($pdo)) {
    try {
        $pillDraftStmt = $pdo->prepare("SELECT draft_completed FROM leagues WHERE id = ?");
        $pillDraftStmt->execute([$pillLeagueId]);
        $pillDraftCompleted = (bool)$pillDraftStmt->fetchColumn();
    } catch (Exception $e) {
        // Fail silently — default to draft page
    }
}

// Profile link target: guests see the first participant, logged-in users see themselves
$pillProfileUserId = $pillUserId;
if ($pillIsGuest && $pillLeagueId && isset($pdo)) {
    try {
        $pillStmt = $pdo->prepare("
            SELECT u.id FROM league_participants lp
            JOIN users u ON lp.user_id = u.id
            WHERE lp.league_id = ? AND lp.status = 'active'
            ORDER BY u.display_name ASC LIMIT 1
        ");
        $pillStmt->execute([$pillLeagueId]);
        $pillResult = $pillStmt->fetch(PDO::FETCH_ASSOC);
        if ($pillResult) $pillProfileUserId = $pillResult['id'];
    } catch (Exception $e) {
        error_log('pill_nav: failed to get first participant: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Check for unread articles (NEW dot on Column icon)
// Only compute if not already set by the including page
// ---------------------------------------------------------------------------
if (!isset($hasNewArticles)) {
    $hasNewArticles = false;
    $pillColumnDir = $_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/claudes-column/';
    if (!$pillIsGuest && isset($pdo) && $pillUserId && is_dir($pillColumnDir)) {
        $pillLatestFile = null;
        $pillLatestDate = 0;
        $pillFiles = scandir($pillColumnDir);
        foreach ($pillFiles as $pf) {
            if (pathinfo($pf, PATHINFO_EXTENSION) === 'html') {
                $pfContent = @file_get_contents($pillColumnDir . $pf);
                if ($pfContent) {
                    $pfDom = new DOMDocument();
                    @$pfDom->loadHTML($pfContent);
                    foreach ($pfDom->getElementsByTagName('meta') as $pfMeta) {
                        if ($pfMeta->getAttribute('name') === 'date') {
                            $pfDate = strtotime($pfMeta->getAttribute('content'));
                            if ($pfDate && $pfDate > $pillLatestDate) {
                                $pillLatestDate = $pfDate;
                                $pillLatestFile = $pf;
                            }
                        }
                    }
                }
            }
        }
        if ($pillLatestFile) {
            try {
                $pillReadStmt = $pdo->prepare("SELECT 1 FROM user_article_reads WHERE user_id = ? AND article_filename = ?");
                $pillReadStmt->execute([$pillUserId, $pillLatestFile]);
                $hasNewArticles = !$pillReadStmt->fetch();
            } catch (Exception $e) {
                $hasNewArticles = false;
            }
        }
    }
}
?>

<!-- ===== FLOATING PILL NAV STYLES ===== -->
<style>
    /* Pill adds bottom padding so page content isn't hidden behind it */
    body { padding-bottom: 84px; }

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

    /* Main row (always visible) */
    .pill-main-row {
        display: flex;
        align-items: center;
        gap: 2px;
    }

    /* Expanded row (hidden by default) */
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
        border-bottom: 1px solid rgba(255, 255, 255, 0.12);
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
        color: #9da5ae;
        font-size: 14px;
        transition: all 0.15s ease;
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
        color: #e6edf3;
        background: rgba(255, 255, 255, 0.08);
    }
    .pill-expanded-item.logout-item:hover {
        color: #f85149;
    }
    .pill-expanded-item.active-expanded {
        color: #388bfd;
    }

    /* Hamburger to X morph */
    .pill-menu-btn .fa-bars,
    .pill-menu-btn .fa-xmark { transition: transform 0.3s ease, opacity 0.2s ease; }
    .pill-menu-btn .fa-xmark { position: absolute; opacity: 0; transform: rotate(-90deg); }
    .floating-pill.expanded .pill-menu-btn .fa-bars { opacity: 0; transform: rotate(90deg); }
    .floating-pill.expanded .pill-menu-btn .fa-xmark { opacity: 1; transform: rotate(0deg); }

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
        color: #9da5ae;
        font-size: 17px;
        transition: all 0.15s ease;
        cursor: pointer;
        border: none;
        background: none;
        -webkit-tap-highlight-color: transparent;
        position: relative;
    }
    .pill-item:hover {
        color: #e6edf3;
        background: #2a3446;
    }
    .pill-item.active {
        color: white;
        background: #388bfd;
    }
    .pill-item:active {
        transform: scale(0.92);
    }

    .pill-divider {
        width: 2px;
        height: 26px;
        background: rgba(255, 255, 255, 0.28);
        flex-shrink: 0;
    }

    /* Tooltip on hover (desktop only) */
    @media (min-width: 601px) {
        .pill-item::after {
            content: attr(data-label);
            position: absolute;
            bottom: calc(100% + 8px);
            left: 50%;
            transform: translateX(-50%) scale(0.9);
            background: #2a3446;
            color: #e6edf3;
            font-size: 11px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            padding: 4px 10px;
            border-radius: 6px;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: all 0.15s ease;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .pill-item:hover::after {
            opacity: 1;
            transform: translateX(-50%) scale(1);
        }
        /* Hide tooltips when expanded (items have labels) */
        .floating-pill.expanded .pill-item:hover::after { opacity: 0; }
    }
</style>

<!-- ===== PILL TOAST MESSAGE ===== -->
<style>
    .pill-toast {
        position: fixed;
        bottom: 90px;
        left: 50%;
        transform: translateX(-50%) translateY(20px);
        background: rgba(24, 33, 47, 0.95);
        color: #e6edf3;
        padding: 12px 20px;
        border-radius: 10px;
        font-family: 'Outfit', sans-serif;
        font-size: 13px;
        font-weight: 500;
        z-index: 10000;
        pointer-events: none;
        opacity: 0;
        transition: opacity 0.25s ease, transform 0.25s ease;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
        border: 1px solid rgba(255, 255, 255, 0.08);
        text-align: center;
        max-width: 320px;
        line-height: 1.4;
    }
    .pill-toast.visible {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }
</style>

<!-- ===== FLOATING PILL NAV HTML ===== -->
<nav class="floating-pill" id="floatingPill">
    <!-- Expanded row (hidden until menu tap) -->
    <div class="pill-expanded-row" id="pillExpandedRow">
        <a href="/nba_standings.php" class="pill-expanded-item <?= $pillActivePage === 'standings' ? 'active-expanded' : '' ?>">
            <i class="fas fa-basketball-ball"></i>
            <span>Standings</span>
        </a>
        <?php if ($pillHasLeague): ?>
        <a href="<?= $pillDraftCompleted ? '/draft_summary.php' : '/draft.php' ?>" class="pill-expanded-item <?= $pillActivePage === 'draft' ? 'active-expanded' : '' ?>">
            <i class="fas fa-file-alt"></i>
            <span>Draft</span>
        </a>
        <?php else: ?>
        <a href="javascript:void(0)" onclick="pillToast('Join a league with a completed draft to view the summary')" class="pill-expanded-item">
            <i class="fas fa-file-alt"></i>
            <span>Draft</span>
        </a>
        <?php endif; ?>
        <?php if (!$pillIsGuest): ?>
        <a href="/nba-wins-platform/auth/league_hub.php" class="pill-expanded-item <?= $pillActivePage === 'league_hub' ? 'active-expanded' : '' ?>">
            <i class="fas fa-shield-alt"></i>
            <span>Leagues</span>
        </a>
        <?php endif; ?>
        <a href="https://buymeacoffee.com/taylorstvns" target="_blank" class="pill-expanded-item">
            <i class="fas fa-mug-hot"></i>
            <span>Tip Jar</span>
        </a>
        <?php if (!$pillIsGuest): ?>
        <a href="/nba-wins-platform/auth/logout.php" class="pill-expanded-item logout-item">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
        <?php endif; ?>
    </div>
    <!-- Main row -->
    <div class="pill-main-row">
        <a href="/index.php" class="pill-item <?= $pillActivePage === 'home' ? 'active' : '' ?>" data-label="Home">
            <i class="fas fa-home"></i>
        </a>
        <?php if ($pillHasLeague): ?>
        <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?= $pillLeagueId ?>&user_id=<?= $pillProfileUserId ?>" class="pill-item <?= $pillActivePage === 'profile' ? 'active' : '' ?>" data-label="<?= $pillIsGuest ? 'Profiles' : 'My Profile' ?>">
            <i class="fas <?= $pillIsGuest ? 'fa-users' : 'fa-user' ?>"></i>
        </a>
        <?php else: ?>
        <a href="javascript:void(0)" onclick="pillToast('Join a league to view your profile')" class="pill-item" data-label="Profile">
            <i class="fas fa-user"></i>
        </a>
        <?php endif; ?>
        <a href="/analytics.php" class="pill-item <?= $pillActivePage === 'analytics' ? 'active' : '' ?>" data-label="Analytics">
            <i class="fas fa-chart-line"></i>
        </a>
        <?php if ($pillHasLeague): ?>
        <a href="/claudes-column.php" class="pill-item <?= $pillActivePage === 'column' ? 'active' : '' ?>" data-label="Column" style="position:relative">
            <i class="fa-solid fa-newspaper"></i>
            <?php if ($hasNewArticles): ?><span style="position:absolute;top:2px;right:2px;width:7px;height:7px;background:#f85149;border-radius:50%;box-shadow:0 0 4px rgba(248,81,73,0.5)"></span><?php endif; ?>
        </a>
        <?php else: ?>
        <a href="javascript:void(0)" onclick="pillToast('Join a league to access Claude\'s Column')" class="pill-item" data-label="Column">
            <i class="fa-solid fa-newspaper"></i>
        </a>
        <?php endif; ?>
        <div class="pill-divider"></div>
        <button class="pill-item pill-menu-btn" data-label="Menu" onclick="togglePillMenu()">
            <i class="fas fa-bars"></i>
            <i class="fas fa-xmark"></i>
        </button>
    </div>
</nav>

<!-- ===== FLOATING PILL NAV JS ===== -->
<div class="pill-toast" id="pillToast"></div>
<script>
function togglePillMenu() {
    document.getElementById('floatingPill').classList.toggle('expanded');
}
// Close expanded pill when clicking outside
document.addEventListener('click', function(e) {
    var pill = document.getElementById('floatingPill');
    if (pill && pill.classList.contains('expanded') && !pill.contains(e.target)) {
        pill.classList.remove('expanded');
    }
});
// Toast message for locked pill items
var pillToastTimer = null;
function pillToast(msg) {
    var toast = document.getElementById('pillToast');
    if (!toast) return;
    toast.textContent = msg;
    toast.classList.add('visible');
    clearTimeout(pillToastTimer);
    pillToastTimer = setTimeout(function() {
        toast.classList.remove('visible');
    }, 3000);
}
</script>