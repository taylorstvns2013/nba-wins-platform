<?php
// claudes-column_new.php - Sports Column Page (Dark Theme)
session_start();

$column_dir = __DIR__ . '/nba-wins-platform/claudes-column/';
$articles = [];

if (is_dir($column_dir)) {
    $files = scandir($column_dir);
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'html') {
            $filepath = $column_dir . $file;
            $content = file_get_contents($filepath);
            $dom = new DOMDocument();
            @$dom->loadHTML($content);
            
            $title = '';
            $date = '';
            $description = '';
            
            $metas = $dom->getElementsByTagName('meta');
            foreach ($metas as $meta) {
                $name = $meta->getAttribute('name');
                $content_attr = $meta->getAttribute('content');
                if ($name === 'date') $date = $content_attr;
                elseif ($name === 'description') $description = $content_attr;
            }
            
            $titles = $dom->getElementsByTagName('title');
            if ($titles->length > 0) $title = $titles->item(0)->textContent;
            
            $articles[] = [
                'filename' => $file,
                'title' => $title ?: str_replace(['-', '.html'], [' ', ''], $file),
                'date' => $date,
                'description' => $description,
                'filepath' => $filepath
            ];
        }
    }
}

usort($articles, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

$selected_article = null;
if (isset($_GET['article']) && !empty($_GET['article'])) {
    $requested_file = basename($_GET['article']);
    foreach ($articles as $article) {
        if ($article['filename'] === $requested_file) {
            $selected_article = $article;
            break;
        }
    }
}

if (!$selected_article && !empty($articles)) {
    $selected_article = $articles[0];
}

// Include DB connection for nav/league switcher
require_once '/data/www/default/nba-wins-platform/config/db_connection.php';
$user_id = $_SESSION['user_id'] ?? null;
$league_id = $_SESSION['current_league_id'] ?? null;
$currentLeagueId = $league_id;
$isGuest = isset($auth) && $auth->isGuest();

// Mark article as read for logged-in (non-guest) users
if ($selected_article && $user_id && !$isGuest) {
    try {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO user_article_reads (user_id, article_filename, read_at)
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$user_id, $selected_article['filename']]);
    } catch (Exception $e) {
        // Table might not exist yet — silently ignore
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
    <title>Claude's Column - NBA Wins Pool</title>
    <meta name="description" content="AI-powered NBA analysis and commentary">
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
        --accent-orange: #d29922;
        --radius-sm: 6px;
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

    * { margin: 0; padding: 0; box-sizing: border-box; }
    html { background-color: var(--bg-primary); }

    body {
        font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
        line-height: 1.6;
        color: var(--text-primary);
        background: var(--bg-primary);
        background-image: radial-gradient(ellipse at 50% 0%, rgba(56, 139, 253, 0.04) 0%, transparent 60%);
        min-height: 100vh;
        -webkit-font-smoothing: antialiased;
    }

    .app-container {
        max-width: 900px;
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

    /* Back link */
    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 14px;
        color: var(--text-secondary);
        text-decoration: none;
        font-weight: 500;
        font-size: 14px;
        padding: 7px 14px;
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-md);
        transition: all var(--transition-fast);
    }

    .back-link:hover {
        color: var(--accent-blue);
        border-color: rgba(56, 139, 253, 0.3);
    }

    /* Article selector */
    .article-selector {
        background: var(--bg-card);
        padding: 14px 16px;
        border-radius: var(--radius-lg);
        margin-bottom: 16px;
        box-shadow: var(--shadow-card);
    }

    .selector-content {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .selector-label {
        font-weight: 600;
        font-size: 14px;
        color: var(--text-secondary);
        white-space: nowrap;
    }

    .article-dropdown {
        flex: 1;
        padding: 9px 32px 9px 14px;
        font-family: 'Outfit', sans-serif;
        font-size: 14px;
        font-weight: 500;
        background: var(--bg-elevated);
        color: var(--text-primary);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-md);
        cursor: pointer;
        appearance: none;
        -webkit-appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238b949e' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 10px center;
        transition: all var(--transition-fast);
    }

    .article-dropdown:hover { border-color: rgba(56, 139, 253, 0.3); }
    .article-dropdown:focus { outline: none; border-color: var(--accent-blue); box-shadow: 0 0 0 2px var(--accent-blue-dim); }
    .article-dropdown option { background: var(--bg-elevated); color: var(--text-primary); }

    /* Article container */
    .article-container {
        background: var(--bg-card);
        border-radius: var(--radius-lg);
        padding: 2.5rem;
        box-shadow: var(--shadow-card);
    }

    /* Article content overrides */
    .article-container article h1 {
        font-family: 'Outfit', sans-serif;
        font-size: 2.2rem;
        font-weight: 800;
        color: var(--text-primary);
        margin-bottom: 0.75rem;
        line-height: 1.15;
        letter-spacing: -0.02em;
    }

    .article-container article .subtitle {
        font-size: 1.1rem;
        color: var(--text-secondary);
        margin-bottom: 1.25rem;
        font-style: italic;
    }

    .article-meta {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        margin-bottom: 2rem;
        padding-bottom: 2rem;
        border-bottom: 1px solid var(--border-color);
    }

    .author-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--bg-elevated);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-muted);
        flex-shrink: 0;
    }

    .author-info { display: flex; flex-direction: column; }

    .author-name {
        font-weight: 600;
        font-size: 0.9rem;
        color: var(--text-primary);
    }

    .author-title {
        font-size: 0.8rem;
        color: var(--text-muted);
        line-height: 1.3;
    }

    .article-date {
        text-align: right;
        margin-left: auto;
        font-size: 0.85rem;
        color: var(--text-muted);
    }

    .article-container article h2 {
        font-family: 'Outfit', sans-serif;
        font-size: 1.6rem;
        font-weight: 700;
        color: var(--accent-orange);
        margin-top: 2.5rem;
        margin-bottom: 0.75rem;
    }

    .article-container article h3 {
        font-family: 'Outfit', sans-serif;
        font-size: 1.3rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-top: 2rem;
        margin-bottom: 0.5rem;
    }

    .article-container article p {
        margin-bottom: 1.25rem;
        font-size: 1rem;
        line-height: 1.8;
        color: var(--text-secondary);
    }

    .article-container article ul,
    .article-container article ol {
        margin-left: 1.5rem;
        margin-bottom: 1.25rem;
        color: var(--text-secondary);
    }

    .article-container article li {
        margin-bottom: 0.5rem;
        line-height: 1.8;
    }

    .article-container article strong {
        color: var(--text-primary);
        font-weight: 600;
    }

    .article-container article em {
        color: var(--text-muted);
    }

    .article-container .author-note {
        margin-top: 2.5rem;
        padding-top: 1.5rem;
        border-top: 1px solid var(--border-color);
        color: var(--text-muted);
        font-size: 0.9rem;
    }

    /* No articles state */
    .no-articles {
        text-align: center;
        padding: 4rem 2rem;
        color: var(--text-muted);
    }

    .no-articles h2 {
        font-size: 1.5rem;
        margin-bottom: 0.75rem;
        color: var(--text-secondary);
    }

    /* Responsive */
    @media (max-width: 600px) {
        .article-container { padding: 1.5rem 1.25rem; }

        .selector-content {
            flex-direction: column;
            align-items: stretch;
        }

        .article-container article h1 { font-size: 1.6rem; }
        .article-container article h2 { font-size: 1.3rem; }
        .article-container article h3 { font-size: 1.1rem; }

        .article-meta { align-items: center; }
    }

    @media (min-width: 601px) {
        .app-container { padding: 0 20px 2rem; }
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

    /* Hamburger to X morph */
    .pill-menu-btn .fa-bars,
    .pill-menu-btn .fa-xmark { transition: transform 0.3s ease, opacity 0.2s ease; }
    .pill-menu-btn .fa-xmark { position: absolute; opacity: 0; transform: rotate(-90deg); }
    .floating-pill.expanded .pill-menu-btn .fa-bars { opacity: 0; transform: rotate(90deg); }
    .floating-pill.expanded .pill-menu-btn .fa-xmark { opacity: 1; transform: rotate(0deg); }

    /* Space at the bottom so content doesn't hide behind pill */
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

    /* Tooltip on hover (desktop only) */
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

        /* Hide tooltips when expanded (items have labels) */
        .floating-pill.expanded .pill-item:hover::after { opacity: 0; }
    }
    /* ===== GUEST LOCK OVERLAY ===== */
    .guest-lock-wrapper {
        position: relative;
    }
    .guest-lock-wrapper .app-container {
        filter: blur(6px);
        -webkit-filter: blur(6px);
        pointer-events: none;
        user-select: none;
        opacity: 0.5;
    }
    .guest-lock-overlay {
        position: fixed;
        inset: 0;
        z-index: 1000;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(18, 26, 35, 0.3);
        -webkit-backdrop-filter: blur(2px);
        backdrop-filter: blur(2px);
    }
    .guest-lock-box {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-lg);
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5), 0 0 0 1px var(--border-color);
        padding: 2.5rem 2rem;
        text-align: center;
        max-width: 380px;
        width: 90%;
    }
    .guest-lock-icon {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: var(--bg-elevated);
        border: 1px solid var(--border-color);
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.25rem;
        font-size: 22px;
        color: var(--text-muted);
    }
    .guest-lock-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }
    .guest-lock-desc {
        font-size: 0.85rem;
        color: var(--text-secondary);
        line-height: 1.5;
        margin-bottom: 1.5rem;
    }
    .guest-lock-actions {
        display: flex;
        gap: 8px;
        justify-content: center;
    }
    .guest-lock-btn {
        padding: 9px 20px;
        border-radius: 999px;
        font-family: 'Outfit', sans-serif;
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        transition: all var(--transition-fast);
        cursor: pointer;
        border: none;
    }
    .guest-lock-btn-primary {
        background: var(--accent-blue);
        color: white;
    }
    .guest-lock-btn-primary:hover {
        filter: brightness(1.15);
    }
    .guest-lock-btn-secondary {
        background: transparent;
        color: var(--text-muted);
        border: 1px solid var(--border-color);
    }
    .guest-lock-btn-secondary:hover {
        color: var(--text-primary);
        border-color: var(--text-muted);
    }

