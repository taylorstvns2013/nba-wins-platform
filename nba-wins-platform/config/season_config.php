<?php
/**
 * Season Configuration Loader
 *
 * Loads season dates and parameters from season.json.
 * Update season.json once per year — all files read from it automatically.
 *
 * Usage:
 *   require_once '/data/www/default/nba-wins-platform/config/season_config.php';
 *   $season = getSeasonConfig();
 *   echo $season['season_start_date'];  // '2025-10-21'
 */

function getSeasonConfig() {
    static $config = null;
    if ($config === null) {
        $json = file_get_contents(__DIR__ . '/season.json');
        $config = json_decode($json, true);
        if ($config === null) {
            error_log("Failed to parse season.json: " . json_last_error_msg());
            // Fallback defaults so the site doesn't crash
            $config = [
                'season_label' => '2025-26',
                'standings_table' => '2025_2026',
                'standings_table_backup' => '2025_2026_backup',
                'season_start_date' => '2025-10-21',
                'api_season_nba' => '2025-26',
                'api_season_rapid' => '2025',
                'api_season_espn' => '2026',
                'all_star_break_start' => '2026-02-13',
                'all_star_break_end' => '2026-02-18',
                'nba_cup_dates' => [],
            ];
        }
    }
    return $config;
}
