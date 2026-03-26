#!/usr/bin/env python3
# upload_season_data.py - Upload NBA season data, boxscores, and player statistics
# Location: /data/www/default/nba-wins-platform/tasks/

import pymysql
from nba_api.stats.endpoints import leaguegamefinder, boxscoretraditionalv3, boxscoresummaryv2
from datetime import datetime, timedelta
import pytz
import time
import logging
import requests
import json
from requests.exceptions import RequestException
from tenacity import retry, stop_after_attempt, wait_exponential
from season_config import get_season_config
season_cfg = get_season_config()

# Set up basic logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[logging.StreamHandler()]
)

# Database configuration - Fixed for socket connection
DB_CONFIG = {
    'unix_socket': '/tmp/mysql.sock',  # Use socket instead of host
    'database': 'nba_wins_platform',
    'user': 'nba_app',
    'password': DB_PASSWORD,
    'charset': 'utf8mb4'
}

def connect_to_database():
    try:
        connection = pymysql.connect(**DB_CONFIG)
        logging.info("Connected to database successfully via socket")
        return connection
    except Exception as e:
        logging.error(f"Database connection error: {e}")
        # Fallback to TCP if socket fails
        try:
            fallback_config = {
                'host': 'localhost',
                'database': 'nba_wins_platform',
                'user': 'nba_app', 
                'password': DB_PASSWORD,
                'charset': 'utf8mb4'
            }
            connection = pymysql.connect(**fallback_config)
            logging.info("Connected to database via TCP fallback")
            return connection
        except Exception as e2:
            logging.error(f"TCP fallback also failed: {e2}")
            return None

def log_to_update_table(connection, script_name, details):
    """Log script execution to the update_log table"""
    try:
        with connection.cursor() as cursor:
            update_time = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            cursor.execute(
                "INSERT INTO update_log (update_time, script_name, details) VALUES (%s, %s, %s)",
                (update_time, script_name, details)
            )
        connection.commit()
    except Exception as e:
        logging.error(f"Failed to log to update_log table: {e}")

