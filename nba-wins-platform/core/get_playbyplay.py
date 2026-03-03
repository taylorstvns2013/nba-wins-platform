#!/usr/bin/env python3
"""
get_playbyplay.py - Fetch latest play-by-play action from NBA CDN
Path: /data/www/default/nba-wins-platform/core/get_playbyplay.py

Usage: python3 get_playbyplay.py <game_id>
  e.g. python3 get_playbyplay.py 0022500741

Returns JSON with the most recent play action, or error JSON on failure.
"""

import sys
import json
import urllib.request
import re

def parse_clock(iso_duration):
    """Convert ISO 8601 duration (PT05M30.00S) to readable time (5:30)."""
    if not iso_duration:
        return "0:00"
    
    # Already formatted
    if re.match(r'^\d{1,2}:\d{2}$', iso_duration.strip()):
        return iso_duration.strip()
    
    match = re.match(r'^PT(\d+)M([\d.]+)S$', iso_duration.strip(), re.IGNORECASE)
    if match:
        minutes = int(match.group(1))
        seconds = int(float(match.group(2)))
        return f"{minutes}:{seconds:02d}"
    
    return iso_duration.strip()


def get_latest_play(game_id):
    """Fetch play-by-play from CDN and return the most recent meaningful action."""
    url = f"https://cdn.nba.com/static/json/liveData/playbyplay/playbyplay_{game_id}.json"
    
    req = urllib.request.Request(url, headers={
        'User-Agent': 'Mozilla/5.0',
        'Accept': 'application/json'
    })
    
    with urllib.request.urlopen(req, timeout=8) as resp:
        data = json.loads(resp.read().decode('utf-8'))
    
    actions = data.get('game', {}).get('actions', [])
    if not actions:
        return None
    
    # Walk backwards to find the most recent play with a description
    # (skip empty actions like period-start markers)
    for action in reversed(actions):
        desc = action.get('description', '').strip()
        if desc:
            return {
                'actionNumber': action.get('actionNumber', 0),
                'description': desc,
                'clock': parse_clock(action.get('clock', '')),
                'period': action.get('period', 0),
                'teamTricode': action.get('teamTricode', ''),
                'playerName': action.get('playerNameI', ''),
                'actionType': action.get('actionType', ''),
                'subType': action.get('subType', ''),
                'scoreHome': action.get('scoreHome', '0'),
                'scoreAway': action.get('scoreAway', '0'),
                'shotResult': action.get('shotResult', ''),
                'isFieldGoal': action.get('isFieldGoal', 0),
            }
    
    return None


def main():
    if len(sys.argv) < 2:
        print(json.dumps({'error': 'No game_id provided'}))
        sys.exit(1)
    
    game_id = sys.argv[1].strip()
    
    # Validate game ID format (10 digits)
    if not re.match(r'^\d{10}$', game_id):
        print(json.dumps({'error': f'Invalid game_id format: {game_id}'}))
        sys.exit(1)
    
    try:
        play = get_latest_play(game_id)
        if play:
            print(json.dumps({'success': True, 'play': play}))
        else:
            print(json.dumps({'success': True, 'play': None, 'message': 'No plays yet'}))
    except Exception as e:
        print(json.dumps({'error': str(e)}))
        sys.exit(1)


if __name__ == '__main__':
    main()