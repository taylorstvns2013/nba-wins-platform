#!/usr/bin/env python3
"""
save_quarter_scores.py - Store Quarter-by-Quarter Scores to Database
Location: /data/www/default/nba-wins-platform/tasks/

Fetches current games from NBA API and saves quarter-by-quarter scores
to the game_quarter_scores table for historical preservation.

Features:
- Extracts period-by-period scoring data
- Handles overtime periods
- Prevents duplicate entries
- Updates existing records if scores change
- Comprehensive error handling

Usage:
    python3 save_quarter_scores.py                # Standard run
    python3 save_quarter_scores.py --verbose      # Detailed output
    python3 save_quarter_scores.py --debug        # Full debug info
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
import sys
sys.path.insert(0, '/data/www/default/nba-wins-platform/config')
from db_secrets import DB_PASS as DB_PASSWORD, DB_USER, DB_NAME

# Configure logging
log_handlers = [logging.StreamHandler()]

try:
    log_file = '/tmp/nba_save_quarter_scores.log'
    log_handlers.append(logging.FileHandler(log_file, mode='a'))
except PermissionError:
    try:
        home_dir = os.path.expanduser('~')
        log_file = os.path.join(home_dir, 'nba_save_quarter_scores.log')
        log_handlers.append(logging.FileHandler(log_file, mode='a'))
    except:
        pass

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=log_handlers
)
logger = logging.getLogger(__name__)

# Team name mapping for consistency with games table
# NBA API gives "Los Angeles Clippers" but games table uses "LA Clippers"
# So we normalize TO the games table format
TEAM_NAME_MAP = {
    'Los Angeles Clippers': 'LA Clippers',
    # Lakers stay as 'Los Angeles Lakers' (matches games table)
}

def normalize_team_name(team_city, team_name):
    """Normalize team names to match games table format (LA not Los Angeles)"""
    full_name = f"{team_city} {team_name}"
    return TEAM_NAME_MAP.get(full_name, full_name)

def get_games_from_api(max_retries=3, retry_delay=2):
    """Fetch current games from NBA API with retry logic"""
    for attempt in range(max_retries):
        try:
            logger.info(f"Fetching games from NBA API (attempt {attempt + 1}/{max_retries})...")
            board = scoreboard.ScoreBoard()
            games_dict = board.get_dict()
            
            logger.info(f"Successfully fetched {len(games_dict['scoreboard']['games'])} games")
            return games_dict
            
        except Exception as e:
            logger.error(f"Error fetching games (attempt {attempt + 1}/{max_retries}): {str(e)}")
            if attempt < max_retries - 1:
                time.sleep(retry_delay * (attempt + 1))
            else:
                logger.error("All retry attempts failed")
                return None

def save_quarter_scores_to_db(games_dict, verbose=False, debug=False):
    """
    Save quarter-by-quarter scores to database
    
    Args:
        games_dict: Raw games data from NBA API
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
        # Database connection with multiple methods (matching get_games.py)
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
                    password=DB_PASSWORD,
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
        
        # Get current date in EST
        est_tz = pytz.timezone('America/New_York')
        current_date_est = datetime.now(est_tz).date()
        
        games = games_dict.get('scoreboard', {}).get('games', [])
        
        if verbose or debug:
            print(f"\n{'='*70}")
            print(f"Quarter Scores Database Update - {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
            print(f"{'='*70}")
            print(f"Processing {len(games)} games from NBA API...")
            print()
        
        inserted_count = 0
        updated_count = 0
        skipped_count = 0
        error_count = 0
        
        for game in games:
            try:
                home_team = normalize_team_name(
                    game['homeTeam']['teamCity'],
                    game['homeTeam']['teamName']
                )
                away_team = normalize_team_name(
                    game['awayTeam']['teamCity'],
                    game['awayTeam']['teamName']
                )
                
                home_tricode = game['homeTeam']['teamTricode']
                away_tricode = game['awayTeam']['teamTricode']
                
                # Extract periods data
                home_periods = game['homeTeam'].get('periods', [])
                away_periods = game['awayTeam'].get('periods', [])
                
                # Skip if no period data available
                if not home_periods or not away_periods:
                    skipped_count += 1
                    if debug:
                        print(f"⊘ Skipped (no period data): {home_team} vs {away_team}")
                    continue
                
                # Find game_id from games table
                # Try today's date first, then yesterday (handles late games crossing midnight EST)
                find_game_query = """
                    SELECT id FROM games 
                    WHERE home_team = %s 
                    AND away_team = %s 
                    AND date = %s
                    LIMIT 1
                """
                game_date_used = current_date_est
                cursor.execute(find_game_query, (home_team, away_team, current_date_est))
                game_record = cursor.fetchone()
                
                if not game_record:
                    # Try yesterday — handles late games still playing after midnight EST
                    yesterday_est = current_date_est - timedelta(days=1)
                    cursor.execute(find_game_query, (home_team, away_team, yesterday_est))
                    game_record = cursor.fetchone()
                    if game_record:
                        game_date_used = yesterday_est
                        if debug:
                            print(f"↩ Found game on previous date ({yesterday_est}): {home_team} vs {away_team}")
                
                if not game_record:
                    if debug:
                        print(f"⚠ Game not found in DB: {home_team} vs {away_team}")
                    continue
                
                game_id = game_record['id']
                
                # Process HOME team quarters
                home_q1 = home_q2 = home_q3 = home_q4 = None
                for period in home_periods:
                    if period['periodType'] == 'REGULAR':
                        period_num = period['period']
                        score = period['score']
                        
                        if period_num == 1:
                            home_q1 = score
                        elif period_num == 2:
                            home_q2 = score
                        elif period_num == 3:
                            home_q3 = score
                        elif period_num == 4:
                            home_q4 = score
                
                home_total = game['homeTeam']['score']
                
                # Process AWAY team quarters
                away_q1 = away_q2 = away_q3 = away_q4 = None
                for period in away_periods:
                    if period['periodType'] == 'REGULAR':
                        period_num = period['period']
                        score = period['score']
                        
                        if period_num == 1:
                            away_q1 = score
                        elif period_num == 2:
                            away_q2 = score
                        elif period_num == 3:
                            away_q3 = score
                        elif period_num == 4:
                            away_q4 = score
                
                away_total = game['awayTeam']['score']
                
                # Check if records already exist
                # Use game_date_used (may be yesterday for late games crossing midnight)
                check_query = """
                    SELECT id, team_abbrev, q1_points, q2_points, q3_points, q4_points, total_points
                    FROM game_quarter_scores
                    WHERE game_date = %s AND team_abbrev IN (%s, %s)
                """
                cursor.execute(check_query, (game_date_used, home_tricode, away_tricode))
                existing_records = cursor.fetchall()
                
                # Create a map of existing records
                existing_map = {rec['team_abbrev']: rec for rec in existing_records}
                
                # Process HOME team record
                home_needs_update = False
                home_record_id = None
                
                if home_tricode in existing_map:
                    # Check if update needed
                    existing = existing_map[home_tricode]
                    home_record_id = existing['id']
                    
                    if (existing['q1_points'] != home_q1 or
                        existing['q2_points'] != home_q2 or
                        existing['q3_points'] != home_q3 or
                        existing['q4_points'] != home_q4 or
                        existing['total_points'] != home_total):
                        home_needs_update = True
                
                if home_tricode not in existing_map:
                    # Insert new record
                    insert_query = """
                        INSERT INTO game_quarter_scores 
                        (game_id, game_date, team_abbrev, q1_points, q2_points, q3_points, q4_points, total_points)
                        VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
                    """
                    cursor.execute(insert_query, (
                        game_id, game_date_used, home_tricode,
                        home_q1, home_q2, home_q3, home_q4, home_total
                    ))
                    inserted_count += 1
                    
                    if verbose or debug:
                        print(f"✓ Inserted: {home_tricode} - Q1:{home_q1} Q2:{home_q2} Q3:{home_q3} Q4:{home_q4} Total:{home_total}")
                
                elif home_needs_update:
                    # Update existing record
                    update_query = """
                        UPDATE game_quarter_scores
                        SET q1_points = %s, q2_points = %s, q3_points = %s, q4_points = %s, total_points = %s
                        WHERE id = %s
                    """
                    cursor.execute(update_query, (
                        home_q1, home_q2, home_q3, home_q4, home_total, home_record_id
                    ))
                    updated_count += 1
                    
                    if verbose or debug:
                        print(f"↻ Updated: {home_tricode} - Q1:{home_q1} Q2:{home_q2} Q3:{home_q3} Q4:{home_q4} Total:{home_total}")
                
                # Process AWAY team record
                away_needs_update = False
                away_record_id = None
                
                if away_tricode in existing_map:
                    existing = existing_map[away_tricode]
                    away_record_id = existing['id']
                    
                    if (existing['q1_points'] != away_q1 or
                        existing['q2_points'] != away_q2 or
                        existing['q3_points'] != away_q3 or
                        existing['q4_points'] != away_q4 or
                        existing['total_points'] != away_total):
                        away_needs_update = True
                
                if away_tricode not in existing_map:
                    # Insert new record
                    insert_query = """
                        INSERT INTO game_quarter_scores 
                        (game_id, game_date, team_abbrev, q1_points, q2_points, q3_points, q4_points, total_points)
                        VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
                    """
                    cursor.execute(insert_query, (
                        game_id, game_date_used, away_tricode,
                        away_q1, away_q2, away_q3, away_q4, away_total
                    ))
                    inserted_count += 1
                    
                    if verbose or debug:
                        print(f"✓ Inserted: {away_tricode} - Q1:{away_q1} Q2:{away_q2} Q3:{away_q3} Q4:{away_q4} Total:{away_total}")
                
                elif away_needs_update:
                    # Update existing record
                    update_query = """
                        UPDATE game_quarter_scores
                        SET q1_points = %s, q2_points = %s, q3_points = %s, q4_points = %s, total_points = %s
                        WHERE id = %s
                    """
                    cursor.execute(update_query, (
                        away_q1, away_q2, away_q3, away_q4, away_total, away_record_id
                    ))
                    updated_count += 1
                    
                    if verbose or debug:
                        print(f"↻ Updated: {away_tricode} - Q1:{away_q1} Q2:{away_q2} Q3:{away_q3} Q4:{away_q4} Total:{away_total}")
                
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
            print(f"  Inserted: {inserted_count}")
            print(f"  Updated: {updated_count}")
            print(f"  Skipped: {skipped_count}")
            print(f"  Errors: {error_count}")
            print(f"  Timestamp: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
            print(f"{'='*70}\n")
        
        logger.info(f"Quarter scores saved: {inserted_count} inserted, {updated_count} updated, {error_count} errors")
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
        description='Save quarter-by-quarter scores from NBA API to database',
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
    
    if not games_dict:
        logger.error("Failed to fetch games from API")
        sys.exit(1)
    
    # Save to database
    success = save_quarter_scores_to_db(games_dict, verbose=args.verbose, debug=args.debug)
    sys.exit(0 if success else 1)

if __name__ == '__main__':
    main()