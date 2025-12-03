#!/usr/bin/env python3
"""
get_games_readable.py - Clean, Human-Readable NBA Game Display
Location: /data/www/default/nba-wins-platform/tasks/

Displays current NBA games in a clean, formatted table view.

Usage:
    python3 get_games_readable.py                    # Show all games
    python3 get_games_readable.py --live             # Show only live games
    python3 get_games_readable.py --scheduled        # Show only scheduled games
    python3 get_games_readable.py --final            # Show only final games
    python3 get_games_readable.py --compact          # Compact single-line format
"""

from nba_api.live.nba.endpoints import scoreboard
from datetime import datetime
import pytz
import sys
import argparse

def get_status_emoji(game_status, status_text):
    """Get emoji for game status"""
    if game_status == 1:  # Scheduled
        return "🕐"
    elif game_status == 2:  # Live
        return "🔴"
    elif game_status == 3 or 'final' in status_text.lower():  # Final
        return "✅"
    else:
        return "❓"

def format_game_time(game_time_utc):
    """Convert UTC time to EST and format nicely"""
    try:
        utc_time = datetime.strptime(game_time_utc, '%Y-%m-%dT%H:%M:%SZ')
        utc_time = pytz.utc.localize(utc_time)
        est_time = utc_time.astimezone(pytz.timezone('America/New_York'))
        return est_time.strftime('%I:%M %p ET').lstrip('0')
    except:
        return "TBD"

def get_game_status_display(game):
    """Get clean status display"""
    status = game['gameStatus']
    status_text = game.get('gameStatusText', '').strip()
    
    if status == 1:  # Scheduled
        return format_game_time(game.get('gameTimeUTC', ''))
    elif status == 2:  # Live
        period = game['period']
        clock = game['gameClock']
        
        if period <= 4:
            quarter = f"Q{period}"
        else:
            quarter = f"OT" if period == 5 else f"OT{period-4}"
        
        # Clean up clock display
        if clock and clock != "PT00M00.00S":
            # Remove PT and .00S, convert to readable format
            clock = clock.replace('PT', '').replace('.00S', '')
            if 'M' in clock:
                mins, secs = clock.split('M')
                clock = f"{mins}:{secs.zfill(2)}"
            else:
                clock = clock.replace('S', '') + "s"
            return f"{quarter} - {clock}"
        else:
            if "Halftime" in status_text:
                return "Halftime"
            elif "End of" in status_text:
                return f"End {quarter}"
            else:
                return quarter
    elif status == 3:  # Final
        return "Final"
    else:
        return status_text

def print_games_table(games, compact=False):
    """Print games in a nice table format"""
    if not games:
        print("\n📭 No games found.\n")
        return
    
    # Get current time
    est_tz = pytz.timezone('America/New_York')
    current_time = datetime.now(est_tz)
    
    print(f"\n{'='*90}")
    print(f"🏀 NBA GAMES - {current_time.strftime('%A, %B %d, %Y at %I:%M %p ET')}")
    print(f"{'='*90}\n")
    
    if compact:
        # Compact format - one line per game
        for game in games:
            emoji = get_status_emoji(game['gameStatus'], game.get('gameStatusText', ''))
            away = game['awayTeam']
            home = game['homeTeam']
            status = get_game_status_display(game)
            
            away_display = f"{away['teamCity']} {away['teamName']}"
            home_display = f"{home['teamCity']} {home['teamName']}"
            
            if game['gameStatus'] == 1:  # Scheduled
                print(f"{emoji} {away_display:25s} @ {home_display:25s} - {status}")
            else:
                away_score = away['score']
                home_score = home['score']
                winner = ">" if away_score > home_score else " " if away_score == home_score else "<"
                print(f"{emoji} {away_display:25s} {away_score:3d} {winner} {home_score:3d} {home_display:25s} - {status}")
    else:
        # Full format - detailed view
        for i, game in enumerate(games, 1):
            emoji = get_status_emoji(game['gameStatus'], game.get('gameStatusText', ''))
            away = game['awayTeam']
            home = game['homeTeam']
            status = get_game_status_display(game)
            
            print(f"{emoji} Game {i}: {status}")
            print(f"   {'─'*70}")
            
            # Away team
            away_display = f"{away['teamCity']} {away['teamName']}"
            away_record = f"({away['wins']}-{away['losses']})"
            away_score = away['score']
            
            # Home team
            home_display = f"{home['teamCity']} {home['teamName']}"
            home_record = f"({home['wins']}-{home['losses']})"
            home_score = home['score']
            
            # Display with proper alignment
            if game['gameStatus'] == 1:  # Scheduled
                print(f"   {away_display:30s} {away_record:8s}")
                print(f"   {'@':^30s}")
                print(f"   {home_display:30s} {home_record:8s}")
            else:
                # Show scores
                winner_indicator_away = " ← LEADING" if away_score > home_score else ""
                winner_indicator_home = " ← LEADING" if home_score > away_score else ""
                
                if game['gameStatus'] == 3:  # Final
                    winner_indicator_away = " ✓ WINNER" if away_score > home_score else ""
                    winner_indicator_home = " ✓ WINNER" if home_score > away_score else ""
                
                print(f"   {away_display:30s} {away_record:8s}  {away_score:3d}{winner_indicator_away}")
                print(f"   {'@':^30s}")
                print(f"   {home_display:30s} {home_record:8s}  {home_score:3d}{winner_indicator_home}")
                
                # Show quarter-by-quarter scores if available
                if game['gameStatus'] >= 2 and len(away['periods']) > 0:
                    quarters = []
                    for p in range(len(away['periods'])):
                        if away['periods'][p]['score'] > 0 or home['periods'][p]['score'] > 0:
                            q_num = p + 1
                            q_label = f"Q{q_num}" if q_num <= 4 else "OT" if q_num == 5 else f"OT{q_num-4}"
                            quarters.append(f"{q_label}: {away['periods'][p]['score']}-{home['periods'][p]['score']}")
                    
                    if quarters:
                        print(f"   Quarters: {' | '.join(quarters)}")
            
            print()
    
    print(f"{'='*90}\n")

def main():
    parser = argparse.ArgumentParser(
        description='Display NBA games in a clean, readable format',
        formatter_class=argparse.RawDescriptionHelpFormatter
    )
    parser.add_argument('--live', action='store_true', help='Show only live games')
    parser.add_argument('--scheduled', action='store_true', help='Show only scheduled games')
    parser.add_argument('--final', action='store_true', help='Show only final games')
    parser.add_argument('--compact', action='store_true', help='Compact single-line format')
    
    args = parser.parse_args()
    
    try:
        # Fetch games from NBA API
        board = scoreboard.ScoreBoard()
        games_dict = board.get_dict()
        games = games_dict.get('scoreboard', {}).get('games', [])
        
        # Filter games based on arguments
        if args.live:
            games = [g for g in games if g['gameStatus'] == 2]
        elif args.scheduled:
            games = [g for g in games if g['gameStatus'] == 1]
        elif args.final:
            games = [g for g in games if g['gameStatus'] == 3]
        
        # Sort games: Live first, then scheduled, then final
        games.sort(key=lambda x: (0 if x['gameStatus'] == 2 else 1 if x['gameStatus'] == 1 else 2, 
                                  x.get('gameTimeUTC', '')))
        
        print_games_table(games, compact=args.compact)
        
    except Exception as e:
        print(f"\n❌ Error fetching games: {str(e)}\n")
        sys.exit(1)

if __name__ == '__main__':
    main()