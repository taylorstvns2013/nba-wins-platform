#!/bin/bash
set -e

WEB_ROOT="/data/www/default"

echo ""
echo "=========================================="
echo "  NBA Wins Pool - Remove _new Migration"
echo "=========================================="
echo ""

echo "[STEP 1/2] Updating references inside files..."
echo ""

for pair in \
    "index_new.php|index.php" \
    "nba_standings_new.php|nba_standings.php" \
    "draft_summary_new.php|draft_summary.php" \
    "draft_new.php|draft.php" \
    "analytics_new.php|analytics.php" \
    "claudes-column_new.php|claudes-column.php" \
    "participant_profile_new.php|participant_profile.php" \
    "team_comparison_new.php|team_comparison.php" \
    "game_details_new.php|game_details.php" \
    "team_data_new.php|team_data.php" \
    "player_profile_new.php|player_profile.php" \
    "navigation_menu_new.php|navigation_menu.php" \
    "LeagueSwitcher_new.php|LeagueSwitcher.php" \
    "DashboardWidget_new.php|DashboardWidget.php"; do

    old_text="${pair%%|*}"
    new_text="${pair##*|}"
    matches=$(grep -rl "$old_text" "$WEB_ROOT" --include="*.php" 2>/dev/null || true)
    count=$(echo "$matches" | grep -c . 2>/dev/null || echo 0)
    if [ -n "$matches" ] && [ "$count" -gt 0 ]; then
        echo "$matches" | while read -r file; do
            sed -i "s|$old_text|$new_text|g" "$file"
        done
        echo "  ✓ $old_text -> $new_text  (in $count files)"
    else
        echo "  - $old_text  (no references found)"
    fi
done

echo ""
echo "[STEP 2/2] Renaming files..."
echo ""

for pair in \
    "$WEB_ROOT/index_new.php|$WEB_ROOT/index.php" \
    "$WEB_ROOT/nba_standings_new.php|$WEB_ROOT/nba_standings.php" \
    "$WEB_ROOT/draft_summary_new.php|$WEB_ROOT/draft_summary.php" \
    "$WEB_ROOT/draft_new.php|$WEB_ROOT/draft.php" \
    "$WEB_ROOT/analytics_new.php|$WEB_ROOT/analytics.php" \
    "$WEB_ROOT/claudes-column_new.php|$WEB_ROOT/claudes-column.php" \
    "$WEB_ROOT/nba-wins-platform/profiles/participant_profile_new.php|$WEB_ROOT/nba-wins-platform/profiles/participant_profile.php" \
    "$WEB_ROOT/nba-wins-platform/stats/team_comparison_new.php|$WEB_ROOT/nba-wins-platform/stats/team_comparison.php" \
    "$WEB_ROOT/nba-wins-platform/stats/game_details_new.php|$WEB_ROOT/nba-wins-platform/stats/game_details.php" \
    "$WEB_ROOT/nba-wins-platform/stats/team_data_new.php|$WEB_ROOT/nba-wins-platform/stats/team_data.php" \
    "$WEB_ROOT/nba-wins-platform/stats/player_profile_new.php|$WEB_ROOT/nba-wins-platform/stats/player_profile.php" \
    "$WEB_ROOT/nba-wins-platform/components/navigation_menu_new.php|$WEB_ROOT/nba-wins-platform/components/navigation_menu.php" \
    "$WEB_ROOT/nba-wins-platform/components/LeagueSwitcher_new.php|$WEB_ROOT/nba-wins-platform/components/LeagueSwitcher.php" \
    "$WEB_ROOT/nba-wins-platform/core/DashboardWidget_new.php|$WEB_ROOT/nba-wins-platform/core/DashboardWidget.php"; do

    old_path="${pair%%|*}"
    new_path="${pair##*|}"

    if [ ! -f "$old_path" ]; then
        echo "  - SKIP (not found): $(basename $old_path)"
        continue
    fi

    if [ -f "$new_path" ]; then
        backup="${new_path}.bak_$(date +%Y%m%d_%H%M%S)"
        mv "$new_path" "$backup"
        echo "  ! Backed up: $(basename $new_path) -> $(basename $backup)"
    fi

    mv "$old_path" "$new_path"
    echo "  ✓ $(basename $old_path) -> $(basename $new_path)"
done

echo ""
echo "=========================================="
echo "  Migration complete!"
echo "=========================================="
echo ""
echo "Next steps:"
echo "  1. Test your site"
echo "  2. git add . && git commit -m 'Removed _new suffixes' && git push"
echo "  3. Delete .bak_* files when confident"
echo ""