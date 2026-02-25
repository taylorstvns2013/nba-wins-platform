<?php
// /data/www/default/nba-wins-platform/components/navigation_menu_new.php
// Dark Theme Navigation Menu Component
// For use with index_new.php dark theme redesign

$currentLeagueId = $_SESSION['current_league_id'] ?? 0;
$currentUserId = $_SESSION['user_id'] ?? 0;
$isGuest = isset($_SESSION['is_guest']) && $_SESSION['is_guest'] === true;

$firstParticipantUserId = 0;
if ($isGuest && $currentLeagueId && isset($pdo)) {
    try {
        $navStmt = $pdo->prepare("
            SELECT u.id as user_id 
            FROM league_participants lp 
            JOIN users u ON lp.user_id = u.id 
            WHERE lp.league_id = ? AND lp.status = 'active' 
            ORDER BY u.display_name ASC 
            LIMIT 1
        ");
        $navStmt->execute([$currentLeagueId]);
        $navResult = $navStmt->fetch(PDO::FETCH_ASSOC);
        if ($navResult) {
            $firstParticipantUserId = $navResult['user_id'];
        }
    } catch (Exception $e) {
        error_log('Navigation menu - failed to get first participant: ' . $e->getMessage());
    }
}

$hasNewArticles = false;
$column_dir = $_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/claudes-column/';
if (is_dir($column_dir)) {
    $files = scandir($column_dir);
    $sevenDaysAgo = strtotime('-7 days');
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'html') {
            $filepath = $column_dir . $file;
            $content = @file_get_contents($filepath);
            if ($content) {
                $dom = new DOMDocument();
                @$dom->loadHTML($content);
                $metas = $dom->getElementsByTagName('meta');
                foreach ($metas as $meta) {
                    if ($meta->getAttribute('name') === 'date') {
                        $articleDate = $meta->getAttribute('content');
                        if ($articleDate && strtotime($articleDate) > $sevenDaysAgo) {
                            $hasNewArticles = true;
                            break 2;
                        }
                    }
                }
            }
        }
    }
}

$profileUserId = $isGuest ? $firstParticipantUserId : $currentUserId;
?>

<!-- Dark Theme Navigation Menu -->
<div class="dark-nav-container">
    <div class="dark-nav-overlay" id="darkNavOverlay" onclick="closeDarkNav()"></div>
    
    <div class="dark-nav-panel" id="darkNavPanel">
        <div class="dark-nav-header">
            <div class="dark-nav-brand">
                <img src="/nba-wins-platform/public/assets/team_logos/Logo.png" alt="" style="width:28px;height:28px;">
                <span>NBA Wins Pool</span>
            </div>
            <button class="dark-nav-close" onclick="closeDarkNav()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <nav class="dark-nav-links">
            <a href="/index_new.php" class="dark-nav-link">
                <i class="fas fa-home"></i> Home
            </a>
            <a href="/nba-wins-platform/profiles/participant_profile_new.php?league_id=<?php echo $currentLeagueId; ?>&user_id=<?php echo $profileUserId; ?>" class="dark-nav-link">
                <i class="fas <?php echo $isGuest ? 'fa-users' : 'fa-user'; ?>"></i> <?php echo $isGuest ? 'Profiles' : 'My Profile'; ?>
            </a>
            <a href="/analytics_new.php" class="dark-nav-link">
                <i class="fas fa-chart-line"></i> Analytics
            </a>
            <a href="/claudes-column_new.php" class="dark-nav-link">
                <i class="fa-solid fa-newspaper"></i> Claude's Column
                <?php if ($hasNewArticles): ?>
                    <span class="dark-nav-new-badge">NEW</span>
                <?php endif; ?>
            </a>
            <a href="/nba_standings_new.php" class="dark-nav-link">
                <i class="fas fa-basketball-ball"></i> NBA Standings
            </a>
            <a href="/draft_summary_new.php" class="dark-nav-link">
                <i class="fas fa-file-alt"></i> Draft
            </a>
            <div class="dark-nav-divider"></div>
            <a href="https://buymeacoffee.com/taylorstvns" target="_blank" class="dark-nav-link">
                <i class="fas fa-mug-hot"></i> Buy Me a Coffee
            </a>
            <?php if (!$isGuest): ?>
            <a href="/nba-wins-platform/auth/logout.php" class="dark-nav-link dark-nav-logout">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
            <?php endif; ?>
        </nav>
    </div>
