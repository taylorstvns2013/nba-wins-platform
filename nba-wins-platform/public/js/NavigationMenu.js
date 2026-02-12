// /data/www/default/nba-wins-platform/public/js/NavigationMenu.js
// Standalone Navigation Menu Component for NBA Wins Platform
// Last Updated: November 13, 2025

const NavigationMenu = ({ leagueId, userId, hasNewArticles, isGuest, firstParticipantUserId }) => {
    const [isOpen, setIsOpen] = React.useState(false);

    const toggleMenu = () => {
        setIsOpen(!isOpen);
    };

    // Handle navigation to stay in standalone mode (PWA)
    const handleNavigation = (e, url) => {
        const isStandalone = window.navigator.standalone || 
                            window.matchMedia('(display-mode: standalone)').matches;
        
        if (isStandalone) {
            e.preventDefault();
            window.location.href = url;
        }
    };
    
    return (
        <div className="menu-container">
            <button
                onClick={toggleMenu}
                className="menu-button"
                aria-label="Toggle menu"
            >
                <i className="fas fa-bars"></i>
            </button>

            {isOpen && (
                <div 
                    className="menu-overlay"
                    onClick={toggleMenu}
                />
            )}

            <div className={`menu-panel ${isOpen ? 'menu-open' : ''}`}>
                <div className="menu-header">
                    <button onClick={toggleMenu} className="close-button">
                        <i className="fas fa-times"></i>
                    </button>
                </div>
                <nav className="menu-content">
                    <ul className="menu-list">
                        <li>
                            <a href="/index.php" 
                               className="menu-link"
                               onClick={(e) => handleNavigation(e, '/index.php')}>
                                <i className="fas fa-home"></i>
                                Home
                            </a>
                        </li>
                        <li>
                            <a href={`/nba-wins-platform/profiles/participant_profile.php?league_id=${leagueId}&user_id=${isGuest ? firstParticipantUserId : userId}`}
                               className="menu-link"
                               onClick={(e) => handleNavigation(e, `/nba-wins-platform/profiles/participant_profile.php?league_id=${leagueId}&user_id=${isGuest ? firstParticipantUserId : userId}`)}>
                                <i className={`fas ${isGuest ? 'fa-users' : 'fa-user'}`}></i>
                                {isGuest ? 'Profiles' : 'My Profile'}
                            </a>
                        </li>
                        <li>
                            <a href="/analytics.php" 
                               className="menu-link"
                               onClick={(e) => handleNavigation(e, '/analytics.php')}>
                                <i className="fas fa-chart-line"></i>
                                Analytics
                            </a>
                        </li>
                        <li>
                            <a href="/claudes-column.php" 
                               className="menu-link"
                               onClick={(e) => handleNavigation(e, '/claudes-column.php')}>
                                <i className="fa-solid fa-newspaper"></i>
                                Claude's Column
                                {hasNewArticles && (
                                    <span className="new-badge">NEW</span>
                                )}
                            </a>
                        </li>
                        <li>
                            <a href="/nba_standings.php" 
                               className="menu-link"
                               onClick={(e) => handleNavigation(e, '/nba_standings.php')}>
                                <i className="fas fa-basketball-ball"></i>
                                NBA Standings
                            </a>
                        </li>
                        <li>
                            <a href="/draft.php" 
                               className="menu-link"
                               onClick={(e) => handleNavigation(e, '/draft.php')}>
                                <i className="fas fa-file-alt"></i>
                                Draft
                            </a>
                        </li>
                        <li>
                            <a href="https://buymeacoffee.com/taylorstvns" 
                               className="menu-link" 
                               target="_blank" 
                               rel="noopener noreferrer">
                                <i className="fas fa-coffee"></i>
                                Buy Me a Coffee
                            </a>
                        </li>
                        <li>
                            <a href="/nba-wins-platform/auth/logout.php" 
                               className="menu-link"
                               onClick={(e) => handleNavigation(e, '/nba-wins-platform/auth/logout.php')}>
                                <i className="fas fa-sign-out-alt"></i>
                                Logout
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
    );
};

// Make it globally available
window.NavigationMenu = NavigationMenu;