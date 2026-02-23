<?php
header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['current_league_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '/data/www/default/nba-wins-platform/config/db_connection.php';

$user_id = $_SESSION['user_id'];
$league_id = $_SESSION['current_league_id'];

// Get parameters
$team_name = str_replace('+', ' ', $_GET['team'] ?? '');
$player_name = $_GET['player'] ?? '';
$espn_id = $_GET['espn_id'] ?? '';

if (!$team_name || !$player_name) {
    die("Missing team or player parameter");
}

// =====================================================================
// ESPN FUNCTIONS
// =====================================================================

function getEspnTeamId($teamName) {
    $espnMap = [
        'Atlanta Hawks' => 1, 'Boston Celtics' => 2, 'Brooklyn Nets' => 17,
        'Charlotte Hornets' => 30, 'Chicago Bulls' => 4, 'Cleveland Cavaliers' => 5,
        'Dallas Mavericks' => 6, 'Denver Nuggets' => 7, 'Detroit Pistons' => 8,
        'Golden State Warriors' => 9, 'Houston Rockets' => 10, 'Indiana Pacers' => 11,
        'Los Angeles Clippers' => 12, 'LA Clippers' => 12, 'Los Angeles Lakers' => 13,
        'Memphis Grizzlies' => 29, 'Miami Heat' => 14, 'Milwaukee Bucks' => 15,
        'Minnesota Timberwolves' => 16, 'New Orleans Pelicans' => 3, 'New York Knicks' => 18,
        'Oklahoma City Thunder' => 25, 'Orlando Magic' => 19, 'Philadelphia 76ers' => 20,
        'Phoenix Suns' => 21, 'Portland Trail Blazers' => 22, 'Sacramento Kings' => 23,
        'San Antonio Spurs' => 24, 'Toronto Raptors' => 28, 'Utah Jazz' => 26,
        'Washington Wizards' => 27
    ];
    return $espnMap[$teamName] ?? null;
}

function espnCurlFetch($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) return null;
    return json_decode($response, true);
}

// Normalize player names for matching (strips diacritics: ć→c, ñ→n, etc.)
function normalizeForMatch($name) {
    if (function_exists('transliterator_transliterate')) {
        $name = transliterator_transliterate('Any-Latin; Latin-ASCII', $name);
    } elseif (function_exists('iconv')) {
        $name = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    }
    return strtolower(trim(preg_replace('/[^a-z ]/', '', strtolower($name))));
}

// Fetch player bio from ESPN roster (uses team cache)
function fetchPlayerBio($teamName, $playerName, $espnId) {
    $teamEspnId = getEspnTeamId($teamName);
    if (!$teamEspnId) return null;
    
    // Check roster cache first
    $cacheDir = '/tmp/espn_cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $cacheFile = $cacheDir . '/roster_' . $teamEspnId . '.json';
    
    $roster = null;
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $roster = json_decode(file_get_contents($cacheFile), true);
    }
    
    // Fetch fresh if no cache
    if (!$roster) {
        $url = "https://site.api.espn.com/apis/site/v2/sports/basketball/nba/teams/{$teamEspnId}/roster";
        $data = espnCurlFetch($url);
        if (!$data) return null;
        
        $roster = [];
        foreach ($data['athletes'] ?? [] as $p) {
            if (!isset($p['displayName']) && !isset($p['fullName'])) continue;
            
            $pos = '';
            if (isset($p['position']) && is_array($p['position'])) {
                $pos = $p['position']['abbreviation'] ?? '';
            }
            
            $exp = 'R';
            if (isset($p['experience'])) {
                if (is_array($p['experience'])) $exp = $p['experience']['years'] ?? 'R';
                elseif (is_numeric($p['experience'])) $exp = $p['experience'] > 0 ? $p['experience'] : 'R';
            }
            
            $headshot = '';
            if (isset($p['headshot']['href'])) $headshot = $p['headshot']['href'];
            elseif (isset($p['headshot']) && is_string($p['headshot'])) $headshot = $p['headshot'];
            
            $college = '';
            if (isset($p['college']['name'])) $college = $p['college']['name'];
            
            $birthPlace = '';
            if (isset($p['birthPlace'])) {
                $bp = $p['birthPlace'];
                $parts = array_filter([$bp['city'] ?? '', $bp['state'] ?? '', $bp['country'] ?? '']);
                $birthPlace = implode(', ', $parts);
            }
            
            $roster[] = [
                'espn_id' => $p['id'] ?? '',
                'name' => $p['displayName'] ?? $p['fullName'] ?? 'Unknown',
                'jersey' => $p['jersey'] ?? '',
                'position' => $pos,
                'age' => $p['age'] ?? '',
                'height' => $p['displayHeight'] ?? '',
                'weight' => $p['displayWeight'] ?? '',
                'experience' => $exp,
                'headshot' => $headshot,
                'college' => $college,
                'birthPlace' => $birthPlace,
                'dateOfBirth' => $p['dateOfBirth'] ?? '',
                'debutYear' => $p['debutYear'] ?? '',
            ];
        }
        
        if (!empty($roster)) {
            @file_put_contents($cacheFile, json_encode($roster));
        }
    }
    
    // Find player by ESPN ID or name
    foreach ($roster as $p) {
        if ($espnId && $p['espn_id'] == $espnId) return $p;
    }
    foreach ($roster as $p) {
        if (strtolower(trim($p['name'])) === strtolower(trim($playerName))) return $p;
    }
    // Fuzzy match
    $normTarget = normalizeForMatch($playerName);
    foreach ($roster as $p) {
        if (normalizeForMatch($p['name']) === $normTarget) return $p;
    }
    
    return null;
}

