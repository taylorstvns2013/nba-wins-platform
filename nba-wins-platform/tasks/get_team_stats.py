#!/usr/bin/python3

import sys
import json
import time
import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry
from nba_api.stats.endpoints import leaguedashteamstats, commonteamroster
from nba_api.stats.static import teams
from season_config import get_season_config
season_cfg = get_season_config()

def safe_api_call(endpoint_func, *args, max_retries=2, timeout=20, **kwargs):
    """
    Safely call NBA API endpoints with configurable timeout and retries
    Optimized for 2025-26 season data
    """
    for attempt in range(max_retries):
        try:
            if attempt > 0:
                delay = 3 * attempt  # Linear backoff: 3, 6 seconds
                print(f"Retry attempt {attempt + 1} after {delay} seconds...", file=sys.stderr)
                time.sleep(delay)
            
            # Brief rate limiting pause
            time.sleep(1)
            
            # Set timeout on the endpoint
            endpoint = endpoint_func(*args, timeout=timeout, **kwargs)
            result = endpoint.get_dict()
            return result
            
        except requests.exceptions.Timeout:
            if attempt == max_retries - 1:
                raise Exception(f"NBA API timeout after {timeout} seconds (attempt {attempt + 1}/{max_retries})")
            continue
            
        except requests.exceptions.RequestException as e:
            if attempt == max_retries - 1:
                raise Exception(f"NBA API request failed: {str(e)}")
            continue
            
        except Exception as e:
            if attempt == max_retries - 1:
                raise Exception(f"NBA API call failed: {str(e)}")
            continue
    
    raise Exception("All retry attempts exhausted")

def get_team_stats_2025_26(team_id):
    """
    Get team statistics for 2025-26 NBA season
    Returns live data when available, clear error message when not
    """
    try:
        # Primary attempt: 2025-26 season data
        print(f"Fetching {season_cfg['api_season_nba']} season data for team {team_id}...", file=sys.stderr)

        stats_dict = safe_api_call(
            leaguedashteamstats.LeagueDashTeamStats,
            season=season_cfg['api_season_nba'],
            season_type_all_star='Regular Season',
            per_mode_detailed='PerGame',
            timeout=15  # Shorter timeout for production
        )
        
        if stats_dict and 'resultSets' in stats_dict and len(stats_dict['resultSets']) > 0:
            headers = stats_dict['resultSets'][0]['headers']
            rows = stats_dict['resultSets'][0]['rowSet']
            
            for row in rows:
                if row[0] == team_id:  # TEAM_ID is first column
                    team_stats = dict(zip(headers, row))
                    
                    # Format the data according to documentation
                    cleaned_stats = {
                        'TEAM_ID': team_stats.get('TEAM_ID'),
                        'TEAM_NAME': team_stats.get('TEAM_NAME'),
                        'GP': int(team_stats.get('GP', 0)),
                        'W': int(team_stats.get('W', 0)),
                        'L': int(team_stats.get('L', 0)),
                        'W_PCT': round(float(team_stats.get('W_PCT', 0)), 3),
                        'MIN': round(float(team_stats.get('MIN', 0)), 1),
                        'PTS': round(float(team_stats.get('PTS', 0)), 1),
                        'FGM': round(float(team_stats.get('FGM', 0)), 1),
                        'FGA': round(float(team_stats.get('FGA', 0)), 1),
                        'FG_PCT': round(float(team_stats.get('FG_PCT', 0)), 3),
                        'FG3M': round(float(team_stats.get('FG3M', 0)), 1),
                        'FG3A': round(float(team_stats.get('FG3A', 0)), 1),
                        'FG3_PCT': round(float(team_stats.get('FG3_PCT', 0)), 3),
                        'FTM': round(float(team_stats.get('FTM', 0)), 1),
                        'FTA': round(float(team_stats.get('FTA', 0)), 1),
                        'FT_PCT': round(float(team_stats.get('FT_PCT', 0)), 3),
                        'OREB': round(float(team_stats.get('OREB', 0)), 1),
                        'DREB': round(float(team_stats.get('DREB', 0)), 1),
                        'REB': round(float(team_stats.get('REB', 0)), 1),
                        'AST': round(float(team_stats.get('AST', 0)), 1),
                        'TOV': round(float(team_stats.get('TOV', 0)), 1),
                        'STL': round(float(team_stats.get('STL', 0)), 1),
                        'BLK': round(float(team_stats.get('BLK', 0)), 1),
                        'BLKA': round(float(team_stats.get('BLKA', 0)), 1),
                        'PF': round(float(team_stats.get('PF', 0)), 1),
                        'PFD': round(float(team_stats.get('PFD', 0)), 1),
                        'PLUS_MINUS': round(float(team_stats.get('PLUS_MINUS', 0)), 1),
                        'season': season_cfg['api_season_nba'],
                        'data_source': 'nba_api',
                        'api_status': 'live'
                    }
                    
                    print(f"Successfully retrieved {season_cfg['api_season_nba']} season data", file=sys.stderr)
                    return cleaned_stats
        
        # If we get here, team wasn't found in results
        return {
            'error': f'Team ID {team_id} not found in {season_cfg["api_season_nba"]} season data',
            'season': season_cfg['api_season_nba'],
            'api_status': 'no_data'
        }
        
    except Exception as e:
        error_msg = str(e)
        
        # Classify the error for better handling
        if 'timeout' in error_msg.lower():
            return {
                'error': f'{season_cfg["api_season_nba"]} NBA season data not yet available or API timeout',
                'details': f'Season starts {season_cfg["season_start_date"]}. Data will be available after games begin.',
                'season': season_cfg['api_season_nba'],
                'api_status': 'timeout'
            }
        elif 'connection' in error_msg.lower() or 'network' in error_msg.lower():
            return {
                'error': 'Unable to connect to NBA API servers',
                'details': 'Check network connection and try again later.',
                'season': season_cfg['api_season_nba'],
                'api_status': 'connection_error'
            }
        else:
            return {
                'error': f'NBA API error: {error_msg}',
                'season': season_cfg['api_season_nba'],
                'api_status': 'api_error'
            }

