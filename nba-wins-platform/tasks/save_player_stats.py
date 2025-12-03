#!/usr/bin/env python3
"""
save_player_stats.py - Fetch and Store Player Stats from NBA Live API
Location: /data/www/default/nba-wins-platform/tasks/

FIXED: Now extracts game date from API data instead of querying database
This prevents using wrong dates when multiple games exist between same teams

Features:
- Extracts date from gameCode (format: "20251004/NYKPHI")
- Only processes games that have started (status 2 or 3)
- Updates existing records or inserts new ones
- Comprehensive error handling
- Prevents duplicate entries

Usage:
    python3 save_player_stats.py                # Standard run
    python3 save_player_stats.py --verbose      # Detailed output
    python3 save_player_stats.py --debug        # Full debug info
"""

from nba_api.live.nba.endpoints import scoreboard, boxscore
import json
from datetime import datetime
import pytz
import sys
import argparse
import logging
import time
import os

# Configure logging
log_handlers = [logging.StreamHandler()]

try:
    log_file = '/tmp/nba_save_player_stats.log'
    log_handlers.append(logging.FileHandler(log_file, mode='a'))
except PermissionError:
    try:
        home_dir = os.path.expanduser('~')
        log_file = os.path.join(home_dir, 'nba_save_player_stats.log')
        log_handlers.append(logging.FileHandler(log_file, mode='a'))
    except:
        pass

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=log_handlers
)
logger = logging.getLogger(__name__)

# Team name mapping for consistency
TEAM_NAME_MAP = {
    'LA Clippers': 'Los Angeles Clippers',
    'LA Lakers': 'Los Angeles Lakers',
}

def normalize_team_name(team_city, team_name):
    """Normalize team names for consistency with database"""
    full_name = f"{team_city} {team_name}"
    return TEAM_NAME_MAP.get(full_name, full_name)

def get_games_from_api(max_retries=3, retry_delay=2):
    """Fetch current games from NBA API with retry logic"""
    for attempt in range(max_retries):
        try:
            logger.info(f"Fetching games from NBA API (attempt {attempt + 1}/{max_retries})...")
            board = scoreboard.ScoreBoard()
            
            # Get the full dictionary structure (same as get_games.py does)
            games_dict = board.get_dict()
            
            # Extract games array from the scoreboard structure
            games_list = games_dict.get('scoreboard', {}).get('games', [])
            
            logger.info(f"Successfully fetched {len(games_list)} games")
            
            # Return the full dict structure to match get_games.py
            return games_dict
            
        except Exception as e:
            logger.error(f"Error fetching games (attempt {attempt + 1}/{max_retries}): {str(e)}")
            if attempt < max_retries - 1:
                time.sleep(retry_delay * (attempt + 1))
            else:
                logger.error("All retry attempts failed")
                return {'scoreboard': {'games': []}}

def format_minutes(minutes_str):
    """
    Format minutes from API format to database format
    API format: "PT32M15.00S" or could be a simple string
    Database format: "32:15" or similar
    """
    if not minutes_str or minutes_str == "0:00":
        return None
    
    # If already in MM:SS format
    if ':' in str(minutes_str):
        return str(minutes_str)
    
    # If in PT format (e.g., "PT32M15.00S")
    if isinstance(minutes_str, str) and minutes_str.startswith('PT'):
        try:
            # Remove PT prefix and S suffix
            time_part = minutes_str.replace('PT', '').replace('S', '')
            
            # Split by M to get minutes and seconds
            if 'M' in time_part:
                parts = time_part.split('M')
                mins = int(float(parts[0]))
                secs = int(float(parts[1])) if len(parts) > 1 and parts[1] else 0
                return f"{mins}:{secs:02d}"
        except:
            pass
    
    # Try to parse as a simple number (minutes as float)
    try:
        total_mins = float(minutes_str)
        mins = int(total_mins)
        secs = int((total_mins - mins) * 60)
        return f"{mins}:{secs:02d}"
    except:
        pass
    
    return str(minutes_str)

