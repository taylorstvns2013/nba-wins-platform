<?php
// claudes-column.php - Sports Column Page
// Place this file in /data/www/default/

// Start session for authentication check
session_start();

// Optional: Include your database connection if you want to add authentication
// require_once __DIR__ . '/nba-wins-platform/config/db_connection.php';

// Get list of articles from the claudes-column directory
$column_dir = __DIR__ . '/nba-wins-platform/claudes-column/';
$articles = [];

if (is_dir($column_dir)) {
    $files = scandir($column_dir);
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'html') {
            $filepath = $column_dir . $file;
            
            // Parse article metadata from HTML
            $content = file_get_contents($filepath);
            $dom = new DOMDocument();
            @$dom->loadHTML($content);
            
            $title = '';
            $date = '';
            $description = '';
            
            // Extract metadata
            $metas = $dom->getElementsByTagName('meta');
            foreach ($metas as $meta) {
                $name = $meta->getAttribute('name');
                $content_attr = $meta->getAttribute('content');
                
                if ($name === 'date') {
                    $date = $content_attr;
                } elseif ($name === 'description') {
                    $description = $content_attr;
                }
            }
            
            // Extract title
            $titles = $dom->getElementsByTagName('title');
            if ($titles->length > 0) {
                $title = $titles->item(0)->textContent;
            }
            
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

// Sort articles by date (newest first)
usort($articles, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

// Get selected article
$selected_article = null;
if (isset($_GET['article']) && !empty($_GET['article'])) {
    $requested_file = basename($_GET['article']); // Security: prevent directory traversal
    foreach ($articles as $article) {
        if ($article['filename'] === $requested_file) {
            $selected_article = $article;
            break;
        }
    }
}

// If no article selected, show the most recent one
if (!$selected_article && !empty($articles)) {
    $selected_article = $articles[0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="theme-color" content="#f5f5f5">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claude's Column - NBA Wins Pool</title>
    <meta name="description" content="AI-powered NBA analysis and commentary">
    <link rel="apple-touch-icon" type="image/png" href="nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <!-- React and Babel for Navigation Component -->
    <script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
    <style>
        :root {
            --primary-color: #212121;
            --secondary-color: #424242;
            --background-color: rgba(245, 245, 245, 0.8);
            --text-color: #333333;
            --border-color: #e0e0e0;
            --hover-color: #757575;
            --basketball-orange: #FF7F00;
            --success-color: #4CAF50;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 0;
            background-image: url('nba-wins-platform/public/assets/background/geometric_white.png');
            background-repeat: repeat;
            background-attachment: fixed;
            color: var(--text-color);
            background-color: #f5f5f5;
            min-height: 100vh;
            min-height: -webkit-fill-available;
        }

        html {
            height: -webkit-fill-available;
            background-color: #f5f5f5;
        }
        
        /* Menu styling */
        .menu-container {
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
        }
        
        .menu-button {
            position: fixed;
            top: 1rem;
            left: 1rem;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 4px;
            padding: 0.5rem;
            cursor: pointer;
            z-index: 1002;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .menu-button:hover {
            background-color: var(--secondary-color);
        }
        
        .menu-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1001;
        }
        
        .menu-panel {
            position: fixed;
            top: 0;
            left: -300px;
            width: 300px;
            height: 100vh;
            background-color: white;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            transition: left 0.3s ease;
            z-index: 1002;
        }
        
        .menu-panel.menu-open {
            left: 0;
        }
        
        .menu-header {
            padding: 1rem;
            display: flex;
            justify-content: flex-end;
            border-bottom: 1px solid var(--border-color);
        }
        
        .close-button {
            background: none;
            border: none;
            color: var(--text-color);
            cursor: pointer;
            padding: 0.5rem;
        }
        
        .close-button:hover {
            color: var(--hover-color);
        }
        
        .menu-content {
            padding: 1rem;
        }
        
        .menu-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .menu-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem;
            color: var(--text-color);
            text-decoration: none;
            transition: background-color 0.2s;
            border-radius: 4px;
        }
        
        .menu-link:hover {
            background-color: var(--background-color);
            color: var(--secondary-color);
        }
        
        .menu-link i {
            width: 20px;
        }
        
        /* Top Banner */
        .banner {
            background-color: var(--primary-color);
            padding: 20px 0;
            margin-bottom: 20px;
        }
        
        .banner-content {
            display: flex;
            align-items: center;
            gap: 15px;
            max-width: 900px;
            margin: 0 auto;
            padding: 0 15px;
        }
        
        .banner-logo {
            width: 60px;
            height: 60px;
        }
        
        .banner-title {
            color: white;
            font-size: 1.5rem;
            font-weight: bold;
            margin: 0;
        }
        
        /* Back Link */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            color: var(--text-color);
            text-decoration: none;
            font-weight: 500;
            padding: 8px 16px;
            background: rgba(255,255,255,0.8);
            border-radius: 6px;
            transition: background-color 0.2s;
        }
        
        .back-link:hover {
            background: rgba(255,255,255,1);
            color: var(--basketball-orange);
        }
        
        /* Article Selector */
        .article-selector {
            background: rgba(255, 255, 255, 0.9);
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
        }
        
        .selector-content {
            display: flex;
            align-items: center;
            gap: 1rem;
            max-width: 900px;
            margin: 0 auto;
        }
        
        .selector-label {
            font-weight: 600;
            color: var(--secondary-color);
            white-space: nowrap;
        }
        
        .article-dropdown {
            flex: 1;
            padding: 0.75rem 1rem;
            background: white;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-color);
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .article-dropdown:hover {
            border-color: var(--basketball-orange);
        }
        
        .article-dropdown:focus {
            outline: none;
            border-color: var(--basketball-orange);
            box-shadow: 0 0 0 3px rgba(255, 127, 0, 0.1);
        }
        
        /* Main Content */
        .content-wrapper {
            max-width: 900px;
            margin: 0 auto;
            padding: 0 15px 20px 15px;
        }
        
        .article-container {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 12px;
            padding: 3rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border-color);
        }
        
        /* Article Styles */
        article h1 {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--primary-color);
            margin-bottom: 1rem;
            line-height: 1.2;
        }
        
        article .subtitle {
            font-size: 1.2rem;
            color: var(--secondary-color);
            margin-bottom: 1.5rem;
            font-style: italic;
        }
        
        .article-meta {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 2rem;
            padding-bottom: 2rem;
            border-bottom: 2px solid var(--border-color);
        }
        
        .author-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            flex-shrink: 0;
        }
        
        .author-info {
            display: flex;
            flex-direction: column;
        }
        
        .author-name {
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .author-title {
            font-size: 0.85rem;
            color: var(--secondary-color);
            line-height: 1.2;
        }
        
        .article-date {
            text-align: right;
            margin-left: auto;
            font-size: 0.9rem;
            color: var(--secondary-color);
        }
        
        article h2 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--basketball-orange);
            margin-top: 2.5rem;
            margin-bottom: 1rem;
        }
        
        article h3 {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-top: 2rem;
            margin-bottom: 0.75rem;
        }
        
        article p {
            margin-bottom: 1.25rem;
            font-size: 1.05rem;
            line-height: 1.8;
            color: var(--text-color);
        }
        
        article ul, article ol {
            margin-left: 2rem;
            margin-bottom: 1.25rem;
            color: var(--text-color);
        }
        
        article li {
            margin-bottom: 0.75rem;
            line-height: 1.8;
        }
        
        article strong {
            color: var(--primary-color);
            font-weight: 600;
        }
        
        article em {
            color: var(--secondary-color);
        }
        
        .author-note {
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 2px solid var(--border-color);
            color: var(--secondary-color);
            font-size: 0.95rem;
        }
        
        /* No Articles State */
        .no-articles {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--secondary-color);
        }
        
        .no-articles h2 {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--primary-color);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .banner-content {
                padding: 0 10px;
            }
            
            .banner-title {
                font-size: 1.25rem;
            }
            
            .banner-logo {
                width: 50px;
                height: 50px;
            }
            
            .selector-content {
                flex-direction: column;
                align-items: stretch;
            }
            
            .article-dropdown {
                max-width: 100%;
            }
            
            .article-container {
                padding: 2rem 1.5rem;
            }
            
            .article-meta {
                align-items: center;
            }
            
            article h1 {
                font-size: 1.8rem;
            }
            
            article h2 {
                font-size: 1.5rem;
            }
            
            article h3 {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <?php 
    // Include the navigation menu component (always show it)
    include $_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/components/navigation_menu.php'; 
    ?>
    
    <!-- Top Banner -->
    <div class="banner">
        <div class="banner-content">
            <a href="/">
                <img src="nba-wins-platform/public/assets/favicon/favicon.png" alt="NBA Logo" class="banner-logo">
            </a>
            <h1 class="banner-title">Claude's Column</h1>
        </div>
    </div>
    
    <!-- Article Selector -->
    <?php if (!empty($articles)): ?>
    <div class="content-wrapper">
        <a href="/" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Wins Pool
        </a>
        
        <div class="article-selector">
            <div class="selector-content">
                <label class="selector-label" for="article-select">Select Article:</label>
                <select id="article-select" class="article-dropdown" onchange="loadArticle(this.value)">
                    <?php foreach ($articles as $article): ?>
                    <option value="<?php echo htmlspecialchars($article['filename']); ?>" 
                            <?php echo ($selected_article && $selected_article['filename'] === $article['filename']) ? 'selected' : ''; ?>>
                        <?php 
                        $display_title = $article['title'];
                        if ($article['date']) {
                            $display_title = date('M j, Y', strtotime($article['date'])) . ' - ' . $display_title;
                        }
                        echo htmlspecialchars($display_title); 
                        ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Main Content -->
    <?php if (!empty($articles)): ?>
        <?php if ($selected_article): ?>
        <div class="article-container">
            <?php
            // Load and display the article content with author byline
            $content = file_get_contents($selected_article['filepath']);
            $dom = new DOMDocument();
            @$dom->loadHTML($content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            
            // Extract the article element
            $articles_dom = $dom->getElementsByTagName('article');
            if ($articles_dom->length > 0) {
                $article_element = $articles_dom->item(0);
                
                // Find h1 and subtitle
                $h1_element = null;
                $subtitle_element = null;
                
                foreach ($article_element->childNodes as $node) {
                    if ($node->nodeName === 'h1') {
                        $h1_element = $node;
                    } elseif ($node->nodeName === 'p' && 
                             $node->getAttribute('class') === 'subtitle') {
                        $subtitle_element = $node;
                        break;
                    }
                }
                
                // Output the article opening tag
                echo '<article>';
                
                // Output h1 if found
                if ($h1_element) {
                    echo $dom->saveHTML($h1_element);
                }
                
                // Output subtitle if found
                if ($subtitle_element) {
                    echo $dom->saveHTML($subtitle_element);
                }
                
                // Insert author byline
                $article_date = $selected_article['date'] ? date('F j, Y', strtotime($selected_article['date'])) : 'Recent';
                ?>
                <div class="article-meta">
                    <div class="author-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="author-info">
                        <div class="author-name">Claude Sonnet</div>
                        <div class="author-title">NBA Wins<br>Pool Analyst</div>
                    </div>
                    <div class="article-date">
                        <?php echo $article_date; ?>
                    </div>
                </div>
                <?php
                
                // Output the rest of the content
                foreach ($article_element->childNodes as $node) {
                    // Skip h1 and subtitle as we already output them
                    if (($node->nodeName === 'h1') || 
                        ($node->nodeName === 'p' && $node->getAttribute('class') === 'subtitle')) {
                        continue;
                    }
                    echo $dom->saveHTML($node);
                }
                
                echo '</article>';
            } else {
                // Fallback: display body content
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
    </div>
    <?php else: ?>
    <div class="content-wrapper">
        <a href="/" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Wins Pool
        </a>
        
        <div class="no-articles">
            <h2>No Articles Yet</h2>
            <p>Check back soon for AI-powered NBA analysis and commentary!</p>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
        function loadArticle(filename) {
            if (filename) {
                window.location.href = '?article=' + encodeURIComponent(filename);
            }
        }
    </script>
</body>
</html>