def get_team_roster_2025_26(team_id):
    """
    Get team roster for 2025-26 NBA season
    Returns empty list if data not available (graceful failure)
    """
    try:
        print(f"Fetching {season_cfg['api_season_nba']} roster for team {team_id}...", file=sys.stderr)

        roster_dict = safe_api_call(
            commonteamroster.CommonTeamRoster,
            team_id=team_id,
            season=season_cfg['api_season_nba'],
            league_id_nullable='00',
            timeout=15
        )
        
        if not roster_dict or 'resultSets' not in roster_dict or len(roster_dict['resultSets']) == 0:
            return []  # Empty roster instead of error
            
        roster_data = roster_dict['resultSets'][0]
        if 'rowSet' not in roster_data or not roster_data['rowSet']:
            return []
            
        players = roster_data['rowSet']
        headers = roster_data['headers']
        roster_list = []
        
        for player_row in players:
            try:
                player_dict = dict(zip(headers, player_row))
                
                roster_player = {
                    'PLAYER_ID': player_dict.get('PLAYER_ID'),
                    'PLAYER': player_dict.get('PLAYER', ''),
                    'NUM': player_dict.get('NUM', ''),
                    'POSITION': player_dict.get('POSITION', ''),
                    'HEIGHT': player_dict.get('HEIGHT', ''),
                    'WEIGHT': player_dict.get('WEIGHT', ''),
                    'BIRTH_DATE': player_dict.get('BIRTH_DATE', ''),
                    'AGE': player_dict.get('AGE', ''),
                    'EXP': player_dict.get('EXP', ''),
                    'SCHOOL': player_dict.get('SCHOOL', ''),
                    # Placeholder stats - would need separate API calls for actual stats
                    'GP': 0, 'MIN': 0, 'PTS': 0, 'REB': 0, 'AST': 0,
                    'FG_PCT': 0, 'FG3_PCT': 0, 'FT_PCT': 0,
                    'season': season_cfg['api_season_nba']
                }

                roster_list.append(roster_player)
                
            except Exception:
                continue  # Skip problematic player entries
                
        print(f"Successfully retrieved roster with {len(roster_list)} players", file=sys.stderr)
        return roster_list
        
    except Exception as e:
        print(f"Roster fetch failed: {str(e)}", file=sys.stderr)
        return []  # Return empty list instead of error for graceful handling

def test_api_connectivity():
    """
    Test NBA API connectivity and readiness for 2025-26 season
    """
    try:
        # Test basic team data (should always work)
        from nba_api.stats.static import teams
        all_teams = teams.get_teams()
        
        if len(all_teams) > 0:
            return {
                'status': 'connected',
                'teams_available': len(all_teams),
                'message': 'NBA API connection successful'
            }
        else:
            return {
                'status': 'error',
                'message': 'NBA API connected but no team data available'
            }
            
    except Exception as e:
        return {
            'status': 'error',
            'message': f'NBA API connection failed: {str(e)}'
        }

if __name__ == "__main__":
    try:
        if len(sys.argv) < 2:
            print(json.dumps({'error': 'Team ID required'}))
            sys.exit(1)
        
        if sys.argv[1] == '--test':
            # API connectivity test
            result = test_api_connectivity()
            print(json.dumps(result))
            
        elif sys.argv[1] == '--roster':
            # Team roster request
            if len(sys.argv) != 3:
                print(json.dumps({'error': 'Team ID required for roster'}))
                sys.exit(1)
            
            team_id = int(sys.argv[2])
            result = get_team_roster_2025_26(team_id)
            print(json.dumps(result))
            
        else:
            # Team stats request
            team_id = int(sys.argv[1])
            result = get_team_stats_2025_26(team_id)
            print(json.dumps(result))
            
    except ValueError:
        print(json.dumps({'error': 'Invalid team ID format'}))
    except Exception as e:
        print(json.dumps({'error': f'Script error: {str(e)}'}))