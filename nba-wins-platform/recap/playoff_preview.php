<?php
/**
 * =====================================================================
 * NBA Wins Platform - Playoff Preview Page
 * =====================================================================
 * 
 * Full-screen slide deck covering:
 *   S1:  Title / Key Dates
 *   S2:  Playoff Team Distribution by Participant
 *   S3:  Play-In Tournament (Seeds 7-10)
 *   S4:  East First Round Matchups
 *   S5:  West First Round Matchups
 *   S6:  Championship Odds (Log5 Bracket Simulation)
 *   S7:  Momentum Watch (Last 10 Games)
 *   S8:  Highest Seeded Teams by Participant
 *   S9:  East Playoff Team Stats
 *   S10: West Playoff Team Stats
 *   S11: Playoff Team Killers (Best W% vs Playoff Teams)
 *   S12: Playoff Wins Projection / Upside
 *   S13: Path to the Finals (Interactive Bracket)
 *   S14: Buy Me a Coffee / Supporters
 * =====================================================================
 */

// =====================================================================
// CONFIGURATION & AUTHENTICATION
// =====================================================================

date_default_timezone_set('America/New_York');
require_once '/data/www/default/nba-wins-platform/config/db_connection.php';
require_once '/data/www/default/nba-wins-platform/config/season_config.php';

$season = getSeasonConfig();
requireAuthentication($auth);

$leagueContext = getCurrentLeagueContext($auth);
if (!$leagueContext || !$leagueContext['league_id']) {
    die('Error: No league selected.');
}

$currentLeagueId = $leagueContext['league_id'];
$seasonStartDate = $season['season_start_date'];
$playoffsStartDate = $season['playoffs_start_date'] ?? '2026-04-18';
$playInStartDate = $season['play_in_start_date'] ?? '2026-04-14';

// =====================================================================
// HELPER: Team Logo Path Mapping
// =====================================================================

function getTeamLogo($t) {
    $m = [
        'Atlanta Hawks'           => 'atlanta_hawks.png',
        'Boston Celtics'          => 'boston_celtics.png',
        'Brooklyn Nets'           => 'brooklyn_nets.png',
        'Charlotte Hornets'       => 'charlotte_hornets.png',
        'Chicago Bulls'           => 'chicago_bulls.png',
        'Cleveland Cavaliers'     => 'cleveland_cavaliers.png',
        'Detroit Pistons'         => 'detroit_pistons.png',
        'Indiana Pacers'          => 'indiana_pacers.png',
        'Miami Heat'              => 'miami_heat.png',
        'Milwaukee Bucks'         => 'milwaukee_bucks.png',
        'New York Knicks'         => 'new_york_knicks.png',
        'Orlando Magic'           => 'orlando_magic.png',
        'Philadelphia 76ers'      => 'philadelphia_76ers.png',
        'Toronto Raptors'         => 'toronto_raptors.png',
        'Washington Wizards'      => 'washington_wizards.png',
        'Dallas Mavericks'        => 'dallas_mavericks.png',
        'Denver Nuggets'          => 'denver_nuggets.png',
        'Golden State Warriors'   => 'golden_state_warriors.png',
        'Houston Rockets'         => 'houston_rockets.png',
        'LA Clippers'             => 'la_clippers.png',
        'Los Angeles Clippers'    => 'la_clippers.png',
        'Los Angeles Lakers'      => 'los_angeles_lakers.png',
        'Memphis Grizzlies'       => 'memphis_grizzlies.png',
        'Minnesota Timberwolves'  => 'minnesota_timberwolves.png',
        'New Orleans Pelicans'    => 'new_orleans_pelicans.png',
        'Oklahoma City Thunder'   => 'oklahoma_city_thunder.png',
        'Phoenix Suns'            => 'phoenix_suns.png',
        'Portland Trail Blazers'  => 'portland_trail_blazers.png',
        'Sacramento Kings'        => 'sacramento_kings.png',
        'San Antonio Spurs'       => 'san_antonio_spurs.png',
        'Utah Jazz'               => 'utah_jazz.png',
    ];
    return '/nba-wins-platform/public/assets/team_logos/' . ($m[$t] ?? strtolower(str_replace(' ', '_', $t)) . '.png');
}

// =====================================================================
// DATA QUERIES & PROCESSING
// =====================================================================

