#!/usr/bin/env python3
# update_roster_stats.py - Update NBA roster statistics
# Location: /data/www/default/nba-wins-platform/tasks/

import pymysql
from nba_api.stats.endpoints import commonteamroster
import time
import logging
from datetime import datetime

# Set up logging
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

def create_player_stats_table(connection):
    """Create player_stats table if it doesn't exist"""
    try:
        cursor = connection.cursor()
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS player_stats (
                id INT AUTO_INCREMENT PRIMARY KEY,
                team_name VARCHAR(100) NOT NULL,
                player_name VARCHAR(100) NOT NULL,
                jersey_number VARCHAR(10),
                position VARCHAR(10),
                height VARCHAR(20),
                weight VARCHAR(20),
                birth_date DATE,
                age INT,
                experience VARCHAR(20),
                school VARCHAR(100),
                last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_team_player (team_name, player_name),
                INDEX idx_team_name (team_name),
                INDEX idx_player_name (player_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        """)
        connection.commit()
        logging.info("Player stats table created/verified successfully")
    except Exception as e:
        logging.error(f"Error creating player_stats table: {e}")
        raise

def log_to_update_table(connection, script_name, details):
    """Log script execution to the update_log table"""
    try:
        cursor = connection.cursor()
        update_time = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        cursor.execute(
            "INSERT INTO update_log (update_time, script_name, details) VALUES (%s, %s, %s)",
            (update_time, script_name, details)
        )
        connection.commit()
    except Exception as e:
        logging.error(f"Failed to log to update_log table: {e}")

def get_nba_teams():
    """Get list of NBA team IDs and names"""
    teams = [
        (1610612737, 'Atlanta Hawks'),
        (1610612738, 'Boston Celtics'),
        (1610612751, 'Brooklyn Nets'),
        (1610612766, 'Charlotte Hornets'),
        (1610612741, 'Chicago Bulls'),
        (1610612739, 'Cleveland Cavaliers'),
        (1610612742, 'Dallas Mavericks'),
        (1610612743, 'Denver Nuggets'),
        (1610612765, 'Detroit Pistons'),
        (1610612744, 'Golden State Warriors'),
        (1610612745, 'Houston Rockets'),
        (1610612754, 'Indiana Pacers'),
        (1610612746, 'LA Clippers'),
        (1610612747, 'Los Angeles Lakers'),
        (1610612763, 'Memphis Grizzlies'),
        (1610612748, 'Miami Heat'),
        (1610612749, 'Milwaukee Bucks'),
        (1610612750, 'Minnesota Timberwolves'),
        (1610612740, 'New Orleans Pelicans'),
        (1610612752, 'New York Knicks'),
        (1610612760, 'Oklahoma City Thunder'),
        (1610612753, 'Orlando Magic'),
        (1610612755, 'Philadelphia 76ers'),
        (1610612756, 'Phoenix Suns'),
        (1610612757, 'Portland Trail Blazers'),
        (1610612758, 'Sacramento Kings'),
        (1610612759, 'San Antonio Spurs'),
        (1610612761, 'Toronto Raptors'),
        (1610612762, 'Utah Jazz'),
        (1610612764, 'Washington Wizards')
    ]
    return teams

def update_team_roster(connection, team_id, team_name):
    """Update roster for a specific team"""
    try:
        logging.info(f"Updating roster for {team_name}")
        
        # Get team roster from NBA API
        roster = commonteamroster.CommonTeamRoster(team_id=team_id)
        roster_data = roster.get_dict()
        
        if 'resultSets' not in roster_data or not roster_data['resultSets']:
            logging.warning(f"No roster data found for {team_name}")
            return 0
        
        # Get the roster data
        players_data = roster_data['resultSets'][0]
        headers = players_data['headers']
        players = players_data['rowSet']
        
        updated_count = 0
        cursor = connection.cursor()
        
        for player_row in players:
            player_dict = dict(zip(headers, player_row))
            
            # Extract player information
            player_name = player_dict.get('PLAYER', 'Unknown')
            jersey_number = player_dict.get('NUM', '')
            position = player_dict.get('POSITION', '')
            height = player_dict.get('HEIGHT', '')
            weight = player_dict.get('WEIGHT', '')
            birth_date = player_dict.get('BIRTH_DATE', None)
            age = player_dict.get('AGE', None)
            experience = player_dict.get('EXP', '')
            school = player_dict.get('SCHOOL', '')
            
            # Convert birth_date if it exists
            if birth_date:
                try:
                    birth_date = datetime.strptime(birth_date, '%Y-%m-%dT%H:%M:%S').date()
                except:
                    birth_date = None
            
            # Insert or update player data
            cursor.execute("""
                INSERT INTO player_stats 
                (team_name, player_name, jersey_number, position, height, weight, birth_date, age, experience, school)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE 
                jersey_number = VALUES(jersey_number),
                position = VALUES(position),
                height = VALUES(height),
                weight = VALUES(weight),
                birth_date = VALUES(birth_date),
                age = VALUES(age),
                experience = VALUES(experience),
                school = VALUES(school)
            """, (
                team_name,
                player_name,
                jersey_number,
                position,
                height,
                weight,
                birth_date,
                age,
                experience,
                school
            ))
            
            updated_count += 1
        
        connection.commit()
        logging.info(f"Updated {updated_count} players for {team_name}")
        return updated_count
        
    except Exception as e:
        logging.error(f"Error updating roster for {team_name}: {e}")
        return 0

def main():
    """Main function to update NBA roster statistics"""
    connection = None
    total_updated = 0
    
    try:
        print("Starting NBA roster stats update...")
        
        connection = connect_to_database()
        if not connection:
            logging.error("Failed to connect to database")
            return
        
        # Create player_stats table if it doesn't exist
        create_player_stats_table(connection)
        
        # Get all NBA teams
        teams = get_nba_teams()
        
        for team_id, team_name in teams:
            try:
                updated_count = update_team_roster(connection, team_id, team_name)
                total_updated += updated_count
                
                # Add delay to respect API limits
                time.sleep(1)
                
            except Exception as e:
                logging.error(f"Failed to update {team_name}: {e}")
                continue
        
        # Log the update
        details = f"Updated {total_updated} player records across {len(teams)} teams"
        log_to_update_table(connection, 'update_roster_stats.py', details)
        
        logging.info(f"Roster stats update completed. {details}")
        
    except Exception as e:
        logging.error(f"Main function error: {e}")
        if connection:
            try:
                log_to_update_table(connection, 'update_roster_stats.py', f"ERROR: {str(e)}")
            except:
                pass
    finally:
        if connection:
            connection.close()
        print("NBA roster stats update completed.")

if __name__ == "__main__":
    main()