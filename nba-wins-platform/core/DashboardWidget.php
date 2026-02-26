<?php
// /data/www/default/nba-wins-platform/core/DashboardWidget.php
// Renders individual widgets on the homepage dashboard - DARK THEME VERSION
// Matches the dark UI of index.php

class DashboardWidget {
    private $pdo;
    private $widgetFetcher;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        require_once __DIR__ . '/WidgetDataFetcher.php';
        $this->widgetFetcher = new WidgetDataFetcher($pdo);
    }
    
    /**
     * Render a widget based on its type
     */
    public function render($widget_type, $user_id, $league_id, $edit_mode = false, $selected_date = null) {
        switch ($widget_type) {
            case 'upcoming_games':
                return $this->renderUpcomingGames($user_id, $league_id, $edit_mode, $selected_date);
            case 'last_10_games':
                return $this->renderLastGames($user_id, $league_id, $edit_mode);
            case 'league_stats':
                return $this->renderLeagueStats($user_id, $league_id, $edit_mode);
            case 'exceeding_expectations':
                return $this->renderExceedingExpectations($user_id, $league_id, $edit_mode);
            case 'falling_short':
                return $this->renderFallingShort($user_id, $league_id, $edit_mode);
            case 'platform_leaderboard':
                return $this->renderPlatformLeaderboard($edit_mode);
            case 'draft_steals':
                return $this->renderDraftSteals($edit_mode);
            case 'weekly_rankings':
                return $this->renderWeeklyRankings($user_id, $league_id, $edit_mode);
            case 'strength_of_schedule':
                return $this->renderStrengthOfSchedule($user_id, $league_id, $edit_mode);
            case 'tracking_graph':
            case 'h2h_comparison':
                return $this->renderAnalyticsPlaceholder($widget_type, $edit_mode);
            default:
                return '';
        }
    }
    
    /**
     * Shared edit controls markup
     */
    private function renderEditControls($widget_type, $edit_mode) {
        if (!$edit_mode) return '';
        ob_start();
        ?>
        <div class="dw-controls show">
            <button class="dw-ctrl-btn" onclick="moveWidget('<?php echo $widget_type; ?>', 'up')" title="Move Up">
                <i class="fas fa-arrow-up"></i>
            </button>
            <button class="dw-ctrl-btn" onclick="moveWidget('<?php echo $widget_type; ?>', 'down')" title="Move Down">
                <i class="fas fa-arrow-down"></i>
            </button>
            <button class="dw-ctrl-btn dw-ctrl-remove" onclick="removeWidget('<?php echo $widget_type; ?>')" title="Remove Widget">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Platform Leaderboard with expandable teams
     */
    private function renderPlatformLeaderboard($edit_mode) {
        $leaderboard = $this->widgetFetcher->getPlatformLeaderboardWithTeams();
        
        ob_start();
        ?>
        <div class="dw-card" data-widget-type="platform_leaderboard">
            <div class="dw-header">
                <h3 class="dw-title"><i class="fas fa-globe"></i> Platform Leaderboard</h3>
                <?php echo $this->renderEditControls('platform_leaderboard', $edit_mode); ?>
            </div>
            
            <?php if (!empty($leaderboard)): ?>
            <div class="dw-body">
                <?php 
                $rank = 1; $prevWins = null; $nextRank = 1;
                foreach ($leaderboard as $index => $entry): 
                    if ($prevWins !== null && $entry['total_wins'] < $prevWins) $rank = $nextRank;
                    $prevWins = $entry['total_wins'];
                    $nextRank = $index + 2;
                    $rowId = 'lb-row-' . $entry['participant_id'];
                    $teamListId = 'lb-teams-' . $entry['participant_id'];
                ?>
                <div class="dw-lb-row" id="<?php echo $rowId; ?>" 
                     onclick="toggleLeaderboardTeams('<?php echo $teamListId; ?>', this)">
                    <div class="dw-lb-rank">
                        <?php echo $rank; ?>.
                        <i class="fas fa-chevron-down dw-lb-arrow"></i>
                        <?php if ($rank === 1 && $entry['total_wins'] > 0): ?>
                            <i class="fa-solid fa-trophy" style="color: var(--accent-gold); margin-left: 4px;"></i>
                        <?php elseif ($rank === 2): ?>
                            <i class="fa-solid fa-trophy" style="color: var(--accent-silver); margin-left: 4px;"></i>
                        <?php elseif ($rank === 3): ?>
                            <i class="fa-solid fa-trophy" style="color: var(--accent-bronze); margin-left: 4px;"></i>
                        <?php endif; ?>
                    </div>
                    <div class="dw-lb-info">
                        <div class="dw-lb-name"><?php echo htmlspecialchars($entry['display_name']); ?></div>
                        <div class="dw-lb-league"><?php echo htmlspecialchars($entry['league_name']); ?></div>
                    </div>
                    <div class="dw-lb-wins"><?php echo $entry['total_wins']; ?></div>
                </div>
                
                <div class="dw-lb-teams" id="<?php echo $teamListId; ?>">
                    <?php foreach ($entry['teams'] as $team): ?>
                    <div class="dw-lb-team-row">
                        <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($team['team_name']); ?>" 
                           class="dw-lb-team-link">
                            <img src="<?php echo htmlspecialchars($this->getTeamLogo($team['team_name'])); ?>" 
                                 alt="" class="dw-team-logo" onerror="this.style.opacity='0.3'">
                            <span><?php echo htmlspecialchars($team['team_name']); ?></span>
                        </a>
                        <span class="dw-lb-team-wins"><?php echo $team['wins']; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
            
            <script>
            function toggleLeaderboardTeams(teamListId, rowElement) {
                const teamList = document.getElementById(teamListId);
                const isExpanded = rowElement.classList.contains('expanded');
                if (isExpanded) {
                    teamList.style.display = 'none';
                    rowElement.classList.remove('expanded');
                } else {
                    teamList.style.display = 'block';
                    rowElement.classList.add('expanded');
                }
            }
            </script>
            <?php else: ?>
            <div class="dw-empty"><p>No leaderboard data available yet</p></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Draft Steals widget
     */
    private function renderDraftSteals($edit_mode) {
        $draftSteals = $this->widgetFetcher->getBestDraftSteals();
        
        ob_start();
        ?>
        <div class="dw-card" data-widget-type="draft_steals">
            <div class="dw-header">
                <h3 class="dw-title"><i class="fas fa-gem"></i> Draft Steals</h3>
                <?php echo $this->renderEditControls('draft_steals', $edit_mode); ?>
            </div>
            
            <?php if (!empty($draftSteals)): ?>
            <div class="dw-body">
                <table class="dw-steals-table">
                    <thead>
                        <tr>
                            <th class="dw-steals-rank-col">#</th>
                            <th>Team</th>
                            <th class="dw-steals-hide-mobile">Owner</th>
                            <th class="dw-steals-hide-mobile">League</th>
                            <th class="dw-steals-value-col">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($draftSteals as $steal): ?>
                        <tr>
                            <td class="dw-steals-rank-col">
                                <strong><?php echo $steal['rank']; ?>.</strong>
                                <?php if ($steal['rank'] === 1): ?>
                                    <i class="fa-solid fa-trophy" style="color: var(--accent-gold);"></i>
                                <?php elseif ($steal['rank'] === 2): ?>
                                    <i class="fa-solid fa-trophy" style="color: var(--accent-silver);"></i>
                                <?php elseif ($steal['rank'] === 3): ?>
                                    <i class="fa-solid fa-trophy" style="color: var(--accent-bronze);"></i>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($steal['team_name']); ?>" class="dw-steals-team-link">
                                    <img src="<?php echo htmlspecialchars($this->getTeamLogo($steal['team_name'])); ?>" alt="" class="dw-team-logo" onerror="this.style.opacity='0.3'">
                                    <span class="dw-steals-team-name"><?php echo htmlspecialchars($steal['team_name']); ?></span>
                                </a>
                                <div class="dw-steals-meta">
                                    <span class="dw-steals-hide-mobile"><?php echo htmlspecialchars($steal['owner_name']); ?> · </span>
                                    Pick #<?php echo $steal['pick_number']; ?>, Rnd <?php echo $steal['round_number']; ?> · <?php echo $steal['actual_wins']; ?>W
                                </div>
                                <div class="dw-steals-show-mobile">
                                    <div style="color: var(--text-secondary); margin-top: 2px;"><?php echo htmlspecialchars($steal['owner_name']); ?></div>
                                    <div style="color: var(--text-muted); margin-top: 1px;"><?php echo htmlspecialchars($steal['league_name']); ?></div>
                                </div>
                            </td>
                            <td class="dw-steals-hide-mobile">
                                <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?php echo $steal['league_id']; ?>&user_id=<?php echo $steal['user_id']; ?>" 
                                   style="color: var(--accent-blue); text-decoration: none;">
                                    <?php echo htmlspecialchars($steal['owner_name']); ?>
                                </a>
                            </td>
                            <td class="dw-steals-hide-mobile" style="color: var(--text-secondary);">
                                <?php echo htmlspecialchars($steal['league_name']); ?>
                            </td>
                            <td class="dw-steals-value-col">
                                <div style="font-weight: 700; font-size: 1.05rem; color: <?php echo $steal['grade_color']; ?>;">
                                    +<?php echo number_format($steal['steal_score'], 2); ?>
                                </div>
                                <div style="font-size: 0.65rem; color: <?php echo $steal['grade_color']; ?>; font-weight: 700;">
                                    <?php echo $steal['steal_grade']; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="dw-empty"><p>No draft steal data available yet</p></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Exceeding Expectations (Vegas overperformers)
     */
    private function renderExceedingExpectations($user_id, $league_id, $edit_mode) {
        $vegasData = $this->widgetFetcher->getVegasOverUnderPerformance($league_id);
        $overperformers = $vegasData['overperformers'] ?? [];
        
        ob_start();
        ?>
        <div class="dw-card" data-widget-type="exceeding_expectations">
            <div class="dw-header">
                <h3 class="dw-title"><i class="fa-solid fa-dice"></i> Exceeding Expectations</h3>
                <?php echo $this->renderEditControls('exceeding_expectations', $edit_mode); ?>
            </div>
            
            <?php if (!empty($overperformers)): ?>
            <div class="dw-body">
                <?php foreach ($overperformers as $index => $team): ?>
                <div class="dw-team-stat-row">
                    <div class="dw-team-stat-left">
                        <span class="dw-team-stat-rank"><?php echo $index + 1; ?>.</span>
                        <img src="<?php echo htmlspecialchars($this->getTeamLogo($team['team_name'])); ?>" alt="" class="dw-team-logo" onerror="this.style.opacity='0.3'">
                        <div>
                            <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($team['team_name']); ?>" class="dw-team-stat-name">
                                <?php echo htmlspecialchars($team['team_name']); ?>
                            </a>
                            <?php if ($team['owner']): ?>
                            <div class="dw-team-stat-sub"><?php echo htmlspecialchars($team['owner']); ?> · <?php echo $team['current_record']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="dw-team-stat-right">
                        <div class="dw-team-stat-secondary">Pace: <?php echo number_format($team['current_pace'], 1); ?></div>
                        <div style="color: var(--accent-green); font-weight: 700;">+<?php echo number_format($team['variance'], 1); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="dw-empty"><p>No teams currently exceeding Vegas expectations in your league</p></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Falling Short (Vegas underperformers)
     */
    private function renderFallingShort($user_id, $league_id, $edit_mode) {
        $vegasData = $this->widgetFetcher->getVegasOverUnderPerformance($league_id);
        $underperformers = $vegasData['underperformers'] ?? [];
        
        ob_start();
        ?>
        <div class="dw-card" data-widget-type="falling_short">
            <div class="dw-header">
                <h3 class="dw-title"><i class="fa-solid fa-dice"></i> Falling Short of Expectations</h3>
                <?php echo $this->renderEditControls('falling_short', $edit_mode); ?>
            </div>
            
            <?php if (!empty($underperformers)): ?>
            <div class="dw-body">
                <?php foreach ($underperformers as $index => $team): ?>
                <div class="dw-team-stat-row">
                    <div class="dw-team-stat-left">
                        <span class="dw-team-stat-rank"><?php echo $index + 1; ?>.</span>
                        <img src="<?php echo htmlspecialchars($this->getTeamLogo($team['team_name'])); ?>" alt="" class="dw-team-logo" onerror="this.style.opacity='0.3'">
                        <div>
                            <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($team['team_name']); ?>" class="dw-team-stat-name">
                                <?php echo htmlspecialchars($team['team_name']); ?>
                            </a>
                            <?php if ($team['owner']): ?>
                            <div class="dw-team-stat-sub"><?php echo htmlspecialchars($team['owner']); ?> · <?php echo $team['current_record']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="dw-team-stat-right">
                        <div class="dw-team-stat-secondary">Pace: <?php echo number_format($team['current_pace'], 1); ?></div>
                        <div style="color: var(--accent-red); font-weight: 700;"><?php echo number_format($team['variance'], 1); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="dw-empty"><p>No teams currently falling short of Vegas expectations in your league</p></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Upcoming Games widget
     */
    private function renderUpcomingGames($user_id, $league_id, $edit_mode, $selected_date = null) {
        $upcomingGames = $this->widgetFetcher->getUpcomingGames($user_id, $league_id, $selected_date);
        
        ob_start();
        ?>
        <div class="dw-card" data-widget-type="upcoming_games">
            <div class="dw-header">
                <h3 class="dw-title"><i class="fas fa-calendar-alt"></i> Upcoming Games</h3>
                <?php echo $this->renderEditControls('upcoming_games', $edit_mode); ?>
            </div>
            
            <?php if (!empty($upcomingGames)): ?>
            <div class="dw-body dw-game-list">
                <?php foreach ($upcomingGames as $game): 
                    $comparisonUrl = "/nba-wins-platform/stats/team_comparison.php?home_team=" . urlencode($game['home_team_code']) . "&away_team=" . urlencode($game['away_team_code']) . "&date=" . urlencode($game['game_date']);
                ?>
                <a href="<?php echo $comparisonUrl; ?>" class="dw-game-item">
                    <div class="dw-game-info">
                        <div class="dw-game-date"><?php echo date('M j, Y', strtotime($game['game_date'])); ?></div>
                        <div class="dw-game-matchup">
                            <img src="<?php echo htmlspecialchars($this->getTeamLogo($game['my_team'])); ?>" alt="" class="dw-team-logo-sm" onerror="this.style.opacity='0.3'">
                            <?php echo htmlspecialchars($game['my_team']); ?>
                            <?php echo $game['team_location'] === 'home' ? 'vs' : '@'; ?>
                            <img src="<?php echo htmlspecialchars($this->getTeamLogo($game['opponent'])); ?>" alt="" class="dw-team-logo-sm" onerror="this.style.opacity='0.3'">
                            <?php echo htmlspecialchars($game['opponent']); ?>
                            <?php if (!empty($game['opponent_owner'])): ?>
                                <span class="dw-game-owner">(<?php echo htmlspecialchars($game['opponent_owner']); ?>)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="dw-empty"><p>No upcoming games scheduled</p></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Last 10 Games widget
     */
    private function renderLastGames($user_id, $league_id, $edit_mode) {
        $lastGames = $this->widgetFetcher->getLastGames($user_id, $league_id);
        
        $last10_wins = 0; $last10_losses = 0;
        foreach ($lastGames as $game) {
            if ($game['result'] === 'W') $last10_wins++;
            elseif ($game['result'] === 'L') $last10_losses++;
        }
        
        ob_start();
        ?>
        <div class="dw-card" data-widget-type="last_10_games">
            <div class="dw-header">
                <h3 class="dw-title">
                    <i class="fas fa-history"></i> Last 10 Games
                    <?php if (!empty($lastGames)): ?>
                        <span style="font-size: 0.85rem; color: var(--text-muted); margin-left: 8px;">
                            (<?php echo $last10_wins; ?>-<?php echo $last10_losses; ?>)
                        </span>
                    <?php endif; ?>
                </h3>
                <?php echo $this->renderEditControls('last_10_games', $edit_mode); ?>
            </div>
            
            <?php if (!empty($lastGames)): ?>
            <div class="dw-body dw-game-list">
                <?php foreach (array_reverse($lastGames) as $game): 
                    $teamScore = ($game['team_location'] === 'home') ? $game['home_points'] : $game['away_points'];
                    $oppScore = ($game['team_location'] === 'home') ? $game['away_points'] : $game['home_points'];
                    $gameUrl = "/nba-wins-platform/stats/game_details.php?home_team=" . urlencode($game['home_team_code']) . "&away_team=" . urlencode($game['away_team_code']) . "&date=" . urlencode($game['game_date']);
                    $isWin = $game['result'] === 'W';
                ?>
                <a href="<?php echo $gameUrl; ?>" class="dw-game-item dw-game-<?php echo $isWin ? 'win' : 'loss'; ?>">
                    <div class="dw-game-info">
                        <div class="dw-game-date"><?php echo date('M j, Y', strtotime($game['game_date'])); ?></div>
                        <div class="dw-game-matchup">
                            <img src="<?php echo htmlspecialchars($this->getTeamLogo($game['my_team'])); ?>" alt="" class="dw-team-logo-sm" onerror="this.style.opacity='0.3'">
                            <?php echo htmlspecialchars($game['my_team']); ?>
                            <?php echo $game['team_location'] === 'home' ? 'vs' : '@'; ?>
                            <img src="<?php echo htmlspecialchars($this->getTeamLogo($game['opponent'])); ?>" alt="" class="dw-team-logo-sm" onerror="this.style.opacity='0.3'">
                            <?php echo htmlspecialchars($game['opponent']); ?>
                            <?php if (!empty($game['opponent_owner'])): ?>
                                <span class="dw-game-owner">(<?php echo htmlspecialchars($game['opponent_owner']); ?>)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="dw-game-result">
                        <div class="dw-game-score"><?php echo $teamScore . '-' . $oppScore; ?></div>
                        <div class="dw-game-outcome <?php echo $isWin ? 'win' : 'loss'; ?>"><?php echo $game['result']; ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="dw-empty"><p>No recent games to display</p></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * League Stats widget
     */
    private function renderLeagueStats($user_id, $league_id, $edit_mode) {
        $stats = $this->widgetFetcher->getLeagueStatsAndRivals($user_id, $league_id);
        if (!$stats) return '';
        
        // Get display name for header
        $nameStmt = $this->pdo->prepare("SELECT display_name FROM users WHERE id = ?");
        $nameStmt->execute([$user_id]);
        $dwDisplayName = $nameStmt->fetchColumn() ?: 'My';
        
        ob_start();
        ?>
        <div class="dw-card" data-widget-type="league_stats">
            <div class="dw-header">
                <h3 class="dw-title"><?php echo htmlspecialchars($dwDisplayName); ?> Stats</h3>
                <?php echo $this->renderEditControls('league_stats', $edit_mode); ?>
            </div>
            
            <div class="dw-body">
                <div class="dw-team-stat-row">
                    <div class="dw-team-stat-left"><span>Total Games Played</span></div>
                    <div class="dw-team-stat-value"><?php echo $stats['total_games_played']; ?></div>
                </div>
                <div class="dw-team-stat-row">
                    <div class="dw-team-stat-left"><span>Average Team Record</span></div>
                    <div class="dw-team-stat-value"><?php echo $stats['avg_wins'] . '-' . $stats['avg_losses']; ?></div>
                </div>
                <div class="dw-team-stat-row">
                    <div class="dw-team-stat-left"><span>Win %</span></div>
                    <div class="dw-team-stat-value">
                        <?php 
                        $totalW = floatval($stats['avg_wins']);
                        $totalL = floatval($stats['avg_losses']);
                        $totalG = $totalW + $totalL;
                        echo $totalG > 0 
                            ? number_format(($totalW / $totalG) * 100, 1) . '%'
                            : '0.0%';
                        ?>
                    </div>
                </div>
                <div class="dw-team-stat-row">
                    <div class="dw-team-stat-left"><span>Best Team</span></div>
                    <div class="dw-team-stat-value">
                        <?php echo $stats['best_team'] 
                            ? htmlspecialchars($stats['best_team']['team_name']) . ' (' . $stats['best_team']['wins'] . '-' . $stats['best_team']['losses'] . ')'
                            : 'N/A'; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Weekly Rankings with dropdown
     */
    private function renderWeeklyRankings($user_id, $league_id, $edit_mode) {
        $weeklyData = $this->widgetFetcher->getWeeklyRankings($league_id);
        
        $weeks = [];
        foreach ($weeklyData as $record) {
            $weekNum = $record['week_num'];
            if (!isset($weeks[$weekNum])) {
                $weeks[$weekNum] = ['weekNum' => $weekNum, 'label' => $record['week_label'], 'participants' => []];
            }
            $weeks[$weekNum]['participants'][] = ['name' => $record['display_name'], 'wins' => (int)$record['weekly_wins']];
        }
        
        foreach ($weeks as &$week) {
            usort($week['participants'], function($a, $b) { return $b['wins'] - $a['wins']; });
            $rank = 1; $prevWins = null; $nextRank = 1;
            foreach ($week['participants'] as $idx => &$p) {
                if ($prevWins !== null && $p['wins'] < $prevWins) $rank = $nextRank;
                $p['rank'] = $rank;
                $prevWins = $p['wins'];
                $nextRank = $idx + 2;
            }
        }
        unset($week, $p);
        
        usort($weeks, function($a, $b) { return $b['weekNum'] - $a['weekNum']; });
        $latestWeek = !empty($weeks) ? $weeks[0]['weekNum'] : null;
        
        ob_start();
        ?>
        <div class="dw-card" data-widget-type="weekly_rankings" id="weekly-rankings-widget">
            <div class="dw-header">
                <h3 class="dw-title"><i class="fas fa-trophy"></i> Weekly Rankings</h3>
                <?php echo $this->renderEditControls('weekly_rankings', $edit_mode); ?>
            </div>
            
            <?php if (!empty($weeks)): ?>
            <div class="dw-body">
                <div style="text-align: center; margin-bottom: 14px;">
                    <select id="weekSelector" onchange="changeWeek()" class="dw-select">
                        <?php foreach ($weeks as $week): ?>
                        <option value="<?php echo $week['weekNum']; ?>"><?php echo htmlspecialchars($week['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php foreach ($weeks as $week): ?>
                <div class="week-data" data-week="<?php echo $week['weekNum']; ?>" 
                     style="<?php echo $week['weekNum'] === $latestWeek ? '' : 'display: none;'; ?>">
                    <?php foreach ($week['participants'] as $p): ?>
                    <div class="dw-weekly-row <?php 
                        if ($p['rank'] === 1) echo 'dw-weekly-gold';
                        elseif ($p['rank'] === 2) echo 'dw-weekly-silver';
                        elseif ($p['rank'] === 3) echo 'dw-weekly-bronze';
                    ?>">
                        <div style="display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0;">
                            <span class="dw-weekly-rank"><?php echo $p['rank']; ?>.</span>
                            <span class="dw-weekly-name"><?php echo htmlspecialchars($p['name']); ?></span>
                        </div>
                        <span class="dw-weekly-wins"><?php echo $p['wins']; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
            
            <script>
            function changeWeek() {
                const selectedWeek = document.getElementById('weekSelector').value;
                document.querySelectorAll('.week-data').forEach(el => {
                    el.style.display = el.dataset.week === selectedWeek ? 'block' : 'none';
                });
            }
            </script>
            <?php else: ?>
            <div class="dw-empty"><p>No weekly ranking data available yet</p></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Strength of Schedule widget
     */
    private function renderStrengthOfSchedule($user_id, $league_id, $edit_mode) {
        $sosData = $this->widgetFetcher->getStrengthOfSchedule($league_id);
        
        ob_start();
        ?>
        <div class="dw-card" data-widget-type="strength_of_schedule" id="sos-widget">
            <div class="dw-header">
                <h3 class="dw-title"><i class="fas fa-calendar-check"></i> Strength of Schedule</h3>
                <?php echo $this->renderEditControls('strength_of_schedule', $edit_mode); ?>
            </div>
            
            <?php if (!empty($sosData)): ?>
            <div class="dw-body">
                <div style="display: flex; justify-content: center; gap: 8px; margin-bottom: 14px; flex-wrap: wrap;">
                    <button onclick="sortSOSWidget('opponent_win_pct')" id="sos-sort-pct" class="dw-sort-btn dw-sort-active">
                        <i class="fas fa-percentage"></i> Opp Win %
                    </button>
                    <button onclick="sortSOSWidget('total_games')" id="sos-sort-games" class="dw-sort-btn">
                        <i class="fas fa-hashtag"></i> Games Played
                    </button>
                </div>
                
                <table class="dw-sos-table">
                    <thead>
                        <tr>
                            <th style="text-align: left;">Participant</th>
                            <th style="text-align: center;">Games</th>
                            <th style="text-align: center;">Opp Win %</th>
                        </tr>
                    </thead>
                    <tbody id="sos-table-body">
                        <?php foreach ($sosData as $entry): ?>
                        <tr data-games="<?php echo $entry['total_games']; ?>" 
                            data-pct="<?php echo $entry['opponent_win_pct']; ?>" 
                            data-name="<?php echo htmlspecialchars($entry['display_name']); ?>">
                            <td>
                                <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?php echo $league_id; ?>&user_id=<?php echo $entry['user_id']; ?>" 
                                   style="color: var(--accent-blue); text-decoration: none; font-weight: 600;">
                                    <?php echo htmlspecialchars($entry['display_name']); ?>
                                </a>
                            </td>
                            <td style="text-align: center; font-weight: 700; font-size: 1.05rem;">
                                <?php echo $entry['total_games']; ?>
                            </td>
                            <td style="text-align: center;">
                                <div style="font-weight: 700; font-size: 1.05rem; color: <?php echo $entry['opponent_win_pct'] >= 50 ? 'var(--accent-red)' : 'var(--accent-green)'; ?>;">
                                    <?php echo number_format($entry['opponent_win_pct'], 1); ?>%
                                </div>
                                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 1px;">
                                    <?php echo $entry['opponent_win_pct'] >= 50 ? 'Tough' : 'Easy'; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <script>
            function sortSOSWidget(sortBy) {
                const tbody = document.getElementById('sos-table-body');
                const rows = Array.from(tbody.getElementsByTagName('tr'));
                
                document.querySelectorAll('.dw-sort-btn').forEach(btn => btn.classList.remove('dw-sort-active'));
                document.getElementById(sortBy === 'opponent_win_pct' ? 'sos-sort-pct' : 'sos-sort-games').classList.add('dw-sort-active');
                
                rows.sort((a, b) => {
                    const key = sortBy === 'opponent_win_pct' ? 'pct' : 'games';
                    const aVal = parseFloat(a.dataset[key]);
                    const bVal = parseFloat(b.dataset[key]);
                    return bVal !== aVal ? bVal - aVal : a.dataset.name.localeCompare(b.dataset.name);
                });
                rows.forEach(row => tbody.appendChild(row));
            }
            </script>
            <?php else: ?>
            <div class="dw-empty"><p>No strength of schedule data available yet for your league</p></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Placeholder for complex analytics widgets
     */
    private function renderAnalyticsPlaceholder($widget_type, $edit_mode) {
        $widgetTitles = [
            'tracking_graph' => ['title' => 'Tracking Graph', 'icon' => 'fa-chart-line'],
            'h2h_comparison' => ['title' => 'Head-to-Head Comparison', 'icon' => 'fa-users'],
        ];
        $info = $widgetTitles[$widget_type] ?? ['title' => 'Widget', 'icon' => 'fa-info-circle'];
        
        ob_start();
        ?>
        <div class="dw-card" data-widget-type="<?php echo $widget_type; ?>">
            <div class="dw-header">
                <h3 class="dw-title"><i class="fas <?php echo $info['icon']; ?>"></i> <?php echo $info['title']; ?></h3>
                <?php echo $this->renderEditControls($widget_type, $edit_mode); ?>
            </div>
            <div style="text-align: center; padding: 40px 20px;">
                <i class="fas <?php echo $info['icon']; ?>" style="font-size: 2.5rem; color: var(--text-muted); opacity: 0.4; margin-bottom: 12px; display: block;"></i>
                <p style="color: var(--text-secondary); margin: 8px 0;">
                    View this content on the <a href="analytics.php" style="color: var(--accent-blue); text-decoration: none; font-weight: 600;">Analytics page</a>
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Helper: get team logo path
     */
    private function getTeamLogo($teamName) {
        $logoMap = [
            'Atlanta Hawks' => 'atlanta_hawks.png',
            'Boston Celtics' => 'boston_celtics.png',
            'Brooklyn Nets' => 'brooklyn_nets.png',
            'Charlotte Hornets' => 'charlotte_hornets.png',
            'Chicago Bulls' => 'chicago_bulls.png',
            'Cleveland Cavaliers' => 'cleveland_cavaliers.png',
            'Detroit Pistons' => 'detroit_pistons.png',
            'Indiana Pacers' => 'indiana_pacers.png',
            'Miami Heat' => 'miami_heat.png',
            'Milwaukee Bucks' => 'milwaukee_bucks.png',
            'New York Knicks' => 'new_york_knicks.png',
            'Orlando Magic' => 'orlando_magic.png',
            'Philadelphia 76ers' => 'philadelphia_76ers.png',
            'Toronto Raptors' => 'toronto_raptors.png',
            'Washington Wizards' => 'washington_wizards.png',
            'Dallas Mavericks' => 'dallas_mavericks.png',
            'Denver Nuggets' => 'denver_nuggets.png',
            'Golden State Warriors' => 'golden_state_warriors.png',
            'Houston Rockets' => 'houston_rockets.png',
            'LA Clippers' => 'la_clippers.png',
            'Los Angeles Clippers' => 'la_clippers.png',
            'Los Angeles Lakers' => 'los_angeles_lakers.png',
            'Memphis Grizzlies' => 'memphis_grizzlies.png',
            'Minnesota Timberwolves' => 'minnesota_timberwolves.png',
            'New Orleans Pelicans' => 'new_orleans_pelicans.png',
            'Oklahoma City Thunder' => 'oklahoma_city_thunder.png',
            'Phoenix Suns' => 'phoenix_suns.png',
            'Portland Trail Blazers' => 'portland_trail_blazers.png',
            'Sacramento Kings' => 'sacramento_kings.png',
            'San Antonio Spurs' => 'san_antonio_spurs.png',
            'Utah Jazz' => 'utah_jazz.png'
        ];
        
        if (isset($logoMap[$teamName])) {
            return '/nba-wins-platform/public/assets/team_logos/' . $logoMap[$teamName];
        }
        $filename = strtolower(str_replace(' ', '_', $teamName)) . '.png';
        return '/nba-wins-platform/public/assets/team_logos/' . $filename;
    }
}
?>