try {

    // -----------------------------------------------------------------
    // 1. CONFERENCE STANDINGS (with H2H tiebreaker)
    // -----------------------------------------------------------------

    $stmt = $pdo->query("
        SELECT s.name as team_name,
               nt.abbreviation as team_abbreviation,
               s.win, s.loss, s.percentage,
               s.streak, s.winstreak,
               LOWER(s.conference) as conference
        FROM `2025_2026` s
        JOIN nba_teams nt ON s.name = nt.name
        WHERE LOWER(s.conference) IN ('east','west')
        ORDER BY LOWER(s.conference), s.win DESC, s.percentage DESC
    ");
    $rawStandings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build head-to-head lookup from regular season games
    $h2hAll = [];
    $stmt = $pdo->prepare("
        SELECT home_team, away_team, home_points, away_points
        FROM games
        WHERE status_long IN ('Final','Finished')
          AND date >= ? AND date < ?
    ");
    $stmt->execute([$seasonStartDate, $playInStartDate]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
        if ($g['home_points'] > $g['away_points']) {
            $h2hAll[$g['home_team']][$g['away_team']] = ($h2hAll[$g['home_team']][$g['away_team']] ?? 0) + 1;
        } else {
            $h2hAll[$g['away_team']][$g['home_team']] = ($h2hAll[$g['away_team']][$g['home_team']] ?? 0) + 1;
        }
    }

    // Split by conference
    $byConf = ['east' => [], 'west' => []];
    foreach ($rawStandings as $t) {
        $byConf[$t['conference']][] = $t;
    }

    // Sort with H2H tiebreaker, then assign seeds
    foreach ($byConf as $conf => &$teams) {
        usort($teams, function ($a, $b) use ($h2hAll) {
            if ($a['win'] !== $b['win']) return $b['win'] - $a['win'];
            if ($a['loss'] !== $b['loss']) return $a['loss'] - $b['loss'];
            // H2H tiebreaker
            $aWinsVsB = $h2hAll[$a['team_name']][$b['team_name']] ?? 0;
            $bWinsVsA = $h2hAll[$b['team_name']][$a['team_name']] ?? 0;
            if ($aWinsVsB !== $bWinsVsA) return $bWinsVsA - $aWinsVsB;
            return 0;
        });
        foreach ($teams as $i => &$t) {
            $t['seed'] = $i + 1;
        }
        unset($t);
    }
    unset($teams);

    // Build seeded lookup and identify play-in teams (seeds 7-10)
    $allSeeded = [];
    $playInTeams = [];
    foreach ($byConf as $conf => $teams) {
        foreach ($teams as $t) {
            $allSeeded[$conf][(int)$t['seed']] = $t;
            if ((int)$t['seed'] >= 7 && (int)$t['seed'] <= 10) {
                $playInTeams[$conf][] = $t;
            }
        }
    }

    // Build team-name → abbreviation lookup BEFORE play-in resolution
    // (play-in overwrites seeds in $allSeeded, which can erase teams)
    $teamAbbrLookup = [];
    foreach (['east', 'west'] as $c) {
        foreach ($allSeeded[$c] as $t) {
            $teamAbbrLookup[$t['team_name']] = $t['team_abbreviation'];
        }
    }

    // -----------------------------------------------------------------
    // 2. PLAY-IN RESOLUTION
    //    Determine actual 7/8 seeds from game results
    //    Game 1: #7 vs #8  → Winner = 7-seed
    //    Game 2: #9 vs #10 → Loser eliminated
    //    Game 3: Loser(G1) vs Winner(G2) → Winner = 8-seed
    // -----------------------------------------------------------------

    $playInEliminated = [];
    $playInBracket = [];  // Per-conference bracket state for Slide 3

    // Finished play-in games
    $stmtPI = $pdo->prepare("
        SELECT home_team, away_team, home_points, away_points
        FROM games
        WHERE date >= ? AND date < ?
          AND status_long IN ('Final','Finished')
        ORDER BY date ASC, start_time ASC
    ");
    $stmtPI->execute([$playInStartDate, $playoffsStartDate]);
    $piGames = $stmtPI->fetchAll(PDO::FETCH_ASSOC);

    // Scheduled (upcoming) play-in games
    $stmtPISched = $pdo->prepare("
        SELECT home_team, away_team, home_team_code, away_team_code,
               date, start_time, arena
        FROM games
        WHERE date >= ? AND date < ?
          AND status_long IN ('Scheduled','Not Started','')
        ORDER BY date ASC, start_time ASC
    ");
    $stmtPISched->execute([$playInStartDate, $playoffsStartDate]);
    $piScheduled = $stmtPISched->fetchAll(PDO::FETCH_ASSOC);

    foreach (['east', 'west'] as $conf) {
        $s7  = $allSeeded[$conf][7]['team_name']  ?? null;
        $s8  = $allSeeded[$conf][8]['team_name']  ?? null;
        $s9  = $allSeeded[$conf][9]['team_name']  ?? null;
        $s10 = $allSeeded[$conf][10]['team_name'] ?? null;
        if (!$s7 || !$s8 || !$s9 || !$s10) continue;

        $piTeams = [$s7, $s8, $s9, $s10];

        // Initialize bracket state for this conference
        $playInBracket[$conf] = [
            's7' => $s7, 's8' => $s8, 's9' => $s9, 's10' => $s10,
            'g1' => null, 'g2' => null, 'g3' => null,
            'seed7_confirmed' => null, 'seed8_confirmed' => null,
            'seed8_contenders' => [],
            'upcoming' => [],
        ];

        // Filter play-in games for this conference's teams (chronological)
        $confPIGames = array_values(array_filter($piGames, function ($g) use ($piTeams) {
            return in_array($g['home_team'], $piTeams) && in_array($g['away_team'], $piTeams);
        }));

        // Filter scheduled games for this conference
        $confPISched = array_filter($piScheduled, function ($g) use ($piTeams) {
            return in_array($g['home_team'], $piTeams) && in_array($g['away_team'], $piTeams);
        });
        $playInBracket[$conf]['upcoming'] = array_values($confPISched);

        // ---------------------------------------------------------------
        // Chronological detection: identify G1, G2, G3 from game order
        // rather than assuming which seed pairs play which game.
        //
        // NBA Play-In rules:
        //   G1 winner → 7-seed (done, no more games)
        //   G2 loser  → eliminated (done, no more games)
        //   G3 = G1 loser vs G2 winner → winner = 8-seed
        //
        // Detection: Take the first 2 games (day 1). The game whose winner
        // does NOT appear in any later play-in game is G1 (they secured 7).
        // The other is G2. The 3rd game chronologically is G3.
        // ---------------------------------------------------------------

        $g1Winner = null; $g1Loser = null;
        $g2Winner = null; $g2Loser = null;
        $g3Winner = null; $g3Loser = null;

        // Build game result objects
        $gameResults = [];
        foreach ($confPIGames as $g) {
            $winner = ($g['home_points'] > $g['away_points']) ? $g['home_team'] : $g['away_team'];
            $loser  = ($g['home_points'] > $g['away_points']) ? $g['away_team'] : $g['home_team'];
            $gameResults[] = [
                'winner' => $winner, 'loser' => $loser,
                'home' => $g['home_team'], 'away' => $g['away_team'],
                'home_pts' => (int)$g['home_points'], 'away_pts' => (int)$g['away_points'],
            ];
        }

        if (count($gameResults) >= 2) {
            // We have at least 2 games — determine which is G1 and which is G2
            $first  = $gameResults[0];
            $second = $gameResults[1];

            // Collect all teams that appear in games 3+ (if any)
            $laterTeams = [];
            for ($gi = 2; $gi < count($gameResults); $gi++) {
                $laterTeams[] = $gameResults[$gi]['home'];
                $laterTeams[] = $gameResults[$gi]['away'];
            }
            // Also check scheduled games for teams that will play later
            foreach ($confPISched as $sg) {
                $laterTeams[] = $sg['home_team'];
                $laterTeams[] = $sg['away_team'];
            }

            // G1 winner does NOT appear in later games (they secured 7-seed)
            $firstWinnerInLater  = in_array($first['winner'], $laterTeams);
            $secondWinnerInLater = in_array($second['winner'], $laterTeams);

            if (!$firstWinnerInLater && $secondWinnerInLater) {
                // First game = G1, second = G2
                $g1Data = $first;
                $g2Data = $second;
            } elseif ($firstWinnerInLater && !$secondWinnerInLater) {
                // First game = G2, second = G1
                $g1Data = $second;
                $g2Data = $first;
            } else {
                // Fallback: neither/both in later games — check if loser appears later
                // G2 loser does NOT appear later (eliminated), G1 loser DOES (plays G3)
                $firstLoserInLater  = in_array($first['loser'], $laterTeams);
                $secondLoserInLater = in_array($second['loser'], $laterTeams);

                if ($firstLoserInLater && !$secondLoserInLater) {
                    $g1Data = $first;
                    $g2Data = $second;
                } elseif (!$firstLoserInLater && $secondLoserInLater) {
                    $g1Data = $second;
                    $g2Data = $first;
                } else {
                    // Last resort: higher combined seed total = G2 (9+10=19 vs 7+8=15)
                    $firstSeedSum  = ($allSeeded[$conf][7]['team_name'] === $first['home'] || $allSeeded[$conf][7]['team_name'] === $first['away']) ? 15 : 19;
                    $g1Data = ($firstSeedSum <= 15) ? $first : $second;
                    $g2Data = ($firstSeedSum <= 15) ? $second : $first;
                }
            }

            $g1Winner = $g1Data['winner']; $g1Loser = $g1Data['loser'];
            $g2Winner = $g2Data['winner']; $g2Loser = $g2Data['loser'];

            $playInBracket[$conf]['g1'] = $g1Data;
            $playInBracket[$conf]['g2'] = $g2Data;

            // Update s7/s8/s9/s10 to reflect actual G1/G2 pairings
            $playInBracket[$conf]['s7']  = $g1Data['home'];
            $playInBracket[$conf]['s8']  = $g1Data['away'];
            $playInBracket[$conf]['s9']  = $g2Data['home'];
            $playInBracket[$conf]['s10'] = $g2Data['away'];

            // G3 is the third game
            if (count($gameResults) >= 3) {
                $g3Data = $gameResults[2];
                $g3Winner = $g3Data['winner'];
                $g3Loser  = $g3Data['loser'];
                $playInBracket[$conf]['g3'] = $g3Data;
            }
        } elseif (count($gameResults) === 1) {
            // Only one game played — could be G1 or G2
            $only = $gameResults[0];

            // Check if the winner appears in a scheduled future game
            $winnerInSched = false;
            foreach ($confPISched as $sg) {
                if ($sg['home_team'] === $only['winner'] || $sg['away_team'] === $only['winner']) {
                    $winnerInSched = true; break;
                }
            }

            if (!$winnerInSched) {
                // Winner doesn't play again = G1 (secured 7-seed)
                $g1Winner = $only['winner']; $g1Loser = $only['loser'];
                $playInBracket[$conf]['g1'] = $only;
                $playInBracket[$conf]['s7'] = $only['home'];
                $playInBracket[$conf]['s8'] = $only['away'];
            } else {
                // Winner plays again = G2
                $g2Winner = $only['winner']; $g2Loser = $only['loser'];
                $playInBracket[$conf]['g2'] = $only;
                $playInBracket[$conf]['s9']  = $only['home'];
                $playInBracket[$conf]['s10'] = $only['away'];
            }
        }

        // Apply results: reassign seeds in $allSeeded
        if ($g1Winner) {
            // Find the winner's original data in seeds 7-10
            $winnerData = null; $loserData = null;
            foreach ([7, 8, 9, 10] as $origSeed) {
                $tn = $allSeeded[$conf][$origSeed]['team_name'] ?? '';
                if ($tn === $g1Winner) $winnerData = $allSeeded[$conf][$origSeed];
                if ($tn === $g1Loser)  $loserData  = $allSeeded[$conf][$origSeed];
            }
            if ($winnerData) {
                $allSeeded[$conf][7] = array_merge($winnerData, ['seed' => 7]);
            }
            if ($loserData) {
                $allSeeded[$conf][8] = array_merge($loserData, ['seed' => 8]);
            }
            $playInBracket[$conf]['seed7_confirmed'] = $g1Winner;
        }

        if ($g3Winner) {
            foreach ([7, 8, 9, 10] as $origSeed) {
                if (($allSeeded[$conf][$origSeed]['team_name'] ?? '') === $g3Winner) {
                    $allSeeded[$conf][8] = array_merge($allSeeded[$conf][$origSeed], ['seed' => 8]);
                    break;
                }
            }
            $playInBracket[$conf]['seed8_confirmed'] = $g3Winner;
            $playInEliminated[$g3Loser] = true;
        } elseif ($g1Winner && $g2Winner) {
            // Both G1 and G2 played, G3 pending — contenders are G1 loser + G2 winner
            $playInBracket[$conf]['seed8_contenders'] = [$g1Loser, $g2Winner];
        } elseif ($g1Winner) {
            // Only G1 played — G1 loser + s9/s10 all contend
            $playInBracket[$conf]['seed8_contenders'] = [$g1Loser, $playInBracket[$conf]['s9'], $playInBracket[$conf]['s10']];
        } elseif ($g2Winner) {
            // Only G2 played — G2 winner + s7/s8 all contend
            $playInBracket[$conf]['seed8_contenders'] = [$playInBracket[$conf]['s7'], $playInBracket[$conf]['s8'], $g2Winner];
        }

        if ($g2Loser) {
            $playInEliminated[$g2Loser] = true;
        }
    }

    // NOTE: playInEliminated is merged into eliminatedTeams after section 3

    // -----------------------------------------------------------------
    // 3. PLAYOFF SERIES TRACKING & ELIMINATION
    // -----------------------------------------------------------------

    $seriesData = [];
    $eliminatedTeams = [];

    $stmtS = $pdo->prepare("
        SELECT LEAST(home_team, away_team) AS ta,
               GREATEST(home_team, away_team) AS tb,
               home_team, away_team, home_points, away_points
        FROM games
        WHERE date >= ?
          AND status_long IN ('Final','Finished')
        ORDER BY date ASC
    ");
    $stmtS->execute([$playoffsStartDate]);

    foreach ($stmtS->fetchAll(PDO::FETCH_ASSOC) as $pg) {
        $k = $pg['ta'] . '|' . $pg['tb'];
        if (!isset($seriesData[$k])) {
            $seriesData[$k] = ['wins' => [$pg['ta'] => 0, $pg['tb'] => 0]];
        }
        $w = ($pg['home_points'] > $pg['away_points']) ? $pg['home_team'] : $pg['away_team'];
        $seriesData[$k]['wins'][$w]++;
    }

    // Mark eliminated teams (lost 4 games in a series)
    foreach ($seriesData as $s) {
        $ts = array_keys($s['wins']);
        foreach ($ts as $t) {
            $o = ($t === $ts[0]) ? $ts[1] : $ts[0];
            if ($s['wins'][$o] >= 4) {
                $eliminatedTeams[$t] = true;
            }
        }
    }

    // Now merge play-in eliminated into the main array
    foreach ($playInEliminated as $tn => $v) {
        $eliminatedTeams[$tn] = true;
    }

    // Helper: get series score between two teams
    function getSS($a, $b, $sd) {
        $k = min($a, $b) . '|' . max($a, $b);
        return isset($sd[$k]) ? [$sd[$k]['wins'][$a] ?? 0, $sd[$k]['wins'][$b] ?? 0] : [0, 0];
    }

    // -----------------------------------------------------------------
    // 4. PARTICIPANT → TEAM MAPPING (current league)
    // -----------------------------------------------------------------

    $stmt = $pdo->prepare("
        SELECT COALESCE(u.display_name, lp.participant_name) as pname,
               lpt.team_name,
               u.id as uid,
               u.profile_photo
        FROM league_participants lp
        LEFT JOIN users u ON lp.user_id = u.id
        JOIN league_participant_teams lpt ON lp.id = lpt.league_participant_id
        WHERE lp.league_id = ? AND lp.status = 'active'
    ");
    $stmt->execute([$currentLeagueId]);

    $tp = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tp[$r['team_name']] = [
            'name'  => $r['pname'],
            'uid'   => $r['uid'],
            'photo' => $r['profile_photo'],
        ];
    }

    // -----------------------------------------------------------------
    // 5. PLAYOFF TEAM COUNTS & DETAILS PER PARTICIPANT
    // -----------------------------------------------------------------

    $pCounts  = [];
    $pDetails = [];
    $seenTeams = []; // Deduplicate — play-in can place a team at two seed slots

    foreach ($allSeeded as $conf => $seeds) {
        foreach ($seeds as $seed => $team) {
            if ($seed > 10) continue;
            $tn = $team['team_name'];
            if (isset($seenTeams[$tn])) continue;
            $seenTeams[$tn] = true;
            $p  = $tp[$tn] ?? null;
            if (!$p) continue;
            $n = $p['name'];

            if (!isset($pCounts[$n])) {
                $pCounts[$n] = ['total' => 0, 'east' => 0, 'west' => 0, 'elim' => 0];
            }
            $pCounts[$n]['total']++;
            $pCounts[$n][$conf]++;
            if (isset($eliminatedTeams[$tn])) {
                $pCounts[$n]['elim']++;
            }

            $pDetails[$n][] = [
                'tn'   => $tn,
                'abbr' => $team['team_abbreviation'],
                'seed' => $seed,
                'conf' => $conf,
                'rec'  => $team['win'] . '-' . $team['loss'],
                'elim' => isset($eliminatedTeams[$tn]),
            ];
        }
    }

    // Sort by active (non-eliminated) team count descending
    uasort($pCounts, fn($a, $b) => ($b['total'] - $b['elim']) - ($a['total'] - $a['elim']));

    // -----------------------------------------------------------------
    // 6. FIRST ROUND MATCHUPS
    // -----------------------------------------------------------------

    function buildM($as, $c, $tp, $sd, $el) {
        $ms = [];
        foreach ([[1, 8], [2, 7], [3, 6], [4, 5]] as $pr) {
            $h = $as[$c][$pr[0]] ?? null;
            $l = $as[$c][$pr[1]] ?? null;
            if (!$h || !$l) continue;

            $hp = $tp[$h['team_name']] ?? ['name' => 'Unowned'];
            $lp = $tp[$l['team_name']] ?? ['name' => 'Unowned'];
            $ss = getSS($h['team_name'], $l['team_name'], $sd);

            $ms[] = [
                'h' => array_merge($h, ['p' => $hp['name'], 'el' => isset($el[$h['team_name']]), 'sw' => $ss[0]]),
                'l' => array_merge($l, ['p' => $lp['name'], 'el' => isset($el[$l['team_name']]), 'sw' => $ss[1]]),
            ];
        }
        return $ms;
    }

    $eastM = buildM($allSeeded, 'east', $tp, $seriesData, $eliminatedTeams);
    $westM = buildM($allSeeded, 'west', $tp, $seriesData, $eliminatedTeams);

    // Track which conferences have unresolved 8-seed for matchup display
    $tbd8 = [];
    foreach (['east', 'west'] as $c) {
        if (!empty($playInBracket[$c]['seed8_contenders'])) {
            $contenders = [];
            foreach ($playInBracket[$c]['seed8_contenders'] as $ct) {
                $contenders[] = $teamAbbrLookup[$ct] ?? '?';
            }
            $tbd8[$c] = $contenders;
        }
    }

    // H2H records for matchup display
    $matchH2H = [];
    foreach (array_merge($eastM, $westM) as $m) {
        $t1 = $m['h']['team_name'];
        $t2 = $m['l']['team_name'];
        $w1 = $h2hAll[$t1][$t2] ?? 0;
        $w2 = $h2hAll[$t2][$t1] ?? 0;
        $matchH2H[$t1 . '|' . $t2] = ['w1' => $w1, 'w2' => $w2];
    }

    // -----------------------------------------------------------------
    // 7. CHAMPIONSHIP ODDS (Log5 + Bracket Path Simulation)
    //
    //    Log5:   P(A beats B) = (pA*(1-pB)) / (pA*(1-pB) + pB*(1-pA))
    //    Series: P(win best-of-7) = p^4 * (1 + 4q + 10q^2 + 20q^3)
    // -----------------------------------------------------------------

    $log5 = function ($a, $b) {
        if ($a + $b == 0) return 0.5;
        return ($a * (1 - $b)) / ($a * (1 - $b) + $b * (1 - $a));
    };

    $seriesP = function ($pg) {
        $p = max(0.01, min(0.99, $pg));
        $q = 1 - $p;
        return pow($p, 4) * (1 + 4 * $q + 10 * $q * $q + 20 * $q * $q * $q);
    };

    // Get win% for all playoff teams (seeds 1-8)
    $teamWP = [];
    foreach (['east', 'west'] as $c) {
        for ($s = 1; $s <= 8; $s++) {
            $t = $allSeeded[$c][$s] ?? null;
            if (!$t) continue;
            $total = $t['win'] + $t['loss'];
            $teamWP[$c][$s] = ($total > 0 && !isset($eliminatedTeams[$t['team_name']])) ? $t['win'] / $total : 0.001;
        }
    }

    // Simulate bracket for each conference
    $champProb = []; // team_name => ['conf', 'seed', 'pCF', 'wp']

    foreach (['east', 'west'] as $c) {
        $wp = $teamWP[$c] ?? [];

        // Round 1 probabilities (bracket order: [1v8, 4v5, 3v6, 2v7])
        $r1P = [[1, 8], [4, 5], [3, 6], [2, 7]];
        $pR1 = [];
        foreach ($r1P as $pr) {
            $pg = $log5($wp[$pr[0]] ?? 0.5, $wp[$pr[1]] ?? 0.5);
            $pR1[$pr[0]] = $seriesP($pg);
            $pR1[$pr[1]] = 1 - $pR1[$pr[0]];
        }

        // Semis: Semi1 from [1,8] vs [4,5], Semi2 from [3,6] vs [2,7]
        $pSemi = [];
        $semiFeed = [[[1, 8], [4, 5]], [[3, 6], [2, 7]]];
        foreach ($semiFeed as $sf) {
            foreach ($sf[0] as $s1) {
                foreach ($sf[1] as $s2) {
                    $pg = $log5($wp[$s1] ?? 0.5, $wp[$s2] ?? 0.5);
                    $ps = $seriesP($pg);
                    $pSemi[$s1] = ($pSemi[$s1] ?? 0) + $pR1[$s1] * $pR1[$s2] * $ps;
                    $pSemi[$s2] = ($pSemi[$s2] ?? 0) + $pR1[$s2] * $pR1[$s1] * (1 - $ps);
                }
            }
        }

        // Conf Finals: Semi1 winners vs Semi2 winners
        $pCF = [];
        $cf1 = [1, 8, 4, 5];
        $cf2 = [3, 6, 2, 7];
        foreach ($cf1 as $s1) {
            foreach ($cf2 as $s2) {
                $pg = $log5($wp[$s1] ?? 0.5, $wp[$s2] ?? 0.5);
                $ps = $seriesP($pg);
                $pCF[$s1] = ($pCF[$s1] ?? 0) + ($pSemi[$s1] ?? 0) * ($pSemi[$s2] ?? 0) * $ps;
                $pCF[$s2] = ($pCF[$s2] ?? 0) + ($pSemi[$s2] ?? 0) * ($pSemi[$s1] ?? 0) * (1 - $ps);
            }
        }

        for ($s = 1; $s <= 8; $s++) {
            $tn = $allSeeded[$c][$s]['team_name'] ?? null;
            if ($tn) {
                $champProb[$tn] = [
                    'conf' => $c,
                    'seed' => $s,
                    'pCF'  => $pCF[$s] ?? 0,
                    'wp'   => $wp[$s] ?? 0,
                ];
            }
        }
    }

    // Finals: cross-conference matchups
    $finalProb = [];
    foreach ($champProb as $tn1 => $d1) {
        if ($d1['conf'] !== 'east') continue;
        foreach ($champProb as $tn2 => $d2) {
            if ($d2['conf'] !== 'west') continue;
            $pg = $log5($d1['wp'], $d2['wp']);
            $ps = $seriesP($pg);
            $finalProb[$tn1] = ($finalProb[$tn1] ?? 0) + $d1['pCF'] * $d2['pCF'] * $ps;
            $finalProb[$tn2] = ($finalProb[$tn2] ?? 0) + $d2['pCF'] * $d1['pCF'] * (1 - $ps);
        }
    }

    // Aggregate odds by participant
    $pOdds = [];
    foreach ($finalProb as $tn => $prob) {
        $p = $tp[$tn] ?? null;
        if (!$p) continue;
        $n   = $p['name'];
        $pct = round($prob * 100, 1);

        $abbr = '?';
        foreach (['east', 'west'] as $c) {
            foreach ($allSeeded[$c] as $t) {
                if ($t['team_name'] === $tn) $abbr = $t['team_abbreviation'];
            }
        }

        if (!isset($pOdds[$n])) $pOdds[$n] = ['teams' => [], 'total' => 0];
        $pOdds[$n]['teams'][] = ['abbr' => $abbr, 'odds' => $pct, 'elim' => isset($eliminatedTeams[$tn])];
        $pOdds[$n]['total'] += $pct;
    }

    foreach ($pOdds as &$po) {
        $po['total'] = round($po['total'], 1);
        usort($po['teams'], fn($a, $b) => $b['odds'] <=> $a['odds']);
    }
    unset($po);
    uasort($pOdds, fn($a, $b) => $b['total'] <=> $a['total']);

    // Add play-in eliminated teams to odds (they're at seeds 9/10, not in the simulation)
    foreach ($playInEliminated as $tn => $v) {
        $p = $tp[$tn] ?? null;
        if (!$p) continue;
        $n    = $p['name'];
        $abbr = $teamAbbrLookup[$tn] ?? '?';
        if (!isset($pOdds[$n])) $pOdds[$n] = ['teams' => [], 'total' => 0];
        $pOdds[$n]['teams'][] = ['abbr' => $abbr, 'odds' => 0, 'elim' => true];
    }

    // -----------------------------------------------------------------
    // 8. PLAYOFF WINS PROJECTION
    //
    //    E[wins] = P(lose R1)*1.5 + P(R1 only)*5.5 + P(Semis only)*9.5
    //            + P(CF only)*13.5 + P(champ)*16
    // -----------------------------------------------------------------

    $teamRoundProbs = []; // team_name => [pR1, pSemi, pCF, pChamp]

    foreach (['east', 'west'] as $c) {
        $wp = $teamWP[$c] ?? [];

        $r1P = [[1, 8], [4, 5], [3, 6], [2, 7]];
        $pR1 = [];
        foreach ($r1P as $pr) {
            $pg = $log5($wp[$pr[0]] ?? 0.5, $wp[$pr[1]] ?? 0.5);
            $pR1[$pr[0]] = $seriesP($pg);
            $pR1[$pr[1]] = 1 - $pR1[$pr[0]];
        }

        $pSm = [];
        foreach ([[[1, 8], [4, 5]], [[3, 6], [2, 7]]] as $sf) {
            foreach ($sf[0] as $s1) {
                foreach ($sf[1] as $s2) {
                    $pg = $log5($wp[$s1] ?? 0.5, $wp[$s2] ?? 0.5);
                    $ps = $seriesP($pg);
                    $pSm[$s1] = ($pSm[$s1] ?? 0) + $pR1[$s1] * $pR1[$s2] * $ps;
                    $pSm[$s2] = ($pSm[$s2] ?? 0) + $pR1[$s2] * $pR1[$s1] * (1 - $ps);
                }
            }
        }

        $pCf = [];
        foreach ([1, 8, 4, 5] as $s1) {
            foreach ([3, 6, 2, 7] as $s2) {
                $pg = $log5($wp[$s1] ?? 0.5, $wp[$s2] ?? 0.5);
                $ps = $seriesP($pg);
                $pCf[$s1] = ($pCf[$s1] ?? 0) + ($pSm[$s1] ?? 0) * ($pSm[$s2] ?? 0) * $ps;
                $pCf[$s2] = ($pCf[$s2] ?? 0) + ($pSm[$s2] ?? 0) * ($pSm[$s1] ?? 0) * (1 - $ps);
            }
        }

        for ($s = 1; $s <= 8; $s++) {
            $tn = $allSeeded[$c][$s]['team_name'] ?? null;
            if ($tn) {
                $teamRoundProbs[$tn] = [
                    'pR1'    => $pR1[$s] ?? 0,
                    'pSemi'  => $pSm[$s] ?? 0,
                    'pCF'    => $pCf[$s] ?? 0,
                    'pChamp' => $finalProb[$tn] ?? 0,
                ];
            }
        }
    }

    // Aggregate projections by participant
    $projections = []; // participant => [projected, ceiling, teams[]]

    // -----------------------------------------------------------------
    // True Ceiling Helper: walks the bracket tree for one conference.
    //
    //   At each series node, count how many owned-alive teams are present:
    //     0 owned → 0 wins, unowned team advances
    //     1 owned → 4 wins (sweep ceiling), owned team advances
    //     2 owned → 7 wins (4-3 ceiling), owned team advances
    //
    //   Because the advancer is always "owned" when >=1 owned team enters,
    //   there's no branching — the downstream collision pattern is fixed.
    //
    //   Returns ['wins' => int, 'champ' => bool]
    // -----------------------------------------------------------------

    $confCeiling = function ($aliveSeeds) {
        if (empty($aliveSeeds)) return ['wins' => 0, 'champ' => false];

        $owned = array_flip($aliveSeeds);
        $wins  = 0;

        // Round 1: bracket order
        $r1Pairs = [[1, 8], [4, 5], [3, 6], [2, 7]];
        $r1Adv   = [];
        foreach ($r1Pairs as $pair) {
            $n = (isset($owned[$pair[0]]) ? 1 : 0) + (isset($owned[$pair[1]]) ? 1 : 0);
            if ($n === 2)     { $wins += 7; $r1Adv[] = true; }
            elseif ($n === 1) { $wins += 4; $r1Adv[] = true; }
            else              {              $r1Adv[] = false; }
        }

        // Conf Semis: Semi1 = R1[0] vs R1[1], Semi2 = R1[2] vs R1[3]
        $semiAdv = [];
        for ($i = 0; $i < 2; $i++) {
            $n = ($r1Adv[$i * 2] ? 1 : 0) + ($r1Adv[$i * 2 + 1] ? 1 : 0);
            if ($n === 2)     { $wins += 7; $semiAdv[] = true; }
            elseif ($n === 1) { $wins += 4; $semiAdv[] = true; }
            else              {              $semiAdv[] = false; }
        }

        // Conf Finals: Semi1 winner vs Semi2 winner
        $n     = ($semiAdv[0] ? 1 : 0) + ($semiAdv[1] ? 1 : 0);
        $champ = false;
        if ($n === 2)     { $wins += 7; $champ = true; }
        elseif ($n === 1) { $wins += 4; $champ = true; }

        return ['wins' => $wins, 'champ' => $champ];
    };

    foreach ($teamRoundProbs as $tn => $probs) {
        $p = $tp[$tn] ?? null;
        if (!$p) continue;
        $n = $p['name'];

        if (!isset($projections[$n])) {
            $projections[$n] = ['projected' => 0, 'ceiling' => 0, 'teams' => []];
        }

        // Expected wins: weighted average across all outcomes
        $pLoseR1  = 1 - $probs['pR1'];
        $pR1Only  = $probs['pR1'] - $probs['pSemi'];
        $pSemiOnly = $probs['pSemi'] - $probs['pCF'];
        $pCFOnly  = $probs['pCF'] - $probs['pChamp'];
        $pChamp   = $probs['pChamp'];

        $expected = $pLoseR1 * 1.5
                  + $pR1Only * 5.5
                  + $pSemiOnly * 9.5
                  + $pCFOnly * 13.5
                  + $pChamp * 16;

        $abbr = '?';
        $seed = 0;
        $foundConf = '';
        foreach (['east', 'west'] as $c) {
            foreach ($allSeeded[$c] as $t) {
                if ($t['team_name'] === $tn) {
                    $abbr = $t['team_abbreviation'];
                    $seed = $t['seed'];
                    $foundConf = $c;
                }
            }
        }

        // Determine projected deepest round
        $deepest = 'R1';
        if ($probs['pR1'] > 0.5)   $deepest = 'R2';
        if ($probs['pSemi'] > 0.5) $deepest = 'CF';
        if ($probs['pCF'] > 0.5)   $deepest = 'Finals';

        $projections[$n]['projected'] += round($expected, 1);
        $projections[$n]['teams'][]    = [
            'abbr'     => $abbr,
            'seed'     => $seed,
            'conf'     => $foundConf,
            'expected' => round($expected, 1),
            'deepest'  => $deepest,
            'elim'     => isset($eliminatedTeams[$tn]),
        ];
    }

    // Compute true ceiling per participant using bracket structure
    foreach ($projections as $name => &$proj) {
        $confSeeds = ['east' => [], 'west' => []];
        foreach ($proj['teams'] as $t) {
            if (!$t['elim'] && $t['conf'] && $t['seed'] >= 1 && $t['seed'] <= 8) {
                $confSeeds[$t['conf']][] = $t['seed'];
            }
        }

        $eastResult = $confCeiling($confSeeds['east']);
        $westResult = $confCeiling($confSeeds['west']);
        $ceiling    = $eastResult['wins'] + $westResult['wins'];

        // NBA Finals: cross-conference collision check
        if ($eastResult['champ'] && $westResult['champ']) {
            $ceiling += 7;  // both conf winners owned → 4-3 max
        } elseif ($eastResult['champ'] || $westResult['champ']) {
            $ceiling += 4;  // one owned team in finals → sweep ceiling
        }

        $proj['ceiling'] = $ceiling;
    }
    unset($proj);

    foreach ($projections as &$pr) {
        $pr['projected'] = round($pr['projected'], 1);
        usort($pr['teams'], fn($a, $b) => $b['expected'] <=> $a['expected']);
    }
    unset($pr);
    uasort($projections, fn($a, $b) => $b['projected'] <=> $a['projected']);

    // Add play-in eliminated teams to projections (they're at seeds 9/10, not in the simulation)
    foreach ($playInEliminated as $tn => $v) {
        $p = $tp[$tn] ?? null;
        if (!$p) continue;
        $n    = $p['name'];
        $abbr = $teamAbbrLookup[$tn] ?? '?';
        $seed = 0;
        $foundConf = '';
        // Find their original conference
        foreach (['east', 'west'] as $c) {
            foreach ($allSeeded[$c] as $sd => $t) {
                if ($t['team_name'] === $tn) {
                    $seed = $sd;
                    $foundConf = $c;
                }
            }
        }
        // Also check the playInBracket original teams (may have been overwritten in allSeeded)
        if (!$foundConf) {
            foreach (['east', 'west'] as $c) {
                $br = $playInBracket[$c] ?? [];
                foreach (['s7','s8','s9','s10'] as $sk) {
                    if (($br[$sk] ?? '') === $tn) { $foundConf = $c; break 2; }
                }
            }
        }

        if (!isset($projections[$n])) {
            $projections[$n] = ['projected' => 0, 'ceiling' => 0, 'teams' => []];
        }
        $projections[$n]['teams'][] = [
            'abbr'     => $abbr,
            'seed'     => $seed,
            'conf'     => $foundConf,
            'expected' => 0,
            'deepest'  => 'Elim',
            'elim'     => true,
        ];
    }

    // -----------------------------------------------------------------
    // 9. TEAM STATS TABLE (seeds 1-8, both conferences)
    // -----------------------------------------------------------------

    $cStats = ['east' => [], 'west' => []];

    // Build lookup: team_name → correct seed from $allSeeded
    $correctSeeds = [];
    $playoffTeamNames = [];
    foreach (['east', 'west'] as $c) {
        for ($s = 1; $s <= 8; $s++) {
            $tn = $allSeeded[$c][$s]['team_name'] ?? null;
            if ($tn) {
                $correctSeeds[$tn] = $s;
                $playoffTeamNames[] = $tn;
            }
        }
    }

    if (!empty($playoffTeamNames)) {
        $ph = str_repeat('?,', count($playoffTeamNames) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT seeded.team_name, seeded.abbreviation,
                   seeded.conference, seeded.win, seeded.loss,
                   COALESCE(owner.pname, 'Unowned') as pname,
                   COALESCE(gs.hw, 0) as hw, COALESCE(gs.hl, 0) as hl,
                   COALESCE(gs.aw, 0) as aw, COALESCE(gs.al, 0) as al,
                   COALESCE(ROUND(gs.pf / NULLIF(seeded.win + seeded.loss, 0), 1), 0) as ppg,
                   COALESCE(ROUND(gs.pa / NULLIF(seeded.win + seeded.loss, 0), 1), 0) as oppg
            FROM (
                SELECT s.name as team_name, nt.abbreviation, s.win, s.loss,
                       LOWER(s.conference) as conference
                FROM `2025_2026` s
                JOIN nba_teams nt ON s.name = nt.name
                WHERE s.name IN ($ph)
            ) seeded
            LEFT JOIN (
                SELECT lpt.team_name, COALESCE(u.display_name, lp.participant_name) as pname
                FROM league_participant_teams lpt
                JOIN league_participants lp ON lpt.league_participant_id = lp.id
                LEFT JOIN users u ON lp.user_id = u.id
                WHERE lp.league_id = ? AND lp.status = 'active'
            ) owner ON seeded.team_name = owner.team_name
            LEFT JOIN (
                SELECT team_name,
                       SUM(CASE WHEN ih = 1 AND tp > op THEN 1 ELSE 0 END) as hw,
                       SUM(CASE WHEN ih = 1 AND tp < op THEN 1 ELSE 0 END) as hl,
                       SUM(CASE WHEN ih = 0 AND tp > op THEN 1 ELSE 0 END) as aw,
                       SUM(CASE WHEN ih = 0 AND tp < op THEN 1 ELSE 0 END) as al,
                       SUM(tp) as pf, SUM(op) as pa
                FROM (
                    SELECT g.home_team as team_name, 1 as ih, g.home_points as tp, g.away_points as op
                    FROM games g
                    WHERE g.status_long IN ('Final','Finished') AND g.date >= ? AND g.date < ?
                    UNION ALL
                    SELECT g.away_team, 0, g.away_points, g.home_points
                    FROM games g
                    WHERE g.status_long IN ('Final','Finished') AND g.date >= ? AND g.date < ?
                ) ag
                GROUP BY team_name
            ) gs ON seeded.team_name = gs.team_name
        ");
        $stmt->execute(array_merge(
            $playoffTeamNames,
            [$currentLeagueId, $seasonStartDate, $playInStartDate, $seasonStartDate, $playInStartDate]
        ));

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $t['seed'] = $correctSeeds[$t['team_name']] ?? 0;
            $cStats[$t['conference']][] = $t;
        }

        // Sort by seed
        foreach (['east', 'west'] as $c) {
            usort($cStats[$c], fn($a, $b) => $a['seed'] - $b['seed']);
        }
    }

    // -----------------------------------------------------------------
    // 10. PLAYOFF TEAM KILLERS (best W% vs playoff teams, min 10 games)
    // -----------------------------------------------------------------

    $ptn = [];
    foreach (['east', 'west'] as $c) {
        for ($s = 1; $s <= 8; $s++) {
            if (isset($allSeeded[$c][$s])) {
                $ptn[] = $allSeeded[$c][$s]['team_name'];
            }
        }
    }

    $killers = [];
    if (!empty($ptn)) {
        $ph = str_repeat('?,', count($ptn) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT * FROM (
                SELECT t.team_name, t.pn as participant_name,
                    COUNT(DISTINCT CASE
                        WHEN (g.home_team = t.team_name AND g.away_team IN ($ph) AND g.home_points > g.away_points)
                          OR (g.away_team = t.team_name AND g.home_team IN ($ph) AND g.away_points > g.home_points)
                        THEN g.id END) as pw,
                    COUNT(DISTINCT CASE
                        WHEN (g.home_team = t.team_name AND g.away_team IN ($ph))
                          OR (g.away_team = t.team_name AND g.home_team IN ($ph))
                        THEN g.id END) as pg
                FROM (
                    SELECT lpt.team_name, COALESCE(u.display_name, lp.participant_name) as pn
                    FROM league_participant_teams lpt
                    JOIN league_participants lp ON lpt.league_participant_id = lp.id
                    LEFT JOIN users u ON lp.user_id = u.id
                    WHERE lp.league_id = ? AND lp.status = 'active'
                ) t
                JOIN games g ON (g.home_team = t.team_name OR g.away_team = t.team_name)
                WHERE g.status_long IN ('Final','Finished')
                  AND g.date >= ? AND g.date < ?
                GROUP BY t.team_name, t.pn
            ) rk
            WHERE rk.pg >= 10
            ORDER BY (rk.pw / rk.pg) DESC
            LIMIT 5
        ");
        $stmt->execute(array_merge($ptn, $ptn, $ptn, $ptn, [$currentLeagueId, $seasonStartDate, $playInStartDate]));
        $killers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($killers as &$k) {
            $k['wp'] = $k['pg'] > 0 ? round(($k['pw'] / $k['pg']) * 100, 1) : 0;
        }
        unset($k);
    }

    // -----------------------------------------------------------------
    // 11. HIGHEST SEEDED TEAMS BY PARTICIPANT
    // -----------------------------------------------------------------

    $cTeams = ['east' => [], 'west' => []];
    $seen   = [];

    foreach (['east', 'west'] as $c) {
        foreach ($allSeeded[$c] as $sd => $t) {
            if ($sd > 8) continue;
            $p = $tp[$t['team_name']] ?? null;
            if (!$p || isset($seen[$c . $p['name']])) continue;
            $seen[$c . $p['name']] = 1;
            $cTeams[$c][] = [
                'seed' => $sd,
                'tn'   => $t['team_name'],
                'abbr' => $t['team_abbreviation'],
                'pn'   => $p['name'],
            ];
        }
    }

    // -----------------------------------------------------------------
    // 12. MOMENTUM - Last 10 Games for Each Playoff Team
    // -----------------------------------------------------------------

    $momentum = [];

    foreach (['east', 'west'] as $c) {
        for ($s = 1; $s <= 8; $s++) {
            if (!isset($allSeeded[$c][$s])) continue;
            $t  = $allSeeded[$c][$s];
            $tn = $t['team_name'];

            $sl = $pdo->prepare("
                SELECT CASE
                    WHEN (home_team = ? AND home_points > away_points)
                      OR (away_team = ? AND away_points > home_points) THEN 'W'
                    ELSE 'L'
                END
                FROM games
                WHERE (home_team = ? OR away_team = ?)
                  AND status_long IN ('Final','Finished')
                  AND date >= ?
                ORDER BY date DESC, start_time DESC
                LIMIT 10
            ");
            $sl->execute([$tn, $tn, $tn, $tn, $seasonStartDate]);
            $l10 = $sl->fetchAll(PDO::FETCH_COLUMN);

            $w = count(array_filter($l10, fn($r) => $r === 'W'));
            $p = $tp[$tn] ?? ['name' => 'Unowned'];

            $momentum[] = [
                'tn'      => $tn,
                'abbr'    => $t['team_abbreviation'],
                'conf'    => $c,
                'seed'    => $s,
                'w'       => $w,
                'l'       => 10 - $w,
                'results' => $l10,
                'streak'  => $t['streak'],
                'ws'      => $t['winstreak'],
                'p'       => $p['name'],
            ];
        }
    }

    usort($momentum, fn($a, $b) => $b['w'] - $a['w']);

    // -----------------------------------------------------------------
    // 13. BRACKET DATA (Full Progression Through All Rounds)
    //
    //     Detects series winners and advances them to next round.
    //     Standard bracket order:
    //       R1: [1v8, 4v5, 3v6, 2v7]
    //       Semi1: Winner(1v8) vs Winner(4v5)
    //       Semi2: Winner(3v6) vs Winner(2v7)
    //       CF:    Winner(Semi1) vs Winner(Semi2)
    // -----------------------------------------------------------------

    function getSeriesWinner($teamA, $teamB, $seriesData) {
        $k = min($teamA, $teamB) . '|' . max($teamA, $teamB);
        if (!isset($seriesData[$k])) return null;
        $wA = $seriesData[$k]['wins'][$teamA] ?? 0;
        $wB = $seriesData[$k]['wins'][$teamB] ?? 0;
        if ($wA >= 4) return $teamA;
        if ($wB >= 4) return $teamB;
        return null;
    }

    function findTeamData($name, $allSeeded) {
        foreach (['east', 'west'] as $c) {
            foreach ($allSeeded[$c] as $t) {
                if ($t['team_name'] === $name) return $t;
            }
        }
        return null;
    }

    $bracketData = [];

    foreach (['east', 'west'] as $c) {

        // Round 1 (bracket order)
        $r1      = [];
        $r1Pairs = [[1, 8], [4, 5], [3, 6], [2, 7]];

        foreach ($r1Pairs as $pr) {
            $h = $allSeeded[$c][$pr[0]] ?? null;
            $l = $allSeeded[$c][$pr[1]] ?? null;
            if (!$h || !$l) {
                $r1[] = null;
                continue;
            }
            $ss     = getSS($h['team_name'], $l['team_name'], $seriesData);
            $winner = getSeriesWinner($h['team_name'], $l['team_name'], $seriesData);
            $r1[] = [
                'h_abbr' => $h['team_abbreviation'], 'h_seed' => $pr[0],
                'h_wins' => $ss[0], 'h_name' => $h['team_name'],
                'l_abbr' => $l['team_abbreviation'], 'l_seed' => $pr[1],
                'l_wins' => $ss[1], 'l_name' => $l['team_name'],
                'h_elim' => isset($eliminatedTeams[$h['team_name']]),
                'l_elim' => isset($eliminatedTeams[$l['team_name']]),
                'winner' => $winner,
            ];
        }

        // Conference Semis
        $semis = [];
        for ($i = 0; $i < 2; $i++) {
            $m1 = $r1[$i * 2] ?? null;       // 1v8 or 3v6
            $m2 = $r1[$i * 2 + 1] ?? null;   // 4v5 or 2v7
            $w1 = $m1 ? $m1['winner'] : null;
            $w2 = $m2 ? $m2['winner'] : null;

            if ($w1 && $w2) {
                $t1     = findTeamData($w1, $allSeeded);
                $t2     = findTeamData($w2, $allSeeded);
                $ss     = getSS($w1, $w2, $seriesData);
                $winner = getSeriesWinner($w1, $w2, $seriesData);
                $semis[] = [
                    'h_abbr' => $t1['team_abbreviation'] ?? '?', 'h_seed' => $t1['seed'] ?? 0,
                    'h_wins' => $ss[0], 'h_name' => $w1,
                    'l_abbr' => $t2['team_abbreviation'] ?? '?', 'l_seed' => $t2['seed'] ?? 0,
                    'l_wins' => $ss[1], 'l_name' => $w2,
                    'h_elim' => isset($eliminatedTeams[$w1]),
                    'l_elim' => isset($eliminatedTeams[$w2]),
                    'winner' => $winner,
                ];
            } else {
                $semis[] = null; // TBD
            }
        }

        // Conference Finals
        $confFinal = null;
        $sw1 = $semis[0] ? $semis[0]['winner'] : null;
        $sw2 = $semis[1] ? $semis[1]['winner'] : null;

        if ($sw1 && $sw2) {
            $t1     = findTeamData($sw1, $allSeeded);
            $t2     = findTeamData($sw2, $allSeeded);
            $ss     = getSS($sw1, $sw2, $seriesData);
            $winner = getSeriesWinner($sw1, $sw2, $seriesData);
            $confFinal = [
                'h_abbr' => $t1['team_abbreviation'] ?? '?', 'h_seed' => $t1['seed'] ?? 0,
                'h_wins' => $ss[0], 'h_name' => $sw1,
                'l_abbr' => $t2['team_abbreviation'] ?? '?', 'l_seed' => $t2['seed'] ?? 0,
                'l_wins' => $ss[1], 'l_name' => $sw2,
                'h_elim' => isset($eliminatedTeams[$sw1]),
                'l_elim' => isset($eliminatedTeams[$sw2]),
                'winner' => $winner,
            ];
        }

        $bracketData[$c] = [
            'r1'          => $r1,           // 4 matchups in bracket order
            'semis'       => $semis,        // 2 matchups
            'conf_final'  => $confFinal,
            'conf_winner' => $confFinal ? $confFinal['winner'] : null,
        ];
    }

    // NBA Finals
    $ew = $bracketData['east']['conf_winner'] ?? null;
    $ww = $bracketData['west']['conf_winner'] ?? null;
    $finalsData = null;

    if ($ew && $ww) {
        $t1 = findTeamData($ew, $allSeeded);
        $t2 = findTeamData($ww, $allSeeded);
        $ss = getSS($ew, $ww, $seriesData);
        $finalsData = [
            'h_abbr' => $t1['team_abbreviation'] ?? '?', 'h_seed' => $t1['seed'] ?? 0, 'h_wins' => $ss[0],
            'l_abbr' => $t2['team_abbreviation'] ?? '?', 'l_seed' => $t2['seed'] ?? 0, 'l_wins' => $ss[1],
            'h_conf' => 'east', 'l_conf' => 'west',
        ];
    }

    $bracketData['finals'] = $finalsData;
    $bracketJson = json_encode($bracketData);

    // Round dates for bracket highlighting
    $roundDates = json_encode([
        'playin'      => $playInStartDate,
        'round1'      => $playoffsStartDate,
        'semis'       => $season['conf_semis_start_date']  ?? '2026-05-04',
        'conf_finals' => $season['conf_finals_start_date'] ?? '2026-05-19',
        'finals'      => $season['finals_start_date']      ?? '2026-06-03',
    ]);

} catch (PDOException $e) {
    error_log("Playoff Preview Error: " . $e->getMessage());
    die("Database Error. Please try again later.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="theme-color" content="#0f0f0f">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Playoff Preview - NBA Wins Platform</title>
    <link rel="icon" type="image/png" href="../public/assets/favicon/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>


    <!-- ============================================================= -->
    <!-- STYLES                                                        -->
    <!-- ============================================================= -->
    <style>
        /* --- Base / Background --- */
        * { box-sizing: border-box }
        html { height: 100%; background: #0f0f0f }
        body {
            margin: 0; padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #0f0f0f, #1a1a2e 50%, #16213e);
            background-attachment: fixed;
            color: #fff;
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
        }
        body::before {
            content: '';
            position: fixed; inset: 0;
            background: linear-gradient(135deg, #0f0f0f, #1a1a2e 50%, #16213e);
            z-index: -1;
        }

        /* --- Slide System --- */
        .slide {
            min-height: 100vh; min-height: 100dvh;
            opacity: 0; display: none;
            padding: 1.5rem; padding-bottom: 6rem;
            transition: all .5s ease-in-out;
            transform: translateY(20px);
            overflow-y: auto;
        }
        .slide.active {
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            opacity: 1; transform: translateY(0);
        }
        @media (min-width: 769px) {
            .slide { height: 100vh; height: 100dvh }
        }
        .slide.slide-left  { transform: translateX(-100%) }
        .slide.slide-right { transform: translateX(100%) }

        /* --- Slide Animations --- */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(25px) }
            to   { opacity: 1; transform: translateY(0) }
        }
        .slide.active > * {
            animation: fadeInUp .6s cubic-bezier(.22, 1, .36, 1) forwards;
            opacity: 0;
        }
        .slide.active > *:nth-child(1) { animation-delay: .05s }
        .slide.active > *:nth-child(2) { animation-delay: .15s }
        .slide.active > *:nth-child(3) { animation-delay: .25s }
        .slide.active > *:nth-child(4) { animation-delay: .35s }

        /* --- Emoji Bounce --- */
        @keyframes emojiBounce {
            0%   { transform: scale(.3) rotate(-15deg); opacity: 0 }
            50%  { transform: scale(1.15) rotate(5deg); opacity: 1 }
            100% { transform: scale(1) rotate(0); opacity: 1 }
        }
        .slide-emoji { display: inline-block }
        .slide.active .slide-emoji {
            animation: emojiBounce .7s cubic-bezier(.34, 1.56, .64, 1) forwards !important;
        }

        /* --- Cascade-In Animation --- */
        @keyframes cascadeIn {
            from { opacity: 0; transform: translateY(30px) scale(.97) }
            to   { opacity: 1; transform: translateY(0) scale(1) }
        }
        .slide.active .mc {
            animation: cascadeIn .5s cubic-bezier(.22, 1, .36, 1) forwards;
            opacity: 0;
        }
        .slide.active .mc:nth-child(1) { animation-delay: .15s }
        .slide.active .mc:nth-child(2) { animation-delay: .3s }
        .slide.active .mc:nth-child(3) { animation-delay: .45s }
        .slide.active .mc:nth-child(4) { animation-delay: .6s }

        /* --- Halftime / Title Slide Animations --- */
        @keyframes halftimeFadeUp {
            from { opacity: 0; transform: translateY(30px) }
            to   { opacity: 1; transform: translateY(0) }
        }
        .ht-a { opacity: 0 }
        .slide.active .ht-a { animation: halftimeFadeUp .8s ease forwards }
        .slide.active .ht-a:nth-child(1) { animation-delay: .2s }
        .slide.active .ht-a:nth-child(2) { animation-delay: .6s }
        .slide.active .ht-a:nth-child(3) { animation-delay: 1s }
        .slide.active .ht-a:nth-child(4) { animation-delay: 1.3s }
        .slide.active .ht-a:nth-child(5) { animation-delay: 1.6s }

        .ht-title {
            font-size: 3.5rem; font-weight: 800; text-align: center;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6, #f59e0b);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text; line-height: 1.2;
        }
        .ht-sub {
            font-size: 1.1rem; color: #9ca3af; text-align: center;
            margin-top: 1rem; letter-spacing: .15em; text-transform: uppercase;
        }
        .ht-div {
            width: 80px; height: 3px;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6);
            border-radius: 2px; margin: 1.5rem auto;
        }
        @media (max-width: 768px) {
            .ht-title { font-size: 2.5rem }
        }

        /* --- Cascade-In for .ci elements --- */
        .slide.active .ci {
            animation: cascadeIn .45s cubic-bezier(.22, 1, .36, 1) forwards;
            opacity: 0;
        }
        .slide.active .ci:nth-child(1) { animation-delay: .1s }
        .slide.active .ci:nth-child(2) { animation-delay: .18s }
        .slide.active .ci:nth-child(3) { animation-delay: .26s }
        .slide.active .ci:nth-child(4) { animation-delay: .34s }
        .slide.active .ci:nth-child(5) { animation-delay: .42s }
        .slide.active .ci:nth-child(6) { animation-delay: .5s }
        .slide.active .ci:nth-child(7) { animation-delay: .58s }
        .slide.active .ci:nth-child(8) { animation-delay: .66s }

        /* --- Round Highlight --- */
        .rnd-active {
            color: #f59e0b !important;
            font-weight: 700 !important;
            text-shadow: 0 0 12px rgba(245, 158, 11, .4);
        }

        /* --- Progress Bar --- */
        .pb {
            height: 10px;
            background: rgba(255, 255, 255, .08);
            border-radius: 5px; overflow: hidden;
        }
        .pbf {
            height: 100%; border-radius: 5px;
            transition: width 1s cubic-bezier(.22, 1, .36, 1);
            background: linear-gradient(90deg, #3b82f6, #8b5cf6);
        }

        /* --- Matchup Card --- */
        .mc {
            background: rgba(255, 255, 255, .05);
            border: 1px solid rgba(255, 255, 255, .07);
            border-radius: 10px; padding: .7rem 1rem;
            transition: transform .2s, border-color .2s;
        }
        .mc:hover {
            transform: translateY(-2px);
            border-color: rgba(255, 255, 255, .15);
        }

        /* --- Eliminated / Badges --- */
        .elim {
            text-decoration: line-through;
            color: rgba(255, 255, 255, .35);
        }
        .be {
            background: rgba(239, 68, 68, .2); color: #fca5a5;
            font-size: .6rem; padding: 2px 6px;
            border-radius: 999px; font-weight: 600;
        }
        .bp {
            background: rgba(234, 179, 8, .2); color: #fde047;
            font-size: .6rem; padding: 2px 6px;
            border-radius: 999px; font-weight: 600;
        }
        .bc {
            background: rgba(34, 197, 94, .2); color: #86efac;
            font-size: .6rem; padding: 2px 6px;
            border-radius: 999px; font-weight: 600;
        }
        .bt {
            background: rgba(59, 130, 246, .15); color: #93c5fd;
            font-size: .6rem; padding: 2px 6px;
            border-radius: 999px; font-weight: 600;
        }

        /* --- Play-In Bracket --- */
        .pi-game {
            background: rgba(255,255,255,.05);
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 10px; padding: .6rem .8rem;
            margin-bottom: .5rem;
            min-height: 4.2rem;
        }
        .pi-game.pi-final { border-color: rgba(34,197,94,.3) }
        .pi-game.pi-sched { border-color: rgba(234,179,8,.2) }
        .pi-team-row {
            display: flex; align-items: center; gap: .4rem;
            padding: 3px 0; font-size: .85rem;
        }
        .pi-team-row.pi-winner { font-weight: 700 }
        .pi-team-row.pi-loser { opacity: .4 }
        .pi-seed-num {
            width: 1.3rem; text-align: center; font-size: .7rem;
            color: rgba(255,255,255,.4); font-weight: 600;
        }
        .pi-score {
            margin-left: auto; font-variant-numeric: tabular-nums;
            font-weight: 700; font-size: .85rem; min-width: 1.5rem; text-align: right;
        }
        .pi-label {
            font-size: .65rem; color: rgba(255,255,255,.35);
            text-transform: uppercase; letter-spacing: .08em;
            margin-bottom: 3px; font-weight: 600;
        }
        .pi-result-tag {
            font-size: .6rem; padding: 1px 6px; border-radius: 999px;
            font-weight: 600; margin-left: .5rem;
        }
        .pi-result-tag.tag-7 { background: rgba(34,197,94,.2); color: #86efac }
        .pi-result-tag.tag-elim { background: rgba(239,68,68,.2); color: #fca5a5 }
        .pi-result-tag.tag-8 { background: rgba(34,197,94,.2); color: #86efac }
        .pi-sched-time {
            font-size: .7rem; color: rgba(234,179,8,.7);
        }

        /* --- Series Score --- */
        .ss {
            font-size: 1.25rem; font-weight: 800;
            font-variant-numeric: tabular-nums;
            min-width: 1.25rem; text-align: center;
        }

        /* --- Participant Pill --- */
        .pp {
            font-size: .7rem; padding: 2px 8px;
            background: rgba(255, 255, 255, .08);
            border-radius: 999px; white-space: nowrap;
        }

        /* --- Team Logo --- */
        .tl { width: 28px; height: 28px; object-fit: contain }

        /* --- Toggle Detail List --- */
        .tdl {
            max-height: 0; overflow: hidden;
            transition: max-height .35s ease, opacity .3s;
            opacity: 0;
        }
        .tdl.ex { max-height: 500px; opacity: 1 }

        /* --- Result Dot (W/L) --- */
        .rd {
            width: 18px; height: 18px; border-radius: 4px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: .55rem; font-weight: 700;
        }
        .rd.w { background: rgba(74, 222, 128, .2); color: #4ade80 }
        .rd.l { background: rgba(248, 113, 113, .15); color: #f87171 }

        /* --- Navigation Button --- */
        .nb {
            background: rgba(255, 255, 255, .12);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, .15);
            color: #fff; border-radius: 999px;
            padding: .65rem 1.25rem; font-size: .9rem;
            font-weight: 600; cursor: pointer;
            transition: all .2s;
        }
        .nb:hover { background: rgba(255, 255, 255, .2) }

        /* --- Slide Dots --- */
        .sd {
            position: fixed; bottom: 1.25rem; left: 50%;
            transform: translateX(-50%); display: flex; gap: 6px;
            z-index: 100; padding-bottom: env(safe-area-inset-bottom);
        }
        .sdt {
            width: 8px; height: 8px; border-radius: 50%;
            background: rgba(255, 255, 255, .2);
            cursor: pointer; transition: all .3s;
        }
        .sdt.active { background: #3b82f6; transform: scale(1.3) }

        /* --- Stats Table --- */
        .st { width: 100%; font-size: .8rem; border-collapse: collapse }
        .st th {
            padding: .5rem; text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, .15);
            color: rgba(255, 255, 255, .5);
            font-weight: 600; font-size: .65rem;
            text-transform: uppercase; letter-spacing: .05em;
        }
        .st td {
            padding: .5rem;
            border-bottom: 1px solid rgba(255, 255, 255, .06);
        }
        .st tr:hover { background: rgba(255, 255, 255, .04) }

        /* --- Bracket Slots --- */
        .bk-slot {
            background: rgba(255, 255, 255, .06);
            border: 1px solid rgba(255, 255, 255, .1);
            border-radius: 6px; padding: 4px 10px;
            font-size: .75rem; display: flex;
            align-items: center; gap: 6px;
            min-width: 110px; justify-content: space-between;
        }
        .bk-slot.winner { border-color: rgba(74, 222, 128, .4); background: rgba(74, 222, 128, .08) }
        .bk-slot.loser  { opacity: .4 }
        .bk-line { background: rgba(255, 255, 255, .15) }
    </style>
</head>
<body>

<div id="slides">

    <!-- ============================================================= -->
    <!-- SLIDE 1: Title                                                -->
    <!-- ============================================================= -->
    <div class="slide active" id="s1">
        <div class="ht-a">
            <img src="/nba-wins-platform/public/assets/league_logos/nba_champ.png" alt="NBA Playoffs" style="width:120px;height:auto;object-fit:contain;margin:0 auto;display:block">
        </div>
        <div class="ht-a">
            <h1 class="ht-title">NBA Playoffs<br>2026</h1>
        </div>
        <div class="ht-a">
            <div class="ht-div"></div>
        </div>
        <div class="ht-a">
            <div class="ht-sub">
                Play-In: <?php echo date('M j', strtotime($playInStartDate)); ?>
                &bull;
                Round 1: <?php echo date('M j', strtotime($playoffsStartDate)); ?>
            </div>
        </div>
        <div class="ht-a">
            <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:.5rem 1.5rem;margin-top:1.25rem;font-size:.8rem;color:rgba(255,255,255,.35)">
                <span>Conf Semis: <?php echo date('M j', strtotime($season['conf_semis_start_date'] ?? '2026-05-04')); ?></span>
                <span>Conf Finals: <?php echo date('M j', strtotime($season['conf_finals_start_date'] ?? '2026-05-19')); ?></span>
                <span>NBA Finals: <?php echo date('M j', strtotime($season['finals_start_date'] ?? '2026-06-03')); ?></span>
            </div>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- SLIDE 2: Playoff Team Distribution                            -->
    <!-- ============================================================= -->
    <div class="slide" id="s2">
        <div class="text-6xl mb-8 slide-emoji">📊</div>
        <h2 class="text-3xl font-bold mb-8 text-center">Playoff Team Distribution</h2>
        <div class="w-full max-w-3xl space-y-4">
            <?php $di = 0; foreach ($pCounts as $name => $d): $act = $d['total'] - $d['elim']; ?>
            <div class="ci">
                <div class="flex justify-between mb-1.5 items-center cursor-pointer" onclick="toggleT(<?php echo $di; ?>)">
                    <span class="font-semibold flex items-center gap-2">
                        <?php echo htmlspecialchars($name); ?>
                        <svg id="ar-<?php echo $di; ?>" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="transition:transform .3s;opacity:.4">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    </span>
                    <span class="flex items-center gap-2 flex-wrap justify-end">
                        <span class="px-2 py-0.5 rounded text-xs" style="background:rgba(59,130,246,.2);color:#93c5fd">E: <?php echo $d['east']; ?></span>
                        <span class="px-2 py-0.5 rounded text-xs" style="background:rgba(239,68,68,.2);color:#fca5a5">W: <?php echo $d['west']; ?></span>
                        <?php if ($d['elim'] > 0): ?>
                            <span class="px-2 py-0.5 rounded text-xs" style="background:rgba(107,114,128,.3);color:#d1d5db">Out: <?php echo $d['elim']; ?></span>
                        <?php endif; ?>
                        <span class="font-bold" data-count="<?php echo $act; ?>">0</span>
                        <span class="text-sm" style="color:rgba(255,255,255,.5)">teams</span>
                    </span>
                </div>
                <div class="pb mb-1">
                    <div class="pbf" data-target-width="<?php echo min(100, $act / 10 * 100); ?>%" style="width:0%"></div>
                </div>
                <div class="tdl" id="tl-<?php echo $di; ?>">
                    <div class="flex flex-wrap gap-2 pt-2 pb-1">
                        <?php
                        $ts = $pDetails[$name] ?? [];
                        usort($ts, fn($a, $b) => $a['seed'] - $b['seed']);
                        foreach ($ts as $t):
                        ?>
                        <div class="flex items-center gap-1.5 px-2 py-1 rounded-lg text-xs <?php echo $t['elim'] ? 'opacity-40 line-through' : ''; ?>" style="background:rgba(255,255,255,.06)">
                            <img src="<?php echo htmlspecialchars(getTeamLogo($t['tn'])); ?>" style="width:18px;height:18px;object-fit:contain">
                            <span class="font-semibold"><?php echo $t['abbr']; ?></span>
                            <span style="color:rgba(255,255,255,.4)">#<?php echo $t['seed']; ?> <?php echo strtoupper(substr($t['conf'], 0, 1)); ?></span>
                            <span style="color:rgba(255,255,255,.35)"><?php echo $t['rec']; ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php $di++; endforeach; ?>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- SLIDE 3: Play-In Tournament                                   -->
    <!-- ============================================================= -->
    <div class="slide" id="s3">
        <div class="text-6xl mb-8 slide-emoji">🎯</div>
        <h2 class="text-3xl font-bold mb-2 text-center">Play-In Tournament</h2>
        <div class="text-center mb-6" style="color:rgba(255,255,255,.4)">Seeds 7-10 battle for final playoff spots</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 max-w-4xl w-full">
            <?php foreach (['east' => 'Eastern Conference', 'west' => 'Western Conference'] as $c => $cl):
                $br = $playInBracket[$c] ?? null;
                if (!$br) continue;
                // Original seed numbers for display (before play-in resolution)
                $origSeeds = [
                    $br['s7'] => 7, $br['s8'] => 8,
                    $br['s9'] => 9, $br['s10'] => 10,
                ];
                // Find the original seeds for s7/s8/s9/s10
                $s7Abbr  = $teamAbbrLookup[$br['s7']] ?? '?';
                $s8Abbr  = $teamAbbrLookup[$br['s8']] ?? '?';
                $s9Abbr  = $teamAbbrLookup[$br['s9']] ?? '?';
                $s10Abbr = $teamAbbrLookup[$br['s10']] ?? '?';
            ?>
            <div>
                <h3 class="text-lg font-semibold mb-4 text-center" style="color:rgba(255,255,255,.6)"><?php echo $cl; ?></h3>

                <?php // ---- GAME 1: #7 vs #8 ---- ?>
                <div class="pi-label">Game 1 · #7 vs #8 → Winner = 7-seed</div>
                <?php if ($br['g1']): $g = $br['g1']; ?>
                <div class="pi-game pi-final ci">
                    <?php
                        $hAbbr = $teamAbbrLookup[$g['home']] ?? '?';
                        $aAbbr = $teamAbbrLookup[$g['away']] ?? '?';
                        $hWon = $g['home_pts'] > $g['away_pts'];
                    ?>
                    <div class="pi-team-row <?php echo $hWon ? 'pi-winner' : 'pi-loser'; ?>">
                        <span class="pi-seed-num"><?php echo $origSeeds[$g['home']] ?? ''; ?></span>
                        <img src="<?php echo htmlspecialchars(getTeamLogo($g['home'])); ?>" style="width:20px;height:20px">
                        <span><?php echo $hAbbr; ?></span>
                        <?php if ($hWon): ?><span class="pi-result-tag tag-7">7-SEED ✓</span><?php endif; ?>
                        <span class="pi-score"><?php echo $g['home_pts']; ?></span>
                    </div>
                    <div class="pi-team-row <?php echo !$hWon ? 'pi-winner' : 'pi-loser'; ?>">
                        <span class="pi-seed-num"><?php echo $origSeeds[$g['away']] ?? ''; ?></span>
                        <img src="<?php echo htmlspecialchars(getTeamLogo($g['away'])); ?>" style="width:20px;height:20px">
                        <span><?php echo $aAbbr; ?></span>
                        <?php if (!$hWon): ?><span class="pi-result-tag tag-7">7-SEED ✓</span><?php endif; ?>
                        <span class="pi-score"><?php echo $g['away_pts']; ?></span>
                    </div>
                </div>
                <?php else: ?>
                <?php
                    // Check if there's a scheduled game for 7v8
                    $g1Sched = null;
                    $g1Pair = [$br['s7'], $br['s8']];
                    foreach ($br['upcoming'] as $ug) {
                        if (in_array($ug['home_team'], $g1Pair) && in_array($ug['away_team'], $g1Pair)) {
                            $g1Sched = $ug; break;
                        }
                    }
                ?>
                <div class="pi-game pi-sched ci">
                    <div class="pi-team-row">
                        <span class="pi-seed-num">7</span>
                        <img src="<?php echo htmlspecialchars(getTeamLogo($br['s7'])); ?>" style="width:20px;height:20px">
                        <span><?php echo $s7Abbr; ?></span>
                    </div>
                    <div class="pi-team-row">
                        <span class="pi-seed-num">8</span>
                        <img src="<?php echo htmlspecialchars(getTeamLogo($br['s8'])); ?>" style="width:20px;height:20px">
                        <span><?php echo $s8Abbr; ?></span>
                        <?php if ($g1Sched): ?>
                        <span class="pi-sched-time" style="margin-left:auto"><?php echo date('D M j · g:i A', strtotime($g1Sched['start_time'] ?: $g1Sched['date'])); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php // ---- GAME 2: #9 vs #10 ---- ?>
                <div class="pi-label" style="margin-top:8px">Game 2 · #9 vs #10 → Loser eliminated</div>
                <?php if ($br['g2']): $g = $br['g2']; ?>
                <div class="pi-game pi-final ci">
                    <?php
                        $hAbbr = $teamAbbrLookup[$g['home']] ?? '?';
                        $aAbbr = $teamAbbrLookup[$g['away']] ?? '?';
                        $hWon = $g['home_pts'] > $g['away_pts'];
                    ?>
                    <div class="pi-team-row <?php echo $hWon ? 'pi-winner' : 'pi-loser'; ?>">
                        <span class="pi-seed-num"><?php echo $origSeeds[$g['home']] ?? ''; ?></span>
                        <img src="<?php echo htmlspecialchars(getTeamLogo($g['home'])); ?>" style="width:20px;height:20px">
                        <span><?php echo $hAbbr; ?></span>
                        <?php if (!$hWon): ?><span class="pi-result-tag tag-elim">ELIMINATED</span><?php endif; ?>
                        <span class="pi-score"><?php echo $g['home_pts']; ?></span>
                    </div>
                    <div class="pi-team-row <?php echo !$hWon ? 'pi-winner' : 'pi-loser'; ?>">
                        <span class="pi-seed-num"><?php echo $origSeeds[$g['away']] ?? ''; ?></span>
                        <img src="<?php echo htmlspecialchars(getTeamLogo($g['away'])); ?>" style="width:20px;height:20px">
                        <span><?php echo $aAbbr; ?></span>
                        <?php if ($hWon): ?><span class="pi-result-tag tag-elim">ELIMINATED</span><?php endif; ?>
                        <span class="pi-score"><?php echo $g['away_pts']; ?></span>
                    </div>
                </div>
                <?php else: ?>
                <?php
                    $g2Sched = null;
                    $g2Pair = [$br['s9'], $br['s10']];
                    foreach ($br['upcoming'] as $ug) {
                        if (in_array($ug['home_team'], $g2Pair) && in_array($ug['away_team'], $g2Pair)) {
                            $g2Sched = $ug; break;
                        }
                    }
                ?>
                <div class="pi-game pi-sched ci">
                    <div class="pi-team-row">
                        <span class="pi-seed-num">9</span>
                        <img src="<?php echo htmlspecialchars(getTeamLogo($br['s9'])); ?>" style="width:20px;height:20px">
                        <span><?php echo $s9Abbr; ?></span>
                    </div>
                    <div class="pi-team-row">
                        <span class="pi-seed-num">10</span>
                        <img src="<?php echo htmlspecialchars(getTeamLogo($br['s10'])); ?>" style="width:20px;height:20px">
                        <span><?php echo $s10Abbr; ?></span>
                        <?php if ($g2Sched): ?>
                        <span class="pi-sched-time" style="margin-left:auto"><?php echo date('D M j · g:i A', strtotime($g2Sched['start_time'] ?: $g2Sched['date'])); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php // ---- GAME 3: Loser(G1) vs Winner(G2) → 8-seed ---- ?>
                <div class="pi-label" style="margin-top:8px">Game 3 · For the 8-seed</div>
                <?php if ($br['g3']): $g = $br['g3']; ?>
                <div class="pi-game pi-final ci">
                    <?php
                        $hAbbr = $teamAbbrLookup[$g['home']] ?? '?';
                        $aAbbr = $teamAbbrLookup[$g['away']] ?? '?';
                        $hWon = $g['home_pts'] > $g['away_pts'];
                    ?>
                    <div class="pi-team-row <?php echo $hWon ? 'pi-winner' : 'pi-loser'; ?>">
                        <span class="pi-seed-num"></span>
                        <img src="<?php echo htmlspecialchars(getTeamLogo($g['home'])); ?>" style="width:20px;height:20px">
                        <span><?php echo $hAbbr; ?></span>
                        <?php if ($hWon): ?><span class="pi-result-tag tag-8">8-SEED ✓</span><?php endif; ?>
                        <?php if (!$hWon): ?><span class="pi-result-tag tag-elim">ELIMINATED</span><?php endif; ?>
                        <span class="pi-score"><?php echo $g['home_pts']; ?></span>
                    </div>
                    <div class="pi-team-row <?php echo !$hWon ? 'pi-winner' : 'pi-loser'; ?>">
                        <span class="pi-seed-num"></span>
                        <img src="<?php echo htmlspecialchars(getTeamLogo($g['away'])); ?>" style="width:20px;height:20px">
                        <span><?php echo $aAbbr; ?></span>
                        <?php if (!$hWon): ?><span class="pi-result-tag tag-8">8-SEED ✓</span><?php endif; ?>
                        <?php if ($hWon): ?><span class="pi-result-tag tag-elim">ELIMINATED</span><?php endif; ?>
                        <span class="pi-score"><?php echo $g['away_pts']; ?></span>
                    </div>
                </div>
                <?php elseif ($br['g1'] && $br['g2']): ?>
                <?php
                    // Both G1 and G2 played — exact G3 matchup known
                    $g3Sched = null;
                    $g3Pair = [$br['g1']['loser'], $br['g2']['winner']];
                    foreach ($br['upcoming'] as $ug) {
                        if (in_array($ug['home_team'], $g3Pair) && in_array($ug['away_team'], $g3Pair)) {
                            $g3Sched = $ug; break;
                        }
                    }
                ?>
                <div class="pi-game pi-sched ci">
                    <div class="pi-team-row">
                        <span class="pi-seed-num"></span>
                        <img src="<?php echo htmlspecialchars(getTeamLogo($br['g1']['loser'])); ?>" style="width:20px;height:20px">
                        <span><?php echo $teamAbbrLookup[$br['g1']['loser']] ?? '?'; ?></span>
                    </div>
                    <div class="pi-team-row">
                        <span class="pi-seed-num"></span>
                        <img src="<?php echo htmlspecialchars(getTeamLogo($br['g2']['winner'])); ?>" style="width:20px;height:20px">
                        <span><?php echo $teamAbbrLookup[$br['g2']['winner']] ?? '?'; ?></span>
                        <?php if ($g3Sched): ?>
                        <span class="pi-sched-time" style="margin-left:auto"><?php echo date('D M j · g:i A', strtotime($g3Sched['start_time'] ?: $g3Sched['date'])); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php elseif ($br['g2'] && !$br['g1']): ?>
                <?php // G2 played, G1 not yet — show G2 winner vs "Loser of G1" ?>
                <div class="pi-game pi-sched ci">
                    <div class="pi-team-row">
                        <span class="pi-seed-num"></span>
                        <img src="<?php echo htmlspecialchars(getTeamLogo($br['g2']['winner'])); ?>" style="width:20px;height:20px">
                        <span style="font-weight:600"><?php echo $teamAbbrLookup[$br['g2']['winner']] ?? '?'; ?></span>
                    </div>
                    <div class="pi-team-row">
                        <span class="pi-seed-num"></span>
                        <span style="color:rgba(255,255,255,.5);font-size:.8rem">vs Loser of <?php echo $teamAbbrLookup[$br['s7']] ?? '?'; ?> / <?php echo $teamAbbrLookup[$br['s8']] ?? '?'; ?></span>
                    </div>
                </div>
                <?php elseif ($br['g1'] && !$br['g2']): ?>
                <?php // G1 played, G2 not yet — show G1 loser vs "Winner of G2" ?>
                <div class="pi-game pi-sched ci">
                    <div class="pi-team-row">
                        <span class="pi-seed-num"></span>
                        <img src="<?php echo htmlspecialchars(getTeamLogo($br['g1']['loser'])); ?>" style="width:20px;height:20px">
                        <span style="font-weight:600"><?php echo $teamAbbrLookup[$br['g1']['loser']] ?? '?'; ?></span>
                    </div>
                    <div class="pi-team-row">
                        <span class="pi-seed-num"></span>
                        <span style="color:rgba(255,255,255,.5);font-size:.8rem">vs Winner of <?php echo $teamAbbrLookup[$br['s9']] ?? '?'; ?> / <?php echo $teamAbbrLookup[$br['s10']] ?? '?'; ?></span>
                    </div>
                </div>
                <?php else: ?>
                <div class="pi-game ci" style="text-align:center;color:rgba(255,255,255,.3);font-size:.8rem;padding:.8rem">
                    TBD — Awaiting Games 1 &amp; 2
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="mt-4 text-xs p-3 rounded-lg max-w-md w-full" style="background:rgba(234,179,8,.05);border:1px solid rgba(234,179,8,.15);color:rgba(255,255,255,.5)">
            7 vs 8 → Winner = 7th seed<br>
            9 vs 10 → Loser eliminated<br>
            Loser 7v8 vs Winner 9v10 → 8th seed
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- SLIDE 4: East First Round Matchups                            -->
    <!-- ============================================================= -->
    <div class="slide" id="s4">
        <h2 class="text-2xl font-bold mb-1 text-center">First Round Matchups</h2>
        <h3 class="text-base mb-5 text-center" style="color:rgba(255,255,255,.5)">Eastern Conference</h3>
        <div class="w-full max-w-2xl space-y-3">
            <?php foreach ($eastM as $m):
                $h  = $m['h'];
                $l  = $m['l'];
                $hh = $matchH2H[$h['team_name'] . '|' . $l['team_name']] ?? null;
                $lSeed = (int)$l['seed'];
                $is8Tbd = ($lSeed === 8 && isset($tbd8['east']));
                $is7Confirmed = ($lSeed === 7 && !empty($playInBracket['east']['seed7_confirmed']));
                $is8Confirmed = ($lSeed === 8 && !empty($playInBracket['east']['seed8_confirmed']));
            ?>
            <div class="mc">
                <!-- Higher Seed -->
                <div class="flex justify-between items-center mb-2">
                    <div class="flex items-center gap-2">
                        <img src="<?php echo htmlspecialchars(getTeamLogo($h['team_name'])); ?>" class="tl">
                        <span style="color:rgba(255,255,255,.4);font-size:.75rem">#<?php echo $h['seed']; ?></span>
                        <span class="font-bold <?php echo $h['el'] ? 'elim' : ''; ?>"><?php echo $h['team_abbreviation']; ?></span>
                        <?php if ($h['el']): ?><span class="be">Eliminated</span><?php endif; ?>
                        <span style="color:rgba(255,255,255,.3);font-size:.7rem"><?php echo $h['win']; ?>-<?php echo $h['loss']; ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="pp"><?php echo htmlspecialchars($h['p']); ?></span>
                        <span class="ss"><?php echo $h['sw']; ?></span>
                    </div>
                </div>
                <!-- Lower Seed -->
                <div class="flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <img src="<?php echo htmlspecialchars(getTeamLogo($l['team_name'])); ?>" class="tl">
                        <span style="color:rgba(255,255,255,.4);font-size:.75rem">#<?php echo $l['seed']; ?></span>
                        <span class="font-bold <?php echo $l['el'] ? 'elim' : ''; ?>"><?php echo $l['team_abbreviation']; ?></span>
                        <?php if ($l['el']): ?><span class="be">Eliminated</span><?php endif; ?>
                        <?php if ($is7Confirmed): ?><span class="bc">✓ Play-In</span>
                        <?php elseif ($is8Confirmed): ?><span class="bc">✓ Play-In</span>
                        <?php elseif ($is8Tbd): ?><span class="bt">TBD</span>
                        <?php elseif ($lSeed >= 7): ?><span class="bp">Play-In</span>
                        <?php endif; ?>
                        <span style="color:rgba(255,255,255,.3);font-size:.7rem"><?php echo $l['win']; ?>-<?php echo $l['loss']; ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="pp"><?php echo htmlspecialchars($l['p']); ?></span>
                        <span class="ss"><?php echo $l['sw']; ?></span>
                    </div>
                </div>
                <?php if ($is8Tbd): ?>
                <div class="mt-2 pt-2" style="border-top:1px solid rgba(255,255,255,.06);font-size:.75rem;color:rgba(255,255,255,.4)">
                    Possible 8-seed: <span style="color:rgba(255,255,255,.7);font-weight:600"><?php echo implode(' / ', $tbd8['east']); ?></span>
                </div>
                <?php endif; ?>
                <!-- Season Series -->
                <?php if (!$is8Tbd && $hh && ($hh['w1'] + $hh['w2'] > 0)): ?>
                <div class="mt-2 pt-2" style="border-top:1px solid rgba(255,255,255,.06);font-size:.75rem;color:rgba(255,255,255,.4)">
                    Season Series:
                    <span style="color:rgba(255,255,255,.7);font-weight:600">
                        <?php echo $h['team_abbreviation']; ?> <?php echo $hh['w1']; ?>-<?php echo $hh['w2']; ?> <?php echo $l['team_abbreviation']; ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- SLIDE 5: West First Round Matchups                            -->
    <!-- ============================================================= -->
    <div class="slide" id="s5">
        <h2 class="text-2xl font-bold mb-1 text-center">First Round Matchups</h2>
        <h3 class="text-base mb-5 text-center" style="color:rgba(255,255,255,.5)">Western Conference</h3>
        <div class="w-full max-w-2xl space-y-3">
            <?php foreach ($westM as $m):
                $h  = $m['h'];
                $l  = $m['l'];
                $hh = $matchH2H[$h['team_name'] . '|' . $l['team_name']] ?? null;
                $lSeed = (int)$l['seed'];
                $is8Tbd = ($lSeed === 8 && isset($tbd8['west']));
                $is7Confirmed = ($lSeed === 7 && !empty($playInBracket['west']['seed7_confirmed']));
                $is8Confirmed = ($lSeed === 8 && !empty($playInBracket['west']['seed8_confirmed']));
            ?>
            <div class="mc">
                <!-- Higher Seed -->
                <div class="flex justify-between items-center mb-2">
                    <div class="flex items-center gap-2">
                        <img src="<?php echo htmlspecialchars(getTeamLogo($h['team_name'])); ?>" class="tl">
                        <span style="color:rgba(255,255,255,.4);font-size:.75rem">#<?php echo $h['seed']; ?></span>
                        <span class="font-bold <?php echo $h['el'] ? 'elim' : ''; ?>"><?php echo $h['team_abbreviation']; ?></span>
                        <?php if ($h['el']): ?><span class="be">Eliminated</span><?php endif; ?>
                        <span style="color:rgba(255,255,255,.3);font-size:.7rem"><?php echo $h['win']; ?>-<?php echo $h['loss']; ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="pp"><?php echo htmlspecialchars($h['p']); ?></span>
                        <span class="ss"><?php echo $h['sw']; ?></span>
                    </div>
                </div>
                <!-- Lower Seed -->
                <div class="flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <img src="<?php echo htmlspecialchars(getTeamLogo($l['team_name'])); ?>" class="tl">
                        <span style="color:rgba(255,255,255,.4);font-size:.75rem">#<?php echo $l['seed']; ?></span>
                        <span class="font-bold <?php echo $l['el'] ? 'elim' : ''; ?>"><?php echo $l['team_abbreviation']; ?></span>
                        <?php if ($l['el']): ?><span class="be">Eliminated</span><?php endif; ?>
                        <?php if ($is7Confirmed): ?><span class="bc">✓ Play-In</span>
                        <?php elseif ($is8Confirmed): ?><span class="bc">✓ Play-In</span>
                        <?php elseif ($is8Tbd): ?><span class="bt">TBD</span>
                        <?php elseif ($lSeed >= 7): ?><span class="bp">Play-In</span>
                        <?php endif; ?>
                        <span style="color:rgba(255,255,255,.3);font-size:.7rem"><?php echo $l['win']; ?>-<?php echo $l['loss']; ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="pp"><?php echo htmlspecialchars($l['p']); ?></span>
                        <span class="ss"><?php echo $l['sw']; ?></span>
                    </div>
                </div>
                <?php if ($is8Tbd): ?>
                <div class="mt-2 pt-2" style="border-top:1px solid rgba(255,255,255,.06);font-size:.75rem;color:rgba(255,255,255,.4)">
                    Possible 8-seed: <span style="color:rgba(255,255,255,.7);font-weight:600"><?php echo implode(' / ', $tbd8['west']); ?></span>
                </div>
                <?php endif; ?>
                <!-- Season Series -->
                <?php if (!$is8Tbd && $hh && ($hh['w1'] + $hh['w2'] > 0)): ?>
                <div class="mt-2 pt-2" style="border-top:1px solid rgba(255,255,255,.06);font-size:.75rem;color:rgba(255,255,255,.4)">
                    Season Series:
                    <span style="color:rgba(255,255,255,.7);font-weight:600">
                        <?php echo $h['team_abbreviation']; ?> <?php echo $hh['w1']; ?>-<?php echo $hh['w2']; ?> <?php echo $l['team_abbreviation']; ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- SLIDE 6: Championship Odds (Log5 Bracket Simulation)          -->
    <!-- ============================================================= -->
    <div class="slide" id="s6" style="justify-content:flex-start;padding-top:2.5rem;padding-bottom:6rem;height:auto">
        <div class="mb-6">
            <img src="/nba-wins-platform/public/assets/league_logos/nba_champ.png" alt="Championship" style="width:80px;height:auto;object-fit:contain;margin:0 auto;display:block">
        </div>
        <h2 class="text-3xl font-bold mb-2 text-center">Championship Odds</h2>
        <div class="text-center mb-4">
            <button onclick="document.getElementById('oddsInfo').classList.toggle('ex')"
                    style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);color:rgba(255,255,255,.5);border-radius:999px;padding:3px 12px;font-size:.7rem;cursor:pointer;transition:all .2s">
                ℹ How are these calculated?
            </button>
        </div>
        <div class="tdl" id="oddsInfo" style="max-width:36rem;margin:0 auto .75rem">
            <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:1rem;font-size:.75rem;color:rgba(255,255,255,.5);line-height:1.6">
                <div style="font-weight:700;color:rgba(255,255,255,.7);margin-bottom:.5rem">Log5 Bracket Simulation</div>
                These odds are calculated using the <span style="color:rgba(255,255,255,.7)">Log5 method</span>
                (used by FiveThirtyEight &amp; Basketball Reference) which estimates win probability between any two teams
                based on their actual win percentages.<br><br>
                For each team, we simulate their full bracket path: the probability of winning their Round 1 series,
                then the Conf Semis (weighted by who they'd likely face), Conf Finals, and NBA Finals against all
                possible cross-conference opponents.<br><br>
                <span style="color:rgba(255,255,255,.7)">Bracket difficulty matters</span> — a strong team on a loaded
                side faces tougher opponents each round, lowering their odds compared to an equally strong team with an
                easier path. Eliminated teams drop to 0%. All probabilities sum to 100% across the 16 playoff teams.
            </div>
        </div>
        <div class="w-full max-w-2xl space-y-7">
            <?php foreach ($pOdds as $name => $d): ?>
            <div class="ci">
                <div class="flex justify-between items-center mb-3">
                    <span class="font-bold text-xl"><?php echo htmlspecialchars($name); ?></span>
                    <span class="text-xl font-bold"><?php echo $d['total']; ?>%</span>
                </div>
                <div class="pb mb-3">
                    <div class="pbf" data-target-width="<?php echo min(100, $d['total'] * 2); ?>%" style="width:0%"></div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($d['teams'] as $t): ?>
                    <div class="flex items-center gap-1 px-2 py-1 rounded text-xs"
                         style="background:rgba(255,255,255,.06);<?php echo $t['elim'] ? 'opacity:.4;text-decoration:line-through;' : ''; ?>">
                        <span class="font-semibold"><?php echo $t['abbr']; ?></span>
                        <span style="color:rgba(255,255,255,.4)"><?php echo ($t['odds'] == 0 && !$t['elim']) ? '<0.1' : $t['odds']; ?>%</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- SLIDE 7: Momentum Watch (Last 10 Games)                       -->
    <!-- ============================================================= -->
    <div class="slide" id="s7">
        <div class="text-6xl mb-8 slide-emoji">📈</div>
        <h2 class="text-3xl font-bold mb-2 text-center">Momentum Watch</h2>
        <div class="text-center mb-6" style="color:rgba(255,255,255,.4)">Last 10 games heading into the postseason</div>
        <div class="w-full max-w-3xl space-y-2">
            <?php foreach (array_slice($momentum, 0, 5) as $mt): ?>
            <div class="flex items-center gap-3 p-3 rounded-lg ci" style="background:rgba(255,255,255,.05)">
                <img src="<?php echo htmlspecialchars(getTeamLogo($mt['tn'])); ?>" class="tl">
                <div class="flex-grow" style="min-width:0">
                    <div class="flex items-center gap-2">
                        <span class="font-semibold text-sm"><?php echo $mt['abbr']; ?></span>
                        <span style="color:rgba(255,255,255,.3);font-size:.7rem">#<?php echo $mt['seed']; ?> <?php echo strtoupper(substr($mt['conf'], 0, 1)); ?></span>
                        <span class="pp"><?php echo htmlspecialchars($mt['p']); ?></span>
                    </div>
                    <div class="flex gap-1 mt-1.5">
                        <?php foreach (array_reverse($mt['results']) as $r): ?>
                        <span class="rd <?php echo $r === 'W' ? 'w' : 'l'; ?>"><?php echo $r; ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="text-right flex-shrink-0">
                    <div class="font-bold text-lg"><?php echo $mt['w']; ?>-<?php echo $mt['l']; ?></div>
                    <div class="text-xs" style="color:rgba(255,255,255,.4)"><?php echo ($mt['ws'] == 1 ? 'W' : 'L') . $mt['streak']; ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- SLIDE 8: Highest Seeded Teams by Participant                  -->
    <!-- ============================================================= -->
    <div class="slide" id="s8">
        <div class="text-6xl mb-8 slide-emoji">⭐</div>
        <h2 class="text-3xl font-bold mb-8 text-center">Highest Seeded Teams</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 max-w-4xl w-full">
            <?php foreach (['east' => ['Eastern Conference', 'rgba(59,130,246,.2)', '#93c5fd'], 'west' => ['Western Conference', 'rgba(239,68,68,.2)', '#fca5a5']] as $c => $st): ?>
            <div>
                <h3 class="text-lg font-semibold mb-4 text-center" style="color:rgba(255,255,255,.6)"><?php echo $st[0]; ?></h3>
                <?php foreach ($cTeams[$c] as $t): ?>
                <div class="flex items-center gap-3 mb-3 p-3 rounded-lg ci" style="background:rgba(255,255,255,.06)">
                    <div class="flex-shrink-0 w-8 h-8 flex items-center justify-center rounded-full text-sm font-bold"
                         style="background:<?php echo $st[1]; ?>;color:<?php echo $st[2]; ?>">
                        <?php echo $t['seed']; ?>
                    </div>
                    <img src="<?php echo htmlspecialchars(getTeamLogo($t['tn'])); ?>" class="tl">
                    <div class="flex-grow">
                        <div class="font-semibold text-sm"><?php echo htmlspecialchars($t['tn']); ?></div>
                        <div class="text-xs" style="color:rgba(255,255,255,.4)"><?php echo htmlspecialchars($t['pn']); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- SLIDE 9: East Playoff Team Stats                              -->
    <!-- ============================================================= -->
    <div class="slide" id="s9" style="padding-bottom:6rem">
        <div class="text-5xl mb-4 slide-emoji">📊</div>
        <h2 class="text-2xl font-bold mb-1 text-center">Playoff Team Stats</h2>
        <h3 class="text-base mb-5 text-center" style="color:rgba(255,255,255,.5)">Eastern Conference</h3>
        <div class="w-full max-w-5xl overflow-x-auto">
            <table class="st">
                <thead>
                    <tr>
                        <th>Seed</th><th>Team</th><th>Record</th><th>Home</th>
                        <th>Away</th><th>PPG</th><th>OPPG</th><th>Diff</th><th>Pool</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cStats['east'] as $t): $df = $t['ppg'] - $t['oppg']; ?>
                    <tr>
                        <td><?php echo $t['seed']; ?></td>
                        <td class="font-semibold"><?php echo $t['abbreviation']; ?></td>
                        <td><?php echo $t['win']; ?>-<?php echo $t['loss']; ?></td>
                        <td><?php echo $t['hw']; ?>-<?php echo $t['hl']; ?></td>
                        <td><?php echo $t['aw']; ?>-<?php echo $t['al']; ?></td>
                        <td><?php echo $t['ppg']; ?></td>
                        <td><?php echo $t['oppg']; ?></td>
                        <td style="color:<?php echo $df > 0 ? '#4ade80' : '#f87171'; ?>"><?php echo sprintf("%+.1f", $df); ?></td>
                        <td style="color:rgba(255,255,255,.4)"><?php echo htmlspecialchars($t['pname']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- SLIDE 10: West Playoff Team Stats                             -->
    <!-- ============================================================= -->
    <div class="slide" id="s10" style="padding-bottom:6rem">
        <div class="text-5xl mb-4 slide-emoji">📊</div>
        <h2 class="text-2xl font-bold mb-1 text-center">Playoff Team Stats</h2>
        <h3 class="text-base mb-5 text-center" style="color:rgba(255,255,255,.5)">Western Conference</h3>
        <div class="w-full max-w-5xl overflow-x-auto">
            <table class="st">
                <thead>
                    <tr>
                        <th>Seed</th><th>Team</th><th>Record</th><th>Home</th>
                        <th>Away</th><th>PPG</th><th>OPPG</th><th>Diff</th><th>Pool</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cStats['west'] as $t): $df = $t['ppg'] - $t['oppg']; ?>
                    <tr>
                        <td><?php echo $t['seed']; ?></td>
                        <td class="font-semibold"><?php echo $t['abbreviation']; ?></td>
                        <td><?php echo $t['win']; ?>-<?php echo $t['loss']; ?></td>
                        <td><?php echo $t['hw']; ?>-<?php echo $t['hl']; ?></td>
                        <td><?php echo $t['aw']; ?>-<?php echo $t['al']; ?></td>
                        <td><?php echo $t['ppg']; ?></td>
                        <td><?php echo $t['oppg']; ?></td>
                        <td style="color:<?php echo $df > 0 ? '#4ade80' : '#f87171'; ?>"><?php echo sprintf("%+.1f", $df); ?></td>
                        <td style="color:rgba(255,255,255,.4)"><?php echo htmlspecialchars($t['pname']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- SLIDE 11: Playoff Team Killers                                -->
    <!-- ============================================================= -->
    <div class="slide" id="s11">
        <div class="text-6xl mb-8 slide-emoji">🔥</div>
        <h2 class="text-3xl font-bold mb-2 text-center">Playoff Team Killers</h2>
        <div class="text-center mb-6" style="color:rgba(255,255,255,.4)">Best win% against playoff teams this season</div>
        <div class="w-full max-w-3xl space-y-3">
            <?php if (empty($killers)): ?>
                <div class="text-center" style="color:rgba(255,255,255,.4)">Data will populate once more games are played.</div>
            <?php else: ?>
                <?php foreach ($killers as $i => $k): ?>
                <div class="flex items-center gap-4 p-4 rounded-lg ci" style="background:rgba(255,255,255,.06)">
                    <div class="flex-shrink-0 w-8 h-8 flex items-center justify-center rounded-full font-bold text-sm"
                         style="background:<?php echo $i === 0 ? 'rgba(234,179,8,.3)' : 'rgba(255,255,255,.1)'; ?>;color:<?php echo $i === 0 ? '#fde047' : 'rgba(255,255,255,.6)'; ?>">
                        <?php echo $i + 1; ?>
                    </div>
                    <img src="<?php echo htmlspecialchars(getTeamLogo($k['team_name'])); ?>" class="tl">
                    <div class="flex-grow">
                        <div class="font-semibold"><?php echo htmlspecialchars($k['team_name']); ?></div>
                        <div class="text-xs" style="color:rgba(255,255,255,.4)"><?php echo htmlspecialchars($k['participant_name']); ?></div>
                    </div>
                    <div class="text-right">
                        <div class="text-2xl font-bold"><?php echo $k['wp']; ?>%</div>
                        <div class="text-xs" style="color:rgba(255,255,255,.4)"><?php echo $k['pw']; ?>-<?php echo $k['pg'] - $k['pw']; ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- SLIDE 12: Playoff Wins Projection / Upside                    -->
    <!-- ============================================================= -->
    <div class="slide" id="s12">
        <div class="text-6xl mb-6 slide-emoji">🎯</div>
        <h2 class="text-3xl font-bold mb-2 text-center">Playoff Wins Upside</h2>
        <div class="text-center mb-6" style="color:rgba(255,255,255,.4)">Projected additional wins from the postseason</div>
        <div class="w-full max-w-3xl space-y-4">
            <?php $maxProj = max(array_column($projections, 'projected')) ?: 1; ?>
            <?php foreach ($projections as $name => $proj): ?>
            <div class="ci" style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);border-radius:10px;padding:.85rem 1rem">
                <div class="flex justify-between items-center mb-2">
                    <span class="font-bold"><?php echo htmlspecialchars($name); ?></span>
                    <div class="flex items-center gap-3">
                        <span style="color:rgba(255,255,255,.35);font-size:.75rem">ceiling <?php echo $proj['ceiling']; ?></span>
                        <span class="font-bold text-xl" style="color:#4ade80;">+<?php echo $proj['projected']; ?></span>
                    </div>
                </div>
                <div class="pb mb-2">
                    <div class="pbf" data-target-width="<?php echo round(($proj['projected'] / $maxProj) * 100); ?>%" style="width:0%"></div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($proj['teams'] as $t): ?>
                    <div class="flex items-center gap-1 px-2 py-1 rounded text-xs <?php echo $t['elim'] ? 'opacity-30 line-through' : ''; ?>" style="background:rgba(255,255,255,.06)">
                        <span class="font-semibold"><?php echo $t['abbr']; ?></span>
                        <span style="color:rgba(255,255,255,.35)">+<?php echo $t['expected']; ?></span>
                        <span style="color:rgba(255,255,255,.25);font-size:.6rem">→<?php echo $t['deepest']; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- SLIDE 13: Path to the Finals (Bracket)                        -->
    <!-- ============================================================= -->
    <div class="slide" id="s13" style="padding-bottom:6rem">
        <h2 class="ht-a text-3xl font-bold text-center">Path to</h2>
        <div class="ht-a" style="margin-top:.5rem">
            <img src="/nba-wins-platform/public/assets/league_logos/nba_finals.png" alt="The Finals" style="width:160px;height:auto;object-fit:contain;margin:0 auto;display:block">
        </div>
        <div class="ht-a text-center mb-6 mt-3" style="color:rgba(255,255,255,.4)">2026 NBA Playoff Bracket</div>
        <div id="bracket" class="ht-a w-full max-w-6xl overflow-x-auto"></div>
    </div>

    <!-- ============================================================= -->
    <!-- SLIDE 14: Buy Me a Coffee / Supporters                        -->
    <!-- ============================================================= -->
    <div class="slide" id="s14">
        <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;flex:1;width:100%">
            <div class="text-6xl mb-8 slide-emoji">☕</div>
            <h2 class="text-3xl font-bold mb-4 text-center">Enjoying the Platform?<br>(Shameless Plug)</h2>
            <div class="text-center mb-8" style="color:rgba(255,255,255,.4)">
                If you're having fun tracking wins, consider buying me a coffee!
            </div>
            <a href="https://buymeacoffee.com/taylorstvns" target="_blank" rel="noopener noreferrer"
               style="display:inline-flex;align-items:center;gap:.75rem;background:#FFDD00;color:#000;font-weight:700;font-size:1.25rem;padding:1rem 2rem;border-radius:12px;text-decoration:none;transition:transform .2s ease,box-shadow .2s ease;box-shadow:0 4px 15px rgba(255,221,0,.3)">
                <img src="https://cdn.buymeacoffee.com/buttons/bmc-new-btn-logo.svg" alt="" style="height:28px;width:28px">
                <span>Buy Me a Coffee</span>
            </a>
            <div style="margin-top:2.5rem;text-align:center">
                <div style="font-size:.85rem;color:#9ca3af;margin-bottom:.75rem">Shoutout to the supporters 🙏</div>
                <div style="display:flex;gap:1rem;justify-content:center;flex-wrap:wrap">
                    <span style="background:rgba(255,255,255,.08);padding:.4rem 1rem;border-radius:999px;font-size:.85rem;color:#e5e7eb">🎉 brianshane.com</span>
                    <span style="background:rgba(255,255,255,.08);padding:.4rem 1rem;border-radius:999px;font-size:.85rem;color:#e5e7eb">🎉 BasedKhan</span>
                </div>
            </div>
            <div class="text-center mt-6 text-sm" style="color:rgba(255,255,255,.3)">
                Thanks for being part of the NBA Wins Platform 🏀
            </div>
        </div>
    </div>

</div><!-- /#slides -->

<!-- ================================================================= -->
<!-- NAVIGATION: Slide Dots + Prev/Next Buttons                        -->
<!-- ================================================================= -->
<div class="sd" id="sdots"></div>
<div id="nav">
    <div class="fixed z-[100] bottom-8 left-8" style="padding-bottom:env(safe-area-inset-bottom)">
        <button onclick="prev()" class="nb flex items-center space-x-2">
            <span class="text-xl">←</span><span>Prev</span>
        </button>
    </div>
    <div class="fixed z-[100] bottom-8 right-8" style="padding-bottom:env(safe-area-inset-bottom)">
        <button onclick="next()" class="nb flex items-center space-x-2">
            <span>Next</span><span class="text-xl">→</span>
        </button>
    </div>
</div>

<!-- ================================================================= -->
<!-- JAVASCRIPT: Slide Engine, Animations, Bracket Renderer            -->
<!-- ================================================================= -->
<script>

    // -----------------------------------------------------------------
    // Toggle detail expansion (distribution slide)
    // -----------------------------------------------------------------
    function toggleT(i) {
        const e = document.getElementById('tl-' + i);
        const a = document.getElementById('ar-' + i);
        e.classList.toggle('ex');
        a.style.transform = e.classList.contains('ex') ? 'rotate(180deg)' : 'rotate(0)';
    }

    // -----------------------------------------------------------------
    // Slide navigation engine
    // -----------------------------------------------------------------
    const slides = document.querySelectorAll('.slide');
    let cur = 0;

    // Build slide dots
    const dc = document.getElementById('sdots');
    slides.forEach((_, i) => {
        const d = document.createElement('div');
        d.className = 'sdt' + (i === 0 ? ' active' : '');
        d.addEventListener('click', () => show(i, i > cur ? 'next' : 'prev'));
        dc.appendChild(d);
    });
    const dots = dc.querySelectorAll('.sdt');

    function ud(i) {
        dots.forEach((d, j) => d.classList.toggle('active', j === i));
    }

    // -----------------------------------------------------------------
    // Progress bar & counter animations
    // -----------------------------------------------------------------
    function ap(s) {
        setTimeout(() => {
            s.querySelectorAll('.pbf[data-target-width]').forEach(b => {
                b.style.width = b.getAttribute('data-target-width');
            });
        }, 400);
    }

    function rp(s) {
        s.querySelectorAll('.pbf[data-target-width]').forEach(b => {
            b.style.transition = 'none';
            b.style.width = '0%';
            b.offsetHeight; // force reflow
            b.style.transition = '';
        });
    }

    function ac(s) {
        s.querySelectorAll('[data-count]').forEach(el => {
            const t = parseInt(el.getAttribute('data-count'));
            if (isNaN(t)) return;
            const st = performance.now();
            function tk(n) {
                const p = Math.min((n - st) / 1200, 1);
                el.textContent = Math.round(t * (1 - Math.pow(1 - p, 3)));
                if (p < 1) requestAnimationFrame(tk);
            }
            setTimeout(() => requestAnimationFrame(tk), 300);
        });
    }

    // -----------------------------------------------------------------
    // Show slide by index
    // -----------------------------------------------------------------
    function show(i, dir = 'next') {
        slides[cur].classList.remove('active', 'slide-left', 'slide-right');
        rp(slides[cur]);

        const ni = (i + slides.length) % slides.length;
        slides[ni].classList.remove('slide-left', 'slide-right');

        if (dir === 'next') {
            slides[cur].classList.add('slide-left');
            slides[ni].classList.add('slide-right');
        } else {
            slides[cur].classList.add('slide-right');
            slides[ni].classList.add('slide-left');
        }

        setTimeout(() => {
            slides[cur].classList.remove('active');
            slides[ni].classList.add('active');
            slides[ni].classList.remove('slide-left', 'slide-right');
            ap(slides[ni]);
            ac(slides[ni]);
        }, 50);

        cur = ni;
        ud(ni);
    }

    function next() { show(cur + 1, 'next') }
    function prev() { show(cur - 1, 'prev') }

    // -----------------------------------------------------------------
    // Keyboard & touch navigation
    // -----------------------------------------------------------------
    document.addEventListener('keydown', e => {
        if (e.key === 'ArrowRight') next();
        if (e.key === 'ArrowLeft') prev();
    });

    // Initialize first slide
    ap(slides[0]);
    ac(slides[0]);

    // -----------------------------------------------------------------
    // BRACKET RENDERING
    // -----------------------------------------------------------------
    const bracketData = <?php echo $bracketJson; ?>;
    const roundDates  = <?php echo $roundDates; ?>;

    function getCurrentRound() {
        const now = new Date().toISOString().split('T')[0];
        if (now >= roundDates.finals)      return 'finals';
        if (now >= roundDates.conf_finals) return 'conf_finals';
        if (now >= roundDates.semis)       return 'semis';
        if (now >= roundDates.round1)      return 'round1';
        if (now >= roundDates.playin)      return 'playin';
        return 'pre';
    }

    function renderBracket() {
        const el = document.getElementById('bracket');
        if (!el) return;

        const W = bracketData.west || {};
        const E = bracketData.east || {};
        const F = bracketData.finals;

        // --- Helper: single team slot ---
        function slot(seed, abbr, wins, isElim, isWinner) {
            const cls = isWinner ? 'winner' : (isElim ? 'loser' : '');
            return `<div class="bk-slot ${cls}" style="height:32px;font-size:.75rem">
                <span style="color:rgba(255,255,255,.4);font-size:.6rem;min-width:18px">[${seed}]</span>
                <span class="font-bold" style="flex:1">${abbr}</span>
                <span style="font-weight:700;min-width:14px;text-align:right">${wins}</span>
            </div>`;
        }

        // --- Helper: TBD slot ---
        function tbd() {
            return '<div class="bk-slot" style="height:32px;opacity:.3;justify-content:center;font-size:.7rem">TBD</div>';
        }

        // --- Helper: matchup pair ---
        function pair(m) {
            if (!m) return `<div style="display:flex;flex-direction:column;gap:2px">${tbd()}${tbd()}</div>`;
            const hW = m.h_wins >= 4, lW = m.l_wins >= 4;
            return `<div style="display:flex;flex-direction:column;gap:2px">
                ${slot(m.h_seed, m.h_abbr, m.h_wins, m.h_elim, hW)}
                ${slot(m.l_seed, m.l_abbr, m.l_wins, m.l_elim, lW)}
            </div>`;
        }

        function tbdPair() {
            return `<div style="display:flex;flex-direction:column;gap:2px">${tbd()}${tbd()}</div>`;
        }

        // --- Build grid ---
        const cr = getCurrentRound();
        const hs = 'text-align:center;font-size:.65rem;text-transform:uppercase;letter-spacing:.08em;padding-bottom:4px;color:rgba(255,255,255,.3)';

        let html = `<div style="display:grid;grid-template-columns:repeat(7,1fr);gap:8px;min-width:800px;align-items:center">`;

        // Round headers
        html += `<div style="${hs}" class="${cr === 'round1'      ? 'rnd-active' : ''}">Round 1</div>`;
        html += `<div style="${hs}" class="${cr === 'semis'        ? 'rnd-active' : ''}">Conf Semis</div>`;
        html += `<div style="${hs}" class="${cr === 'conf_finals'  ? 'rnd-active' : ''}">Conf Finals</div>`;
        html += `<div style="${hs}" class="${cr === 'finals'       ? 'rnd-active' : ''}">NBA Finals</div>`;
        html += `<div style="${hs}" class="${cr === 'conf_finals'  ? 'rnd-active' : ''}">Conf Finals</div>`;
        html += `<div style="${hs}" class="${cr === 'semis'        ? 'rnd-active' : ''}">Conf Semis</div>`;
        html += `<div style="${hs}" class="${cr === 'round1'      ? 'rnd-active' : ''}">Round 1</div>`;

        // Conference labels
        html += `<div style="text-align:center;font-weight:700;color:#fca5a5;font-size:.85rem;padding-bottom:6px;grid-column:1/4">Western Conference</div>`;
        html += `<div></div>`;
        html += `<div style="text-align:center;font-weight:700;color:#93c5fd;font-size:.85rem;padding-bottom:6px;grid-column:5/8">Eastern Conference</div>`;

        // West R1 (top half: 1v8, 4v5 | gap | bottom half: 3v6, 2v7)
        const wr1 = W.r1 || [];
        html += `<div style="display:flex;flex-direction:column;gap:14px">`;
        if (wr1[0]) html += pair(wr1[0]);
        if (wr1[1]) html += pair(wr1[1]);
        html += `<div style="height:20px"></div>`;
        if (wr1[2]) html += pair(wr1[2]);
        if (wr1[3]) html += pair(wr1[3]);
        html += `</div>`;

        // West Semis
        const ws = W.semis || [];
        html += `<div style="display:flex;flex-direction:column;gap:80px;padding:33px 0">`;
        html += pair(ws[0] || null);
        html += pair(ws[1] || null);
        html += `</div>`;

        // West Conf Finals
        html += `<div style="display:flex;flex-direction:column;justify-content:center;height:100%">`;
        html += pair(W.conf_final || null);
        html += `</div>`;

        // NBA Finals (center)
        html += `<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;height:100%">`;
        html += `<img src="/nba-wins-platform/public/assets/league_logos/nba_finals.png" alt="NBA Finals" style="width:80px;height:auto;object-fit:contain">`;
        if (F) { html += pair(F); } else { html += tbdPair(); }
        html += `</div>`;

        // East Conf Finals
        html += `<div style="display:flex;flex-direction:column;justify-content:center;height:100%">`;
        html += pair(E.conf_final || null);
        html += `</div>`;

        // East Semis
        const es = E.semis || [];
        html += `<div style="display:flex;flex-direction:column;gap:80px;padding:33px 0">`;
        html += pair(es[0] || null);
        html += pair(es[1] || null);
        html += `</div>`;

        // East R1 (top half: 1v8, 4v5 | gap | bottom half: 3v6, 2v7)
        const er1 = E.r1 || [];
        html += `<div style="display:flex;flex-direction:column;gap:14px">`;
        if (er1[0]) html += pair(er1[0]);
        if (er1[1]) html += pair(er1[1]);
        html += `<div style="height:20px"></div>`;
        if (er1[2]) html += pair(er1[2]);
        if (er1[3]) html += pair(er1[3]);
        html += `</div>`;

        html += `</div>`;
        el.innerHTML = html;
    }

    renderBracket();

</script>
</body>
</html>