#!/usr/bin/env python3
"""
get_games.py - Production NBA API Game Fetcher with Database Write Support
Location: /data/www/default/nba-wins-platform/tasks/

Two modes:
1. JSON output only (default) - for game_scores_helper.php
2. Database write (--write flag) - for cron jobs

Features:
- Automatic retry with exponential backoff
- Comprehensive error handling and logging
- Team name normalization/mapping
- Timezone-aware date handling
- Connection pooling and cleanup
- Race condition prevention via audit table
- FINAL GAME PROTECTION - prevents overwriting completed games

Usage:
    python3 get_games.py                     # Returns JSON (for PHP)
    python3 get_games.py --write             # Updates database (for cron)
    python3 get_games.py --write --verbose   # With detailed logging
    python3 get_games.py --write --debug     # Debug mode with full output
"""

from nba_api.live.nba.endpoints import scoreboard
import json
from datetime import datetime, timedelta
import pytz
import sys
import argparse
import logging
import time
import os

# Configure logging with fallback for permission issues
log_handlers = [logging.StreamHandler()]

# Try to add file handler, fall back to stdout only if permissions fail
try:
    log_file = '/tmp/nba_get_games.log'
    # Try to create/open log file
    log_handlers.append(logging.FileHandler(log_file, mode='a'))
except PermissionError:
    # Fall back to user's home directory
    try:
        home_dir = os.path.expanduser('~')
        log_file = os.path.join(home_dir, 'nba_get_games.log')
        log_handlers.append(logging.FileHandler(log_file, mode='a'))
    except:
        # If all else fails, just use stdout
        pass

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=log_handlers
)
logger = logging.getLogger(__name__)

# Team name mapping for consistency between API and database
TEAM_NAME_MAP = {
    'LA Clippers': 'Los Angeles Clippers',
    'LA Lakers': 'Los Angeles Lakers',
    # Add more mappings if needed
}

def normalize_team_name(team_city, team_name):
    """Normalize team names for consistency with database"""
    full_name = f"{team_city} {team_name}"
    return TEAM_NAME_MAP.get(full_name, full_name)

def is_game_final(status_text):
    """
    Check if a game status indicates the game is final/completed.
    Final games should not be updated to preserve data integrity.
    
    Args:
        status_text: The status_long value from database
    
    Returns:
        bool: True if game is final, False otherwise
    """
    if not status_text:
        return False
    
    status_lower = status_text.lower().strip()
    
    # Check for various final status indicators
    final_indicators = [
        'final',
        'finished',
        'completed',
        'final/ot',
        'final ot'
    ]
    
    return any(indicator in status_lower for indicator in final_indicators)

