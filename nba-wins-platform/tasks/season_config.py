"""
Season Configuration Loader for Python Scripts

Loads season dates and parameters from config/season.json.
Update season.json once per year — all files read from it automatically.

Usage:
    from season_config import get_season_config
    season = get_season_config()
    print(season['season_start_date'])  # '2025-10-21'
"""

import json
import os


def get_season_config():
    """Load and return the season configuration from season.json."""
    config_path = os.path.join(
        os.path.dirname(os.path.abspath(__file__)),
        '..', 'config', 'season.json'
    )
    try:
        with open(config_path) as f:
            return json.load(f)
    except (FileNotFoundError, json.JSONDecodeError) as e:
        print(f"Warning: Could not load season config: {e}")
        # Fallback defaults so scripts don't crash
        return {
            'season_label': '2025-26',
            'api_season_nba': '2025-26',
            'api_season_rapid': '2025',
            'api_season_espn': '2026',
            'season_start_date': '2025-10-21',
        }
