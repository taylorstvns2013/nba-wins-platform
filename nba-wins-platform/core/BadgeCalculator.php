<?php
/**
 * BadgeCalculator.php - Gamification Badge Engine
 * 
 * Calculates, stores, and retrieves achievement badges for league participants.
 * All badges are league-specific. Repeatable badges track each instance (dates/details).
 * 
 * DB Table Required:
 *   CREATE TABLE IF NOT EXISTS user_badges (
 *       id INT AUTO_INCREMENT PRIMARY KEY,
 *       user_id INT NOT NULL,
 *       league_id INT NOT NULL,
 *       badge_key VARCHAR(50) NOT NULL,
 *       earned_at DATETIME NOT NULL,
 *       times_earned INT DEFAULT 1,
 *       metadata JSON NULL,
 *       UNIQUE KEY unique_badge (user_id, league_id, badge_key),
 *       INDEX idx_user_league (user_id, league_id)
 *   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 * 
 * Path: /data/www/default/nba-wins-platform/core/BadgeCalculator.php
 */

class BadgeCalculator {

    private $pdo;
    private $season;

    // =========================================================================
    // BADGE REGISTRY
    // key => [name, desc, icon (FA class), color (hex), repeatable]
    // =========================================================================
    public const BADGE_DEFINITIONS = [

        // --- Win Streaks ---
        'win_streak_5'   => [
            'name'       => 'Heating Up',
            'desc'       => 'Achieved a 5-game win streak',
            'icon'       => 'fa-fire',
            'color'      => '#f59e0b',
            'glow'       => 'rgba(245, 158, 11, 0.4)',
            'repeatable' => true,
            'category'   => 'streaks',
        ],
        'win_streak_10'  => [
            'name'       => 'On Fire',
            'desc'       => 'Achieved a 10-game win streak',
            'icon'       => 'fa-fire-flame-curved',
            'color'      => '#ef4444',
            'glow'       => 'rgba(239, 68, 68, 0.4)',
            'repeatable' => true,
            'category'   => 'streaks',
        ],
        'win_streak_15'  => [
            'name'       => 'Unstoppable',
            'desc'       => 'Achieved a 15-game win streak',
            'icon'       => 'fa-bolt',
            'color'      => '#a855f7',
            'glow'       => 'rgba(168, 85, 247, 0.4)',
            'repeatable' => true,
            'category'   => 'streaks',
        ],

        // --- Loss Streaks ---
        'loss_streak_5'  => [
            'name'       => 'Cooling Off',
            'desc'       => 'Hit a 5-game losing streak',
            'icon'       => 'fa-snowflake',
            'color'      => '#93c5fd',
            'glow'       => 'rgba(147, 197, 253, 0.4)',
            'repeatable' => true,
            'category'   => 'streaks',
        ],
        'loss_streak_10' => [
            'name'       => 'Ice Cold',
            'desc'       => 'Hit a 10-game losing streak',
            'icon'       => 'fa-icicles',
            'color'      => '#60a5fa',
            'glow'       => 'rgba(96, 165, 250, 0.4)',
            'repeatable' => true,
            'category'   => 'streaks',
        ],
        'loss_streak_15' => [
            'name'       => 'Cooked',
            'desc'       => 'Hit a 15-game losing streak',
            'icon'       => 'fa-skull',
            'color'      => '#6b7280',
            'glow'       => 'rgba(107, 114, 128, 0.4)',
            'repeatable' => true,
            'category'   => 'streaks',
        ],

        // --- Weekly Dominance ---
        'week_dominator' => [
            'name'       => 'Week Dominator',
            'desc'       => 'Had the most wins in the league for a calendar week',
            'icon'       => 'fa-crown',
            'color'      => '#f59e0b',
            'glow'       => 'rgba(245, 158, 11, 0.4)',
            'repeatable' => true,
            'category'   => 'weekly',
        ],
        'perfect_week'   => [
            'name'       => 'Perfect Week',
            'desc'       => 'Won every game in a week (minimum 5 games)',
            'icon'       => 'fa-star',
            'color'      => '#22d3ee',
            'glow'       => 'rgba(34, 211, 238, 0.4)',
            'repeatable' => true,
            'category'   => 'weekly',
        ],

        // --- Win Milestones ---
        'wins_100'       => [
            'name'       => 'Century Club',
            'desc'       => 'Accumulated 100 total wins',
            'icon'       => 'fa-trophy',
            'color'      => '#fbbf24',
            'glow'       => 'rgba(251, 191, 36, 0.4)',
            'repeatable' => false,
            'category'   => 'milestones',
        ],
        'wins_200'       => [
            'name'       => 'Double Century',
            'desc'       => 'Accumulated 200 total wins',
            'icon'       => 'fa-trophy',
            'color'      => '#d1d5db',
            'glow'       => 'rgba(209, 213, 219, 0.4)',
            'repeatable' => false,
            'category'   => 'milestones',
        ],
        'wins_300'       => [
            'name'       => 'Triple Threat',
            'desc'       => 'Accumulated 300 total wins',
            'icon'       => 'fa-trophy',
            'color'      => '#c084fc',
            'glow'       => 'rgba(192, 132, 252, 0.4)',
            'repeatable' => false,
            'category'   => 'milestones',
        ],

        // --- Weeks in the Lead ---
        'weeks_lead_10'  => [
            'name'       => 'Floor General',
            'desc'       => 'Led the league for 5 weeks (35 days in first)',
            'icon'       => 'fa-award',
            'color'      => '#f59e0b',
            'glow'       => 'rgba(245, 158, 11, 0.4)',
            'repeatable' => false,
            'category'   => 'milestones',
        ],
        'weeks_lead_15'  => [
            'name'       => 'Top Dog',
            'desc'       => 'Led the league for 10 weeks (70 days in first)',
            'icon'       => 'fa-certificate',
            'color'      => '#f97316',
            'glow'       => 'rgba(249, 115, 22, 0.4)',
            'repeatable' => false,
            'category'   => 'milestones',
        ],
        'weeks_lead_20'  => [
            'name'       => 'Running the League',
            'desc'       => 'Led the league for 15 weeks (105 days in first)',
            'icon'       => 'fa-medal',
            'color'      => '#a855f7',
            'glow'       => 'rgba(168, 85, 247, 0.4)',
            'repeatable' => false,
            'category'   => 'milestones',
        ],

        // --- Comeback ---
        'comeback_20'    => [
            'name'       => 'Why Do I Do This',
            'desc'       => 'Reached first place after trailing the leader by 20+ wins',
            'icon'       => 'fa-person-running',
            'color'      => '#10b981',
            'glow'       => 'rgba(16, 185, 129, 0.4)',
            'repeatable' => false,
            'category'   => 'performance',
        ],

        // --- Win Rate ---
        'elite_roster'   => [
            'name'       => 'Elite Roster',
            'desc'       => 'Currently maintaining 55%+ win rate with 50+ games played (can come and go)',
            'icon'       => 'fa-chart-line',
            'color'      => '#3b82f6',
            'glow'       => 'rgba(59, 130, 246, 0.4)',
            'repeatable' => false,
            'category'   => 'performance',
        ],
        'hot_hand'       => [
            'name'       => 'Hot Hand',
            'desc'       => 'Won 75%+ of games in a single week (min 5 games)',
            'icon'       => 'fa-hand-sparkles',
            'color'      => '#f97316',
            'glow'       => 'rgba(249, 115, 22, 0.4)',
            'repeatable' => true,
            'category'   => 'performance',
        ],

        // --- Head-to-Head ---
        'bully_5'        => [
            'name'       => 'Bully I',
            'desc'       => '5 consecutive head-to-head wins against a single opponent',
            'icon'       => 'fa-hand-fist',
            'color'      => '#10b981',
            'glow'       => 'rgba(16, 185, 129, 0.4)',
            'repeatable' => true,
            'category'   => 'rivals',
        ],
        'bully_10'       => [
            'name'       => 'Bully II',
            'desc'       => '10 consecutive head-to-head wins against a single opponent',
            'icon'       => 'fa-face-angry',
            'color'      => '#f97316',
            'glow'       => 'rgba(249, 115, 22, 0.4)',
            'repeatable' => true,
            'category'   => 'rivals',
        ],
        'bully_15'       => [
            'name'       => 'Bully III',
            'desc'       => '15 consecutive head-to-head wins against a single opponent',
            'icon'       => 'fa-skull-crossbones',
            'color'      => '#ef4444',
            'glow'       => 'rgba(239, 68, 68, 0.4)',
            'repeatable' => true,
            'category'   => 'rivals',
        ],
        'rivalmaster'    => [
            'name'       => 'Rival Master',
            'desc'       => 'Led a single opponent by 15+ wins in head-to-head matchups',
            'icon'       => 'fa-crosshairs',
            'color'      => '#a855f7',
            'glow'       => 'rgba(168, 85, 247, 0.4)',
            'repeatable' => false,
            'category'   => 'rivals',
        ],

        // --- Draft & Team ---
        'sleeper_pick'   => [
            'name'       => 'Sleeper Pick',
            'desc'       => 'Your best-performing team was drafted in round 4 or later',
            'icon'       => 'fa-bed',
            'color'      => '#8b5cf6',
            'glow'       => 'rgba(139, 92, 246, 0.4)',
            'repeatable' => false,
            'category'   => 'draft',
        ],
        'clean_sweep'    => [
            'name'       => 'Clean Sweep',
            'desc'       => 'Every one of your drafted teams is above .500',
            'icon'       => 'fa-broom',
            'color'      => '#10b981',
            'glow'       => 'rgba(16, 185, 129, 0.4)',
            'repeatable' => false,
            'category'   => 'draft',
        ],

        // --- Profile ---
        'loyal_fan'      => [
            'name'       => 'Loyal Fan',
            'desc'       => 'Uploaded a profile photo',
            'icon'       => 'fa-camera',
            'color'      => '#06b6d4',
            'glow'       => 'rgba(6, 182, 212, 0.4)',
            'repeatable' => false,
            'category'   => 'profile',
        ],
    ];

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================
    public function __construct($pdo, $season) {
        $this->pdo    = $pdo;
        $this->season = $season;
        $this->ensureTableExists();
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Run all badge calculations and persist results.
     * Safe to call on every profile page load — uses INSERT ... ON DUPLICATE KEY UPDATE.
     */
    public function calculateAndStoreBadges($user_id, $league_id, $participant_id, $profile_photo, $standings_wins = null, $standings_losses = null) {
        $teams    = $this->getParticipantTeams($participant_id);
        $teamNames = array_column($teams, 'team_name');

        if (empty($teamNames)) return;

        // Streak badges — walk full game history
        $allGames = $this->getAllGames($teamNames);
        $streakBadges = $this->calculateStreakBadges($allGames);
        foreach ($streakBadges as $key => $data) {
            if ($key === '_current') continue; // internal tracking only
            if ($data['times'] > 0) {
                $this->storeBadge($user_id, $league_id, $key, $data['earned_at'], $data['times'], $data['metadata'], true);
            }
        }

        // Weekly badges (Week Dominator, Perfect Week, Hot Hand)
        $weeklyBadges = $this->calculateWeeklyBadges($participant_id, $league_id, $teamNames);
        foreach ($weeklyBadges as $key => $data) {
            if ($data['times'] > 0) {
                $this->storeBadge($user_id, $league_id, $key, $data['earned_at'], $data['times'], $data['metadata'], true);
            }
        }

        // Win milestones — find exact date from league_participant_daily_wins
        $totalWins = $this->getTotalWins($teamNames);
        foreach ([100 => 'wins_100', 200 => 'wins_200', 300 => 'wins_300'] as $threshold => $key) {
            if ($totalWins >= $threshold) {
                $milestoneDate = $this->getMilestoneDateFromDailyWins($participant_id, $threshold);
                $this->storeBadge($user_id, $league_id, $key, $milestoneDate, 1, ['threshold' => $threshold, 'achieved_date' => $milestoneDate]);
            }
        }

        // Elite Roster — can come and go, delete row if no longer qualifying
        // Use standings-based totals if passed in (matches profile header exactly)
        if ($standings_wins !== null && $standings_losses !== null) {
            $er_wins  = (int)$standings_wins;
            $er_total = (int)$standings_wins + (int)$standings_losses;
        } else {
            $stats    = $this->getWinRateStats($teamNames);
            $er_wins  = $stats['wins'];
            $er_total = $stats['total'];
        }
        if ($er_total >= 50 && ($er_wins / $er_total) >= 0.55) {
            $this->storeBadge($user_id, $league_id, 'elite_roster', date('Y-m-d H:i:s'), 1, [
                'win_pct' => round($er_wins / $er_total * 100, 1),
                'games'   => $er_total,
            ]);
        } else {
            $this->deleteBadge($user_id, $league_id, 'elite_roster');
        }

        // Head-to-head badges
        $h2hBadges = $this->calculateH2HBadges($participant_id, $league_id, $teamNames);
        foreach (['bully_5', 'bully_10', 'bully_15'] as $key) {
            if (isset($h2hBadges[$key])) {
                $this->storeBadge($user_id, $league_id, $key,
                    $h2hBadges[$key]['earned_at'],
                    $h2hBadges[$key]['times'],
                    $h2hBadges[$key]['metadata'] ?? null,
                    true
                );
            }
        }
        if (isset($h2hBadges['rivalmaster'])) {
            $this->storeBadge($user_id, $league_id, 'rivalmaster', date('Y-m-d H:i:s'), 1, null);
        }

        // Draft/team badges
        foreach ($this->calculateDraftBadges($teams) as $key) {
            $this->storeBadge($user_id, $league_id, $key, date('Y-m-d H:i:s'), 1, null);
        }

        // Profile badge
        if (!empty($profile_photo)) {
            $this->storeBadge($user_id, $league_id, 'loyal_fan', date('Y-m-d H:i:s'), 1, null);
        }

        // Weeks in the lead
        $weeksLed = $this->calculateWeeksInLead($participant_id, $league_id);
        foreach ([5 => 'weeks_lead_10', 10 => 'weeks_lead_15', 15 => 'weeks_lead_20'] as $threshold => $key) {
            if ($weeksLed['count'] >= $threshold) {
                $earnedDate = $weeksLed['threshold_dates'][$threshold] ?? date('Y-m-d');
                $this->storeBadge($user_id, $league_id, $key, $earnedDate, 1, [
                    'weeks_led'      => $weeksLed['count'],
                    'days_led'       => $weeksLed['days'],
                    'achieved_date'  => $earnedDate,
                ]);
            } else {
                $this->deleteBadge($user_id, $league_id, $key);
            }
        }

        // Comeback: reached first place after trailing by 20+ wins
        $comeback = $this->calculateComeback20($participant_id, $league_id);
        if ($comeback['achieved']) {
            $this->storeBadge($user_id, $league_id, 'comeback_20', $comeback['date'], 1, [
                'achieved_date' => $comeback['date'],
                'max_deficit'   => $comeback['max_deficit'],
            ]);
        } else {
            $this->deleteBadge($user_id, $league_id, 'comeback_20');
        }
    }

    /**
     * Fetch all stored badges for a user in a league.
     * Returns associative array: badge_key => [earned_at, times_earned, metadata]
     */
    public function getBadges($user_id, $league_id) {
        $stmt = $this->pdo->prepare("
            SELECT badge_key, earned_at, times_earned, metadata
            FROM user_badges
            WHERE user_id = ? AND league_id = ?
        ");
        $stmt->execute([$user_id, $league_id]);
        $result = [];
        while ($row = $stmt->fetch()) {
            $result[$row['badge_key']] = [
                'earned_at'    => $row['earned_at'],
                'times_earned' => (int)$row['times_earned'],
                'metadata'     => $row['metadata'] ? json_decode($row['metadata'], true) : null,
            ];
        }
        return $result;
    }

    /**
     * Returns current progress values for trackable badges.
     * Used to show progress bars on locked badges.
     * Keys: badge_key => ['current' => N, 'target' => N, 'label' => string]
     */
    public function getProgress($participant_id, $league_id, $earned_badges, $standings_wins = null, $standings_losses = null) {
        $teams     = $this->getParticipantTeams($participant_id);
        $teamNames = array_column($teams, 'team_name');
        $progress  = [];

        // Win milestones — use standings-based wins if available
        $totalWins = ($standings_wins !== null) ? (int)$standings_wins : $this->getTotalWins($teamNames);
        foreach ([100 => 'wins_100', 200 => 'wins_200', 300 => 'wins_300'] as $target => $key) {
            if (!isset($earned_badges[$key])) {
                $progress[$key] = [
                    'current' => $totalWins,
                    'target'  => $target,
                    'label'   => $totalWins . ' / ' . $target . ' wins',
                ];
            }
        }

        // Win/loss streaks — always populate ALL entries so profile can pick
        // the correct target badge based on current streak range
        $allGames     = $this->getAllGames($teamNames);
        $streakBadges = $this->calculateStreakBadges($allGames);
        $curStreak    = $streakBadges['_current']['streak'] ?? 0;
        $curType      = $streakBadges['_current']['type'] ?? null;
        $curWinStreak  = ($curType === 'W') ? $curStreak : 0;
        $curLossStreak = ($curType === 'L') ? $curStreak : 0;

        foreach ([5 => 'win_streak_5', 10 => 'win_streak_10', 15 => 'win_streak_15'] as $target => $key) {
            $progress[$key] = [
                'current' => $curWinStreak,
                'target'  => $target,
                'label'   => $curWinStreak . ' / ' . $target,
            ];
        }
        foreach ([5 => 'loss_streak_5', 10 => 'loss_streak_10', 15 => 'loss_streak_15'] as $target => $key) {
            $progress[$key] = [
                'current' => $curLossStreak,
                'target'  => $target,
                'label'   => $curLossStreak . ' / ' . $target,
            ];
        }

        // Bully streaks — always populate all entries
        $h2hBadges   = $this->calculateH2HBadges($participant_id, $league_id, $teamNames);
        $curBully     = $h2hBadges['_current_bully'] ?? 0;
        $curBullyOpp  = $h2hBadges['_current_bully_opp_name'] ?? null;
        foreach ([5 => 'bully_5', 10 => 'bully_10', 15 => 'bully_15'] as $target => $key) {
            $progress[$key] = [
                'current'  => $curBully,
                'target'   => $target,
                'label'    => $curBully . ' / ' . $target . ($curBullyOpp ? ' vs ' . $curBullyOpp : ''),
                'opp_name' => $curBullyOpp,
            ];
        }

        // Weeks in lead
        $weeksLed = $this->calculateWeeksInLead($participant_id, $league_id);
        foreach ([5 => 'weeks_lead_10', 10 => 'weeks_lead_15', 15 => 'weeks_lead_20'] as $target => $key) {
            if (!isset($earned_badges[$key])) {
                $progress[$key] = [
                    'current' => $weeksLed['count'],
                    'target'  => $target,
                    'label'   => $weeksLed['count'] . ' / ' . $target . ' weeks (' . $weeksLed['days'] . ' days in first)',
                ];
            }
        }

        // Comeback 20 — show max deficit seen so far
        if (!isset($earned_badges['comeback_20'])) {
            $comeback = $this->calculateComeback20($participant_id, $league_id);
            $deficit  = $comeback['max_deficit'] ?? 0;
            $progress['comeback_20'] = [
                'current' => $deficit,
                'target'  => 20,
                'label'   => $deficit >= 20
                    ? 'Max deficit reached — waiting to claim first'
                    : 'Max deficit: ' . $deficit . ' wins behind (need 20+)',
            ];
        }

        return $progress;
    }

    // =========================================================================
    // PRIVATE — DATA HELPERS
    // =========================================================================

    private function ensureTableExists() {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS user_badges (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                user_id      INT NOT NULL,
                league_id    INT NOT NULL,
                badge_key    VARCHAR(50) NOT NULL,
                earned_at    DATETIME NOT NULL,
                times_earned INT DEFAULT 1,
                metadata     JSON NULL,
                UNIQUE KEY unique_badge (user_id, league_id, badge_key),
                INDEX idx_user_league (user_id, league_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function getParticipantTeams($participant_id) {
        $stmt = $this->pdo->prepare("
            SELECT team_name, draft_round, draft_pick_number
            FROM league_participant_teams
            WHERE league_participant_id = ?
        ");
        $stmt->execute([$participant_id]);
        return $stmt->fetchAll();
    }

    private function getParticipantTeamNames($participant_id) {
        $stmt = $this->pdo->prepare("
            SELECT team_name FROM league_participant_teams WHERE league_participant_id = ?
        ");
        $stmt->execute([$participant_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function getAllGames($teamNames) {
        $ph = $this->placeholders($teamNames);
        $stmt = $this->pdo->prepare("
            SELECT
                g.date,
                CASE
                    WHEN g.home_team IN ($ph) AND g.home_points > g.away_points THEN 'W'
                    WHEN g.away_team IN ($ph) AND g.away_points > g.home_points THEN 'W'
                    ELSE 'L'
                END AS result
            FROM games g
            WHERE (g.home_team IN ($ph) OR g.away_team IN ($ph))
              AND g.status_long IN ('Final', 'Finished')
              AND g.date >= ?
            ORDER BY g.date ASC, g.start_time ASC
        ");
        $stmt->execute(array_merge($teamNames, $teamNames, $teamNames, $teamNames, [$this->season['season_start_date']]));
        return $stmt->fetchAll();
    }

    private function getTotalWins($teamNames) {
        $ph = $this->placeholders($teamNames);
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(
                CASE
                    WHEN g.home_team IN ($ph) AND g.home_points > g.away_points THEN 1
                    WHEN g.away_team IN ($ph) AND g.away_points > g.home_points THEN 1
                    ELSE 0
                END
            ), 0) AS wins
            FROM games g
            WHERE (g.home_team IN ($ph) OR g.away_team IN ($ph))
              AND g.status_long IN ('Final', 'Finished')
              AND g.date >= ?
        ");
        $stmt->execute(array_merge($teamNames, $teamNames, $teamNames, $teamNames, [$this->season['season_start_date']]));
        return (int)$stmt->fetchColumn();
    }

    private function getWinRateStats($teamNames) {
        $ph = $this->placeholders($teamNames);
        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(SUM(CASE
                    WHEN g.home_team IN ($ph) AND g.home_points > g.away_points THEN 1
                    WHEN g.away_team IN ($ph) AND g.away_points > g.home_points THEN 1
                    ELSE 0
                END), 0) AS wins,
                COUNT(*) AS total
            FROM games g
            WHERE (g.home_team IN ($ph) OR g.away_team IN ($ph))
              AND g.status_long IN ('Final', 'Finished')
              AND g.date >= ?
        ");
        $stmt->execute(array_merge($teamNames, $teamNames, $teamNames, $teamNames, [$this->season['season_start_date']]));
        $r = $stmt->fetch();
        return ['wins' => (int)$r['wins'], 'total' => (int)$r['total']];
    }

    private function getLast20Stats($teamNames) {
        $ph = $this->placeholders($teamNames);
        $stmt = $this->pdo->prepare("
            SELECT CASE
                WHEN g.home_team IN ($ph) AND g.home_points > g.away_points THEN 'W'
                WHEN g.away_team IN ($ph) AND g.away_points > g.home_points THEN 'W'
                ELSE 'L'
            END AS result
            FROM games g
            WHERE (g.home_team IN ($ph) OR g.away_team IN ($ph))
              AND g.status_long IN ('Final', 'Finished')
              AND g.date >= ?
            ORDER BY g.date DESC, g.start_time DESC
            LIMIT 20
        ");
        $stmt->execute(array_merge($teamNames, $teamNames, $teamNames, $teamNames, [$this->season['season_start_date']]));
        $rows = $stmt->fetchAll();
        $wins = count(array_filter($rows, function($r) { return $r["result"] === "W"; }));
        return ['wins' => $wins, 'total' => count($rows)];
    }

    private function placeholders($arr) {
        return implode(',', array_fill(0, count($arr), '?'));
    }

    // =========================================================================
    // PRIVATE — BADGE CALCULATORS
    // =========================================================================

    /**
     * Walk full game history to detect ALL streak milestones, not just the current one.
     * Awards once per streak crossing each threshold within a continuous run.
     * Also returns current_streak and current_type for live streak display.
     */
    private function calculateStreakBadges($allGames) {
        $badges = [];
        foreach (['win_streak_5','win_streak_10','win_streak_15','loss_streak_5','loss_streak_10','loss_streak_15'] as $k) {
            $badges[$k] = ['times' => 0, 'earned_at' => null, 'metadata' => ['instances' => []]];
        }

        $currentStreak = 0;
        $currentType   = null;
        $awardedInRun  = [];

        foreach ($allGames as $game) {
            $r = $game['result'];

            if ($r === $currentType) {
                $currentStreak++;
            } else {
                $currentStreak = 1;
                $currentType   = $r;
                $awardedInRun  = [];
            }

            $thresholds = ($r === 'W')
                ? [5 => 'win_streak_5', 10 => 'win_streak_10', 15 => 'win_streak_15']
                : [5 => 'loss_streak_5', 10 => 'loss_streak_10', 15 => 'loss_streak_15'];

            foreach ($thresholds as $threshold => $key) {
                if ($currentStreak >= $threshold && empty($awardedInRun[$key])) {
                    $badges[$key]['times']++;
                    $badges[$key]['earned_at'] = $game['date'];
                    $badges[$key]['metadata']['instances'][] = [
                        'date'   => $game['date'],
                        'streak' => $currentStreak,
                    ];
                    $awardedInRun[$key] = true;
                }
            }
        }

        // Attach current live streak to any relevant earned badges
        if ($currentStreak > 0 && $currentType !== null) {
            $activeKeys = ($currentType === 'W')
                ? ['win_streak_5', 'win_streak_10', 'win_streak_15']
                : ['loss_streak_5', 'loss_streak_10', 'loss_streak_15'];
            foreach ($activeKeys as $key) {
                $badges[$key]['metadata']['current_streak'] = $currentStreak;
                $badges[$key]['metadata']['current_type']   = $currentType;
            }
        }

        // Store overall current streak for progress tracking on unearned badges
        $badges['_current'] = ['streak' => $currentStreak, 'type' => $currentType];

        return $badges;
    }

    /**
     * Weekly Dominator: most wins in the league for a week.
     * Perfect Week: all games won in a week (min 5 games).
     */
    private function calculateWeeklyBadges($participant_id, $league_id, $teamNames) {
        $badges = [
            'week_dominator' => ['times' => 0, 'earned_at' => null, 'metadata' => ['instances' => []]],
            'perfect_week'   => ['times' => 0, 'earned_at' => null, 'metadata' => ['instances' => []]],
            'hot_hand'       => ['times' => 0, 'earned_at' => null, 'metadata' => ['instances' => []]],
        ];

        if (empty($teamNames)) return $badges;

        // Build weekly totals for ALL participants in one pass
        $stmt = $this->pdo->prepare("
            SELECT lp.id AS participant_id
            FROM league_participants lp
            WHERE lp.league_id = ? AND lp.status = 'active'
        ");
        $stmt->execute([$league_id]);
        $allParticipantIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // $weeklyWins[yearweek][participant_id] = ['wins' => x, 'total' => y, 'week_start' => date]
        $weeklyWins = [];
        foreach ($allParticipantIds as $pid) {
            $pTeams = $this->getParticipantTeamNames($pid);
            if (empty($pTeams)) continue;
            $ph = $this->placeholders($pTeams);
            $wStmt = $this->pdo->prepare("
                SELECT
                    YEARWEEK(g.date, 1) AS yr_week,
                    MIN(g.date)         AS week_start,
                    SUM(CASE
                        WHEN g.home_team IN ($ph) AND g.home_points > g.away_points THEN 1
                        WHEN g.away_team IN ($ph) AND g.away_points > g.home_points THEN 1
                        ELSE 0
                    END) AS wins,
                    COUNT(*) AS total_games
                FROM games g
                WHERE (g.home_team IN ($ph) OR g.away_team IN ($ph))
                  AND g.status_long IN ('Final', 'Finished')
                  AND g.date >= ?
                GROUP BY YEARWEEK(g.date, 1)
            ");
            $wStmt->execute(array_merge($pTeams, $pTeams, $pTeams, $pTeams, [$this->season['season_start_date']]));
            foreach ($wStmt->fetchAll() as $row) {
                $yw = $row['yr_week'];
                if (!isset($weeklyWins[$yw])) $weeklyWins[$yw] = [];
                $weeklyWins[$yw][$pid] = [
                    'wins'       => (int)$row['wins'],
                    'total'      => (int)$row['total_games'],
                    'week_start' => $row['week_start'],
                ];
            }
        }

        // Evaluate this participant's weeks
        foreach ($weeklyWins as $yw => $participantData) {
            if (!isset($participantData[$participant_id])) continue;

            $myWins  = $participantData[$participant_id]['wins'];
            $myTotal = $participantData[$participant_id]['total'];
            $weekStart = $participantData[$participant_id]['week_start'];

            // Week Dominator: my wins >= all others' wins (ties count)
            $maxOtherWins = 0;
            foreach ($participantData as $pid => $data) {
                if ($pid != $participant_id && $data['wins'] > $maxOtherWins) {
                    $maxOtherWins = $data['wins'];
                }
            }
            if ($myWins > 0 && $myWins >= $maxOtherWins) {
                $badges['week_dominator']['times']++;
                $badges['week_dominator']['earned_at'] = $weekStart;
                $badges['week_dominator']['metadata']['instances'][] = [
                    'week' => $weekStart,
                    'wins' => $myWins,
                ];
            }

            // Perfect Week: won every game, minimum 5
            if ($myTotal >= 5 && $myWins === $myTotal) {
                $badges['perfect_week']['times']++;
                $badges['perfect_week']['earned_at'] = $weekStart;
                $badges['perfect_week']['metadata']['instances'][] = [
                    'week' => $weekStart,
                    'wins' => $myWins,
                ];
            }

            // Hot Hand: 75%+ win rate in a week with at least 5 games
            if ($myTotal >= 5 && ($myWins / $myTotal) >= 0.75) {
                $badges['hot_hand']['times']++;
                $badges['hot_hand']['earned_at'] = $weekStart;
                $badges['hot_hand']['metadata']['instances'][] = [
                    'week' => $weekStart,
                    'wins' => $myWins,
                    'total'=> $myTotal,
                ];
            }
        }

        return $badges;
    }

    /**
     * Bully I/II/III: detect consecutive H2H win streaks vs a single opponent (5/10/15).
     *   - Walk all H2H games vs each opponent in chronological order.
     *   - Count the longest streak of consecutive wins within that sequence.
     *   - Also count how many times each threshold was crossed (repeatable).
     *
     * Rival Master: win differential vs a single opponent >= 15
     *   (your H2H wins minus their H2H wins against you >= 15).
     */
    private function calculateH2HBadges($participant_id, $league_id, $myTeams) {
        $earned = [];
        if (empty($myTeams)) return $earned;

        $stmt = $this->pdo->prepare("
            SELECT lp.id AS participant_id
            FROM league_participants lp
            WHERE lp.league_id = ? AND lp.id != ? AND lp.status = 'active'
        ");
        $stmt->execute([$league_id, $participant_id]);
        $opponents = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($opponents)) return $earned;

        $myPh = $this->placeholders($myTeams);

        // Track per-threshold times earned for bully badges
        $bullyTimes = ['bully_5' => 0, 'bully_10' => 0, 'bully_15' => 0];
        $bullyEarnedAt = ['bully_5' => null, 'bully_10' => null, 'bully_15' => null];
        $rivalMasterEarned = false;
        $maxCurrentBullyStreak = 0; // highest active bully streak vs any opponent
        $maxCurrentBullyOppId  = null; // opponent that streak is against

        foreach ($opponents as $oppId) {
            $oppTeams = $this->getParticipantTeamNames($oppId);
            if (empty($oppTeams)) continue;
            $oppPh = $this->placeholders($oppTeams);

            // Fetch all direct H2H games in chronological order
            $stmt = $this->pdo->prepare("
                SELECT
                    g.date,
                    CASE
                        WHEN g.home_team IN ($myPh) AND g.away_team IN ($oppPh) AND g.home_points > g.away_points THEN 'W'
                        WHEN g.away_team IN ($myPh) AND g.home_team IN ($oppPh) AND g.away_points > g.home_points THEN 'W'
                        ELSE 'L'
                    END AS result
                FROM games g
                WHERE g.status_long IN ('Final', 'Finished')
                  AND g.date >= ?
                  AND (
                      (g.home_team IN ($myPh) AND g.away_team IN ($oppPh))
                      OR
                      (g.away_team IN ($myPh) AND g.home_team IN ($oppPh))
                  )
                ORDER BY g.date ASC, g.start_time ASC
            ");
            $stmt->execute(array_merge(
                $myTeams, $oppTeams,
                $myTeams, $oppTeams,
                [$this->season['season_start_date']],
                $myTeams, $oppTeams,
                $myTeams, $oppTeams
            ));
            $h2hGames = $stmt->fetchAll();

            if (empty($h2hGames)) continue;

            // --- Bully streak detection (repeatable, per opponent) ---
            $currentStreak = 0;
            $awardedInRun  = [];

            foreach ($h2hGames as $game) {
                if ($game['result'] === 'W') {
                    $currentStreak++;
                } else {
                    $currentStreak = 0;
                    $awardedInRun  = [];
                }

                foreach ([5 => 'bully_5', 10 => 'bully_10', 15 => 'bully_15'] as $threshold => $key) {
                    if ($currentStreak >= $threshold && empty($awardedInRun[$key])) {
                        $bullyTimes[$key]++;
                        $bullyEarnedAt[$key] = $game['date'];
                        $awardedInRun[$key]  = true;
                    }
                }
            }

            // Track the highest current active bully streak vs any single opponent
            if ($currentStreak > $maxCurrentBullyStreak) {
                $maxCurrentBullyStreak = $currentStreak;
                $maxCurrentBullyOppId  = $oppId;
            }

            // --- Rival Master: win differential >= 15 ---
            $myWins  = count(array_filter($h2hGames, function($g) { return $g["result"] === "W"; }));
            $theirWins = count($h2hGames) - $myWins;
            if (($myWins - $theirWins) >= 15) {
                $rivalMasterEarned = true;
            }
        }

        // Resolve the opponent name for the active bully streak
        $maxCurrentBullyOppName = null;
        if ($maxCurrentBullyOppId) {
            $stmt = $this->pdo->prepare("
                SELECT u.display_name
                FROM league_participants lp
                JOIN users u ON lp.user_id = u.id
                WHERE lp.id = ?
            ");
            $stmt->execute([$maxCurrentBullyOppId]);
            $row = $stmt->fetch();
            $maxCurrentBullyOppName = $row ? $row['display_name'] : null;
        }

        // Return earned badge data — storage handled by calculateAndStoreBadges
        foreach (['bully_5', 'bully_10', 'bully_15'] as $key) {
            if ($bullyTimes[$key] > 0) {
                $earned[$key] = [
                    'times'     => $bullyTimes[$key],
                    'earned_at' => $bullyEarnedAt[$key],
                    'metadata'  => [
                        'current_streak'   => $maxCurrentBullyStreak,
                        'current_opp_name' => $maxCurrentBullyOppName,
                    ],
                ];
            }
        }

        // Also pass the current bully streak for progress tracking on unearned badges
        $earned['_current_bully']          = $maxCurrentBullyStreak;
        $earned['_current_bully_opp_name'] = $maxCurrentBullyOppName;

        if ($rivalMasterEarned) {
            $earned['rivalmaster'] = true;
        }

        return $earned;
    }

    /**
     * Sleeper Pick: best team (by wins) was drafted round 4 or later.
     * Clean Sweep: all drafted teams are currently above .500.
     */
    private function calculateDraftBadges($teams) {
        $earned = [];
        if (empty($teams)) return $earned;

        $bestTeam    = null;
        $bestWins    = -1;
        $allAbove500 = true;

        foreach ($teams as $team) {
            $stmt = $this->pdo->prepare(
                "SELECT win, loss FROM {$this->season['standings_table']} WHERE name = ?"
            );
            $stmt->execute([$team['team_name']]);
            $standing = $stmt->fetch();

            if (!$standing) { $allAbove500 = false; continue; }

            $w = (int)$standing['win'];
            $l = (int)$standing['loss'];

            if ($w <= $l) $allAbove500 = false;

            if ($w > $bestWins) {
                $bestWins = $w;
                $bestTeam = $team;
            }
        }

        if ($bestTeam && isset($bestTeam['draft_round']) && (int)$bestTeam['draft_round'] >= 4) {
            $earned[] = 'sleeper_pick';
        }

        if ($allAbove500 && count($teams) > 0) {
            $earned[] = 'clean_sweep';
        }

        return $earned;
    }

    // =========================================================================
    // PRIVATE — STORAGE
    // =========================================================================

    /**
     * Count how many distinct calendar weeks this participant led the league
     * (had the most total_wins on any day in that week).
     */
    private function calculateWeeksInLead($participant_id, $league_id) {
        $stmt = $this->pdo->prepare("
            SELECT lp.id
            FROM league_participants lp
            WHERE lp.league_id = ?
        ");
        $stmt->execute([$league_id]);
        $allParticipantIds = array_column($stmt->fetchAll(), 'id');

        if (empty($allParticipantIds)) return ['count' => 0, 'days' => 0, 'threshold_dates' => []];

        $ph = implode(',', array_fill(0, count($allParticipantIds), '?'));

        // Get every distinct date that has data, ordered chronologically
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT date
            FROM league_participant_daily_wins
            WHERE league_participant_id IN ($ph)
              AND date >= ?
            ORDER BY date ASC
        ");
        $stmt->execute(array_merge($allParticipantIds, [$this->season['season_start_date']]));
        $dates = array_column($stmt->fetchAll(), 'date');

        $daysInFirst = 0;
        // Track first date each week threshold (5/10/15) was crossed (in days: 35/70/105)
        $thresholdDays = [35 => 5, 70 => 10, 105 => 15];
        $thresholdDates = []; // keyed by week threshold e.g. [5 => '2025-12-01']

        foreach ($dates as $date) {
            $stmt2 = $this->pdo->prepare("
                SELECT MAX(total_wins) as max_wins
                FROM league_participant_daily_wins
                WHERE date = ?
                  AND league_participant_id IN ($ph)
            ");
            $stmt2->execute(array_merge([$date], $allParticipantIds));
            $maxRow = $stmt2->fetch();
            if (!$maxRow || !$maxRow['max_wins']) continue;

            $stmt3 = $this->pdo->prepare("
                SELECT total_wins FROM league_participant_daily_wins
                WHERE date = ? AND league_participant_id = ?
            ");
            $stmt3->execute([$date, $participant_id]);
            $myRow = $stmt3->fetch();

            if ($myRow && (int)$myRow['total_wins'] >= (int)$maxRow['max_wins']) {
                $daysInFirst++;
                // Check if we just crossed a threshold for the first time
                foreach ($thresholdDays as $dayThreshold => $weekThreshold) {
                    if ($daysInFirst === $dayThreshold && !isset($thresholdDates[$weekThreshold])) {
                        $thresholdDates[$weekThreshold] = $date;
                    }
                }
            }
        }

        $weeksLed = floor($daysInFirst / 7);

        return ['count' => $weeksLed, 'days' => $daysInFirst, 'threshold_dates' => $thresholdDates];
    }

    /**
     * Check if participant ever reached first place after being 20+ wins behind the leader.
     * "Reached first place" = tied or led at any subsequent date.
     */
    private function calculateComeback20($participant_id, $league_id) {
        $stmt = $this->pdo->prepare("
            SELECT lp.id
            FROM league_participants lp
            WHERE lp.league_id = ?
        ");
        $stmt->execute([$league_id]);
        $allParticipantIds = array_column($stmt->fetchAll(), 'id');

        if (empty($allParticipantIds)) return ['achieved' => false];

        $ph = implode(',', array_fill(0, count($allParticipantIds), '?'));

        // Get daily snapshot: this participant's wins + league leader's wins
        $stmt = $this->pdo->prepare("
            SELECT
                w.date,
                w.total_wins AS my_wins,
                (SELECT MAX(w2.total_wins)
                 FROM league_participant_daily_wins w2
                 WHERE w2.date = w.date
                   AND w2.league_participant_id IN ($ph)
                ) AS leader_wins
            FROM league_participant_daily_wins w
            WHERE w.league_participant_id = ?
              AND w.date >= ?
            ORDER BY w.date ASC
        ");
        $stmt->execute(array_merge($allParticipantIds, [$participant_id, $this->season['season_start_date']]));
        $rows = $stmt->fetchAll();

        $maxDeficit = 0;
        $hadBigDeficit = false;

        foreach ($rows as $row) {
            $deficit = (int)$row['leader_wins'] - (int)$row['my_wins'];
            if ($deficit > $maxDeficit) $maxDeficit = $deficit;
            if ($deficit >= 20) $hadBigDeficit = true;

            // Once we've been 20+ behind, check if we ever tied/led
            if ($hadBigDeficit && (int)$row['my_wins'] >= (int)$row['leader_wins']) {
                return [
                    'achieved'    => true,
                    'date'        => $row['date'],
                    'max_deficit' => $maxDeficit,
                ];
            }
        }

        return ['achieved' => false, 'max_deficit' => $maxDeficit];
    }

    /**
     * Find the first date in league_participant_daily_wins where total_wins >= threshold.
     */
    private function getMilestoneDateFromDailyWins($participant_id, $threshold) {
        $stmt = $this->pdo->prepare("
            SELECT date
            FROM league_participant_daily_wins
            WHERE league_participant_id = ?
              AND total_wins >= ?
            ORDER BY date ASC
            LIMIT 1
        ");
        $stmt->execute([$participant_id, $threshold]);
        $row = $stmt->fetch();
        return $row ? $row['date'] : date('Y-m-d');
    }

    private function deleteBadge($user_id, $league_id, $badge_key) {
        $stmt = $this->pdo->prepare("
            DELETE FROM user_badges
            WHERE user_id = ? AND league_id = ? AND badge_key = ?
        ");
        $stmt->execute([$user_id, $league_id, $badge_key]);
    }

    private function storeBadge($user_id, $league_id, $badge_key, $earned_at, $times_earned, $metadata, $keepLatest = false) {
        $metaJson = $metadata ? json_encode($metadata) : null;
        // Repeatable badges: always update to most recent earned_at
        // Milestone badges: preserve the earliest (first achievement) date
        $dateLogic = $keepLatest
            ? 'earned_at = VALUES(earned_at)'
            : 'earned_at = IF(VALUES(earned_at) < earned_at, VALUES(earned_at), earned_at)';
        $stmt = $this->pdo->prepare("
            INSERT INTO user_badges (user_id, league_id, badge_key, earned_at, times_earned, metadata)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                times_earned = VALUES(times_earned),
                $dateLogic,
                metadata     = VALUES(metadata)
        ");
        $stmt->execute([$user_id, $league_id, $badge_key, $earned_at, $times_earned, $metaJson]);
    }
}