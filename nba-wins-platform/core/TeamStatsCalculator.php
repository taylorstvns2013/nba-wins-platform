<?php
/**
 * TeamStatsCalculator - Calculate Team Statistics from Database
 *
 * Customized for nba_wins_platform schema:
 * - games table: home_team, away_team, home_points, away_points
 * - game_player_stats table: team_name, points, rebounds, assists, fg_made, fg_attempts, etc.
 * - nba_team_api_stats: RapidAPI cached stats with advanced metrics
 *
 * ENHANCED: Now integrates RapidAPI stats from nba_team_api_stats table
 * Season dates loaded from config/season.json via getSeasonConfig()
 */

require_once __DIR__ . '/../config/season_config.php';

class TeamStatsCalculator {
    private $pdo;
    private $season_start_date;
    private $seasonConfig;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->seasonConfig = getSeasonConfig();
        $this->season_start_date = $this->seasonConfig['season_start_date'];
    }
    
    /**
     * Get comprehensive team statistics for current season
     * Only includes games from season start date onwards (no preseason)
     * ENHANCED: Now merges RapidAPI stats from nba_team_api_stats table
     */
    public function getTeamStats($team_name, $season = null) {
        if ($season === null) {
            $season = str_replace('-', '-20', $this->seasonConfig['season_label']);
        }
        $stats = [
            'team_name' => $team_name,
            'season' => $season,
            'data_source' => 'database_calculated',
            
            // Basic stats
            'GP' => 0,     // Games played
            'W' => 0,      // Wins
            'L' => 0,      // Losses
            'W_PCT' => 0,  // Win percentage
            
            // Scoring stats (per game averages)
            'PTS' => 0,    // Points
            'FGM' => 0,    // Field goals made
            'FGA' => 0,    // Field goals attempted
            'FG_PCT' => 0, // Field goal percentage
            'FG3M' => 0,   // 3-pointers made
            'FG3A' => 0,   // 3-pointers attempted
            'FG3_PCT' => 0,// 3-point percentage
            'FTM' => 0,    // Free throws made
            'FTA' => 0,    // Free throws attempted
            'FT_PCT' => 0, // Free throw percentage
            
            // Rebounding stats
            'REB' => 0,    // Total rebounds per game
            'OREB' => 0,   // Offensive rebounds per game
            'DREB' => 0,   // Defensive rebounds per game
            
            // Other stats
            'AST' => 0,    // Assists per game
            'STL' => 0,    // Steals per game
            'BLK' => 0,    // Blocks per game
            'TOV' => 0,    // Turnovers per game
            'PF' => 0,     // Personal fouls per game
            'PLUS_MINUS' => 0, // Plus/minus per game
            
            // Advanced stats
            'FAST_BREAK_PTS' => 0,
            'POINTS_IN_PAINT' => 0,
            'SECOND_CHANCE_PTS' => 0,
            'PTS_OFF_TO' => 0,
            
            'MIN' => 0,    // Minutes per game
            
            'last_updated' => date('Y-m-d H:i:s')
        ];
        
        // Get stats from games and player data
        $gameStats = $this->getStatsFromGames($team_name);
        
        if ($gameStats && $gameStats['GP'] > 0) {
            $stats = array_merge($stats, $gameStats);
        } else {
            // Fallback to standings table if no games yet
            $standingsStats = $this->getStatsFromStandings($team_name);
            if ($standingsStats) {
                $stats = array_merge($stats, $standingsStats);
            }
        }
        
        // ENHANCEMENT: Merge RapidAPI stats for advanced metrics
        $apiStats = $this->getCachedApiStats($team_name);
        if ($apiStats && $apiStats['games_played'] > 0 && $stats['GP'] > 0) {
            // IMPORTANT: Use actual regular season games count from $stats, not API's games_played
            // API games_played may include preseason games
            $games = $stats['GP'];
            
            // MANUAL CALCULATION: Calculate shooting percentages from made/attempted instead of using API percentages
            // The API's fg_pct, tp_pct, ft_pct values are sometimes inaccurate
            
            // Field Goal % - Calculate from fgm and fga
            if ($apiStats['fga'] > 0) {
                $stats['FG_PCT'] = $apiStats['fgm'] / $apiStats['fga'];
            } else {
                $stats['FG_PCT'] = 0;
            }
            
            // 3-Point stats - Calculate per game averages from totals, but keep percentage as season total
            $stats['FG3M'] = round($apiStats['tpm'] / $games, 1);  // Per game average
            $stats['FG3A'] = round($apiStats['tpa'] / $games, 1);  // Per game average
            if ($apiStats['tpa'] > 0) {
                $stats['FG3_PCT'] = $apiStats['tpm'] / $apiStats['tpa'];  // Season percentage
            } else {
                $stats['FG3_PCT'] = 0;
            }
            
            // Free Throw stats
            $stats['FTM'] = round($apiStats['ftm'] / $games, 1);
            $stats['FTA'] = round($apiStats['fta'] / $games, 1);
            if ($apiStats['fta'] > 0) {
                $stats['FT_PCT'] = $apiStats['ftm'] / $apiStats['fta'];
            } else {
                $stats['FT_PCT'] = 0;
            }
            
            // Calculate per-game stats from totals
            $stats['REB'] = round($apiStats['tot_reb'] / $games, 1);
            $stats['OREB'] = round($apiStats['off_reb'] / $games, 1);
            $stats['DREB'] = round($apiStats['def_reb'] / $games, 1);
            $stats['AST'] = round($apiStats['assists'] / $games, 1);
            $stats['STL'] = round($apiStats['steals'] / $games, 1);
            $stats['BLK'] = round($apiStats['blocks'] / $games, 1);
            $stats['TOV'] = round($apiStats['turnovers'] / $games, 1);
            $stats['PF'] = round($apiStats['fouls'] / $games, 1);
            $stats['PLUS_MINUS'] = round($apiStats['plus_minus'] / $games, 1);
            
            // Advanced stats (also per game)
            $stats['FAST_BREAK_PTS'] = round($apiStats['fast_break_points'] / $games, 1);
            $stats['POINTS_IN_PAINT'] = round($apiStats['points_in_paint'] / $games, 1);
            $stats['SECOND_CHANCE_PTS'] = round($apiStats['second_chance_points'] / $games, 1);
            $stats['PTS_OFF_TO'] = round($apiStats['points_off_turnovers'] / $games, 1);
            
            $stats['data_source'] = 'api_enhanced';
            $stats['api_last_updated'] = $apiStats['last_updated'];
        }
        
        return $stats;
    }
    
    /**
     * Get cached API stats from nba_team_api_stats table
     */
    private function getCachedApiStats($team_name) {
        try {
            $apiSeason = $this->seasonConfig['api_season_rapid'];
            $stmt = $this->pdo->prepare("
                SELECT * FROM nba_team_api_stats
                WHERE team_name = ? AND season = ?
                LIMIT 1
            ");
            $stmt->execute([$team_name, $apiSeason]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching cached API stats: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Calculate stats from games table
     * Schema: home_team, away_team, home_points, away_points, date, status_long
     */
    private function getStatsFromGames($team_name) {
        try {
            // Get all regular season games for this team
            $stmt = $this->pdo->prepare("
                SELECT 
                    id,
                    home_team,
                    away_team,
                    home_points,
                    away_points,
                    date,
                    status_long
                FROM games
                WHERE (home_team = ? OR away_team = ?)
                AND date >= ?
                AND (status_long IN ('Final', 'Finished') OR status_long LIKE '%Final%' OR status_long LIKE '%Finished%')
                ORDER BY date ASC
            ");
            $stmt->execute([$team_name, $team_name, $this->season_start_date]);
            $games = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($games)) {
                return null;
            }
            
            $games_played = count($games);
            $wins = 0;
            $total_pts = 0;
            $total_pts_allowed = 0;
            
            // Calculate W-L and scoring from game results
            foreach ($games as $game) {
                $is_home = ($game['home_team'] === $team_name);
                $team_score = $is_home ? $game['home_points'] : $game['away_points'];
                $opp_score = $is_home ? $game['away_points'] : $game['home_points'];
                
                $total_pts += $team_score;
                $total_pts_allowed += $opp_score;
                
                if ($team_score > $opp_score) {
                    $wins++;
                }
            }
            
            $losses = $games_played - $wins;
            $win_pct = $games_played > 0 ? $wins / $games_played : 0;
            $ppg = $games_played > 0 ? $total_pts / $games_played : 0;
            $opp_ppg = $games_played > 0 ? $total_pts_allowed / $games_played : 0;
            
            // Get detailed player stats aggregated to team level
            $detailedStats = $this->getDetailedPlayerStats($team_name);
            
            return array_merge([
                'GP' => $games_played,
                'W' => $wins,
                'L' => $losses,
                'W_PCT' => round($win_pct, 3),
                'PTS' => round($ppg, 1),
                'OPP_PTS' => round($opp_ppg, 1)
            ], $detailedStats);
            
        } catch (Exception $e) {
            error_log("Error calculating team stats from games: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get detailed stats from game_player_stats table
     * Aggregates individual player stats to get team totals per game
     */
    private function getDetailedPlayerStats($team_name) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(DISTINCT game_id) as games_count,
                    AVG(total_pts) as avg_pts,
                    AVG(total_reb) as avg_reb,
                    AVG(total_ast) as avg_ast,
                    AVG(total_fgm) as avg_fgm,
                    AVG(total_fga) as avg_fga,
                    AVG(total_min) as avg_min,
                    SUM(total_fgm) as sum_fgm,
                    SUM(total_fga) as sum_fga
                FROM (
                    SELECT 
                        gps.game_id,
                        SUM(gps.points) as total_pts,
                        SUM(gps.rebounds) as total_reb,
                        SUM(gps.assists) as total_ast,
                        SUM(gps.fg_made) as total_fgm,
                        SUM(gps.fg_attempts) as total_fga,
                        SUM(CAST(SUBSTRING_INDEX(gps.minutes, ':', 1) AS UNSIGNED) + 
                            CAST(SUBSTRING_INDEX(gps.minutes, ':', -1) AS UNSIGNED) / 60.0) as total_min
                    FROM game_player_stats gps
                    JOIN games g ON gps.game_id = g.id
                    WHERE gps.team_name = ?
                    AND g.date >= ?
                    AND (g.status_long IN ('Final', 'Finished') OR g.status_long LIKE '%Final%' OR g.status_long LIKE '%Finished%')
                    GROUP BY gps.game_id
                ) as game_totals
            ");
            $stmt->execute([$team_name, $this->season_start_date]);
            $totals = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$totals || $totals['games_count'] == 0) {
                return [
                    'FGM' => 0,
                    'FGA' => 0,
                    'MIN' => 0
                ];
            }
            
            return [
                'FGM' => round($totals['avg_fgm'], 1),
                'FGA' => round($totals['avg_fga'], 1),
                'MIN' => round($totals['avg_min'], 1)
            ];
            
        } catch (Exception $e) {
            error_log("Error getting detailed player stats: " . $e->getMessage());
            return [
                'FGM' => 0,
                'FGA' => 0,
                'MIN' => 0
            ];
        }
    }
    
    /**
     * Fallback: Get basic stats from standings table
     */
    private function getStatsFromStandings($team_name) {
        try {
            $table = $this->seasonConfig['standings_table'];
            $stmt = $this->pdo->prepare("
                SELECT name, win, loss
                FROM `{$table}`
                WHERE name = ?
            ");
            $stmt->execute([$team_name]);
            $team = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$team) {
                return null;
            }
            
            $games = $team['win'] + $team['loss'];
            $win_pct = $games > 0 ? $team['win'] / $games : 0;
            
            return [
                'GP' => $games,
                'W' => $team['win'],
                'L' => $team['loss'],
                'W_PCT' => round($win_pct, 3),
                'data_source' => 'standings_table'
            ];
            
        } catch (Exception $e) {
            error_log("Error getting stats from standings: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get recent game results for a team
     * Returns last N completed games with scores and results
     */
    public function getRecentGames($team_name, $limit = 5) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    id as game_id,
                    home_team,
                    away_team,
                    home_points,
                    away_points,
                    date,
                    status_long
                FROM games
                WHERE (home_team = ? OR away_team = ?)
                AND date >= ?
                AND (status_long IN ('Final', 'Finished') OR status_long LIKE '%Final%' OR status_long LIKE '%Finished%')
                ORDER BY date DESC
                LIMIT ?
            ");
            $stmt->execute([$team_name, $team_name, $this->season_start_date, $limit]);
            $games = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $results = [];
            foreach ($games as $game) {
                $is_home = ($game['home_team'] === $team_name);
                $team_score = $is_home ? $game['home_points'] : $game['away_points'];
                $opp_score = $is_home ? $game['away_points'] : $game['home_points'];
                $opponent = $is_home ? $game['away_team'] : $game['home_team'];
                
                $results[] = [
                    'game_id' => $game['game_id'],
                    'date' => $game['date'],
                    'opponent' => $opponent,
                    'location' => $is_home ? 'vs' : '@',
                    'result' => $team_score > $opp_score ? 'W' : 'L',
                    'score' => $team_score . '-' . $opp_score,
                    'team_score' => $team_score,
                    'opp_score' => $opp_score
                ];
            }
            
            return $results;
            
        } catch (Exception $e) {
            error_log("Error getting recent games: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get roster from players who have played this season
     * Alternative to API - builds roster from game_player_stats
     */
    public function getRosterFromGames($team_name) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT DISTINCT
                    gps.player_name as PLAYER,
                    '' as NUM,
                    '' as POSITION,
                    '' as HEIGHT,
                    '' as WEIGHT,
                    '' as AGE,
                    '' as EXP,
                    '' as SCHOOL,
                    COUNT(DISTINCT gps.game_id) as GP,
                    AVG(CAST(SUBSTRING_INDEX(gps.minutes, ':', 1) AS UNSIGNED) + 
                        CAST(SUBSTRING_INDEX(gps.minutes, ':', -1) AS UNSIGNED) / 60.0) as MIN,
                    AVG(gps.points) as PTS,
                    AVG(gps.rebounds) as REB,
                    AVG(gps.assists) as AST,
                    AVG(CASE WHEN gps.fg_attempts > 0 
                        THEN gps.fg_made / gps.fg_attempts ELSE 0 END) as FG_PCT
                FROM game_player_stats gps
                JOIN games g ON gps.game_id = g.id
                WHERE gps.team_name = ?
                AND g.date >= ?
                AND (g.status_long IN ('Final', 'Finished') OR g.status_long LIKE '%Final%' OR g.status_long LIKE '%Finished%')
                GROUP BY gps.player_name
                ORDER BY AVG(gps.points) DESC
            ");
            $stmt->execute([$team_name, $this->season_start_date]);
            $roster = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format the stats
            foreach ($roster as &$player) {
                $player['MIN'] = round($player['MIN'], 1);
                $player['PTS'] = round($player['PTS'], 1);
                $player['REB'] = round($player['REB'], 1);
                $player['AST'] = round($player['AST'], 1);
                $player['FG_PCT'] = round($player['FG_PCT'], 3);
                $player['FG3_PCT'] = 0; // Not available in current schema
                $player['FT_PCT'] = 0;  // Not available in current schema
            }
            
            return $roster;
            
        } catch (Exception $e) {
            error_log("Error getting roster from games: " . $e->getMessage());
            return [];
        }
    }
}
?>