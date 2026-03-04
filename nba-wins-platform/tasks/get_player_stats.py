#!/usr/bin/python3

import sys
import json
import time
import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry
from nba_api.stats.endpoints import playercareerstats, playergamelog, playerdashboardbygeneralsplits
from nba_api.stats.static import players
from season_config import get_season_config
season_cfg = get_season_config()

# Configure requests session with retries and longer timeouts
def create_robust_session():
    """Create a requests session with retry strategy and longer timeouts"""
    session = requests.Session()
    
    retry_strategy = Retry(
        total=3,
        backoff_factor=2,
        status_forcelist=[429, 500, 502, 503, 504],
    )
    
    adapter = HTTPAdapter(max_retries=retry_strategy)
    session.mount("http://", adapter)
    session.mount("https://", adapter)
    
    # Set longer timeout
    session.timeout = 60
    
    return session

def safe_api_call(endpoint_func, *args, max_retries=3, base_delay=2, **kwargs):
    """
    Safely call NBA API endpoints with retry logic and error handling
    """
    for attempt in range(max_retries):
        try:
            # Add delay between attempts
            if attempt > 0:
                delay = base_delay * (2 ** attempt)  # Exponential backoff
                print(f"Retry attempt {attempt + 1} after {delay} seconds...", file=sys.stderr)
                time.sleep(delay)
            
            # Create endpoint
            endpoint = endpoint_func(*args, **kwargs)
            
            # Wait before making request
            time.sleep(1)
            
            result = endpoint.get_dict()
            return result
            
        except requests.exceptions.Timeout as e:
            if attempt == max_retries - 1:
                raise Exception(f"Timeout after {max_retries} attempts: {str(e)}")
            continue
            
        except requests.exceptions.RequestException as e:
            if attempt == max_retries - 1:
                raise Exception(f"Request failed after {max_retries} attempts: {str(e)}")
            continue
            
        except Exception as e:
            if attempt == max_retries - 1:
                raise Exception(f"API call failed: {str(e)}")
            continue
    
    raise Exception("All retry attempts exhausted")

def find_player_id(player_name):
    """
    Find NBA API player ID from player name with improved matching
    """
    try:
        all_players = players.get_players()
        
        # Try exact match first
        for player in all_players:
            if player['full_name'].lower() == player_name.lower():
                return player['id']
        
        # Try partial match
        for player in all_players:
            if player_name.lower() in player['full_name'].lower():
                return player['id']
                
        # Try reverse partial match
        for player in all_players:
            if player['full_name'].lower() in player_name.lower():
                return player['id']
        
        return None
        
    except Exception as e:
        return None

def get_player_career_stats(player_id):
    """
    Get player career statistics with robust error handling
    """
    try:
        stats_dict = safe_api_call(playercareerstats.PlayerCareerStats, player_id=player_id)
        
        current_season = None
        career_stats = []
        
        if ('resultSets' in stats_dict and 
            len(stats_dict['resultSets']) > 0 and 
            'rowSet' in stats_dict['resultSets'][0]):
            
            regular_season = stats_dict['resultSets'][0]
            headers = [h.lower() for h in regular_season['headers']]
            rows = regular_season['rowSet']
            
            for row in rows:
                try:
                    stats = dict(zip(headers, row))
                    season_data = {
                        'season': stats.get('season_id', ''),
                        'team': stats.get('team_abbreviation', ''),
                        'games_played': int(stats.get('gp', 0)),
                        'games_started': int(stats.get('gs', 0)),
                        'minutes': round(float(stats.get('min', 0)), 1),
                        'pts': round(float(stats.get('pts', 0)), 1),
                        'reb': round(float(stats.get('reb', 0)), 1),
                        'ast': round(float(stats.get('ast', 0)), 1),
                        'stl': round(float(stats.get('stl', 0)), 1),
                        'blk': round(float(stats.get('blk', 0)), 1),
                        'tov': round(float(stats.get('tov', 0)), 1),
                        'pf': round(float(stats.get('pf', 0)), 1),
                        'fg_pct': round(float(stats.get('fg_pct', 0)), 3),
                        'fg3_pct': round(float(stats.get('fg3_pct', 0)), 3),
                        'ft_pct': round(float(stats.get('ft_pct', 0)), 3)
                    }
                    
                    if stats.get('season_id') == season_cfg['api_season_nba']:
                        current_season = season_data
                        
                    career_stats.append(season_data)
                except:
                    continue  # Skip problematic rows
        
        # Sort by season (most recent first)
        career_stats.sort(key=lambda x: x['season'], reverse=True)
        
        return current_season, career_stats
        
    except Exception as e:
        return None, []

def get_player_recent_games(player_id, num_games=5):
    """
    Get player's recent game logs - simplified to avoid timeouts
    """
    try:
        # Skip recent games for now to avoid additional timeouts
        # This can be enabled later once basic functionality is working
        return []
        
    except Exception as e:
        return []

def get_player_advanced_stats(player_id):
    """
    Get player's advanced statistics - simplified to avoid timeouts
    """
    try:
        # Skip advanced stats for now to avoid additional timeouts
        # This can be enabled later once basic functionality is working
        return {}
        
    except Exception as e:
        return {}

def get_player_stats(player_name):
    """
    Get comprehensive player statistics with timeout handling
    """
    try:
        player_id = find_player_id(player_name)
        if not player_id:
            return {'error': f'Player "{player_name}" not found'}
        
        # Get career stats (most important data)
        current_season, career_stats = get_player_career_stats(player_id)
        
        # Skip recent games and advanced stats to avoid timeouts for now
        recent_games = []
        advanced_stats = {}
        
        result = {
            'player_id': player_id,
            'player_name': player_name,
            'current_season': current_season or {},
            'career_stats': career_stats,
            'recent_games': recent_games,
            'advanced_stats': advanced_stats,
            'total_seasons': len(career_stats),
            'note': 'Recent games and advanced stats temporarily disabled to prevent timeouts'
        }
        
        return result
        
    except Exception as e:
        return {'error': f'Failed to get player stats: {str(e)}'}

if __name__ == "__main__":
    try:
        if len(sys.argv) < 2:
            print(json.dumps({'error': 'No player name provided'}))
            sys.exit(1)
        
        player_name = ' '.join(sys.argv[1:])
        result = get_player_stats(player_name)
        print(json.dumps(result))
        
    except Exception as e:
        print(json.dumps({'error': f'Script error: {str(e)}'}))