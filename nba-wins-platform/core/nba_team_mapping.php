<?php
/**
 * NBA Team Name to API Team ID Mapping
 * Maps database team names to NBA API team IDs
 */

class NBATeamMapper {
    private static $teamMapping = [
        // Eastern Conference
        'Atlanta Hawks' => 1610612737,
        'Boston Celtics' => 1610612738,
        'Brooklyn Nets' => 1610612751,
        'Charlotte Hornets' => 1610612766,
        'Chicago Bulls' => 1610612741,
        'Cleveland Cavaliers' => 1610612739,
        'Detroit Pistons' => 1610612765,
        'Indiana Pacers' => 1610612754,
        'Miami Heat' => 1610612748,
        'Milwaukee Bucks' => 1610612749,
        'New York Knicks' => 1610612752,
        'Orlando Magic' => 1610612753,
        'Philadelphia 76ers' => 1610612755,
        'Toronto Raptors' => 1610612761,
        'Washington Wizards' => 1610612764,
        
        // Western Conference
        'Dallas Mavericks' => 1610612742,
        'Denver Nuggets' => 1610612743,
        'Golden State Warriors' => 1610612744,
        'Houston Rockets' => 1610612745,
        'Los Angeles Clippers' => 1610612746,
        'Los Angeles Lakers' => 1610612747,
        'Memphis Grizzlies' => 1610612763,
        'Minnesota Timberwolves' => 1610612750,
        'New Orleans Pelicans' => 1610612740,
        'Oklahoma City Thunder' => 1610612760,
        'Phoenix Suns' => 1610612756,
        'Portland Trail Blazers' => 1610612757,
        'Sacramento Kings' => 1610612758,
        'San Antonio Spurs' => 1610612759,
        'Utah Jazz' => 1610612762
    ];
    
    private static $abbreviationMapping = [
        'ATL' => 'Atlanta Hawks',
        'BOS' => 'Boston Celtics',
        'BKN' => 'Brooklyn Nets',
        'CHA' => 'Charlotte Hornets',
        'CHI' => 'Chicago Bulls',
        'CLE' => 'Cleveland Cavaliers',
        'DET' => 'Detroit Pistons',
        'IND' => 'Indiana Pacers',
        'MIA' => 'Miami Heat',
        'MIL' => 'Milwaukee Bucks',
        'NYK' => 'New York Knicks',
        'ORL' => 'Orlando Magic',
        'PHI' => 'Philadelphia 76ers',
        'TOR' => 'Toronto Raptors',
        'WAS' => 'Washington Wizards',
        'DAL' => 'Dallas Mavericks',
        'DEN' => 'Denver Nuggets',
        'GSW' => 'Golden State Warriors',
        'HOU' => 'Houston Rockets',
        'LAC' => 'Los Angeles Clippers',
        'LAL' => 'Los Angeles Lakers',
        'MEM' => 'Memphis Grizzlies',
        'MIN' => 'Minnesota Timberwolves',
        'NOP' => 'New Orleans Pelicans',
        'OKC' => 'Oklahoma City Thunder',
        'PHX' => 'Phoenix Suns',
        'POR' => 'Portland Trail Blazers',
        'SAC' => 'Sacramento Kings',
        'SAS' => 'San Antonio Spurs',
        'UTA' => 'Utah Jazz'
    ];
    
    /**
     * Get NBA API team ID from team name
     */
    public static function getTeamId($teamName) {
        // Direct name lookup
        if (isset(self::$teamMapping[$teamName])) {
            return self::$teamMapping[$teamName];
        }
        
        // Try abbreviation lookup
        if (isset(self::$abbreviationMapping[$teamName])) {
            $fullName = self::$abbreviationMapping[$teamName];
            return self::$teamMapping[$fullName];
        }
        
        // Try partial matching
        foreach (self::$teamMapping as $name => $id) {
            if (stripos($name, $teamName) !== false || stripos($teamName, $name) !== false) {
                return $id;
            }
        }
        
        return null;
    }
    
    /**
     * Get team name from NBA API team ID
     */
    public static function getTeamName($teamId) {
        return array_search($teamId, self::$teamMapping);
    }
    
    /**
     * Get all team mappings
     */
    public static function getAllMappings() {
        return self::$teamMapping;
    }
    
    /**
     * Validate if team exists
     */
    public static function teamExists($teamName) {
        return self::getTeamId($teamName) !== null;
    }
    
    /**
     * Get team abbreviation from full name
     */
    public static function getAbbreviation($teamName) {
        return array_search($teamName, self::$abbreviationMapping);
    }
}
?>