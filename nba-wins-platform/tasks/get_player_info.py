#!/usr/bin/env python3
"""
get_player_info.py - Fetch Player Career Stats from NBA API
Location: /data/www/default/nba-wins-platform/tasks/

Fetches player career statistics by player name for real-time display.
Returns JSON data with player info, career stats, and current season stats.

Usage:
    python3 get_player_info.py "Karl-Anthony Towns"
    python3 get_player_info.py "LeBron James"
"""

import sys
import json
from nba_api.stats.static import players
from nba_api.stats.endpoints import commonplayerinfo, playercareerstats
import time

def find_player_by_name(player_name):
    """
    Find player ID by name (handles partial matches)
    
    Args:
        player_name: Full or partial player name
    
    Returns:
        dict: Player info with id, full_name, etc.
    """
    # Get all NBA players
    all_players = players.get_players()
    
    # Clean the search name
    search_name = player_name.lower().strip()
    
    # Try exact match first
    for player in all_players:
        if player['full_name'].lower() == search_name:
            return player
    
    # Try partial match
    matches = []
    for player in all_players:
        if search_name in player['full_name'].lower():
            matches.append(player)
    
    # Return best match (or first if multiple)
    if matches:
        return matches[0]
    
    return None

def get_player_career_stats(player_id):
    """
    Fetch career statistics for a player
    
    Args:
        player_id: NBA player ID
    
    Returns:
        dict: Career stats data
    """
    try:
        # Get basic player info
        player_info = commonplayerinfo.CommonPlayerInfo(player_id=player_id)
        info_data = player_info.common_player_info.get_dict()
        
        # Get career stats
        career_stats = playercareerstats.PlayerCareerStats(player_id=player_id)
        
        # Season totals
        season_totals = career_stats.season_totals_regular_season.get_dict()
        
        # Career totals
        career_totals = career_stats.career_totals_regular_season.get_dict()
        
        return {
            'player_info': info_data,
            'season_totals': season_totals,
            'career_totals': career_totals
        }
        
    except Exception as e:
        return {'error': str(e)}

