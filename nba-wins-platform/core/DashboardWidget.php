<?php
// /data/www/default/nba-wins-platform/core/DashboardWidget.php
// Renders individual widgets on the homepage dashboard

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
     * 
     * @param string $widget_type - The type of widget to render
     * @param int $user_id - The user ID
     * @param int $league_id - The league ID
     * @param bool $edit_mode - Whether edit mode is active
     * @param string $selected_date - Optional date selected on index page (for upcoming games widget)
     * @return string - HTML for the widget
     */
    public function render($widget_type, $user_id, $league_id, $edit_mode = false, $selected_date = null) {
        switch ($widget_type) {
            // Participant Profile Widgets
            case 'upcoming_games':
                return $this->renderUpcomingGames($user_id, $league_id, $edit_mode, $selected_date);
            case 'last_10_games':
                return $this->renderLastGames($user_id, $league_id, $edit_mode);
            case 'league_stats':
                return $this->renderLeagueStats($user_id, $league_id, $edit_mode);
                
            // Vegas Over/Under Widgets
            case 'exceeding_expectations':
                return $this->renderExceedingExpectations($user_id, $league_id, $edit_mode);
            case 'falling_short':
                return $this->renderFallingShort($user_id, $league_id, $edit_mode);
                
            // Analytics Widgets - NOW WITH REAL DATA!
            case 'platform_leaderboard':
                return $this->renderPlatformLeaderboard($edit_mode);
            case 'draft_steals':
                return $this->renderDraftSteals($edit_mode);
                
            // Enhanced Analytics Widgets
            case 'weekly_rankings':
                return $this->renderWeeklyRankings($user_id, $league_id, $edit_mode);
            case 'strength_of_schedule':
                return $this->renderStrengthOfSchedule($user_id, $league_id, $edit_mode);
            
            // Complex Analytics Widgets - Still placeholders (require heavy JS/charting)
            case 'tracking_graph':
            case 'h2h_comparison':
                return $this->renderAnalyticsPlaceholder($widget_type, $edit_mode);
                
            default:
                return '';
        }
    }
    
    /**
     * Render Platform Leaderboard widget with EXPANDABLE TEAMS
     */
    private function renderPlatformLeaderboard($edit_mode) {
        $leaderboard = $this->widgetFetcher->getPlatformLeaderboardWithTeams();
        
        ob_start();
        ?>
        <div class="stats-card dashboard-widget" data-widget-type="platform_leaderboard">
            <div class="widget-header">
                <h2 class="section-title">
                    <i class="fas fa-globe"></i>
                    Platform Leaderboard
                </h2>
                <?php if ($edit_mode): ?>
                <div class="widget-controls">
                    <button class="widget-control-btn" onclick="moveWidget('platform_leaderboard', 'up')" title="Move Up">
                        <i class="fas fa-arrow-up"></i>
                    </button>
                    <button class="widget-control-btn" onclick="moveWidget('platform_leaderboard', 'down')" title="Move Down">
                        <i class="fas fa-arrow-down"></i>
                    </button>
                    <button class="widget-control-btn widget-remove-btn" onclick="removeWidget('platform_leaderboard')" title="Remove Widget">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($leaderboard)): ?>
            <style>
            .leaderboard-expandable-row {
                cursor: pointer;
                transition: background-color 0.2s;
            }
            .leaderboard-expandable-row:hover {
                background-color: rgba(0,0,0,0.02);
            }
            .leaderboard-expandable-row.expanded .expand-indicator {
                transform: rotate(180deg);
            }
            .expand-indicator {
                transition: transform 0.3s;
                margin-left: 5px;
                color: #666;
                font-size: 0.8rem;
            }
            .team-list-expanded {
                display: none;
                background-color: #f9fafb;
                padding: 12px;
                border-top: 1px solid #e0e0e0;
            }
            .team-list-table {
                width: 100%;
                border-collapse: collapse;
            }
            .team-list-table th {
                text-align: left;
                padding: 8px;
                font-size: 0.85rem;
                color: #666;
                border-bottom: 1px solid #e0e0e0;
            }
            .team-list-table td {
                padding: 8px;
                font-size: 0.9rem;
                border-bottom: 1px solid #f0f0f0;
            }
            .team-list-table td:last-child {
                text-align: right;
                font-weight: bold;
                color: var(--primary-color);
            }
            @media (max-width: 768px) {
                .team-row {
                    padding: 10px 8px !important;
                }
                .team-info {
                    font-size: 0.85rem;
                    min-width: 0;
                    overflow: hidden;
                }
                .team-info > div {
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                }
                .team-info a {
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                    max-width: 180px;
                }
                .expand-indicator {
                    font-size: 0.7rem;
                }
                .fa-trophy {
                    font-size: 0.75rem !important;
                    margin-left: 3px !important;
                }
                .team-record {
                    font-size: 1.1rem !important;
                    padding-right: 4px !important;
                }
            }
            </style>
            
            <div>
                <?php 
                $rank = 1;
                $prevWins = null;
                $nextRank = 1;
                foreach ($leaderboard as $index => $entry): 
                    // Proper tie handling
                    if ($prevWins !== null && $entry['total_wins'] < $prevWins) {
                        $rank = $nextRank;
                    }
                    $prevWins = $entry['total_wins'];
                    $nextRank = $index + 2;
                    
                    $rowId = 'leaderboard-row-' . $entry['participant_id'];
                    $teamListId = 'leaderboard-teams-' . $entry['participant_id'];
                ?>
                <div class="leaderboard-expandable-row" id="<?php echo $rowId; ?>" 
                     onclick="toggleLeaderboardTeams('<?php echo $teamListId; ?>', this)">
                    <div class="team-row" style="padding: 12px 8px; border-bottom: 1px solid #e0e0e0; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                    <div class="team-info" style="display: flex; align-items: center; flex: 1; min-width: 0; gap: 8px;">
                    <span class="participant-rank" style="font-weight: bold; color: #666; min-width: 30px; display: inline-flex; align-items: center; font-size: 0.9rem; flex-shrink: 0;">
                    <?php echo $rank; ?>.
                    <i class="fas fa-chevron-down expand-indicator"></i>
                    <?php if ($rank === 1 && $entry['total_wins'] > 0): ?>
                    <i class="fa-solid fa-trophy" style="color: gold; margin-left: 5px;" title="1st Place"></i>
                    <?php elseif ($rank === 2): ?>
                    <i class="fa-solid fa-trophy" style="color: silver; margin-left: 5px;" title="2nd Place"></i>
                    <?php elseif ($rank === 3): ?>
                    <i class="fa-solid fa-trophy" style="color: #CD7F32; margin-left: 5px;" title="3rd Place"></i>
                    <?php endif; ?>
                    </span>
                    <div style="flex: 1; min-width: 0; overflow: hidden;">
                    <div class="participant-name-text" style="font-weight: 600; font-size: 0.9rem; display: block; word-wrap: break-word; overflow-wrap: break-word; white-space: normal; line-height: 1.3;">
                    <?php echo htmlspecialchars($entry['display_name']); ?>
                    </div>
                    <div class="participant-league-text" style="font-size: 0.75rem; color: #666; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    <?php echo htmlspecialchars($entry['league_name']); ?>
                    </div>
                    </div>
                    </div>
                    <div class="team-record" style="font-size: 1rem; font-weight: bold; color: var(--primary-color); padding-right: 8px; flex-shrink: 0;">
                    <?php echo $entry['total_wins']; ?>
                    </div>
                    </div>
                </div>
                
                <!-- Expandable Team List -->
                <div class="team-list-expanded" id="<?php echo $teamListId; ?>">
                    <table class="team-list-table">
                        <thead>
                            <tr>
                                <th>Team</th>
                                <th style="text-align: right;">Wins</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entry['teams'] as $team): ?>
                            <tr>
                                <td>
                                    <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($team['team_name']); ?>" 
                                       style="text-decoration: none; color: inherit; display: flex; align-items: center; gap: 8px;">
                                        <img src="<?php echo htmlspecialchars($this->getTeamLogo($team['team_name'])); ?>" 
                                             alt="<?php echo htmlspecialchars($team['team_name']); ?>" 
                                             style="width: 20px; height: 20px;"
                                             onerror="this.style.display='none'">
                                        <span><?php echo htmlspecialchars($team['team_name']); ?></span>
                                    </a>
                                </td>
                                <td><?php echo $team['wins']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
            <div class="no-data">
                <p>No leaderboard data available yet</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render Draft Steals widget with LEAGUE COLUMN
     */
    private function renderDraftSteals($edit_mode) {
        $draftSteals = $this->widgetFetcher->getBestDraftSteals();
        
        ob_start();
        ?>
        <div class="stats-card dashboard-widget" data-widget-type="draft_steals">
            <div class="widget-header">
                <h2 class="section-title">
                    <i class="fas fa-gem"></i>
                    Draft Steals
                </h2>
                <?php if ($edit_mode): ?>
                <div class="widget-controls">
                    <button class="widget-control-btn" onclick="moveWidget('draft_steals', 'up')" title="Move Up">
                        <i class="fas fa-arrow-up"></i>
                    </button>
                    <button class="widget-control-btn" onclick="moveWidget('draft_steals', 'down')" title="Move Down">
                        <i class="fas fa-arrow-down"></i>
                    </button>
                    <button class="widget-control-btn widget-remove-btn" onclick="removeWidget('draft_steals')" title="Remove Widget">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($draftSteals)): ?>
            <style>
            .draft-steals-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 0.9rem;
            }
            .draft-steals-table th {
                background-color: #f8f9fa;
                padding: 10px 8px;
                text-align: left;
                font-weight: 600;
                border-bottom: 2px solid #dee2e6;
            }
            .draft-steals-table td {
                padding: 10px 8px;
                border-bottom: 1px solid #e0e0e0;
            }
            .draft-steals-table .rank-col {
                width: 60px;
                text-align: center;
            }
            .draft-steals-table .value-col {
                text-align: center;
                width: 90px;
            }
            .draft-steals-table .team-logo-small {
                width: 20px;
                height: 20px;
                flex-shrink: 0;
            }
            .show-mobile-only {
                display: none;
            }
            .draft-steal-team-name {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            @media (max-width: 768px) {
                .draft-steals-table .hide-mobile {
                    display: none;
                }
                .show-mobile-only {
                    display: block;
                }
                .draft-steals-table {
                    font-size: 0.8rem;
                }
                .draft-steals-table th,
                .draft-steals-table td {
                    padding: 8px 4px;
                }
                .draft-steals-table .rank-col {
                    width: 45px;
                    font-size: 0.75rem;
                }
                .draft-steals-table .value-col {
                    width: 70px;
                }
                .draft-steals-table .team-logo-small {
                    width: 16px;
                    height: 16px;
                }
                .draft-steal-team-name {
                    max-width: 100px;
                }
            }
            </style>
            
            <table class="draft-steals-table">
                <thead>
                    <tr>
                        <th class="rank-col">Rank</th>
                        <th>Team</th>
                        <th class="hide-mobile">Owner</th>
                        <th class="hide-mobile">League</th>
                        <th class="value-col">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($draftSteals as $steal): ?>
                    <tr>
                        <td class="rank-col">
                            <strong><?php echo $steal['rank']; ?>.</strong>
                            <?php if ($steal['rank'] === 1): ?>
                                <i class="fa-solid fa-trophy" style="color: gold;" title="Best Steal"></i>
                            <?php elseif ($steal['rank'] === 2): ?>
                                <i class="fa-solid fa-trophy" style="color: silver;" title="2nd Best"></i>
                            <?php elseif ($steal['rank'] === 3): ?>
                                <i class="fa-solid fa-trophy" style="color: #CD7F32;" title="3rd Best"></i>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($steal['team_name']); ?>" 
                               style="text-decoration: none; color: inherit; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                                <img src="<?php echo htmlspecialchars($this->getTeamLogo($steal['team_name'])); ?>" 
                                     alt="<?php echo htmlspecialchars($steal['team_name']); ?>" 
                                     class="team-logo-small"
                                     onerror="this.style.display='none'">
                                <span class="draft-steal-team-name"><?php echo htmlspecialchars($steal['team_name']); ?></span>
                            </a>
                            <div style="font-size: 0.75rem; color: #666; margin-top: 4px;">
                                <span class="hide-mobile"><?php echo htmlspecialchars($steal['owner_name']); ?> • </span>
                                Pick #<?php echo $steal['pick_number']; ?>, Rnd <?php echo $steal['round_number']; ?> • <?php echo $steal['actual_wins']; ?>W
                            </div>
                            <div class="show-mobile-only" style="font-size: 0.72rem; color: #666; margin-top: 2px; font-weight: 500;">
                                <?php echo htmlspecialchars($steal['owner_name']); ?>
                            </div>
                            <div class="show-mobile-only" style="font-size: 0.7rem; color: #999; margin-top: 2px;">
                                <?php echo htmlspecialchars($steal['league_name']); ?>
                            </div>
                        </td>
                        <td class="hide-mobile">
                            <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?php echo $steal['league_id']; ?>&user_id=<?php echo $steal['user_id']; ?>" 
                               style="text-decoration: none; color: inherit;">
                                <?php echo htmlspecialchars($steal['owner_name']); ?>
                            </a>
                        </td>
                        <td class="hide-mobile">
                            <?php echo htmlspecialchars($steal['league_name']); ?>
                        </td>
                        <td class="value-col">
                            <div style="font-weight: bold; font-size: 1.05rem; color: <?php echo $steal['grade_color']; ?>;">
                                +<?php echo number_format($steal['steal_score'], 2); ?>
                            </div>
                            <div style="font-size: 0.65rem; color: <?php echo $steal['grade_color']; ?>; font-weight: bold;">
                                <?php echo $steal['steal_grade']; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="no-data">
                <p>No draft steal data available yet</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render a placeholder for complex analytics widgets
     */
    private function renderAnalyticsPlaceholder($widget_type, $edit_mode) {
        $widgetTitles = [
            'tracking_graph' => ['title' => 'Tracking Graph', 'icon' => 'fa-chart-line'],
            'h2h_comparison' => ['title' => 'Head-to-Head Comparison', 'icon' => 'fa-users'],
            'weekly_rankings' => ['title' => 'Weekly Rankings', 'icon' => 'fa-trophy'],
            'strength_of_schedule' => ['title' => 'Strength of Schedule', 'icon' => 'fa-calendar-check']
        ];
        
        $info = $widgetTitles[$widget_type] ?? ['title' => 'Widget', 'icon' => 'fa-info-circle'];
        
        ob_start();
        ?>
        <div class="stats-card dashboard-widget" data-widget-type="<?php echo $widget_type; ?>" style="padding: 30px;">
            <div class="widget-header">
                <h2 class="section-title">
                    <i class="fas <?php echo $info['icon']; ?>"></i>
                    <?php echo $info['title']; ?>
                </h2>
                <?php if ($edit_mode): ?>
                <div class="widget-controls">
                    <button class="widget-control-btn" onclick="moveWidget('<?php echo $widget_type; ?>', 'up')" title="Move Up">
                        <i class="fas fa-arrow-up"></i>
                    </button>
                    <button class="widget-control-btn" onclick="moveWidget('<?php echo $widget_type; ?>', 'down')" title="Move Down">
                        <i class="fas fa-arrow-down"></i>
                    </button>
                    <button class="widget-control-btn widget-remove-btn" onclick="removeWidget('<?php echo $widget_type; ?>')" title="Remove Widget">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <div style="text-align: center; padding: 40px 20px;">
                <i class="fas <?php echo $info['icon']; ?>" style="font-size: 3rem; color: #ddd; margin-bottom: 15px;"></i>
                <p style="color: #666; margin: 10px 0;">
                    View this content on the <a href="analytics.php" style="color: #007bff; text-decoration: none; font-weight: 600;">Analytics page</a>
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render Exceeding Expectations widget (Vegas Over/Under overperformers)
     */
    private function renderExceedingExpectations($user_id, $league_id, $edit_mode) {
        $vegasData = $this->widgetFetcher->getVegasOverUnderPerformance($league_id);
        $overperformers = $vegasData['overperformers'] ?? [];
        
        ob_start();
        ?>
        <div class="stats-card dashboard-widget" data-widget-type="exceeding_expectations">
            <div class="widget-header">
                <h2 class="section-title">
                    <i class="fa-solid fa-dice"></i>
                    Exceeding Expectations
                </h2>
                <?php if ($edit_mode): ?>
                <div class="widget-controls">
                    <button class="widget-control-btn" onclick="moveWidget('exceeding_expectations', 'up')" title="Move Up">
                        <i class="fas fa-arrow-up"></i>
                    </button>
                    <button class="widget-control-btn" onclick="moveWidget('exceeding_expectations', 'down')" title="Move Down">
                        <i class="fas fa-arrow-down"></i>
                    </button>
                    <button class="widget-control-btn widget-remove-btn" onclick="removeWidget('exceeding_expectations')" title="Remove Widget">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($overperformers)): ?>
            <div>
                <?php foreach ($overperformers as $index => $team): ?>
                <div class="team-row">
                    <div class="team-info">
                        <span style="font-weight: bold; color: #666; min-width: 25px; display: inline-block;"><?php echo $index + 1; ?>.</span>
                        <img src="<?php echo htmlspecialchars($this->getTeamLogo($team['team_name'])); ?>" 
                             alt="<?php echo htmlspecialchars($team['team_name']); ?>" 
                             style="width: 24px; height: 24px; margin-right: 8px;"
                             onerror="this.style.display='none'">
                        <div>
                            <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($team['team_name']); ?>" 
                               style="text-decoration: none; color: inherit; font-weight: 600;">
                                <?php echo htmlspecialchars($team['team_name']); ?>
                            </a>
                            <?php if ($team['owner']): ?>
                            <div style="font-size: 0.85rem; color: #666;">
                                <?php echo htmlspecialchars($team['owner']); ?> • <?php echo $team['current_record']; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="team-record">
                        <div style="font-size: 0.9rem; color: #666;">Pace: <?php echo number_format($team['current_pace'], 1); ?></div>
                        <div style="color: #28a745; font-weight: bold;">+<?php echo number_format($team['variance'], 1); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="no-data">
                <p>No teams currently exceeding Vegas expectations in your league</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render Falling Short widget (Vegas Over/Under underperformers)
     */
    private function renderFallingShort($user_id, $league_id, $edit_mode) {
        $vegasData = $this->widgetFetcher->getVegasOverUnderPerformance($league_id);
        $underperformers = $vegasData['underperformers'] ?? [];
        
        ob_start();
        ?>
        <div class="stats-card dashboard-widget" data-widget-type="falling_short">
            <div class="widget-header">
                <h2 class="section-title">
                    <i class="fa-solid fa-dice"></i>
                    Falling Short of Expectations
                </h2>
                <?php if ($edit_mode): ?>
                <div class="widget-controls">
                    <button class="widget-control-btn" onclick="moveWidget('falling_short', 'up')" title="Move Up">
                        <i class="fas fa-arrow-up"></i>
                    </button>
                    <button class="widget-control-btn" onclick="moveWidget('falling_short', 'down')" title="Move Down">
                        <i class="fas fa-arrow-down"></i>
                    </button>
                    <button class="widget-control-btn widget-remove-btn" onclick="removeWidget('falling_short')" title="Remove Widget">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($underperformers)): ?>
            <div>
                <?php foreach ($underperformers as $index => $team): ?>
                <div class="team-row">
                    <div class="team-info">
                        <span style="font-weight: bold; color: #666; min-width: 25px; display: inline-block;"><?php echo $index + 1; ?>.</span>
                        <img src="<?php echo htmlspecialchars($this->getTeamLogo($team['team_name'])); ?>" 
                             alt="<?php echo htmlspecialchars($team['team_name']); ?>" 
                             style="width: 24px; height: 24px; margin-right: 8px;"
                             onerror="this.style.display='none'">
                        <div>
                            <a href="/nba-wins-platform/stats/team_data.php?team=<?php echo urlencode($team['team_name']); ?>" 
                               style="text-decoration: none; color: inherit; font-weight: 600;">
                                <?php echo htmlspecialchars($team['team_name']); ?>
                            </a>
                            <?php if ($team['owner']): ?>
                            <div style="font-size: 0.85rem; color: #666;">
                                <?php echo htmlspecialchars($team['owner']); ?> • <?php echo $team['current_record']; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="team-record">
                        <div style="font-size: 0.9rem; color: #666;">Pace: <?php echo number_format($team['current_pace'], 1); ?></div>
                        <div style="color: #dc3545; font-weight: bold;"><?php echo number_format($team['variance'], 1); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="no-data">
                <p>No teams currently falling short of Vegas expectations in your league</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render the Upcoming 5 Games widget
     */
    private function renderUpcomingGames($user_id, $league_id, $edit_mode, $selected_date = null) {
        $upcomingGames = $this->widgetFetcher->getUpcomingGames($user_id, $league_id, $selected_date);
        
        ob_start();
        ?>
        <div class="stats-card dashboard-widget" data-widget-type="upcoming_games">
            <div class="widget-header">
                <h2 class="section-title">
                    <i class="fas fa-calendar-alt"></i>
                    Upcoming Games
                </h2>
                <?php if ($edit_mode): ?>
                <div class="widget-controls">
                    <button class="widget-control-btn" onclick="moveWidget('upcoming_games', 'up')" title="Move Up">
                        <i class="fas fa-arrow-up"></i>
                    </button>
                    <button class="widget-control-btn" onclick="moveWidget('upcoming_games', 'down')" title="Move Down">
                        <i class="fas fa-arrow-down"></i>
                    </button>
                    <button class="widget-control-btn widget-remove-btn" onclick="removeWidget('upcoming_games')" title="Remove Widget">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($upcomingGames)): ?>
            <div class="games-list">
                <?php foreach ($upcomingGames as $game): 
                    $comparisonUrl = "/nba-wins-platform/stats/team_comparison.php?home_team=" . urlencode($game['home_team_code']) . "&away_team=" . urlencode($game['away_team_code']) . "&date=" . urlencode($game['game_date']);
                ?>
                <a href="<?php echo $comparisonUrl; ?>" class="game-list-item clickable" style="display: flex;">
                    <div class="game-list-info">
                        <div class="game-list-date">
                            <?php echo date('M j, Y', strtotime($game['game_date'])); ?>
                        </div>
                        <div class="game-list-matchup">
                            <img src="<?php echo htmlspecialchars($this->getTeamLogo($game['my_team'])); ?>" 
                                 alt="<?php echo htmlspecialchars($game['my_team']); ?>" 
                                 style="width: 20px; height: 20px; vertical-align: middle; margin-right: 5px;"
                                 onerror="this.style.display='none'">
                            <?php echo htmlspecialchars($game['my_team']); ?>
                            <?php echo $game['team_location'] === 'home' ? 'vs' : '@'; ?>
                            <img src="<?php echo htmlspecialchars($this->getTeamLogo($game['opponent'])); ?>" 
                                 alt="<?php echo htmlspecialchars($game['opponent']); ?>" 
                                 style="width: 20px; height: 20px; vertical-align: middle; margin: 0 5px;"
                                 onerror="this.style.display='none'">
                            <?php echo htmlspecialchars($game['opponent']); ?>
                            <?php if (!empty($game['opponent_owner'])): ?>
                                <span style="font-size: 0.85rem; color: #666; font-weight: normal;">
                                    (<?php echo htmlspecialchars($game['opponent_owner']); ?>)
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="no-data">
                <p>No upcoming games scheduled</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render the Last 10 Games widget
     */
    private function renderLastGames($user_id, $league_id, $edit_mode) {
        $lastGames = $this->widgetFetcher->getLastGames($user_id, $league_id);
        
        // Calculate W-L record from last 10 games
        $last10_wins = 0;
        $last10_losses = 0;
        foreach ($lastGames as $game) {
            if ($game['result'] === 'W') {
                $last10_wins++;
            } else if ($game['result'] === 'L') {
                $last10_losses++;
            }
        }
        
        ob_start();
        ?>
        <div class="stats-card dashboard-widget" data-widget-type="last_10_games">
            <div class="widget-header">
                <h2 class="section-title">
                    <i class="fas fa-history"></i>
                    Last 10 Games
                    <?php if (!empty($lastGames)): ?>
                        <span style="font-size: 0.9rem; color: #666; margin-left: 10px;">
                            (<?php echo $last10_wins; ?>-<?php echo $last10_losses; ?>)
                        </span>
                    <?php endif; ?>
                </h2>
                <?php if ($edit_mode): ?>
                <div class="widget-controls">
                    <button class="widget-control-btn" onclick="moveWidget('last_10_games', 'up')" title="Move Up">
                        <i class="fas fa-arrow-up"></i>
                    </button>
                    <button class="widget-control-btn" onclick="moveWidget('last_10_games', 'down')" title="Move Down">
                        <i class="fas fa-arrow-down"></i>
                    </button>
                    <button class="widget-control-btn widget-remove-btn" onclick="removeWidget('last_10_games')" title="Remove Widget">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($lastGames)): ?>
            <div class="games-list">
                <?php foreach (array_reverse($lastGames) as $game): 
                    $teamScore = ($game['team_location'] === 'home') ? $game['home_points'] : $game['away_points'];
                    $oppScore = ($game['team_location'] === 'home') ? $game['away_points'] : $game['home_points'];
                    $gameUrl = "/nba-wins-platform/stats/game_details.php?home_team=" . urlencode($game['home_team_code']) . "&away_team=" . urlencode($game['away_team_code']) . "&date=" . urlencode($game['game_date']);
                ?>
                <a href="<?php echo $gameUrl; ?>" class="game-list-item clickable <?php echo strtolower($game['result']); ?>" style="display: flex;">
                    <div class="game-list-info">
                        <div class="game-list-date">
                            <?php echo date('M j, Y', strtotime($game['game_date'])); ?>
                        </div>
                        <div class="game-list-matchup">
                            <img src="<?php echo htmlspecialchars($this->getTeamLogo($game['my_team'])); ?>" 
                                 alt="<?php echo htmlspecialchars($game['my_team']); ?>" 
                                 style="width: 20px; height: 20px; vertical-align: middle; margin-right: 5px;"
                                 onerror="this.style.display='none'">
                            <?php echo htmlspecialchars($game['my_team']); ?>
                            <?php echo $game['team_location'] === 'home' ? 'vs' : '@'; ?>
                            <img src="<?php echo htmlspecialchars($this->getTeamLogo($game['opponent'])); ?>" 
                                 alt="<?php echo htmlspecialchars($game['opponent']); ?>" 
                                 style="width: 20px; height: 20px; vertical-align: middle; margin: 0 5px;"
                                 onerror="this.style.display='none'">
                            <?php echo htmlspecialchars($game['opponent']); ?>
                            <?php if (!empty($game['opponent_owner'])): ?>
                                <span style="font-size: 0.85rem; color: #666; font-weight: normal;">
                                    (<?php echo htmlspecialchars($game['opponent_owner']); ?>)
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="game-list-result">
                        <div class="game-list-score"><?php echo $teamScore . '-' . $oppScore; ?></div>
                        <div class="game-list-outcome" style="color: <?php echo $game['result'] === 'W' ? '#4CAF50' : '#F44336'; ?>; font-weight: bold;">
                            <?php echo $game['result']; ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="no-data">
                <p>No recent games to display</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render the League Stats widget
     */
    private function renderLeagueStats($user_id, $league_id, $edit_mode) {
        $stats = $this->widgetFetcher->getLeagueStatsAndRivals($user_id, $league_id);
        
        if (!$stats) {
            return '';
        }
        
        ob_start();
        ?>
        <div class="stats-card dashboard-widget" data-widget-type="league_stats">
            <div class="widget-header">
                <h2 class="section-title">League Stats</h2>
                <?php if ($edit_mode): ?>
                <div class="widget-controls">
                    <button class="widget-control-btn" onclick="moveWidget('league_stats', 'up')" title="Move Up">
                        <i class="fas fa-arrow-up"></i>
                    </button>
                    <button class="widget-control-btn" onclick="moveWidget('league_stats', 'down')" title="Move Down">
                        <i class="fas fa-arrow-down"></i>
                    </button>
                    <button class="widget-control-btn widget-remove-btn" onclick="removeWidget('league_stats')" title="Remove Widget">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            
            <div>
                <div class="team-row">
                    <div class="team-info">
                        <span>Total Games Played</span>
                    </div>
                    <div class="team-record">
                        <?php echo $stats['total_games_played']; ?>
                    </div>
                </div>
                <div class="team-row">
                    <div class="team-info">
                        <span>Average Team Record</span>
                    </div>
                    <div class="team-record">
                        <?php echo $stats['avg_wins'] . '-' . $stats['avg_losses']; ?>
                    </div>
                </div>
                <div class="team-row">
                    <div class="team-info">
                        <span>Best Team</span>
                    </div>
                    <div class="team-record">
                        <?php 
                        if ($stats['best_team']) {
                            echo htmlspecialchars($stats['best_team']['team_name']) . ' (' . $stats['best_team']['wins'] . '-' . $stats['best_team']['losses'] . ')';
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </div>
                </div>
                
                <!-- Rivals Section -->
                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
                    <h3 style="margin: 0 0 15px 0; font-size: 1.1rem; color: var(--primary-color); display: flex; align-items: center;">
                        <i class="fas fa-trophy" style="margin-right: 8px;"></i>Rivals
                    </h3>
                    <?php if ($stats['biggest_rival']): ?>
                    <div class="team-row">
                        <div class="team-info">
                            <i class="fas fa-fire" style="color: #ff4444; margin-right: 8px;"></i>
                            <span>Most Wins Against</span>
                        </div>
                        <div class="team-record">
                            <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?php echo $league_id; ?>&user_id=<?php echo $stats['biggest_rival']['opponent_user_id']; ?>" 
                               style="text-decoration: none; color: #007bff; font-weight: 600;">
                                <?php echo htmlspecialchars($stats['biggest_rival']['opponent_name']); ?>
                            </a>
                            <div style="font-size: 0.9em; color: #28a745; margin-top: 2px;">
                                <?php echo $stats['biggest_rival']['wins_against_opponent']; ?>-<?php echo $stats['biggest_rival']['losses_against_opponent']; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($stats['nemesis']): ?>
                    <div class="team-row">
                        <div class="team-info">
                            <i class="fas fa-skull-crossbones" style="color: #721c24; margin-right: 8px;"></i>
                            <span>Most Losses Against</span>
                        </div>
                        <div class="team-record">
                            <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?php echo $league_id; ?>&user_id=<?php echo $stats['nemesis']['opponent_user_id']; ?>" 
                               style="text-decoration: none; color: #007bff; font-weight: 600;">
                                <?php echo htmlspecialchars($stats['nemesis']['opponent_name']); ?>
                            </a>
                            <div style="font-size: 0.9em; color: #dc3545; margin-top: 2px;">
                                <?php echo $stats['nemesis']['wins_against_opponent']; ?>-<?php echo $stats['nemesis']['losses_against_opponent']; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!$stats['biggest_rival'] && !$stats['nemesis']): ?>
                    <div class="no-data">
                        <i class="fas fa-handshake" style="margin-right: 8px;"></i>
                        No head-to-head games yet
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * NEW: Weekly Rankings widget with dropdown
     */
    private function renderWeeklyRankings($user_id, $league_id, $edit_mode) {
        $weeklyData = $this->widgetFetcher->getWeeklyRankings($league_id);
        
        // Process weeks
        $weeks = [];
        foreach ($weeklyData as $record) {
            $weekNum = $record['week_num'];
            if (!isset($weeks[$weekNum])) {
                $weeks[$weekNum] = [
                    'weekNum' => $weekNum,
                    'label' => $record['week_label'],
                    'participants' => []
                ];
            }
            $weeks[$weekNum]['participants'][] = [
                'name' => $record['display_name'],
                'wins' => (int)$record['weekly_wins']
            ];
        }
        
        // Sort and rank participants within each week
        foreach ($weeks as &$week) {
            usort($week['participants'], function($a, $b) {
                return $b['wins'] - $a['wins'];
            });
            
            $rank = 1;
            $prevWins = null;
            $nextRank = 1;
            foreach ($week['participants'] as $idx => &$p) {
                if ($prevWins !== null && $p['wins'] < $prevWins) {
                    $rank = $nextRank;
                }
                $p['rank'] = $rank;
                $prevWins = $p['wins'];
                $nextRank = $idx + 2;
            }
        }
        unset($week, $p);
        
        // Sort weeks descending
        usort($weeks, function($a, $b) {
            return $b['weekNum'] - $a['weekNum'];
        });
        
        $latestWeek = !empty($weeks) ? $weeks[0]['weekNum'] : null;
        
        ob_start();
        ?>
        <div class="stats-card dashboard-widget" data-widget-type="weekly_rankings" id="weekly-rankings-widget">
            <div class="widget-header">
                <h2 class="section-title">
                    <i class="fas fa-trophy"></i>
                    Weekly Rankings
                </h2>
                <?php if ($edit_mode): ?>
                <div class="widget-controls">
                    <button class="widget-control-btn" onclick="moveWidget('weekly_rankings', 'up')" title="Move Up">
                        <i class="fas fa-arrow-up"></i>
                    </button>
                    <button class="widget-control-btn" onclick="moveWidget('weekly_rankings', 'down')" title="Move Down">
                        <i class="fas fa-arrow-down"></i>
                    </button>
                    <button class="widget-control-btn widget-remove-btn" onclick="removeWidget('weekly_rankings')" title="Remove Widget">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($weeks)): ?>
            <div style="text-align: center; margin-bottom: 20px;">
                <select id="weekSelector" onchange="changeWeek()" 
                        style="padding: 10px 15px; border: 2px solid #e0e0e0; border-radius: 6px; font-size: 1rem; cursor: pointer; min-width: 200px;">
                    <?php foreach ($weeks as $week): ?>
                    <option value="<?php echo $week['weekNum']; ?>"><?php echo htmlspecialchars($week['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <?php foreach ($weeks as $week): ?>
            <div class="week-data" data-week="<?php echo $week['weekNum']; ?>" 
                 style="<?php echo $week['weekNum'] === $latestWeek ? '' : 'display: none;'; ?>">
                <?php foreach ($week['participants'] as $p): ?>
                <div class="weekly-rank-row" style="display: flex; justify-content: space-between; align-items: center; 
                            padding: 12px; border-bottom: 1px solid #e0e0e0;
                            <?php if ($p['rank'] === 1): ?>background: linear-gradient(135deg, #ffd700 0%, #ffed4e 100%); font-weight: bold;
                            <?php elseif ($p['rank'] === 2): ?>background: linear-gradient(135deg, #c0c0c0 0%, #e8e8e8 100%); font-weight: bold;
                            <?php elseif ($p['rank'] === 3): ?>background: linear-gradient(135deg, #cd7f32 0%, #d4a76a 100%); font-weight: bold;
                            <?php else: ?>background: #f5f7fa;
                            <?php endif; ?>
                            border-radius: 4px; margin-bottom: 8px;">
                    <div style="display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0;">
                        <span class="weekly-rank-number" style="font-size: 1rem; font-weight: bold; color: var(--primary-color); min-width: 30px; flex-shrink: 0;">
                            <?php echo $p['rank']; ?>.
                        </span>
                        <span class="weekly-participant-name" style="font-size: 0.9rem; word-wrap: break-word; overflow-wrap: break-word; white-space: normal; line-height: 1.3;">
                            <?php echo htmlspecialchars($p['name']); ?>
                        </span>
                    </div>
                    <span class="weekly-wins" style="font-size: 1rem; font-weight: bold; color: var(--primary-color); flex-shrink: 0; margin-left: 8px;">
                        <?php echo $p['wins']; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
            
            <script>
            function changeWeek() {
                const selectedWeek = document.getElementById('weekSelector').value;
                document.querySelectorAll('.week-data').forEach(el => {
                    el.style.display = el.dataset.week === selectedWeek ? 'block' : 'none';
                });
            }
            </script>
            <?php else: ?>
            <div class="no-data">
                <p>No weekly ranking data available yet</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * NEW: Strength of Schedule widget with sorting
     */
    private function renderStrengthOfSchedule($user_id, $league_id, $edit_mode) {
        $sosData = $this->widgetFetcher->getStrengthOfSchedule($league_id);
        
        ob_start();
        ?>
        <div class="stats-card dashboard-widget" data-widget-type="strength_of_schedule" id="sos-widget">
            <div class="widget-header">
                <h2 class="section-title">
                    <i class="fas fa-calendar-check"></i>
                    Strength of Schedule
                </h2>
                <?php if ($edit_mode): ?>
                <div class="widget-controls">
                    <button class="widget-control-btn" onclick="moveWidget('strength_of_schedule', 'up')" title="Move Up">
                        <i class="fas fa-arrow-up"></i>
                    </button>
                    <button class="widget-control-btn" onclick="moveWidget('strength_of_schedule', 'down')" title="Move Down">
                        <i class="fas fa-arrow-down"></i>
                    </button>
                    <button class="widget-control-btn widget-remove-btn" onclick="removeWidget('strength_of_schedule')" title="Remove Widget">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($sosData)): ?>
            <div style="display: flex; justify-content: center; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
                <button onclick="sortSOSWidget('opponent_win_pct')" id="sos-sort-pct" 
                        class="sos-sort-btn active"
                        style="padding: 10px 20px; background-color: var(--primary-color); color: white; border: 2px solid var(--primary-color); 
                               border-radius: 6px; cursor: pointer; font-size: 0.9rem; font-weight: 500; transition: all 0.2s;">
                    <i class="fas fa-percentage"></i> Sort by Opp Win %
                </button>
                <button onclick="sortSOSWidget('total_games')" id="sos-sort-games"
                        class="sos-sort-btn"
                        style="padding: 10px 20px; background-color: #f8f9fa; color: var(--text-color); border: 2px solid #e0e0e0; 
                               border-radius: 6px; cursor: pointer; font-size: 0.9rem; font-weight: 500; transition: all 0.2s;">
                    <i class="fas fa-hashtag"></i> Sort by Games Played
                </button>
            </div>
            
            <table id="sos-table" style="width: 100%; border-collapse: collapse;">
                <thead style="background-color: #f8f9fa; border-bottom: 2px solid #dee2e6;">
                    <tr>
                        <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Participant</th>
                        <th style="padding: 12px 8px; text-align: center; font-weight: 600;">Games</th>
                        <th style="padding: 12px 8px; text-align: center; font-weight: 600;">Opp Win %</th>
                    </tr>
                </thead>
                <tbody id="sos-table-body">
                    <?php foreach ($sosData as $entry): ?>
                    <tr data-games="<?php echo $entry['total_games']; ?>" 
                        data-pct="<?php echo $entry['opponent_win_pct']; ?>" 
                        data-name="<?php echo htmlspecialchars($entry['display_name']); ?>"
                        style="border-bottom: 1px solid #e0e0e0;">
                        <td style="padding: 12px 8px;">
                            <a href="/nba-wins-platform/profiles/participant_profile.php?league_id=<?php echo $league_id; ?>&user_id=<?php echo $entry['user_id']; ?>" 
                               style="text-decoration: none; color: inherit; font-weight: 600;">
                                <?php echo htmlspecialchars($entry['display_name']); ?>
                            </a>
                        </td>
                        <td style="padding: 12px 8px; text-align: center; font-weight: bold; font-size: 1.1rem;">
                            <?php echo $entry['total_games']; ?>
                        </td>
                        <td style="padding: 12px 8px; text-align: center;">
                            <div style="font-weight: bold; font-size: 1.1rem; color: <?php echo $entry['opponent_win_pct'] >= 50 ? '#dc3545' : '#28a745'; ?>;">
                                <?php echo number_format($entry['opponent_win_pct'], 1); ?>%
                            </div>
                            <div style="font-size: 0.8rem; color: #666; margin-top: 2px;">
                                <?php echo $entry['opponent_win_pct'] >= 50 ? 'Tough' : 'Easy'; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <script>
            function sortSOSWidget(sortBy) {
                const tbody = document.getElementById('sos-table-body');
                const rows = Array.from(tbody.getElementsByTagName('tr'));
                
                // Update button styles
                document.querySelectorAll('.sos-sort-btn').forEach(btn => {
                    btn.classList.remove('active');
                    btn.style.backgroundColor = '#f8f9fa';
                    btn.style.color = 'var(--text-color)';
                    btn.style.borderColor = '#e0e0e0';
                });
                
                const activeBtn = document.getElementById(sortBy === 'opponent_win_pct' ? 'sos-sort-pct' : 'sos-sort-games');
                activeBtn.classList.add('active');
                activeBtn.style.backgroundColor = 'var(--primary-color)';
                activeBtn.style.color = 'white';
                activeBtn.style.borderColor = 'var(--primary-color)';
                
                // Sort rows
                rows.sort((a, b) => {
                    if (sortBy === 'opponent_win_pct') {
                        const aVal = parseFloat(a.dataset.pct);
                        const bVal = parseFloat(b.dataset.pct);
                        if (bVal !== aVal) {
                            return bVal - aVal;
                        }
                        return a.dataset.name.localeCompare(b.dataset.name);
                    } else {
                        const aVal = parseInt(a.dataset.games);
                        const bVal = parseInt(b.dataset.games);
                        if (bVal !== aVal) {
                            return bVal - aVal;
                        }
                        return a.dataset.name.localeCompare(b.dataset.name);
                    }
                });
                
                // Reorder rows
                rows.forEach(row => tbody.appendChild(row));
            }
            </script>
            <?php else: ?>
            <div class="no-data">
                <p>No strength of schedule data available yet for your league</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Helper function to get team logo path
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
            return '../nba-wins-platform/public/assets/team_logos/' . $logoMap[$teamName];
        }
        
        $filename = strtolower(str_replace(' ', '_', $teamName)) . '.png';
        return '../nba-wins-platform/public/assets/team_logos/' . $filename;
    }
}
?>