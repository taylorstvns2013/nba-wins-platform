<?php
require_once '/data/www/default/nba-wins-platform/core/nba_api_integration.php';

$nbaApi = new NBAApiIntegration([
    'python_path' => '/usr/bin/python3',
    'scripts_path' => '/data/www/default/nba-wins-platform/tasks'
]);

// Check season status
echo "Season Status:\n";
print_r($nbaApi->getSeasonStatus());

// Check dependencies
echo "\nDependency Check:\n";
print_r($nbaApi->checkDependencies());

// Test Lakers data
echo "\nTesting Lakers API call:\n";
$stats = $nbaApi->getTeamStats('Los Angeles Lakers');
print_r($stats);
?>