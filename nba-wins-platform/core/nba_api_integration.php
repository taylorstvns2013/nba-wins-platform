<?php
/**
 * NBA API Integration Class
 * Handles communication between PHP and Python NBA API scripts
 * Optimized for production use with proper error handling
 * Season dates loaded from config/season.json via getSeasonConfig()
 */

require_once __DIR__ . '/../config/season_config.php';

class NBAApiIntegration {
    private $pythonPath;
    private $scriptsPath;
    private $cacheEnabled;
    private $cacheTimeout;
    private $cacheDir;
    private $seasonConfig;

    public function __construct($config = []) {
        $this->pythonPath = $config['python_path'] ?? '/usr/bin/python3';
        $this->scriptsPath = $config['scripts_path'] ?? '/data/www/default/nba-wins-platform/tasks';
        $this->cacheEnabled = $config['cache_enabled'] ?? true;
        $this->cacheTimeout = $config['cache_timeout'] ?? 300; // 5 minutes default
        $this->cacheDir = $config['cache_dir'] ?? '/tmp/nba_cache';
        $this->seasonConfig = getSeasonConfig();

        // Create cache directory if it doesn't exist
        if ($this->cacheEnabled && !is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Check if Python and NBA API dependencies are available
     */
    public function checkDependencies() {
        $checks = [
            'python' => false,
            'nba_api' => false,
            'scripts' => [],
            'connectivity' => false
        ];
        
        // Check Python
        $pythonCheck = shell_exec($this->pythonPath . ' --version 2>&1');
        $checks['python'] = strpos($pythonCheck, 'Python') !== false;
        
        // Check NBA API module
        if ($checks['python']) {
            $nbaApiCheck = shell_exec($this->pythonPath . ' -c "import nba_api; print(\'success\')" 2>&1');
            $checks['nba_api'] = strpos($nbaApiCheck, 'success') !== false;
        }
        
        // Check script files
        $scriptFiles = [
            'get_team_stats.py'
        ];
        
        foreach ($scriptFiles as $script) {
            $scriptPath = $this->scriptsPath . '/' . $script;
            $checks['scripts'][$script] = file_exists($scriptPath);
        }
        
        // Test API connectivity
        if ($checks['nba_api']) {
            $connectivityTest = $this->testConnectivity();
            $checks['connectivity'] = isset($connectivityTest['status']) && $connectivityTest['status'] === 'connected';
        }
        
        return $checks;
    }
    
    /**
     * Test NBA API connectivity
     */
    public function testConnectivity() {
        try {
            $scriptPath = $this->scriptsPath . '/get_team_stats.py';
            if (!file_exists($scriptPath)) {
                return ['error' => 'NBA API script not found'];
            }
            
            $command = escapeshellcmd($this->pythonPath) . ' ' . escapeshellarg($scriptPath) . ' --test';
            $output = shell_exec($command . ' 2>&1');
            
            if (!$output) {
                return ['error' => 'No response from NBA API test'];
            }
            
            $data = json_decode(trim($output), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['error' => 'Invalid response from NBA API test'];
            }
            
            return $data;
            
        } catch (Exception $e) {
            return ['error' => 'NBA API test failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get team statistics by team name for current season
     */
    public function getTeamStats($teamName) {
        try {
            $seasonLabel = $this->seasonConfig['season_label'];
            $teamId = $this->getTeamIdByName($teamName);
            if (!$teamId) {
                return [
                    'error' => 'Team not found: ' . $teamName,
                    'season' => $seasonLabel
                ];
            }

            $cacheKey = 'team_stats_' . str_replace('-', '_', $seasonLabel) . '_' . $teamId;
            
            // Check cache first
            if ($this->cacheEnabled) {
                $cached = $this->getFromCache($cacheKey);
                if ($cached !== null) {
                    return array_merge($cached, [
                        'success' => true,
                        'cached' => true,
                        'timestamp' => time()
                    ]);
                }
            }
            
            // Execute Python script for current season
            $scriptPath = $this->scriptsPath . '/get_team_stats.py';
            if (!file_exists($scriptPath)) {
                return [
                    'error' => 'NBA API script not found at: ' . $scriptPath,
                    'season' => $seasonLabel
                ];
            }

            $command = escapeshellcmd($this->pythonPath) . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($teamId);
            $output = shell_exec($command . ' 2>&1');

            if (!$output) {
                $seasonStart = $this->seasonConfig['season_start_date'];
                return [
                    'error' => $seasonLabel . ' season data not available yet',
                    'details' => "Season starts $seasonStart",
                    'season' => $seasonLabel,
                    'api_status' => 'no_response'
                ];
            }

            $data = json_decode(trim($output), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'error' => 'Invalid response from NBA API',
                    'raw_output' => substr($output, 0, 200) . '...',
                    'season' => $seasonLabel
                ];
            }
            
            // Handle different response types
            if (isset($data['error'])) {
                // Don't cache errors, but provide detailed status
                return array_merge($data, [
                    'success' => false,
                    'cached' => false,
                    'timestamp' => time()
                ]);
            }
            
            // Cache successful results
            if ($this->cacheEnabled && isset($data['api_status']) && $data['api_status'] === 'live') {
                $this->saveToCache($cacheKey, $data);
            }
            
            return array_merge($data, [
                'success' => true,
                'cached' => false,
                'timestamp' => time()
            ]);
            
        } catch (Exception $e) {
            return [
                'error' => 'Exception in getTeamStats: ' . $e->getMessage(),
                'season' => $this->seasonConfig['season_label'],
                'api_status' => 'exception'
            ];
        }
    }

    /**
     * Get team roster by team name for current season
     */
    public function getTeamRoster($teamName) {
        try {
            $seasonLabel = $this->seasonConfig['season_label'];
            $teamId = $this->getTeamIdByName($teamName);
            if (!$teamId) {
                return [
                    'error' => 'Team not found: ' . $teamName,
                    'season' => $seasonLabel
                ];
            }

            $cacheKey = 'team_roster_' . str_replace('-', '_', $seasonLabel) . '_' . $teamId;
            
            // Check cache first
            if ($this->cacheEnabled) {
                $cached = $this->getFromCache($cacheKey);
                if ($cached !== null) {
                    return [
                        'success' => true,
                        'data' => $cached,
                        'cached' => true,
                        'timestamp' => time(),
                        'season' => $seasonLabel
                    ];
                }
            }

            // Execute Python script
            $scriptPath = $this->scriptsPath . '/get_team_stats.py';
            if (!file_exists($scriptPath)) {
                return [
                    'error' => 'NBA API script not found',
                    'season' => $seasonLabel
                ];
            }

            $command = escapeshellcmd($this->pythonPath) . ' ' . escapeshellarg($scriptPath) . ' --roster ' . escapeshellarg($teamId);
            $output = shell_exec($command . ' 2>&1');

            if (!$output) {
                return [
                    'success' => true,
                    'data' => [],
                    'message' => $seasonLabel . ' roster data not yet available',
                    'season' => $seasonLabel
                ];
            }

            $data = json_decode(trim($output), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'success' => true,
                    'data' => [],
                    'message' => 'Roster data format error',
                    'season' => $seasonLabel
                ];
            }
            
            // Cache successful results
            if ($this->cacheEnabled && is_array($data) && !empty($data)) {
                $this->saveToCache($cacheKey, $data);
            }
            
            return [
                'success' => true,
                'data' => is_array($data) ? $data : [],
                'cached' => false,
                'timestamp' => time(),
                'season' => $seasonLabel
            ];

        } catch (Exception $e) {
            return [
                'success' => true,
                'data' => [],
                'error' => 'Exception in getTeamRoster: ' . $e->getMessage(),
                'season' => $this->seasonConfig['season_label']
            ];
        }
    }
    
    /**
     * Get NBA season status and readiness
     */
    public function getSeasonStatus() {
        $currentDate = date('Y-m-d');
        $seasonLabel = $this->seasonConfig['season_label'];
        $seasonStart = $this->seasonConfig['season_start_date'];
        $trainingCamp = $this->seasonConfig['training_camp_date'];
        $preseason = $this->seasonConfig['preseason_start_date'];

        if ($currentDate < $trainingCamp) {
            return [
                'status' => 'off_season',
                'message' => 'NBA off-season',
                'next_milestone' => "Training camps start $trainingCamp",
                'data_available' => false,
                'season' => $seasonLabel
            ];
        } elseif ($currentDate < $preseason) {
            return [
                'status' => 'training_camp',
                'message' => 'Training camp period',
                'next_milestone' => "Preseason starts $preseason",
                'data_available' => false,
                'season' => $seasonLabel
            ];
        } elseif ($currentDate < $seasonStart) {
            return [
                'status' => 'preseason',
                'message' => 'Preseason games',
                'next_milestone' => "Regular season starts $seasonStart",
                'data_available' => 'limited',
                'season' => $seasonLabel
            ];
        } else {
            return [
                'status' => 'regular_season',
                'message' => "$seasonLabel regular season active",
                'next_milestone' => 'Season in progress',
                'data_available' => true,
                'season' => $seasonLabel
            ];
        }
    }
    
    /**
     * Convert team name to NBA API team ID
     */
    private function getTeamIdByName($teamName) {
        $teamMap = [
            'Atlanta Hawks' => 1610612737,
            'Boston Celtics' => 1610612738,
            'Brooklyn Nets' => 1610612751,
            'Charlotte Hornets' => 1610612766,
            'Chicago Bulls' => 1610612741,
            'Cleveland Cavaliers' => 1610612739,
            'Dallas Mavericks' => 1610612742,
            'Denver Nuggets' => 1610612743,
            'Detroit Pistons' => 1610612765,
            'Golden State Warriors' => 1610612744,
            'Houston Rockets' => 1610612745,
            'Indiana Pacers' => 1610612754,
            'Los Angeles Clippers' => 1610612746,
            'Los Angeles Lakers' => 1610612747,
            'Memphis Grizzlies' => 1610612763,
            'Miami Heat' => 1610612748,
            'Milwaukee Bucks' => 1610612749,
            'Minnesota Timberwolves' => 1610612750,
            'New Orleans Pelicans' => 1610612740,
            'New York Knicks' => 1610612752,
            'Oklahoma City Thunder' => 1610612760,
            'Orlando Magic' => 1610612753,
            'Philadelphia 76ers' => 1610612755,
            'Phoenix Suns' => 1610612756,
            'Portland Trail Blazers' => 1610612757,
            'Sacramento Kings' => 1610612758,
            'San Antonio Spurs' => 1610612759,
            'Toronto Raptors' => 1610612761,
            'Utah Jazz' => 1610612762,
            'Washington Wizards' => 1610612764
        ];
        
        return $teamMap[$teamName] ?? null;
    }
    
    /**
     * Cache management methods
     */
    private function getFromCache($key) {
        if (!$this->cacheEnabled) return null;
        
        $cacheFile = $this->cacheDir . '/' . md5($key) . '.json';
        if (!file_exists($cacheFile)) return null;
        
        $cacheTime = filemtime($cacheFile);
        if (time() - $cacheTime > $this->cacheTimeout) {
            unlink($cacheFile);
            return null;
        }
        
        $data = file_get_contents($cacheFile);
        return json_decode($data, true);
    }
    
    private function saveToCache($key, $data) {
        if (!$this->cacheEnabled) return;
        $cacheFile = $this->cacheDir . '/' . md5($key) . '.json';
        file_put_contents($cacheFile, json_encode($data));
    }
    
    public function clearCache() {
        if (!$this->cacheEnabled) return;
        $files = glob($this->cacheDir . '/*.json');
        foreach ($files as $file) {
            unlink($file);
        }
    }
    
    public function getCacheStats() {
        if (!$this->cacheEnabled) {
            return ['enabled' => false];
        }
        
        $files = glob($this->cacheDir . '/*.json');
        $totalSize = 0;
        foreach ($files as $file) {
            $totalSize += filesize($file);
        }
        
        return [
            'enabled' => true,
            'file_count' => count($files),
            'total_size' => $totalSize,
            'cache_dir' => $this->cacheDir,
            'timeout' => $this->cacheTimeout
        ];
    }
}
?>