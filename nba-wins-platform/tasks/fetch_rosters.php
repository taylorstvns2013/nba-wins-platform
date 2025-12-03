<?php
/**
 * Fetch NBA Rosters from RapidAPI
 * 
 * This script fetches all NBA team rosters from RapidAPI and stores them in the database.
 * It stores only: firstname, lastname, jersey_number for each player.
 * 
 * Usage: php /data/www/default/nba-wins-platform/tasks/fetch_rosters.php
 */

// Use CLI database connection (no authentication needed)
require_once '/data/www/default/nba-wins-platform/config/db_connection_cli.php';

// RapidAPI Configuration
const RAPIDAPI_KEY = 'RAPIDAPI_KEY_REMOVED'; // Replace with your actual API key
const RAPIDAPI_HOST = 'api-nba-v1.p.rapidapi.com';
const SEASON = '2025';

// NBA Teams with their RapidAPI team IDs (verified from API /teams endpoint)
$nbaTeams = [
    // Eastern Conference - Atlantic Division
    ['name' => 'Boston Celtics', 'id' => 2],
    ['name' => 'Brooklyn Nets', 'id' => 4],
    ['name' => 'New York Knicks', 'id' => 24],
    ['name' => 'Philadelphia 76ers', 'id' => 27],
    ['name' => 'Toronto Raptors', 'id' => 38],
    
    // Eastern Conference - Central Division
    ['name' => 'Chicago Bulls', 'id' => 6],
    ['name' => 'Cleveland Cavaliers', 'id' => 7],
    ['name' => 'Detroit Pistons', 'id' => 10],
    ['name' => 'Indiana Pacers', 'id' => 15],
    ['name' => 'Milwaukee Bucks', 'id' => 21],
    
    // Eastern Conference - Southeast Division
    ['name' => 'Atlanta Hawks', 'id' => 1],
    ['name' => 'Charlotte Hornets', 'id' => 5],
    ['name' => 'Miami Heat', 'id' => 20],
    ['name' => 'Orlando Magic', 'id' => 26],
    ['name' => 'Washington Wizards', 'id' => 41],
    
    // Western Conference - Northwest Division
    ['name' => 'Denver Nuggets', 'id' => 9],
    ['name' => 'Minnesota Timberwolves', 'id' => 22],
    ['name' => 'Oklahoma City Thunder', 'id' => 25],
    ['name' => 'Portland Trail Blazers', 'id' => 29],
    ['name' => 'Utah Jazz', 'id' => 40],
    
    // Western Conference - Pacific Division
    ['name' => 'Golden State Warriors', 'id' => 11],
    ['name' => 'Los Angeles Clippers', 'id' => 16],
    ['name' => 'Los Angeles Lakers', 'id' => 17],
    ['name' => 'Phoenix Suns', 'id' => 28],
    ['name' => 'Sacramento Kings', 'id' => 30],
    
    // Western Conference - Southwest Division
    ['name' => 'Dallas Mavericks', 'id' => 8],
    ['name' => 'Houston Rockets', 'id' => 14],
    ['name' => 'Memphis Grizzlies', 'id' => 19],
    ['name' => 'New Orleans Pelicans', 'id' => 23],
    ['name' => 'San Antonio Spurs', 'id' => 31]
];

/**
 * Fetch roster from RapidAPI for a specific team
 */
function fetchTeamRoster($teamId, $season) {
    $url = "https://" . RAPIDAPI_HOST . "/players?team=" . $teamId . "&season=" . $season;
    
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => [
            'x-rapidapi-host: ' . RAPIDAPI_HOST,
            'x-rapidapi-key: ' . RAPIDAPI_KEY
        ],
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    if ($error) {
        echo "  ❌ cURL Error: $error\n";
        return null;
    }
    
    if ($httpCode !== 200) {
        echo "  ❌ HTTP Error $httpCode\n";
        return null;
    }
    
    $data = json_decode($response, true);
    
    if (!$data || !isset($data['response'])) {
        echo "  ❌ Invalid response format\n";
        return null;
    }
    
    return $data['response'];
}

/**
 * Store roster in database
 */
