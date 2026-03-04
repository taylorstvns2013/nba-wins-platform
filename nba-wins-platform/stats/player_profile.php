<?php
/**
 * player_profile.php - Individual Player Profile
 * 
 * Displays a player's profile page including:
 *   - Player hero card with headshot, bio info from ESPN
 *   - Season stats from ESPN API (primary) or DB fallback
 *   - Shooting splits, additional stats (doubles, turnovers, fouls)
 * 
 * Path: /data/www/default/nba-wins-platform/stats/player_profile.php
 */

header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

// =====================================================================
// SESSION & AUTH CHECK
// =====================================================================
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['current_league_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

// =====================================================================
// DEPENDENCIES
// =====================================================================
require_once '/data/www/default/nba-wins-platform/config/db_connection.php';
require_once '/data/www/default/nba-wins-platform/config/season_config.php';
$season = getSeasonConfig();

// =====================================================================
// REQUEST PARAMETERS
// =====================================================================
$user_id     = $_SESSION['user_id'];
$league_id   = $_SESSION['current_league_id'];
$team_name   = str_replace('+', ' ', $_GET['team'] ?? '');
$player_name = $_GET['player'] ?? '';
$espn_id     = $_GET['espn_id'] ?? '';

if (!$team_name || !$player_name) {
    die("Missing team or player parameter");
}


// ==========================================================================
// HELPER FUNCTIONS — ESPN
// ==========================================================================

/**
 * Map team name to ESPN team ID
 */
function getEspnTeamId($teamName) {
    $espnMap = [
        'Atlanta Hawks'          => 1,
        'Boston Celtics'         => 2,
        'Brooklyn Nets'          => 17,
        'Charlotte Hornets'      => 30,
        'Chicago Bulls'          => 4,
        'Cleveland Cavaliers'    => 5,
        'Dallas Mavericks'       => 6,
        'Denver Nuggets'         => 7,
        'Detroit Pistons'        => 8,
        'Golden State Warriors'  => 9,
        'Houston Rockets'        => 10,
        'Indiana Pacers'         => 11,
        'Los Angeles Clippers'   => 12,
        'LA Clippers'            => 12,
        'Los Angeles Lakers'     => 13,
        'Memphis Grizzlies'      => 29,
        'Miami Heat'             => 14,
        'Milwaukee Bucks'        => 15,
        'Minnesota Timberwolves' => 16,
        'New Orleans Pelicans'   => 3,
        'New York Knicks'        => 18,
        'Oklahoma City Thunder'  => 25,
        'Orlando Magic'          => 19,
        'Philadelphia 76ers'     => 20,
        'Phoenix Suns'           => 21,
        'Portland Trail Blazers' => 22,
        'Sacramento Kings'       => 23,
        'San Antonio Spurs'      => 24,
        'Toronto Raptors'        => 28,
        'Utah Jazz'              => 26,
        'Washington Wizards'     => 27
    ];
    return $espnMap[$teamName] ?? null;
}

/**
 * Fetch JSON from an ESPN API URL via cURL
 */
function espnCurlFetch($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return null;

    return json_decode($response, true);
}

/**
 * Normalize player name for fuzzy matching (strips accents, lowercases, removes non-alpha)
 */
function normalizeForMatch($name) {
    if (function_exists('transliterator_transliterate')) {
        $name = transliterator_transliterate('Any-Latin; Latin-ASCII', $name);
    } elseif (function_exists('iconv')) {
        $name = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    }
    return strtolower(trim(preg_replace('/[^a-z ]/', '', strtolower($name))));
}

/**
 * Fetch player bio from ESPN roster API (with 1-hour cache)
 * Matches by ESPN ID first, then exact name, then normalized name
 */
function fetchPlayerBio($teamName, $playerName, $espnId) {
    $teamEspnId = getEspnTeamId($teamName);
    if (!$teamEspnId) return null;

    // Cache setup
    $cacheDir  = '/tmp/espn_cache';
    $cacheFile = $cacheDir . '/roster_' . $teamEspnId . '.json';

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    // Try cached roster first
    $roster = null;
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $roster = json_decode(file_get_contents($cacheFile), true);
    }

    // Fetch fresh roster if no cache
    if (!$roster) {
        $url  = "https://site.api.espn.com/apis/site/v2/sports/basketball/nba/teams/{$teamEspnId}/roster";
        $data = espnCurlFetch($url);
        if (!$data) return null;

        $roster = [];
        foreach ($data['athletes'] ?? [] as $p) {
            if (!isset($p['displayName']) && !isset($p['fullName'])) continue;

            // Position
            $pos = '';
            if (isset($p['position']) && is_array($p['position'])) {
                $pos = $p['position']['abbreviation'] ?? '';
            }

            // Experience
            $exp = 'R';
            if (isset($p['experience'])) {
                if (is_array($p['experience'])) {
                    $exp = $p['experience']['years'] ?? 'R';
                } elseif (is_numeric($p['experience'])) {
                    $exp = $p['experience'] > 0 ? $p['experience'] : 'R';
                }
            }

            // Headshot
            $headshot = '';
            if (isset($p['headshot']['href'])) {
                $headshot = $p['headshot']['href'];
            } elseif (isset($p['headshot']) && is_string($p['headshot'])) {
                $headshot = $p['headshot'];
            }

            // College
            $college = '';
            if (isset($p['college']['name'])) {
                $college = $p['college']['name'];
            }

            // Birthplace
            $birthPlace = '';
            if (isset($p['birthPlace'])) {
                $bp    = $p['birthPlace'];
                $parts = array_filter([
                    $bp['city'] ?? '',
                    $bp['state'] ?? '',
                    $bp['country'] ?? ''
                ]);
                $birthPlace = implode(', ', $parts);
            }

            $roster[] = [
                'espn_id'     => $p['id'] ?? '',
                'name'        => $p['displayName'] ?? $p['fullName'] ?? 'Unknown',
                'jersey'      => $p['jersey'] ?? '',
                'position'    => $pos,
                'age'         => $p['age'] ?? '',
                'height'      => $p['displayHeight'] ?? '',
                'weight'      => $p['displayWeight'] ?? '',
                'experience'  => $exp,
                'headshot'    => $headshot,
                'college'     => $college,
                'birthPlace'  => $birthPlace,
                'dateOfBirth' => $p['dateOfBirth'] ?? '',
                'debutYear'   => $p['debutYear'] ?? ''
            ];
        }

        if (!empty($roster)) {
            @file_put_contents($cacheFile, json_encode($roster));
        }
    }

    // Match player: ESPN ID → exact name → normalized name
    foreach ($roster as $p) {
        if ($espnId && $p['espn_id'] == $espnId) return $p;
    }
    foreach ($roster as $p) {
        if (strtolower(trim($p['name'])) === strtolower(trim($playerName))) return $p;
    }
    $normTarget = normalizeForMatch($playerName);
    foreach ($roster as $p) {
        if (normalizeForMatch($p['name']) === $normTarget) return $p;
    }

    return null;
}

