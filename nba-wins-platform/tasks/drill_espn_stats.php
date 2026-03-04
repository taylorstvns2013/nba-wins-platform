<?php
// More ESPN endpoints to find per-player stats by team
// Bucks = 15, MIL

require_once(__DIR__ . '/../config/season_config.php');
$season = getSeasonConfig();

$endpoints = [
    'Core API team athletes stats' =>
        "https://sports.core.api.espn.com/v2/sports/basketball/leagues/nba/seasons/{$season['api_season_espn']}/types/2/teams/15/athletes?limit=30",
    'Core API team statistics' =>
        "https://sports.core.api.espn.com/v2/sports/basketball/leagues/nba/seasons/{$season['api_season_espn']}/types/2/teams/15/statistics",
    'Core API single athlete stats (Giannis 3032977)' =>
        "https://sports.core.api.espn.com/v2/sports/basketball/leagues/nba/seasons/{$season['api_season_espn']}/types/2/athletes/3032977/statistics",
    'Web API team roster stats' =>
        "https://site.web.api.espn.com/apis/common/v3/sports/basketball/nba/teams/15/statistics?season={$season['api_season_espn']}&seasontype=2",
    'Site API team stats page data' =>
        'https://site.web.api.espn.com/apis/site/v2/sports/basketball/nba/teams/15/statistics',
    'byathlete with limit and team sort' =>
        "https://site.web.api.espn.com/apis/common/v3/sports/basketball/nba/statistics/byathlete?team=15&season={$season['api_season_espn']}&seasontype=2&limit=50&sort=points",
];

foreach ($endpoints as $label => $url) {
    echo "=== $label ===\n";
    echo "URL: $url\n";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "HTTP: $httpCode | Size: " . strlen($response) . " bytes\n";
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if ($data) {
            echo "Top keys: " . implode(', ', array_keys($data)) . "\n";
            
            // Show first 1500 chars to understand structure
            echo "Structure preview:\n";
            echo substr(json_encode($data, JSON_PRETTY_PRINT), 0, 1500) . "\n";
        }
    } else {
        echo "FAILED\n";
        if ($response) echo "Response preview: " . substr($response, 0, 300) . "\n";
    }
    
    echo "\n" . str_repeat('-', 60) . "\n\n";
    sleep(1);
}

// Also try: What if byathlete actually has Bucks players but with different teamId format?
echo "=== CHECKING: All unique teamIds in byathlete response ===\n";
$url = "https://site.web.api.espn.com/apis/common/v3/sports/basketball/nba/statistics/byathlete?team=15&season={$season['api_season_espn']}&seasontype=2&limit=50";
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'Mozilla/5.0']);
$response = curl_exec($ch);
curl_close($ch);
$data = json_decode($response, true);

$teams = [];
foreach ($data['athletes'] ?? [] as $a) {
    $tid = $a['athlete']['teamId'] ?? '?';
    $tname = $a['athlete']['teamName'] ?? '?';
    $teams["$tid-$tname"] = ($teams["$tid-$tname"] ?? 0) + 1;
}
echo "Teams in response:\n";
foreach ($teams as $t => $count) {
    echo "  $t: $count players\n";
}

echo "\nDone!\n";
?>