function storeRoster($pdo, $teamName, $teamId, $players, $season) {
    $insertCount = 0;
    $updateCount = 0;
    $skipCount = 0;
    
    foreach ($players as $player) {
        // Skip players without basic information
        if (empty($player['firstname']) || empty($player['lastname'])) {
            $skipCount++;
            continue;
        }
        
        // Only store active players
        $isActive = isset($player['leagues']['standard']['active']) && 
                    $player['leagues']['standard']['active'] === true;
        
        if (!$isActive) {
            $skipCount++;
            continue;
        }
        
        $firstname = $player['firstname'];
        $lastname = $player['lastname'];
        $jersey = $player['leagues']['standard']['jersey'] ?? null;
        
        try {
            // Use INSERT ... ON DUPLICATE KEY UPDATE to handle both inserts and updates
            $stmt = $pdo->prepare("
                INSERT INTO nba_simple_rosters 
                (team_name, team_id, firstname, lastname, jersey_number, season, active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    jersey_number = VALUES(jersey_number),
                    active = VALUES(active),
                    updated_at = CURRENT_TIMESTAMP
            ");
            
            $stmt->execute([
                $teamName,
                $teamId,
                $firstname,
                $lastname,
                $jersey,
                $season
            ]);
            
            if ($stmt->rowCount() > 0) {
                $insertCount++;
            } else {
                $updateCount++;
            }
            
        } catch (PDOException $e) {
            echo "  ⚠️  Error storing player $firstname $lastname: " . $e->getMessage() . "\n";
        }
    }
    
    return [
        'inserted' => $insertCount,
        'updated' => $updateCount,
        'skipped' => $skipCount
    ];
}

// Main execution
echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  NBA ROSTER IMPORT - Season " . SEASON . "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "\n";

if (RAPIDAPI_KEY === 'YOUR_RAPIDAPI_KEY_HERE') {
    die("❌ ERROR: Please set your RapidAPI key in the script!\n\n");
}

$totalInserted = 0;
$totalUpdated = 0;
$totalSkipped = 0;
$successCount = 0;
$errorCount = 0;

$startTime = microtime(true);

foreach ($nbaTeams as $team) {
    echo "🏀 {$team['name']} (ID: {$team['id']})...\n";
    
    $players = fetchTeamRoster($team['id'], SEASON);
    
    if ($players === null) {
        echo "  ⚠️  Failed to fetch roster\n\n";
        $errorCount++;
        continue;
    }
    
    echo "  📥 Fetched " . count($players) . " players\n";
    
    $result = storeRoster($pdo, $team['name'], $team['id'], $players, SEASON);
    
    echo "  ✅ Stored: {$result['inserted']} new, {$result['updated']} updated, {$result['skipped']} skipped\n\n";
    
    $totalInserted += $result['inserted'];
    $totalUpdated += $result['updated'];
    $totalSkipped += $result['skipped'];
    $successCount++;
    
    // Be nice to the API - longer delay to avoid rate limits (429 errors)
    sleep(2); // 2 second delay between requests
}

$endTime = microtime(true);
$duration = round($endTime - $startTime, 2);

echo "═══════════════════════════════════════════════════════════════\n";
echo "  IMPORT COMPLETE\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "\n";
echo "  ✅ Successful teams: $successCount\n";
echo "  ❌ Failed teams: $errorCount\n";
echo "  📊 Total players inserted: $totalInserted\n";
echo "  🔄 Total players updated: $totalUpdated\n";
echo "  ⏭️  Total players skipped: $totalSkipped\n";
echo "  ⏱️  Duration: {$duration} seconds\n";
echo "\n";

// Show sample of stored data
echo "═══════════════════════════════════════════════════════════════\n";
echo "  SAMPLE DATA (Los Angeles Lakers)\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "\n";

try {
    $stmt = $pdo->prepare("
        SELECT firstname, lastname, jersey_number 
        FROM nba_simple_rosters 
        WHERE team_name = 'Los Angeles Lakers' AND season = ?
        ORDER BY jersey_number
        LIMIT 10
    ");
    $stmt->execute([SEASON]);
    $sample = $stmt->fetchAll();
    
    foreach ($sample as $player) {
        $jersey = $player['jersey_number'] ?? '--';
        echo "  #{$jersey} {$player['firstname']} {$player['lastname']}\n";
    }
    
    echo "\n";
} catch (PDOException $e) {
    echo "  Error fetching sample: " . $e->getMessage() . "\n\n";
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "\n";
?>