/**
 * Fetch player season statistics from ESPN core API (with 1-hour cache)
 */
function fetchPlayerStats($espnId) {
    if (!$espnId) return null;

    // Cache setup
    $cacheDir  = '/tmp/espn_cache';
    $cacheFile = $cacheDir . '/player_stats_' . $espnId . '.json';

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    // Return cached stats if fresh
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached) return $cached;
    }

    // Fetch from ESPN core API
    $url  = "https://sports.core.api.espn.com/v2/sports/basketball/leagues/nba/seasons/{$GLOBALS['season']['api_season_espn']}/types/2/athletes/{$espnId}/statistics";
    $data = espnCurlFetch($url);
    if (!$data) return null;

    // Parse stats from categories
    $stats      = [];
    $categories = $data['splits']['categories'] ?? [];

    foreach ($categories as $cat) {
        foreach ($cat['stats'] ?? [] as $s) {
            $name = $s['name'] ?? '';
            if ($name) {
                $stats[$name] = [
                    'value'          => $s['value'] ?? 0,
                    'display'        => $s['displayValue'] ?? '0',
                    'label'          => $s['shortDisplayName'] ?? $s['displayName'] ?? $name,
                    'rank'           => $s['rank'] ?? null,
                    'rankDisplay'    => $s['rankDisplayValue'] ?? null,
                    'perGame'        => $s['perGameValue'] ?? null,
                    'perGameDisplay' => $s['perGameDisplayValue'] ?? null
                ];
            }
        }
    }

    if (!empty($stats)) {
        @file_put_contents($cacheFile, json_encode($stats));
    }

    return $stats;
}


