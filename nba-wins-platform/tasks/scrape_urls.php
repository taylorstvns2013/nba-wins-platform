<?php
// scrape_urls.php - Scrape NBA game streaming URLs
// Location: /data/www/default/nba-wins-platform/tasks/

// Include database connection
require_once(__DIR__ . '/../config/db_connection_cli.php');

// Check if Composer autoload exists
$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    die("Error: Composer dependencies not found. Please run 'composer install' in the project root.\n");
}

require $vendorAutoload;

use Symfony\Component\DomCrawler\Crawler;

// Set timezone to EST
date_default_timezone_set('America/New_York');

try {
    // Check if game_stream_urls table exists, create if not
    $pdo->exec("CREATE TABLE IF NOT EXISTS game_stream_urls (
        id INT AUTO_INCREMENT PRIMARY KEY,
        game_date DATE NOT NULL,
        game_time TIME DEFAULT NULL,
        home_team VARCHAR(100) NOT NULL,
        away_team VARCHAR(100) NOT NULL,
        stream_url TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_game_date (game_date),
        INDEX idx_teams (home_team, away_team)
    )");
    
    // Check if game_time column exists, if not add it
    $checkColumn = $pdo->query("SHOW COLUMNS FROM game_stream_urls LIKE 'game_time'");
    if ($checkColumn->rowCount() == 0) {
        $pdo->exec("ALTER TABLE game_stream_urls ADD COLUMN game_time TIME AFTER game_date");
        echo "Added game_time column to database\n";
    }
} catch(PDOException $e) {
    error_log("Database setup error: " . $e->getMessage());
    die("Database setup failed: " . $e->getMessage());
}

// Function to normalize team names
function normalizeTeamName($teamName) {
    // Remove any prefix that doesn't belong to the team name
    $teamName = preg_replace('/^NBA\s+Streams\s+/', '', $teamName);
    
    $teamNameMap = [
        'Los Angeles Clippers' => 'LA Clippers',
        // Add any other team name mappings here if needed
    ];
    
    return isset($teamNameMap[$teamName]) ? $teamNameMap[$teamName] : $teamName;
}

// Function to convert UTC time string to local time
function convertUtcToLocalTime($utcTimeString) {
    try {
        // Parse the UTC time string (format: 2025-04-29T22:05:00Z)
        $utcTime = new DateTime($utcTimeString, new DateTimeZone('UTC'));
        
        // Convert to EST/EDT (America/New_York timezone)
        $utcTime->setTimezone(new DateTimeZone('America/New_York'));
        
        // Return as TIME format (HH:MM:SS)
        return $utcTime->format('H:i:s');
    } catch (Exception $e) {
        echo "Error parsing time: " . $e->getMessage() . "\n";
        return null;
    }
}

// Get today's date
$today = date('Y-m-d');

// Clear existing entries for today
$stmt = $pdo->prepare("DELETE FROM game_stream_urls WHERE game_date = ?");
$stmt->execute([$today]);

echo "Starting scrape for $today...\n";

// Initialize cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://thetvapp.to/nba');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$html = curl_exec($ch);

if (curl_errno($ch)) {
    $error = 'Curl error: ' . curl_error($ch);
    curl_close($ch);
    error_log($error);
    die($error);
}

curl_close($ch);

if (empty($html)) {
    $error = "Warning: Empty HTML response from thetvapp.to";
    echo "$error\n";
    error_log($error);
    die("No content retrieved from the website");
}

echo "HTML Length: " . strlen($html) . "\n";

try {
    $crawler = new Crawler($html);
    $links = $crawler->filter('a');
    echo "Found " . $links->count() . " total links\n";
} catch (Exception $e) {
    $error = "Error creating Crawler: " . $e->getMessage();
    error_log($error);
    die("$error\n");
}

// Store all found game data
$foundGames = [];