</div>

<style>
.dark-nav-container {
    z-index: 1000;
}

.dark-nav-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(2px);
    z-index: 1001;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.dark-nav-overlay.show {
    display: block;
    opacity: 1;
}

.dark-nav-panel {
    position: fixed;
    top: 0;
    left: 0;
    width: 280px;
    height: 100vh;
    height: 100dvh;
    background: #161b22;
    border-right: 1px solid rgba(255, 255, 255, 0.06);
    z-index: 1003;
    transform: translateX(-100%);
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
    overflow-y: auto;
}

.dark-nav-panel.open {
    transform: translateX(0);
}

.dark-nav-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 16px 12px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}

.dark-nav-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    font-family: 'Outfit', sans-serif;
    font-weight: 700;
    font-size: 16px;
    color: #e6edf3;
}

.dark-nav-close {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: none;
    border-radius: 6px;
    color: #545d68;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.15s ease;
}

.dark-nav-close:hover {
    background: rgba(255, 255, 255, 0.06);
    color: #e6edf3;
}

.dark-nav-links {
    padding: 8px;
    flex: 1;
}

.dark-nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 14px;
    color: #8b949e;
    text-decoration: none;
    font-family: 'Outfit', sans-serif;
    font-size: 14px;
    font-weight: 500;
    border-radius: 8px;
    transition: all 0.15s ease;
    margin-bottom: 2px;
}

.dark-nav-link:hover {
    background: rgba(255, 255, 255, 0.06);
    color: #e6edf3;
}

.dark-nav-link i {
    width: 18px;
    text-align: center;
    font-size: 14px;
}

.dark-nav-divider {
    height: 1px;
    background: rgba(255, 255, 255, 0.06);
    margin: 8px 14px;
}

.dark-nav-logout {
    color: #f85149;
}

.dark-nav-logout:hover {
    background: rgba(248, 81, 73, 0.1);
    color: #f85149;
}

.dark-nav-new-badge {
    display: inline-block;
    background: linear-gradient(135deg, #f85149 0%, #f0883e 100%);
    color: white;
    font-size: 9px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 4px;
    margin-left: auto;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

<?php if (($_SESSION['theme_preference'] ?? 'dark') === 'classic'): ?>
/* Classic theme overrides */
.dark-nav-panel {
    background: #ffffff;
    border-right: 1px solid #e0e0e0;
}
.dark-nav-header { border-bottom-color: #e0e0e0; }
.dark-nav-brand { color: #333; }
.dark-nav-close { color: #999; }
.dark-nav-close:hover { background: rgba(0, 0, 0, 0.05); color: #333; }
.dark-nav-link { color: #666; }
.dark-nav-link:hover { background: rgba(0, 0, 0, 0.05); color: #333; }
.dark-nav-divider { background: #e0e0e0; }
.dark-nav-logout { color: #dc3545; }
.dark-nav-logout:hover { background: rgba(220, 53, 69, 0.06); }
.dark-nav-overlay { background: rgba(0, 0, 0, 0.35); }
<?php endif; ?>
</style>

<script>
function toggleDarkNav() {
    const panel = document.getElementById('darkNavPanel');
    const overlay = document.getElementById('darkNavOverlay');
    panel.classList.toggle('open');
    overlay.classList.toggle('show');
    document.body.style.overflow = panel.classList.contains('open') ? 'hidden' : '';
}

function closeDarkNav() {
    document.getElementById('darkNavPanel').classList.remove('open');
    document.getElementById('darkNavOverlay').classList.remove('show');
    document.body.style.overflow = '';
}

// Close on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDarkNav();
});
</script>