// ==========================================================================
// HELPER FUNCTIONS — DATABASE FALLBACK
// ==========================================================================

/**
 * Fetch player stats from DB with multi-level fallback:
 *   1. team_roster_stats by exact team + exact name
 *   2. team_roster_stats by exact team + normalized name
 *   3. team_roster_stats by exact name (any team)
 *   4. game_player_stats aggregated by team + normalized name
 */
function fetchDbStats($pdo, $teamName, $playerName) {
    try {
        // Handle Clippers name variants
        $teamVariations = [$teamName];
        if (strpos($teamName, 'Clippers') !== false) {
            $teamVariations = ['LA Clippers', 'Los Angeles Clippers'];
        }
        $placeholders = implode(',', array_fill(0, count($teamVariations), '?'));

        $statColumns = "player_name, games_played, avg_minutes, avg_points, avg_rebounds,
                        avg_assists, avg_fg_made, avg_fg_attempts, fg_percentage";

        // 1. Exact team + exact name
        $stmt = $pdo->prepare("
            SELECT $statColumns
            FROM team_roster_stats
            WHERE current_team_name IN ($placeholders) AND player_name = ?
        ");
        $stmt->execute(array_merge($teamVariations, [$playerName]));
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) return $result;

        // 2. Exact team + normalized name match
        $stmt = $pdo->prepare("
            SELECT $statColumns
            FROM team_roster_stats
            WHERE current_team_name IN ($placeholders)
        ");
        $stmt->execute($teamVariations);
        $allPlayers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $normTarget = normalizeForMatch($playerName);

        foreach ($allPlayers as $row) {
            if (normalizeForMatch($row['player_name']) === $normTarget) return $row;
        }

        // 3. Any team, exact name
        $stmt = $pdo->prepare("
            SELECT $statColumns
            FROM team_roster_stats
            WHERE player_name = ?
            LIMIT 1
        ");
        $stmt->execute([$playerName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) return $result;

        // 4. Aggregate from game_player_stats
        $stmt = $pdo->prepare("
            SELECT 
                player_name,
                COUNT(*) AS games_played,
                ROUND(AVG(minutes), 1) AS avg_minutes,
                ROUND(AVG(points), 1) AS avg_points,
                ROUND(AVG(rebounds), 1) AS avg_rebounds,
                ROUND(AVG(assists), 1) AS avg_assists,
                ROUND(AVG(fg_made), 1) AS avg_fg_made,
                ROUND(AVG(fg_attempts), 1) AS avg_fg_attempts,
                CASE WHEN SUM(fg_attempts) > 0
                     THEN ROUND(SUM(fg_made) / SUM(fg_attempts) * 100, 1)
                     ELSE 0
                END AS fg_percentage
            FROM game_player_stats
            WHERE team_name IN ($placeholders) AND game_date >= '{$GLOBALS['season']['season_start_date']}'
            GROUP BY player_name
        ");
        $stmt->execute($teamVariations);
        $allGps = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allGps as $row) {
            if (normalizeForMatch($row['player_name']) === $normTarget) return $row;
        }

        return null;

    } catch (Exception $e) {
        return null;
    }
}


// ==========================================================================
// HELPER FUNCTIONS — GENERAL
// ==========================================================================

/**
 * Get team logo path from team name
 */
function getTeamLogo($teamName) {
    $logoMap = [
        'Atlanta Hawks'          => 'atlanta_hawks.png',
        'Boston Celtics'         => 'boston_celtics.png',
        'Brooklyn Nets'          => 'brooklyn_nets.png',
        'Charlotte Hornets'      => 'charlotte_hornets.png',
        'Chicago Bulls'          => 'chicago_bulls.png',
        'Cleveland Cavaliers'    => 'cleveland_cavaliers.png',
        'Dallas Mavericks'       => 'dallas_mavericks.png',
        'Denver Nuggets'         => 'denver_nuggets.png',
        'Detroit Pistons'        => 'detroit_pistons.png',
        'Golden State Warriors'  => 'golden_state_warriors.png',
        'Houston Rockets'        => 'houston_rockets.png',
        'Indiana Pacers'         => 'indiana_pacers.png',
        'Los Angeles Clippers'   => 'la_clippers.png',
        'Los Angeles Lakers'     => 'los_angeles_lakers.png',
        'Memphis Grizzlies'      => 'memphis_grizzlies.png',
        'Miami Heat'             => 'miami_heat.png',
        'Milwaukee Bucks'        => 'milwaukee_bucks.png',
        'Minnesota Timberwolves' => 'minnesota_timberwolves.png',
        'New Orleans Pelicans'   => 'new_orleans_pelicans.png',
        'New York Knicks'        => 'new_york_knicks.png',
        'Oklahoma City Thunder'  => 'oklahoma_city_thunder.png',
        'Orlando Magic'          => 'orlando_magic.png',
        'Philadelphia 76ers'     => 'philadelphia_76ers.png',
        'Phoenix Suns'           => 'phoenix_suns.png',
        'Portland Trail Blazers' => 'portland_trail_blazers.png',
        'Sacramento Kings'       => 'sacramento_kings.png',
        'San Antonio Spurs'      => 'san_antonio_spurs.png',
        'Toronto Raptors'        => 'toronto_raptors.png',
        'Utah Jazz'              => 'utah_jazz.png',
        'Washington Wizards'     => 'washington_wizards.png'
    ];

    if (isset($logoMap[$teamName])) {
        return '/nba-wins-platform/public/assets/team_logos/' . $logoMap[$teamName];
    }

    return '/nba-wins-platform/public/assets/team_logos/' . strtolower(str_replace(' ', '_', $teamName)) . '.png';
}

/**
 * ESPN stat accessor helpers
 */
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


// ==========================================================================
// FETCH DATA
// ==========================================================================

// Player bio from ESPN roster
$playerBio = fetchPlayerBio($team_name, $player_name, $espn_id);

// If we didn't have an ESPN ID but found one in the roster, use it
if (empty($espn_id) && $playerBio && !empty($playerBio['espn_id'])) {
    $espn_id = $playerBio['espn_id'];
}

// Season stats (ESPN primary, DB fallback)
$espnStats = $espn_id ? fetchPlayerStats($espn_id) : null;
$dbStats   = fetchDbStats($pdo, $team_name, $player_name);

$hasEspnStats = !empty($espnStats);
$hasDbStats   = !empty($dbStats);

// Navigation context
$teamLogo  = getTeamLogo($team_name);
$rosterUrl = '/nba-wins-platform/stats/team_data.php?team=' . urlencode($team_name) . '&tab=roster';
$referrer  = $_SERVER['HTTP_REFERER'] ?? '';
$backUrl   = $rosterUrl;
$backLabel = $team_name . ' Roster';

if (strpos($referrer, 'game_details') !== false) {
    $backUrl   = $referrer;
    $backLabel = 'Box Score';
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
    <title><?= htmlspecialchars($player_name) ?> - <?= htmlspecialchars($team_name) ?></title>
    <link rel="apple-touch-icon" type="image/png" href="/nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="/nba-wins-platform/public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
/* ==========================================================================
   CSS VARIABLES
   ========================================================================== */
:root {
    --bg-primary: #151d28;
    --bg-secondary: #1a222c;
    --bg-card: #202a38;
    --bg-card-hover: #273140;
    --bg-elevated: #2a3446;
    --border-color: rgba(255, 255, 255, 0.08);
    --text-primary: #e6edf3;
    --text-secondary: #8b949e;
    --text-muted: #545d68;
    --accent-blue: #388bfd;
    --accent-blue-dim: rgba(56, 139, 253, 0.15);
    --accent-green: #3fb950;
    --accent-red: #f85149;
    --accent-orange: #d29922;
    --accent-teal: #76a5af;
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

/* ==========================================================================
   BASE / RESET
   ========================================================================== */
* { margin: 0; padding: 0; box-sizing: border-box; }
html { background: var(--bg-primary); }
body {
    font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
    line-height: 1.5;
    color: var(--text-primary);
    background: var(--bg-primary);
    background-image: radial-gradient(ellipse at 50% 0%, rgba(56, 139, 253, 0.04) 0%, transparent 60%);
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
    padding: 0;
}

/* ==========================================================================
   LAYOUT
   ========================================================================== */
.app-container { max-width: 900px; margin: 0 auto; padding: 0 12px 2rem; }

.app-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 16px 16px 12px;
    position: relative;
}
.app-header-logo { width: 36px; height: 36px; }
.app-header-title { font-size: 1.35rem; font-weight: 700; letter-spacing: -0.02em; }

.nav-toggle-btn {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    width: 38px; height: 38px;
    display: flex; align-items: center; justify-content: center;
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

/* ==========================================================================
   BACK LINK
   ========================================================================== */
.back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 0.85rem;
    margin-bottom: 14px;
    padding: 6px 12px;
    border-radius: var(--radius-md);
    transition: all 0.2s;
}
.back-link:hover {
    background: var(--bg-elevated);
    color: var(--text-primary);
}
.back-link img { width: 20px; height: 20px; object-fit: contain; }

/* ==========================================================================
   PLAYER HERO
   ========================================================================== */
.player-hero {
    display: flex;
    gap: 20px;
    align-items: flex-start;
    padding: 1.25rem;
    background: var(--bg-card);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-card);
    margin-bottom: 14px;
}

.player-photo {
    width: 130px; height: 130px;
    border-radius: 50%;
    object-fit: cover;
    background: var(--bg-elevated);
    border: 3px solid var(--bg-elevated);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    flex-shrink: 0;
}
.player-photo-fallback {
    width: 130px; height: 130px;
    border-radius: 50%;
    background: var(--bg-elevated);
    display: flex; align-items: center; justify-content: center;
    color: var(--text-muted);
    font-size: 2.5rem;
    border: 3px solid var(--bg-elevated);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    flex-shrink: 0;
}

.player-details { flex: 1; min-width: 0; }

.player-team-row {
    display: flex; align-items: center; gap: 8px;
    margin-bottom: 6px;
}
.player-team-row img { width: 22px; height: 22px; object-fit: contain; }
.player-team-name { font-size: 0.82rem; color: var(--text-muted); font-weight: 500; }

.player-full-name {
    font-size: 1.8rem; font-weight: 800;
    color: var(--text-primary);
    margin: 0 0 4px;
    line-height: 1.2;
}

.player-jersey-pos {
    font-size: 1rem;
    color: var(--text-secondary);
    margin-bottom: 14px;
}
.pos-badge {
    display: inline-block;
    background: var(--accent-blue);
    color: white;
    padding: 2px 10px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    margin-left: 8px;
}

/* Bio grid */
.bio-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 10px;
}
.bio-item { display: flex; flex-direction: column; }
.bio-label {
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted);
    font-weight: 600;
}
.bio-value { font-size: 0.9rem; font-weight: 600; color: var(--text-primary); }

/* ==========================================================================
   STATS SECTIONS
   ========================================================================== */
.stats-card {
    background: var(--bg-card);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-card);
    padding: 1.25rem;
    margin-bottom: 14px;
}

