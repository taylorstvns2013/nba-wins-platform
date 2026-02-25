<?php
// nba-wins-platform/components/LeagueSwitcher_new.php
// Dark Theme League Switcher - For use with index_new.php
?>

<div class="dark-league-switcher">
    <span class="dark-ls-welcome">Welcome, <?php echo htmlspecialchars($_SESSION['display_name']); ?></span>
    
    <?php
    $userLeagues = $auth->getUserLeagues();
    $currentLeague = $auth->getCurrentLeague();
    
    if (count($userLeagues) > 1): ?>
        <select id="league-switcher" class="dark-ls-select" onchange="switchLeague(this.value)">
            <?php foreach ($userLeagues as $ls_league): ?>
                <option value="<?php echo $ls_league['id']; ?>" 
                        <?php echo ($ls_league['id'] == $_SESSION['current_league_id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($ls_league['display_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php else: ?>
        <span class="dark-ls-badge"><?php echo htmlspecialchars($currentLeague['display_name']); ?></span>
    <?php endif; ?>
</div>

<style>
.dark-league-switcher {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 16px;
    margin: 0 12px;
    background: #161b22;
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.06);
    flex-wrap: wrap;
    gap: 10px;
}

.dark-ls-welcome {
    font-family: 'Outfit', sans-serif;
    font-weight: 600;
    font-size: 14px;
    color: #e6edf3;
}

.dark-ls-select {
    padding: 6px 28px 6px 12px;
    font-family: 'Outfit', sans-serif;
    font-size: 13px;
    font-weight: 500;
    background: #1c2333;
    color: #e6edf3;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 8px;
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238b949e' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
    transition: all 0.15s ease;
    min-width: 120px;
}

.dark-ls-select:hover {
    border-color: rgba(56, 139, 253, 0.3);
}

.dark-ls-select:focus {
    outline: none;
    border-color: #388bfd;
    box-shadow: 0 0 0 2px rgba(56, 139, 253, 0.15);
}

.dark-ls-select option {
    background: #1c2333;
    color: #e6edf3;
}

.dark-ls-badge {
    padding: 6px 12px;
    background: rgba(255, 255, 255, 0.05);
    color: #8b949e;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 8px;
    font-family: 'Outfit', sans-serif;
    font-size: 13px;
    font-weight: 500;
}

@media (max-width: 480px) {
    .dark-league-switcher {
        flex-direction: column;
        align-items: stretch;
        text-align: center;
        padding: 10px 12px;
    }
    
    .dark-ls-welcome {
        font-size: 13px;
    }
    
    .dark-ls-select {
        width: 100%;
    }
}

<?php if (($_SESSION['theme_preference'] ?? 'dark') === 'classic'): ?>
/* Classic theme overrides */
.dark-league-switcher {
    background: #ffffff;
    border-color: #e0e0e0;
}
.dark-ls-welcome { color: #333; }
.dark-ls-select {
    background-color: #f5f5f5;
    color: #333;
    border-color: #ccc;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23666666' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
}
.dark-ls-select:hover { border-color: rgba(0, 102, 255, 0.3); }
.dark-ls-select:focus { border-color: #0066ff; box-shadow: 0 0 0 2px rgba(0, 102, 255, 0.12); }
.dark-ls-select option { background: white; color: #333; }
.dark-ls-badge {
    background: #f0f0f2;
    color: #666;
    border-color: #e0e0e0;
}
<?php endif; ?>
</style>

<script>
function switchLeague(leagueId) {
    if (!leagueId) return;
    
    const switcher = document.getElementById('league-switcher');
    switcher.disabled = true;
    
    fetch('/nba-wins-platform/auth/switch_league.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'league_id=' + encodeURIComponent(leagueId)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert('Error switching league: ' + data.message);
            switcher.value = <?php echo $_SESSION['current_league_id']; ?>;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error switching league');
        switcher.value = <?php echo $_SESSION['current_league_id']; ?>;
    })
    .finally(() => {
        switcher.disabled = false;
    });
}
</script>