// Get all elements that might contain game listings
echo "Looking for game listings in all list items...\n";
$crawler->filter('li, div, a')->each(function ($node) use (&$foundGames) {
    // Get the node text and check for team pattern
    $nodeText = $node->text();
    
    // Check if this element contains a team pattern: "Team1 @ Team2"
    // Use regex that can handle cases with or without "NBA Streams" prefix
    if (preg_match('/((?:NBA\s+Streams\s+)?[A-Za-z0-9\s]+) @ ([A-Za-z0-9\s]+)/', $nodeText, $teamMatches)) {
        $awayTeam = normalizeTeamName(preg_replace('/\s+\d{4}$/', '', trim($teamMatches[1])));
        $homeTeam = normalizeTeamName(preg_replace('/\s+\d{4}$/', '', trim($teamMatches[2])));
        
        echo "Found team pattern: $awayTeam @ $homeTeam\n";
        
        // Get full HTML to extract all relevant data
        $nodeHtml = $node->html();
        
        // Game URL from this node or its children
        $href = '';
        if ($node->nodeName() === 'a') {
            $href = $node->attr('href');
        } else if ($node->filter('a')->count() > 0) {
            $href = $node->filter('a')->attr('href');
        }
        
        // Ensure this is an event link
        if (strpos($href, '/event/') !== false) {
            // Now look for time information in spans
            $gameTime = null;
            $spans = $node->filter('span');
            
            if ($spans->count() > 0) {
                foreach ($spans as $span) {
                    $spanCrawler = new Crawler($span);
                    $spanText = trim($spanCrawler->text());
                    
                    // Check if the span contains a UTC timestamp
                    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $spanText)) {
                        echo "Found time span: $spanText\n";
                        $gameTime = convertUtcToLocalTime($spanText);
                        echo "Converted to local time: $gameTime\n";
                        break;
                    }
                }
            }
            
            // Add the game data - use full URL to ensure uniqueness
            $fullUrl = 'https://thetvapp.to' . $href;
            $foundGames[$fullUrl] = [
                'away_team' => $awayTeam,
                'home_team' => $homeTeam,
                'url' => $fullUrl,
                'time' => $gameTime
            ];
            
            echo "Added game: $awayTeam @ $homeTeam" . ($gameTime ? " at $gameTime" : " (no time found)") . "\n";
        }
    }
});

// Prepare insert statement with game_time
$insert = $pdo->prepare("
    INSERT INTO game_stream_urls (game_date, game_time, home_team, away_team, stream_url) 
    VALUES (?, ?, ?, ?, ?)
");

// Insert games with times
$gameCount = 0;
$errorCount = 0;

foreach ($foundGames as $game) {
    try {
        $insert->execute([
            $today,
            $game['time'],
            $game['home_team'],
            $game['away_team'],
            $game['url']
        ]);
        $gameCount++;
        echo "Added game to database: {$game['away_team']} @ {$game['home_team']}\n";
        echo "Time: " . ($game['time'] ? $game['time'] : 'Not available') . "\n";
        echo "URL: {$game['url']}\n\n";
    } catch (PDOException $e) {
        $errorCount++;
        echo "Error inserting game URL: " . $e->getMessage() . "\n";
        error_log("Error inserting game URL: " . $e->getMessage());
    }
}

// Log the results
$update_time = date('Y-m-d H:i:s');
$details = "Added $gameCount games, $errorCount errors for $today";

try {
    $log_stmt = $pdo->prepare("INSERT INTO update_log (update_time, script_name, details) VALUES (?, ?, ?)");
    $log_stmt->execute([$update_time, 'scrape_urls.php', $details]);
} catch (Exception $e) {
    error_log("Could not log to update_log: " . $e->getMessage());
}

// Print summary
echo "\nFinished! Added $gameCount games for $today\n";
echo "Errors: $errorCount\n";
echo "Last updated: $update_time\n";

// Verify the database entries
if ($gameCount > 0) {
    echo "\nVerifying database entries:\n";
    $stmt = $pdo->prepare("SELECT * FROM game_stream_urls WHERE game_date = ? ORDER BY game_time");
    $stmt->execute([$today]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($results as $result) {
        echo "ID: {$result['id']} | ";
        echo "Time: " . ($result['game_time'] ? $result['game_time'] : 'N/A') . " | ";
        echo "Teams: {$result['away_team']} @ {$result['home_team']}\n";
    }
}
?>