.section-title {
    font-size: 1.15rem; font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 14px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--border-color);
    display: flex; align-items: center; gap: 8px;
}
.stats-source {
    font-size: 0.72rem;
    color: var(--text-muted);
    font-weight: 400;
    margin-left: auto;
}

/* Primary stats row */
.primary-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(90px, 1fr));
    gap: 10px;
    margin-bottom: 20px;
}
.primary-stat {
    text-align: center;
    padding: 14px 6px;
    background: var(--bg-elevated);
    border-radius: var(--radius-md);
    border: 1px solid var(--border-color);
}
.primary-stat.highlight {
    background: var(--accent-blue-dim);
    border-color: rgba(56, 139, 253, 0.2);
}
.primary-stat-value {
    font-size: 1.6rem; font-weight: 800;
    color: var(--text-primary);
    line-height: 1;
    font-variant-numeric: tabular-nums;
}
.primary-stat-label {
    font-size: 0.7rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-top: 5px;
    font-weight: 600;
}
.primary-stat-rank {
    font-size: 0.65rem;
    color: var(--text-muted);
    margin-top: 3px;
}

/* Detail stats */
.detail-section-title {
    font-size: 1rem; font-weight: 700;
    color: var(--text-secondary);
    margin: 18px 0 10px;
    display: flex; align-items: center; gap: 8px;
}
.detail-section-title i { color: var(--text-muted); }