</style>
</head>
<body>

    <?php include '/data/www/default/nba-wins-platform/components/navigation_menu_new.php'; ?>

    <?php if ($isGuest): ?>
    <div class="guest-lock-wrapper">
        <div class="guest-lock-overlay">
            <div class="guest-lock-box">
                <div class="guest-lock-icon">
                    <i class="fas fa-lock"></i>
                </div>
                <div class="guest-lock-title">League Participants Only</div>
                <div class="guest-lock-desc">This feature is only available to league participants. Sign up or log in to access Claude's Column.</div>
                <div class="guest-lock-actions">
                    <a href="/nba-wins-platform/auth/register.php" class="guest-lock-btn guest-lock-btn-primary">Sign Up</a>
                    <a href="/index_new.php" class="guest-lock-btn guest-lock-btn-secondary">Go Back</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="app-container">

        <?php if (!empty($articles)): ?>
        <div class="article-selector">
            <div class="selector-content">
                <label class="selector-label" for="article-select">Select Article:</label>
                <select id="article-select" class="article-dropdown" onchange="loadArticle(this.value)">
                    <?php foreach ($articles as $cc_article): ?>
                    <option value="<?php echo htmlspecialchars($cc_article['filename']); ?>" 
                            <?php echo ($selected_article && $selected_article['filename'] === $cc_article['filename']) ? 'selected' : ''; ?>>
                        <?php 
                        $display_title = $cc_article['title'];
                        if ($cc_article['date']) {
                            $display_title = date('M j, Y', strtotime($cc_article['date'])) . ' - ' . $display_title;
                        }
                        echo htmlspecialchars($display_title); 
                        ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

            <?php if ($selected_article): ?>
            <div class="article-container">
                <?php
                $content = file_get_contents($selected_article['filepath']);
                $dom = new DOMDocument();
                @$dom->loadHTML($content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                
                $articles_dom = $dom->getElementsByTagName('article');
                if ($articles_dom->length > 0) {
                    $article_element = $articles_dom->item(0);
                    
                    $h1_element = null;
                    $subtitle_element = null;
                    
                    foreach ($article_element->childNodes as $node) {
                        if ($node->nodeName === 'h1') $h1_element = $node;
                        elseif ($node->nodeName === 'p' && $node->getAttribute('class') === 'subtitle') {
                            $subtitle_element = $node;
                            break;
                        }
                    }
                    
                    echo '<article>';
                    if ($h1_element) echo $dom->saveHTML($h1_element);
                    if ($subtitle_element) echo $dom->saveHTML($subtitle_element);
                    
                    $article_date = $selected_article['date'] ? date('F j, Y', strtotime($selected_article['date'])) : 'Recent';
                    ?>
                    <div class="article-meta">
                        <div class="author-avatar">
                            <i class="fas fa-robot"></i>
                        </div>
                        <div class="author-info">
                            <div class="author-name">Claude Sonnet</div>
                            <div class="author-title">NBA Wins Pool Analyst</div>
                        </div>
                        <div class="article-date"><?php echo $article_date; ?></div>
                    </div>
                    <?php
                    
                    foreach ($article_element->childNodes as $node) {
                        if (($node->nodeName === 'h1') || 
                            ($node->nodeName === 'p' && $node->getAttribute('class') === 'subtitle')) {
                            continue;
                        }
                        echo $dom->saveHTML($node);
                    }
                    echo '</article>';
                } else {
                    $bodies = $dom->getElementsByTagName('body');
                    if ($bodies->length > 0) {
                        foreach ($bodies->item(0)->childNodes as $node) {
                            echo $dom->saveHTML($node);
                        }
                    }
                }
                ?>
            </div>
            <?php else: ?>
            <div class="no-articles">
                <h2>No Articles Yet</h2>
                <p>Check back soon for AI-powered NBA analysis and commentary!</p>
            </div>
            <?php endif; ?>

        <?php else: ?>
        <div class="no-articles">
            <h2>No Articles Yet</h2>
            <p>Check back soon for AI-powered NBA analysis and commentary!</p>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($isGuest): ?>
    </div><!-- /.guest-lock-wrapper -->
    <?php endif; ?>

    <script>
        function loadArticle(filename) {
            if (filename) window.location.href = '?article=' + encodeURIComponent(filename);
        }
    </script>
    <!-- Floating Pill Navigation -->
    <nav class="floating-pill" id="floatingPill">
        <!-- Expanded row (hidden until menu tap) -->
        <div class="pill-expanded-row" id="pillExpandedRow">
            <a href="/nba_standings_new.php" class="pill-expanded-item">
                <i class="fas fa-basketball-ball"></i>
                <span>Standings</span>
            </a>
            <a href="/draft_summary_new.php" class="pill-expanded-item">
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
        <!-- Main row -->
        <div class="pill-main-row">
            <a href="/index_new.php" class="pill-item" data-label="Home">
                <i class="fas fa-home"></i>
            </a>
            <a href="/nba-wins-platform/profiles/participant_profile_new.php?league_id=<?php echo $currentLeagueId ?? ($_SESSION['current_league_id'] ?? 0); ?>&user_id=<?php echo $profileUserId ?? ($_SESSION['user_id'] ?? 0); ?>" class="pill-item" data-label="Profile">
                <i class="fas fa-user"></i>
            </a>
            <a href="/analytics_new.php" class="pill-item" data-label="Analytics">
                <i class="fas fa-chart-line"></i>
            </a>
            <a href="/claudes-column_new.php" class="pill-item active" data-label="Column" style="position:relative">
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
    // Close expanded pill when clicking outside
    document.addEventListener('click', function(e) {
        var pill = document.getElementById('floatingPill');
        if (pill.classList.contains('expanded') && !pill.contains(e.target)) {
            pill.classList.remove('expanded');
        }
    });
    </script>
</body>
</html>