// Fetch individual player stats from ESPN Core API
function fetchPlayerStats($espnId) {
    if (!$espnId) return null;
    
    // Cache individual player stats for 1 hour
    $cacheDir = '/tmp/espn_cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $cacheFile = $cacheDir . '/player_stats_' . $espnId . '.json';
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached) return $cached;
    }
    
    $url = "https://sports.core.api.espn.com/v2/sports/basketball/leagues/nba/seasons/2026/types/2/athletes/{$espnId}/statistics";
    $data = espnCurlFetch($url);
    if (!$data) return null;
    
    // Parse splits -> categories -> stats
    $stats = [];
    $categories = $data['splits']['categories'] ?? [];
    
    foreach ($categories as $cat) {
        foreach ($cat['stats'] ?? [] as $s) {
            $name = $s['name'] ?? '';
            if ($name) {
                $stats[$name] = [
                    'value' => $s['value'] ?? 0,
                    'display' => $s['displayValue'] ?? '0',
                    'label' => $s['shortDisplayName'] ?? $s['displayName'] ?? $name,
                    'rank' => $s['rank'] ?? null,
                    'rankDisplay' => $s['rankDisplayValue'] ?? null,
                    'perGame' => $s['perGameValue'] ?? null,
                    'perGameDisplay' => $s['perGameDisplayValue'] ?? null,
                ];
            }
        }
    }
    
    if (!empty($stats)) {
        @file_put_contents($cacheFile, json_encode($stats));
    }
    
    return $stats;
}