.detail-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
    gap: 8px;
    margin-bottom: 20px;
}
.detail-stat {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 12px;
    background: var(--bg-elevated);
    border-radius: 6px;
    border-left: 3px solid var(--border-color);
}
.detail-stat-name { font-size: 0.82rem; color: var(--text-secondary); }
.detail-stat-value {
    font-size: 0.95rem; font-weight: 700;
    color: var(--text-primary);
    font-variant-numeric: tabular-nums;
}

/* ==========================================================================
   NO DATA STATE
   ========================================================================== */
.no-data {
    text-align: center;
    padding: 2.5rem 1.5rem;
    color: var(--text-muted);
    background: var(--bg-card);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-card);
}
.no-data i {
    font-size: 2rem;
    margin-bottom: 10px;
    display: block;
    color: var(--accent-teal);
}

/* ==========================================================================
   MOBILE RESPONSIVE
   ========================================================================== */
@media (max-width: 600px) {
    .app-container { padding: 0 8px 2rem; }

    .player-hero {
        flex-direction: column;
        align-items: center;
        text-align: center;
        padding: 1rem;
        gap: 10px;
    }
    .player-photo, .player-photo-fallback { width: 90px; height: 90px; }
    .player-photo-fallback { font-size: 2rem; }
    .player-team-row { justify-content: center; }
    .player-full-name { font-size: 1.3rem; }
    .player-jersey-pos { font-size: 0.9rem; margin-bottom: 10px; }

    .bio-grid { grid-template-columns: repeat(3, 1fr); text-align: center; gap: 8px; }
    .bio-label { font-size: 0.6rem; }
    .bio-value { font-size: 0.82rem; }

    .section-title { font-size: 1rem; }

    .primary-stats { grid-template-columns: repeat(3, 1fr); gap: 8px; }
    .primary-stat { padding: 10px 4px; }
    .primary-stat-value { font-size: 1.2rem; }
    .primary-stat-label { font-size: 0.6rem; }

    .detail-stats-grid { grid-template-columns: 1fr 1fr; gap: 6px; }
    .detail-stat { padding: 8px 10px; }
    .detail-stat-name { font-size: 0.75rem; }
    .detail-stat-value { font-size: 0.85rem; }
}

