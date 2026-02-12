<?php
// /data/www/default/nba-wins-platform/components/navigation_menu.php
// Navigation Menu Component - Include this in any page that needs the menu
// Last Updated: November 13, 2025

// Ensure we have session data
$currentLeagueId = $_SESSION['current_league_id'] ?? 0;
$currentUserId = $_SESSION['user_id'] ?? 0;
$isGuest = isset($_SESSION['is_guest']) && $_SESSION['is_guest'] === true;

// For guests, get the first participant's user_id in the current league
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

// Check for new articles (within last 7 days)
$hasNewArticles = false;
$column_dir = $_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/claudes-column/';

if (is_dir($column_dir)) {
    $files = scandir($column_dir);
    $sevenDaysAgo = strtotime('-7 days');
    
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'html') {
            $filepath = $column_dir . $file;
            
            // Parse article metadata from HTML to get the date
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
                            break 2; // Exit both loops
                        }
                    }
                }
            }
        }
    }
}
?>

<!-- Navigation Menu Container -->
<div id="navigation-root"></div>

<!-- Load the NavigationMenu component -->
<script type="text/babel">
// Check if component is already loaded to avoid duplicate renders
if (typeof window.navigationMenuRendered === 'undefined') {
    window.navigationMenuRendered = true;
    
    // Load the external component file
    const loadNavigationComponent = async () => {
        try {
            // Dynamically load the component (corrected path)
            const response = await fetch('/nba-wins-platform/public/js/NavigationMenu.js?v=' + Date.now());
            const componentCode = await response.text();
            
            // Execute the component code
            eval(Babel.transform(componentCode, { presets: ['react'] }).code);
            
            // Render the component
            const container = document.getElementById('navigation-root');
            if (container && window.NavigationMenu) {
                const root = ReactDOM.createRoot(container);
                root.render(React.createElement(window.NavigationMenu, {
                    leagueId: <?php echo json_encode($currentLeagueId); ?>,
                    userId: <?php echo json_encode($currentUserId); ?>,
                    hasNewArticles: <?php echo $hasNewArticles ? 'true' : 'false'; ?>,
                    isGuest: <?php echo $isGuest ? 'true' : 'false'; ?>,
                    firstParticipantUserId: <?php echo json_encode($firstParticipantUserId); ?>
                }));
            }
        } catch (error) {
            console.error('Failed to load navigation menu:', error);
        }
    };
    
    // Load when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadNavigationComponent);
    } else {
        loadNavigationComponent();
    }
}
</script>

<!-- NEW Badge Styling -->
<style>
    .new-badge {
        display: inline-block;
        background: linear-gradient(135deg, #FF6B6B 0%, #FF8E53 100%);
        color: white;
        font-size: 0.65rem;
        font-weight: 700;
        padding: 2px 6px;
        border-radius: 4px;
        margin-left: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        box-shadow: 0 2px 4px rgba(255, 107, 107, 0.3);
    }
</style>