// Fetch DB stats as fallback (handles diacritics)
function fetchDbStats($pdo, $teamName, $playerName) {
    try {
        $teamVariations = [$teamName];
        if (strpos($teamName, 'Clippers') !== false) {
            $teamVariations = ['LA Clippers', 'Los Angeles Clippers'];
        }
        
        // Try exact match first
        $placeholders = implode(',', array_fill(0, count($teamVariations), '?'));
        $stmt = $pdo->prepare("
            SELECT player_name, games_played, avg_minutes, avg_points, avg_rebounds, 
                   avg_assists, avg_fg_made, avg_fg_attempts, fg_percentage
            FROM team_roster_stats
            WHERE current_team_name IN ($placeholders) AND player_name = ?
        ");
        $params = array_merge($teamVariations, [$playerName]);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) return $result;
        
        // Try normalized diacritic match
        $stmt = $pdo->prepare("
            SELECT player_name, games_played, avg_minutes, avg_points, avg_rebounds, 
                   avg_assists, avg_fg_made, avg_fg_attempts, fg_percentage
            FROM team_roster_stats
            WHERE current_team_name IN ($placeholders)
        ");
        $stmt->execute($teamVariations);
        $allPlayers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $normTarget = normalizeForMatch($playerName);
        foreach ($allPlayers as $row) {
            if (normalizeForMatch($row['player_name']) === $normTarget) {
                return $row;
            }
        }
        
        // Try any team (trade handling)
        $stmt = $pdo->prepare("
            SELECT player_name, games_played, avg_minutes, avg_points, avg_rebounds, 
                   avg_assists, avg_fg_made, avg_fg_attempts, fg_percentage
            FROM team_roster_stats WHERE player_name = ? LIMIT 1
        ");
        $stmt->execute([$playerName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) return $result;
        
        // Last resort: Calculate from game_player_stats (date filtered >= Oct 20)
        $stmt = $pdo->prepare("
            SELECT player_name,
                   COUNT(*) as games_played,
                   ROUND(AVG(minutes), 1) as avg_minutes,
                   ROUND(AVG(points), 1) as avg_points,
                   ROUND(AVG(rebounds), 1) as avg_rebounds,
                   ROUND(AVG(assists), 1) as avg_assists,
                   ROUND(AVG(fg_made), 1) as avg_fg_made,
                   ROUND(AVG(fg_attempts), 1) as avg_fg_attempts,
                   CASE WHEN SUM(fg_attempts) > 0 
                       THEN ROUND(SUM(fg_made)/SUM(fg_attempts)*100, 1) 
                       ELSE 0 END as fg_percentage
            FROM game_player_stats
            WHERE team_name IN ($placeholders) AND game_date >= '2025-10-20'
            GROUP BY player_name
        ");
        $stmt->execute($teamVariations);
        $allGps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allGps as $row) {
            if (normalizeForMatch($row['player_name']) === $normTarget) {
                return $row;
            }
        }
        
        return null;
    } catch (Exception $e) {
        return null;
    }
}

// Team logo mapping
function getTeamLogo($teamName) {
    $logoMap = [
        'Atlanta Hawks' => 'atlanta_hawks.png', 'Boston Celtics' => 'boston_celtics.png',
        'Brooklyn Nets' => 'brooklyn_nets.png', 'Charlotte Hornets' => 'charlotte_hornets.png',
        'Chicago Bulls' => 'chicago_bulls.png', 'Cleveland Cavaliers' => 'cleveland_cavaliers.png',
        'Dallas Mavericks' => 'dallas_mavericks.png', 'Denver Nuggets' => 'denver_nuggets.png',
        'Detroit Pistons' => 'detroit_pistons.png', 'Golden State Warriors' => 'golden_state_warriors.png',
        'Houston Rockets' => 'houston_rockets.png', 'Indiana Pacers' => 'indiana_pacers.png',
        'Los Angeles Clippers' => 'la_clippers.png', 'Los Angeles Lakers' => 'los_angeles_lakers.png',
        'Memphis Grizzlies' => 'memphis_grizzlies.png', 'Miami Heat' => 'miami_heat.png',
        'Milwaukee Bucks' => 'milwaukee_bucks.png', 'Minnesota Timberwolves' => 'minnesota_timberwolves.png',
        'New Orleans Pelicans' => 'new_orleans_pelicans.png', 'New York Knicks' => 'new_york_knicks.png',
        'Oklahoma City Thunder' => 'oklahoma_city_thunder.png', 'Orlando Magic' => 'orlando_magic.png',
        'Philadelphia 76ers' => 'philadelphia_76ers.png', 'Phoenix Suns' => 'phoenix_suns.png',
        'Portland Trail Blazers' => 'portland_trail_blazers.png', 'Sacramento Kings' => 'sacramento_kings.png',
        'San Antonio Spurs' => 'san_antonio_spurs.png', 'Toronto Raptors' => 'toronto_raptors.png',
        'Utah Jazz' => 'utah_jazz.png', 'Washington Wizards' => 'washington_wizards.png'
    ];
    if (isset($logoMap[$teamName])) {
        return '/nba-wins-platform/public/assets/team_logos/' . $logoMap[$teamName];
    }
    return '/nba-wins-platform/public/assets/team_logos/' . strtolower(str_replace(' ', '_', $teamName)) . '.png';
}

// =====================================================================
// FETCH DATA
// =====================================================================

$playerBio = fetchPlayerBio($team_name, $player_name, $espn_id);

// If no espn_id from URL, get it from the bio lookup
if (empty($espn_id) && $playerBio && !empty($playerBio['espn_id'])) {
    $espn_id = $playerBio['espn_id'];
}

$espnStats = $espn_id ? fetchPlayerStats($espn_id) : null;
$dbStats = fetchDbStats($pdo, $team_name, $player_name);

// Helper to get ESPN stat value
function getStat($espnStats, $name, $default = null) {
    return $espnStats[$name]['value'] ?? $default;
}
function getStatDisplay($espnStats, $name, $default = '-') {
    return $espnStats[$name]['display'] ?? $default;
}
function getStatRank($espnStats, $name) {
    return $espnStats[$name]['rankDisplay'] ?? null;
}
function getStatPerGame($espnStats, $name, $default = '-') {
    return $espnStats[$name]['perGameDisplay'] ?? $default;
}

// Determine stats source
$hasEspnStats = !empty($espnStats);
$hasDbStats = !empty($dbStats);

$teamLogo = getTeamLogo($team_name);
$rosterUrl = '/nba-wins-platform/stats/team_data.php?team=' . urlencode($team_name) . '&tab=roster';

// Detect where user came from for back button
$referrer = $_SERVER['HTTP_REFERER'] ?? '';
$backUrl = $rosterUrl;
$backLabel = $team_name . ' Roster';
if (strpos($referrer, 'game_details.php') !== false) {
    $backUrl = $referrer;
    $backLabel = 'Box Score';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($player_name); ?> - <?php echo htmlspecialchars($team_name); ?></title>
    <link rel="apple-touch-icon" type="image/png" href="/nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="/nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
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
        --success-color: #4CAF50;
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        line-height: 1.6;
        margin: 0;
        padding: 20px;
        background-image: url('/nba-wins-platform/public/assets/background/geometric_white.png');
        background-repeat: repeat;
        background-attachment: fixed;
        background-color: #f5f5f5;
    }
    
    .container {
        max-width: 900px;
        margin: 0 auto;
        background-color: white;
        padding: 24px;
        padding-top: 50px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #666;
        text-decoration: none;
        font-size: 0.9rem;
        margin-bottom: 20px;
        padding: 6px 12px;
        border-radius: 6px;
        transition: all 0.2s;
    }
    .back-link:hover {
        background-color: #f0f0f0;
        color: var(--primary-color);
    }
    
    /* Player Hero Card */
    .player-hero {
        display: flex;
        gap: 24px;
        align-items: flex-start;
        padding: 24px;
        background: linear-gradient(135deg, #fafafa 0%, #f0f0f0 100%);
        border-radius: 12px;
        border: 1px solid var(--border-color);
        margin-bottom: 24px;
    }
    
    .player-photo-wrap {
        flex-shrink: 0;
    }
    
    .player-photo {
        width: 140px;
        height: 140px;
        border-radius: 50%;
        object-fit: cover;
        background: #e8e8e8;
        border: 4px solid white;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    
    .player-photo-fallback {
        width: 140px;
        height: 140px;
        border-radius: 50%;
        background: #e0e0e0;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #aaa;
        font-size: 3rem;
        border: 4px solid white;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    
    .player-details {
        flex: 1;
        min-width: 0;
    }
    
    .player-team-row {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
    }
    
    .player-team-row img {
        width: 24px;
        height: 24px;
        object-fit: contain;
    }
    
    .player-team-name {
        font-size: 0.85rem;
        color: #888;
        font-weight: 500;
    }
    
    .player-full-name {
        font-size: 2rem;
        font-weight: 700;
        color: var(--primary-color);
        margin: 0 0 4px 0;
        line-height: 1.2;
    }
    
    .player-jersey-pos {
        font-size: 1.1rem;
        color: #666;
        margin-bottom: 16px;
    }
    
    .pos-badge {
        display: inline-block;
        background-color: var(--primary-color);
        color: white;
        padding: 2px 10px;
        border-radius: 4px;
        font-size: 0.8rem;
        font-weight: 600;
        letter-spacing: 0.03em;
        margin-left: 8px;
    }
    
    .bio-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
        gap: 12px;
    }
    
    .bio-item {
        display: flex;
        flex-direction: column;
    }
    
    .bio-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #999;
        font-weight: 600;
    }
    
    .bio-value {
        font-size: 0.95rem;
        font-weight: 600;
        color: var(--text-color);
    }
    
    /* Stats Sections */
    .section-title {
        font-size: 1.3rem;
        font-weight: 700;
        color: var(--primary-color);
        margin: 0 0 16px 0;
        padding-bottom: 8px;
        border-bottom: 2px solid #eee;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .stats-source {
        font-size: 0.75rem;
        color: #aaa;
        font-weight: normal;
        margin-left: auto;
    }
    
    /* Primary Stats Row */
    .primary-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
        gap: 12px;
        margin-bottom: 24px;
    }
    
    .primary-stat {
        text-align: center;
        padding: 16px 8px;
        background: #f8f9fa;
        border-radius: 10px;
        border: 1px solid #eee;
    }
    
    .primary-stat.highlight {
        background: linear-gradient(135deg, #f0f4ff 0%, #e8ecff 100%);
        border-color: #c5cae9;
    }
    
    .primary-stat-value {
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--primary-color);
        line-height: 1;
    }
    
    .primary-stat-label {
        font-size: 0.75rem;
        color: #888;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-top: 6px;
        font-weight: 600;
    }
    
    .primary-stat-rank {
        font-size: 0.7rem;
        color: #aaa;
        margin-top: 4px;
    }
    
    /* Detailed Stats Grid */
    .detail-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 10px;
        margin-bottom: 24px;
    }
    
    .detail-stat {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 14px;
        background: #fafafa;
        border-radius: 6px;
        border-left: 3px solid var(--border-color);
    }
    
    .detail-stat-name {
        font-size: 0.85rem;
        color: #666;
    }
    
    .detail-stat-value {
        font-size: 1rem;
        font-weight: 700;
        color: var(--primary-color);
        font-variant-numeric: tabular-nums;
    }
    
    /* No Data */
    .no-data {
        text-align: center;
        padding: 40px 20px;
        color: #999;
        background: #f8f9fa;
        border-radius: 8px;
    }
    
    /* Menu styles */
    .menu-container { position: fixed; top: 0; left: 0; z-index: 1000; }
    .menu-button { position: fixed; top: 1rem; left: 1rem; background-color: var(--primary-color); color: white; border: none; border-radius: 4px; padding: 0.5rem; cursor: pointer; z-index: 1002; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .menu-button:hover { background-color: var(--secondary-color); }
    .menu-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0,0,0,0.5); z-index: 1001; }
    .menu-panel { position: fixed; top: 0; left: -300px; width: 300px; height: 100vh; background-color: white; box-shadow: 2px 0 5px rgba(0,0,0,0.1); transition: left 0.3s ease; z-index: 1002; }
    .menu-panel.menu-open { left: 0; }
    .menu-header { padding: 1rem; display: flex; justify-content: flex-end; border-bottom: 1px solid var(--border-color); }
    .close-button { background: none; border: none; color: var(--text-color); cursor: pointer; padding: 0.5rem; }
    .close-button:hover { color: var(--hover-color); }
    .menu-content { padding: 1rem; }
    .menu-list { list-style: none; padding: 0; margin: 0; }
    .menu-link { display: flex; align-items: center; gap: 0.5rem; padding: 1rem; color: var(--text-color); text-decoration: none; transition: background-color 0.2s; border-radius: 4px; }
    .menu-link:hover { background-color: var(--background-color); color: var(--secondary-color); }
    .menu-link i { width: 20px; }
    
    @media (max-width: 768px) {
        body { padding: 10px; }
        .container { padding: 14px; padding-top: 50px; }
        
        .back-link { font-size: 0.8rem; margin-bottom: 14px; }
        
        .player-hero {
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 18px 14px;
            gap: 12px;
        }
        
        .player-photo, .player-photo-fallback {
            width: 100px;
            height: 100px;
        }
        
        .player-photo-fallback { font-size: 2.5rem; }
        
        .player-details { width: 100%; }
        
        .player-team-row {
            justify-content: center;
            margin-bottom: 6px;
        }
        
        .player-full-name { font-size: 1.4rem; }
        .player-jersey-pos { font-size: 1rem; margin-bottom: 12px; }
        
        .bio-grid {
            grid-template-columns: repeat(3, 1fr);
            text-align: center;
            gap: 10px;
        }
        
        .bio-label { font-size: 0.65rem; }
        .bio-value { font-size: 0.85rem; }
        
        .section-title { font-size: 1.1rem; margin: 16px 0 12px; }
        
        .primary-stats {
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }
        
        .primary-stat { padding: 12px 6px; }
        .primary-stat-value { font-size: 1.3rem; }
        .primary-stat-label { font-size: 0.65rem; }
        .primary-stat-rank { font-size: 0.6rem; }
        
        .detail-stats-grid {
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        
        .detail-stat { padding: 8px 10px; }
        .detail-stat-name { font-size: 0.78rem; }
        .detail-stat-value { font-size: 0.9rem; }
        
        h3 { font-size: 1rem !important; margin: 16px 0 10px !important; }
    }
    
    @media (max-width: 400px) {
        .bio-grid { grid-template-columns: repeat(2, 1fr); }
        .primary-stats { grid-template-columns: repeat(3, 1fr); }
        .primary-stat-value { font-size: 1.15rem; }
        .detail-stats-grid { grid-template-columns: 1fr; }
    }
</style>
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/components/navigation_menu.php'; ?>
    
    <div class="container">
        <a href="<?php echo htmlspecialchars($backUrl); ?>" class="back-link">
            <i class="fas fa-arrow-left"></i>
            <img src="<?php echo htmlspecialchars($teamLogo); ?>" alt="" style="width:20px;height:20px;object-fit:contain" onerror="this.style.display='none'">
            <?php echo htmlspecialchars($backLabel); ?>
        </a>
        
        <!-- Player Hero Card -->
        <div class="player-hero">
            <div class="player-photo-wrap">
                <?php if ($playerBio && !empty($playerBio['headshot'])): ?>
                    <img src="<?php echo htmlspecialchars($playerBio['headshot']); ?>" 
                         alt="<?php echo htmlspecialchars($player_name); ?>" 
                         class="player-photo"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div class="player-photo-fallback" style="display:none"><i class="fas fa-user"></i></div>
                <?php else: ?>
                    <div class="player-photo-fallback"><i class="fas fa-user"></i></div>
                <?php endif; ?>
            </div>
            
            <div class="player-details">
                <div class="player-team-row">
                    <img src="<?php echo htmlspecialchars($teamLogo); ?>" alt="" onerror="this.style.display='none'">
                    <span class="player-team-name"><?php echo htmlspecialchars($team_name); ?></span>
                </div>
                
                <h1 class="player-full-name"><?php echo htmlspecialchars($player_name); ?></h1>
                
                <?php if ($playerBio): ?>
                <div class="player-jersey-pos">
                    <?php if (!empty($playerBio['jersey'])): ?>
                        #<?php echo htmlspecialchars($playerBio['jersey']); ?>
                    <?php endif; ?>
                    <?php if (!empty($playerBio['position'])): ?>
                        <span class="pos-badge"><?php echo htmlspecialchars($playerBio['position']); ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="bio-grid">
                    <?php if (!empty($playerBio['height'])): ?>
                    <div class="bio-item">
                        <span class="bio-label">Height</span>
                        <span class="bio-value"><?php echo htmlspecialchars($playerBio['height']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($playerBio['weight'])): ?>
                    <div class="bio-item">
                        <span class="bio-label">Weight</span>
                        <span class="bio-value"><?php echo htmlspecialchars($playerBio['weight']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($playerBio['age'])): ?>
                    <div class="bio-item">
                        <span class="bio-label">Age</span>
                        <span class="bio-value"><?php echo $playerBio['age']; ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="bio-item">
                        <span class="bio-label">Experience</span>
                        <span class="bio-value">
                            <?php 
                            $exp = $playerBio['experience'];
                            echo ($exp === 0 || $exp === 'R') ? 'Rookie' : $exp . ' year' . ($exp > 1 ? 's' : '');
                            ?>
                        </span>
                    </div>
                    
                    <?php if (!empty($playerBio['college'])): ?>
                    <div class="bio-item">
                        <span class="bio-label">College</span>
                        <span class="bio-value"><?php echo htmlspecialchars($playerBio['college']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($playerBio['birthPlace'])): ?>
                    <div class="bio-item">
                        <span class="bio-label">Birthplace</span>
                        <span class="bio-value"><?php echo htmlspecialchars($playerBio['birthPlace']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Season Stats -->
        <?php if ($hasEspnStats): ?>
        
        <h2 class="section-title">
            <i class="fas fa-chart-bar"></i>
            2025-26 Season Stats
            <span class="stats-source">via ESPN</span>
        </h2>
        
        <!-- Primary Stats -->
        <div class="primary-stats">
            <div class="primary-stat">
                <div class="primary-stat-value"><?php echo getStatDisplay($espnStats, 'gamesPlayed', '-'); ?></div>
                <div class="primary-stat-label">GP</div>
            </div>
            <div class="primary-stat">
                <div class="primary-stat-value"><?php echo getStatDisplay($espnStats, 'avgMinutes', '-'); ?></div>
                <div class="primary-stat-label">MPG</div>
            </div>
            <div class="primary-stat highlight">
                <div class="primary-stat-value"><?php echo getStatDisplay($espnStats, 'avgPoints', '-'); ?></div>
                <div class="primary-stat-label">PPG</div>
                <?php $rank = getStatRank($espnStats, 'avgPoints'); if ($rank): ?>
                    <div class="primary-stat-rank"><?php echo $rank; ?></div>
                <?php endif; ?>
            </div>
            <div class="primary-stat">
                <div class="primary-stat-value"><?php echo getStatDisplay($espnStats, 'avgRebounds', '-'); ?></div>
                <div class="primary-stat-label">RPG</div>
                <?php $rank = getStatRank($espnStats, 'avgRebounds'); if ($rank): ?>
                    <div class="primary-stat-rank"><?php echo $rank; ?></div>
                <?php endif; ?>
            </div>
            <div class="primary-stat">
                <div class="primary-stat-value"><?php echo getStatDisplay($espnStats, 'avgAssists', '-'); ?></div>
                <div class="primary-stat-label">APG</div>
                <?php $rank = getStatRank($espnStats, 'avgAssists'); if ($rank): ?>
                    <div class="primary-stat-rank"><?php echo $rank; ?></div>
                <?php endif; ?>
            </div>
            <div class="primary-stat">
                <div class="primary-stat-value"><?php echo getStatDisplay($espnStats, 'avgSteals', '-'); ?></div>
                <div class="primary-stat-label">SPG</div>
            </div>
            <div class="primary-stat">
                <div class="primary-stat-value"><?php echo getStatDisplay($espnStats, 'avgBlocks', '-'); ?></div>
                <div class="primary-stat-label">BPG</div>
            </div>
        </div>
        
        <!-- Shooting Stats -->
        <h3 style="font-size: 1.1rem; font-weight: 600; color: var(--primary-color); margin: 20px 0 12px;">
            <i class="fas fa-bullseye" style="color:#888;"></i> Shooting
        </h3>
        <div class="detail-stats-grid">
            <div class="detail-stat">
                <span class="detail-stat-name">FG%</span>
                <span class="detail-stat-value"><?php echo getStatDisplay($espnStats, 'fieldGoalPct', '-'); ?>%</span>
            </div>
            <div class="detail-stat">
                <span class="detail-stat-name">FGM / FGA</span>
                <span class="detail-stat-value"><?php echo getStatDisplay($espnStats, 'avgFieldGoalsMade', '-'); ?> / <?php echo getStatDisplay($espnStats, 'avgFieldGoalsAttempted', '-'); ?></span>
            </div>
            <div class="detail-stat">
                <span class="detail-stat-name">3P%</span>
                <span class="detail-stat-value"><?php echo getStatDisplay($espnStats, 'threePointFieldGoalPct', '-'); ?>%</span>
            </div>
            <div class="detail-stat">
                <span class="detail-stat-name">3PM / 3PA</span>
                <span class="detail-stat-value"><?php echo getStatDisplay($espnStats, 'avgThreePointFieldGoalsMade', '-'); ?> / <?php echo getStatDisplay($espnStats, 'avgThreePointFieldGoalsAttempted', '-'); ?></span>
            </div>
            <div class="detail-stat">
                <span class="detail-stat-name">FT%</span>
                <span class="detail-stat-value"><?php echo getStatDisplay($espnStats, 'freeThrowPct', '-'); ?>%</span>
            </div>
            <div class="detail-stat">
                <span class="detail-stat-name">FTM / FTA</span>
                <span class="detail-stat-value"><?php echo getStatDisplay($espnStats, 'avgFreeThrowsMade', '-'); ?> / <?php echo getStatDisplay($espnStats, 'avgFreeThrowsAttempted', '-'); ?></span>
            </div>
        </div>
        
        <!-- Additional Stats -->
        <h3 style="font-size: 1.1rem; font-weight: 600; color: var(--primary-color); margin: 20px 0 12px;">
            <i class="fas fa-chart-line" style="color:#888;"></i> Additional
        </h3>
        <div class="detail-stats-grid">
            <div class="detail-stat">
                <span class="detail-stat-name">Turnovers</span>
                <span class="detail-stat-value"><?php echo getStatDisplay($espnStats, 'avgTurnovers', '-'); ?></span>
            </div>
            <div class="detail-stat">
                <span class="detail-stat-name">Fouls</span>
                <span class="detail-stat-value"><?php echo getStatDisplay($espnStats, 'avgFouls', '-'); ?></span>
            </div>
            <div class="detail-stat">
                <span class="detail-stat-name">Double-Doubles</span>
                <span class="detail-stat-value"><?php echo getStatDisplay($espnStats, 'doubleDouble', '-'); ?></span>
            </div>
            <div class="detail-stat">
                <span class="detail-stat-name">Triple-Doubles</span>
                <span class="detail-stat-value"><?php echo getStatDisplay($espnStats, 'tripleDouble', '-'); ?></span>
            </div>
        </div>
        
        <?php elseif ($hasDbStats): ?>
        
        <!-- DB Stats Fallback -->
        <h2 class="section-title">
            <i class="fas fa-chart-bar"></i>
            2025-26 Season Stats
        </h2>
        
        <div class="primary-stats">
            <div class="primary-stat">
                <div class="primary-stat-value"><?php echo $dbStats['games_played']; ?></div>
                <div class="primary-stat-label">GP</div>
            </div>
            <div class="primary-stat">
                <div class="primary-stat-value"><?php echo number_format($dbStats['avg_minutes'], 1); ?></div>
                <div class="primary-stat-label">MPG</div>
            </div>
            <div class="primary-stat highlight">
                <div class="primary-stat-value"><?php echo number_format($dbStats['avg_points'], 1); ?></div>
                <div class="primary-stat-label">PPG</div>
            </div>
            <div class="primary-stat">
                <div class="primary-stat-value"><?php echo number_format($dbStats['avg_rebounds'], 1); ?></div>
                <div class="primary-stat-label">RPG</div>
            </div>
            <div class="primary-stat">
                <div class="primary-stat-value"><?php echo number_format($dbStats['avg_assists'], 1); ?></div>
                <div class="primary-stat-label">APG</div>
            </div>
            <div class="primary-stat">
                <div class="primary-stat-value"><?php echo number_format($dbStats['fg_percentage'], 1); ?>%</div>
                <div class="primary-stat-label">FG%</div>
            </div>
        </div>
        
        <?php else: ?>
        
        <div class="no-data">
            <i class="fas fa-chart-bar" style="font-size: 2rem; margin-bottom: 12px; display: block;"></i>
            <p style="font-size: 1.1rem; margin: 0;">No season stats available for this player</p>
            <p style="font-size: 0.85rem; color: #aaa; margin-top: 8px;">Stats will appear once games have been played</p>
        </div>
        
        <?php endif; ?>
    </div>
</body>
</html>