def format_player_data(player, stats_data):
    """
    Format player data for JSON output
    
    Args:
        player: Player info dict from find_player_by_name
        stats_data: Career stats data
    
    Returns:
        dict: Formatted player data
    """
    if 'error' in stats_data:
        return {
            'success': False,
            'error': stats_data['error'],
            'player_name': player['full_name']
        }
    
    # Extract player info
    player_info_data = stats_data['player_info']['data'][0] if stats_data['player_info']['data'] else {}
    
    # Extract current season stats (most recent season)
    season_data = stats_data['season_totals']['data']
    current_season = season_data[0] if season_data else {}
    
    # Extract career totals
    career_data = stats_data['career_totals']['data']
    career_totals = career_data[0] if career_data else {}
    
    # Build response
    response = {
        'success': True,
        'player': {
            'id': player['id'],
            'full_name': player['full_name'],
            'first_name': player_info_data.get('FIRST_NAME', ''),
            'last_name': player_info_data.get('LAST_NAME', ''),
            'birthdate': player_info_data.get('BIRTHDATE', ''),
            'school': player_info_data.get('SCHOOL', ''),
            'country': player_info_data.get('COUNTRY', ''),
            'height': player_info_data.get('HEIGHT', ''),
            'weight': player_info_data.get('WEIGHT', ''),
            'position': player_info_data.get('POSITION', ''),
            'jersey': player_info_data.get('JERSEY', ''),
            'team_name': player_info_data.get('TEAM_NAME', ''),
            'team_abbreviation': player_info_data.get('TEAM_ABBREVIATION', ''),
            'team_city': player_info_data.get('TEAM_CITY', ''),
            'draft_year': player_info_data.get('DRAFT_YEAR', ''),
            'draft_round': player_info_data.get('DRAFT_ROUND', ''),
            'draft_number': player_info_data.get('DRAFT_NUMBER', ''),
        },
        'current_season': {
            'season': current_season.get('SEASON_ID', ''),
            'team': current_season.get('TEAM_ABBREVIATION', ''),
            'games_played': current_season.get('GP', 0),
            'games_started': current_season.get('GS', 0),
            'minutes': current_season.get('MIN', 0),
            'points': current_season.get('PTS', 0),
            'rebounds': current_season.get('REB', 0),
            'assists': current_season.get('AST', 0),
            'steals': current_season.get('STL', 0),
            'blocks': current_season.get('BLK', 0),
            'turnovers': current_season.get('TOV', 0),
            'fg_made': current_season.get('FGM', 0),
            'fg_attempts': current_season.get('FGA', 0),
            'fg_pct': current_season.get('FG_PCT', 0),
            'fg3_made': current_season.get('FG3M', 0),
            'fg3_attempts': current_season.get('FG3A', 0),
            'fg3_pct': current_season.get('FG3_PCT', 0),
            'ft_made': current_season.get('FTM', 0),
            'ft_attempts': current_season.get('FTA', 0),
            'ft_pct': current_season.get('FT_PCT', 0),
        },
        'career_totals': {
            'games_played': career_totals.get('GP', 0),
            'games_started': career_totals.get('GS', 0),
            'minutes': career_totals.get('MIN', 0),
            'points': career_totals.get('PTS', 0),
            'rebounds': career_totals.get('REB', 0),
            'assists': career_totals.get('AST', 0),
            'steals': career_totals.get('STL', 0),
            'blocks': career_totals.get('BLK', 0),
            'turnovers': career_totals.get('TOV', 0),
            'fg_made': career_totals.get('FGM', 0),
            'fg_attempts': career_totals.get('FGA', 0),
            'fg_pct': career_totals.get('FG_PCT', 0),
            'fg3_made': career_totals.get('FG3M', 0),
            'fg3_attempts': career_totals.get('FG3A', 0),
            'fg3_pct': career_totals.get('FG3_PCT', 0),
            'ft_made': career_totals.get('FTM', 0),
            'ft_attempts': career_totals.get('FTA', 0),
            'ft_pct': career_totals.get('FT_PCT', 0),
        },
        'season_by_season': []
    }
    
    # Add season-by-season breakdown
    for season in season_data:
        response['season_by_season'].append({
            'season': season.get('SEASON_ID', ''),
            'team': season.get('TEAM_ABBREVIATION', ''),
            'age': season.get('PLAYER_AGE', 0),
            'games_played': season.get('GP', 0),
            'games_started': season.get('GS', 0),
            'minutes_per_game': round(season.get('MIN', 0) / max(season.get('GP', 1), 1), 1),
            'points_per_game': round(season.get('PTS', 0) / max(season.get('GP', 1), 1), 1),
            'rebounds_per_game': round(season.get('REB', 0) / max(season.get('GP', 1), 1), 1),
            'assists_per_game': round(season.get('AST', 0) / max(season.get('GP', 1), 1), 1),
            'fg_pct': round(season.get('FG_PCT', 0) * 100, 1),
            'fg3_pct': round(season.get('FG3_PCT', 0) * 100, 1),
            'ft_pct': round(season.get('FT_PCT', 0) * 100, 1),
        })
    
    return response

def main():
    if len(sys.argv) < 2:
        print(json.dumps({
            'success': False,
            'error': 'Player name required'
        }))
        sys.exit(1)
    
    player_name = sys.argv[1]
    
    # Find player
    player = find_player_by_name(player_name)
    
    if not player:
        print(json.dumps({
            'success': False,
            'error': f'Player not found: {player_name}'
        }))
        sys.exit(1)
    
    # Fetch career stats
    try:
        stats_data = get_player_career_stats(player['id'])
        
        # Format and output JSON
        result = format_player_data(player, stats_data)
        print(json.dumps(result))
        
    except Exception as e:
        print(json.dumps({
            'success': False,
            'error': str(e),
            'player_name': player_name
        }))
        sys.exit(1)

if __name__ == '__main__':
    main()