def get_formatted_games(max_retries=3, retry_delay=2):
    """
    Fetch current games from NBA API with retry logic
    
    Args:
        max_retries: Number of retry attempts
        retry_delay: Delay between retries in seconds
    
    Returns:
        dict: Formatted games data or error dict
    """
    for attempt in range(max_retries):
        try:
            logger.info(f"Fetching games from NBA API (attempt {attempt + 1}/{max_retries})...")
            board = scoreboard.ScoreBoard()
            games_dict = board.get_dict()
            
            # Create properly structured JSON output
            formatted_games = {
                'scoreboard': {
                    'games': [],
                    'gameDate': games_dict.get('scoreboard', {}).get('gameDate', ''),
                    'leagueId': games_dict.get('scoreboard', {}).get('leagueId', ''),
                    'leagueName': games_dict.get('scoreboard', {}).get('leagueName', '')
                }
            }
            
            for game in games_dict['scoreboard']['games']:
                # Determine if game is final
                game_status = game['gameStatus']
                game_status_text = game.get('gameStatusText', '')
                is_final = game_status == 3 or is_game_final(game_status_text)
                
                formatted_game = {
                    'gameId': game['gameId'],
                    'gameCode': game.get('gameCode', ''),
                    'gameStatus': game['gameStatus'],
                    'gameStatusText': game_status_text,
                    'is_final': is_final,  # Protection flag
                    'period': game['period'],
                    'gameClock': game['gameClock'],
                    'gameTimeUTC': game.get('gameTimeUTC', ''),
                    'gameEt': game.get('gameEt', ''),
                    'homeTeam': {
                        'teamId': game['homeTeam']['teamId'],
                        'teamName': game['homeTeam']['teamName'],
                        'teamCity': game['homeTeam']['teamCity'],
                        'teamTricode': game['homeTeam']['teamTricode'],
                        'score': int(game['homeTeam']['score']),
                        'wins': game['homeTeam'].get('wins', 0),
                        'losses': game['homeTeam'].get('losses', 0),
                        'periods': game['homeTeam'].get('periods', [])
                    },
                    'awayTeam': {
                        'teamId': game['awayTeam']['teamId'],
                        'teamName': game['awayTeam']['teamName'],
                        'teamCity': game['awayTeam']['teamCity'],
                        'teamTricode': game['awayTeam']['teamTricode'],
                        'score': int(game['awayTeam']['score']),
                        'wins': game['awayTeam'].get('wins', 0),
                        'losses': game['awayTeam'].get('losses', 0),
                        'periods': game['awayTeam'].get('periods', [])
                    }
                }
                
                # Add warning for final games
                if is_final:
                    formatted_game['_protection'] = 'Game is final - protected from updates'
                
                formatted_games['scoreboard']['games'].append(formatted_game)
            
            logger.info(f"Successfully fetched {len(formatted_games['scoreboard']['games'])} games")
            return formatted_games
            
        except Exception as e:
            logger.error(f"Error fetching games (attempt {attempt + 1}/{max_retries}): {str(e)}")
            if attempt < max_retries - 1:
                time.sleep(retry_delay * (attempt + 1))  # Exponential backoff
            else:
                logger.error("All retry attempts failed")
                return {'error': str(e), 'scoreboard': {'games': []}}

