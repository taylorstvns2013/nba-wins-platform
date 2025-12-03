#!/usr/bin/env python3
"""
Test script to verify nba_api roster fetching for 2025-26 season
Run this first to test before full update
"""

from nba_api.stats.static import teams
from nba_api.stats.endpoints import commonteamroster
import json

# Test with a single team first
CURRENT_SEASON = '2025-26'
TEST_TEAM_NAME = 'Boston Celtics'  # Current champions

print(f"\n{'='*60}")
print(f"Testing NBA Roster API - {CURRENT_SEASON} Season")
print(f"{'='*60}\n")

# Get all teams
print("Fetching team list...")
nba_teams = teams.get_teams()
print(f"✓ Found {len(nba_teams)} NBA teams\n")

# Find test team
test_team = next((t for t in nba_teams if t['full_name'] == TEST_TEAM_NAME), None)

if not test_team:
    print(f"✗ Could not find {TEST_TEAM_NAME}")
    print("\nAvailable teams:")
    for team in sorted(nba_teams, key=lambda x: x['full_name']):
        print(f"  - {team['full_name']} (ID: {team['id']})")
    exit(1)

print(f"Testing with: {test_team['full_name']}")
print(f"Team ID: {test_team['id']}\n")

# Fetch roster
print(f"Fetching {CURRENT_SEASON} roster...")
try:
    roster = commonteamroster.CommonTeamRoster(
        team_id=test_team['id'],
        season=CURRENT_SEASON,
        league_id_nullable='00'
    )
    
    # Get data as DataFrame
    df = roster.get_data_frames()[0]
    
    print(f"✓ Successfully retrieved roster\n")
    print(f"{'='*60}")
    print(f"{test_team['full_name']} - {CURRENT_SEASON} Season Roster")
    print(f"{'='*60}\n")
    
    if df.empty:
        print("⚠ WARNING: No roster data returned!")
        print("This might mean:")
        print("  - Season hasn't started yet")
        print("  - Rosters not finalized")
        print("  - API doesn't have 2025-26 data yet")
    else:
        print(f"Total Players: {len(df)}\n")
        
        # Display roster in organized format
        print(f"{'#':<4} {'Name':<25} {'Pos':<5} {'Ht':<6} {'Wt':<5} {'Age':<4} {'Exp':<4}")
        print(f"{'-'*60}")
        
        for _, player in df.iterrows():
            num = str(player.get('NUM', '-'))
            name = player.get('PLAYER', 'Unknown')[:25]
            pos = player.get('POSITION', '-')
            height = player.get('HEIGHT', '-')
            weight = str(player.get('WEIGHT', '-'))
            age = str(player.get('AGE', '-'))
            exp = player.get('EXP', '-')
            
            print(f"{num:<4} {name:<25} {pos:<5} {height:<6} {weight:<5} {age:<4} {exp:<4}")
        
        print(f"\n{'='*60}")
        print("Available Data Fields:")
        print(f"{'='*60}")
        print(", ".join(df.columns.tolist()))
        
        # Show sample player data
        print(f"\n{'='*60}")
        print("Sample Player Data (First Player):")
        print(f"{'='*60}")
        first_player = df.iloc[0].to_dict()
        for key, value in first_player.items():
            print(f"{key}: {value}")
        
        print(f"\n{'='*60}")
        print("✓ Test Successful!")
        print(f"{'='*60}")
        print(f"\nReady to run full roster update for all {len(nba_teams)} teams")
        print("Command: python3 /data/www/default/nba-wins-platform/tasks/update_roster_stats.py")
    
except Exception as e:
    print(f"✗ Error: {str(e)}\n")
    import traceback
    traceback.print_exc()
    
    print(f"\n{'='*60}")
    print("Troubleshooting:")
    print(f"{'='*60}")
    print("1. Check if nba_api is installed: pip3 install nba_api")
    print("2. Season might not be available yet in API")
    print("3. Try with previous season: '2024-25'")
    print("4. Check NBA API status")

print()