def create_tables(connection):
    try:
        cursor = connection.cursor()
        
        # Create quarter scores table
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS game_quarter_scores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                game_id VARCHAR(20) NOT NULL,
                game_date DATE NOT NULL,
                team_abbrev VARCHAR(5) NOT NULL,
                q1_points INT DEFAULT 0,
                q2_points INT DEFAULT 0,
                q3_points INT DEFAULT 0,
                q4_points INT DEFAULT 0,
                total_points INT DEFAULT 0,
                last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_game_team (game_id, team_abbrev),
                INDEX idx_game_date (game_date),
                INDEX idx_game_id (game_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        """)
        
        # Create player stats table
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS game_player_stats (
                id INT AUTO_INCREMENT PRIMARY KEY,
                game_id VARCHAR(20) NOT NULL,
                game_date DATE NOT NULL,
                team_id VARCHAR(20),
                team_name VARCHAR(50),
                player_name VARCHAR(100) NOT NULL,
                minutes VARCHAR(20),
                points INT DEFAULT 0,
                rebounds INT DEFAULT 0,
                assists INT DEFAULT 0,
                fg_made INT DEFAULT 0,
                fg_attempts INT DEFAULT 0,
                last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_game_player (game_id, player_name),
                INDEX idx_game_date (game_date),
                INDEX idx_game_id (game_id),
                INDEX idx_player_name (player_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        """)
        
        # Create inactive players table
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS game_inactive_players (
                id INT AUTO_INCREMENT PRIMARY KEY,
                game_id VARCHAR(20) NOT NULL,
                game_date DATE NOT NULL,
                team_city VARCHAR(50),
                player_name VARCHAR(100) NOT NULL,
                last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_game_player (game_id, player_name),
                INDEX idx_game_date (game_date),
                INDEX idx_game_id (game_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        """)
        
        connection.commit()
        logging.info("All required tables created/verified successfully")
        
    except Exception as e:
        logging.error(f"Error creating tables: {e}")
        raise

def check_update_times(connection, game_id):
    """Check synchronization of updates across tables"""
    try:
        cursor = connection.cursor()
        cursor.execute("""
            SELECT 
                'Quarter Scores' as table_name, MAX(last_updated) as last_update
                FROM game_quarter_scores WHERE game_id = %s
            UNION ALL
            SELECT 
                'Player Stats', MAX(last_updated)
                FROM game_player_stats WHERE game_id = %s
            UNION ALL
            SELECT 
                'Inactive Players', MAX(last_updated)
                FROM game_inactive_players WHERE game_id = %s
        """, (game_id, game_id, game_id))
        
        results = cursor.fetchall()
        times = [row[1] for row in results if row[1] is not None]
        if times:
            time_diff = max(times) - min(times)
            if time_diff.total_seconds() > 60:
                logging.warning(f"Updates not synchronized for game {game_id}")
                
    except Exception as e:
        logging.error(f"Update time check error: {e}")

def insert_quarter_scores(connection, game_id, game_date, scores):
    try:
        cursor = connection.cursor()
        for team_score in scores:
            # Extract team abbreviation - handle both uppercase and lowercase keys
            team_abbrev = None
            for key in ['TEAM_ABBREVIATION', 'team_abbrev', 'TEAM_ABBRV', 'team_abbrv', 'TEAM_CODE', 'team_code']:
                if key in team_score:
                    team_abbrev = team_score[key]
                    break
            
            if not team_abbrev:
                logging.warning(f"Could not find team abbreviation in {team_score}")
                continue
            
            # Extract quarter points - handle both uppercase and lowercase keys
            q1 = None
            for key in ['PTS_QTR1', 'q1_points', 'PT1', 'Q1']:
                if key in team_score and team_score[key] is not None:
                    q1 = team_score[key]
                    break
            
            q2 = None
            for key in ['PTS_QTR2', 'q2_points', 'PT2', 'Q2']:
                if key in team_score and team_score[key] is not None:
                    q2 = team_score[key]
                    break
            
            q3 = None
            for key in ['PTS_QTR3', 'q3_points', 'PT3', 'Q3']:
                if key in team_score and team_score[key] is not None:
                    q3 = team_score[key]
                    break
            
            q4 = None
            for key in ['PTS_QTR4', 'q4_points', 'PT4', 'Q4']:
                if key in team_score and team_score[key] is not None:
                    q4 = team_score[key]
                    break
            
            total = None
            for key in ['PTS', 'total_points', 'TOTAL', 'total']:
                if key in team_score and team_score[key] is not None:
                    total = team_score[key]
                    break
            
            # Use defaults if values are missing
            q1 = q1 if q1 is not None else 0
            q2 = q2 if q2 is not None else 0
            q3 = q3 if q3 is not None else 0
            q4 = q4 if q4 is not None else 0
            
            # Calculate total if not provided
            if total is None:
                total = q1 + q2 + q3 + q4
            
            cursor.execute("""
                INSERT INTO game_quarter_scores 
                (game_id, game_date, team_abbrev, q1_points, q2_points, q3_points, q4_points, total_points)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE 
                q1_points = VALUES(q1_points),
                q2_points = VALUES(q2_points),
                q3_points = VALUES(q3_points),
                q4_points = VALUES(q4_points),
                total_points = VALUES(total_points),
                game_date = VALUES(game_date)
            """, (
                game_id,
                game_date,
                team_abbrev,
                q1,
                q2,
                q3,
                q4,
                total
            ))
        connection.commit()
        logging.info(f"Successfully inserted quarter scores for game {game_id}")
    except Exception as e:
        logging.error(f"Quarter scores error: {str(e)}")
        raise

def insert_player_stats(connection, game_id, game_date, player_stats, team_id, team_name):
    try:
        cursor = connection.cursor()
        for player in player_stats:
            cursor.execute("""
                INSERT INTO game_player_stats 
                (game_id, game_date, team_id, team_name, player_name, minutes, points, rebounds, assists, fg_made, fg_attempts)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE 
                minutes = VALUES(minutes),
                points = VALUES(points),
                rebounds = VALUES(rebounds),
                assists = VALUES(assists),
                fg_made = VALUES(fg_made),
                fg_attempts = VALUES(fg_attempts),
                team_name = VALUES(team_name),
                game_date = VALUES(game_date)
            """, (
                game_id,
                game_date,
                team_id,
                team_name,
                player['player_name'],
                player['minutes'],
                player['points'],
                player['rebounds'],
                player['assists'],
                player['fg_made'],
                player['fg_attempts']
            ))
        connection.commit()
    except Exception as e:
        logging.error(f"Player stats error: {str(e)}")
        raise

def insert_inactive_players(connection, game_id, game_date, inactive_players):
    try:
        cursor = connection.cursor()
        for player in inactive_players:
            player_name = f"{player['FIRST_NAME']} {player['LAST_NAME']}"
            cursor.execute("""
                INSERT INTO game_inactive_players 
                (game_id, game_date, team_city, player_name)
                VALUES (%s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE 
                team_city = VALUES(team_city),
                game_date = VALUES(game_date)
            """, (game_id, game_date, player['TEAM_CITY'], player_name))
        connection.commit()
    except Exception as e:
        logging.error(f"Inactive players error: {str(e)}")
        raise

def get_quarter_scores_from_rapidapi(game_id, game_date):
    """
    Fetch quarter-by-quarter scores from RapidAPI as a fallback
    when NBA API doesn't provide the data
    """
    try:
        # RapidAPI configuration
        api_key = RAPIDAPI_KEY
        api_host = "api-nba-v1.p.rapidapi.com"
        base_url = "https://api-nba-v1.p.rapidapi.com"
        
        # Setup headers for RapidAPI
        headers = {
            "X-RapidAPI-Key": api_key,
            "X-RapidAPI-Host": api_host
        }
        
        # Get game statistics directly using the game ID
        games_url = f"{base_url}/games"
        querystring = {"id": game_id}
        
        logging.info(f"Fetching game data from RapidAPI for game {game_id}")
        response = requests.get(games_url, headers=headers, params=querystring)
        response.raise_for_status()
        game_data = response.json()
        
        # If we don't get a response for this specific game, try getting games for the date
        if not game_data.get("response", []):
            logging.info(f"No direct game data, searching by date: {game_date}")
            date_str = game_date
            if hasattr(game_date, 'strftime'):
                date_str = game_date.strftime("%Y-%m-%d")
                
            querystring = {"date": date_str}
            response = requests.get(games_url, headers=headers, params=querystring)
            response.raise_for_status()
            games_data = response.json()
            
            # Find our game in the list
            game_found = False
            for game in games_data.get("response", []):
                # Try to match by ID (potential format differences)
                current_id = str(game.get("id"))
                if current_id.endswith(game_id) or game_id.endswith(current_id):
                    game_data = {"response": [game]}
                    game_found = True
                    logging.info(f"Found game by date search: {current_id}")
                    break
            
            if not game_found:
                logging.warning(f"Could not find game {game_id} for date {date_str} in RapidAPI")
                return None
        
        # Extract quarter scores from the game data
        game_info = game_data["response"][0]
        
        # Check if we have scores data
        if "scores" not in game_info:
            logging.warning(f"No scores data available for game {game_id} in RapidAPI")
            return None
            
        # Extract home and away team data
        home_team = game_info.get("teams", {}).get("home", {})
        away_team = game_info.get("teams", {}).get("visitors", {})
        
        # Extract home team scores
        home_scores = game_info.get("scores", {}).get("home", {}).get("linescore", [])
        home_code = home_team.get("code", "")
        
        # Ensure we have at least 4 quarters (fill with 0 if needed)
        while len(home_scores) < 4:
            home_scores.append(0)
            
        # Calculate total points
        home_total = sum(int(score or 0) for score in home_scores[:4])
        
        # Extract away team scores
        away_scores = game_info.get("scores", {}).get("visitors", {}).get("linescore", [])
        away_code = away_team.get("code", "")
        
        # Ensure we have at least 4 quarters (fill with 0 if needed)
        while len(away_scores) < 4:
            away_scores.append(0)
            
        # Calculate total points
        away_total = sum(int(score or 0) for score in away_scores[:4])
        
        # Create the scores data structure
        scores = [
            {
                "team_abbrev": home_code,
                "q1_points": int(home_scores[0] or 0),
                "q2_points": int(home_scores[1] or 0),
                "q3_points": int(home_scores[2] or 0), 
                "q4_points": int(home_scores[3] or 0),
                "total_points": home_total
            },
            {
                "team_abbrev": away_code,
                "q1_points": int(away_scores[0] or 0),
                "q2_points": int(away_scores[1] or 0),
                "q3_points": int(away_scores[2] or 0),
                "q4_points": int(away_scores[3] or 0),
                "total_points": away_total
            }
        ]
        
        logging.info(f"Successfully retrieved quarter scores from RapidAPI for game {game_id}")
        return scores
        
    except Exception as e:
        logging.error(f"Error fetching quarter scores from RapidAPI: {str(e)}")
        return None

@retry(stop=stop_after_attempt(3), wait=wait_exponential(multiplier=1, min=4, max=10))
def get_box_score(game_id):
    try:
        time.sleep(1.5)
        data = boxscoretraditionalv3.BoxScoreTraditionalV3(game_id=game_id).get_dict()
        
        # Check if data has the correct format (V3 structure)
        if 'boxScoreTraditional' not in data:
            raise ValueError(f"Invalid box score format for game {game_id}")
            
        return data
    except Exception as e:
        logging.error(f"Box score error: {str(e)}")
        raise

@retry(stop=stop_after_attempt(3), wait=wait_exponential(multiplier=1, min=4, max=10))
def get_game_summary(game_id):
    try:
        time.sleep(1.5)
        data = boxscoresummaryv2.BoxScoreSummaryV2(game_id=game_id).get_dict()
        if 'resultSets' not in data:
            raise ValueError(f"Invalid game summary format for game {game_id}")
        return data
    except Exception as e:
        logging.error(f"Game summary error: {str(e)}")
        raise

def process_nba_games(start_date, end_date, connection):
    """Process NBA games for the given date range"""
    games_processed = 0
    games_with_errors = 0
    
    try:
        gamefinder = leaguegamefinder.LeagueGameFinder(
            league_id_nullable='00',
            season_nullable=season_cfg['api_season_nba'],
            date_from_nullable=start_date.strftime('%m/%d/%Y'),
            date_to_nullable=end_date.strftime('%m/%d/%Y')
        )
        
        games_dict = gamefinder.get_dict()
        if not games_dict.get('resultSets') or not games_dict['resultSets'][0].get('rowSet'):
            logging.warning(f"No games found for {start_date} to {end_date}")
            return games_processed, games_with_errors

        games = games_dict['resultSets'][0]['rowSet']
        headers = games_dict['resultSets'][0]['headers']
        
        games_by_date = {}
        for game in games:
            game_data = dict(zip(headers, game))
            game_date = game_data['GAME_DATE']
            game_id = game_data['GAME_ID']
            if game_date not in games_by_date:
                games_by_date[game_date] = {}
            if game_id not in games_by_date[game_date]:
                games_by_date[game_date][game_id] = []
            games_by_date[game_date][game_id].append(game_data)

        for date in sorted(games_by_date.keys()):
            logging.info(f"Processing games for {date}")
            for game_id, game_data in games_by_date[date].items():
                try:
                    if len(game_data) != 2:
                        logging.error(f"Invalid game data for game {game_id}: Expected 2 teams, got {len(game_data)}")
                        games_with_errors += 1
                        continue

                    # Determine home/away teams
                    home_team = next((g for g in game_data if g.get('GAME_LOCATION') == 'H'), None)
                    away_team = next((g for g in game_data if g.get('GAME_LOCATION') != 'H'), None)
                    
                    if not home_team or not away_team:
                        logging.warning(f"Could not determine home/away from GAME_LOCATION for game {game_id}")
                        
                        # Fallback to matchup parsing
                        team1_matchup = game_data[0]['MATCHUP']
                        
                        if ' @ ' in team1_matchup:
                            teams = team1_matchup.split(' @ ')
                            away_abbrev, home_abbrev = teams
                        else:
                            logging.error(f"Unable to parse matchup format for game {game_id}")
                            games_with_errors += 1
                            continue

                        # Find the corresponding team data
                        home_team = next(g for g in game_data if g['TEAM_ABBREVIATION'] == home_abbrev)
                        away_team = next(g for g in game_data if g['TEAM_ABBREVIATION'] == away_abbrev)

                    logging.info(f"Processing: {away_team['TEAM_NAME']} @ {home_team['TEAM_NAME']}")

                    # Get game summary for quarter scores and inactive players
                    try:
                        game_summary = get_game_summary(game_id)
                    except Exception as e:
                        logging.error(f"Failed to get game summary for game {game_id}: {str(e)}")
                        games_with_errors += 1
                        continue

                    # Process quarter scores from the game summary
                    line_score = next((rs for rs in game_summary['resultSets'] 
                                    if rs['name'] == 'LineScore'), None)
                    
                    scores_inserted = False
                    if line_score and line_score['rowSet']:
                        try:
                            scores = []
                            valid_data = False
                            
                            for row in line_score['rowSet']:
                                score_dict = dict(zip(line_score['headers'], row))
                                
                                # Check if this row has actual data (not all NULL)
                                has_team = score_dict.get('TEAM_ABBREVIATION') is not None
                                has_scores = (score_dict.get('PTS_QTR1') is not None or 
                                            score_dict.get('PTS_QTR2') is not None or 
                                            score_dict.get('PTS_QTR3') is not None or 
                                            score_dict.get('PTS_QTR4') is not None)
                                
                                if has_team and has_scores:
                                    valid_data = True
                                
                                scores.append(score_dict)
                            
                            if valid_data:
                                insert_quarter_scores(connection, game_id, date, scores)
                                scores_inserted = True
                                logging.info(f"Inserted quarter scores from NBA API for game {game_id}")
                            else:
                                logging.warning(f"NBA API returned only NULL values for game {game_id}")
                                
                        except Exception as e:
                            logging.error(f"Failed to insert quarter scores from NBA API: {str(e)}")
                    
                    # Try RapidAPI as fallback for quarter scores if needed
                    if not scores_inserted:
                        logging.info(f"Attempting to fetch quarter scores from RapidAPI for game {game_id}")
                        rapid_scores = get_quarter_scores_from_rapidapi(game_id, date)
                        
                        if rapid_scores:
                            try:
                                insert_quarter_scores(connection, game_id, date, rapid_scores)
                                logging.info(f"Successfully inserted quarter scores from RapidAPI for game {game_id}")
                                scores_inserted = True
                            except Exception as e:
                                logging.error(f"Failed to insert quarter scores from RapidAPI: {str(e)}")
                    
                    # Process inactive players from the game summary
                    inactive = next((rs for rs in game_summary['resultSets'] 
                                   if rs['name'] == 'InactivePlayers'), None)
                    if inactive and inactive['rowSet']:
                        players = [dict(zip(inactive['headers'], player)) 
                                 for player in inactive['rowSet']]
                        insert_inactive_players(connection, game_id, date, players)
                    
                    # Process box scores using the V3 format
                    try:
                        box_score = get_box_score(game_id)
                        
                        # Process the V3 data structure
                        boxscore_data = box_score['boxScoreTraditional']
                        
                        # Process home team players
                        home_team_id = str(boxscore_data['homeTeamId'])
                        home_team_name = f"{boxscore_data['homeTeam']['teamCity']} {boxscore_data['homeTeam']['teamName']}"
                        home_players = []
                        
                        for player in boxscore_data['homeTeam']['players']:
                            # Skip players who didn't play (DNP or empty minutes)
                            if not player['statistics'].get('minutes'):
                                continue
                                
                            player_data = {
                                'player_name': f"{player['firstName']} {player['familyName']}",
                                'minutes': player['statistics']['minutes'],
                                'points': player['statistics']['points'],
                                'rebounds': player['statistics']['reboundsTotal'],
                                'assists': player['statistics']['assists'],
                                'fg_made': player['statistics']['fieldGoalsMade'],
                                'fg_attempts': player['statistics']['fieldGoalsAttempted']
                            }
                            home_players.append(player_data)
                        
                        # Process away team players
                        away_team_id = str(boxscore_data['awayTeamId'])
                        away_team_name = f"{boxscore_data['awayTeam']['teamCity']} {boxscore_data['awayTeam']['teamName']}"
                        away_players = []
                        
                        for player in boxscore_data['awayTeam']['players']:
                            # Skip players who didn't play (DNP or empty minutes)
                            if not player['statistics'].get('minutes'):
                                continue
                                
                            player_data = {
                                'player_name': f"{player['firstName']} {player['familyName']}",
                                'minutes': player['statistics']['minutes'],
                                'points': player['statistics']['points'],
                                'rebounds': player['statistics']['reboundsTotal'],
                                'assists': player['statistics']['assists'],
                                'fg_made': player['statistics']['fieldGoalsMade'],
                                'fg_attempts': player['statistics']['fieldGoalsAttempted']
                            }
                            away_players.append(player_data)
                        
                        # Insert player data into database
                        if home_players:
                            insert_player_stats(connection, game_id, date, home_players, home_team_id, home_team_name)
                        if away_players:
                            insert_player_stats(connection, game_id, date, away_players, away_team_id, away_team_name)
                    
                    except Exception as e:
                        logging.error(f"Error processing box score for game {game_id}: {str(e)}")
                    
                    check_update_times(connection, game_id)
                    games_processed += 1
                    time.sleep(1)
                    
                except Exception as e:
                    logging.error(f"Error processing game {game_id}: {str(e)}", exc_info=True)
                    games_with_errors += 1
                    continue
                    
    except Exception as e:
        logging.error(f"Error in process_nba_games: {str(e)}", exc_info=True)

    return games_processed, games_with_errors

def main():
    """Main function to process NBA game data"""
    connection = None
    try:
        connection = connect_to_database()
        if not connection:
            return
        
        # Create required tables
        create_tables(connection)
        
        est = pytz.timezone('America/New_York')
        utc_now = datetime.now(pytz.UTC)
        est_now = utc_now.astimezone(est)
        
        today = est_now.date()
        yesterday = today - timedelta(days=1)
        
        start_date = datetime.combine(yesterday, datetime.min.time()).replace(tzinfo=est)
        end_date = datetime.combine(today, datetime.max.time()).replace(tzinfo=est)
        
        logging.info(f"Fetching NBA games from {yesterday} to {today}")
        games_processed, games_with_errors = process_nba_games(start_date, end_date, connection)
        
        # Log results to update_log table
        details = f"Processed: {games_processed} games, Errors: {games_with_errors}"
        log_to_update_table(connection, 'upload_season_data.py', details)
        
        logging.info(f"Season data update completed. {details}")
        
    except Exception as e:
        logging.error(f"Main function error: {e}")
        if connection:
            try:
                log_to_update_table(connection, 'upload_season_data.py', f"ERROR: {str(e)}")
            except:
                pass
    finally:
        if connection:
            connection.close()

if __name__ == "__main__":
    print("Starting NBA season data upload...")
    main()
    print("NBA season data upload completed.")