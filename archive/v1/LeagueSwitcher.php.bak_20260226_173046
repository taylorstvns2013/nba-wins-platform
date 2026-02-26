<?php
// nba-wins-platform/components/LeagueSwitcher.php
?>

<div class="league-switcher-container">
    <div class="user-info">
        <span class="welcome-text">Welcome, <?php echo htmlspecialchars($_SESSION['display_name']); ?></span>
        
        <?php
        $userLeagues = $auth->getUserLeagues();
        $currentLeague = $auth->getCurrentLeague();
        
        if (count($userLeagues) > 1): ?>
            <select id="league-switcher" class="league-select" onchange="switchLeague(this.value)">
                <?php foreach ($userLeagues as $league): ?>
                    <option value="<?php echo $league['id']; ?>" 
                            <?php echo ($league['id'] == $_SESSION['current_league_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($league['display_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php else: ?>
            <span class="current-league"><?php echo htmlspecialchars($currentLeague['display_name']); ?></span>
        <?php endif; ?>
    </div>
</div>

<style>
.league-switcher-container {
    background-color: rgba(33, 33, 33, 0.95);
    color: white;
    padding: 10px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.user-info {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 15px;
}

.welcome-text {
    font-weight: 600;
    font-size: 16px;
}

.league-select {
    padding: 6px 12px;
    border: 1px solid #666;
    border-radius: 4px;
    background-color: white;
    color: #333;
    font-size: 14px;
    cursor: pointer;
    min-width: 120px;
}

.current-league {
    background-color: rgba(255, 255, 255, 0.1);
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 14px;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

@media (max-width: 600px) {
    .user-info {
        flex-direction: column;
        align-items: stretch;
        text-align: center;
    }
    
    .welcome-text {
        font-size: 14px;
    }
    
    .league-select, .current-league {
        margin: 5px 0;
    }
}
</style>

<script>
function switchLeague(leagueId) {
    if (!leagueId) return;
    
    // Show loading state
    const switcher = document.getElementById('league-switcher');
    const originalText = switcher.options[switcher.selectedIndex].text;
    switcher.disabled = true;
    
    // Send AJAX request to switch league
    fetch('/nba-wins-platform/auth/switch_league.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'league_id=' + encodeURIComponent(leagueId)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Reload page to show new league data
            window.location.reload();
        } else {
            alert('Error switching league: ' + data.message);
            // Revert selection
            switcher.value = <?php echo $_SESSION['current_league_id']; ?>;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error switching league');
        // Revert selection
        switcher.value = <?php echo $_SESSION['current_league_id']; ?>;
    })
    .finally(() => {
        switcher.disabled = false;
    });
}
</script>