def save_player_stats_to_db(games, verbose=False, debug=False):
    """
    Save player statistics to database
    
    Args:
        games: List of games from NBA API
        verbose: Print detailed progress
        debug: Print debug info including SQL
    
    Returns:
        bool: True if successful
    """
    connection = None
    cursor = None
    
    try:
        import mysql.connector
        from mysql.connector import Error, errorcode
    except ImportError:
        logger.error("mysql-connector-python not installed")
        if verbose or debug:
            print("\n✗ Error: mysql-connector-python not installed")
            print("Install with: pip3 install mysql-connector-python --break-system-packages\n")
        return False
    
    try:
        # Database connection with multiple methods
        connection_methods = [
            {'unix_socket': '/tmp/mysql.sock', 'database': 'nba_wins_platform'},
            {'unix_socket': '/var/run/mysqld/mysqld.sock', 'database': 'nba_wins_platform'},
            {'host': 'localhost', 'database': 'nba_wins_platform'},
        ]
        
        connected = False
        for method in connection_methods:
            try:
                connection = mysql.connector.connect(
                    user='nba_app',
                    password='DB_PASSWORD_REMOVED',
                    charset='utf8mb4',
                    autocommit=False,
                    connection_timeout=10,
                    **method
                )
                connected = True
                if debug:
                    print(f"✓ Connected using: {method}")
                break
            except Error:
                continue
        
        if not connected:
            logger.error("Failed to connect to database")
            return False
        
        cursor = connection.cursor(dictionary=True)
        
        if verbose or debug:
            print(f"\n{'='*70}")
            print(f"Player Stats Database Update - {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
            print(f"{'='*70}")
            print(f"Processing {len(games)} games from NBA API...")
            print()
        
        inserted_count = 0
        updated_count = 0
        skipped_games = 0
        error_count = 0
        
        for game in games:
            try:
                game_id = game['gameId']
                game_status = game['gameStatus']
                
                home_team = normalize_team_name(
                    game['homeTeam']['teamCity'],
                    game['homeTeam']['teamName']
                )
                away_team = normalize_team_name(
                    game['awayTeam']['teamCity'],
                    game['awayTeam']['teamName']
                )
                
                # Only process games that have started (status 2) or finished (status 3)
                if game_status not in [2, 3]:
                    skipped_games += 1
                    if debug:
                        print(f"⊘ Skipped (not started): {away_team} @ {home_team}")
                    continue
                
                if verbose or debug:
                    status_text = "In Progress" if game_status == 2 else "Final"
                    print(f"\nProcessing: {away_team} @ {home_team} ({status_text})")
                
                home_team_id = str(game['homeTeam']['teamId'])
                away_team_id = str(game['awayTeam']['teamId'])
                
                # FIXED: Extract game date from the API data itself
                # gameCode format: "20251004/NYKPHI" - first 8 chars are YYYYMMDD
                game_code = game.get('gameCode', '')
                if game_code and '/' in game_code:
                    date_str = game_code.split('/')[0]
                    # Convert YYYYMMDD to YYYY-MM-DD
                    game_date = f"{date_str[0:4]}-{date_str[4:6]}-{date_str[6:8]}"
                else:
                    # Fallback: use gameTimeUTC
                    game_time_utc = game.get('gameTimeUTC', '')
                    if game_time_utc:
                        # Parse ISO format datetime
                        game_date = datetime.fromisoformat(game_time_utc.replace('Z', '+00:00')).strftime('%Y-%m-%d')
                    else:
                        if debug:
                            print(f"  ⚠ Could not determine game date: {away_team} @ {home_team}")
                        continue
                
                if verbose or debug:
                    print(f"  Using game date: {game_date}")
                
                # Fetch box score for this game
                try:
                    box = boxscore.BoxScore(game_id=game_id)
                    box_data = box.game.get_dict()
                except Exception as e:
                    error_count += 1
                    logger.error(f"Error fetching boxscore for game {game_id}: {str(e)}")
                    if verbose or debug:
                        print(f"  ✗ Error fetching boxscore: {str(e)}")
                    continue
                
                # Process home team players
                home_players = box_data.get('homeTeam', {}).get('players', [])
                
                for player_data in home_players:
                    try:
                        player_name = player_data.get('name', '')
                        stats = player_data.get('statistics', {})
                        
                        minutes = format_minutes(stats.get('minutes', ''))
                        
                        # Skip players who didn't play
                        if not minutes or minutes == "0:00":
                            continue
                        
                        points = int(stats.get('points', 0))
                        rebounds = int(stats.get('reboundsTotal', 0))
                        assists = int(stats.get('assists', 0))
                        fg_made = int(stats.get('fieldGoalsMade', 0))
                        fg_attempts = int(stats.get('fieldGoalsAttempted', 0))
                        
                        # Check if player record already exists
                        check_query = """
                            SELECT id FROM game_player_stats
                            WHERE game_id = %s AND player_name = %s AND game_date = %s
                        """
                        cursor.execute(check_query, (game_id, player_name, game_date))
                        existing = cursor.fetchone()
                        
                        if existing:
                            # Update existing record
                            update_query = """
                                UPDATE game_player_stats
                                SET team_id = %s, team_name = %s, minutes = %s, 
                                    points = %s, rebounds = %s, assists = %s,
                                    fg_made = %s, fg_attempts = %s
                                WHERE id = %s
                            """
                            cursor.execute(update_query, (
                                home_team_id, home_team, minutes,
                                points, rebounds, assists,
                                fg_made, fg_attempts,
                                existing['id']
                            ))
                            updated_count += 1
                            
                            if debug:
                                print(f"  ↻ Updated: {player_name} - {points}pts, {rebounds}reb, {assists}ast")
                        else:
                            # Insert new record
                            insert_query = """
                                INSERT INTO game_player_stats
                                (game_id, game_date, team_id, team_name, player_name, 
                                 minutes, points, rebounds, assists, fg_made, fg_attempts)
                                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                            """
                            cursor.execute(insert_query, (
                                game_id, game_date, home_team_id, home_team, player_name,
                                minutes, points, rebounds, assists, fg_made, fg_attempts
                            ))
                            inserted_count += 1
                            
                            if verbose or debug:
                                print(f"  ✓ Inserted: {player_name} - {points}pts, {rebounds}reb, {assists}ast")
                    
                    except Exception as e:
                        error_count += 1
                        logger.error(f"Error processing player {player_data.get('name', 'Unknown')}: {str(e)}")
                        if debug:
                            print(f"  ✗ Error with player {player_data.get('name', 'Unknown')}: {str(e)}")
                        continue
                
                # Process away team players
                away_players = box_data.get('awayTeam', {}).get('players', [])
                
                for player_data in away_players:
                    try:
                        player_name = player_data.get('name', '')
                        stats = player_data.get('statistics', {})
                        
                        minutes = format_minutes(stats.get('minutes', ''))
                        
                        # Skip players who didn't play
                        if not minutes or minutes == "0:00":
                            continue
                        
                        points = int(stats.get('points', 0))
                        rebounds = int(stats.get('reboundsTotal', 0))
                        assists = int(stats.get('assists', 0))
                        fg_made = int(stats.get('fieldGoalsMade', 0))
                        fg_attempts = int(stats.get('fieldGoalsAttempted', 0))
                        
                        # Check if player record already exists
                        check_query = """
                            SELECT id FROM game_player_stats
                            WHERE game_id = %s AND player_name = %s AND game_date = %s
                        """
                        cursor.execute(check_query, (game_id, player_name, game_date))
                        existing = cursor.fetchone()
                        
                        if existing:
                            # Update existing record
                            update_query = """
                                UPDATE game_player_stats
                                SET team_id = %s, team_name = %s, minutes = %s, 
                                    points = %s, rebounds = %s, assists = %s,
                                    fg_made = %s, fg_attempts = %s
                                WHERE id = %s
                            """
                            cursor.execute(update_query, (
                                away_team_id, away_team, minutes,
                                points, rebounds, assists,
                                fg_made, fg_attempts,
                                existing['id']
                            ))
                            updated_count += 1
                            
                            if debug:
                                print(f"  ↻ Updated: {player_name} - {points}pts, {rebounds}reb, {assists}ast")
                        else:
                            # Insert new record
                            insert_query = """
                                INSERT INTO game_player_stats
                                (game_id, game_date, team_id, team_name, player_name, 
                                 minutes, points, rebounds, assists, fg_made, fg_attempts)
                                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                            """
                            cursor.execute(insert_query, (
                                game_id, game_date, away_team_id, away_team, player_name,
                                minutes, points, rebounds, assists, fg_made, fg_attempts
                            ))
                            inserted_count += 1
                            
                            if verbose or debug:
                                print(f"  ✓ Inserted: {player_name} - {points}pts, {rebounds}reb, {assists}ast")
                    
                    except Exception as e:
                        error_count += 1
                        logger.error(f"Error processing player {player_data.get('name', 'Unknown')}: {str(e)}")
                        if debug:
                            print(f"  ✗ Error with player {player_data.get('name', 'Unknown')}: {str(e)}")
                        continue
                
                # Small delay between games to be nice to the API
                time.sleep(0.5)
                
            except Exception as e:
                error_count += 1
                logger.error(f"Error processing game: {str(e)}")
                if verbose or debug:
                    print(f"✗ Error processing game: {str(e)}")
                continue
        
        # Commit all changes
        connection.commit()
        
        if verbose or debug:
            print()
            print(f"{'='*70}")
            print(f"Summary:")
            print(f"  Total games from API: {len(games)}")
            print(f"  Games processed: {len(games) - skipped_games}")
            print(f"  Games skipped (not started): {skipped_games}")
            print(f"  Player records inserted: {inserted_count}")
            print(f"  Player records updated: {updated_count}")
            print(f"  Errors: {error_count}")
            print(f"  Timestamp: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
            print(f"{'='*70}\n")
        
        logger.info(f"Player stats saved: {inserted_count} inserted, {updated_count} updated, {error_count} errors")
        return True
        
    except Exception as e:
        logger.error(f"Database error: {str(e)}")
        if connection:
            connection.rollback()
        return False
        
    finally:
        if cursor:
            cursor.close()
        if connection and connection.is_connected():
            connection.close()

def main():
    """Main function"""
    parser = argparse.ArgumentParser(
        description='Save player statistics from NBA Live API to database',
        formatter_class=argparse.RawDescriptionHelpFormatter
    )
    parser.add_argument('--verbose', action='store_true',
                       help='Verbose output showing all operations')
    parser.add_argument('--debug', action='store_true',
                       help='Debug mode with detailed info')
    
    args = parser.parse_args()
    
    if args.debug:
        logger.setLevel(logging.DEBUG)
    elif args.verbose:
        logger.setLevel(logging.INFO)
    
    # Fetch games
    games_dict = get_games_from_api()
    
    if not games_dict or not games_dict.get('scoreboard', {}).get('games'):
        logger.error("Failed to fetch games from API")
        sys.exit(1)
    
    # Extract games list from the structure
    games = games_dict.get('scoreboard', {}).get('games', [])
    
    # Save to database
    success = save_player_stats_to_db(games, verbose=args.verbose, debug=args.debug)
    sys.exit(0 if success else 1)

if __name__ == '__main__':
    main()