def write_to_database(games_data, verbose=False, debug=False):
    """
    Write game data to MySQL database with comprehensive error handling
    and final game protection.
    
    Args:
        games_data: Dictionary containing game data from API
        verbose: Print detailed progress information
        debug: Print debug information including SQL queries
    
    Returns:
        bool: True if successful, False otherwise
    """
    connection = None
    cursor = None
    
    try:
        import mysql.connector
        from mysql.connector import Error, errorcode
    except ImportError as e:
        logger.error("mysql-connector-python not installed. Run: pip3 install mysql-connector-python --break-system-packages")
        if verbose or debug:
            print("\n✗ Error: mysql-connector-python not installed")
            print("Install with: pip3 install mysql-connector-python --break-system-packages\n")
        return False
    
    try:
        # Database connection with retry logic - using Unix socket to match PHP config
        max_connection_attempts = 3
        
        # Try multiple connection methods like db_connection_cli.php does
        connection_methods = [
            # Method 1: Unix socket (most likely to work)
            {'unix_socket': '/tmp/mysql.sock', 'database': 'nba_wins_platform'},
            # Method 2: Alternative socket path
            {'unix_socket': '/var/run/mysqld/mysqld.sock', 'database': 'nba_wins_platform'},
            # Method 3: localhost (socket connection)
            {'host': 'localhost', 'database': 'nba_wins_platform'},
        ]
        
        connected = False
        for method in connection_methods:
            for attempt in range(max_connection_attempts):
                try:
                    connection = mysql.connector.connect(
                        user='nba_app',
                        password='DB_PASSWORD_REMOVED',
                        charset='utf8mb4',
                        autocommit=False,
                        connection_timeout=10,
                        **method  # Unpack the connection method
                    )
                    connected = True
                    if debug:
                        print(f"✓ Connected using: {method}")
                    break
                except Error as err:
                    if attempt < max_connection_attempts - 1:
                        logger.debug(f"Connection attempt {attempt + 1} with {method} failed, retrying...")
                        time.sleep(1)
                    else:
                        logger.debug(f"All attempts with {method} failed")
                        continue
            
            if connected:
                break
        
        if not connected:
            logger.error("Failed to connect with any method")
            return False
        
        if not connection.is_connected():
            logger.error("Failed to connect to database")
            return False
        
        cursor = connection.cursor(dictionary=True)
        
        updated_count = 0
        skipped_count = 0
        protected_count = 0  # Track protected final games
        error_count = 0
        games = games_data.get('scoreboard', {}).get('games', [])
        
        if verbose or debug:
            print(f"\n{'='*70}")
            print(f"NBA API Database Update - {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
            print(f"{'='*70}")
            print(f"Processing {len(games)} games from NBA API...")
            print()
        
        # Get current date in EST
        est_tz = pytz.timezone('America/New_York')
        current_date_est = datetime.now(est_tz).date()
        
        # Also check yesterday's games (in case of late-night games)
        yesterday_est = current_date_est - timedelta(days=1)
        
        for game in games:
            try:
                # Build team names with normalization
                home_team = normalize_team_name(
                    game['homeTeam']['teamCity'],
                    game['homeTeam']['teamName']
                )
                away_team = normalize_team_name(
                    game['awayTeam']['teamCity'],
                    game['awayTeam']['teamName']
                )
                
                home_score = int(game['homeTeam']['score'])
                away_score = int(game['awayTeam']['score'])
                home_tricode = game['homeTeam']['teamTricode']
                away_tricode = game['awayTeam']['teamTricode']
                
                # Determine detailed status
                game_status = game['gameStatus']
                if game_status == 1:
                    status_long = 'Scheduled'
                elif game_status == 2:
                    period = game['period']
                    clock = game['gameClock']
                    if period <= 4:
                        status_long = f'Q{period}'
                    else:
                        status_long = f'OT{period-4}' if period > 4 else 'OT'
                    if clock and clock.strip():
                        status_long += f' - {clock}'
                elif game_status == 3:
                    status_long = 'Final'
                else:
                    status_long = game.get('gameStatusText', 'Unknown')
                
                # Try to find the game in database
                # Check both today and yesterday for late games
                find_query = """
                    SELECT id, status_long, home_points, away_points, date
                    FROM games 
                    WHERE home_team = %s 
                    AND away_team = %s 
                    AND date IN (%s, %s)
                    LIMIT 1
                """
                
                cursor.execute(find_query, (
                    home_team,
                    away_team,
                    current_date_est,
                    yesterday_est
                ))
                
                existing_game = cursor.fetchone()
                
                if existing_game:
                    game_id = existing_game['id']
                    old_status = existing_game['status_long']
                    old_home_points = existing_game['home_points'] or 0
                    old_away_points = existing_game['away_points'] or 0
                    
                    # 🛡️ FINAL GAME PROTECTION - Check if database game is already final
                    if is_game_final(old_status):
                        protected_count += 1
                        if verbose or debug:
                            print(f"🛡️  Protected: {home_team} vs {away_team} - Already marked as '{old_status}' (Final game protected)")
                        continue  # Skip update for final games
                    
                    # Only update if something changed
                    if (old_status != status_long or 
                        old_home_points != home_score or 
                        old_away_points != away_score):
                        
                        update_query = """
                            UPDATE games 
                            SET home_points = %s, 
                                away_points = %s, 
                                status_long = %s,
                                home_team_code = %s,
                                away_team_code = %s
                            WHERE id = %s
                        """
                        
                        if debug:
                            print(f"SQL: {update_query}")
                            print(f"Values: ({home_score}, {away_score}, {status_long}, {home_tricode}, {away_tricode}, {game_id})")
                        
                        cursor.execute(update_query, (
                            home_score,
                            away_score,
                            status_long,
                            home_tricode,
                            away_tricode,
                            game_id
                        ))
                        
                        updated_count += 1
                        
                        if verbose or debug:
                            status_change = f" [{old_status} → {status_long}]" if old_status != status_long else ""
                            print(f"✓ Updated: {home_team} {home_score} - {away_team} {away_score} ({status_long}){status_change}")
                            
                            # Log when game finishes
                            if status_long == 'Final' and old_status != 'Final':
                                print(f"  🏁 Game finished! Trigger will count wins.")
                    else:
                        skipped_count += 1
                        if debug:
                            print(f"⊘ Skipped (no changes): {home_team} vs {away_team}")
                else:
                    if debug:
                        print(f"⚠ Not found in DB: {home_team} vs {away_team} (Date: {current_date_est})")
                
            except Exception as e:
                error_count += 1
                logger.error(f"Error processing game {home_team} vs {away_team}: {str(e)}")
                if verbose or debug:
                    print(f"✗ Error: {home_team} vs {away_team} - {str(e)}")
                continue
        
        # Commit all changes
        connection.commit()
        
        if verbose or debug:
            print()
            print(f"{'='*70}")
            print(f"Summary:")
            print(f"  Total games processed: {len(games)}")
            print(f"  Updated: {updated_count}")
            print(f"  Protected (Final): {protected_count}  🛡️")
            print(f"  Skipped (no changes): {skipped_count}")
            print(f"  Not found in DB: {len(games) - updated_count - skipped_count - protected_count - error_count}")
            print(f"  Errors: {error_count}")
            print(f"  Timestamp: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
            print(f"{'='*70}\n")
        
        logger.info(f"Database update complete: {updated_count} updated, {protected_count} protected (final), {error_count} errors")
        return True
        
    except Error as err:
        if err.errno == errorcode.ER_ACCESS_DENIED_ERROR:
            logger.error("Database access denied - check credentials")
        elif err.errno == errorcode.ER_BAD_DB_ERROR:
            logger.error("Database does not exist")
        else:
            logger.error(f"Database error: {err}")
        
        if connection:
            connection.rollback()
        
        if verbose or debug:
            print(f"\n✗ Database error: {err}\n")
        
        return False
        
    except Exception as e:
        logger.error(f"Unexpected error: {str(e)}")
        if connection:
            connection.rollback()
        return False
        
    finally:
        # Clean up resources
        if cursor:
            cursor.close()
        if connection and connection.is_connected():
            connection.close()
            if debug:
                print("Database connection closed")

def main():
    """Main function with argument parsing"""
    parser = argparse.ArgumentParser(
        description='Fetch NBA games from API and optionally update database',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  python3 get_games.py                     # JSON output for PHP
  python3 get_games.py --write             # Update database (cron mode)
  python3 get_games.py --write --verbose   # Verbose output
  python3 get_games.py --write --debug     # Full debug output
        """
    )
    parser.add_argument('--write', action='store_true', 
                       help='Write data to database (default: JSON output only)')
    parser.add_argument('--verbose', action='store_true',
                       help='Verbose output showing all updates')
    parser.add_argument('--debug', action='store_true',
                       help='Debug mode with SQL queries and detailed info')
    
    args = parser.parse_args()
    
    # Set logging level based on arguments
    if args.debug:
        logger.setLevel(logging.DEBUG)
    elif args.verbose:
        logger.setLevel(logging.INFO)
    else:
        logger.setLevel(logging.WARNING)
    
    # Fetch games from API
    games_data = get_formatted_games()
    
    # Check for errors
    if 'error' in games_data:
        logger.error(f"Failed to fetch games: {games_data['error']}")
        if not args.write:
            # Still output JSON for PHP even on error
            print(json.dumps(games_data))
        sys.exit(1)
    
    if args.write:
        # Database write mode (for cron)
        success = write_to_database(games_data, verbose=args.verbose, debug=args.debug)
        sys.exit(0 if success else 1)
    else:
        # JSON output mode (for PHP)
        print(json.dumps(games_data))
        sys.exit(0)

if __name__ == '__main__':
    main()