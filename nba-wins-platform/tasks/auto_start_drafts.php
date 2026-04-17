<?php
// /data/www/default/nba-wins-platform/tasks/auto_start_drafts.php
// Cron job: Checks for leagues with a draft_date that has passed and auto-starts the draft
// Run every minute: * * * * * /usr/bin/php /data/www/default/nba-wins-platform/tasks/auto_start_drafts.php
//
// UTC cron, but dates are stored in EST — platform_settings sets timezone

require_once '/data/www/default/nba-wins-platform/config/db_connection_cli.php';
require_once '/data/www/default/nba-wins-platform/config/platform_settings.php';
require_once '/data/www/default/nba-wins-platform/core/DraftManager.php';

$now = date('Y-m-d H:i:s');
echo "[" . $now . "] Checking for drafts ready to start...\n";

// Find leagues where:
// 1. draft_date has passed (or is now)
// 2. draft is enabled but not completed
// 3. No active/paused draft session exists
// 4. League has at least 2 active participants
$stmt = $pdo->prepare("
    SELECT l.id AS league_id, 
           l.display_name, 
           l.draft_date, 
           l.commissioner_user_id,
           COUNT(lp.id) AS participant_count
    FROM leagues l
    JOIN league_participants lp ON l.id = lp.league_id AND lp.status = 'active'
    WHERE l.draft_date IS NOT NULL
      AND l.draft_date <= NOW()
      AND l.draft_enabled = 1
      AND l.draft_completed = 0
      AND l.status = 'active'
      AND l.id NOT IN (
          SELECT ds.league_id 
          FROM draft_sessions ds 
          WHERE ds.status IN ('active', 'paused', 'completed')
      )
    GROUP BY l.id
    HAVING participant_count >= 2
    ORDER BY l.draft_date ASC
");
$stmt->execute();
$ready_leagues = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($ready_leagues)) {
    echo "No drafts ready to start.\n";
    exit(0);
}

echo "Found " . count($ready_leagues) . " draft(s) ready to start.\n";

$draftManager = new DraftManager($pdo);

foreach ($ready_leagues as $league) {
    $league_id = $league['league_id'];
    $commissioner_id = $league['commissioner_user_id'];
    $league_name = $league['display_name'];
    $participant_count = $league['participant_count'];

    echo "\n--- League: {$league_name} (ID: {$league_id}) ---\n";
    echo "  Draft date: {$league['draft_date']}\n";
    echo "  Commissioner: User {$commissioner_id}\n";
    echo "  Participants: {$participant_count}\n";

    // Safety check: commissioner must exist
    if (!$commissioner_id) {
        echo "  ERROR: No commissioner set, skipping.\n";
        error_log("AUTO-START DRAFT: League {$league_id} has no commissioner, cannot auto-start.");
        continue;
    }

    try {
        $draft_session_id = $draftManager->startDraft($league_id, $commissioner_id);
        echo "  SUCCESS: Draft started! Session ID: {$draft_session_id}\n";
        error_log("AUTO-START DRAFT: League {$league_id} '{$league_name}' - Draft session {$draft_session_id} started automatically.");

        // Set the pick timer for the first pick
        $stmt2 = $pdo->prepare("
            UPDATE draft_sessions 
            SET current_pick_started_at = NOW(),
                pick_time_limit = COALESCE(pick_time_limit, 120)
            WHERE id = ? AND status = 'active' AND current_participant_id IS NOT NULL
        ");
        $stmt2->execute([$draft_session_id]);

    } catch (Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
        error_log("AUTO-START DRAFT FAILED: League {$league_id} '{$league_name}' - " . $e->getMessage());
    }
}

echo "\nDone.\n";
?>