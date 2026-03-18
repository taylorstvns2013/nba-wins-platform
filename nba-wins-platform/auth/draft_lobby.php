<?php
// nba-wins-platform/auth/draft_lobby.php
// Draft Lobby - Shows draft date countdown and league info before draft starts
// If draft is complete, shows summary. If draft is active, redirects to draft admin.
require_once '../config/db_connection.php';
require_once '../core/UserAuthentication.php';
require_once '../core/LeagueManager.php';

$auth = new UserAuthentication($pdo);
$leagueManager = new LeagueManager($pdo);

if (!$auth->isAuthenticated() || $auth->isGuest()) {
    header('Location: login_v2.php');
    exit;
}

$userId = $_SESSION['user_id'];
$leagueId = $_SESSION['current_league_id'] ?? null;

if (!$leagueId) {
    header('Location: league_hub.php');
    exit;
}

// Get league details
$stmt = $pdo->prepare("
    SELECT l.*, u.display_name as commissioner_name,
           (l.commissioner_user_id = ?) as is_commissioner
    FROM leagues l
    LEFT JOIN users u ON l.commissioner_user_id = u.id
    WHERE l.id = ?
");
$stmt->execute([$userId, $leagueId]);
$league = $stmt->fetch();

if (!$league) {
    header('Location: league_hub.php');
    exit;
}

$isCommissioner = (bool)$league['is_commissioner'];

// Get participants
$members = $leagueManager->getLeagueMembers($leagueId);
$memberCount = count($members);
$spotsLeft = $league['user_limit'] - $memberCount;
$isFull = $spotsLeft <= 0;

// Check draft status
$draftCompleted = (bool)$league['draft_completed'];
$draftDate = $league['draft_date'] ? strtotime($league['draft_date']) : null;
$now = time();
$hasDraftDate = !empty($draftDate);
$draftInPast = $hasDraftDate && $draftDate <= $now;

// Check for active draft session
$stmt = $pdo->prepare("
    SELECT id, status FROM draft_sessions
    WHERE league_id = ? AND status IN ('active', 'paused')
    ORDER BY created_at DESC LIMIT 1
");
$stmt->execute([$leagueId]);
$activeDraft = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="theme-color" content="#121a23">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Draft Lobby - <?php echo htmlspecialchars($league['display_name']); ?></title>
    <link rel="apple-touch-icon" type="image/png" href="../public/assets/favicon/favicon.png">
    <link rel="icon" type="image/png" href="../public/assets/favicon/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #151d28;
            --bg-secondary: #1a222c;
            --bg-card: #202a38;
            --bg-card-hover: #273140;
            --bg-elevated: #2a3446;
            --border-color: rgba(255, 255, 255, 0.08);
            --text-primary: #e6edf3;
            --text-secondary: #8b949e;
            --text-muted: #545d68;
            --accent-blue: #388bfd;
            --accent-blue-dim: rgba(56, 139, 253, 0.15);
            --accent-green: #3fb950;
            --accent-green-dim: rgba(63, 185, 80, 0.15);
            --accent-red: #f85149;
            --accent-orange: #d29922;
            --accent-purple: #a371f7;
            --radius-md: 10px;
            --radius-lg: 14px;
            --shadow-card: 0 1px 3px rgba(0, 0, 0, 0.4), 0 0 0 1px var(--border-color);
            --shadow-elevated: 0 8px 25px rgba(0, 0, 0, 0.5);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { background: var(--bg-primary); }

        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg-primary);
            background-image: radial-gradient(ellipse at 50% 0%, rgba(56, 139, 253, 0.04) 0%, transparent 60%);
            color: var(--text-primary);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            line-height: 1.5;
        }

        .lobby-container {
            max-width: 700px;
            margin: 0 auto;
        }

        /* Header */
        .lobby-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .lobby-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .lobby-header-left img {
            width: 48px;
            height: 48px;
        }

        .lobby-header h1 {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .lobby-header h1 span {
            display: block;
            font-size: 13px;
            font-weight: 400;
            color: var(--text-muted);
        }

        .header-btn {
            padding: 8px 16px;
            border-radius: var(--radius-md);
            font-family: 'Outfit', sans-serif;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
            border: 1px solid var(--border-color);
            background: var(--bg-elevated);
            color: var(--text-secondary);
        }

        .header-btn:hover {
            background: var(--bg-card-hover);
            color: var(--text-primary);
        }

        /* Card */
        .card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            padding: 28px;
            margin-bottom: 16px;
        }

        /* Countdown */
        .countdown-section {
            text-align: center;
            padding: 20px 0;
        }

        .countdown-label {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 8px;
        }

        .countdown-date {
            font-size: 18px;
            font-weight: 600;
            color: var(--accent-orange);
            margin-bottom: 24px;
        }

        .countdown-timer {
            display: flex;
            justify-content: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .countdown-unit {
            background: var(--bg-elevated);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px 12px;
            min-width: 80px;
            text-align: center;
        }

        .countdown-value {
            font-size: 36px;
            font-weight: 800;
            line-height: 1;
            color: var(--text-primary);
            font-variant-numeric: tabular-nums;
        }

        .countdown-value.urgent {
            color: var(--accent-red);
        }

        .countdown-value.soon {
            color: var(--accent-orange);
        }

        .countdown-unit-label {
            font-size: 11px;
            font-weight: 500;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-top: 6px;
        }

        /* Draft ready / not set states */
        .draft-status-banner {
            text-align: center;
            padding: 24px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
        }

        .draft-status-banner.ready {
            background: var(--accent-green-dim);
            border: 1px solid rgba(63, 185, 80, 0.25);
        }

        .draft-status-banner.waiting {
            background: var(--accent-blue-dim);
            border: 1px solid rgba(56, 139, 253, 0.25);
        }

        .draft-status-banner.not-set {
            background: var(--bg-elevated);
            border: 1px solid var(--border-color);
        }

        .draft-status-banner.completed {
            background: rgba(163, 113, 247, 0.1);
            border: 1px solid rgba(163, 113, 247, 0.25);
        }

        .draft-status-banner i {
            font-size: 32px;
            margin-bottom: 10px;
            display: block;
        }

        .draft-status-banner h2 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .draft-status-banner p {
            font-size: 14px;
            color: var(--text-secondary);
        }

        .banner-ready i { color: var(--accent-green); }
        .banner-ready h2 { color: var(--accent-green); }

        .banner-waiting i { color: var(--accent-blue); }
        .banner-waiting h2 { color: var(--accent-blue); }

        .banner-not-set i { color: var(--text-muted); }
        .banner-not-set h2 { color: var(--text-secondary); }

        .banner-completed i { color: var(--accent-purple); }
        .banner-completed h2 { color: var(--accent-purple); }

        /* Participants */
        .participants-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
        }

        .participants-title {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .participants-count {
            font-size: 13px;
            color: var(--text-secondary);
            background: var(--bg-elevated);
            padding: 3px 10px;
            border-radius: 20px;
        }

        .participant-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }

        .participant-row:last-child {
            border-bottom: none;
        }

        .participant-row i {
            width: 16px;
            text-align: center;
        }

        .participant-name {
            color: var(--text-primary);
            font-weight: 500;
        }

        .participant-tag {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 600;
            margin-left: auto;
        }

        .tag-commissioner {
            background: rgba(163, 113, 247, 0.15);
            color: var(--accent-purple);
        }

        .tag-you {
            background: var(--accent-blue-dim);
            color: var(--accent-blue);
        }

        .empty-slot {
            color: var(--text-muted);
            font-style: italic;
        }

        .empty-slot i {
            color: var(--text-muted);
        }

        /* PIN share section */
        .pin-share {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--bg-elevated);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 14px 18px;
            margin-top: 16px;
        }

        .pin-share-label {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .pin-code {
            font-family: 'Courier New', monospace;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.15em;
            color: var(--accent-green);
        }

        .copy-btn {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 14px;
            padding: 4px 6px;
            transition: color 0.15s ease;
        }

        .copy-btn:hover {
            color: var(--accent-blue);
        }

        /* Action button */
        .action-btn {
            display: block;
            width: 100%;
            padding: 14px 20px;
            border: none;
            border-radius: var(--radius-md);
            font-size: 15px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            margin-top: 16px;
        }

        .action-btn:hover {
            transform: translateY(-1px);
        }

        .btn-green {
            background: linear-gradient(135deg, var(--accent-green), #2ea043);
            color: white;
        }

        .btn-green:hover {
            box-shadow: 0 4px 16px rgba(63, 185, 80, 0.3);
        }

        .btn-blue {
            background: linear-gradient(135deg, var(--accent-blue), #1a6ddb);
            color: white;
        }

        .btn-blue:hover {
            box-shadow: 0 4px 16px rgba(56, 139, 253, 0.3);
        }

        .btn-purple {
            background: linear-gradient(135deg, var(--accent-purple), #8957e5);
            color: white;
        }

        .btn-purple:hover {
            box-shadow: 0 4px 16px rgba(163, 113, 247, 0.3);
        }

        /* Responsive */
        @media (max-width: 500px) {
            body { padding: 12px; }

            .countdown-unit {
                min-width: 65px;
                padding: 12px 8px;
            }

            .countdown-value {
                font-size: 28px;
            }

            .lobby-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .countdown-timer {
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="lobby-container">
        <!-- Header -->
        <div class="lobby-header">
            <div class="lobby-header-left">
                <img src="../public/assets/team_logos/Logo.png" alt="NBA Logo">
                <h1>
                    <?php echo htmlspecialchars($league['display_name']); ?>
                    <span>Draft Lobby</span>
                </h1>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="league_hub.php" class="header-btn"><i class="fas fa-th-large"></i> League Hub</a>
                <a href="/index.php" class="header-btn"><i class="fas fa-home"></i> Dashboard</a>
            </div>
        </div>

        <?php if ($draftCompleted): ?>
            <!-- DRAFT COMPLETED -->
            <div class="card">
                <div class="draft-status-banner completed banner-completed">
                    <i class="fas fa-trophy"></i>
                    <h2>Draft Complete</h2>
                    <p>The draft for this league has been completed. Head to the dashboard to view results.</p>
                </div>
                <a href="/index.php" class="action-btn btn-purple">
                    <i class="fas fa-chart-bar"></i> View Dashboard
                </a>
            </div>

        <?php elseif ($activeDraft): ?>
            <!-- DRAFT IN PROGRESS -->
            <div class="card">
                <div class="draft-status-banner ready banner-ready">
                    <i class="fas fa-fire"></i>
                    <h2>Draft In Progress</h2>
                    <p>The draft is currently live! Jump in to make your picks.</p>
                </div>
                <a href="../admin/draft_admin.php" class="action-btn btn-green">
                    <i class="fas fa-gavel"></i> Enter Draft Room
                </a>
            </div>

        <?php elseif ($hasDraftDate && !$draftInPast): ?>
            <!-- COUNTDOWN TO DRAFT -->
            <div class="card">
                <div class="countdown-section">
                    <div class="countdown-label">Draft Day</div>
                    <div class="countdown-date">
                        <i class="fas fa-calendar-alt"></i>
                        <?php echo date('l, F j, Y \a\t g:i A', $draftDate); ?>
                    </div>
                    <div class="countdown-timer" id="countdown">
                        <div class="countdown-unit">
                            <div class="countdown-value" id="cd-days">--</div>
                            <div class="countdown-unit-label">Days</div>
                        </div>
                        <div class="countdown-unit">
                            <div class="countdown-value" id="cd-hours">--</div>
                            <div class="countdown-unit-label">Hours</div>
                        </div>
                        <div class="countdown-unit">
                            <div class="countdown-value" id="cd-mins">--</div>
                            <div class="countdown-unit-label">Minutes</div>
                        </div>
                        <div class="countdown-unit">
                            <div class="countdown-value" id="cd-secs">--</div>
                            <div class="countdown-unit-label">Seconds</div>
                        </div>
                    </div>
                </div>

                <?php if (!$isFull): ?>
                    <div class="draft-status-banner waiting banner-waiting" style="margin-top: 20px;">
                        <i class="fas fa-hourglass-half"></i>
                        <h2>Waiting for Players</h2>
                        <p><?php echo $spotsLeft; ?> spot<?php echo $spotsLeft > 1 ? 's' : ''; ?> remaining. Share the PIN code below to invite players.</p>
                    </div>
                <?php else: ?>
                    <div class="draft-status-banner ready banner-ready" style="margin-top: 20px;">
                        <i class="fas fa-check-circle"></i>
                        <h2>League Full — Ready to Draft</h2>
                        <p>All <?php echo $league['user_limit']; ?> spots filled. The draft will begin at the scheduled time.</p>
                    </div>
                <?php endif; ?>

                <?php if ($isCommissioner): ?>
                    <a href="../admin/draft_admin.php" class="action-btn btn-green">
                        <i class="fas fa-gavel"></i> Open Draft Admin
                    </a>
                <?php endif; ?>
            </div>

        <?php elseif ($hasDraftDate && $draftInPast): ?>
            <!-- DRAFT DATE PASSED -->
            <div class="card">
                <div class="draft-status-banner waiting banner-waiting">
                    <i class="fas fa-clock"></i>
                    <h2>Draft Day Has Arrived</h2>
                    <p>
                        Scheduled for <?php echo date('F j, Y \a\t g:i A', $draftDate); ?>.
                        <?php if ($isCommissioner): ?>
                            As commissioner, start the draft when everyone is ready.
                        <?php else: ?>
                            Waiting for the commissioner to start the draft.
                        <?php endif; ?>
                    </p>
                </div>

                <?php if ($isCommissioner): ?>
                    <a href="../admin/draft_admin.php" class="action-btn btn-green">
                        <i class="fas fa-gavel"></i> Start the Draft
                    </a>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- NO DRAFT DATE SET -->
            <div class="card">
                <div class="draft-status-banner not-set banner-not-set">
                    <i class="fas fa-calendar-times"></i>
                    <h2>No Draft Date Set</h2>
                    <p>
                        <?php if ($isCommissioner): ?>
                            Set a draft date from the League Hub so your players know when to show up.
                        <?php else: ?>
                            The commissioner hasn't set a draft date yet. Check back later.
                        <?php endif; ?>
                    </p>
                </div>

                <?php if ($isCommissioner): ?>
                    <a href="league_hub.php" class="action-btn btn-blue">
                        <i class="fas fa-calendar-alt"></i> Set Draft Date in League Hub
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Participants -->
        <div class="card">
            <div class="participants-header">
                <span class="participants-title"><i class="fas fa-users" style="color: var(--accent-blue); margin-right: 6px;"></i> Participants</span>
                <span class="participants-count"><?php echo $memberCount; ?>/<?php echo $league['user_limit']; ?></span>
            </div>

            <?php foreach ($members as $member): ?>
                <div class="participant-row">
                    <?php if ($member['is_commissioner']): ?>
                        <i class="fas fa-crown" style="color: var(--accent-purple);"></i>
                    <?php else: ?>
                        <i class="fas fa-user" style="color: var(--text-muted);"></i>
                    <?php endif; ?>
                    <span class="participant-name"><?php echo htmlspecialchars($member['display_name']); ?></span>
                    <?php if ($member['is_commissioner']): ?>
                        <span class="participant-tag tag-commissioner">Commissioner</span>
                    <?php endif; ?>
                    <?php if ($member['user_id'] == $userId && !$member['is_commissioner']): ?>
                        <span class="participant-tag tag-you">You</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php for ($i = 0; $i < $spotsLeft; $i++): ?>
                <div class="participant-row empty-slot">
                    <i class="fas fa-user-plus"></i>
                    <span>Open slot</span>
                </div>
            <?php endfor; ?>

            <?php if ($isCommissioner && !$isFull): ?>
                <div class="pin-share">
                    <span class="pin-share-label">Share PIN:</span>
                    <span class="pin-code" id="pin-code"><?php echo htmlspecialchars($league['pin_code']); ?></span>
                    <button class="copy-btn" onclick="copyPIN()" title="Copy PIN">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($hasDraftDate && !$draftInPast && !$draftCompleted && !$activeDraft): ?>
    <script>
    (function() {
        const draftTime = <?php echo $draftDate * 1000; ?>;

        function updateCountdown() {
            const now = Date.now();
            let diff = Math.max(0, draftTime - now);

            const days = Math.floor(diff / 86400000);
            diff %= 86400000;
            const hours = Math.floor(diff / 3600000);
            diff %= 3600000;
            const mins = Math.floor(diff / 60000);
            diff %= 60000;
            const secs = Math.floor(diff / 1000);

            document.getElementById('cd-days').textContent = String(days).padStart(2, '0');
            document.getElementById('cd-hours').textContent = String(hours).padStart(2, '0');
            document.getElementById('cd-mins').textContent = String(mins).padStart(2, '0');
            document.getElementById('cd-secs').textContent = String(secs).padStart(2, '0');

            // Color coding based on urgency
            const totalHours = (draftTime - now) / 3600000;
            const valueEls = document.querySelectorAll('.countdown-value');
            valueEls.forEach(el => {
                el.classList.remove('urgent', 'soon');
                if (totalHours <= 1) {
                    el.classList.add('urgent');
                } else if (totalHours <= 24) {
                    el.classList.add('soon');
                }
            });

            if (draftTime - now <= 0) {
                // Draft time reached — reload to show "Draft Day Has Arrived" state
                window.location.reload();
                return;
            }

            requestAnimationFrame(updateCountdown);
        }

        updateCountdown();
        // Also tick every second for consistent updates
        setInterval(updateCountdown, 1000);
    })();
    </script>
    <?php endif; ?>

    <script>
    function copyPIN() {
        const pin = document.getElementById('pin-code')?.textContent;
        if (!pin) return;
        navigator.clipboard.writeText(pin).then(() => {
            const btn = document.querySelector('.copy-btn');
            const original = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check" style="color: var(--accent-green);"></i>';
            setTimeout(() => { btn.innerHTML = original; }, 1500);
        });
    }
    </script>
</body>
</html>