@media (max-width: 400px) {
    .bio-grid { grid-template-columns: repeat(2, 1fr); }
    .primary-stats { grid-template-columns: repeat(3, 1fr); }
    .primary-stat-value { font-size: 1.1rem; }
    .detail-stats-grid { grid-template-columns: 1fr; }
}
    
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

</style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/nba-wins-platform/components/navigation_menu.php'; ?>

<div class="app-container">

    <!-- ================================================================
         HEADER
         ================================================================ -->


    <!-- ================================================================
         BACK LINK
         ================================================================ -->
    <a href="<?= htmlspecialchars($backUrl) ?>" class="back-link">
        <i class="fas fa-arrow-left"></i>
        <img src="<?= htmlspecialchars($teamLogo) ?>" alt="" onerror="this.style.display='none'">
        <?= htmlspecialchars($backLabel) ?>
    </a>

    <!-- ================================================================
         PLAYER HERO CARD
         ================================================================ -->
    <div class="player-hero">
        <div>
            <?php if ($playerBio && !empty($playerBio['headshot'])): ?>
                <img src="<?= htmlspecialchars($playerBio['headshot']) ?>"
                     alt="<?= htmlspecialchars($player_name) ?>"
                     class="player-photo"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                <div class="player-photo-fallback" style="display: none">
                    <i class="fas fa-user"></i>
                </div>
            <?php else: ?>
                <div class="player-photo-fallback"><i class="fas fa-user"></i></div>
            <?php endif; ?>
        </div>

        <div class="player-details">
            <!-- Team badge -->
            <div class="player-team-row">
                <img src="<?= htmlspecialchars($teamLogo) ?>" alt="" onerror="this.style.display='none'">
                <span class="player-team-name"><?= htmlspecialchars($team_name) ?></span>
            </div>

            <!-- Name -->
            <h1 class="player-full-name"><?= htmlspecialchars($player_name) ?></h1>

            <?php if ($playerBio): ?>
                <!-- Jersey & Position -->
                <div class="player-jersey-pos">
                    <?php if (!empty($playerBio['jersey'])): ?>
                        #<?= htmlspecialchars($playerBio['jersey']) ?>
                    <?php endif; ?>
                    <?php if (!empty($playerBio['position'])): ?>
                        <span class="pos-badge"><?= htmlspecialchars($playerBio['position']) ?></span>
                    <?php endif; ?>
                </div>

                <!-- Bio info grid -->
                <div class="bio-grid">
                    <?php if (!empty($playerBio['height'])): ?>
                        <div class="bio-item">
                            <span class="bio-label">Height</span>
                            <span class="bio-value"><?= htmlspecialchars($playerBio['height']) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($playerBio['weight'])): ?>
                        <div class="bio-item">
                            <span class="bio-label">Weight</span>
                            <span class="bio-value"><?= htmlspecialchars($playerBio['weight']) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($playerBio['age'])): ?>
                        <div class="bio-item">
                            <span class="bio-label">Age</span>
                            <span class="bio-value"><?= $playerBio['age'] ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="bio-item">
                        <span class="bio-label">Experience</span>
                        <span class="bio-value">
                            <?php
                            $exp = $playerBio['experience'];
                            echo ($exp === 0 || $exp === 'R')
                                ? 'Rookie'
                                : $exp . ' year' . ($exp > 1 ? 's' : '');
                            ?>
                        </span>
                    </div>

                    <?php if (!empty($playerBio['college'])): ?>
                        <div class="bio-item">
                            <span class="bio-label">College</span>
                            <span class="bio-value"><?= htmlspecialchars($playerBio['college']) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($playerBio['birthPlace'])): ?>
                        <div class="bio-item">
                            <span class="bio-label">Birthplace</span>
                            <span class="bio-value"><?= htmlspecialchars($playerBio['birthPlace']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>


    <!-- ================================================================
         SEASON STATS — ESPN (Primary)
         ================================================================ -->
    <?php if ($hasEspnStats): ?>
        <div class="stats-card">
            <h2 class="section-title">
            <?= $season['season_label'] ?>
                <span class="stats-source">via ESPN</span>
            </h2>

            <!-- Primary stat cards -->
            <div class="primary-stats">
                <div class="primary-stat">
                    <div class="primary-stat-value"><?= getStatDisplay($espnStats, 'gamesPlayed', '-') ?></div>
                    <div class="primary-stat-label">GP</div>
                </div>
                <div class="primary-stat">
                    <div class="primary-stat-value"><?= getStatDisplay($espnStats, 'avgMinutes', '-') ?></div>
                    <div class="primary-stat-label">MPG</div>
                </div>
                <div class="primary-stat highlight">
                    <div class="primary-stat-value"><?= getStatDisplay($espnStats, 'avgPoints', '-') ?></div>
                    <div class="primary-stat-label">PPG</div>
                    <?php $r = getStatRank($espnStats, 'avgPoints'); if ($r): ?>
                        <div class="primary-stat-rank"><?= $r ?></div>
                    <?php endif; ?>
                </div>
                <div class="primary-stat">
                    <div class="primary-stat-value"><?= getStatDisplay($espnStats, 'avgRebounds', '-') ?></div>
                    <div class="primary-stat-label">RPG</div>
                    <?php $r = getStatRank($espnStats, 'avgRebounds'); if ($r): ?>
                        <div class="primary-stat-rank"><?= $r ?></div>
                    <?php endif; ?>
                </div>
                <div class="primary-stat">
                    <div class="primary-stat-value"><?= getStatDisplay($espnStats, 'avgAssists', '-') ?></div>
                    <div class="primary-stat-label">APG</div>
                    <?php $r = getStatRank($espnStats, 'avgAssists'); if ($r): ?>
                        <div class="primary-stat-rank"><?= $r ?></div>
                    <?php endif; ?>
                </div>
                <div class="primary-stat">
                    <div class="primary-stat-value"><?= getStatDisplay($espnStats, 'avgSteals', '-') ?></div>
                    <div class="primary-stat-label">SPG</div>
                </div>
                <div class="primary-stat">
                    <div class="primary-stat-value"><?= getStatDisplay($espnStats, 'avgBlocks', '-') ?></div>
                    <div class="primary-stat-label">BPG</div>
                </div>
            </div>

            <!-- Shooting splits -->
            <h3 class="detail-section-title"></i> Shooting</h3>
            <div class="detail-stats-grid">
                <div class="detail-stat">
                    <span class="detail-stat-name">FG%</span>
                    <span class="detail-stat-value"><?= getStatDisplay($espnStats, 'fieldGoalPct', '-') ?>%</span>
                </div>
                <div class="detail-stat">
                    <span class="detail-stat-name">FGM / FGA</span>
                    <span class="detail-stat-value">
                        <?= getStatDisplay($espnStats, 'avgFieldGoalsMade', '-') ?> /
                        <?= getStatDisplay($espnStats, 'avgFieldGoalsAttempted', '-') ?>
                    </span>
                </div>
                <div class="detail-stat">
                    <span class="detail-stat-name">3P%</span>
                    <span class="detail-stat-value"><?= getStatDisplay($espnStats, 'threePointFieldGoalPct', '-') ?>%</span>
                </div>
                <div class="detail-stat">
                    <span class="detail-stat-name">3PM / 3PA</span>
                    <span class="detail-stat-value">
                        <?= getStatDisplay($espnStats, 'avgThreePointFieldGoalsMade', '-') ?> /
                        <?= getStatDisplay($espnStats, 'avgThreePointFieldGoalsAttempted', '-') ?>
                    </span>
                </div>
                <div class="detail-stat">
                    <span class="detail-stat-name">FT%</span>
                    <span class="detail-stat-value"><?= getStatDisplay($espnStats, 'freeThrowPct', '-') ?>%</span>
                </div>
                <div class="detail-stat">
                    <span class="detail-stat-name">FTM / FTA</span>
                    <span class="detail-stat-value">
                        <?= getStatDisplay($espnStats, 'avgFreeThrowsMade', '-') ?> /
                        <?= getStatDisplay($espnStats, 'avgFreeThrowsAttempted', '-') ?>
                    </span>
                </div>
            </div>

            <!-- Additional stats -->
            <h3 class="detail-section-title">Additional</h3>
            <div class="detail-stats-grid">
                <div class="detail-stat">
                    <span class="detail-stat-name">Turnovers</span>
                    <span class="detail-stat-value"><?= getStatDisplay($espnStats, 'avgTurnovers', '-') ?></span>
                </div>
                <div class="detail-stat">
                    <span class="detail-stat-name">Fouls</span>
                    <span class="detail-stat-value"><?= getStatDisplay($espnStats, 'avgFouls', '-') ?></span>
                </div>
                <div class="detail-stat">
                    <span class="detail-stat-name">Double-Doubles</span>
                    <span class="detail-stat-value"><?= getStatDisplay($espnStats, 'doubleDouble', '-') ?></span>
                </div>
                <div class="detail-stat">
                    <span class="detail-stat-name">Triple-Doubles</span>
                    <span class="detail-stat-value"><?= getStatDisplay($espnStats, 'tripleDouble', '-') ?></span>
                </div>
            </div>
        </div>


    <!-- ================================================================
         SEASON STATS — DB Fallback
         ================================================================ -->
    <?php elseif ($hasDbStats): ?>
        <div class="stats-card">
            <h2 class="section-title">
            <?= $season['season_label'] ?>
            </h2>
            <div class="primary-stats">
                <div class="primary-stat">
                    <div class="primary-stat-value"><?= $dbStats['games_played'] ?></div>
                    <div class="primary-stat-label">GP</div>
                </div>
                <div class="primary-stat">
                    <div class="primary-stat-value"><?= number_format($dbStats['avg_minutes'], 1) ?></div>
                    <div class="primary-stat-label">MPG</div>
                </div>
                <div class="primary-stat highlight">
                    <div class="primary-stat-value"><?= number_format($dbStats['avg_points'], 1) ?></div>
                    <div class="primary-stat-label">PPG</div>
                </div>
                <div class="primary-stat">
                    <div class="primary-stat-value"><?= number_format($dbStats['avg_rebounds'], 1) ?></div>
                    <div class="primary-stat-label">RPG</div>
                </div>
                <div class="primary-stat">
                    <div class="primary-stat-value"><?= number_format($dbStats['avg_assists'], 1) ?></div>
                    <div class="primary-stat-label">APG</div>
                </div>
                <div class="primary-stat">
                    <div class="primary-stat-value"><?= number_format($dbStats['fg_percentage'], 1) ?>%</div>
                    <div class="primary-stat-label">FG%</div>
                </div>
            </div>
        </div>


    <!-- ================================================================
         NO STATS AVAILABLE
         ================================================================ -->
    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-chart-bar"></i>
            <p style="font-size: 1rem; margin: 0">No season stats available for this player</p>
            <p style="font-size: 0.82rem; color: var(--text-muted); margin-top: 6px">
                Stats will appear once games have been played
            </p>
        </div>
    <?php endif; ?>

</div>

    <!-- Floating Pill Navigation -->
    <nav class="floating-pill" id="floatingPill">
        <!-- Expanded row (hidden until menu tap) -->
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
        <!-- Main row -->
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